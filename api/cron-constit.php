<?php
/**
 * CRON — Import des décisions du Conseil constitutionnel
 * Source : DILA Open Data XML (echanges.dila.gouv.fr/OPENDATA/CONSTIT/)
 * Usage : php cron-constit.php [--full]
 *   --full : réimporte tout depuis le dump global
 *   sans flag : import incrémental (dernière mise à jour)
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

$startTime = microtime(true);
$full = in_array('--full', $argv ?? []);

function logLine(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

logLine("=== IMPORT DECISIONS CC — " . date('Y-m-d H:i:s') . " ===");
logLine("Mode: " . ($full ? 'FULL (dump global)' : 'INCREMENTAL'));

// ── 1. Créer les tables ──
logLine('Création tables si nécessaire...');
$pdo->exec("
    CREATE TABLE IF NOT EXISTS decisions_cc (
        id VARCHAR(50) PRIMARY KEY,
        numero VARCHAR(50),
        type_decision ENUM('DC','QPC','LP','AN','AUTR') NOT NULL DEFAULT 'AUTR',
        date_decision DATE NOT NULL,
        titre VARCHAR(500),
        solution VARCHAR(200),
        membres_presents JSON,
        texte_resume TEXT,
        texte_url VARCHAR(500),
        loi_concernee VARCHAR(500),
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type_decision),
        INDEX idx_date (date_decision),
        INDEX idx_solution (solution)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS decisions_cc_membres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        decision_id VARCHAR(50) NOT NULL,
        nom_membre VARCHAR(255) NOT NULL,
        elu_id INT DEFAULT NULL,
        FOREIGN KEY (decision_id) REFERENCES decisions_cc(id) ON DELETE CASCADE,
        FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE SET NULL,
        INDEX idx_decision (decision_id),
        INDEX idx_elu (elu_id),
        UNIQUE KEY uk_decision_membre (decision_id, nom_membre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Télécharger le XML DILA ──
$DILA_BASE = 'https://echanges.dila.gouv.fr/OPENDATA/CONSTIT/';
$tmpDir = '/tmp/constit_import_' . getmypid();
@mkdir($tmpDir, 0777, true);

if ($full) {
    // Dump global : trouver le fichier Freemium le plus récent
    logLine('Téléchargement listing DILA...');
    $html = file_get_contents($DILA_BASE);
    if (!$html) { logLine('ERREUR: impossible de lister DILA'); exit(1); }

    // Chercher le dump global le plus récent
    preg_match_all('/href="(Freemium_constit_global_[^"]+\.tar\.gz)"/', $html, $matches);
    if (empty($matches[1])) { logLine('ERREUR: aucun dump global trouvé'); exit(1); }
    $dumpFile = end($matches[1]);
    $dumpUrl = $DILA_BASE . $dumpFile;
    logLine("Téléchargement $dumpFile...");
} else {
    // Incrémental : trouver le fichier CONSTIT_ le plus récent
    logLine('Téléchargement listing DILA...');
    $html = file_get_contents($DILA_BASE);
    if (!$html) { logLine('ERREUR: impossible de lister DILA'); exit(1); }

    preg_match_all('/href="(CONSTIT_[^"]+\.tar\.gz)"/', $html, $matches);
    if (empty($matches[1])) {
        logLine('Aucun incrémental trouvé, essai du dump global...');
        preg_match_all('/href="(Freemium_constit_global_[^"]+\.tar\.gz)"/', $html, $matches);
        if (empty($matches[1])) { logLine('ERREUR: aucun fichier trouvé'); exit(1); }
    }
    $dumpFile = end($matches[1]);
    $dumpUrl = $DILA_BASE . $dumpFile;
    logLine("Téléchargement $dumpFile...");
}

$localTar = "$tmpDir/$dumpFile";
$downloaded = file_put_contents($localTar, file_get_contents($dumpUrl));
if (!$downloaded) { logLine("ERREUR: téléchargement échoué"); exit(1); }
logLine("Téléchargé: " . round($downloaded / 1024 / 1024, 1) . " Mo");

// ── 3. Extraire les XML ──
logLine('Extraction...');
exec("cd $tmpDir && tar xzf " . escapeshellarg($dumpFile) . " 2>&1", $output, $ret);
if ($ret !== 0) { logLine("ERREUR extraction: " . implode("\n", $output)); exit(1); }

// Trouver tous les fichiers XML CONSTEXT
$xmlFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir));
foreach ($iterator as $file) {
    if ($file->isFile() && preg_match('/CONSTEXT\d+\.xml$/', $file->getFilename())) {
        $xmlFiles[] = $file->getPathname();
    }
}
logLine(count($xmlFiles) . " fichiers XML trouvés");

// ── 4. Parser et importer ──
$stmtInsert = $pdo->prepare("
    INSERT INTO decisions_cc (id, numero, type_decision, date_decision, titre, solution, membres_presents, texte_resume, texte_url, loi_concernee)
    VALUES (:id, :numero, :type, :date, :titre, :solution, :membres, :resume, :url, :loi)
    ON DUPLICATE KEY UPDATE
        solution = VALUES(solution),
        membres_presents = VALUES(membres_presents),
        texte_resume = VALUES(texte_resume)
");

$stmtMembre = $pdo->prepare("
    INSERT IGNORE INTO decisions_cc_membres (decision_id, nom_membre, elu_id)
    VALUES (:did, :nom, :elu_id)
");

$stmtFindElu = $pdo->prepare("SELECT id FROM elus WHERE LOWER(nom) = LOWER(:nom) LIMIT 1");

$inserted = 0;
$updated = 0;
$membresInserted = 0;
$skipped = 0;
$minDate = $full ? '2000-01-01' : '2020-01-01';

foreach ($xmlFiles as $i => $xmlPath) {
    $xml = @simplexml_load_file($xmlPath);
    if (!$xml) { $skipped++; continue; }

    // ID
    $id = basename($xmlPath, '.xml');

    // Date
    $dateStr = (string)($xml->META->META_SPEC->META_JURI->DATE_DEC ?? $xml->META->META_COMMUN->DATE_DEC ?? '');
    if (!$dateStr || $dateStr < $minDate) { $skipped++; continue; }

    // Nature/Type
    $nature = strtoupper(trim((string)($xml->META->META_COMMUN->NATURE ?? '')));
    $typeMap = ['DC' => 'DC', 'QPC' => 'QPC', 'LP' => 'LP', 'AN' => 'AN', 'L' => 'LP'];
    $type = $typeMap[$nature] ?? 'AUTR';

    // Filtrer : on ne garde que DC, QPC et LP
    if (!in_array($type, ['DC', 'QPC', 'LP'])) { $skipped++; continue; }

    // Numéro
    $numero = trim((string)($xml->META->META_SPEC->META_JURI->NUMERO ?? ''));

    // Titre
    $titre = trim((string)($xml->META->META_SPEC->META_JURI->TITRE ?? ''));

    // Solution
    $solution = trim((string)($xml->META->META_SPEC->META_JURI->SOLUTION ?? ''));

    // URL
    $urlCC = trim((string)($xml->META->META_SPEC->META_JURI_CONSTIT->URL_CC ?? ''));

    // Texte intégral — remplacer <br/> par des espaces avant strip_tags
    $rawHtml = (string)($xml->TEXTE->BLOC_TEXTUEL->CONTENU ?? '');
    $contenu = strip_tags(str_replace(['<br/>', '<br>', '<br />'], ' ', $rawHtml));
    $contenu = preg_replace('/\s+/', ' ', $contenu);

    // Résumé (premiers 1500 chars)
    $resume = mb_substr($contenu, 0, 1500);

    // Loi concernée
    $loi = '';
    if (preg_match('/(?:relative? (?:à|au|aux)\s+)(.{10,200}?)(?:\.|;)/ui', $contenu, $m)) {
        $loi = trim($m[1]);
    }

    // Membres présents — chercher dans le texte COMPLET (c'est à la fin)
    $membres = [];
    // La formule est : "siégeaient : M. Prénom NOM, ... et Mme Prénom NOM."
    // On cherche tout ce qui suit "siégeaient" jusqu'au point final suivi de "Rendu" ou fin
    if (preg_match('/siégeaient\s*:?\s*(.+?)(?:Rendu|Jugé|Le Conseil constitutionnel)/uis', $contenu, $m)) {
        $raw = $m[1];
        // Nettoyer les titres honorifiques
        $raw = preg_replace('/\b(MM?\.|Mmes?|Président(?:e)?)\b/ui', '', $raw);
        // Nettoyer ponctuation finale
        $raw = rtrim($raw, ". \t\n\r");
        $parts = preg_split('/[,;]\s*|\s+et\s+/u', $raw);
        foreach ($parts as $part) {
            $part = trim($part);
            // Garder seulement les noms (Prénom NOM ou Prénom-Composé NOM)
            if ($part && preg_match('/^[A-ZÀ-Ü][a-zà-ü-]+\s+[A-ZÀ-Ü]/u', $part) && mb_strlen($part) > 5) {
                $membres[] = trim($part);
            }
        }
    }

    // Insert décision
    try {
        $stmtInsert->execute([
            ':id'       => $id,
            ':numero'   => $numero ?: null,
            ':type'     => $type,
            ':date'     => $dateStr,
            ':titre'    => $titre ?: null,
            ':solution' => $solution ?: null,
            ':membres'  => json_encode($membres, JSON_UNESCAPED_UNICODE),
            ':resume'   => $resume ?: null,
            ':url'      => $urlCC ?: null,
            ':loi'      => $loi ?: null,
        ]);
        $inserted++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            $updated++;
        } else {
            logLine("ERREUR SQL $id: " . $e->getMessage());
        }
        continue;
    }

    // Insert membres
    foreach ($membres as $nom) {
        $stmtFindElu->execute([':nom' => explode(' ', $nom)[count(explode(' ', $nom)) - 1]]);
        $eluId = $stmtFindElu->fetchColumn() ?: null;

        try {
            $stmtMembre->execute([':did' => $id, ':nom' => $nom, ':elu_id' => $eluId]);
            $membresInserted++;
        } catch (PDOException $e) {}
    }

    if (($i + 1) % 200 === 0) {
        logLine("  Progress: " . ($i + 1) . "/" . count($xmlFiles) . " — Insérés: $inserted");
    }
}

// ── 5. Nettoyage ──
exec("rm -rf " . escapeshellarg($tmpDir));

// ── 6. Log ──
$durationMs = (int)((microtime(true) - $startTime) * 1000);
$summary = json_encode([
    'inserted' => $inserted,
    'updated'  => $updated,
    'membres'  => $membresInserted,
    'skipped'  => $skipped,
    'xml_files' => count($xmlFiles),
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("
    INSERT INTO fetch_log (source, endpoint, status, records_count, error_message, duration_ms)
    VALUES ('constit', 'dila_xml', :status, :count, :msg, :duration)
")->execute([
    ':status'   => $inserted > 0 ? 'success' : 'empty',
    ':count'    => $inserted,
    ':msg'      => $summary,
    ':duration' => $durationMs,
]);

logLine('');
logLine('=== BILAN ===');
logLine("XML parsés   : " . count($xmlFiles));
logLine("Insérés      : $inserted");
logLine("Mis à jour   : $updated");
logLine("Membres      : $membresInserted");
logLine("Skippés      : $skipped");
logLine("Durée        : {$durationMs}ms");
logLine('=== FIN ===');
