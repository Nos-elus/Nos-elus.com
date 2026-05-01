#!/usr/bin/env php
<?php
/**
 * Enrichit les photos des députés (NosDéputés.fr) et sénateurs (Sénat.fr)
 *
 * Sources :
 *   - Députés : https://www.nosdeputes.fr/depute/photo/{slug}/200
 *   - Sénateurs : https://www.senat.fr/senimg/nom_prenom.jpg (pattern)
 *
 * Usage :
 *   php scripts/enrich-photos-parlementaires.php
 *   php scripts/enrich-photos-parlementaires.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv);

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo "Erreur BDD : " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Enrichissement photos parlementaires ===\n";
echo "Mode : " . ($dryRun ? "DRY-RUN" : "EXECUTION") . "\n\n";

// ── 1. Députés via NosDéputés.fr ──
echo "--- Députés (NosDéputés.fr) ---\n";

$stmt = $pdo->query("
    SELECT id, nom, prenom, slug
    FROM elus
    WHERE type_mandat = 'depute'
      AND (photo_url IS NULL OR photo_url = '')
      AND source_api != 'manual'
");
$deputes = $stmt->fetchAll();
echo count($deputes) . " députés sans photo\n";

$found = 0;
$updateStmt = $pdo->prepare('UPDATE elus SET photo_url = :url WHERE id = :id');

foreach ($deputes as $i => $dep) {
    $slug = slugify(($dep['prenom'] ?? '') . ' ' . $dep['nom']);
    $url = "https://www.nosdeputes.fr/depute/photo/{$slug}/200";

    // Vérifier que l'URL renvoie une image (HEAD request)
    $headers = @get_headers($url, true);
    $contentType = $headers['Content-Type'] ?? '';
    if (is_array($contentType)) $contentType = end($contentType);

    if ($headers && strpos($headers[0], '200') !== false && strpos($contentType, 'image') !== false) {
        if (!$dryRun) {
            $updateStmt->execute([':url' => $url, ':id' => $dep['id']]);
        }
        $found++;
        echo "  [" . ($i + 1) . "] " . ($dep['prenom'] ?? '') . " " . $dep['nom'] . " -> OK\n";
    }

    if (($i + 1) % 50 === 0) {
        echo "  ... " . ($i + 1) . "/" . count($deputes) . " traités ($found trouvés)\n";
    }
    usleep(200000); // 200ms entre chaque appel
}
echo "Députés : $found photos trouvées\n\n";

// ── 2. Sénateurs via Sénat.fr ──
echo "--- Sénateurs (senat.fr) ---\n";

$stmt = $pdo->query("
    SELECT id, nom, prenom, slug
    FROM elus
    WHERE type_mandat = 'senateur'
      AND (photo_url IS NULL OR photo_url = '')
      AND source_api != 'manual'
");
$senateurs = $stmt->fetchAll();
echo count($senateurs) . " sénateurs sans photo\n";

$foundSen = 0;

foreach ($senateurs as $i => $sen) {
    // Pattern Sénat.fr : /senimg/prenom_nom.jpg (minuscule, sans accents, underscore)
    $prenomClean = slugifyUnderscore($sen['prenom'] ?? '');
    $nomClean = slugifyUnderscore($sen['nom']);
    $url = "https://www.senat.fr/senimg/{$nomClean}_{$prenomClean}.jpg";

    $headers = @get_headers($url, true);
    $contentType = $headers['Content-Type'] ?? '';
    if (is_array($contentType)) $contentType = end($contentType);

    if ($headers && strpos($headers[0], '200') !== false && strpos($contentType, 'image') !== false) {
        if (!$dryRun) {
            $updateStmt->execute([':url' => $url, ':id' => $sen['id']]);
        }
        $foundSen++;
        echo "  [" . ($i + 1) . "] " . ($sen['prenom'] ?? '') . " " . $sen['nom'] . " -> OK\n";
    }

    if (($i + 1) % 50 === 0) {
        echo "  ... " . ($i + 1) . "/" . count($senateurs) . " traités ($foundSen trouvés)\n";
    }
    usleep(200000); // 200ms
}
echo "Sénateurs : $foundSen photos trouvées\n\n";

echo "=== Terminé ===\n";
echo "Total : " . ($found + $foundSen) . " photos ajoutées\n";

// ── Helpers ──

function slugify(string $text): string {
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function slugifyUnderscore(string $text): string {
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    return trim($text, '_');
}
