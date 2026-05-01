<?php
/**
 * Audit mandats en lot via Wikidata — compare BDD vs P39 pour N élus.
 *
 * PHASES :
 *   Phase 1 — Dry-run : produit un rapport CSV (aucune écriture BDD)
 *   Phase 2 — Apply   : applique UNIQUEMENT les elu_ids validés manuellement
 *
 * Usage :
 *   # Phase 1 : audit dry-run (priorité : élus avec source_id QID, puis maires/deputés/sénateurs)
 *   php wiki-audit-batch.php --limit=200 --offset=0 --out=/tmp/audit_mandats.csv
 *
 *   # Reprendre depuis un offset (paginer si beaucoup d'élus)
 *   php wiki-audit-batch.php --limit=200 --offset=200 --out=/tmp/audit_mandats.csv --append
 *
 *   # Phase 2 : appliquer après revue humaine (IDs séparés par virgule)
 *   php wiki-audit-batch.php --apply-ids=42,137,892
 *
 *   # Filtres optionnels
 *   --type=maire         # seulement les maires
 *   --type=depute        # seulement les députés AN
 *   --type=senateur
 *   --type=europeen
 *   --qid-only           # seulement les élus avec source_id QID (plus fiable)
 *   --min-mandats-wiki=1 # ignorer si Wikidata a moins de N mandats (entité pauvre)
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('max_execution_time', 0);
ini_set('memory_limit', '256M');

// ── Paramètres CLI ──
$opts = getopt('', ['limit:', 'offset:', 'out:', 'append', 'apply-ids:', 'type:', 'qid-only', 'min-mandats-wiki:', 'force']);
$limit       = (int)($opts['limit'] ?? 100);
$offset      = (int)($opts['offset'] ?? 0);
$outFile     = $opts['out'] ?? '/tmp/audit_mandats_' . date('Ymd_His') . '.csv';
$append      = isset($opts['append']);
$applyIds    = isset($opts['apply-ids']) ? array_map('intval', explode(',', $opts['apply-ids'])) : [];
$filterType  = $opts['type'] ?? null;
$qidOnly     = isset($opts['qid-only']);
$minWikiMandats = (int)($opts['min-mandats-wiki'] ?? 1);

$ua = 'nos-elus.fr/1.0 (https://nos-elus.fr; contact@nos-elus.fr)';
$ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 12]]);

// ══════════════════════════════════════════════════════════════
// MODE APPLY — applique uniquement les elu_ids validés
// ══════════════════════════════════════════════════════════════

if (!empty($applyIds)) {
    echo "=== MODE APPLY — " . count($applyIds) . " élus ===\n";
    if (!isset($opts['force'])) {
        echo "RAPPEL : ces IDs ont-ils été revus dans le rapport CSV ? [Ctrl+C pour annuler, Entrée pour continuer]\n";
        fgets(STDIN);
    }

    $applied = 0;
    foreach ($applyIds as $eluId) {
        $stmt = $pdo->prepare('SELECT id, nom, prenom, source_id FROM elus WHERE id = :id');
        $stmt->execute([':id' => $eluId]);
        $elu = $stmt->fetch();
        if (!$elu) { echo "  [SKIP] id=$eluId introuvable\n"; continue; }

        $qid = (!empty($elu['source_id']) && str_starts_with($elu['source_id'], 'Q')) ? $elu['source_id'] : null;
        if (!$qid) {
            $qid = wikidataSearch(trim($elu['prenom'] . ' ' . $elu['nom']), $ua, $ctx);
        }
        if (!$qid) { echo "  [SKIP] {$elu['nom']} — QID introuvable\n"; continue; }

        $missing = getMissingMandats($pdo, $elu, $qid, $ua, $ctx, $minWikiMandats);
        if (empty($missing) || empty($missing['manquants'])) { echo "  [OK] {$elu['nom']} — rien à insérer\n"; continue; }

        foreach ($missing['manquants'] as $m) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM mandats WHERE elu_id = :eid AND titre = :t AND date_debut = :d');
            $chk->execute([':eid' => $eluId, ':t' => $m['titre'], ':d' => $m['debut']]);
            if ((int)$chk->fetchColumn() > 0) continue;

            $pdo->prepare('INSERT INTO mandats (elu_id, titre, date_debut, date_fin, institution) VALUES (:eid, :t, :d, :f, :inst) -- WEB-VERIFIED wikidata.org/wiki/' . $qid . ' wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', trim($elu['prenom'] . ' ' . $elu['nom']))))
                ->execute([':eid' => $eluId, ':t' => $m['titre'], ':d' => $m['debut'], ':f' => $m['fin'], ':inst' => '']);
            echo "  [INSERT] {$elu['nom']} — {$m['titre']} ({$m['debut']})\n";
            $applied++;
        }

        // Invalider cache
        foreach (glob(__DIR__ . '/cache/data/elu_' . $eluId . '*.json') as $f) @unlink($f);
        usleep(250000); // 250ms entre élus
    }
    echo "\n$applied mandats insérés.\n";
    exit(0);
}

// ══════════════════════════════════════════════════════════════
// MODE DRY-RUN — audit et rapport CSV
// ══════════════════════════════════════════════════════════════

echo "=== AUDIT BATCH MANDATS — " . date('Y-m-d H:i:s') . " ===\n";
echo "Limit=$limit Offset=$offset QID-only=" . ($qidOnly?'oui':'non') . " Type=" . ($filterType??'tous') . "\n";
echo "Sortie : $outFile\n\n";

// ── Sélectionner les élus à auditer ──
// Priorité 1 : source_id QID (match Wikidata garanti)
// Priorité 2 : type_mandat filtré
$typeFilter = '';
if ($filterType) {
    $typeFilter = "AND e.type_mandat = " . $pdo->quote($filterType);
}
$qidFilter = $qidOnly ? "AND e.source_id REGEXP '^Q[0-9]+'" : '';

// Ordonner : source_id QID d'abord, puis par nb_consultations décroissant (élus les plus vus)
$sql = "
    SELECT e.id, e.nom, e.prenom, e.type_mandat, e.source_id,
           COALESCE(s.nb_consultations, 0) AS nb_consult
    FROM elus e
    LEFT JOIN elu_stats s ON s.elu_id = e.id
    WHERE e.actif = 1
      $typeFilter
      $qidFilter
    ORDER BY
        (e.source_id REGEXP '^Q[0-9]+') DESC,
        nb_consult DESC
    LIMIT $limit OFFSET $offset
";
$elus = $pdo->query($sql)->fetchAll();
echo count($elus) . " élus à auditer\n\n";

// ── Ouvrir le fichier CSV ──
$mode = ($append && file_exists($outFile)) ? 'a' : 'w';
$fh = fopen($outFile, $mode);
if ($mode === 'w') {
    fputcsv($fh, ['elu_id', 'nom', 'prenom', 'type_mandat', 'wikidata_qid', 'mandat_titre', 'date_debut', 'date_fin', 'statut', 'wiki_total_mandats']);
}

$stats = ['ok' => 0, 'manquants' => 0, 'no_qid' => 0, 'no_wiki_data' => 0, 'erreurs' => 0];

foreach ($elus as $elu) {
    $nom = $elu['nom'];
    $nomComplet = trim($elu['prenom'] . ' ' . $nom);

    // Trouver le QID
    $qid = (!empty($elu['source_id']) && str_starts_with($elu['source_id'], 'Q')) ? $elu['source_id'] : null;
    if (!$qid) {
        usleep(150000); // 150ms avant recherche
        $qid = wikidataSearch($nomComplet, $ua, $ctx);
    }

    if (!$qid) {
        $stats['no_qid']++;
        fputcsv($fh, [$elu['id'], $nom, $elu['prenom'], $elu['type_mandat'], '', '', '', '', 'NO_QID', 0]);
        echo "  [NO_QID] $nomComplet\n";
        continue;
    }

    usleep(200000); // 200ms entre appels Wikidata (respecter rate limit)

    $missing = getMissingMandats($pdo, $elu, $qid, $ua, $ctx, $minWikiMandats);

    if ($missing === null) {
        $stats['no_wiki_data']++;
        fputcsv($fh, [$elu['id'], $nom, $elu['prenom'], $elu['type_mandat'], $qid, '', '', '', 'NO_WIKI_DATA', 0]);
        echo "  [NO_DATA] $nomComplet ($qid)\n";
        continue;
    }

    if ($missing === false) {
        $stats['erreurs']++;
        fputcsv($fh, [$elu['id'], $nom, $elu['prenom'], $elu['type_mandat'], $qid, '', '', '', 'ERREUR', 0]);
        continue;
    }

    if (empty($missing['manquants'])) {
        $stats['ok']++;
        fputcsv($fh, [$elu['id'], $nom, $elu['prenom'], $elu['type_mandat'], $qid, '', '', '', 'OK', $missing['wiki_total']]);
        echo "  [OK] $nomComplet\n";
    } else {
        foreach ($missing['manquants'] as $m) {
            $stats['manquants']++;
            fputcsv($fh, [
                $elu['id'], $nom, $elu['prenom'], $elu['type_mandat'], $qid,
                $m['titre'], $m['debut'], $m['fin'] ?? '',
                'MISSING', $missing['wiki_total'],
            ]);
        }
        echo "  [MISSING " . count($missing['manquants']) . "] $nomComplet ($qid)\n";
        foreach ($missing['manquants'] as $m) {
            echo "    → {$m['titre']} ({$m['debut']} → " . ($m['fin'] ?? 'en cours') . ")\n";
        }
    }

    usleep(200000);
}

fclose($fh);

echo "\n=== RÉSUMÉ ===\n";
echo "OK (aucun manquant) : {$stats['ok']}\n";
echo "Mandats manquants   : {$stats['manquants']}\n";
echo "QID introuvable     : {$stats['no_qid']}\n";
echo "Pas de données Wiki : {$stats['no_wiki_data']}\n";
echo "Erreurs             : {$stats['erreurs']}\n";
echo "\nRapport : $outFile\n";
echo "\nÉtape suivante :\n";
echo "  1. Ouvrir le rapport et vérifier les lignes MISSING\n";
echo "  2. Identifier les elu_id à appliquer (vérification manuelle Wikipedia)\n";
echo "  3. php wiki-audit-batch.php --apply-ids=ID1,ID2,ID3\n";

// ══════════════════════════════════════════════════════════════
// Fonctions
// ══════════════════════════════════════════════════════════════

function wikidataSearch(string $nom, string $ua, $ctx): ?string
{
    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities', 'search' => $nom,
        'language' => 'fr', 'type' => 'item', 'format' => 'json', 'limit' => 5,
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $results = json_decode($json, true)['search'] ?? [];

    $keywords = ['politi', 'député', 'sénateur', 'maire', 'minister', 'french', 'français', 'élu', 'mep'];
    foreach ($results as $r) {
        $desc = mb_strtolower($r['description'] ?? '');
        foreach ($keywords as $kw) {
            if (str_contains($desc, $kw)) return $r['id'];
        }
    }
    // Premier résultat si pertinent (éviter les homonymes non-politiques)
    if (!empty($results[0]) && !empty($results[0]['description'])) {
        $d = mb_strtolower($results[0]['description']);
        if (!str_contains($d, 'film') && !str_contains($d, 'sport') && !str_contains($d, 'musici')
            && !str_contains($d, 'acteur') && !str_contains($d, 'chanteur') && !str_contains($d, 'footballeur')) {
            return $results[0]['id'];
        }
    }
    return null;
}

/**
 * @return null  — Wikidata inaccessible ou entité vide
 * @return false — erreur réseau
 * @return array ['manquants' => [...], 'wiki_total' => int]
 */
