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

// Détecter les patterns d'injection (anti-amplification de scan vers nosdeputes.fr).
// Une recherche d'élu ne contient jamais ces caractères ni ces séquences.
$qLooksSuspicious = (bool) preg_match(
    '/[\x00-\x1f;=#\\\\<>\[\]\{\}|]|--|\bor\s+\d+\s*=\s*\d+|\band\s+\d+\s*=\s*\d+/i',
    $q
);

// Strip des caractères qui n'appartiennent jamais à un nom/fonction
$q = preg_replace('/[\x00-\x1f;=#\\\\<>\[\]\{\}|]/u', '', $q);
// Échapper les opérateurs MySQL FULLTEXT BOOLEAN MODE (sinon erreur SQL)
$q = preg_replace('/[+\-><()~*"@]/', ' ', $q);
$q = trim(preg_replace('/\s+/', ' ', $q));
if ($q === '') { jsonResponse([]); }

// Cache : résultats de recherche (TTL court)
$data = cachedResponse('search', ['q' => $q], CACHE_TTL_SEARCH, function() use ($pdo, $q, $qLooksSuspicious) {
    // Chercher en local (FULLTEXT + LIKE fallback) — retour immédiat
    $results = searchLocal($pdo, $q);

    // Fetch externe UNIQUEMENT si 0 résultat ET query non suspecte (anti-amplification)
    if (count($results) === 0 && !$qLooksSuspicious) {
        require_once __DIR__ . '/fetcher/DataFetcher.php';
        $fetcher = new DataFetcher($pdo);
        $newIds = $fetcher->search($q);

        if (!empty($newIds)) {
            // Récupérer les nouveaux élus par IDs (pas de re-scan)
            $placeholders = implode(',', array_map('intval', $newIds));
            $stmt = $pdo->query("
                SELECT id, nom, prenom, parti, fonction, emoji, photo_url,
                       slug, nb_consultations,
                       score_transparence, score_assiduite, score_coherence, score_bilan
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

// Supprimer les accents pour recherche insensible (pur PHP, sans extension intl)
function removeAccents(string $str): string {
    if (class_exists('Transliterator')) {
        $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($transliterator) return $transliterator->transliterate($str);
    }
    static $map = null;
    if ($map === null) {
        $map = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
            'ý'=>'y','ÿ'=>'y','Ý'=>'Y','Ÿ'=>'Y',
            'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N',
            'œ'=>'oe','Œ'=>'OE','æ'=>'ae','Æ'=>'AE',
        ];
    }
    return strtr($str, $map);
}

function searchLocal(PDO $pdo, string $q): array {
    // FULLTEXT (nom/prenom) combiné avec LIKE sur fonction (commune)
    $like = '%' . $q . '%';
    $likeNoAccent = '%' . removeAccents($q) . '%';
    try {
        $words = array_filter(preg_split('/\s+/', trim($q)), fn($w) => mb_strlen($w) >= 3);
        if (!empty($words)) {
            $boolQuery = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
            $stmt = $pdo->prepare('
                SELECT id, nom, prenom, parti, fonction, emoji, photo_url,
                       slug, nb_consultations,
                       score_transparence, score_assiduite, score_coherence, score_bilan,
                       MATCH(nom, prenom) AGAINST(:q1 IN BOOLEAN MODE) AS relevance,
                       (CASE
                           WHEN fonction LIKE "%Président de la République%" THEN 100
                           WHEN fonction LIKE "%Premier ministre%" OR fonction LIKE "%Premier Ministre%" THEN 95
                           WHEN fonction LIKE "%onseil constitutionnel%" THEN 90
                           WHEN fonction LIKE "%inistre%" OR fonction LIKE "%arde des Sceaux%" THEN 85
                           WHEN fonction LIKE "%énateur%" THEN 70
                           WHEN fonction LIKE "%éputé%" AND fonction NOT LIKE "%urodéputé%" AND fonction NOT LIKE "%uropéen%" THEN 65
                           WHEN fonction LIKE "%urodéputé%" OR fonction LIKE "%uropéen%" THEN 55
                           WHEN fonction LIKE "%aire%" THEN 35
                           WHEN fonction LIKE "%onseiller régional%" OR fonction LIKE "%onseiller regional%" THEN 25
                           WHEN fonction LIKE "%onseiller départemental%" OR fonction LIKE "%onseiller departemental%" THEN 20
                           ELSE 10
                       END) AS importance,
                       (CASE
                           WHEN fonction LIKE :ex1 OR fonction LIKE :ex2 THEN 100
                           WHEN fonction LIKE :exA1 OR fonction LIKE :exA2 THEN 90
                           WHEN nom LIKE :nl OR prenom LIKE :pl THEN 50
                           WHEN fonction LIKE :fl1 OR fonction LIKE :fla1 THEN 30
                           ELSE 0
                       END) AS commune_match
                FROM elus
                WHERE MATCH(nom, prenom) AGAINST(:q2 IN BOOLEAN MODE)
                   OR fonction LIKE :fl2 OR fonction LIKE :fla2
                ORDER BY commune_match DESC, relevance DESC, importance DESC, COALESCE(salaire_brut, 0) DESC, nb_consultations DESC
                LIMIT 20
            ');
            $qNoAcc = removeAccents($q);
            $stmt->execute([
                ':q1' => $boolQuery, ':q2' => $boolQuery,
                ':fl1' => $like, ':fla1' => $likeNoAccent,
                ':fl2' => $like, ':fla2' => $likeNoAccent,
                ':ex1' => "%— $q", ':ex2' => "%— $q /%",
                ':exA1' => "%— $qNoAcc", ':exA2' => "%— $qNoAcc /%",
                ':nl' => $like, ':pl' => $like,
            ]);
            $results = $stmt->fetchAll();
            if (!empty($results)) return $results;
        }
    } catch (PDOException $e) {
        // FULLTEXT peut échouer, fallback LIKE
    }

    // Fallback LIKE — recherche sur nom + prenom + fonction (commune)
    $stmt = $pdo->prepare('
        SELECT id, nom, prenom, parti, fonction, emoji, photo_url,
               slug, nb_consultations,
               score_transparence, score_assiduite, score_coherence, score_bilan,
               (CASE
                   WHEN fonction LIKE "%Président de la République%" THEN 100
                   WHEN fonction LIKE "%Premier ministre%" OR fonction LIKE "%Premier Ministre%" THEN 95
                   WHEN fonction LIKE "%onseil constitutionnel%" THEN 90
                   WHEN fonction LIKE "%inistre%" OR fonction LIKE "%arde des Sceaux%" THEN 85
                   WHEN fonction LIKE "%énateur%" THEN 70
                   WHEN fonction LIKE "%éputé%" AND fonction NOT LIKE "%urodéputé%" AND fonction NOT LIKE "%uropéen%" THEN 65
                   WHEN fonction LIKE "%urodéputé%" OR fonction LIKE "%uropéen%" THEN 55
                   WHEN fonction LIKE "%aire%" THEN 35
                   WHEN fonction LIKE "%onseiller régional%" OR fonction LIKE "%onseiller regional%" THEN 25
                   WHEN fonction LIKE "%onseiller départemental%" OR fonction LIKE "%onseiller departemental%" THEN 20
                   ELSE 10
               END) AS importance,
               (CASE
                   WHEN nom LIKE :qn1 OR prenom LIKE :qn2 THEN 100
                   WHEN nom LIKE :qna1 OR prenom LIKE :qna2 THEN 90
                   WHEN fonction LIKE :qf1 THEN 50
                   WHEN fonction LIKE :qfa1 THEN 40
                   ELSE 0
               END) AS match_score
        FROM elus
        WHERE nom LIKE :q1 OR prenom LIKE :q2
           OR nom LIKE :qa1 OR prenom LIKE :qa2
           OR fonction LIKE :qf2 OR fonction LIKE :qfa2
        ORDER BY match_score DESC, importance DESC, COALESCE(salaire_brut, 0) DESC, nb_consultations DESC
        LIMIT 20
    ');
    $stmt->execute([
        ':q1' => $like, ':q2' => $like, ':qa1' => $likeNoAccent, ':qa2' => $likeNoAccent,
        ':qn1' => $like, ':qn2' => $like, ':qna1' => $likeNoAccent, ':qna2' => $likeNoAccent,
        ':qf1' => $like, ':qfa1' => $likeNoAccent,
        ':qf2' => $like, ':qfa2' => $likeNoAccent,
    ]);
    return $stmt->fetchAll();
}
