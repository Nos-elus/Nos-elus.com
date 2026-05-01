<?php
/**
 * Pour les maires faussement classés RN :
 * 1. Cherche le QID Wikidata par nom
 * 2. Lit P102 (membre de parti politique)
 * 3. Si le parti Wikidata ≠ RN → corrige en base
 * 4. Si parti Wikidata = RN → conserve (gagnant 2nd tour)
 * 5. Si pas de Wikidata → marque NULL pour enrich-on-demand
 *
 * Usage : php fix-rn-wrong.php [--apply]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/normalize-parti.php';
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }

$apply = in_array('--apply', $argv ?? []);
echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n\n";

// IDs des maires à corriger (39 mauvaises + 13 non trouvées)
$ids = [
    114440,116757,117764,118225,121636,124625,125274,90702,126944,127804,
    130155,130663,132786,132999,134196,134220,137090,75116,75107,137285,
    137371,75086,136981,75301,139307,139191,140501,141726,143421,143756,
    143929,145464,145551,145536,145524,145549,145600,146096,147893,
    // non trouvées (13)
    116991,118468,118915,119132,104,122721,126071,264,134260,135362,135496,137020,137840,
];

$ua = 'nos-elus.fr/1.0 (contact@nos-elus.fr)';
$ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 8]]);

// Partis RN (Wikidata QIDs)
$rnQids = ['Q1055388', 'Q313330']; // Rassemblement national, Front national

// IDs à ne pas toucher (Wikidata match erroné ou conservé manuellement)
$skipIds = [75116]; // Carole Dubois → match Wikidata = politique québécoise

$stmtElu = $pdo->prepare("SELECT id, nom, prenom, parti, source_id FROM elus WHERE id = :id");
$stmtUpdate = $pdo->prepare("UPDATE elus SET parti = :p WHERE id = :id AND parti = 'Rassemblement national'");

$fixed = 0; $kept = 0; $noData = 0;

foreach ($ids as $id) {
    if (in_array($id, $skipIds)) { echo "[$id] SKIP (exclu manuellement)\n"; continue; }
    $stmtElu->execute([':id' => $id]);
    $elu = $stmtElu->fetch();
    if (!$elu) continue;

    $nom = trim($elu['prenom'] . ' ' . $elu['nom']);
    echo "[$id] $nom\n";

    // Chercher QID Wikidata
    $qid = null;
    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities', 'search' => $nom,
        'language' => 'fr', 'type' => 'item', 'format' => 'json', 'limit' => 3,
    ]);
    $j = @file_get_contents($url, false, $ctx);
    if ($j) {
        foreach (json_decode($j, true)['search'] ?? [] as $r) {
            $desc = mb_strtolower($r['description'] ?? '');
            if (preg_match('/(politi|maire|député|sénateur|conseill|french|français|élu)/', $desc)) {
                $qid = $r['id']; break;
            }
        }
        if (!$qid && !empty(json_decode($j, true)['search'])) {
            $qid = json_decode($j, true)['search'][0]['id'] ?? null;
        }
    }

    if (!$qid) {
        echo "  → pas de QID Wikidata — mise à NULL\n";
        if ($apply) $pdo->prepare("UPDATE elus SET parti = NULL WHERE id = :id AND parti = 'Rassemblement national'")->execute([':id' => $id]);
        $noData++;
        usleep(200000);
        continue;
    }

    // Charger P102
    $url2 = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities', 'ids' => $qid,
        'props' => 'claims', 'format' => 'json',
    ]);
    $j2 = @file_get_contents($url2, false, $ctx);
    $p102Claims = json_decode($j2 ?? '{}', true)['entities'][$qid]['claims']['P102'] ?? [];

    // Récupérer tous les partis P102
    $partiQids = [];
    foreach ($p102Claims as $c) {
        $partiQids[] = $c['mainsnak']['datavalue']['value']['id'] ?? '';
    }
    $partiQids = array_filter(array_unique($partiQids));

    // Vérifier si RN parmi les partis
    $isRn = !empty(array_intersect($partiQids, $rnQids));

    if ($isRn) {
        echo "  → P102 = RN ($qid) — CONSERVÉ\n";
        $kept++;
        usleep(200000);
        continue;
    }

    if (empty($partiQids)) {
        echo "  → pas de P102 — mise à NULL\n";
        if ($apply) $pdo->prepare("UPDATE elus SET parti = NULL WHERE id = :id AND parti = 'Rassemblement national'")->execute([':id' => $id]);
        $noData++;
        usleep(200000);
        continue;
    }

    // Résoudre le label du premier parti
    $firstQid = reset($partiQids);
    $url3 = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities', 'ids' => $firstQid,
        'props' => 'labels', 'languages' => 'fr', 'format' => 'json',
    ]);
    $j3 = @file_get_contents($url3, false, $ctx);
    $partiLabel = json_decode($j3 ?? '{}', true)['entities'][$firstQid]['labels']['fr']['value'] ?? null;
    $partiNorm = $partiLabel ? normalizeParti($partiLabel) : null;

    if ($partiNorm) {
        echo "  → P102 = $partiLabel → $partiNorm — CORRECTION\n";
        if ($apply) {
            $stmtUpdate->execute([':p' => $partiNorm, ':id' => $id]);
        }
        $fixed++;
    } else {
        echo "  → P102 = $partiLabel (non normalisable) — mise à NULL\n";
        if ($apply) $pdo->prepare("UPDATE elus SET parti = NULL WHERE id = :id AND parti = 'Rassemblement national'")->execute([':id' => $id]);
        $noData++;
    }

    usleep(300000); // respecter rate-limit Wikidata
}

echo "\n=== BILAN ===\n";
echo "Conservés RN (légitimes)   : $kept\n";
echo "Corrigés vers autre parti  : $fixed\n";
echo "Mis à NULL (pas de data)   : $noData\n";
if (!$apply) echo "\nRelancer avec --apply pour appliquer.\n";
