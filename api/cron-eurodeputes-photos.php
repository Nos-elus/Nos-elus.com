<?php
/**
 * CRON — Photos officielles des eurodéputés français depuis europarl.europa.eu
 *
 * Source : XML https://www.europarl.europa.eu/meps/fr/full-list/xml
 * Photos : https://www.europarl.europa.eu/mepphoto/<MEP_ID>.jpg
 *
 * Pour chaque eurodéputé français en BDD (type_mandat=europe), match par nom,
 * télécharge la photo officielle, la convertit en WebP q=85, l'enregistre dans
 * /photos/cached/<slug>.webp, met à jour elus.photo_url.
 *
 * Usage :
 *   php cron-eurodeputes-photos.php             → run
 *   php cron-eurodeputes-photos.php --dry-run   → simulation
 *   php cron-eurodeputes-photos.php --force     → re-télécharge même si déjà WebP
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); exit('Forbidden');
}

ini_set('max_execution_time', 0);
ini_set('memory_limit', '128M');

$dryRun = in_array('--dry-run', $argv ?? []);
$force  = in_array('--force', $argv ?? []);

function logLine(string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . "\n"; }

logLine('=== EURODEPUTES PHOTOS — ' . date('Y-m-d H:i:s')
    . ($dryRun ? ' (DRY)' : '')
    . ($force ? ' (FORCE)' : '')
    . ' ===');

// ── 1. XML eurodéputés ──
$xmlUrl = 'https://www.europarl.europa.eu/meps/fr/full-list/xml';
$ch = curl_init($xmlUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'nos-elus.com/1.0',
    CURLOPT_TIMEOUT        => 60,
]);
$xmlBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$xmlBody) {
    logLine("ERREUR download XML — HTTP $httpCode");
    exit(1);
}
logLine('XML téléchargé : ' . number_format(strlen($xmlBody)) . ' octets');

$xml = simplexml_load_string($xmlBody);
if (!$xml) { logLine('ERREUR parse XML'); exit(1); }

// ── 2. Filtrer France ──
$french = [];
foreach ($xml->mep as $mep) {
    if ((string)$mep->country !== 'France') continue;
    $french[] = [
        'id'   => (string)$mep->id,
        'name' => (string)$mep->fullName,
        'group'=> (string)$mep->politicalGroup,
    ];
}
logLine('Eurodéputés français trouvés dans XML : ' . count($french));

// Index pour match : normalize(name) → mep
function normName(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
$xmlByName = [];
foreach ($french as $m) {
    $xmlByName[normName($m['name'])] = $m;
}

// ── 3. Eurodéputés en BDD ──
$rows = $pdo->query("
    SELECT id, prenom, nom, slug, photo_url, fonction, type_mandat
    FROM elus
    WHERE actif = 1
      AND (type_mandat = 'europe'
           OR LOWER(fonction) LIKE '%député européen%'
           OR LOWER(fonction) LIKE '%députée européenne%'
           OR LOWER(fonction) LIKE '%urodéputé%')
")->fetchAll(PDO::FETCH_ASSOC);
logLine('Eurodéputés actifs en BDD : ' . count($rows));

$cacheDir = '/var/www/noselus/public/photos/cached';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$stmtUpdate = $pdo->prepare("UPDATE elus SET photo_url = :url WHERE id = :id");

$stats = ['total_bdd' => count($rows), 'matched' => 0, 'unmatched' => 0,
          'downloaded' => 0, 'skipped' => 0, 'updated_url' => 0, 'errors' => 0];

foreach ($rows as $row) {
    $bddName = normName($row['prenom'] . ' ' . $row['nom']);
    $bddNameRev = normName($row['nom'] . ' ' . $row['prenom']);

    $mep = $xmlByName[$bddName] ?? $xmlByName[$bddNameRev] ?? null;

    if (!$mep) {
        // Match partiel (nom seulement, en cas d'écart de prenom)
        foreach ($xmlByName as $k => $cand) {
            if (str_contains($k, normName($row['nom'])) && str_contains($k, normName($row['prenom']))) {
                $mep = $cand; break;
            }
        }
    }

    if (!$mep) {
        $stats['unmatched']++;
        logLine("   [NO MATCH] {$row['prenom']} {$row['nom']} ({$row['slug']})");
        continue;
    }

    $stats['matched']++;
    $mepId = $mep['id'];
    $slug  = $row['slug'] ?: 'mep-' . $mepId;
    $webpPath = $cacheDir . '/' . $slug . '.webp';
    $expectedUrl = '/photos/cached/' . $slug . '.webp';

    // Si déjà à jour, skip sauf --force
    if (!$force && file_exists($webpPath) && $row['photo_url'] === $expectedUrl) {
        $stats['skipped']++;
        continue;
    }

    if ($dryRun) {
        logLine("   [DRY] would download MEP $mepId → $webpPath (BDD photo_url='{$row['photo_url']}')");
        $stats['downloaded']++;
        if ($row['photo_url'] !== $expectedUrl) $stats['updated_url']++;
        continue;
    }

    // Téléchargement
    $photoUrl = "https://www.europarl.europa.eu/mepphoto/$mepId.jpg";
    $tmpJpg = '/tmp/mep_' . $mepId . '.jpg';

    $ch = curl_init($photoUrl);
    $fp = fopen($tmpJpg, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE      => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'nos-elus.com/1.0',
        CURLOPT_TIMEOUT   => 30,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code !== 200 || !filesize($tmpJpg)) {
        $stats['errors']++;
        logLine("   [ERR DL] {$row['prenom']} {$row['nom']} (MEP $mepId, HTTP $code)");
        @unlink($tmpJpg);
        continue;
    }

    // Conversion WebP via cwebp
    $cmd = sprintf('cwebp -quiet -q 85 %s -o %s', escapeshellarg($tmpJpg), escapeshellarg($webpPath));
    @exec($cmd, $out, $rc);
    @unlink($tmpJpg);

    if ($rc !== 0 || !file_exists($webpPath)) {
        $stats['errors']++;
        logLine("   [ERR WEBP] {$row['prenom']} {$row['nom']} (cwebp rc=$rc)");
        continue;
    }
    $stats['downloaded']++;

    // Update BDD si nécessaire
    if ($row['photo_url'] !== $expectedUrl) {
        $stmtUpdate->execute([':url' => $expectedUrl, ':id' => $row['id']]);
        $stats['updated_url']++;
    }

    // Petit sleep pour ne pas mitrailler europarl
    usleep(200_000);
}

logLine('=== STATS ===');
foreach ($stats as $k => $v) logLine(sprintf('   %-15s %s', $k, number_format($v)));
logLine('Done.');
