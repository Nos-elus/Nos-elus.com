#!/usr/bin/env php
<?php
/**
 * import-photos-csv.php — Importe des photos d'élus depuis un CSV
 *
 * Format CSV : nom;prenom;photo_url;departement (optionnel)
 * Matche par nom+prénom (+ département si fourni) dans la BDD
 *
 * Usage : php scripts/import-photos-csv.php fichier.csv [--dry-run] [--force]
 *   --force : écrase les photos existantes (sauf source_api=manual)
 */

if ($argc < 2 || in_array('--help', $argv)) {
    echo "Usage: php import-photos-csv.php <fichier.csv> [--dry-run] [--force]\n";
    echo "Format CSV: nom;prenom;photo_url;departement\n";
    exit(0);
}

$csvFile = $argv[1];
$dryRun = in_array('--dry-run', $argv);
$force = in_array('--force', $argv);

if (!file_exists($csvFile)) {
    fwrite(STDERR, "Fichier introuvable: $csvFile\n");
    exit(1);
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== Import photos depuis CSV ===\n";
echo "Fichier: $csvFile | Dry-run: " . ($dryRun ? 'OUI' : 'NON') . " | Force: " . ($force ? 'OUI' : 'NON') . "\n\n";

$condition = $force
    ? "AND source_api != 'manual'"
    : "AND source_api != 'manual' AND (photo_url IS NULL OR photo_url = '' OR photo_url LIKE '%dicebear%')";

$updateStmt = $pdo->prepare("UPDATE elus SET photo_url = :photo WHERE id = :id");

$lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$stats = ['total' => 0, 'matched' => 0, 'updated' => 0, 'skipped' => 0, 'not_found' => 0];

foreach ($lines as $i => $line) {
    if ($i === 0 && (stripos($line, 'nom') !== false || stripos($line, 'photo') !== false)) continue; // skip header

    $parts = str_getcsv($line, ';');
    if (count($parts) < 3) continue;

    $nom = trim($parts[0]);
    $prenom = trim($parts[1]);
    $photoUrl = trim($parts[2]);
    $dept = isset($parts[3]) ? trim($parts[3]) : null;

    if (!$nom || !$photoUrl) continue;
    $stats['total']++;

    // Chercher en BDD
    $sql = "SELECT id, nom, prenom, photo_url, source_api FROM elus WHERE LOWER(nom) = LOWER(:nom)";
    $params = [':nom' => $nom];

    if ($prenom) {
        $sql .= " AND LOWER(prenom) = LOWER(:prenom)";
        $params[':prenom'] = $prenom;
    }
    if ($dept) {
        $sql .= " AND departement = :dept";
        $params[':dept'] = $dept;
    }
    $sql .= " $condition LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $elu = $stmt->fetch();

    if (!$elu) {
        // Retry sans accent
        $nomClean = removeAccents($nom);
        $prenomClean = removeAccents($prenom);
        $sql2 = "SELECT id, nom, prenom, photo_url, source_api FROM elus WHERE LOWER(nom) = LOWER(:nom)";
        $params2 = [':nom' => $nomClean];
        if ($prenomClean) {
            $sql2 .= " AND LOWER(prenom) = LOWER(:prenom)";
            $params2[':prenom'] = $prenomClean;
        }
        $sql2 .= " $condition LIMIT 1";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute($params2);
        $elu = $stmt2->fetch();
    }

    if (!$elu) {
        echo "  ❌ $prenom $nom — non trouvé\n";
        $stats['not_found']++;
        continue;
    }

    $stats['matched']++;

    if (!$dryRun) {
        $updateStmt->execute([':photo' => $photoUrl, ':id' => $elu['id']]);
    }
    $stats['updated']++;
    echo "  ✅ $prenom $nom → " . substr($photoUrl, 0, 60) . "...\n";
}

echo "\n=== Résumé ===\n";
echo "Total: {$stats['total']} | Matchés: {$stats['matched']} | Mis à jour: {$stats['updated']} | Non trouvés: {$stats['not_found']}\n";

function removeAccents(string $str): string {
    $t = @\Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
    return $t ? $t->transliterate($str) : $str;
}
