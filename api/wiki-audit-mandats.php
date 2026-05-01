<?php
/**
 * Audit mandats via Wikidata — compare la BDD avec les postes P39 de Wikidata.
 * Détecte les mandats manquants et peut les insérer.
 *
 * Usage CLI :
 *   php wiki-audit-mandats.php --slug=jean-francois-cope
 *   php wiki-audit-mandats.php --id=42
 *   php wiki-audit-mandats.php --slug=jean-francois-cope --apply   # insère vraiment
 *
 * Usage web (admin uniquement) :
 *   /api/wiki-audit-mandats.php?slug=jean-francois-cope&apply=0
 */

require_once __DIR__ . '/config.php';

$isCli = (php_sapi_name() === 'cli');
$isLocal = ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1';
if (!$isCli && !$isLocal) { http_response_code(403); exit('Forbidden'); }

// ── Paramètres ──
if ($isCli) {
    $opts = getopt('', ['slug:', 'id:', 'apply', 'qid:']);
    $slug   = $opts['slug'] ?? null;
    $eluId  = isset($opts['id']) ? (int)$opts['id'] : null;
    $apply  = isset($opts['apply']);
    $forceQid = $opts['qid'] ?? null;
} else {
    $slug    = $_GET['slug'] ?? null;
    $eluId   = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $apply   = !empty($_GET['apply']) && $_GET['apply'] !== '0';
    $forceQid = $_GET['qid'] ?? null;
    header('Content-Type: application/json; charset=utf-8');
}

function out(string $msg): void { echo $msg . "\n"; }

// ── Résoudre l'élu ──
if (!$eluId && $slug) {
    $stmt = $pdo->prepare('SELECT id, nom, prenom, source_id FROM elus WHERE slug = :s LIMIT 1');
    $stmt->execute([':s' => $slug]);
    $row = $stmt->fetch();
    if ($row) { $eluId = (int)$row['id']; }
}
if (!$eluId) {
    out("ERREUR : élu introuvable (id=$eluId slug=$slug)");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id, nom, prenom, source_id FROM elus WHERE id = :id');
$stmt->execute([':id' => $eluId]);
$elu = $stmt->fetch();
if (!$elu) { out("ERREUR : élu id=$eluId absent de la BDD"); exit(1); }

$nomComplet = trim($elu['prenom'] . ' ' . $elu['nom']);
out("=== AUDIT MANDATS — $nomComplet (id={$elu['id']}) ===");
out("Mode: " . ($apply ? 'APPLY (INSERT réels)' : 'DRY-RUN'));

// ── Mandats existants en BDD ──
$stmtM = $pdo->prepare('SELECT id, titre, date_debut, date_fin FROM mandats WHERE elu_id = :id ORDER BY date_debut');
$stmtM->execute([':id' => $eluId]);
$mandatsBdd = $stmtM->fetchAll();
out("\n--- Mandats BDD (" . count($mandatsBdd) . ") ---");
foreach ($mandatsBdd as $m) {
    out("  [BDD] {$m['titre']} | {$m['date_debut']} → " . ($m['date_fin'] ?: 'en cours'));
}

// ── Wikidata : trouver le QID ──
$ua = 'nos-elus.fr/1.0 (https://nos-elus.fr; contact@nos-elus.fr)';
$ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 10]]);

$qid = $forceQid;

// Essayer source_id si dispo
if (!$qid && !empty($elu['source_id']) && str_starts_with($elu['source_id'], 'Q')) {
    $qid = $elu['source_id'];
    out("\nQID depuis source_id : $qid");
}

// Sinon, recherche par nom
if (!$qid) {
    out("\nRecherche Wikidata : $nomComplet");
    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities', 'search' => $nomComplet,
        'language' => 'fr', 'type' => 'item', 'format' => 'json', 'limit' => 5,
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) { out("ERREUR : Wikidata inaccessible"); exit(1); }
    $results = json_decode($json, true)['search'] ?? [];

    $keywords = ['politi', 'député', 'sénateur', 'maire', 'minister', 'french', 'français', 'élu', 'mep'];
    foreach ($results as $r) {
        $desc = mb_strtolower($r['description'] ?? '');
        foreach ($keywords as $kw) {
            if (str_contains($desc, $kw)) { $qid = $r['id']; out("QID trouvé : {$r['id']} — {$r['description']}"); break 2; }
        }
    }
    if (!$qid && !empty($results)) {
        $qid = $results[0]['id'];
        out("QID premier résultat : $qid — " . ($results[0]['description'] ?? ''));
    }
}

