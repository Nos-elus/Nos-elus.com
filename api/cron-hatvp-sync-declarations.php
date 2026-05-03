<?php
/**
 * CRON — Sync HATVP open data → table hatvp_declarations + elus.url_hatvp
 *
 * Source : https://www.hatvp.fr/livraison/opendata/liste.csv
 *
 * Détecte automatiquement toute nouvelle déclaration publiée par la HATVP
 * (parlementaires, ministres, élus locaux soumis, candidats présidentielle T-15).
 * - Insert / update dans hatvp_declarations (UNIQUE par classement+type+date)
 * - Match avec table elus (nom + prénom, classement HATVP si pré-existant)
 * - Update elus.url_hatvp si vide
 *
 * Usage :
 *   php cron-hatvp-sync-declarations.php             → run normal
 *   php cron-hatvp-sync-declarations.php --dry-run   → simulation
 *   php cron-hatvp-sync-declarations.php --presidentielle  → filtre type_mandat=president
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); exit('Forbidden');
}

ini_set('max_execution_time', 0);
ini_set('memory_limit', '256M');

$dryRun = in_array('--dry-run', $argv ?? []);
$onlyPresidentielle = in_array('--presidentielle', $argv ?? []);

function logLine(string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . "\n"; }

logLine('=== HATVP SYNC DECLARATIONS — ' . date('Y-m-d H:i:s')
    . ($dryRun ? ' (DRY RUN)' : '')
    . ($onlyPresidentielle ? ' (FILTER: présidentielle)' : '')
    . ' ===');

// ── 1. Téléchargement CSV ──
$csvUrl = 'https://www.hatvp.fr/livraison/opendata/liste.csv';
$ch = curl_init($csvUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'nos-elus.com/1.0',
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
]);
$csv = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$csv) {
    logLine("ERREUR download — HTTP $httpCode" . ($err ? " ($err)" : ''));
    exit(1);
}
logLine('CSV téléchargé : ' . number_format(strlen($csv)) . ' octets');

// ── 2. Parse CSV ──
$lines = explode("\n", trim($csv));
$rawHeader = array_shift($lines);
$header = str_getcsv($rawHeader, ';');
logLine('Lignes : ' . number_format(count($lines)));

$expectedCols = ['civilite','prenom','nom','classement','type_mandat','qualite','type_document',
                 'departement','date_publication','date_depot','nom_fichier','url_dossier',
                 'open_data','statut_publication','id_origine','url_photo'];
$missing = array_diff($expectedCols, $header);
if (!empty($missing)) {
    logLine('ERREUR colonnes manquantes : ' . implode(',', $missing));
    exit(1);
}

// ── 3. Stats + prepared statements ──
$stats = [
    'total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0,
    'matched' => 0, 'multi_homonymes' => 0, 'unmatched' => 0,
    'url_updated' => 0, 'errors' => 0,
];

if (!$dryRun) {
    $pdo->beginTransaction();
}

$stmtUpsert = $pdo->prepare("
    INSERT INTO hatvp_declarations
        (civilite, prenom, nom, classement, type_mandat, qualite, type_document, departement,
         date_publication, date_depot, nom_fichier, url_dossier, pdf_url, xml_url,
         statut_publication, id_origine, url_photo, elu_id)
    VALUES
        (:civ, :pre, :nom, :cla, :tm, :qua, :td, :dep,
         :dpub, :ddep, :nf, :ud, :pdf, :xml,
         :sp, :io, :up, :eid)
    ON DUPLICATE KEY UPDATE
        statut_publication = VALUES(statut_publication),
        date_publication   = VALUES(date_publication),
        url_dossier        = VALUES(url_dossier),
        pdf_url            = VALUES(pdf_url),
        xml_url            = VALUES(xml_url),
        elu_id             = COALESCE(VALUES(elu_id), elu_id),
        updated_at         = CURRENT_TIMESTAMP
");

$stmtMatchElu = $pdo->prepare("
    SELECT id, url_hatvp
    FROM elus
    WHERE LOWER(TRIM(nom)) = LOWER(TRIM(:nom))
      AND LOWER(TRIM(prenom)) = LOWER(TRIM(:prenom))
");

$stmtUpdateEluUrl = $pdo->prepare("
    UPDATE elus SET url_hatvp = :url
    WHERE id = :id AND (url_hatvp IS NULL OR url_hatvp = '')
");

$pdfBase = 'https://www.hatvp.fr/livraison/dossiers/';
$xmlBase = 'https://www.hatvp.fr/livraison/merge/';

// ── 4. Boucle ──
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;
    $row = str_getcsv($line, ';');
    if (count($row) < count($header)) { $stats['errors']++; continue; }
    $r = array_combine($header, $row);
    if (!$r) { $stats['errors']++; continue; }

    $stats['total']++;

    if ($onlyPresidentielle && $r['type_mandat'] !== 'president') {
        $stats['skipped']++;
        continue;
    }

    // Match BDD elus
    $eluId = null;
    $stmtMatchElu->execute([':nom' => $r['nom'], ':prenom' => $r['prenom']]);
    $matches = $stmtMatchElu->fetchAll(PDO::FETCH_ASSOC);

    if (count($matches) === 1) {
        $stats['matched']++;
        $eluId = (int)$matches[0]['id'];
        // Update url_hatvp si vide en BDD
        if (empty($matches[0]['url_hatvp']) && !empty($r['url_dossier'])) {
            if (!$dryRun) {
                $stmtUpdateEluUrl->execute([':url' => $r['url_dossier'], ':id' => $eluId]);
            }
            $stats['url_updated']++;
        }
    } elseif (count($matches) > 1) {
        $stats['multi_homonymes']++;
        // Pas de match auto — log pour résolution manuelle
        if ($r['type_mandat'] === 'president' || $r['type_mandat'] === 'gouvernement') {
            logLine("   [HOMONYMES] {$r['prenom']} {$r['nom']} ({$r['type_mandat']}) — " . count($matches) . ' candidats');
        }
    } else {
        $stats['unmatched']++;
        // Candidat présidentielle inconnu = à signaler (ex: nouveau candidat 2027)
        if ($r['type_mandat'] === 'president') {
            logLine("   [NOUVEAU CANDIDAT PRESIDENT] {$r['prenom']} {$r['nom']} — fichier {$r['nom_fichier']}");
        }
    }

    // Upsert dans hatvp_declarations
    $params = [
        ':civ'  => $r['civilite']           ?: null,
        ':pre'  => $r['prenom']             ?: null,
        ':nom'  => $r['nom']                ?: null,
        ':cla'  => $r['classement']         ?: null,
        ':tm'   => $r['type_mandat']        ?: null,
        ':qua'  => $r['qualite']            ?: null,
        ':td'   => $r['type_document']      ?: null,
        ':dep'  => $r['departement']        ?: null,
        ':dpub' => !empty($r['date_publication']) ? $r['date_publication'] : null,
        ':ddep' => !empty($r['date_depot'])       ? $r['date_depot']       : null,
        ':nf'   => $r['nom_fichier']        ?: null,
        ':ud'   => $r['url_dossier']        ?: null,
        ':pdf'  => !empty($r['nom_fichier']) ? $pdfBase . $r['nom_fichier'] : null,
        ':xml'  => !empty($r['open_data'])   ? $xmlBase . $r['open_data']   : null,
        ':sp'   => $r['statut_publication'] ?: null,
        ':io'   => $r['id_origine']         ?: null,
        ':up'   => $r['url_photo']          ?: null,
        ':eid'  => $eluId,
    ];

    if (!$dryRun) {
        try {
            $stmtUpsert->execute($params);
            $rc = $stmtUpsert->rowCount();
            // ROW_COUNT() : 1 = insert, 2 = update, 0 = no change
            if ($rc === 1) $stats['inserted']++;
            elseif ($rc === 2) $stats['updated']++;
        } catch (PDOException $e) {
            $stats['errors']++;
            logLine('   [ERR] ' . $e->getMessage() . ' — ' . substr($line, 0, 80));
        }
    }
}

if (!$dryRun) {
    $pdo->commit();
}

// ── 5. Stats finales ──
logLine('=== STATS ===');
foreach ($stats as $k => $v) {
    logLine(sprintf('   %-20s %s', $k, number_format($v)));
}
logLine('Done.');
