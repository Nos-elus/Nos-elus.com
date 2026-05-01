<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

$q = getStringParam('q', 100);

if (mb_strlen($q) < 2) {
    jsonResponse([]);
}

// Normaliser la casse pour clé de cache cohérente
$q = mb_strtolower(trim($q));

// Cache : résultats de recherche (TTL court)
$data = cachedResponse('search', ['q' => $q], CACHE_TTL_SEARCH, function() use ($pdo, $q) {
    // Chercher en local (FULLTEXT + LIKE fallback) — retour immédiat
    $results = searchLocal($pdo, $q);

    // Fetch externe UNIQUEMENT si 0 résultat (pas 3 — évite les 10s de latence)
    if (count($results) === 0) {
        require_once __DIR__ . '/fetcher/DataFetcher.php';
        $fetcher = new DataFetcher($pdo);
        $newIds = $fetcher->search($q);

        if (!empty($newIds)) {
            // Récupérer les nouveaux élus par IDs (pas de re-scan)
            $placeholders = implode(',', array_map('intval', $newIds));
            $stmt = $pdo->query("
                SELECT id, nom, prenom, parti, fonction, emoji, photo_url,
                       slug, nb_consultations,
                       score_integrite, score_transparence, score_assiduite, score_coherence, score_bilan
                FROM elus WHERE id IN ($placeholders)
                ORDER BY nb_consultations DESC
                LIMIT 20
            ");
            $results = $stmt->fetchAll();
        }
    }

    return $results;
});

jsonResponse($data);

// Supprimer les accents pour recherche insensible
function removeAccents(string $str): string {
    $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
    return $transliterator ? $transliterator->transliterate($str) : $str;
}

function searchLocal(PDO $pdo, string $q): array {
    // Essayer FULLTEXT d'abord — filtrer les mots < 3 chars (ignorés par MySQL)
    try {
        $words = array_filter(preg_split('/\s+/', trim($q)), fn($w) => mb_strlen($w) >= 3);
        if (!empty($words)) {
            $boolQuery = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
            $stmt = $pdo->prepare('
                SELECT id, nom, prenom, parti, fonction, emoji, photo_url,
                       slug, nb_consultations,
                       score_integrite, score_transparence, score_assiduite, score_coherence, score_bilan,
                       MATCH(nom, prenom) AGAINST(:q IN BOOLEAN MODE) AS relevance
                FROM elus
                WHERE MATCH(nom, prenom) AGAINST(:q2 IN BOOLEAN MODE)
                ORDER BY relevance DESC, nb_consultations DESC
                LIMIT 20
            ');
            $stmt->execute([':q' => $boolQuery, ':q2' => $boolQuery]);
            $results = $stmt->fetchAll();
            if (!empty($results)) return $results;
        }
    } catch (PDOException $e) {
        // FULLTEXT peut échouer, fallback LIKE
    }

    // Fallback LIKE — 4 conditions (sans CONVERT redondant)
    $like = '%' . $q . '%';
    $likeNoAccent = '%' . removeAccents($q) . '%';
    $stmt = $pdo->prepare('
        SELECT id, nom, prenom, parti, fonction, emoji, photo_url,
               slug, nb_consultations,
               score_integrite, score_transparence, score_assiduite, score_coherence, score_bilan
        FROM elus
        WHERE nom LIKE :q1 OR prenom LIKE :q2
           OR nom LIKE :qa1 OR prenom LIKE :qa2
        ORDER BY nb_consultations DESC
        LIMIT 20
    ');
    $stmt->execute([':q1' => $like, ':q2' => $like, ':qa1' => $likeNoAccent, ':qa2' => $likeNoAccent]);
    return $stmt->fetchAll();
}
