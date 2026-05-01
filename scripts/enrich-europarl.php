#!/usr/bin/env php
<?php
/**
 * enrich-europarl.php — Enrichit les députés européens FR via data.europarl.europa.eu
 *
 * Usage : php scripts/enrich-europarl.php [--limit=50] [--dry-run]
 */

$opts = getopt('', ['limit:', 'dry-run', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php enrich-europarl.php [--limit=N] [--dry-run]\n";
    exit(0);
}

$limit = (int) ($opts['limit'] ?? 50);
$dryRun = isset($opts['dry-run']);
$RATE_DELAY = 300000; // 300ms entre chaque appel

// ── BDD ──
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERREUR BDD: " . $e->getMessage() . "\n");
    exit(1);
}

echo "=== Enrichissement Europarl — députés européens FR ===\n";
echo "Limite: $limit | Dry-run: " . ($dryRun ? 'OUI' : 'NON') . "\n\n";

// ── Européens FR sans bio/photo/date_naissance ──
$stmt = $pdo->prepare("
    SELECT id, nom, prenom, slug, photo_url, date_naissance, bio
    FROM elus
    WHERE type_mandat = 'europeen'
      AND source_api != 'manual'
      AND (photo_url IS NULL OR photo_url = '' OR date_naissance IS NULL OR bio IS NULL OR bio = '')
    ORDER BY nb_consultations DESC
    LIMIT :lim
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$elus = $stmt->fetchAll();

echo count($elus) . " européen(s) à enrichir\n\n";
if (empty($elus)) { echo "Rien à faire.\n"; exit(0); }

function curlGet(string $url, string $accept = 'application/ld+json'): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'nos-elus.fr/1.0 (https://nos-elus.fr)',
        CURLOPT_HTTPHEADER => ["Accept: $accept"],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 400 && $res) ? $res : null;
}

$updatePhoto = $pdo->prepare('UPDATE elus SET photo_url = :p WHERE id = :id AND (photo_url IS NULL OR photo_url = "")');
$updateBirth = $pdo->prepare('UPDATE elus SET date_naissance = :d WHERE id = :id AND date_naissance IS NULL');
$updateBio   = $pdo->prepare('UPDATE elus SET bio = :b WHERE id = :id AND (bio IS NULL OR bio = "")');
$updateBirthPlace = $pdo->prepare('UPDATE elus SET lieu_naissance = :l WHERE id = :id AND (lieu_naissance IS NULL OR lieu_naissance = "")');

$stats = ['traites' => 0, 'enrichis' => 0, 'photos' => 0, 'naissances' => 0, 'bios' => 0, 'not_found' => 0];

