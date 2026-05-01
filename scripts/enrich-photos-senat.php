#!/usr/bin/env php
<?php
/**
 * Récupère les photos des sénateurs depuis senat.fr
 * Scrape la liste, matche par nom/prénom, met à jour photo_url.
 */

$dryRun = in_array('--dry-run', $argv);

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

echo "=== Photos sénateurs depuis senat.fr ===\n\n";

// 1. Scraper la liste des sénateurs avec leurs slugs sénat
$html = file_get_contents('https://www.senat.fr/senateurs/senatl.html', false, stream_context_create([
    'http' => ['header' => 'User-Agent: nos-elus.fr/1.0', 'timeout' => 15]
]));
if (!$html) { echo "Erreur : impossible de charger senat.fr\n"; exit(1); }

preg_match_all('/senateur\/([^"]+)\.html/', $html, $matches);
$slugsSenat = array_unique($matches[1] ?? []);
echo count($slugsSenat) . " sénateurs trouvés sur senat.fr\n";

// 2. Charger nos sénateurs sans photo
$stmt = $pdo->query("SELECT id, nom, prenom FROM elus WHERE (type_mandat='senateur' OR fonction LIKE '%énat%') AND (photo_url IS NULL OR photo_url='') AND source_api != 'manual'");
$senateurs = $stmt->fetchAll();
echo count($senateurs) . " sénateurs sans photo en BDD\n\n";

// Indexer les slugs sénat par nom normalisé
function normalize(string $s): string {
    $s = mb_strtolower(trim($s));
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    }
    return preg_replace('/[^a-z]/', '', $s);
}

$senatIndex = [];
foreach ($slugsSenat as $slug) {
    // slug = nom_prenom_ID → extraire nom et prénom
    $parts = explode('_', preg_replace('/\d+[a-z]*$/', '', $slug));
    $key = implode('', array_map('normalize', $parts));
    $senatIndex[$key] = $slug;
}

$update = $pdo->prepare('UPDATE elus SET photo_url = :url WHERE id = :id');
$found = 0;

foreach ($senateurs as $sen) {
    $nomNorm = normalize($sen['nom']);
    $prenomNorm = normalize($sen['prenom'] ?? '');

    // Essayer nom+prénom et prénom+nom
    $keys = [
        $nomNorm . $prenomNorm,
        $prenomNorm . $nomNorm,
    ];

    $matched = null;
    foreach ($keys as $k) {
        if (isset($senatIndex[$k])) { $matched = $senatIndex[$k]; break; }
        // Fuzzy : chercher dans les clés par str_contains
        foreach ($senatIndex as $sk => $sv) {
            if (str_contains($sk, $nomNorm) && str_contains($sk, $prenomNorm)) { $matched = $sv; break 2; }
        }
    }

    if (!$matched) continue;

    $photoUrl = "https://www.senat.fr/senimg/{$matched}_carre.jpg";

    // Vérifier que l'image existe (HEAD request)
    $ch = curl_init($photoUrl);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 5, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'nos-elus.fr/1.0']);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        if (!$dryRun) {
            $update->execute([':url' => $photoUrl, ':id' => $sen['id']]);
        }
        $found++;
        echo "  [OK] " . ($sen['prenom'] ?? '') . " " . $sen['nom'] . " → $matched\n";
    }
    usleep(200000); // 200ms
}

echo "\nPhotos trouvées : $found / " . count($senateurs) . "\n";
