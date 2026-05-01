#!/usr/bin/env php
<?php
/**
 * Enrichit les photos des députés via NosDéputés.fr (curl, rapide)
 * et des sénateurs via Wikidata
 */

$dryRun = in_array('--dry-run', $argv);

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

echo "=== Enrichissement photos parlementaires ===\n\n";

// ── Députés : NosDéputés.fr ──
echo "--- Députés ---\n";

$stmt = $pdo->query("
    SELECT id, nom, prenom, slug FROM elus
    WHERE type_mandat = 'depute'
      AND (photo_url IS NULL OR photo_url = '')
      AND source_api != 'manual'
");
$deputes = $stmt->fetchAll();
echo count($deputes) . " sans photo\n";

$update = $pdo->prepare('UPDATE elus SET photo_url = :url WHERE id = :id');
$found = 0;

foreach ($deputes as $i => $dep) {
    $slug = slugify(($dep['prenom'] ?? '') . ' ' . $dep['nom']);
    $url = "https://www.nosdeputes.fr/depute/photo/{$slug}/200";

    // Vérifier via curl HEAD
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'nos-elus.fr/1.0',
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode === 200 && strpos($contentType, 'image') !== false) {
        if (!$dryRun) {
            $update->execute([':url' => $url, ':id' => $dep['id']]);
        }
        $found++;
        echo "  [OK] " . ($dep['prenom'] ?? '') . " " . $dep['nom'] . "\n";
    }
    usleep(100000); // 100ms
    if (($i + 1) % 100 === 0) echo "  ... " . ($i + 1) . "/" . count($deputes) . " ($found trouvés)\n";
}
echo "Résultat : $found photos\n\n";

// ── Sénateurs : construire URL directe senat.fr ──
echo "--- Sénateurs ---\n";

$stmt = $pdo->query("
    SELECT id, nom, prenom FROM elus
    WHERE type_mandat = 'senateur'
      AND (photo_url IS NULL OR photo_url = '')
      AND source_api != 'manual'
");
$senateurs = $stmt->fetchAll();
echo count($senateurs) . " sans photo\n";

$foundSen = 0;

foreach ($senateurs as $i => $sen) {
    // NosSénateurs.fr (même structure que NosDéputés)
    $slug = slugify(($sen['prenom'] ?? '') . ' ' . $sen['nom']);
    $url = "https://www.nossenateurs.fr/senateur/photo/{$slug}/200";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'nos-elus.fr/1.0',
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode === 200 && $contentType && strpos($contentType, 'image') !== false) {
        if (!$dryRun) {
            $update->execute([':url' => $url, ':id' => $sen['id']]);
        }
        $foundSen++;
        echo "  [OK] " . ($sen['prenom'] ?? '') . " " . $sen['nom'] . "\n";
    }
    usleep(100000);
    if (($i + 1) % 100 === 0) echo "  ... " . ($i + 1) . "/" . count($senateurs) . " ($foundSen trouvés)\n";
}
echo "Résultat : $foundSen photos\n\n";

echo "=== Total : " . ($found + $foundSen) . " photos enrichies ===\n";

function slugify(string $text): string {
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    } else {
        $text = mb_strtolower($text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    }
    return trim(preg_replace('/[^a-z0-9]+/', '-', $text), '-');
}
