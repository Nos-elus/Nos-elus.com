<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

// ── Paramètres de filtrage ──
$age_min     = getIntParam('age_min', 0);
$age_max     = getIntParam('age_max', 0);
$sexe        = getStringParam('sexe', 10);      // H, F, ou vide
$region      = getStringParam('region', 100);
$dept        = getStringParam('dept', 5);
$parti       = getStringParam('parti', 100);
$mandats_min = isset($_GET['mandats_min']) ? (int)$_GET['mandats_min'] : -1;
$mandats_max = isset($_GET['mandats_max']) ? (int)$_GET['mandats_max'] : -1;
$casseroles  = isset($_GET['casseroles_max']) ? (int)$_GET['casseroles_max'] : -1; // -1 = pas de filtre
$photo       = getStringParam('photo', 10);        // oui, non, ou vide
$poste       = getStringParam('poste', 50);
$salaire_min = getIntParam('salaire_min', 0);
$salaire_max = getIntParam('salaire_max', 0);
$fortune_min = getIntParam('fortune_min', 0);
$fortune_max = getIntParam('fortune_max', 0);
$sort        = getStringParam('sort', 20) ?: 'score';
$page        = max(1, getIntParam('page', 1));
$limit       = min(60, max(10, getIntParam('limit', 30)));
$offset      = ($page - 1) * $limit;

// ── Construction requête ──
// Filtre par défaut : élus en exercice. Ajout ?include_anciens=1 pour aussi inclure les anciens.
$where  = isset($_GET['include_anciens']) ? [] : ['actif = 1'];
$binds  = [];
$having = [];

// Sexe
if ($sexe === 'F') {
    $where[] = "sexe = 'F'";
} elseif ($sexe === 'H') {
    $where[] = "sexe = 'M'";
}

// Age (exclure 0000-00-00 et NULL)
if ($age_min > 0) {
    $where[] = "date_naissance IS NOT NULL AND date_naissance > '1900-01-01' AND date_naissance <= DATE_SUB(CURDATE(), INTERVAL :age_min YEAR)";
    $binds[':age_min'] = $age_min;
}
if ($age_max > 0) {
    $where[] = "date_naissance IS NOT NULL AND date_naissance > '1900-01-01' AND date_naissance >= DATE_SUB(CURDATE(), INTERVAL :age_max YEAR)";
    $binds[':age_max'] = $age_max;
}

// Région / Département
if ($region) {
    $where[] = "region = :region";
    $binds[':region'] = $region;
}
if ($dept) {
    $where[] = "departement = :dept";
    $binds[':dept'] = $dept;
}

// Parti — avec agrégation par famille politique
// Quand l'utilisateur choisit une famille (Extrême gauche, Gauche, Centre, Droite, etc.),
// on inclut tous les partis équivalents. Sinon on filtre sur la valeur exacte.
$famillesMap = [
    'Extrême gauche' => [
        'Extrême gauche',
        'La France insoumise', 'LFI',
        'Parti communiste français', 'Parti communiste',
        'Nouveau Parti Anticapitaliste', 'NPA',
        'Lutte Ouvrière',
        'Gauche démocrate et républicaine',
    ],
    'Gauche' => [
        'Parti socialiste', 'PS',
        'Divers gauche', 'DVG',
        'Union de la gauche',
        'Parti radical de gauche', 'PRG',
        'Place publique',
        'Générations',
    ],
    'Écologistes' => [
        'Les Écologistes', 'EELV', 'EE-LV', 'LVEC',
        'Europe Écologie',
        'Écologiste', 'Écologiste et Social',
    ],
    'Centre' => [
        'Divers centre', 'DVC',
        'Union centriste', 'Union du centre',
        'Mouvement Démocrate', 'MoDem', 'MODEM',
        'Les Démocrates', 'Les Centristes',
        'LIOT', 'RDSE',
    ],
    'Centre-droit' => [
        'Renaissance', 'LREM', 'En Marche',
        'Horizons', 'HOR',
        'Ensemble',
        'Les Indépendants',
        'Union des Démocrates et Indépendants', 'UDI',
    ],
    'Droite' => [
        'Divers droite', 'DVD',
        'Les Républicains', 'LR', 'UMP', 'RPR',
        'Droite Républicaine',
        'Union de la droite', 'UDR', 'LUDR',
        'Union pour la République',
        'Debout la France',
        'Résistons',
    ],
    'Extrême droite' => [
        'Extrême droite', 'Divers extrême droite', 'LEXD',
        'Rassemblement national', 'RN',
        'Reconquête', 'Reconquête!',
        'Les Patriotes',
    ],
];

if ($parti) {
    if (isset($famillesMap[$parti])) {
        $partis = $famillesMap[$parti];
        $phs = [];
        foreach ($partis as $i => $p) {
            $ph = ":pf$i";
            $phs[] = $ph;
            $binds[$ph] = $p;
        }
        $where[] = "parti IN (" . implode(',', $phs) . ")";
    } else {
        $where[] = "parti = :parti";
        $binds[':parti'] = $parti;
    }
}

