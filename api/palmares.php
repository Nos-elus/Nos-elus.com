<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

$category = getStringParam('category', 50);
$page = max(1, getIntParam('page', 1));
$perPage = min(100, max(10, getIntParam('limit', 50)));

// ── Mode catégorie unique avec pagination ──
if ($category !== '') {
    $data = cachedResponse('palmares_cat', ['c' => $category, 'p' => $page, 'l' => $perPage], CACHE_TTL_LONG, function() use ($pdo, $category, $page, $perPage) {
        $offset = ($page - 1) * $perPage;

        // Clause d'exclusion des postes bloquants (réutilisée par plusieurs catégories)
        $excluBloquants = "AND e.id NOT IN (
            SELECT DISTINCT m.elu_id FROM mandats m
            WHERE (m.date_fin IS NULL OR m.date_fin >= '2022-01-01')
            AND (m.titre LIKE '%résident%assemblée%' OR m.titre LIKE '%résident%Assemblée%'
                 OR m.titre LIKE '%ice-président%assemblée%' OR m.titre LIKE '%ice-président%Assemblée%'
                 OR m.titre LIKE '%inistre%' OR m.titre LIKE '%arde des Sceaux%'
                 OR m.titre LIKE '%remier%inistre%' OR m.titre LIKE '%résident de la Rép%'
                 OR m.titre LIKE '%ecrétaire d%tat%' OR m.titre LIKE '%Premier ministre%'
                 OR m.titre LIKE '%ice-président%énat%' OR m.titre LIKE '%ice-président%Sénat%'
                 OR m.titre LIKE '%uesteur%')
        )
        AND (e.departement IS NULL OR (e.departement NOT LIKE '97%' AND e.departement NOT LIKE '98%' AND e.departement != 'ZZ'))";

        // Métadonnées des catégories
        $catMeta = [
            'top_cout'               => ['title' => 'Coût pour le contribuable', 'icon' => '💸'],
            'top_carriere'           => ['title' => 'Plus longue carrière', 'icon' => '🕰️'],
            'top_mandats'            => ['title' => 'Plus grand nombre de mandats', 'icon' => '🏅'],
            'top_dynasties'          => ['title' => 'Dynasties politiques', 'icon' => '👑'],
            'top_cumulards'          => ['title' => 'Plus gros cumulards', 'icon' => '🤹'],
            'top_casseroles'         => ['title' => 'Plus grosses casseroles', 'icon' => '⚖️'],
            'top_jeunes'             => ['title' => 'Plus jeunes élus', 'icon' => '🌱'],
            'top_doyens'             => ['title' => 'Doyens', 'icon' => '🎖️'],
            'top_salaires'           => ['title' => 'Plus hauts salaires', 'icon' => '💰'],
            'top_assidus_deputes'    => ['title' => 'Meilleurs députés', 'icon' => '🏆'],
            'top_assidus_senateurs'  => ['title' => 'Meilleurs sénateurs', 'icon' => '🏆'],
            'top_absents_deputes'    => ['title' => 'Pires députés', 'icon' => '😴'],
            'top_absents_senateurs'  => ['title' => 'Pires sénateurs', 'icon' => '😴'],
            'top_assidus_europeens'  => ['title' => 'Meilleurs européens', 'icon' => '🇪🇺'],
            'top_absents_europeens'  => ['title' => 'Pires européens', 'icon' => '🇪🇺'],
        ];

        if (!isset($catMeta[$category])) {
            http_response_code(400);
            return ['error' => 'Catégorie inconnue', 'valid' => array_keys($catMeta)];
        }

        $meta = $catMeta[$category];

        // ── Catégories SQL pures (avec LIMIT/OFFSET natif) ──
        $sqlCategories = [
            'top_carriere' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       MIN(m.date_debut) AS debut_carriere,
                       YEAR(CURDATE()) - YEAR(MIN(m.date_debut)) AS annees_carriere
                FROM elus e JOIN mandats m ON m.elu_id = e.id
                WHERE m.titre NOT LIKE '%andidat%'
                  AND m.date_debut IS NOT NULL AND m.date_debut >= '1900-01-01' AND m.date_debut != '0000-00-00'
                  AND DAY(m.date_debut) > 0
                GROUP BY e.id
                HAVING annees_carriere IS NOT NULL AND annees_carriere > 0
                ORDER BY annees_carriere DESC",
                'count' => "SELECT COUNT(*) FROM (
                    SELECT e.id FROM elus e JOIN mandats m ON m.elu_id = e.id
                    WHERE m.titre NOT LIKE '%andidat%'
                      AND m.date_debut IS NOT NULL AND m.date_debut >= '1900-01-01' AND m.date_debut != '0000-00-00'
                      AND DAY(m.date_debut) > 0
                    GROUP BY e.id
                    HAVING YEAR(CURDATE()) - YEAR(MIN(m.date_debut)) > 0
                ) sub",
            ],
            'top_mandats' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       COUNT(m.id) AS nb_mandats
                FROM elus e JOIN mandats m ON m.elu_id = e.id
                WHERE e.actif = 1
                  AND (m.date_debut IS NULL OR m.date_debut = '0000-00-00' OR m.date_debut >= '1900-01-01')
                  AND (m.titre REGEXP '(député|sénateur|sénatrice|maire|conseill|adjoint|ministre|garde des sceaux|premier ministre|président.*(rép|conseil|sénat|assemblée)|membre.*conseil constitutionnel|secrétaire d)'
                       OR m.titre LIKE 'Député%' OR m.titre LIKE 'Sénateur%' OR m.titre LIKE 'Maire%')
                GROUP BY e.id
                ORDER BY nb_mandats DESC",
                'count' => "SELECT COUNT(DISTINCT e.id)
                FROM elus e JOIN mandats m ON m.elu_id = e.id
                WHERE e.actif = 1
                  AND (m.date_debut IS NULL OR m.date_debut = '0000-00-00' OR m.date_debut >= '1900-01-01')
                  AND (m.titre REGEXP '(député|sénateur|sénatrice|maire|conseill|adjoint|ministre|garde des sceaux|premier ministre|président.*(rép|conseil|sénat|assemblée)|membre.*conseil constitutionnel|secrétaire d)'
                       OR m.titre LIKE 'Député%' OR m.titre LIKE 'Sénateur%' OR m.titre LIKE 'Maire%')",
            ],
            'top_cumulards' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       COUNT(DISTINCT m.titre) AS nb_mandats_actifs
                FROM elus e JOIN mandats m ON m.elu_id = e.id
                WHERE m.date_fin IS NULL
                AND (m.titre REGEXP '(député|sénateur|sénatrice|maire|conseill|adjoint|vice-président.*(conseil|départemental|régional)|président.*(conseil|sénat|assemblée))'
                     OR m.titre LIKE 'Député%' OR m.titre LIKE 'Sénateur%' OR m.titre LIKE 'Maire%')
                AND m.titre NOT LIKE '%conseiller municipal%'
                AND m.titre NOT LIKE '%membre du conseil municipal%'
                AND e.id NOT IN (
                    SELECT elu_id FROM mandats WHERE (titre LIKE '%inistre%' OR titre LIKE '%arde des Sceaux%' OR titre LIKE '%remier%inistre%')
                    AND (date_fin IS NULL OR date_fin >= '2024-01-01')
                )
                GROUP BY e.id
                HAVING nb_mandats_actifs >= 2
                ORDER BY nb_mandats_actifs DESC",
                'count' => "SELECT COUNT(*) FROM (
                    SELECT e.id FROM elus e JOIN mandats m ON m.elu_id = e.id
                    WHERE m.date_fin IS NULL
                    AND (m.titre REGEXP '(député|sénateur|sénatrice|maire|conseill|adjoint|vice-président.*(conseil|départemental|régional)|président.*(conseil|sénat|assemblée))'
                         OR m.titre LIKE 'Député%' OR m.titre LIKE 'Sénateur%' OR m.titre LIKE 'Maire%')
                    AND m.titre NOT LIKE '%conseiller municipal%'
                    AND m.titre NOT LIKE '%membre du conseil municipal%'
                    AND e.id NOT IN (
                        SELECT elu_id FROM mandats WHERE (titre LIKE '%inistre%' OR titre LIKE '%arde des Sceaux%' OR titre LIKE '%remier%inistre%')
                        AND (date_fin IS NULL OR date_fin >= '2024-01-01')
                    )
                    GROUP BY e.id
                    HAVING COUNT(DISTINCT m.titre) >= 2
                ) sub",
            ],
            'top_casseroles' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       COUNT(a.id) AS nb_affaires,
                       SUM(CASE WHEN a.statut = 'condamne' THEN 1 ELSE 0 END) AS nb_condamne,
                       SUM(CASE WHEN a.statut = 'en_cours' THEN 1 ELSE 0 END) AS nb_en_cours
                FROM elus e JOIN affaires a ON a.elu_id = e.id
                WHERE a.statut IN ('condamne', 'en_cours')
                GROUP BY e.id
                ORDER BY nb_condamne DESC, nb_en_cours DESC, nb_affaires DESC",
                'count' => "SELECT COUNT(DISTINCT e.id)
                FROM elus e JOIN affaires a ON a.elu_id = e.id
                WHERE a.statut IN ('condamne', 'en_cours')",
            ],
            'top_jeunes' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       e.date_naissance,
                       TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age
                FROM elus e
                WHERE e.date_naissance IS NOT NULL AND e.date_naissance > '1980-01-01'
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND (m.date_fin IS NULL OR m.date_fin >= '2024-01-01'))
                ORDER BY e.date_naissance DESC",
                'count' => "SELECT COUNT(*)
                FROM elus e
                WHERE e.date_naissance IS NOT NULL AND e.date_naissance > '1980-01-01'
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND (m.date_fin IS NULL OR m.date_fin >= '2024-01-01'))",
            ],
            'top_doyens' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       e.date_naissance,
                       TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age
                FROM elus e
                WHERE e.date_naissance IS NOT NULL AND e.date_naissance > '1900-01-01' AND e.date_naissance < '1960-01-01'
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND (m.date_fin IS NULL OR m.date_fin >= '2024-01-01'))
                ORDER BY e.date_naissance ASC",
                'count' => "SELECT COUNT(*)
                FROM elus e
                WHERE e.date_naissance IS NOT NULL AND e.date_naissance > '1900-01-01' AND e.date_naissance < '1960-01-01'
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND (m.date_fin IS NULL OR m.date_fin >= '2024-01-01'))",
            ],
            'top_assidus_deputes' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       ap.taux_global, ap.taux_votes, ap.taux_commissions, ap.nb_questions
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE ap.taux_global > 0 AND ap.nb_votes >= 50
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%éputé%' OR m.titre LIKE '%député%' OR m.titre LIKE '%Député%') AND m.titre NOT LIKE '%européen%')
                ORDER BY ap.taux_global DESC",
                'count' => "SELECT COUNT(*)
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE ap.taux_global > 0 AND ap.nb_votes >= 50
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%éputé%' OR m.titre LIKE '%député%' OR m.titre LIKE '%Député%') AND m.titre NOT LIKE '%européen%')",
            ],
            'top_assidus_senateurs' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       ap.taux_global, ap.nb_questions
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE ap.nb_questions > 0
                AND (e.fonction LIKE '%énateur%' OR e.fonction LIKE '%Sénateur%')
                ORDER BY ap.nb_questions DESC",
                'count' => "SELECT COUNT(*)
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE ap.nb_questions > 0
                AND (e.fonction LIKE '%énateur%' OR e.fonction LIKE '%Sénateur%')",
            ],
            'top_absents_deputes' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       ap.taux_global, ap.taux_votes, ap.taux_commissions, ap.nb_questions
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE ap.nb_votes >= 50
                $excluBloquants
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%éputé%' OR m.titre LIKE '%député%' OR m.titre LIKE '%Député%') AND m.titre NOT LIKE '%européen%')
                ORDER BY ap.taux_global ASC",
                'count' => "SELECT COUNT(*)
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE ap.nb_votes >= 50
                $excluBloquants
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%éputé%' OR m.titre LIKE '%député%' OR m.titre LIKE '%Député%') AND m.titre NOT LIKE '%européen%')",
            ],
            'top_absents_senateurs' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       COALESCE(ap.taux_global, 0) as taux_global, COALESCE(ap.nb_questions, 0) as nb_questions
                FROM elus e
                LEFT JOIN activite_parlementaire ap ON ap.elu_id = e.id
                WHERE (e.fonction LIKE '%énateur%' OR e.fonction LIKE '%Sénateur%')
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%énateur%' OR m.titre LIKE '%Sénateur%'))
                $excluBloquants
                ORDER BY COALESCE(ap.nb_questions, 0) ASC, e.nom ASC",
                'count' => "SELECT COUNT(*)
                FROM elus e
                LEFT JOIN activite_parlementaire ap ON ap.elu_id = e.id
                WHERE (e.fonction LIKE '%énateur%' OR e.fonction LIKE '%Sénateur%')
                AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%énateur%' OR m.titre LIKE '%Sénateur%'))
                $excluBloquants",
            ],
            'top_assidus_europeens' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       ap.taux_global, ap.taux_votes, ap.nb_votes, ap.total_scrutins
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE (e.fonction LIKE 'Député européen%' OR e.fonction LIKE 'Députée européenne%' OR e.fonction LIKE 'député européen%')
                AND ap.taux_global > 0 AND ap.nb_votes >= 100
                ORDER BY ap.taux_global DESC",
                'count' => "SELECT COUNT(*)
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE (e.fonction LIKE 'Député européen%' OR e.fonction LIKE 'Députée européenne%' OR e.fonction LIKE 'député européen%')
                AND ap.taux_global > 0 AND ap.nb_votes >= 100",
            ],
            'top_absents_europeens' => [
                'select' => "SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
                       ap.taux_global, ap.taux_votes, ap.nb_votes, ap.total_scrutins
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE (e.fonction LIKE 'Député européen%' OR e.fonction LIKE 'Députée européenne%' OR e.fonction LIKE 'député européen%')
                AND ap.nb_votes >= 100
                AND e.id NOT IN (
                    SELECT m.elu_id FROM mandats m WHERE m.date_fin IS NULL
                    AND (m.titre LIKE '%ice-président%Parlement européen%' OR m.titre LIKE '%uesteur%Parlement européen%')
                )
                ORDER BY ap.taux_global ASC",
                'count' => "SELECT COUNT(*)
                FROM activite_parlementaire ap
                JOIN elus e ON e.id = ap.elu_id
                WHERE (e.fonction LIKE 'Député européen%' OR e.fonction LIKE 'Députée européenne%' OR e.fonction LIKE 'député européen%')
                AND ap.nb_votes >= 100
                AND e.id NOT IN (
                    SELECT m.elu_id FROM mandats m WHERE m.date_fin IS NULL
                    AND (m.titre LIKE '%ice-président%Parlement européen%' OR m.titre LIKE '%uesteur%Parlement européen%')
                )",
            ],
        ];

        // ── Catégories SQL : pagination native ──
        if (isset($sqlCategories[$category])) {
            $sql = $sqlCategories[$category];
            $total = (int)$pdo->query($sql['count'])->fetchColumn();
            $rows = $pdo->query($sql['select'] . " LIMIT $perPage OFFSET $offset")->fetchAll();
            return [
                'category' => $category, 'title' => $meta['title'], 'icon' => $meta['icon'],
                'data' => $rows, 'total' => $total, 'page' => $page,
                'pages' => (int)ceil($total / $perPage), 'per_page' => $perPage,
            ];
        }

        // ── Catégories calculées en PHP (top_cout, top_salaires, top_dynasties) ──
        if ($category === 'top_cout') {
            require_once __DIR__ . '/calcul-cout.php';
            $stmt = $pdo->query("
                SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur, e.salaire_brut,
                       (SELECT JSON_ARRAYAGG(JSON_OBJECT('titre', m.titre, 'date_debut', m.date_debut, 'date_fin', m.date_fin))
                        FROM mandats m WHERE m.elu_id = e.id
                        AND m.titre NOT LIKE '%andidat%'
                        AND m.date_debut IS NOT NULL AND m.date_debut >= '1900-01-01' AND m.date_debut != '0000-00-00'
                       ) AS mandats_json
                FROM elus e
                WHERE (SELECT COUNT(*) FROM mandats m WHERE m.elu_id = e.id
                       AND m.titre NOT LIKE '%andidat%'
                       AND m.date_debut IS NOT NULL AND m.date_debut >= '1900-01-01') >= 3
                ORDER BY e.id
            ");
            $couts = [];
            foreach ($stmt as $row) {
                $mandats = json_decode($row['mandats_json'] ?? '[]', true) ?: [];
                $result = calculerCoutCarriere($mandats, !empty($row['salaire_brut']) ? (float)$row['salaire_brut'] : null);
                $total = $result['total'];
                if ($total > 100000) {
                    $couts[] = [
                        'id' => $row['id'], 'nom' => $row['nom'], 'prenom' => $row['prenom'],
                        'slug' => $row['slug'], 'photo_url' => $row['photo_url'], 'parti' => $row['parti'],
                        'fonction' => $row['fonction'], 'emoji' => $row['emoji'], 'couleur' => $row['couleur'],
                        'cout_total' => round($total),
                    ];
                }
            }
            usort($couts, fn($a, $b) => $b['cout_total'] - $a['cout_total']);
            $totalCount = count($couts);
            return [
                'category' => $category, 'title' => $meta['title'], 'icon' => $meta['icon'],
                'data' => array_values(array_slice($couts, $offset, $perPage)),
                'total' => $totalCount, 'page' => $page,
                'pages' => (int)ceil($totalCount / $perPage), 'per_page' => $perPage,
            ];
        }

        if ($category === 'top_salaires') {
            require_once __DIR__ . '/calcul-cout.php';
            $stmtSal = $pdo->query("
                SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur, e.salaire_brut,
                       (SELECT JSON_ARRAYAGG(JSON_OBJECT('titre', m.titre, 'date_debut', m.date_debut, 'date_fin', m.date_fin))
                        FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND m.date_debut >= '2017-01-01'
                       ) AS mandats_json
                FROM elus e
                WHERE e.actif = 1
                  AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND m.date_debut >= '2017-01-01')
                ORDER BY e.id
            ");

            $grilleSal = [
                'president_republique'=>16039,'premier_ministre'=>16038,'ministre'=>10692,'garde_des_sceaux'=>10692,'secretaire_etat'=>10692,
                'depute'=>7637,'senateur'=>7637,'depute_europeen'=>11255,
                'president_region'=>5809,'vice_president_region'=>3500,'conseiller_regional'=>2013,
                'president_departement'=>4407,'vice_president_departement'=>3000,'conseiller_departemental'=>1672,
                'maire'=>2500,'adjoint_maire'=>1000,'conseiller_municipal'=>0,
                'conseiller_arrondissement'=>228,'conseiller_metropolitain'=>246,'conseiller_communautaire'=>246,
                'conseiller_paris'=>2510,'conseiller_fde'=>810,'membre_conseil_constitutionnel'=>13872,
            ];
            $normSal = function($titre) {
                $t = mb_strtolower(trim($titre), 'UTF-8');
                if (preg_match('/(directeur|pdg|président-directeur|candidat|avocat|médecin|professeur|chapelle|organiste|compositeur|journaliste|ingénieur|architecte|chef d)/', $t)) {
                    if (!str_contains($t, 'maire') && !str_contains($t, 'député') && !str_contains($t, 'conseill') && !str_contains($t, 'sénateur') && !str_contains($t, 'ministre') && !str_contains($t, 'président') && !str_contains($t, 'adjoint'))
                        return '';
                }
                if (str_contains($t, 'métropol') || str_contains($t, 'metropol')) return 'conseiller_metropolitain';
                if (str_contains($t, 'conseiller communautaire') || str_contains($t, 'délégué communautaire')) return 'conseiller_communautaire';
                if (str_contains($t, 'président de la rép')) return 'president_republique';
                if (str_contains($t, 'premier ministre')) return 'premier_ministre';
                if (str_contains($t, 'garde des sceaux')) return 'garde_des_sceaux';
                if (str_contains($t, "secrétaire d'état") || str_contains($t, "secrétaire d'état")) return 'secretaire_etat';
                if (str_contains($t, 'ministre')) return 'ministre';
                if (str_contains($t, 'député européen') || str_contains($t, 'parlement européen')) return 'depute_europeen';
                if (str_contains($t, 'européen') && !str_contains($t, 'métropol')) return 'depute_europeen';
                if (str_contains($t, 'président') && str_contains($t, 'sénat')) return 'senateur';
                if (str_contains($t, 'président') && str_contains($t, 'assemblée nationale')) return 'depute';
                if (str_contains($t, 'vice-président') && str_contains($t, 'assemblée nationale')) return 'depute';
                if (str_contains($t, 'député')) return 'depute';
                if (str_contains($t, 'sénateur') || str_contains($t, 'sénatrice')) return 'senateur';
                if (str_contains($t, 'conseil constitutionnel')) return 'membre_conseil_constitutionnel';
                if (str_contains($t, 'commission')) return '';
                if ((str_contains($t, 'vice-président') || str_contains($t, 'vice-présidente')) && (str_contains($t, 'conseil régional') || str_contains($t, 'région'))) return 'vice_president_region';
                if ((str_contains($t, 'vice-président') || str_contains($t, 'vice-présidente')) && (str_contains($t, 'conseil départemental') || str_contains($t, 'département'))) return 'vice_president_departement';
                if ((str_contains($t, 'président') || str_contains($t, 'présidente')) && (str_contains($t, 'conseil régional') || str_contains($t, 'région'))) return 'president_region';
                if ((str_contains($t, 'président') || str_contains($t, 'présidente')) && (str_contains($t, 'conseil départemental') || str_contains($t, 'département'))) return 'president_departement';
                if (str_contains($t, 'conseiller') && str_contains($t, 'paris')) return 'conseiller_paris';
                if (str_contains($t, 'arrondissement')) return 'conseiller_arrondissement';
                if (str_contains($t, 'conseiller régional') || str_contains($t, 'conseillère régional')) return 'conseiller_regional';
                if (str_contains($t, 'conseiller départemental') || str_contains($t, 'conseiller général')) return 'conseiller_departemental';
                if (str_contains($t, 'fde') || str_contains($t, "français de l'étranger")) return 'conseiller_fde';
                if (str_contains($t, 'adjoint')) return 'adjoint_maire';
                if (str_contains($t, 'maire')) return 'maire';
                if (str_contains($t, 'conseiller municipal') || str_contains($t, 'membre du conseil municipal')) return 'conseiller_municipal';
                return '';
            };
            $executifsSal = ['president_republique','premier_ministre','ministre','garde_des_sceaux','secretaire_etat'];
            $parlementairesSal = ['depute','senateur','depute_europeen','membre_conseil_constitutionnel'];
            $PLAFOND = 8897.93;

            $salaires = [];
            foreach ($stmtSal as $row) {
                $mandatsJ = json_decode($row['mandats_json'] ?? '[]', true) ?: [];
                if (empty($mandatsJ)) continue;
                $types = [];
                foreach ($mandatsJ as $m) {
                    $type = $normSal($m['titre'] ?? '');
                    if ($type && isset($grilleSal[$type]) && !isset($types[$type])) {
                        $montant = $grilleSal[$type];
                        if ($type === 'maire' && !empty($row['salaire_brut'])) $montant = (int)$row['salaire_brut'];
                        $types[$type] = $montant;
                    }
                }
                if (empty($types)) continue;
                $isExec = false;
                foreach ($types as $t => $v) { if (in_array($t, $executifsSal)) { $isExec = true; break; } }
                if ($isExec) {
                    $maxExec = 0;
                    foreach ($types as $t => $v) { if (in_array($t, $executifsSal) && $v > $maxExec) $maxExec = $v; }
                    $totalSal = $maxExec;
                } else {
                    if (isset($types['maire'])) unset($types['conseiller_municipal']);
                    $brutLocal = 0; $brutParl = 0;
                    foreach ($types as $t => $v) {
                        if (in_array($t, $parlementairesSal)) $brutParl += $v;
                        else $brutLocal += $v;
                    }
                    $hasNat = isset($types['depute']) || isset($types['senateur']);
                    $plafLocal = $hasNat ? 2965.98 : $PLAFOND;
                    $totalSal = min($brutLocal, $plafLocal) + $brutParl;
                }
                if ($totalSal > 1000) {
                    $salaires[] = [
                        'id' => $row['id'], 'nom' => $row['nom'], 'prenom' => $row['prenom'],
                        'slug' => $row['slug'], 'photo_url' => $row['photo_url'], 'parti' => $row['parti'],
                        'fonction' => $row['fonction'], 'emoji' => $row['emoji'], 'couleur' => $row['couleur'],
                        'salaire_mensuel' => round($totalSal),
                    ];
                }
            }
            usort($salaires, fn($a, $b) => $b['salaire_mensuel'] - $a['salaire_mensuel']);
            $totalCount = count($salaires);
            return [
                'category' => $category, 'title' => $meta['title'], 'icon' => $meta['icon'],
                'data' => array_values(array_slice($salaires, $offset, $perPage)),
                'total' => $totalCount, 'page' => $page,
                'pages' => (int)ceil($totalCount / $perPage), 'per_page' => $perPage,
            ];
        }

        if ($category === 'top_dynasties') {
            $stmt = $pdo->query("
                SELECT nom, COUNT(*) AS nb_membres,
                       GROUP_CONCAT(
                           CONCAT_WS('|', id, prenom, COALESCE(slug,''), COALESCE(fonction,''), COALESCE(parti,''), COALESCE(photo_url,''), COALESCE(departement,''))
                           ORDER BY prenom SEPARATOR ';;'
                       ) AS membres_raw
                FROM elus
                WHERE nom != '' AND LENGTH(nom) > 2
                GROUP BY nom
                HAVING nb_membres >= 3
                ORDER BY nb_membres DESC
            ");
            $familles = [];
            foreach ($stmt as $f) {
                $membres = [];
                foreach (explode(';;', $f['membres_raw']) as $raw) {
                    $parts = explode('|', $raw);
                    $membres[] = [
                        'id' => (int)($parts[0] ?? 0), 'prenom' => $parts[1] ?? '',
                        'slug' => $parts[2] ?? '', 'fonction' => $parts[3] ?? '',
                        'parti' => $parts[4] ?? '', 'photo_url' => $parts[5] ?? '',
                        'departement' => $parts[6] ?? '',
                    ];
                }
                $familles[] = [
                    'nom' => $f['nom'], 'nb_membres' => (int)$f['nb_membres'], 'membres' => $membres,
                ];
            }
            $totalCount = count($familles);
            return [
                'category' => $category, 'title' => $meta['title'], 'icon' => $meta['icon'],
                'data' => array_values(array_slice($familles, $offset, $perPage)),
                'total' => $totalCount, 'page' => $page,
                'pages' => (int)ceil($totalCount / $perPage), 'per_page' => $perPage,
            ];
        }

        // Fallback (ne devrait pas arriver vu le check plus haut)
        return ['error' => 'Catégorie non gérée'];
    });
    jsonResponse($data);
    exit;
}

