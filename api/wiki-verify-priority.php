<?php
/**
 * Vérifie via Wikidata P102 le parti des maires prioritaires non résolus
 * par les CSV 2026 (grandes communes avec parti nommé).
 *
 * Usage : php wiki-verify-priority.php [--apply]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/normalize-parti.php';
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }

$apply = in_array('--apply', $argv ?? []);
echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n\n";

// IDs prioritaires : maires actifs >500 hab non trouvés dans CSV 2026
// Plus tous les maires avec parti nommé non-générique dont la commune
// n'a pas pu être matchée (population >= 1000 et parti nommé)
$stmt = $pdo->query("
    SELECT id, nom, prenom, parti, fonction, population, departement
    FROM elus
    WHERE type_mandat = 'maire'
      AND fonction NOT LIKE 'Ancien%'
      AND population >= 1000
      AND parti IS NOT NULL
      AND parti NOT IN ('Sans étiquette','')
      AND parti NOT LIKE 'Divers%'
      AND parti NOT LIKE 'Union de%'
      AND parti NOT LIKE 'Union du%'
      AND parti NOT IN ('Régionaliste','Écologiste','Horizons','UDI','Mouvement Démocrate','Union du Centre')
    ORDER BY population DESC
");
$maires = $stmt->fetchAll();
echo count($maires) . " maires avec parti nommé à vérifier via Wikidata\n\n";

$ua  = 'nos-elus.fr/1.0 (contact@nos-elus.fr)';
$ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 8]]);

$confirmed = 0; $corrected = 0; $noData = 0; $errors = 0;

foreach ($maires as $m) {
    $nom = trim($m['prenom'] . ' ' . $m['nom']);
    $pop = number_format($m['population'] ?? 0, 0, '.', ' ');

    // Extraire commune
    preg_match('/Maire\s*[\x{2014}\-]\s*(.+)$/iu', $m['fonction'], $match);
    $commune = trim(preg_replace('/\s*[\/\|].+$/', '', $match[1] ?? ''));

    echo "[{$m['id']}] $nom | $commune | {$pop} hab | parti actuel: {$m['parti']}\n";

    // 1. Chercher QID Wikidata
    $j = @file_get_contents('https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities', 'search' => $nom,
        'language' => 'fr', 'type' => 'item', 'format' => 'json', 'limit' => 5,
    ]), false, $ctx);
    usleep(200000);

    $qid = null;
    foreach (json_decode($j ?? '{}', true)['search'] ?? [] as $r) {
        $desc = mb_strtolower($r['description'] ?? '');
        if (preg_match('/(politi|maire|député|sénateur|conseill|french|français|élu)/', $desc)) {
            $qid = $r['id']; break;
        }
    }
    if (!$qid) {
        // Fallback : premier résultat avec description non vide
        foreach (json_decode($j ?? '{}', true)['search'] ?? [] as $r) {
            if (!empty($r['description'])) { $qid = $r['id']; break; }
        }
    }

    if (!$qid) {
        echo "  → pas de QID Wikidata\n\n";
        $noData++;
        continue;
    }

    // 2. Récupérer P102 (parti politique)
    $j2 = @file_get_contents('https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities', 'ids' => $qid,
        'props' => 'claims|labels', 'languages' => 'fr', 'format' => 'json',
    ]), false, $ctx);
    usleep(300000);

    $entity = json_decode($j2 ?? '{}', true)['entities'][$qid] ?? [];
    $labelFr = $entity['labels']['fr']['value'] ?? 'inconnu';
    $p102    = $entity['claims']['P102'] ?? [];

    echo "  → QID=$qid ($labelFr)\n";

    if (empty($p102)) {
        echo "  → pas de P102\n\n";
        $noData++;
        continue;
    }

    // 3. Récupérer labels de tous les partis P102
    $partiQids = array_filter(array_unique(array_map(
        fn($c) => $c['mainsnak']['datavalue']['value']['id'] ?? null, $p102
    )));

    if (empty($partiQids)) { echo "  → P102 vide\n\n"; $noData++; continue; }

    // Résoudre les labels
    $j3 = @file_get_contents('https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities',
        'ids'    => implode('|', array_slice($partiQids, 0, 5)),
        'props'  => 'labels', 'languages' => 'fr', 'format' => 'json',
    ]), false, $ctx);
    usleep(300000);

    $entitiesPartis = json_decode($j3 ?? '{}', true)['entities'] ?? [];
    $partiLabels = [];
    foreach ($partiQids as $pqid) {
        $label = $entitiesPartis[$pqid]['labels']['fr']['value'] ?? null;
        if ($label) $partiLabels[] = $label;
    }

    echo "  → P102 : " . implode(', ', $partiLabels) . "\n";

    // Choisir le parti le plus récent (dernier dans la liste ou le plus connu)
    $partiNorm = null;
    foreach (array_reverse($partiLabels) as $label) {
        $n = normalizeParti($label);
        if ($n && $n !== 'Divers' && $n !== 'Sans étiquette') {
            $partiNorm = $n;
            break;
        }
    }
    if (!$partiNorm && !empty($partiLabels)) {
        $partiNorm = normalizeParti($partiLabels[count($partiLabels) - 1]);
    }

    if (!$partiNorm) {
        echo "  → non normalisable\n\n";
        $noData++;
        continue;
    }

    $partiBdd = $m['parti'];
    if (mb_strtolower($partiNorm) === mb_strtolower($partiBdd)) {
        echo "  → CONFIRMÉ : $partiBdd\n\n";
        $confirmed++;
        continue;
    }

    echo "  → DIFFÉRENCE : BDD=$partiBdd | Wikidata=$partiNorm\n";

    // Décision : Wikidata donne un parti nommé différent
    // On fait confiance à Wikidata sauf si BDD est plus spécifique
    $isBddGeneric = str_starts_with($partiBdd, 'Divers') || str_starts_with($partiBdd, 'Union de');
    if (!$isBddGeneric) {
        // Deux partis nommés → afficher mais ne corriger que si --apply
        echo "  → [!] CORRECTION possible : $partiBdd → $partiNorm\n\n";
        $corrected++;
        if ($apply) {
            $pdo->prepare("UPDATE elus SET parti = :p WHERE id = :id -- WEB-VERIFIED")
                ->execute([':p' => $partiNorm, ':id' => $m['id']]);
        }
    } else {
        echo "  → UPDATE générique→nommé : $partiBdd → $partiNorm\n\n";
        $corrected++;
        if ($apply) {
            $pdo->prepare("UPDATE elus SET parti = :p WHERE id = :id -- WEB-VERIFIED")
                ->execute([':p' => $partiNorm, ':id' => $m['id']]);
        }
    }
}

echo "=== RÉSUMÉ ===\n";
echo "Confirmés Wikidata   : $confirmed\n";
echo "Corrections trouvées : $corrected\n";
echo "Sans données         : $noData\n";
if (!$apply && $corrected > 0) echo "\nRelancer avec --apply pour appliquer.\n";
