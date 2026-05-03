<?php
/**
 * Recalcul taux de présence parlementaires (députés AN, eurodéputés).
 *
 * Méthodologie
 * ────────────
 * Pour chaque parlementaire actif :
 *   1. Collecte des mandats parlementaires de la chambre concernée
 *      → liste d'intervalles [date_debut, date_fin] (date_fin = aujourd'hui si NULL)
 *   2. Soustraction des périodes ministérielles concomitantes
 *      (le siège est alors occupé par un suppléant, le titulaire ne vote pas)
 *      → intervalles "actifs" disjoints
 *   3. Compte des scrutins de la chambre dont la date tombe dans un intervalle actif
 *      → total_scrutins (dénominateur)
 *   4. Compte des votes effectivement exprimés sur ces scrutins
 *      → nb_votes (numérateur)
 *   5. taux_votes = nb_votes / total_scrutins × 100
 *
 * Limitations connues (documentées dans README et fiches élus)
 * ───────────────────
 * - Sénateurs : aucun vote SENAT_ en BDD → score laissé inchangé (valeur par défaut 5/10).
 * - Absences justifiées (commission d'enquête, mission temporaire, congé maladie/maternité) :
 *   actuellement comptées comme absences faute de source publique exploitable.
 *   À intégrer dès que cron-votes-an.php capturera les statuts "Non-votant" de l'API AN.
 * - Dimensions commissions et questions écrites : colonnes BDD prêtes (taux_commissions,
 *   nb_questions) mais sources non-implémentées → taux_global = taux_votes pour l'instant.
 *   Formule cible : taux_global = taux_votes×0.50 + taux_commissions×0.35 + taux_questions×0.15.
 *
 * Usage
 * ─────
 *   php cron-taux-presence.php              # DRY-RUN (défaut)
 *   php cron-taux-presence.php --apply      # écrit en BDD
 *   php cron-taux-presence.php --type=depute|europeen
 *   php cron-taux-presence.php --elu-id=13  # un seul élu (debug)
 *   php cron-taux-presence.php --verbose    # log détaillé par élu
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); exit('Forbidden');
}

ini_set('max_execution_time', 1200);
ini_set('memory_limit', '512M');
$startTime = microtime(true);

$opts = getopt('', ['apply', 'type::', 'elu-id::', 'verbose']);
$apply    = isset($opts['apply']);
$onlyType = $opts['type']    ?? null;
$onlyElu  = isset($opts['elu-id']) ? (int) $opts['elu-id'] : null;
$verbose  = isset($opts['verbose']) || $onlyElu;

function logLine(string $m): void { echo '[' . date('H:i:s') . '] ' . $m . "\n"; }

logLine('=== TAUX DE PRESENCE — ' . date('Y-m-d H:i:s') . ' ' . ($apply ? '[APPLY]' : '[DRY-RUN]') . ' ===');

// ── Configuration des chambres ──
// 'senateur' explicitement exclu : pas de scrutin SENAT_ en BDD à ce jour.
$CHAMBRES = [
    'depute'   => [
        'prefix'      => 'VTANR%',
        'titre_inc'   => '%député%',     // tout titre contenant "député"
        'titre_excl'  => '%européen%',   // exclure les eurodéputés
    ],
    'europeen' => [
        'prefix'      => 'EU_%',
        'titre_inc'   => '%député européen%',
        'titre_excl'  => null,
    ],
];

// ── Regex ministre stricte (PHP, ancrée au début du titre) ──
// Match : "Ministre de…", "Premier Ministre", "Ministre,", "Ministre", "Ministre déléguée…",
//         "Secrétaire d'État…", "Garde des sceaux…", "Porte-parole du gouvernement",
//         "Président(e) de la République".
// Ne match pas : "Directeur de cabinet du ministre", "Conseiller du Premier Ministre", etc.
const REGEX_MINISTRE = '/^(premier ministre|ministre(\s|,|$)|ministre\s|secr[ée]taire\s+d[\'’]?[ée]tat|garde\s+des\s+sceaux|porte-parole\s+du\s+gouvernement|pr[ée]sident(e)?\s+de\s+la\s+r[ée]publique)/iu';

function isTitreMinistre(string $titre): bool {
    return (bool) preg_match(REGEX_MINISTRE, trim($titre));
}

/**
 * Soustrait des intervalles ministériels d'une liste d'intervalles parlementaires.
 * Retourne la liste d'intervalles "actifs" disjoints (date_debut <= date_fin garanti).
 *
 * @param array $parl  Liste de [debut(YYYY-MM-DD), fin(YYYY-MM-DD)]
 * @param array $min   Liste de [debut, fin] des périodes ministérielles
 * @return array       Liste de [debut, fin] résultante
 */
