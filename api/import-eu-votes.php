<?php
/**
 * Import des votes nominatifs des députés européens français.
 * Source : HowTheyVote.eu (CSV GitHub, mise à jour hebdomadaire)
 */
require_once __DIR__ . '/config.php';

// ── Protection : CLI ou localhost uniquement ──
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

$startTime = microtime(true);
echo "=== IMPORT VOTES EU — " . date('Y-m-d H:i:s') . " ===\n";

// 1. Télécharger les CSV
echo "Telechargement votes EU...\n";
$membersUrl = 'https://github.com/HowTheyVote/data/releases/latest/download/members.csv.gz';
$votesUrl = 'https://github.com/HowTheyVote/data/releases/latest/download/votes.csv.gz';
$memberVotesUrl = 'https://github.com/HowTheyVote/data/releases/latest/download/member_votes.csv.gz';

// Télécharger et décompresser
foreach (['members' => $membersUrl, 'votes' => $votesUrl, 'member_votes' => $memberVotesUrl] as $name => $url) {
    $gz = "/tmp/eu_{$name}.csv.gz";
    $csv = "/tmp/eu_{$name}.csv";
    file_put_contents($gz, file_get_contents($url));
    // Décompresser
    $fp = gzopen($gz, 'rb');
    $out = fopen($csv, 'wb');
    while (!gzeof($fp)) fwrite($out, gzread($fp, 65536));
    gzclose($fp); fclose($out);
    unlink($gz);
    echo "  $name: " . number_format(filesize($csv)) . " octets\n";
}

// 2. Charger les MEPs français
$frMeps = [];
if (($fp = fopen('/tmp/eu_members.csv', 'r')) !== false) {
    $headers = fgetcsv($fp);
    while ($row = fgetcsv($fp)) {
        $data = array_combine($headers, $row);
        if ($data['country_code'] === 'FRA') {
            $frMeps[$data['id']] = ['first_name' => $data['first_name'], 'last_name' => $data['last_name']];
        }
    }
    fclose($fp);
}
echo count($frMeps) . " MEPs francais\n";

// 3. Charger les scrutins (10e législature uniquement)
$scrutins = [];
if (($fp = fopen('/tmp/eu_votes.csv', 'r')) !== false) {
    $headers = fgetcsv($fp);
    while ($row = fgetcsv($fp)) {
        $data = array_combine($headers, $row);
        if ($data['timestamp'] >= '2024-07-16') {
            $scrutins[$data['id']] = [
                'titre' => mb_substr($data['display_title'] ?: $data['description'] ?: $data['reference'] ?: '', 0, 500, 'UTF-8'),
                'date' => substr($data['timestamp'], 0, 10),
                'ref' => $data['reference'] ?? '',
            ];
        }
    }
    fclose($fp);
}
echo count($scrutins) . " scrutins EU 10e legislature\n";

// 4. Mapping MEP → elu_id
$mapping = [];
$stmtFind = $pdo->prepare("SELECT id FROM elus WHERE LOWER(nom) = LOWER(:nom) AND LOWER(prenom) = LOWER(:prenom) LIMIT 1");
foreach ($frMeps as $mid => $m) {
    $stmtFind->execute([':nom' => $m['last_name'], ':prenom' => $m['first_name']]);
    $elu = $stmtFind->fetch();
    if ($elu) $mapping[$mid] = (int) $elu['id'];
}
echo count($mapping) . " MEPs mappes en BDD\n";

// 5. Insérer les votes
$positionMap = ['FOR' => 'Pour', 'AGAINST' => 'Contre', 'ABSTENTION' => 'Abstention', 'DID_NOT_VOTE' => 'Non-votant'];
$stmtInsert = $pdo->prepare("INSERT IGNORE INTO votes (elu_id, sujet, position, date_vote, scrutin_id) VALUES (:eid, :sujet, :pos, :date, :sid)");
$inserted = 0; $skipped = 0;

if (($fp = fopen('/tmp/eu_member_votes.csv', 'r')) !== false) {
    $headers = fgetcsv($fp);
    while ($row = fgetcsv($fp)) {
        $data = array_combine($headers, $row);
        $mid = $data['member_id'];
        $vid = $data['vote_id'];
        if (!isset($mapping[$mid]) || !isset($scrutins[$vid])) { $skipped++; continue; }
        // Ne pas importer les "non-votant" (pas un vote)
        $pos = $positionMap[$data['position']] ?? null;
        if (!$pos || $pos === 'Non-votant') { $skipped++; continue; }

        $sc = $scrutins[$vid];
        $stmtInsert->execute([
            ':eid' => $mapping[$mid],
            ':sujet' => $sc['titre'],
            ':pos' => $pos,
            ':date' => $sc['date'],
            ':sid' => 'EU_' . $vid,
        ]);
        if ($stmtInsert->rowCount() > 0) $inserted++;
        else $skipped++;
    }
    fclose($fp);
}

$duration = round((microtime(true) - $startTime) * 1000);
echo "\n=== RESULTAT ===\n";
echo "Votes inseres: $inserted\n";
echo "Ignores: $skipped\n";
echo "Duree: " . round($duration / 1000, 1) . "s\n";

// 6. Log
$pdo->prepare("INSERT INTO fetch_log (source, endpoint, status, records_count, duration_ms) VALUES ('eu_votes', 'howtheyvote.eu', 'success', :count, :dur)")
    ->execute([':count' => $inserted, ':dur' => $duration]);

// 7. Purge cache (préfixes spécifiques UNIQUEMENT)
foreach (['elu_', 'palmares_', 'stats_'] as $prefix) {
    foreach (glob(__DIR__ . '/cache/data/' . $prefix . '*.json') as $f) @unlink($f);
}
echo "Cache purge\n";

// Nettoyage
@unlink('/tmp/eu_members.csv');
@unlink('/tmp/eu_votes.csv');
@unlink('/tmp/eu_member_votes.csv');
echo "=== TERMINE ===\n";