// ── Mode par défaut : tous les classements (10 chacun) ──
$data = cachedResponse('palmares', [], CACHE_TTL_LONG, function() use ($pdo) {
    $palmares = [];

    // 1. Coût total pour le contribuable — calcul expert mois par mois
    require_once __DIR__ . '/calcul-cout.php';

    // Pré-filtrer : seuls les élus avec 3+ mandats non-candidat peuvent être dans le top coût
    $stmt = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur, e.salaire_brut,
               (SELECT JSON_ARRAYAGG(JSON_OBJECT('titre', m.titre, 'date_debut', m.date_debut, 'date_fin', m.date_fin))
                FROM mandats m WHERE m.elu_id = e.id
                AND m.titre NOT LIKE '%andidat%'
                AND m.date_debut IS NOT NULL AND m.date_debut >= '1900-01-01' AND m.date_debut != '0000-00-00'
               ) AS mandats_json
        FROM elus e
        WHERE (SELECT COUNT(*) FROM mandats m WHERE m.elu_id = e.id
               AND m.titre NOT LIKE '%andidat%'
               AND m.date_debut IS NOT NULL AND m.date_debut >= '1900-01-01') >= 3
        ORDER BY e.id
    ");

    $couts = [];
    foreach ($stmt as $row) {
        $mandats = json_decode($row['mandats_json'] ?? '[]', true) ?: [];
        $result = calculerCoutCarriere($mandats, !empty($row['salaire_brut']) ? (float)$row['salaire_brut'] : null);
        $total = $result['total'];
        if ($total > 100000) {
            $couts[] = [
                'id' => $row['id'], 'nom' => $row['nom'], 'prenom' => $row['prenom'],
                'slug' => $row['slug'], 'photo_url' => $row['photo_url'], 'parti' => $row['parti'],
                'fonction' => $row['fonction'], 'emoji' => $row['emoji'], 'couleur' => $row['couleur'],
                'cout_total' => round($total),
            ];
        }
    }
    usort($couts, fn($a, $b) => $b['cout_total'] - $a['cout_total']);
    $palmares['top_cout'] = array_slice($couts, 0, 10);

    // 2. Plus longue carrière
    $stmt2 = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               MIN(m.date_debut) AS debut_carriere,
               YEAR(CURDATE()) - YEAR(MIN(m.date_debut)) AS annees_carriere
        FROM elus e JOIN mandats m ON m.elu_id = e.id
        WHERE m.titre NOT LIKE '%andidat%'
          AND m.date_debut IS NOT NULL AND m.date_debut >= '1900-01-01' AND m.date_debut != '0000-00-00'
          AND DAY(m.date_debut) > 0
        GROUP BY e.id
        HAVING annees_carriere IS NOT NULL AND annees_carriere > 0
        ORDER BY annees_carriere DESC
        LIMIT 10
    ");
    $palmares['top_carriere'] = $stmt2->fetchAll();

    // 3. Plus grand nombre de mandats (vrais mandats rémunérés uniquement, actifs)
    $stmt3 = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               COUNT(m.id) AS nb_mandats
        FROM elus e JOIN mandats m ON m.elu_id = e.id
        WHERE e.actif = 1
          AND (m.date_debut IS NULL OR m.date_debut = '0000-00-00' OR m.date_debut >= '1900-01-01')
          AND (m.titre REGEXP '(député|sénateur|sénatrice|maire|conseill|adjoint|ministre|garde des sceaux|premier ministre|président.*(rép|conseil|sénat|assemblée)|membre.*conseil constitutionnel|secrétaire d)'
               OR m.titre LIKE 'Député%' OR m.titre LIKE 'Sénateur%' OR m.titre LIKE 'Maire%')
        GROUP BY e.id
        ORDER BY nb_mandats DESC
        LIMIT 10
    ");
    $palmares['top_mandats'] = $stmt3->fetchAll();

    // 4. Dynasties politiques (familles avec le plus d'élus)
    $stmt4 = $pdo->query("
        SELECT nom, COUNT(*) AS nb_membres,
               GROUP_CONCAT(
                   CONCAT_WS('|', id, prenom, COALESCE(slug,''), COALESCE(fonction,''), COALESCE(parti,''), COALESCE(photo_url,''), COALESCE(departement,''))
                   ORDER BY prenom SEPARATOR ';;'
               ) AS membres_raw
        FROM elus
        WHERE nom != '' AND LENGTH(nom) > 2
        GROUP BY nom
        HAVING nb_membres >= 3
        ORDER BY nb_membres DESC
        LIMIT 10
    ");
    $familles = [];
    foreach ($stmt4 as $f) {
        $membres = [];
        foreach (explode(';;', $f['membres_raw']) as $raw) {
            $parts = explode('|', $raw);
            $membres[] = [
                'id' => (int)($parts[0] ?? 0),
                'prenom' => $parts[1] ?? '',
                'slug' => $parts[2] ?? '',
                'fonction' => $parts[3] ?? '',
                'parti' => $parts[4] ?? '',
                'photo_url' => $parts[5] ?? '',
                'departement' => $parts[6] ?? '',
            ];
        }
        $familles[] = [
            'nom' => $f['nom'],
            'nb_membres' => (int)$f['nb_membres'],
            'membres' => $membres,
        ];
    }
    // 5. Plus gros cumulards — mandats RÉMUNÉRÉS simultanés (hors ministres car indemnité exclusive)
    $stmt5 = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               COUNT(DISTINCT m.titre) AS nb_mandats_actifs
        FROM elus e JOIN mandats m ON m.elu_id = e.id
        WHERE m.date_fin IS NULL
        AND (m.titre REGEXP '(député|sénateur|sénatrice|maire|conseill|adjoint|vice-président.*(conseil|départemental|régional)|président.*(conseil|sénat|assemblée))'
             OR m.titre LIKE 'Député%' OR m.titre LIKE 'Sénateur%' OR m.titre LIKE 'Maire%')
        AND m.titre NOT LIKE '%conseiller municipal%'
        AND m.titre NOT LIKE '%membre du conseil municipal%'
        AND e.id NOT IN (
            SELECT elu_id FROM mandats WHERE (titre LIKE '%inistre%' OR titre LIKE '%arde des Sceaux%' OR titre LIKE '%remier%inistre%')
            AND (date_fin IS NULL OR date_fin >= '2024-01-01')
        )
        GROUP BY e.id
        HAVING nb_mandats_actifs >= 3
        ORDER BY nb_mandats_actifs DESC
        LIMIT 10
    ");
    $palmares['top_cumulards'] = $stmt5->fetchAll();

    // 6. Plus gros casseroles — condamnés en priorité
    $stmt6 = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               COUNT(a.id) AS nb_affaires,
               SUM(CASE WHEN a.statut = 'condamne' THEN 1 ELSE 0 END) AS nb_condamne,
               SUM(CASE WHEN a.statut = 'en_cours' THEN 1 ELSE 0 END) AS nb_en_cours
        FROM elus e JOIN affaires a ON a.elu_id = e.id
        WHERE a.statut IN ('condamne', 'en_cours')
        GROUP BY e.id
        ORDER BY nb_condamne DESC, nb_en_cours DESC, nb_affaires DESC
        LIMIT 10
    ");
    $palmares['top_casseroles'] = $stmt6->fetchAll();

    // 7. Les plus jeunes élus en exercice
    $stmt7 = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               e.date_naissance,
               TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age
        FROM elus e
        WHERE e.date_naissance IS NOT NULL AND e.date_naissance > '1980-01-01'
        AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND (m.date_fin IS NULL OR m.date_fin >= '2024-01-01'))
        ORDER BY e.date_naissance DESC
        LIMIT 10
    ");
    $palmares['top_jeunes'] = $stmt7->fetchAll();

    // 8. Les doyens (plus vieux en exercice — actif=1 obligatoire)
    $stmt8 = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               e.date_naissance,
               TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age
        FROM elus e
        WHERE e.actif = 1
        AND e.date_naissance IS NOT NULL AND e.date_naissance > '1900-01-01' AND e.date_naissance < '1960-01-01'
        AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND (m.date_fin IS NULL OR m.date_fin >= '2024-01-01'))
        ORDER BY e.date_naissance ASC
        LIMIT 10
    ");
    $palmares['top_doyens'] = $stmt8->fetchAll();

    // 9. Plus hauts salaires actuels (indemnité publique la plus élevée en exercice)
    // On utilise la même logique que elu.php : detectType + grille + incompatibilités
    require_once __DIR__ . '/calcul-cout.php';
    // Réutilise la grille et la normalisation de calcul-cout.php (déjà require_once plus haut)
    $stmtSal = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur, e.salaire_brut,
               (SELECT JSON_ARRAYAGG(JSON_OBJECT('titre', m.titre, 'date_debut', m.date_debut, 'date_fin', m.date_fin))
                FROM mandats m WHERE m.elu_id = e.id
                AND m.date_fin IS NULL
               ) AS mandats_json
        FROM elus e
        WHERE EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL)
        ORDER BY e.id
    ");

    $grilleSal = [
        'president_republique'=>16039,'premier_ministre'=>16038,'ministre'=>10692,'garde_des_sceaux'=>10692,'secretaire_etat'=>10692,
        'depute'=>7637,'senateur'=>7637,'depute_europeen'=>11255,
        'president_region'=>5809,'vice_president_region'=>3500,'conseiller_regional'=>2013,
        'president_departement'=>4407,'vice_president_departement'=>3000,'conseiller_departemental'=>1672,
        'maire'=>2500,'adjoint_maire'=>1000,'conseiller_municipal'=>0,
        'conseiller_arrondissement'=>228,'conseiller_metropolitain'=>246,'conseiller_communautaire'=>246,
        'conseiller_paris'=>2510,'conseiller_fde'=>810,'membre_conseil_constitutionnel'=>13872,
    ];

    // Normalisation titre — réutilise la logique de calcul-cout.php via un wrapper
    $normSal = function($titre) {
        // Même logique que calcul-cout.php $normaliser
        $t = mb_strtolower(trim($titre), 'UTF-8');
        // Rejeter titres non-électoraux
        if (preg_match('/(directeur|pdg|président-directeur|candidat|avocat|médecin|professeur|chapelle|organiste|compositeur|journaliste|ingénieur|architecte|chef d)/', $t)) {
            if (!str_contains($t, 'maire') && !str_contains($t, 'député') && !str_contains($t, 'conseill') && !str_contains($t, 'sénateur') && !str_contains($t, 'ministre') && !str_contains($t, 'président') && !str_contains($t, 'adjoint'))
                return '';
        }
        if (str_contains($t, 'métropol') || str_contains($t, 'metropol')) return 'conseiller_metropolitain';
        if (str_contains($t, 'conseiller communautaire') || str_contains($t, 'délégué communautaire')) return 'conseiller_communautaire';
        if (str_contains($t, 'président de la rép')) return 'president_republique';
        if (str_contains($t, 'premier ministre')) return 'premier_ministre';
        if (str_contains($t, 'garde des sceaux')) return 'garde_des_sceaux';
        if (str_contains($t, "secrétaire d'état") || str_contains($t, "secrétaire d'état")) return 'secretaire_etat';
        if (str_contains($t, 'ministre')) return 'ministre';
        if (str_contains($t, 'député européen') || str_contains($t, 'parlement européen')) return 'depute_europeen';
        if (str_contains($t, 'européen') && !str_contains($t, 'métropol')) return 'depute_europeen';
        if (str_contains($t, 'président') && str_contains($t, 'sénat')) return 'senateur';
        if (str_contains($t, 'président') && str_contains($t, 'assemblée nationale')) return 'depute';
        if (str_contains($t, 'vice-président') && str_contains($t, 'assemblée nationale')) return 'depute';
        if (str_contains($t, 'député')) return 'depute';
        if (str_contains($t, 'sénateur') || str_contains($t, 'sénatrice')) return 'senateur';
        if (str_contains($t, 'conseil constitutionnel')) return 'membre_conseil_constitutionnel';
        if (str_contains($t, 'commission')) return '';
        if ((str_contains($t, 'vice-président') || str_contains($t, 'vice-présidente')) && (str_contains($t, 'conseil régional') || str_contains($t, 'région'))) return 'vice_president_region';
        if ((str_contains($t, 'vice-président') || str_contains($t, 'vice-présidente')) && (str_contains($t, 'conseil départemental') || str_contains($t, 'département'))) return 'vice_president_departement';
        if ((str_contains($t, 'président') || str_contains($t, 'présidente')) && (str_contains($t, 'conseil régional') || str_contains($t, 'région'))) return 'president_region';
        if ((str_contains($t, 'président') || str_contains($t, 'présidente')) && (str_contains($t, 'conseil départemental') || str_contains($t, 'département'))) return 'president_departement';
        if (str_contains($t, 'conseiller') && str_contains($t, 'paris')) return 'conseiller_paris';
        if (str_contains($t, 'arrondissement')) return 'conseiller_arrondissement';
        if (str_contains($t, 'conseiller régional') || str_contains($t, 'conseillère régional')) return 'conseiller_regional';
        if (str_contains($t, 'conseiller départemental') || str_contains($t, 'conseiller général')) return 'conseiller_departemental';
        if (str_contains($t, 'fde') || str_contains($t, "français de l'étranger")) return 'conseiller_fde';
        if (str_contains($t, 'adjoint')) return 'adjoint_maire';
        if (str_contains($t, 'maire')) return 'maire';
        if (str_contains($t, 'conseiller municipal') || str_contains($t, 'membre du conseil municipal')) return 'conseiller_municipal';
        return '';
    };

    $executifsSal = ['president_republique','premier_ministre','ministre','garde_des_sceaux','secretaire_etat'];
    $parlementairesSal = ['depute','senateur','depute_europeen','membre_conseil_constitutionnel'];
    $PLAFOND = 8897.93;

    $salaires = [];
    foreach ($stmtSal as $row) {
        $mandatsJ = json_decode($row['mandats_json'] ?? '[]', true) ?: [];
        if (empty($mandatsJ)) continue;

        // Détecter les types d'indemnités actives
        $types = [];
        foreach ($mandatsJ as $m) {
            $type = $normSal($m['titre'] ?? '');
            if ($type && isset($grilleSal[$type]) && !isset($types[$type])) {
                $montant = $grilleSal[$type];
                if ($type === 'maire' && !empty($row['salaire_brut'])) $montant = (int)$row['salaire_brut'];
                $types[$type] = $montant;
            }
        }
        if (empty($types)) continue;

        // Incompatibilité ministre
        $isExec = false;
        foreach ($types as $t => $v) { if (in_array($t, $executifsSal)) { $isExec = true; break; } }
        if ($isExec) {
            $maxExec = 0;
            foreach ($types as $t => $v) { if (in_array($t, $executifsSal) && $v > $maxExec) $maxExec = $v; }
            $totalSal = $maxExec;
        } else {
            // Maire inclut cons municipal
            if (isset($types['maire'])) unset($types['conseiller_municipal']);
            // Séparer parlementaires/locaux
            $brutLocal = 0; $brutParl = 0;
            foreach ($types as $t => $v) {
                if (in_array($t, $parlementairesSal)) $brutParl += $v;
                else $brutLocal += $v;
            }
            // Plafond local = 2 965,98€ si parlementaire national, 8 897,93€ sinon
            $hasNat = isset($types['depute']) || isset($types['senateur']);
            $plafLocal = $hasNat ? 2965.98 : $PLAFOND;
            $totalSal = min($brutLocal, $plafLocal) + $brutParl;
        }

        if ($totalSal > 1000) {
            $salaires[] = [
                'id' => $row['id'], 'nom' => $row['nom'], 'prenom' => $row['prenom'],
                'slug' => $row['slug'], 'photo_url' => $row['photo_url'], 'parti' => $row['parti'],
                'fonction' => $row['fonction'], 'emoji' => $row['emoji'], 'couleur' => $row['couleur'],
                'salaire_mensuel' => round($totalSal),
            ];
        }
    }
    usort($salaires, fn($a, $b) => $b['salaire_mensuel'] - $a['salaire_mensuel']);
    $palmares['top_salaires'] = array_slice($salaires, 0, 10);

    // ── Classements par type de parlementaire ──
    // Clause d'exclusion des postes bloquants
    $excluBloquants = "AND e.id NOT IN (
        SELECT DISTINCT m.elu_id FROM mandats m
        WHERE (m.date_fin IS NULL OR m.date_fin >= '2022-01-01')
        AND (m.titre LIKE '%résident%assemblée%' OR m.titre LIKE '%résident%Assemblée%'
             OR m.titre LIKE '%ice-président%assemblée%' OR m.titre LIKE '%ice-président%Assemblée%'
             OR m.titre LIKE '%inistre%' OR m.titre LIKE '%arde des Sceaux%'
             OR m.titre LIKE '%remier%inistre%' OR m.titre LIKE '%résident de la Rép%'
             OR m.titre LIKE '%ecrétaire d%tat%' OR m.titre LIKE '%Premier ministre%'
             OR m.titre LIKE '%ice-président%énat%' OR m.titre LIKE '%ice-président%Sénat%'
             OR m.titre LIKE '%uesteur%')
    )
    AND (e.departement IS NULL OR (e.departement NOT LIKE '97%' AND e.departement NOT LIKE '98%' AND e.departement != 'ZZ'))";

    // 10a. Meilleurs députés
    $palmares['top_assidus_deputes'] = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               ap.taux_global, ap.taux_votes, ap.taux_commissions, ap.nb_questions
        FROM activite_parlementaire ap
        JOIN elus e ON e.id = ap.elu_id
        WHERE ap.taux_global > 0 AND ap.nb_votes >= 50
        AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%éputé%' OR m.titre LIKE '%député%' OR m.titre LIKE '%Député%') AND m.titre NOT LIKE '%européen%')
        ORDER BY ap.taux_global DESC LIMIT 10
    ")->fetchAll();

    // 10b. Meilleurs sénateurs
    $palmares['top_assidus_senateurs'] = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               ap.taux_global, ap.nb_questions
        FROM activite_parlementaire ap
        JOIN elus e ON e.id = ap.elu_id
        WHERE ap.nb_questions > 0
        AND (e.fonction LIKE '%énateur%' OR e.fonction LIKE '%Sénateur%')
        ORDER BY ap.nb_questions DESC LIMIT 10
    ")->fetchAll();

    // 11a. Pires députés
    $palmares['top_absents_deputes'] = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               ap.taux_global, ap.taux_votes, ap.taux_commissions, ap.nb_questions
        FROM activite_parlementaire ap
        JOIN elus e ON e.id = ap.elu_id
        WHERE ap.nb_votes >= 50
        $excluBloquants
        AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%éputé%' OR m.titre LIKE '%député%' OR m.titre LIKE '%Député%') AND m.titre NOT LIKE '%européen%')
        ORDER BY ap.taux_global ASC LIMIT 10
    ")->fetchAll();

    // 11b. Pires sénateurs (le moins de questions)
    $palmares['top_absents_senateurs'] = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               COALESCE(ap.taux_global, 0) as taux_global, COALESCE(ap.nb_questions, 0) as nb_questions
        FROM elus e
        LEFT JOIN activite_parlementaire ap ON ap.elu_id = e.id
        WHERE (e.fonction LIKE '%énateur%' OR e.fonction LIKE '%Sénateur%')
        AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = e.id AND m.date_fin IS NULL AND (m.titre LIKE '%énateur%' OR m.titre LIKE '%Sénateur%'))
        $excluBloquants
        ORDER BY COALESCE(ap.nb_questions, 0) ASC, e.nom ASC
        LIMIT 10
    ")->fetchAll();

    // 12. Meilleurs européens
    $palmares['top_assidus_europeens'] = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               ap.taux_global, ap.taux_votes, ap.nb_votes, ap.total_scrutins
        FROM activite_parlementaire ap
        JOIN elus e ON e.id = ap.elu_id
        WHERE (e.fonction LIKE 'Député européen%' OR e.fonction LIKE 'Députée européenne%' OR e.fonction LIKE 'député européen%')
        AND ap.taux_global > 0 AND ap.nb_votes >= 100
        ORDER BY ap.taux_global DESC LIMIT 10
    ")->fetchAll();

    // 13. Pires européens
    $palmares['top_absents_europeens'] = $pdo->query("
        SELECT e.id, e.nom, e.prenom, e.slug, e.photo_url, e.parti, e.fonction, e.emoji, e.couleur,
               ap.taux_global, ap.taux_votes, ap.nb_votes, ap.total_scrutins
        FROM activite_parlementaire ap
        JOIN elus e ON e.id = ap.elu_id
        WHERE (e.fonction LIKE 'Député européen%' OR e.fonction LIKE 'Députée européenne%' OR e.fonction LIKE 'député européen%')
        AND ap.nb_votes >= 100
        AND e.id NOT IN (
            SELECT m.elu_id FROM mandats m WHERE m.date_fin IS NULL
            AND (m.titre LIKE '%ice-président%Parlement européen%' OR m.titre LIKE '%uesteur%Parlement européen%')
        )
        ORDER BY ap.taux_global ASC LIMIT 10
    ")->fetchAll();

    // 14. Podium : élus qui apparaissent dans le plus de classements
    $allLists = ['top_cout','top_carriere','top_mandats','top_cumulards','top_casseroles','top_jeunes','top_doyens','top_salaires','top_assidus_deputes','top_absents_deputes','top_assidus_europeens','top_absents_europeens','top_assidus_senateurs','top_absents_senateurs'];
    $catLabels = [
        'top_cout' => 'Coût contribuable', 'top_carriere' => 'Plus longue carrière',
        'top_mandats' => 'Plus de mandats', 'top_cumulards' => 'Cumulards',
        'top_casseroles' => 'Casseroles', 'top_jeunes' => 'Plus jeunes', 'top_doyens' => 'Doyens',
        'top_salaires' => 'Plus hauts salaires',
        'top_assidus_deputes' => 'Meilleurs députés', 'top_absents_deputes' => 'Pires députés',
        'top_assidus_europeens' => 'Meilleurs européens', 'top_absents_europeens' => 'Pires européens',
        'top_assidus_senateurs' => 'Meilleurs sénateurs', 'top_absents_senateurs' => 'Pires sénateurs',
    ];
    $appearances = [];
    foreach ($allLists as $key) {
        foreach ($palmares[$key] ?? [] as $e) {
            $id = (int)$e['id'];
            if (!isset($appearances[$id])) {
                $appearances[$id] = [
                    'id' => $id, 'nom' => $e['nom'], 'prenom' => $e['prenom'],
                    'slug' => $e['slug'], 'photo_url' => $e['photo_url'],
                    'parti' => $e['parti'], 'fonction' => $e['fonction'],
                    'emoji' => $e['emoji'] ?? '', 'couleur' => $e['couleur'] ?? '',
                    'categories' => [], 'nb_categories' => 0,
                ];
            }
            $appearances[$id]['categories'][] = $catLabels[$key];
        }
    }
    foreach ($appearances as &$a) {
        $a['categories'] = array_unique($a['categories']);
        $a['nb_categories'] = count($a['categories']);
    }
    usort($appearances, fn($a, $b) => $b['nb_categories'] - $a['nb_categories']);
    $palmares['podium'] = array_slice(array_filter($appearances, fn($a) => $a['nb_categories'] >= 2), 0, 5);

    return $palmares;
});

jsonResponse($data);
