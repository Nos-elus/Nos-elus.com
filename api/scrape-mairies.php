<?php
/**
 * Scrape la-mairie.com — récupère tel, email, adresse, site web des maires
 * Usage CLI : php scrape-mairies.php [--dept=01] [--offset=0] [--limit=500] [--delay=300]
 *
 * Exécute par batch pour limiter les requêtes. Délai par défaut : 300ms entre chaque.
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

// ── Params ──
$dept   = null;
$offset = 0;
$limit  = 500;
$delay  = 300; // ms entre requêtes

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--dept=(.+)$/', $arg, $m)) $dept = $m[1];
    if (preg_match('/^--offset=(\d+)$/', $arg, $m)) $offset = (int)$m[1];
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
    if (preg_match('/^--delay=(\d+)$/', $arg, $m)) $delay = (int)$m[1];
}

echo "=== SCRAPE LA-MAIRIE.COM — " . date('Y-m-d H:i:s') . " ===\n";
echo "Dept: " . ($dept ?: 'tous') . " | Offset: $offset | Limit: $limit | Delay: {$delay}ms\n\n";

// ── 1. Charger les maires sans contact depuis la BDD ──
$sql = "SELECT e.id, e.nom, e.prenom, e.fonction, e.departement, e.slug
        FROM elus e
        WHERE e.fonction LIKE 'Maire%'
        AND (e.telephone IS NULL OR e.telephone = '')";
$params = [];

if ($dept) {
    $sql .= " AND e.departement = :dept";
    $params[':dept'] = $dept;
}

$sql .= " ORDER BY e.departement, e.nom LIMIT :lim OFFSET :off";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$maires = $stmt->fetchAll();

echo count($maires) . " maires à enrichir\n\n";

if (!$maires) {
    echo "Rien à faire.\n";
    exit(0);
}

// ── 2. Préparer l'UPDATE ──
$stmtUpdate = $pdo->prepare("
    UPDATE elus SET
        telephone = COALESCE(NULLIF(:tel, ''), telephone),
        email = COALESCE(NULLIF(:email, ''), email),
        adresse = COALESCE(NULLIF(:adresse, ''), adresse),
        url_fiche = COALESCE(NULLIF(:url_fiche, ''), url_fiche)
    WHERE id = :id
");

// ── 3. Fonctions ──
function slugifyCommune(string $commune): string {
    $slug = mb_strtolower(trim($commune));
    $slug = str_replace(['saint-', 'sainte-'], ['saint-', 'sainte-'], $slug);
    // Retirer accents
    $slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $slug);
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

function fetchPage(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NosElusFr/1.0; +https://nos-elus.fr)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html', 'Accept-Language: fr-FR,fr;q=0.9'],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $html) ? $html : null;
}

function extractContact(string $html): array {
    $data = ['tel' => '', 'email' => '', 'adresse' => '', 'site' => '', 'maire' => ''];

    // JSON-LD (source la plus fiable)
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

    // Nom du maire — meta description (source la plus fiable)
    if (preg_match('/(?:Monsieur le maire|Madame la maire|le maire)\s+([A-ZÀ-Üa-zà-ü][a-zà-ü-]+\s+[A-ZÀ-Ü][A-ZÀ-Ü\s-]+)/u', $html, $m)) {
        $data['maire'] = trim($m[1]);
    }
    // Fallback : bloc maire-info dans le HTML
    if (!$data['maire'] && preg_match('/maire-info.*?dirigée par.*?(?:Monsieur|Madame).*?maire\s+([A-ZÀ-Üa-zà-ü][a-zà-ü-]+\s+[A-ZÀ-Ü][A-ZÀ-Ü\s-]+)/su', $html, $m)) {
        $data['maire'] = trim($m[1]);
    }

    // Tel fallback (lien tel:)
    if (!$data['tel'] && preg_match('/href="tel:([^"]+)"/', $html, $m)) {
        $data['tel'] = trim($m[1]);
    }

    // Email fallback (lien mailto:)
    if (!$data['email'] && preg_match('/href="mailto:([^"]+)"/', $html, $m)) {
        $data['email'] = trim(strtolower($m[1]));
    }

    // Site web
    if (preg_match('/Site\s*(?:internet|web|officiel)\s*:\s*<a[^>]+href="(https?:\/\/[^"]+)"/i', $html, $m)) {
        $data['site'] = $m[1];
    }

    // Nettoyer tel
    $data['tel'] = preg_replace('/[^\d+]/', '', $data['tel']);

    return $data;
}

function normalizeName(string $name): string {
    $n = mb_strtolower(trim($name));
    $n = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $n);
    return preg_replace('/\s+/', ' ', $n);
}

// ── 4. Boucle de scraping ──
$updated  = 0;
$notFound = 0;
$errors   = 0;
$skipped  = 0;

foreach ($maires as $i => $maire) {
    $idx = $i + 1;

    // Extraire la commune depuis la fonction (ex: "Maire — Paris" → "Paris")
    $commune = '';
    if (preg_match('/Maire\s*(?:—|-)?\s*(.+)$/i', $maire['fonction'], $m)) {
        $commune = trim($m[1]);
    }
    if (!$commune) {
        echo "[$idx] SKIP {$maire['prenom']} {$maire['nom']} — pas de commune dans fonction\n";
        $skipped++;
        continue;
    }

    // Construire l'URL
    $slug = slugifyCommune($commune);
    // Ajouter le département si disponible pour les homonymes
    $deptSuffix = $maire['departement'] ? '-' . strtolower($maire['departement']) : '';

    // Essayer d'abord sans département, puis avec
    $urls = [
        "https://www.la-mairie.com/$slug",
    ];
    if ($deptSuffix && $deptSuffix !== "-$slug") {
        $urls[] = "https://www.la-mairie.com/{$slug}{$deptSuffix}";
    }

    $html = null;
    $usedUrl = '';
    foreach ($urls as $url) {
        $html = fetchPage($url);
        if ($html) {
            $usedUrl = $url;
            break;
        }
        usleep($delay * 500); // demi-délai entre retries
    }

    if (!$html) {
        echo "[$idx] 404 {$maire['prenom']} {$maire['nom']} ($commune) — $slug\n";
        $notFound++;
        usleep($delay * 1000);
        continue;
    }

    $contact = extractContact($html);

    // Vérifier que le nom du maire matche (éviter les erreurs de commune)
    if ($contact['maire']) {
        $nomBdd  = normalizeName($maire['nom']);
        $nomSite = normalizeName($contact['maire']);
        if (strpos($nomSite, $nomBdd) === false && strpos($nomBdd, $nomSite) === false) {
            // Essayer juste le nom de famille
            $parts = explode(' ', $nomSite);
            $lastNameSite = normalizeName(end($parts));
            if ($lastNameSite !== $nomBdd) {
                echo "[$idx] MISMATCH {$maire['prenom']} {$maire['nom']} ≠ site:{$contact['maire']} ($commune)\n";
                $skipped++;
                usleep($delay * 1000);
                continue;
            }
        }
    }

    // Update BDD
    if ($contact['tel'] || $contact['email'] || $contact['adresse']) {
        $stmtUpdate->execute([
            ':tel'       => $contact['tel'],
            ':email'     => $contact['email'],
            ':adresse'   => $contact['adresse'],
            ':url_fiche' => $contact['site'] ?: $usedUrl,
            ':id'        => $maire['id'],
        ]);
        $updated++;
        echo "[$idx] OK {$maire['prenom']} {$maire['nom']} ($commune) — tel:{$contact['tel']} email:{$contact['email']}\n";
    } else {
        echo "[$idx] EMPTY {$maire['prenom']} {$maire['nom']} ($commune) — aucune donnée contact\n";
        $skipped++;
    }

    // Délai entre requêtes
    usleep($delay * 1000);

    // Progress
    if ($idx % 100 === 0) {
        echo "\n--- Progress: $idx/" . count($maires) . " | Updated: $updated | 404: $notFound | Skip: $skipped ---\n\n";
    }
}

// ── 5. Bilan ──
echo "\n=== BILAN ===\n";
echo "Traités   : " . count($maires) . "\n";
echo "Mis à jour: $updated\n";
echo "404       : $notFound\n";
echo "Skippés   : $skipped\n";
echo "Erreurs   : $errors\n";
echo "=== FIN ===\n";