// Photo — exclure les placeholders (data:image, pixel, url vide, "aucune")
if ($photo === 'oui') {
    $where[] = "photo_url IS NOT NULL
                AND photo_url != ''
                AND photo_url NOT LIKE 'data:%'
                AND photo_url NOT LIKE '%placeholder%'
                AND photo_url NOT LIKE '%no-photo%'
                AND photo_url NOT LIKE '%aucune%'
                AND LENGTH(photo_url) > 10";
} elseif ($photo === 'non') {
    $where[] = "(photo_url IS NULL
                OR photo_url = ''
                OR photo_url LIKE 'data:%'
                OR photo_url LIKE '%placeholder%'
                OR photo_url LIKE '%no-photo%'
                OR photo_url LIKE '%aucune%'
                OR LENGTH(photo_url) <= 10)";
}

// Poste
if ($poste) {
    // Pour les postes mappés au type_mandat → utiliser type_mandat (matching précis).
    // Accepte les anciens libellés (Député, Européen) ET les nouveaux (Député AN, Eurodéputé) pour rétro-compat.
    $typeMandatMap = [
        'Député AN'  => 'depute',
        'Député'     => 'depute',
        'Sénateur'   => 'senateur',
        'Eurodéputé' => 'europeen',
        'Européen'   => 'europeen',
        'Maire'      => 'maire',
        'Ministre'   => 'ministre',
    ];
    if (isset($typeMandatMap[$poste])) {
        $where[] = "type_mandat = :poste";
        $binds[':poste'] = $typeMandatMap[$poste];
    } else {
        $posteMap = [
            'Conseiller régional'    => '%conseill%région%',
            'Conseiller départemental' => '%conseill%départem%',
            'Adjoint'                => 'Adjoint%',
        ];
        $pattern = $posteMap[$poste] ?? "%$poste%";
        $where[] = "fonction LIKE :poste";
        $binds[':poste'] = $pattern;
    }
}

// Salaire = indemnités publiques uniquement (grille légale, pas les revenus privés)
if ($salaire_min > 0 || ($salaire_max > 0 && $salaire_max < 20000)) {
    $salaireSub = "(SELECT COALESCE(SUM(
            CASE
                WHEN m2.titre LIKE '%résident de la Rép%' THEN 16039
                WHEN m2.titre LIKE '%remier%inistre%' THEN 16038
                WHEN m2.titre LIKE '%inistre%' OR m2.titre LIKE '%arde des Sceaux%' OR m2.titre LIKE '%ecrétaire d%tat%' THEN 10692
                WHEN m2.titre LIKE '%éputé%européen%' OR m2.titre LIKE '%député européen%' THEN 11255
                WHEN m2.titre REGEXP '(éputé|député|Député)' THEN 7637
                WHEN m2.titre REGEXP '(énateur|Sénateur)' THEN 7637
                WHEN m2.titre LIKE '%président%conseil%régional%' OR m2.titre LIKE '%président%région%' THEN 5809
                WHEN m2.titre LIKE '%président%conseil%départemental%' THEN 4407
                WHEN m2.titre LIKE '%ice-président%conseil%régional%' THEN 3500
                WHEN m2.titre LIKE '%ice-président%conseil%départemental%' THEN 3000
                WHEN m2.titre LIKE 'Maire%' OR m2.titre LIKE 'maire%' THEN COALESCE(NULLIF(e.salaire_brut, 0), 2500)
                WHEN m2.titre LIKE '%conseiller%régional%' THEN 2013
                WHEN m2.titre LIKE '%conseiller%départemental%' THEN 1672
                WHEN m2.titre LIKE '%adjoint%' THEN 1000
                ELSE 0
            END
        ), 0) FROM mandats m2 WHERE m2.elu_id = e.id AND m2.date_fin IS NULL)";
    if ($salaire_min > 0) {
        $where[] = "$salaireSub >= :sal_min";
        $binds[':sal_min'] = $salaire_min;
    }
    if ($salaire_max > 0 && $salaire_max < 20000) {
        $where[] = "$salaireSub <= :sal_max AND $salaireSub > 0";
        $binds[':sal_max'] = $salaire_max;
    }
}

