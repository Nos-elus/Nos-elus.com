<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

$page = max(1, getIntParam('page', 1));
$limit = min(100, max(10, getIntParam('limit', 50)));
$offset = ($page - 1) * $limit;
$parti = getStringParam('parti', 100);
$dept = getStringParam('dept', 5);
$sort = getStringParam('sort', 20) ?: 'nom';

$allowedSorts = ['nom', 'nb_consultations', 'parti', 'created_at', 'importance'];
if (!in_array($sort, $allowedSorts)) $sort = 'nom';
$order = $sort === 'nb_consultations' ? 'DESC' : 'ASC';

$params = ['page' => $page, 'limit' => $limit, 'parti' => $parti, 'dept' => $dept, 'sort' => $sort];

$data = cachedResponse('elus_list', $params, CACHE_TTL_SHORT, function() use ($pdo, $limit, $offset, $parti, $dept, $sort, $order) {
    $where = [];
    $binds = [];

    if ($parti) {
        $where[] = 'parti = :parti';
        $binds[':parti'] = $parti;
    }
    if ($dept) {
        $where[] = 'departement = :dept';
        $binds[':dept'] = $dept;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Tri par importance de mandat (président → PM → ministre → député → sénateur → MEP → local)
    $orderClause = match($sort) {
        'importance' => "FIELD(type_mandat,
            'president','premier_ministre','ministre',
            'depute','senateur','depute_europeen',
            'president_region','president_departement','maire'
        ) ASC, nom ASC",
        'nb_consultations' => 'nb_consultations DESC',
        default => "$sort ASC",
    };

    // Count total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM elus $whereClause");
    $stmt->execute($binds);
    $total = (int)$stmt->fetchColumn();

    // Fetch page
    $stmt = $pdo->prepare("
        SELECT id, nom, prenom, parti, fonction, emoji, photo_url, slug, nb_consultations,
               score_transparence, score_assiduite, score_coherence, score_bilan
        FROM elus
        $whereClause
        ORDER BY $orderClause
        LIMIT :_limit OFFSET :_offset
    ");
    $binds[':_limit'] = $limit;
    $binds[':_offset'] = $offset;
    $stmt->execute($binds);

    return [
        'data' => $stmt->fetchAll(),
        'total' => $total,
        'page' => (int)ceil($offset / $limit) + 1,
        'pages' => (int)ceil($total / $limit),
        'limit' => $limit,
    ];
});

jsonResponse($data);
