<?php
/**
 * Scrape la-mairie.com — RATTRAPAGE des maires non trouvés au 1er passage
 * Essaie avec le suffixe département (ex: moussey-10) pour les communes homonymes
 * Usage : php scrape-mairies-retry.php [--delay=400] [--limit=10000]
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

$delay = 400;
$limit = 10000;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--delay=(\d+)$/', $arg, $m)) $delay = (int)$m[1];
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
}

echo "=== SCRAPE MAIRIES RATTRAPAGE — " . date('Y-m-d H:i:s') . " ===\n";
echo "Delay: {$delay}ms | Limit: $limit\n\n";

// Charger les maires ACTIFS sans contact
$stmt = $pdo->prepare("
    SELECT e.id, e.nom, e.prenom, e.fonction, e.departement, e.slug
    FROM elus e
    WHERE e.fonction LIKE 'Maire — %'
    AND e.actif = 1
    AND (e.telephone IS NULL OR e.telephone = '')
    AND (e.email IS NULL OR e.email = '')
    ORDER BY e.departement, e.nom
    LIMIT :lim
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$maires = $stmt->fetchAll();

echo count($maires) . " maires sans contact à enrichir\n\n";

$stmtUpdate = $pdo->prepare("
    UPDATE elus SET
        telephone = COALESCE(NULLIF(:tel, ''), telephone),
        email = COALESCE(NULLIF(:email, ''), email),
        adresse = COALESCE(NULLIF(:adresse, ''), adresse),
        url_fiche = COALESCE(NULLIF(:url_fiche, ''), url_fiche)
    WHERE id = :id
");

function slugify(string $s): string {
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    $s = preg_replace('/[^a-z0-9-]/', '-', $s);
    return preg_replace('/-+/', '-', trim($s, '-'));
}

function fetchPage(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NosElusFr/1.0; +https://nos-elus.fr)',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $html) ? $html : null;
}

function extractContact(string $html): array {
    $data = ['tel' => '', 'email' => '', 'adresse' => '', 'site' => '', 'maire' => ''];

    // JSON-LD
    if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m)) {
        $json = json_decode($m[1], true);
        if ($json) {
            $data['tel']   = $json['telephone'] ?? '';
            $data['email'] = $json['email'] ?? '';
            if (!empty($json['address'])) {
                $addr = $json['address'];
                $parts = array_filter([
                    $addr['streetAddress'] ?? '',
                    ($addr['postalCode'] ?? '') . ' ' . ($addr['addressLocality'] ?? ''),
                ]);
                $data['adresse'] = implode(', ', $parts);
            }
        }
    }

    // Nom du maire
    if (preg_match('/(?:Monsieur le maire|Madame la maire|le maire)\s+([A-ZÀ-Üa-zà-ü-]+\s+[A-ZÀ-Ü][A-ZÀ-Ü\s-]+)/u', $html, $m)) {
        $data['maire'] = trim($m[1]);
    }

    // Fallbacks
    if (!$data['tel'] && preg_match('/href="tel:([^"]+)"/', $html, $m)) $data['tel'] = trim($m[1]);
    if (!$data['email'] && preg_match('/href="mailto:([^"]+)"/', $html, $m)) $data['email'] = trim(strtolower($m[1]));
    if (preg_match('/Site\s*(?:internet|web|officiel)\s*:\s*<a[^>]+href="(https?:\/\/[^"]+)"/i', $html, $m)) $data['site'] = $m[1];

    $data['tel'] = preg_replace('/[^\d+]/', '', $data['tel']);
    return $data;
}

function normName(string $n): string {
    return mb_strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', trim($n)));
}

$updated = 0;
$notFound = 0;
$mismatch = 0;
$empty = 0;

foreach ($maires as $i => $maire) {
    $idx = $i + 1;

    // Extraire commune
    $commune = '';
    if (preg_match('/Maire\s*(?:—|-)\s*(.+)$/i', $maire['fonction'], $m)) {
        $commune = trim(explode('/', $m[1])[0]);
    }
    if (!$commune) { $empty++; continue; }

    $slug = slugify($commune);
    $dept = strtolower($maire['departement'] ?? '');

    // Stratégie d'URLs : avec département d'abord (résout les homonymes), puis sans
    $urls = [];
    if ($dept) $urls[] = "https://www.la-mairie.com/{$slug}-{$dept}";
    $urls[] = "https://www.la-mairie.com/$slug";

    $html = null;
    $usedUrl = '';
    foreach ($urls as $url) {
        $html = fetchPage($url);
        if ($html) { $usedUrl = $url; break; }
        usleep($delay * 300);
    }

    if (!$html) {
        $notFound++;
        usleep($delay * 1000);
        continue;
    }

    $contact = extractContact($html);

    // Vérifier le nom du maire
    if ($contact['maire']) {
        $nomBdd = normName($maire['nom']);
        $nomSite = normName($contact['maire']);
        $parts = explode(' ', $nomSite);
        $lastNameSite = normName(end($parts));
        if (strpos($nomSite, $nomBdd) === false && $lastNameSite !== $nomBdd) {
            $mismatch++;
            usleep($delay * 1000);
            continue;
        }
    }

    if ($contact['tel'] || $contact['email'] || $contact['adresse']) {
        $stmtUpdate->execute([
            ':tel'       => $contact['tel'],
            ':email'     => $contact['email'],
            ':adresse'   => $contact['adresse'],
            ':url_fiche' => $contact['site'] ?: $usedUrl,
            ':id'        => $maire['id'],
        ]);
        $updated++;
        if ($idx <= 20 || $idx % 500 === 0) {
            echo "[$idx] OK {$maire['prenom']} {$maire['nom']} ($commune) — tel:{$contact['tel']} email:{$contact['email']}\n";
        }
    } else {
        $empty++;
    }

    usleep($delay * 1000);

    if ($idx % 500 === 0) {
        echo "\n--- Progress: $idx/" . count($maires) . " | OK: $updated | 404: $notFound | Mismatch: $mismatch ---\n\n";
    }
}

echo "\n=== BILAN ===\n";
echo "Traités   : " . count($maires) . "\n";
echo "Mis à jour: $updated\n";
echo "404       : $notFound\n";
echo "Mismatch  : $mismatch\n";
echo "Vide      : $empty\n";
echo "=== FIN ===\n";
