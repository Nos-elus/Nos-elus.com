<?php
/**
 * Enrichissement à la demande via Wikidata.
 * Appelé automatiquement quand un profil incomplet est consulté.
 * S'exécute en arrière-plan (après envoi de la réponse au client).
 *
 * Usage interne : require + appel enrichIfNeeded($pdo, $elu)
 */

function enrichIfNeeded(PDO $pdo, array $elu): void {
    // Ne pas enrichir les élus manuels ni les candidats (données curatées)
    if (($elu['source_api'] ?? '') === 'manual' || !empty($elu['is_candidat'])) return;

    // Vérifier si l'élu a besoin d'enrichissement
    $needsEnrich = empty($elu['bio']) || empty($elu['parti']);
    if (!$needsEnrich) return;

    // Vérifier qu'on n'a pas déjà essayé récemment (1 semaine)
    $lockFile = __DIR__ . '/cache/data/enrich_' . $elu['id'] . '.lock';
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 604800) return;
    touch($lockFile);

    // Chercher sur Wikidata
    $nom = trim(($elu['prenom'] ?? '') . ' ' . ($elu['nom'] ?? ''));
    if (mb_strlen($nom) < 3) return;

    $ua = 'nos-elus.fr/1.0 (https://nos-elus.fr; contact@nos-elus.fr)';

    // 1. Recherche Wikidata
    $searchUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities', 'search' => $nom,
        'language' => 'fr', 'type' => 'item', 'format' => 'json', 'limit' => 3,
    ]);
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 5]]);
    $json = @file_get_contents($searchUrl, false, $ctx);
    if (!$json) return;
    $results = json_decode($json, true)['search'] ?? [];

    // Trouver un match politique
    $qid = null;
    $keywords = ['politi', 'député', 'sénateur', 'maire', 'minister', 'french', 'français'];
    foreach ($results as $r) {
        $desc = mb_strtolower($r['description'] ?? '');
        foreach ($keywords as $kw) {
            if (mb_strpos($desc, $kw) !== false) { $qid = $r['id']; break 2; }
        }
    }
    if (!$qid) return;

    // 2. Récupérer l'entité
    $entityUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities', 'ids' => $qid,
        'props' => 'claims|descriptions', 'languages' => 'fr', 'format' => 'json',
    ]);
    $json = @file_get_contents($entityUrl, false, $ctx);
    if (!$json) return;
    $entity = json_decode($json, true)['entities'][$qid] ?? null;
    if (!$entity) return;

    // 3. Extraire les données
    $bio = $entity['descriptions']['fr']['value'] ?? null;

    // Photo P18
    $photoUrl = null;
    $p18 = $entity['claims']['P18'][0]['mainsnak']['datavalue'] ?? null;
    if ($p18 && ($p18['type'] ?? '') === 'string') {
        $filename = str_replace(' ', '_', $p18['value']);
        $photoUrl = 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($filename) . '?width=200';
    }

    // Date naissance P569
    $dob = null;
    $p569 = $entity['claims']['P569'][0]['mainsnak']['datavalue'] ?? null;
    if ($p569 && ($p569['type'] ?? '') === 'time') {
        $time = $p569['value']['time'] ?? '';
        if (preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $time, $m)) $dob = $m[1];
    }

    // Parti P102
    $partiQid = null;
    $p102 = $entity['claims']['P102'][0]['mainsnak']['datavalue'] ?? null;
    if ($p102 && ($p102['type'] ?? '') === 'wikibase-entityid') {
        $partiQid = $p102['value']['id'] ?? null;
    }
    $parti = null;
    if ($partiQid) {
        $partiUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action' => 'wbgetentities', 'ids' => $partiQid,
            'props' => 'labels', 'languages' => 'fr', 'format' => 'json',
        ]);
        $pJson = @file_get_contents($partiUrl, false, $ctx);
        if ($pJson) {
            $parti = json_decode($pJson, true)['entities'][$partiQid]['labels']['fr']['value'] ?? null;
        }
    }

    // Mandats P39
    $p39Claims = $entity['claims']['P39'] ?? [];
    $mandatQids = [];
    foreach ($p39Claims as $c) {
        $v = $c['mainsnak']['datavalue'] ?? null;
        if ($v && ($v['type'] ?? '') === 'wikibase-entityid') {
            $mandatQids[] = $v['value']['id'] ?? '';
        }
    }
    $mandatLabels = [];
    $mandatQids = array_filter(array_unique($mandatQids));
    if (!empty($mandatQids)) {
        $bUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action' => 'wbgetentities', 'ids' => implode('|', array_slice($mandatQids, 0, 20)),
            'props' => 'labels', 'languages' => 'fr', 'format' => 'json',
        ]);
        $bJson = @file_get_contents($bUrl, false, $ctx);
        if ($bJson) {
            foreach (json_decode($bJson, true)['entities'] ?? [] as $q => $e) {
                $mandatLabels[$q] = $e['labels']['fr']['value'] ?? '';
            }
        }
    }

    // 4. UPDATE BDD
    $updates = [];
    $params = [':id' => $elu['id']];

    if ($bio && empty($elu['bio'])) { $updates[] = 'bio = :bio'; $params[':bio'] = $bio; }
    if ($photoUrl && empty($elu['photo_url'])) { $updates[] = 'photo_url = :photo'; $params[':photo'] = $photoUrl; }
    if ($dob && empty($elu['date_naissance'])) { $updates[] = 'date_naissance = :dob'; $params[':dob'] = $dob; }
    if ($parti && empty($elu['parti'])) {
        require_once __DIR__ . '/normalize-parti.php';
        $updates[] = 'parti = :parti';
        $params[':parti'] = normalizeParti($parti);
    }

    if (!empty($updates)) {
        $updates[] = 'derniere_sync = NOW()';
        $sql = 'UPDATE elus SET ' . implode(', ', $updates) . ' WHERE id = :id AND source_api != "manual"';
        try { $pdo->prepare($sql)->execute($params); } catch (PDOException $e) {}
    }

    // 5. INSERT mandats
    foreach ($p39Claims as $c) {
        $v = $c['mainsnak']['datavalue'] ?? null;
        if (!$v || ($v['type'] ?? '') !== 'wikibase-entityid') continue;
        $posQid = $v['value']['id'] ?? '';
        $label = $mandatLabels[$posQid] ?? '';
        if (!$label) continue;

        $debut = null; $fin = null;
        $q = $c['qualifiers'] ?? [];
        if (!empty($q['P580'][0]['datavalue']['value']['time'])) {
            preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $q['P580'][0]['datavalue']['value']['time'], $m);
            $debut = $m[1] ?? null;
        }
        if (!empty($q['P582'][0]['datavalue']['value']['time'])) {
            preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $q['P582'][0]['datavalue']['value']['time'], $m);
            $fin = $m[1] ?? null;
        }
        if (!$debut) continue;

        // Filtrer les mandats non-pertinents (co-prince, dirigeant de parti, etc.)
        $labelLower = mb_strtolower($label);
        if (str_contains($labelLower, 'co-prince') || str_contains($labelLower, 'dirigeant')
            || str_contains($labelLower, 'candidat') || str_contains($labelLower, 'chef de file')) continue;

        // Vérifier doublon : même élu + même type de poste + année proche (±2 ans)
        $debutYear = substr($debut, 0, 4);
        try {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM mandats WHERE elu_id = :eid AND ABS(YEAR(date_debut) - :y) <= 2 AND (
                LOWER(titre) LIKE :pattern1 OR LOWER(titre) LIKE :pattern2
            )');
            // Extraire le mot-clé principal du titre
            $kw = 'xxxxxx';
            if (str_contains($labelLower, 'président de la rép')) $kw = '%président%république%';
            elseif (str_contains($labelLower, 'ministre')) $kw = '%ministre%';
            elseif (str_contains($labelLower, 'député européen') || str_contains($labelLower, 'européen')) $kw = '%européen%';
            elseif (str_contains($labelLower, 'député')) $kw = '%député%';
            elseif (str_contains($labelLower, 'sénateur')) $kw = '%sénat%';
            elseif (str_contains($labelLower, 'maire')) $kw = '%maire%';
            elseif (str_contains($labelLower, 'conseiller')) $kw = '%conseiller%';
            else $kw = '%' . mb_substr($label, 0, 15) . '%';

            $chk->execute([':eid' => $elu['id'], ':y' => $debutYear, ':pattern1' => $kw, ':pattern2' => '%' . mb_substr($label, 0, 20) . '%']);
            if ((int)$chk->fetchColumn() > 0) continue;

            $pdo->prepare('INSERT INTO mandats (elu_id, titre, date_debut, date_fin, institution) VALUES (:eid, :t, :d, :f, "")')
                ->execute([':eid' => $elu['id'], ':t' => $label, ':d' => $debut, ':f' => $fin]);
        } catch (PDOException $e) {}
    }
}
