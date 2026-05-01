#!/usr/bin/env php
<?php
/**
 * Calcule le salaire exact de chaque maire en croisant :
 * - Le nom de commune (dans elus.fonction)
 * - La population INSEE (CSV data.gouv.fr)
 * - La grille légale d'indemnités (CGCT art. L2123-23)
 *
 * Stocke la population + salaire brut dans une colonne dédiée.
 */

$dryRun = in_array('--dry-run', $argv);

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

echo "=== Calcul salaires maires (population INSEE) ===\n";
echo "Mode : " . ($dryRun ? "DRY-RUN" : "EXECUTION") . "\n\n";

// Grille indemnités maires (brut mensuel, CGCT 2025)
$grille = [
    ['max' => 499,    'brut' => 1155],
    ['max' => 999,    'brut' => 1672],
    ['max' => 3499,   'brut' => 2139],
    ['max' => 9999,   'brut' => 2396],
    ['max' => 19999,  'brut' => 2880],
    ['max' => 49999,  'brut' => 3699],
    ['max' => 99999,  'brut' => 4521],
    ['max' => 199999, 'brut' => 5960],
    ['max' => PHP_INT_MAX, 'brut' => 5960], // 200k+ même indemnité sauf Paris/Lyon/Marseille
];
$parisLyonMarseille = 9720; // indemnité spéciale PLM

function salaireParPopulation(int $pop, string $commune): int {
    global $grille, $parisLyonMarseille;
    $communeLower = mb_strtolower($commune);
    if ($communeLower === 'paris' || preg_match('/^paris \d+e/i', $communeLower)) return $parisLyonMarseille;
    if ($communeLower === 'lyon' || preg_match('/^lyon \d+e/i', $communeLower)) return $parisLyonMarseille;
    if ($communeLower === 'marseille' || preg_match('/^marseille \d+e/i', $communeLower)) return $parisLyonMarseille;
    foreach ($GLOBALS['grille'] as $tranche) {
        if ($pop <= $tranche['max']) return $tranche['brut'];
    }
    return 5960;
}

// 1. Récupérer les populations via l'API Geo du gouvernement (1 requête, toutes les communes)
function normalizeCommune(string $s): string {
    $s = mb_strtolower(trim($s));
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    }
    return preg_replace('/[^a-z0-9]/', '', $s);
}

$tmpFile = '/tmp/geo-communes.json';
if (!is_file($tmpFile) || filemtime($tmpFile) < time() - 86400 * 7) {
    echo "Téléchargement API Geo...\n";
    $ch = curl_init('https://geo.api.gouv.fr/communes?fields=nom,population&format=json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'nos-elus.fr/1.0']);
    $json = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$json) { echo "Erreur API Geo ($code)\n"; exit(1); }
    file_put_contents($tmpFile, $json);
    echo "OK\n";
} else {
    echo "Cache local Geo\n";
    $json = file_get_contents($tmpFile);
}

$communes = json_decode($json, true);
$populations = [];
foreach ($communes as $c) {
    $key = normalizeCommune($c['nom'] ?? '');
    $pop = (int)($c['population'] ?? 0);
    if ($key && $pop > 0) $populations[$key] = $pop;
}
echo count($populations) . " communes avec population\n\n";

// 3. Ajouter colonne population + salaire_brut si absentes
if (!$dryRun) {
    try { $pdo->exec('ALTER TABLE elus ADD COLUMN population INT DEFAULT NULL AFTER departement'); } catch (\Exception $e) {}
    try { $pdo->exec('ALTER TABLE elus ADD COLUMN salaire_brut INT DEFAULT NULL AFTER population'); } catch (\Exception $e) {}
}

// 4. Matcher chaque maire avec sa population
$stmt = $pdo->query("SELECT id, fonction FROM elus WHERE type_mandat = 'maire' AND source_api != 'manual'");
$maires = $stmt->fetchAll();
echo count($maires) . " maires à traiter\n";

$update = $pdo->prepare('UPDATE elus SET population = :pop, salaire_brut = :sal WHERE id = :id');
$matched = 0;
$unmatched = 0;

foreach ($maires as $m) {
    // Extraire le nom de commune depuis la fonction "Maire — NomCommune" ou "Maire de NomCommune"
    $commune = '';
    if (preg_match('/Maire\s*[—–-]\s*(.+?)(?:\s*\/|$)/i', $m['fonction'], $match)) {
        $commune = trim($match[1]);
    } elseif (preg_match('/Maire\s+de\s+(.+?)(?:\s*\/|$)/i', $m['fonction'], $match)) {
        $commune = trim($match[1]);
    } elseif (preg_match('/maire\s+d\'(.+?)(?:\s*\/|$)/i', $m['fonction'], $match)) {
        $commune = trim($match[1]);
    }

    if (!$commune) { $unmatched++; continue; }

    $key = normalizeCommune($commune);
    $pop = $populations[$key] ?? null;

    if (!$pop) {
        // Essayer sans article (Le/La/Les/L')
        $cleaned = preg_replace('/^(le|la|les|l)\s*/i', '', $commune);
        $pop = $populations[normalizeCommune($cleaned)] ?? null;
    }

    if (!$pop) { $unmatched++; continue; }

    $salaire = salaireParPopulation($pop, $commune);

    if (!$dryRun) {
        $update->execute([':pop' => $pop, ':sal' => $salaire, ':id' => $m['id']]);
    }
    $matched++;

    if ($matched <= 10) {
        echo "  $commune : $pop hab. → $salaire €/mois\n";
    }
    if ($matched % 5000 === 0) echo "  ... $matched matchés\n";
}

echo "\n=== Terminé ===\n";
echo "Matchés    : $matched / " . count($maires) . "\n";
echo "Non matchés: $unmatched\n";
