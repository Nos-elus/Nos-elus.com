<?php
/**
 * Cron quotidien — Import votes nominatifs Assemblée Nationale.
 *
 * Usage CLI : php cron-votes-an.php [--full]
 *   --full : réimporte tous les scrutins (sinon incrémental depuis dernier import)
 *
 * Cron : 0 5 * * * php cron-votes-an.php
 */

$startTime = microtime(true);

// Charger la config BDD
require_once __DIR__ . '/config.php';

// ── Protection : CLI ou localhost uniquement ──
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/fetcher/ANVotesFetcher.php';

$full = in_array('--full', $argv ?? []);

echo "=== IMPORT VOTES AN — " . date('Y-m-d H:i:s') . " ===\n";
echo "Mode: " . ($full ? 'FULL' : 'INCREMENTAL') . "\n\n";

$fetcher = new ANVotesFetcher($pdo, '/tmp/scrutins_an', 200);

// Étape 1 : Télécharger les scrutins
if (!$fetcher->downloadScrutins()) {
    echo "ECHEC: telechargement impossible\n";
    $fetcher->log('error', ['votes' => 0, 'error' => 'download_failed'], 0);
    exit(1);
}

// Étape 2 : Construire/vérifier le mapping député → elu_id
$matched = $fetcher->buildMapping();
if ($matched === 0) {
    echo "ECHEC: aucun depute matche\n";
    $fetcher->log('error', ['votes' => 0, 'error' => 'no_mapping'], 0);
    exit(1);
}

// Étape 3 : Déterminer la date de dernière sync (pour l'incrémental)
$sinceDate = null;
if (!$full) {
    $stmt = $pdo->query("
        SELECT MAX(created_at) FROM fetch_log
        WHERE source = 'an_votes' AND status = 'success'
    ");
    $lastSync = $stmt->fetchColumn();
    if ($lastSync) {
        // Prendre les scrutins des 7 derniers jours pour rattraper d'éventuels retards
        $sinceDate = date('Y-m-d', strtotime($lastSync . ' -7 days'));
        echo "Incremental depuis: $sinceDate\n";
    } else {
        echo "Premiere importation: mode FULL force\n";
    }
}

// Étape 4 : Importer
echo "\n";
$stats = $fetcher->importScrutins($sinceDate);

$duration = (int) ((microtime(true) - $startTime) * 1000);

echo "\n=== RESULTAT ===\n";
echo "Scrutins importes: {$stats['scrutins']}\n";
echo "Votes inseres: {$stats['votes']}\n";
echo "Scrutins ignores (< 200 votants ou deja importes): {$stats['skipped']}\n";
echo "Erreurs: " . ($stats['errors'] ?? 0) . "\n";
echo "Duree: " . round($duration / 1000, 1) . "s\n";

// Log
$status = ($stats['errors'] ?? 0) > 0 && $stats['votes'] === 0 ? 'error' : 'success';
$fetcher->log($status, $stats, $duration);

// Étape 5 : Purger le cache (préfixes spécifiques UNIQUEMENT)
echo "\nPurge cache...\n";
$cacheDir = __DIR__ . '/cache/data/';
foreach (['elu_', 'palmares_', 'stats_'] as $prefix) {
    foreach (glob($cacheDir . $prefix . '*.json') as $f) {
        @unlink($f);
    }
}
echo "Cache purge (elu_*, palmares_*, stats_*)\n";

// Étape 6 : Calculer l'activité parlementaire (commissions + questions + taux global)
echo "\n=== ACTIVITE PARLEMENTAIRE ===\n";
require_once __DIR__ . '/fetcher/ANActiviteFetcher.php';
$activite = new ANActiviteFetcher($pdo);

// Votes (depuis la BDD, déjà importés)
$activite->calcVotes();

// Commissions (télécharger si pas déjà fait)
$reunionsDir = '/tmp/reunions_an';
if (!is_dir($reunionsDir . '/json/reunion')) {
    echo "Telechargement reunions AN...\n";
    $zipData = @file_get_contents('https://data.assemblee-nationale.fr/static/openData/repository/17/vp/reunions/Agenda.json.zip');
    if ($zipData) {
        file_put_contents('/tmp/reunions_an.zip', $zipData);
        @mkdir($reunionsDir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open('/tmp/reunions_an.zip') === true) { $zip->extractTo($reunionsDir); $zip->close(); }
        @unlink('/tmp/reunions_an.zip');
        echo "  " . count(glob($reunionsDir . '/json/reunion/*.json')) . " reunions extraites\n";
    }
}
$activite->importCommissions($reunionsDir);

// Questions écrites
$questionsDir = '/tmp/questions_an';
if (!is_dir($questionsDir . '/json')) {
    echo "Telechargement questions AN...\n";
    $zipData = @file_get_contents('https://data.assemblee-nationale.fr/static/openData/repository/17/questions/questions_ecrites/Questions_ecrites.json.zip');
    if ($zipData) {
        file_put_contents('/tmp/questions_an.zip', $zipData);
        @mkdir($questionsDir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open('/tmp/questions_an.zip') === true) { $zip->extractTo($questionsDir); $zip->close(); }
        @unlink('/tmp/questions_an.zip');
        echo "  " . count(glob($questionsDir . '/json/*.json')) . " questions extraites\n";
    }
}
$activite->importQuestions($questionsDir);

// Taux global
$activite->calcTauxGlobal();

echo "\n=== TERMINE ===\n";
