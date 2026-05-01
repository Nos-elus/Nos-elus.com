#!/usr/bin/env php
<?php
/**
 * Télécharge toutes les photos externes en local sur le serveur.
 * Remplace photo_url par le chemin local /photos/{slug}.jpg
 *
 * Usage :
 *   php scripts/cache-photos-local.php
 *   php scripts/cache-photos-local.php --dry-run
 *   php scripts/cache-photos-local.php --limit=100
 */

$dryRun = in_array('--dry-run', $argv);
$limit = 1000;
foreach ($argv as $a) { if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1]; }

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$photosDir = dirname(__DIR__) . '/photos';
if (!is_dir($photosDir)) mkdir($photosDir, 0755, true);

echo "=== Cache photos locales ===\n";
echo "Mode : " . ($dryRun ? "DRY-RUN" : "EXECUTION") . "\n\n";

// Sélectionner les élus avec photo_url externe (pas déjà locale)
$stmt = $pdo->prepare("
    SELECT id, slug, photo_url FROM elus
    WHERE photo_url IS NOT NULL AND photo_url != ''
      AND photo_url NOT LIKE '/photos/%'
    ORDER BY nb_consultations DESC
    LIMIT :lim
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$elus = $stmt->fetchAll();

echo count($elus) . " photos externes à télécharger\n\n";

$update = $pdo->prepare('UPDATE elus SET photo_url = :url WHERE id = :id');
$ok = 0;
$fail = 0;

foreach ($elus as $i => $elu) {
    $slug = $elu['slug'] ?: 'elu-' . $elu['id'];
    $ext = 'jpg';
    $localPath = $photosDir . '/' . $slug . '.' . $ext;
    $localUrl = '/photos/' . $slug . '.' . $ext;

    // Déjà en local ?
    if (file_exists($localPath) && filesize($localPath) > 1000) {
        if (!$dryRun) $update->execute([':url' => $localUrl, ':id' => $elu['id']]);
        $ok++;
        continue;
    }

    // Télécharger
    $ch = curl_init($elu['photo_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 nos-elus.fr/1.0',
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    curl_close($ch);

    if ($httpCode === 200 && strlen($data) > 1000 && strpos($contentType, 'image') !== false) {
        if (!$dryRun) {
            file_put_contents($localPath, $data);
            $update->execute([':url' => $localUrl, ':id' => $elu['id']]);
        }
        $ok++;
        if ($ok <= 20 || $ok % 100 === 0) echo "  [OK] $slug\n";
    } else {
        $fail++;
        if ($fail <= 10) echo "  [FAIL] $slug (HTTP $httpCode, " . strlen($data) . " bytes)\n";
    }

    usleep(100000); // 100ms entre chaque
    if (($i + 1) % 200 === 0) echo "  ... " . ($i + 1) . "/" . count($elus) . " ($ok ok, $fail fail)\n";
}

echo "\n=== Terminé ===\n";
echo "Téléchargées : $ok\n";
echo "Échouées     : $fail\n";
