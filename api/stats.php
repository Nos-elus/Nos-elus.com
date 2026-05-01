<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
checkRateLimit();

$data = cachedResponse('stats', [], CACHE_TTL_LONG, function() use ($pdo) {
    $stats = [];

    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM elus');
    $stats['total_elus'] = (int)$stmt->fetch()['total'];

    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM affaires');
    $stats['total_affaires'] = (int)$stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM affaires WHERE statut = 'condamne'");
    $stats['total_condamnes'] = (int)$stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM affaires WHERE statut = 'en_cours'");
    $stats['total_en_cours'] = (int)$stmt->fetch()['total'];

    $stmt = $pdo->query('SELECT COUNT(DISTINCT parti) AS total FROM elus WHERE parti IS NOT NULL');
    $stats['total_partis'] = (int)$stmt->fetch()['total'];

    // Top 10 les plus consultés
    $stmt = $pdo->query('
        SELECT id, nom, emoji, parti, nb_consultations
        FROM elus
        ORDER BY nb_consultations DESC
        LIMIT 10
    ');
    $stats['top_consultes'] = $stmt->fetchAll();

    // Top casseroles — condamnés en priorité, puis en cours
    $stmt = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.emoji, e.parti, e.slug, e.photo_url, e.fonction,
               COUNT(a.id) AS nb_affaires,
               SUM(CASE WHEN a.statut = 'condamne' THEN 1 ELSE 0 END) AS nb_condamne,
               SUM(CASE WHEN a.statut = 'en_cours' THEN 1 ELSE 0 END) AS nb_en_cours,
               SUM(CASE WHEN a.statut = 'classe' THEN 1 ELSE 0 END) AS nb_classe,
               SUM(CASE WHEN a.statut = 'relaxe' THEN 1 ELSE 0 END) AS nb_relaxe
        FROM elus e
        JOIN affaires a ON a.elu_id = e.id
        WHERE a.statut != 'clean'
        GROUP BY e.id
        ORDER BY nb_condamne DESC, nb_en_cours DESC, nb_affaires DESC
        LIMIT 30
    ");
    $stats['top_casseroles'] = $stmt->fetchAll();

    // Répartition par parti
    $stmt = $pdo->query('
        SELECT parti, COUNT(*) AS nb FROM elus
        WHERE parti IS NOT NULL
        GROUP BY parti ORDER BY nb DESC LIMIT 20
    ');
    $stats['partis'] = $stmt->fetchAll();

    // Top mandats — même requête que palmares.php pour cohérence
    $stmt = $pdo->query('
        SELECT e.id, e.nom, e.prenom, e.emoji, e.parti, e.slug, e.fonction, e.photo_url, e.couleur,
               COUNT(m.id) AS nb_mandats
        FROM elus e
        JOIN mandats m ON m.elu_id = e.id
        WHERE m.titre NOT LIKE "%andidat%"
          AND (m.date_debut IS NULL OR m.date_debut = "0000-00-00" OR m.date_debut >= "1900-01-01")
        GROUP BY e.id
        ORDER BY nb_mandats DESC
        LIMIT 10
    ');
    $stats['top_mandats'] = $stmt->fetchAll();

    // Top fortunés (basé sur patrimoine_detail JSON — champ total ou fortune_estimee)
    $stmt = $pdo->query("
        SELECT id, nom, prenom, emoji, parti, slug, fonction, photo_url, couleur,
               patrimoine_info, patrimoine_detail,
               COALESCE(
                   JSON_UNQUOTE(JSON_EXTRACT(patrimoine_detail, '$.fortune_estimee')),
                   JSON_UNQUOTE(JSON_EXTRACT(patrimoine_detail, '$.total'))
               ) AS fortune_montant
        FROM elus
        WHERE patrimoine_detail IS NOT NULL AND patrimoine_detail != ''
        AND patrimoine_detail != 'null'
        ORDER BY CAST(COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(patrimoine_detail, '$.fortune_estimee')),
            JSON_UNQUOTE(JSON_EXTRACT(patrimoine_detail, '$.total')),
            '0'
        ) AS UNSIGNED) DESC
        LIMIT 10
    ");
    $fortunes = $stmt->fetchAll();
    foreach ($fortunes as &$f) {
        $montant = (int)($f['fortune_montant'] ?? 0);
        if ($montant >= 1000000) $f['patrimoine_info'] = number_format($montant / 1000000, 1, ',', '') . 'M€';
        elseif ($montant >= 1000) $f['patrimoine_info'] = number_format($montant / 1000, 0, '', ' ') . 'k€';
        elseif ($montant > 0) $f['patrimoine_info'] = number_format($montant, 0, '', ' ') . '€';
        unset($f['patrimoine_detail'], $f['fortune_montant']);
    }
    $stats['top_fortunes'] = $fortunes;

    return $stats;
});

// Pré-calcul des counts likes/dislikes depuis le fichier de votes
// Lecture des votes avec flock pour éviter les corruptions en écriture concurrente
$votesFile = __DIR__ . '/cache/data/votes_citoyens.json';
$allVotesData = [];
if (file_exists($votesFile)) {
    $fp = @fopen($votesFile, 'r');
    if ($fp && flock($fp, LOCK_SH)) {
        $raw = stream_get_contents($fp);
        $allVotesData = $raw ? (json_decode($raw, true) ?: []) : [];
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
$globalLikeCounts = [];
$globalDislikeCounts = [];
foreach ($allVotesData as $entry) {
    $eluId = (int)($entry['elu_id'] ?? 0);
    $vote = $entry['vote'] ?? null;
    if ($eluId > 0) {
        if ($vote === 1 || $vote === 'like') {
            $globalLikeCounts[$eluId] = ($globalLikeCounts[$eluId] ?? 0) + 1;
        } elseif ($vote === -1 || $vote === 'dislike') {
            $globalDislikeCounts[$eluId] = ($globalDislikeCounts[$eluId] ?? 0) + 1;
        }
    }
}

// Top likes : cache séparé avec TTL court (60s) pour être dynamique
$topLikes = cachedResponse('top_likes', [], 60, function() use ($pdo, $globalLikeCounts, $globalDislikeCounts) {
    $topLikes = [];
    if (!empty($globalLikeCounts)) {
        $likeCounts = $globalLikeCounts;
        arsort($likeCounts);
        $topIds = array_keys(array_slice($likeCounts, 0, 50, true));
        $placeholders = implode(',', array_map('intval', $topIds));
        $stmt = $pdo->query("SELECT id, nom, prenom, emoji, parti, slug, fonction, photo_url, couleur FROM elus WHERE id IN ($placeholders)");
        $elusMap = [];
        foreach ($stmt->fetchAll() as $e) $elusMap[$e['id']] = $e;
        foreach ($topIds as $eid) {
            if (isset($elusMap[$eid])) {
                $row = $elusMap[$eid];
                $row['nb_likes'] = $likeCounts[$eid];
                $row['nb_dislikes'] = $globalDislikeCounts[$eid] ?? 0;
                $topLikes[] = $row;
            }
        }
    }
    return $topLikes;
});

// Top dislikes : cache séparé avec TTL court (60s)
$topDislikes = cachedResponse('top_dislikes', [], 60, function() use ($pdo, $globalLikeCounts, $globalDislikeCounts) {
    $topDislikes = [];
    if (!empty($globalDislikeCounts)) {
        $dislikeCounts = $globalDislikeCounts;
        arsort($dislikeCounts);
        $topIds = array_keys(array_slice($dislikeCounts, 0, 50, true));
        $placeholders = implode(',', array_map('intval', $topIds));
        $stmt = $pdo->query("SELECT id, nom, prenom, emoji, parti, slug, fonction, photo_url, couleur FROM elus WHERE id IN ($placeholders)");
        $elusMap = [];
        foreach ($stmt->fetchAll() as $e) $elusMap[$e['id']] = $e;
        foreach ($topIds as $eid) {
            if (isset($elusMap[$eid])) {
                $row = $elusMap[$eid];
                $row['nb_dislikes'] = $dislikeCounts[$eid];
                $row['nb_likes'] = $globalLikeCounts[$eid] ?? 0;
                $topDislikes[] = $row;
            }
        }
    }
    return $topDislikes;
});

$data['top_likes'] = $topLikes;
$data['top_dislikes'] = $topDislikes;

jsonResponse($data);