foreach ($elus as $i => $elu) {
    $stats['traites']++;
    $nom = trim($elu['prenom'] . ' ' . $elu['nom']);
    echo "[" . ($i + 1) . "/" . count($elus) . "] $nom ... ";

    // Chercher l'ID Europarl via l'API listing
    $searchUrl = 'https://data.europarl.europa.eu/api/v2/meps?format=application%2Fld%2Bjson&offset=0&limit=50';
    // On ne peut pas filtrer par pays via l'API, on cherche par nom
    // Utiliser le XML directory à la place
    $xmlUrl = 'https://www.europarl.europa.eu/meps/en/directory/xml/';

    // Chercher dans le XML (cache en mémoire au 1er appel)
    static $allMeps = null;
    if ($allMeps === null) {
        echo "(chargement directory...) ";
        $xml = curlGet($xmlUrl, 'application/xml');
        if (!$xml) { echo "❌ directory inaccessible\n"; continue; }
        preg_match_all('/<mep><fullName>([^<]+)<\/fullName><id>(\d+)<\/id><\/mep>/', $xml, $matches, PREG_SET_ORDER);
        $allMeps = [];
        foreach ($matches as $m) {
            $allMeps[] = ['name' => $m[1], 'id' => $m[2]];
        }
        echo count($allMeps) . " MEPs chargés... ";
    }

    // Matching par nom (normaliser accents et casse)
    $searchNom = mb_strtoupper(removeAccents($elu['nom']));
    $searchPrenom = mb_strtoupper(removeAccents($elu['prenom'] ?? ''));
    $found = null;
    foreach ($allMeps as $mep) {
        $mepName = mb_strtoupper(removeAccents($mep['name']));
        if (mb_strpos($mepName, $searchNom) !== false && mb_strpos($mepName, $searchPrenom) !== false) {
            $found = $mep;
            break;
        }
    }
    // Fallback: juste le nom de famille
    if (!$found) {
        foreach ($allMeps as $mep) {
            $mepName = mb_strtoupper(removeAccents($mep['name']));
            if (mb_strpos($mepName, $searchNom) !== false) {
                $found = $mep;
                break;
            }
        }
    }

    if (!$found) {
        echo "❌ non trouvé\n";
        $stats['not_found']++;
        continue;
    }

    $mepId = $found['id'];
    echo "→ ID $mepId ";

    // Fetch fiche détaillée
    $detailUrl = "https://data.europarl.europa.eu/api/v2/meps/$mepId?format=application%2Fld%2Bjson";
    $json = curlGet($detailUrl);
    if (!$json) { echo "❌ fiche inaccessible\n"; continue; }

    $data = json_decode($json, true);
    if (!$data || empty($data['data'])) { echo "❌ JSON vide\n"; continue; }

    $mepData = is_array($data['data']) && isset($data['data'][0]) ? $data['data'][0] : $data['data'];
    $enriched = false;

    // Photo
    $photoUrl = $mepData['hasImage'] ?? $mepData['img'] ?? null;
    if (!$photoUrl) $photoUrl = "https://www.europarl.europa.eu/mepphoto/$mepId.jpg";
    if (empty($elu['photo_url']) && $photoUrl) {
        if (!$dryRun) $updatePhoto->execute([':p' => $photoUrl, ':id' => $elu['id']]);
        $stats['photos']++;
        $enriched = true;
        echo "📷 ";
    }

    // Date de naissance
    $birth = $mepData['hasBirthDate'] ?? $mepData['bday'] ?? null;
    if (!$birth && isset($mepData['birthDate'])) $birth = $mepData['birthDate'];
    if (empty($elu['date_naissance']) && $birth) {
        $birthDate = substr($birth, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            if (!$dryRun) $updateBirth->execute([':d' => $birthDate, ':id' => $elu['id']]);
            $stats['naissances']++;
            $enriched = true;
            echo "🎂 ";
        }
    }

    // Lieu de naissance
    $birthPlace = $mepData['birthPlace'] ?? $mepData['placeOfBirth'] ?? null;
    if ($birthPlace && !$dryRun) {
        $updateBirthPlace->execute([':l' => $birthPlace, ':id' => $elu['id']]);
    }

    // Bio (construire à partir des données)
    if (empty($elu['bio'])) {
        $parts = [];
        if ($birth) $parts[] = "Né" . ($mepData['hasGender'] === 'female' ? "e" : "") . " le " . date('d/m/Y', strtotime($birth));
        if ($birthPlace) $parts[-1] = ($parts[count($parts)-1] ?? '') . " à $birthPlace";
        $parts[] = "Député(e) européen(ne) au Parlement européen.";
        $bio = implode('. ', array_filter($parts));
        if ($bio && !$dryRun) {
            $updateBio->execute([':b' => $bio, ':id' => $elu['id']]);
            $stats['bios']++;
            $enriched = true;
            echo "📝 ";
        }
    }

    if ($enriched) $stats['enrichis']++;
    echo "✅\n";
    usleep($RATE_DELAY);
}

echo "\n=== Résumé ===\n";
echo "Traités: {$stats['traites']} | Enrichis: {$stats['enrichis']} | Photos: {$stats['photos']} | Naissances: {$stats['naissances']} | Bios: {$stats['bios']} | Non trouvés: {$stats['not_found']}\n";

function removeAccents(string $str): string {
    $t = @\Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
    return $t ? $t->transliterate($str) : $str;
}