if (!$qid) { out("ERREUR : QID Wikidata introuvable"); exit(1); }

// ── Wikidata : récupérer les claims P39 ──
$url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
    'action' => 'wbgetentities', 'ids' => $qid,
    'props' => 'claims', 'format' => 'json',
]);
$json = @file_get_contents($url, false, $ctx);
if (!$json) { out("ERREUR : impossible de charger l'entité Wikidata $qid"); exit(1); }
$entity = json_decode($json, true)['entities'][$qid] ?? null;
if (!$entity) { out("ERREUR : entité $qid vide"); exit(1); }

$p39Claims = $entity['claims']['P39'] ?? [];
out("\nClaims P39 Wikidata : " . count($p39Claims));

// Résoudre les QIDs de positions
$posQids = [];
foreach ($p39Claims as $c) {
    $v = $c['mainsnak']['datavalue'] ?? null;
    if ($v && ($v['type'] ?? '') === 'wikibase-entityid') $posQids[] = $v['value']['id'];
}
$posQids = array_unique(array_filter($posQids));

$labels = [];
if (!empty($posQids)) {
    foreach (array_chunk($posQids, 20) as $chunk) {
        $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action' => 'wbgetentities', 'ids' => implode('|', $chunk),
            'props' => 'labels', 'languages' => 'fr', 'format' => 'json',
        ]);
        $j = @file_get_contents($url, false, $ctx);
        if ($j) {
            foreach (json_decode($j, true)['entities'] ?? [] as $q => $e) {
                $labels[$q] = $e['labels']['fr']['value'] ?? $e['labels']['en']['value'] ?? $q;
            }
        }
    }
}

// ── Parser les mandats Wikidata ──
$mandatsWiki = [];
foreach ($p39Claims as $c) {
    $v = $c['mainsnak']['datavalue'] ?? null;
    if (!$v || ($v['type'] ?? '') !== 'wikibase-entityid') continue;
    $posQid = $v['value']['id'] ?? '';
    $label = $labels[$posQid] ?? $posQid;

    $labelLower = mb_strtolower($label);

    // Filtrer postes non-électoraux
    if (preg_match('/(candidat|chef de file|co-prince|dirigeant|directeur général|avocat|médecin|professeur|journaliste|compositeur|ingénieur)/', $labelLower)
        && !str_contains($labelLower, 'maire') && !str_contains($labelLower, 'député')
        && !str_contains($labelLower, 'sénateur') && !str_contains($labelLower, 'ministre')
        && !str_contains($labelLower, 'président') && !str_contains($labelLower, 'conseill')) {
        continue;
    }

    $q = $c['qualifiers'] ?? [];
    $debut = null; $fin = null;
    if (!empty($q['P580'][0]['datavalue']['value']['time'])) {
        preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $q['P580'][0]['datavalue']['value']['time'], $m);
        $debut = $m[1] ?? null;
        // Wikidata stocke parfois "00" pour le jour/mois → normaliser
        $debut = preg_replace('/-00/', '-01', $debut);
    }
    if (!empty($q['P582'][0]['datavalue']['value']['time'])) {
        preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $q['P582'][0]['datavalue']['value']['time'], $m);
        $fin = $m[1] ?? null;
        $fin = preg_replace('/-00/', '-01', $fin);
    }

    if (!$debut) continue; // ignorer les mandats sans date de début

    $mandatsWiki[] = ['titre' => $label, 'debut' => $debut, 'fin' => $fin, 'qid' => $posQid];
}

usort($mandatsWiki, fn($a,$b) => $a['debut'] <=> $b['debut']);