// Fortune (via patrimoine_detail JSON)
$fortuneExpr = "GREATEST(CAST(COALESCE(JSON_EXTRACT(patrimoine_detail, '$.fortune_estimee'),0) AS UNSIGNED), CAST(COALESCE(JSON_EXTRACT(patrimoine_detail, '$.total'),0) AS UNSIGNED))";
if ($fortune_min > 0 || ($fortune_max > 0 && $fortune_max < 25000000)) {
    $where[] = "patrimoine_detail IS NOT NULL AND patrimoine_detail != ''";
    if ($fortune_min > 0) {
        $where[] = "$fortuneExpr >= :fort_min";
        $binds[':fort_min'] = $fortune_min;
    }
    if ($fortune_max > 0 && $fortune_max < 25000000) {
        $where[] = "$fortuneExpr <= :fort_max";
        $binds[':fort_max'] = $fortune_max;
    }
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Sous-requêtes pour mandats et casseroles
$mandatsJoin = "(SELECT elu_id, COUNT(*) AS nb_mandats FROM mandats GROUP BY elu_id) AS mc ON mc.elu_id = e.id";
$affairesJoin = "(SELECT elu_id, COUNT(*) AS nb_affaires FROM affaires GROUP BY elu_id) AS ac ON ac.elu_id = e.id";

// Having (post-agrégation)
$havingParts = [];
if ($mandats_min >= 0) {
    $havingParts[] = "nb_mandats >= :mandats_min";
    $binds[':mandats_min'] = $mandats_min;
}
if ($mandats_max >= 0) {
    $havingParts[] = "nb_mandats <= :mandats_max";
    $binds[':mandats_max'] = $mandats_max;
}
if ($casseroles >= 0) {
    $havingParts[] = "nb_casseroles <= :casseroles_max";
    $binds[':casseroles_max'] = $casseroles;
}
$havingClause = $havingParts ? 'HAVING ' . implode(' AND ', $havingParts) : '';

// Sort
$allowedSorts = [
    'score' => '(e.score_transparence + e.score_assiduite + e.score_coherence + e.score_bilan) DESC',
    'casseroles' => 'nb_casseroles DESC',
    'mandats' => 'nb_mandats DESC',
    'age_asc' => 'e.date_naissance DESC',
    'age_desc' => 'e.date_naissance ASC',
    'salaire' => 'e.salaire_brut DESC',
    'consultations' => 'e.nb_consultations DESC',
    'nom'   => 'e.nom ASC',
];
$orderBy = $allowedSorts[$sort] ?? $allowedSorts['score'];

// ── Count total ──
$countSql = "
    SELECT COUNT(*) FROM (
        SELECT e.id,
            COALESCE(mc.nb_mandats, 0) AS nb_mandats,
            COALESCE(ac.nb_affaires, 0) AS nb_casseroles
        FROM elus e
        LEFT JOIN $mandatsJoin
        LEFT JOIN $affairesJoin
        $whereClause
        GROUP BY e.id
        $havingClause
    ) AS sub
";
$stmt = $pdo->prepare($countSql);
$stmt->execute($binds);
$total = (int) $stmt->fetchColumn();

// ── Fetch page ──
$sql = "
    SELECT e.id, e.nom, e.prenom, e.slug, e.parti, e.fonction, e.emoji, e.couleur,
           e.photo_url, e.date_naissance, e.region, e.departement, e.nb_consultations,
           e.score_transparence, e.score_assiduite, e.score_coherence, e.score_bilan,
           COALESCE(mc.nb_mandats, 0) AS nb_mandats,
           COALESCE(ac.nb_affaires, 0) AS nb_casseroles,
           (e.score_transparence + e.score_assiduite + e.score_coherence + e.score_bilan) AS score_total,
           CASE WHEN e.date_naissance IS NOT NULL AND e.date_naissance > '1900-01-01'
                THEN TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE())
                ELSE NULL END AS age
    FROM elus e
    LEFT JOIN $mandatsJoin
    LEFT JOIN $affairesJoin
    $whereClause
    GROUP BY e.id
    $havingClause
    ORDER BY $orderBy
    LIMIT :_limit OFFSET :_offset
";
$stmt = $pdo->prepare($sql);
foreach ($binds as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':_limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':_offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$results = $stmt->fetchAll();

// ── Options disponibles (pour les selects dynamiques) ──
$regions = $pdo->query("SELECT DISTINCT region FROM elus WHERE region IS NOT NULL AND region != '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

// Familles politiques en tête + partis individuels non regroupés
$famillesOrder = ['Extrême gauche', 'Gauche', 'Écologistes', 'Centre', 'Centre-droit', 'Droite', 'Extrême droite'];

// Partis individuels à masquer car déjà regroupés dans une famille
$regroupedPartis = [];
foreach ($famillesMap as $family) {
    $regroupedPartis = array_merge($regroupedPartis, $family);
}
$regroupedPartis = array_unique($regroupedPartis);

$rawPartis = $pdo->query("SELECT DISTINCT parti FROM elus WHERE parti IS NOT NULL AND parti != '' ORDER BY parti")->fetchAll(PDO::FETCH_COLUMN);
$autresPartis = array_values(array_diff($rawPartis, $regroupedPartis, $famillesOrder));

// Ordre final : familles d'abord, puis "autres" (sans étiquette, régionalistes, etc.)
$partis = array_merge($famillesOrder, $autresPartis);

jsonResponse([
    'data'    => $results,
    'total'   => $total,
    'page'    => $page,
    'pages'   => (int) ceil($total / $limit),
    'options' => [
        'regions' => $regions,
        'partis'  => $partis,
    ],
]);
