<?php
/**
 * Mise à jour taux de présence — Députés EU + Sénateurs.
 * Complément de cron-votes-an.php (qui gère les députés AN).
 *
 * Sources :
 * - Députés EU : votes déjà importés (scrutin_id LIKE 'EU_%') via import-eu-votes.php
 * - Sénateurs  : Open Data Sénat — data.senat.fr (votes nominatifs 16e législature)
 *
 * Usage CLI :
 *   php cron-taux-presence.php            # EU + Sénat
 *   php cron-taux-presence.php --eu-only  # EU uniquement
 *   php cron-taux-presence.php --senat-only
 *
 * Cron suggéré : 0 6 * * 1 php cron-taux-presence.php (lundi matin)
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); exit('Forbidden');
}

ini_set('max_execution_time', 300);
$startTime = microtime(true);

$opts = getopt('', ['eu-only', 'senat-only']);
$doEu    = !isset($opts['senat-only']);
$doSenat = !isset($opts['eu-only']);

echo "=== TAUX DE PRÉSENCE EU + SÉNAT — " . date('Y-m-d H:i:s') . " ===\n";

// ── Étendre la table activite_parlementaire avec type_elu si pas encore fait ──
$pdo->exec("
    ALTER TABLE activite_parlementaire
    ADD COLUMN IF NOT EXISTS type_elu ENUM('depute','europeen','senateur') NOT NULL DEFAULT 'depute'
");

// ═══════════════════════════════════════════════════════════════════
// PARTIE 1 — Députés européens (votes déjà en BDD)
// ═══════════════════════════════════════════════════════════════════

if ($doEu) {
    echo "\n--- Calcul taux EU ---\n";

    // Total scrutins EU depuis le début 10e législature (2024-07-16)
    $totalEU = (int) $pdo->query("SELECT COUNT(DISTINCT scrutin_id) FROM votes WHERE scrutin_id LIKE 'EU_%'")->fetchColumn();
    echo "  Total scrutins EU en base : $totalEU\n";

    if ($totalEU === 0) {
        echo "  Aucun vote EU — lancer import-eu-votes.php d'abord\n";
    } else {
        // Identifier les députés EU (mandats actifs ou type_mandat)
        $euIds = $pdo->query("
            SELECT DISTINCT e.id FROM elus e
            WHERE e.type_mandat = 'europeen'
               OR EXISTS (
                   SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL
                   AND (LOWER(m.titre) LIKE '%européen%' OR LOWER(m.titre) LIKE '%parlement européen%')
               )
        ")->fetchAll(PDO::FETCH_COLUMN);

        echo "  Députés EU identifiés : " . count($euIds) . "\n";

        // Votes par élu EU
        $stmtVotes = $pdo->query("
            SELECT elu_id, COUNT(DISTINCT scrutin_id) AS nb_votes
            FROM votes WHERE scrutin_id LIKE 'EU_%'
            GROUP BY elu_id
        ");

        $stmtUpsert = $pdo->prepare("
            INSERT INTO activite_parlementaire
                (elu_id, type_elu, nb_votes, total_scrutins, taux_votes, taux_global)
            VALUES (:eid, 'europeen', :nb, :total, :taux_v, :taux_g)
            ON DUPLICATE KEY UPDATE
                type_elu = 'europeen',
                nb_votes = VALUES(nb_votes),
                total_scrutins = VALUES(total_scrutins),
                taux_votes = VALUES(taux_votes),
                taux_global = VALUES(taux_global)
        ");

        $count = 0;
        while ($row = $stmtVotes->fetch()) {
            if (!in_array($row['elu_id'], $euIds)) continue;
            $taux = round(($row['nb_votes'] / $totalEU) * 100, 2);
            $stmtUpsert->execute([
                ':eid'   => $row['elu_id'],
                ':nb'    => $row['nb_votes'],
                ':total' => $totalEU,
                ':taux_v' => $taux,
                ':taux_g' => $taux,
            ]);
            $count++;
        }

        // Mettre à jour score_assiduite sur elus
        $pdo->exec("
            UPDATE elus e
            JOIN activite_parlementaire ap ON ap.elu_id = e.id
            SET e.score_assiduite = ROUND(ap.taux_global / 10, 1)
            WHERE ap.type_elu = 'europeen'
        ");

        echo "  $count députés EU mis à jour\n";
    }
}

// ═══════════════════════════════════════════════════════════════════
// PARTIE 2 — Sénateurs (Open Data Sénat)
// ═══════════════════════════════════════════════════════════════════

if ($doSenat) {
    echo "\n--- Import votes Sénat ---\n";

    // API Open Data Sénat — votes nominatifs (toute la législature courante)
    // Docs : https://data.senat.fr/api/v2/
    $apiBase = 'https://data.senat.fr/api/v2/catalog/datasets/senatfr_vote/exports/json';
    $params = http_build_query([
        'limit' => -1,
        'offset' => 0,
        'timezone' => 'UTC',
        'lang' => 'fr',
        // Filtre : 16e législature (depuis 2022)
        'where' => 'date_seance >= "2022-09-01"',
    ]);

    echo "  Téléchargement Open Data Sénat...\n";
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: nos-elus.fr/1.0\r\n"]]);
    $json = @file_get_contents("$apiBase?$params", false, $ctx);

    if (!$json) {
        echo "  AVERTISSEMENT : Open Data Sénat inaccessible — passage au vote par vote\n";
        $json = null;
    }

    $senatVotes = $json ? json_decode($json, true) : null;

    if (empty($senatVotes)) {
        // Fallback : charger depuis nossenateurs.fr (format JSON public)
        echo "  Fallback nossenateurs.fr...\n";
        $nsUrl = 'https://www.nossenateurs.fr/senat/vote/?format=json&legislature=16&limit=1000';
        $json2 = @file_get_contents($nsUrl, false, $ctx);
        if ($json2) $senatVotes = json_decode($json2, true)['votes'] ?? null;
    }

    if (empty($senatVotes)) {
        // Deuxième fallback : données open data Sénat via leur dépôt GitHub
        echo "  Fallback GitHub Sénat open data...\n";
        // Le Sénat publie des JSON de votes sur data.senat.fr
        $altUrl = 'https://data.senat.fr/api/v2/catalog/datasets/scrutins_senat/exports/json?limit=-1&where=legislature=16';
        $json3 = @file_get_contents($altUrl, false, $ctx);
        if ($json3) $senatVotes = json_decode($json3, true);
    }

    if (empty($senatVotes)) {
        echo "  Aucune source Sénat disponible — taux Sénat non mis à jour\n";
    } else {
        echo "  " . count($senatVotes) . " entrées Sénat récupérées\n";

        // Format attendu : chaque entrée = 1 vote d'1 sénateur
        // Champs probables : nom, prenom, position (Pour/Contre/Abstention), scrutin_id, date
        // --- Mapping sénateurs → elu_id ---
        $stmtFind = $pdo->prepare("
            SELECT id FROM elus WHERE LOWER(nom) = LOWER(:nom) AND LOWER(prenom) = LOWER(:prenom) LIMIT 1
        ");
        $senMapping = []; // "NOM|PRENOM" → elu_id
        $scrutinIds = []; // set de scrutin_id Sénat

        $stmtIns = $pdo->prepare("
            INSERT IGNORE INTO votes (elu_id, sujet, position, date_vote, scrutin_id)
            VALUES (:eid, :sujet, :pos, :date, :sid)
        ");

        $inserted = 0; $skipped = 0;

        foreach ($senatVotes as $v) {
            // Normaliser les champs selon le format Open Data Sénat
            $nom   = strtoupper(trim($v['nom_sen'] ?? $v['nom'] ?? ''));
            $prenom = ucfirst(strtolower(trim($v['prenom_sen'] ?? $v['prenom'] ?? '')));
            $pos   = $v['libelle_vote'] ?? $v['position'] ?? '';
            $date  = substr($v['date_seance'] ?? $v['date'] ?? '', 0, 10);
            $sid   = 'SENAT_' . ($v['scrutin_id'] ?? $v['numero_scrutin'] ?? uniqid());
            $sujet = mb_substr($v['libelle_scrutin'] ?? $v['sujet'] ?? '', 0, 500, 'UTF-8');

            if (!$nom || !$prenom || !$date) { $skipped++; continue; }

            // Normaliser position
            $posMap = [
                'pour' => 'Pour', 'contre' => 'Contre',
                'abstention' => 'Abstention', 'absent' => 'Abstention',
                'nv' => 'Non-votant', 'n.v' => 'Non-votant',
            ];
            $posNorm = $posMap[mb_strtolower($pos)] ?? null;
            if (!$posNorm || $posNorm === 'Non-votant') { $skipped++; continue; }

            $key = "$nom|$prenom";
            if (!isset($senMapping[$key])) {
                $stmtFind->execute([':nom' => $nom, ':prenom' => $prenom]);
                $senMapping[$key] = (int)($stmtFind->fetchColumn() ?: 0);
            }
            $eluId = $senMapping[$key];
            if (!$eluId) { $skipped++; continue; }

            $scrutinIds[$sid] = true;
            $stmtIns->execute([':eid' => $eluId, ':sujet' => $sujet, ':pos' => $posNorm, ':date' => $date, ':sid' => $sid]);
            if ($stmtIns->rowCount() > 0) $inserted++;
            else $skipped++;
        }

        echo "  Votes Sénat insérés : $inserted | ignorés : $skipped\n";

        // Calculer taux sénateurs
        $totalSenat = (int) $pdo->query("SELECT COUNT(DISTINCT scrutin_id) FROM votes WHERE scrutin_id LIKE 'SENAT_%'")->fetchColumn();
        echo "  Total scrutins Sénat en base : $totalSenat\n";

        if ($totalSenat > 0) {
            $stmtVS = $pdo->query("
                SELECT elu_id, COUNT(DISTINCT scrutin_id) AS nb_votes
                FROM votes WHERE scrutin_id LIKE 'SENAT_%'
                GROUP BY elu_id
            ");
            $stmtUpsert = $pdo->prepare("
                INSERT INTO activite_parlementaire
                    (elu_id, type_elu, nb_votes, total_scrutins, taux_votes, taux_global)
                VALUES (:eid, 'senateur', :nb, :total, :taux_v, :taux_g)
                ON DUPLICATE KEY UPDATE
                    type_elu = 'senateur',
                    nb_votes = VALUES(nb_votes),
                    total_scrutins = VALUES(total_scrutins),
                    taux_votes = VALUES(taux_votes),
                    taux_global = VALUES(taux_global)
            ");
            $count = 0;
            while ($row = $stmtVS->fetch()) {
                // Vérifier que c'est bien un sénateur
                $isSenat = $pdo->prepare("
                    SELECT COUNT(*) FROM mandats WHERE elu_id = :id AND date_fin IS NULL
                    AND (LOWER(titre) LIKE '%sénateur%' OR LOWER(titre) LIKE '%sénatrice%')
                ");
                $isSenat->execute([':id' => $row['elu_id']]);
                if (!(int)$isSenat->fetchColumn()) continue;

                $taux = round(($row['nb_votes'] / $totalSenat) * 100, 2);
                $stmtUpsert->execute([
                    ':eid'    => $row['elu_id'],
                    ':nb'     => $row['nb_votes'],
                    ':total'  => $totalSenat,
                    ':taux_v' => $taux,
                    ':taux_g' => $taux,
                ]);
                $count++;
            }

            // Mettre à jour score_assiduite
            $pdo->exec("
                UPDATE elus e
                JOIN activite_parlementaire ap ON ap.elu_id = e.id
                SET e.score_assiduite = ROUND(ap.taux_global / 10, 1)
                WHERE ap.type_elu = 'senateur'
            ");

            echo "  $count sénateurs mis à jour\n";
        }
    }
}

// ── Purge cache ──
echo "\nPurge cache...\n";
foreach (['elu_', 'palmares_', 'stats_'] as $prefix) {
    foreach (glob(__DIR__ . '/cache/data/' . $prefix . '*.json') as $f) @unlink($f);
}

$duration = round((microtime(true) - $startTime), 1);
echo "=== TERMINÉ en {$duration}s ===\n";

// Log
$pdo->prepare("INSERT INTO fetch_log (source, endpoint, status, records_count, duration_ms) VALUES ('taux_presence', 'eu+senat', 'success', 1, :dur)")
    ->execute([':dur' => (int)($duration * 1000)]);