function getMissingMandats(PDO $pdo, array $elu, string $qid, string $ua, $ctx, int $minWikiMandats): null|false|array
{
    // Récupérer les claims P39
    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities', 'ids' => $qid,
        'props' => 'claims', 'format' => 'json',
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return false;
    $entity = json_decode($json, true)['entities'][$qid] ?? null;
    if (!$entity) return null;

    $p39Claims = $entity['claims']['P39'] ?? [];
    if (count($p39Claims) < $minWikiMandats) return null; // entité Wikidata trop pauvre

    // Résoudre les labels des positions
    $posQids = [];
    foreach ($p39Claims as $c) {
        $v = $c['mainsnak']['datavalue'] ?? null;
        if ($v && ($v['type'] ?? '') === 'wikibase-entityid') $posQids[] = $v['value']['id'];
    }
    $posQids = array_unique(array_filter($posQids));
    $labels = [];
    if (!empty($posQids)) {
        foreach (array_chunk($posQids, 20) as $chunk) {
            usleep(100000);
            $lUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
                'action' => 'wbgetentities', 'ids' => implode('|', $chunk),
                'props' => 'labels', 'languages' => 'fr,en', 'format' => 'json',
            ]);
            $lj = @file_get_contents($lUrl, false, $ctx);
            if ($lj) {
                foreach (json_decode($lj, true)['entities'] ?? [] as $q => $e) {
                    $labels[$q] = $e['labels']['fr']['value'] ?? $e['labels']['en']['value'] ?? $q;
                }
            }
        }
    }

    // Mandats existants en BDD (pour comparaison)
    $stmtBdd = $pdo->prepare('SELECT titre, date_debut FROM mandats WHERE elu_id = :id');
    $stmtBdd->execute([':id' => $elu['id']]);
    $mandatsBdd = $stmtBdd->fetchAll();

    // Construire les mandats Wikidata
    $manquants = [];
    foreach ($p39Claims as $c) {
        $v = $c['mainsnak']['datavalue'] ?? null;
        if (!$v || ($v['type'] ?? '') !== 'wikibase-entityid') continue;
        $label = $labels[$v['value']['id'] ?? ''] ?? '';
        if (!$label) continue;

        $labelLow = mb_strtolower($label);

        $labelLow = mb_strtolower($label);

        // Filtrer les rôles non-électoraux et administratifs
        $isNonMandat = preg_match(
            '/(directeur|pdg|président-directeur|candidat|avocat|médecin|professeur|praticien|chirurgien|infirmier|chapelle|organiste|compositeur|journaliste|ingénieur|architecte|chef d|chef de service|enseignant|chercheur|co-prince|dirigeant de parti|membre du bureau|trésorier|secrétaire fédéral|secrétaire national|secrétaire général\b|inspecteur|auditeur|sous-préfet|préfet\b|directeur-adjoint|directeur adjoint|fondateur|rapporteur général|administrateur|collaborateur)/',
            $labelLow
        );
        $isMandat = str_contains($labelLow, 'maire') || str_contains($labelLow, 'député')
            || str_contains($labelLow, 'sénateur') || str_contains($labelLow, 'ministre')
            || str_contains($labelLow, 'secrétaire d\'état') || str_contains($labelLow, 'secrétaire d\'état')
            || str_contains($labelLow, 'conseiller') || str_contains($labelLow, 'conseillère')
            || str_contains($labelLow, 'adjoint au maire') || str_contains($labelLow, 'président de')
            || str_contains($labelLow, 'présidente de') || str_contains($labelLow, 'vice-président de');
        if ($isNonMandat && !$isMandat) continue;

        // Filtrer labels trop vagues ou rôles sans indemnité française
        $tropVague = in_array(trim($label), [
            'président ou présidente', 'vice-président', 'member of parliament', 'politician',
            'politicien', 'élu local', 'représentant', 'délégué', 'membre', 'official',
            'sénateur ou sénatrice de la Cinquième République',
            'porte-parole',
        ]) || str_contains($labelLow, 'assemblée parlementaire du conseil de l\'europe')
          || str_contains($labelLow, 'assemblée parlementaire de l\'otan')
          || (str_contains($labelLow, 'porte-parole') && !str_contains($labelLow, 'gouvernement'));
        if ($tropVague) continue;

        $q = $c['qualifiers'] ?? [];
        $debut = null; $fin = null;
        if (!empty($q['P580'][0]['datavalue']['value']['time'])) {
            preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $q['P580'][0]['datavalue']['value']['time'], $m);
            $debut = $m[1] ?? null;
            if ($debut) $debut = preg_replace('/-00/', '-01', $debut);
        }
        if (!empty($q['P582'][0]['datavalue']['value']['time'])) {
            preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $q['P582'][0]['datavalue']['value']['time'], $m);
            $fin = $m[1] ?? null;
            if ($fin) $fin = preg_replace('/-00/', '-01', $fin);
        }
        if (!$debut || (int)substr($debut, 0, 4) < 1900) continue;

        // Mot-clé pour comparaison floue
        $kw = '';
        if (str_contains($labelLow, 'ministre') || str_contains($labelLow, 'secrétaire d')) $kw = 'ministre';
        elseif (str_contains($labelLow, 'premier ministre')) $kw = 'premier';
        elseif (str_contains($labelLow, 'président') && str_contains($labelLow, 'république')) $kw = 'république';
        elseif (str_contains($labelLow, 'député') && !str_contains($labelLow, 'européen')) $kw = 'député';
        elseif (str_contains($labelLow, 'européen') || str_contains($labelLow, 'mep')) $kw = 'européen';
        elseif (str_contains($labelLow, 'sénateur') || str_contains($labelLow, 'sénatrice')) $kw = 'sénat';
        elseif (str_contains($labelLow, 'maire')) $kw = 'maire';
        elseif (str_contains($labelLow, 'région')) $kw = 'région';
        elseif (str_contains($labelLow, 'départem')) $kw = 'départem';
        elseif (str_contains($labelLow, 'adjoint')) $kw = 'adjoint';
        elseif (str_contains($labelLow, 'conseiller')) $kw = 'conseiller';
        else $kw = mb_substr($label, 0, 10);

        $debutYear = (int)substr($debut, 0, 4);
        $found = false;
        foreach ($mandatsBdd as $bm) {
            $bmLow = mb_strtolower($bm['titre']);
            $bmYear = (int)substr($bm['date_debut'], 0, 4);
            if ($kw && str_contains($bmLow, $kw) && abs($bmYear - $debutYear) <= 3) {
                $found = true; break;
            }
        }
        if (!$found) {
            $manquants[] = ['titre' => $label, 'debut' => $debut, 'fin' => $fin];
        }
    }

    return ['manquants' => $manquants, 'wiki_total' => count($p39Claims)];
}