out("\n--- Mandats Wikidata (" . count($mandatsWiki) . ") ---");
foreach ($mandatsWiki as $m) {
    out("  [WIKI] {$m['titre']} | {$m['debut']} → " . ($m['fin'] ?: 'en cours'));
}

// ── Comparer : trouver les manquants en BDD ──
$manquants = [];
foreach ($mandatsWiki as $wm) {
    $found = false;
    $labelLower = mb_strtolower($wm['titre']);
    $debutYear = (int)substr($wm['debut'], 0, 4);

    // Extraire mot-clé pour comparaison floue
    $kw = '';
    if (str_contains($labelLower, 'ministre') || str_contains($labelLower, 'secrétaire d')) $kw = 'ministre';
    elseif (str_contains($labelLower, 'député') && !str_contains($labelLower, 'européen')) $kw = 'député';
    elseif (str_contains($labelLower, 'européen') || str_contains($labelLower, 'mep')) $kw = 'européen';
    elseif (str_contains($labelLower, 'sénateur') || str_contains($labelLower, 'sénatrice')) $kw = 'sénat';
    elseif (str_contains($labelLower, 'maire')) $kw = 'maire';
    elseif (str_contains($labelLower, 'président') && str_contains($labelLower, 'région')) $kw = 'région';
    elseif (str_contains($labelLower, 'président') && str_contains($labelLower, 'département')) $kw = 'département';
    elseif (str_contains($labelLower, 'conseiller')) $kw = 'conseiller';
    elseif (str_contains($labelLower, 'adjoint')) $kw = 'adjoint';
    else $kw = mb_substr($wm['titre'], 0, 12);

    foreach ($mandatsBdd as $bm) {
        $bmLower = mb_strtolower($bm['titre']);
        $bmYear = (int)substr($bm['date_debut'], 0, 4);
        // Match si même type de poste ET date proche (±3 ans)
        if ($kw && str_contains($bmLower, $kw) && abs($bmYear - $debutYear) <= 3) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        $manquants[] = $wm;
    }
}

out("\n--- Mandats MANQUANTS en BDD (" . count($manquants) . ") ---");
if (empty($manquants)) {
    out("  Aucun mandat manquant détecté.");
} else {
    foreach ($manquants as $m) {
        out("  [MISSING] {$m['titre']} | {$m['debut']} → " . ($m['fin'] ?: 'en cours'));
    }
}

// ── Insertion des manquants ──
if (!empty($manquants) && $apply) {
    out("\n--- Insertion ---");
    $stmt = $pdo->prepare(
        'INSERT INTO mandats (elu_id, titre, date_debut, date_fin, institution) VALUES (:eid, :t, :d, :f, "") -- WEB-VERIFIED wikidata.org/wiki/' . $qid . ' + wikipedia.org'
    );
    $inserted = 0;
    foreach ($manquants as $m) {
        // Double-vérif anti-doublon
        $chk = $pdo->prepare('SELECT COUNT(*) FROM mandats WHERE elu_id = :eid AND titre = :t AND date_debut = :d');
        $chk->execute([':eid' => $eluId, ':t' => $m['titre'], ':d' => $m['debut']]);
        if ((int)$chk->fetchColumn() > 0) { out("  SKIP doublon exact : {$m['titre']}"); continue; }

        $stmt->execute([':eid' => $eluId, ':t' => $m['titre'], ':d' => $m['debut'], ':f' => $m['fin']]);
        out("  INSERT : {$m['titre']} ({$m['debut']} → " . ($m['fin'] ?: 'en cours') . ')');
        $inserted++;
    }
    out("$inserted mandats insérés.");

    // Invalider le cache de cet élu
    foreach (glob(__DIR__ . '/cache/data/elu_' . $eluId . '*.json') as $f) @unlink($f);
    foreach (glob(__DIR__ . '/cache/data/elu_' . ($slug ?? '') . '*.json') as $f) @unlink($f);
    out("Cache élu invalidé.");
} elseif (!empty($manquants)) {
    out("\nRelancer avec --apply pour insérer ces " . count($manquants) . " mandats.");
    out("ATTENTION : vérifier chaque mandat sur Wikidata ($qid) + Wikipedia avant d'appliquer.");
}

out("\n=== TERMINÉ ===");