function subtractIntervals(array $parl, array $min): array {
    $result = $parl;
    foreach ($min as [$mDeb, $mFin]) {
        $next = [];
        foreach ($result as [$d, $f]) {
            // Intervalles disjoints → conservés tels quels
            if ($mFin < $d || $mDeb > $f) {
                $next[] = [$d, $f];
                continue;
            }
            // Le mandat ministériel chevauche : découper
            if ($mDeb > $d) {
                $next[] = [$d, date('Y-m-d', strtotime($mDeb . ' -1 day'))];
            }
            if ($mFin < $f) {
                $next[] = [date('Y-m-d', strtotime($mFin . ' +1 day')), $f];
            }
            // Sinon (ministère couvre tout) → segment supprimé
        }
        $result = $next;
    }
    // Filtrer intervalles dégénérés (debut > fin) au cas où
    return array_values(array_filter($result, fn($it) => $it[0] <= $it[1]));
}

/** Vérifie si une date tombe dans au moins un intervalle. */
function dateInIntervals(string $date, array $intervals): bool {
    foreach ($intervals as [$d, $f]) {
        if ($date >= $d && $date <= $f) return true;
    }
    return false;
}

$today = date('Y-m-d');

// ── Préchargement des scrutins par chambre (évite les FULL SCAN dans la boucle) ──
$scrutinsByChambre = [];
foreach ($CHAMBRES as $type => $cfg) {
    if ($onlyType && $onlyType !== $type) continue;
    $stmt = $pdo->prepare("
        SELECT scrutin_id, MAX(date_vote) AS date_vote
        FROM votes
        WHERE scrutin_id LIKE :prefix
        GROUP BY scrutin_id
    ");
    $stmt->execute([':prefix' => $cfg['prefix']]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $scrutinsByChambre[$type] = $rows;  // [scrutin_id => date_vote]
    logLine("  Préchargé " . count($rows) . " scrutins {$type} ({$cfg['prefix']})");
}

// ── Préparations ──
$stmtMandats = $pdo->prepare("
    SELECT id, titre, date_debut, date_fin
    FROM mandats
    WHERE elu_id = :id
");

$stmtVotesElu = $pdo->prepare("
    SELECT DISTINCT scrutin_id, MAX(date_vote) AS date_vote
    FROM votes
    WHERE elu_id = :id AND scrutin_id LIKE :prefix
    GROUP BY scrutin_id
");

// Ce cron n'écrit QUE la dimension "votes en séance".
// taux_global est recalculé dynamiquement après l'UPSERT, à partir de toutes les
// dimensions présentes en BDD (votes + commissions quand un autre cron les peuplera).
$stmtUpsert = $pdo->prepare("
    INSERT INTO activite_parlementaire
        (elu_id, type_elu, nb_votes, total_scrutins, taux_votes)
    VALUES (:eid, :type, :nb, :total, :tv)
    ON DUPLICATE KEY UPDATE
        type_elu       = VALUES(type_elu),
        nb_votes       = VALUES(nb_votes),
        total_scrutins = VALUES(total_scrutins),
        taux_votes     = VALUES(taux_votes)
");

$stats = [
    'processed'           => 0,
    'updated'             => 0,
    'skipped_no_mandat'   => 0,
    'skipped_no_scrutins' => 0,
    'skipped_minister_cover' => 0,
];

// ── Boucle principale par chambre ──
foreach ($CHAMBRES as $type => $cfg) {
    if ($onlyType && $onlyType !== $type) continue;

    logLine("--- Chambre: {$type} ---");

    // Lister élus actifs de ce type
    $sql = "SELECT id, prenom, nom FROM elus WHERE actif = 1 AND type_mandat = :tm";
    $params = [':tm' => $type];
    if ($onlyElu) {
        $sql .= " AND id = :id";
        $params[':id'] = $onlyElu;
    }
    $stmtElus = $pdo->prepare($sql);
    $stmtElus->execute($params);
    $elus = $stmtElus->fetchAll();
    logLine("  " . count($elus) . " élus actifs (type_mandat={$type})");

    $count = 0; $printedDry = 0;
    foreach ($elus as $elu) {
        $eluId = (int) $elu['id'];

        // 1. Charger tous ses mandats
        $stmtMandats->execute([':id' => $eluId]);
        $mandats = $stmtMandats->fetchAll();

        $intervallesParl = [];
        $intervallesMin  = [];

        foreach ($mandats as $m) {
            $titre = (string) $m['titre'];
            $titreLow = mb_strtolower($titre);
            $debut = $m['date_debut'];
            $fin   = $m['date_fin'] ?: $today;

            // Sauter mandats sans date de début ou pré-2007 (pas de votes en BDD avant)
            if (!$debut) continue;

            // Détection ministérielle (prioritaire — un titre peut matcher les deux sinon)
            if (isTitreMinistre($titre)) {
                $intervallesMin[] = [$debut, $fin];
                continue;
            }

            // Détection mandat parlementaire de la chambre
            $matchInc = ($cfg['titre_inc'] === null)
                ? true
                : (stripos($titreLow, str_replace('%', '', $cfg['titre_inc'])) !== false);
            $matchExcl = ($cfg['titre_excl'] !== null)
                && (stripos($titreLow, str_replace('%', '', $cfg['titre_excl'])) !== false);

            if ($matchInc && !$matchExcl) {
                $intervallesParl[] = [$debut, $fin];
            }
        }

        if (!$intervallesParl) {
            $stats['skipped_no_mandat']++;
            if ($verbose) logLine("    [skip no-mandat] #{$eluId} {$elu['prenom']} {$elu['nom']}");
            continue;
        }

        // 2. Soustraire périodes ministérielles
        $intervallesActifs = subtractIntervals($intervallesParl, $intervallesMin);
        if (!$intervallesActifs) {
            $stats['skipped_minister_cover']++;
            if ($verbose) logLine("    [skip 100% ministre] #{$eluId} {$elu['prenom']} {$elu['nom']}");
            continue;
        }

        // 3. Compter scrutins applicables (dans intervalles actifs)
        $applicables = [];
        foreach ($scrutinsByChambre[$type] as $sid => $sdate) {
            if (dateInIntervals($sdate, $intervallesActifs)) {
                $applicables[$sid] = $sdate;
            }
        }
        if (!$applicables) {
            $stats['skipped_no_scrutins']++;
            if ($verbose) logLine("    [skip no-scrutin-fenetre] #{$eluId} {$elu['prenom']} {$elu['nom']} (intervalles actifs : " . count($intervallesActifs) . ")");
            continue;
        }

        // 4. Charger les votes de l'élu pour cette chambre
        $stmtVotesElu->execute([':id' => $eluId, ':prefix' => $cfg['prefix']]);
        $votesRows = $stmtVotesElu->fetchAll();

        // 5. Compter votes effectifs : scrutin présent dans applicables
        $nbVotes = 0;
        foreach ($votesRows as $v) {
            if (isset($applicables[$v['scrutin_id']])) $nbVotes++;
        }

        $totalScrutins = count($applicables);
        $taux = $totalScrutins > 0 ? round(($nbVotes / $totalScrutins) * 100, 2) : 0.0;
        $stats['processed']++;

        if ($verbose || (!$apply && $printedDry < 10)) {
            logLine(sprintf(
                "    #%d %s %s : %d/%d = %.2f%% (intervalles actifs : %d, ministériel : %d)",
                $eluId, $elu['prenom'], $elu['nom'],
                $nbVotes, $totalScrutins, $taux,
                count($intervallesActifs), count($intervallesMin)
            ));
            $printedDry++;
        }

        if ($apply) {
            $stmtUpsert->execute([
                ':eid'   => $eluId,
                ':type'  => $type,
                ':nb'    => $nbVotes,
                ':total' => $totalScrutins,
                ':tv'    => $taux,
            ]);
            $stats['updated']++;
        }
        $count++;
    }
    logLine("  → $count traités pour {$type}");
}

// ── Recalcul dynamique de taux_global ──
// Formule : ratio direct (présents / occasions) sur l'ensemble des dimensions
// présentes en BDD. Aujourd'hui seules les colonnes votes sont peuplées →
// taux_global = taux_votes. Demain, si nb_reunions_convoque > 0, la dimension
// commissions s'intègre automatiquement.
if ($apply) {
    $rGlobal = $pdo->exec("
        UPDATE activite_parlementaire
        SET taux_global = CASE
            WHEN (COALESCE(total_scrutins,0) + COALESCE(nb_reunions_convoque,0)) > 0
              THEN ROUND(
                    ((COALESCE(nb_votes,0) + COALESCE(nb_reunions_present,0))
                     / (COALESCE(total_scrutins,0) + COALESCE(nb_reunions_convoque,0))) * 100,
                    2
                  )
            ELSE 0
        END
    ");
    logLine("  Recalculé taux_global dynamique : {$rGlobal} lignes");

    // Synchronisation elus.score_assiduite (taux_global / 10, borné 0-10)
    $r = $pdo->exec("
        UPDATE elus e
        JOIN activite_parlementaire ap ON ap.elu_id = e.id
        SET e.score_assiduite = LEAST(10, GREATEST(0, ROUND(ap.taux_global / 10, 1)))
        WHERE e.actif = 1 AND e.type_mandat IN ('depute', 'europeen')
    ");
    logLine("  Synchronisé elus.score_assiduite : {$r} lignes");

    // Purge cache pour rafraîchir palmares + fiches
    $purged = 0;
    foreach (['elu_', 'palmares_', 'stats_', 'top_'] as $p) {
        foreach (glob(__DIR__ . '/cache/data/' . $p . '*.json') ?: [] as $f) {
            if (@unlink($f)) $purged++;
        }
    }
    logLine("  Purgé {$purged} fichier(s) cache");
}

// ── Récap ──
logLine('=== STATS ===');
foreach ($stats as $k => $v) {
    logLine("  " . str_pad($k, 24) . " : " . number_format($v));
}
$duration = round(microtime(true) - $startTime, 1);
logLine("Durée: {$duration}s.");

if ($apply) {
    @$pdo->prepare(
        "INSERT INTO fetch_log (source, endpoint, status, records_count, duration_ms)
         VALUES ('taux_presence', 'recalc', 'success', :n, :dur)"
    )->execute([':n' => $stats['updated'], ':dur' => (int) ($duration * 1000)]);
}
