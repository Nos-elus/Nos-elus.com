<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

// Accepter id ou slug
$id = getIntParam('id');
$slug = getStringParam('slug', 200);

if (!$id && !$slug) {
    jsonResponse(['error' => 'Paramètre id ou slug requis'], 400);
}

// Résoudre le slug en id si nécessaire
if (!$id && $slug) {
    $stmt = $pdo->prepare('SELECT id FROM elus WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    $id = (int) $stmt->fetchColumn();
    if (!$id) {
        jsonResponse(['error' => 'Élu non trouvé'], 404);
    }
}

$data = cachedResponse('elu', ['id' => $id], CACHE_TTL_MEDIUM, function() use ($pdo, $id) {
    // Requête unique avec sous-requêtes JSON (7 SELECT → 1)
    $stmt = $pdo->prepare("
        SELECT e.id, e.nom, e.prenom, e.slug, e.parti, e.fonction, e.emoji, e.couleur, e.photo_url,
               e.date_naissance, e.lieu_naissance, e.bio, e.alias, e.patrimoine_info, e.patrimoine_detail,
               e.score_integrite, e.score_transparence, e.score_assiduite, e.score_coherence, e.score_bilan,
               e.population, e.salaire_brut, e.url_hatvp, e.profession,
               COALESCE(e.hatvp_non_declarant, 0) AS hatvp_non_declarant,
               e.email, e.telephone, e.adresse, e.url_fiche,
               COALESCE(s.nb_consultations, e.nb_consultations) AS nb_consultations,
               e.actif, e.departement, e.region, e.type_mandat,
               COALESCE(e.is_candidat, 0) AS is_candidat, e.election_cible,
               e.created_at, e.updated_at,
               (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                   'id', m.id, 'titre', m.titre, 'date_debut', m.date_debut,
                   'date_fin', m.date_fin, 'institution', m.institution,
                   'nb_mandats_poste', m.nb_mandats_poste
               )) FROM mandats m WHERE m.elu_id = e.id) AS mandats_json,
               (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                   'id', a.id, 'titre', a.titre, 'description', a.description,
                   'statut', a.statut, 'date_debut', a.date_debut, 'date_fin', a.date_fin,
                   'gravite', a.gravite, 'source_url', a.source_url, 'source_nom', a.source_nom
               )) FROM affaires a WHERE a.elu_id = e.id AND a.statut != 'clean') AS affaires_json,
               (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                   'id', af.id, 'personne_liee_id', af.personne_liee_id,
                   'nom_personne', af.nom_personne, 'type_lien', af.type_lien, 'emoji', af.emoji
               )) FROM affiliations af WHERE af.elu_id = e.id) AS affiliations_json,
               NULL AS votes_json
        FROM elus e
        LEFT JOIN elu_stats s ON s.elu_id = e.id
        WHERE e.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $elu = $stmt->fetch();

    if (!$elu) return null;

    // Décoder les colonnes JSON
    $elu['alias'] = json_decode($elu['alias'] ?? '[]', true) ?: [];
    $elu['patrimoine_detail'] = json_decode($elu['patrimoine_detail'] ?? 'null', true);
    $elu['mandats'] = json_decode($elu['mandats_json'] ?? '[]', true) ?: [];
    $elu['affaires'] = json_decode($elu['affaires_json'] ?? '[]', true) ?: [];
    $elu['affiliations'] = json_decode($elu['affiliations_json'] ?? '[]', true) ?: [];
    unset($elu['mandats_json'], $elu['affaires_json'], $elu['affiliations_json'], $elu['votes_json']);

    // Votes — requête séparée avec LIMIT 500 (JSON_ARRAYAGG tronqué si trop de votes)
    $stmtVotes = $pdo->prepare("SELECT id, sujet, position, date_vote, scrutin_id FROM votes WHERE elu_id = :id ORDER BY date_vote DESC LIMIT 500");
    $stmtVotes->execute([':id' => $elu['id']]);
    $elu['votes'] = $stmtVotes->fetchAll();

    // Activité parlementaire — uniquement si l'élu est actuellement parlementaire (mandat actif)
    // Sinon les stats (taux_global, nb_questions historiques) n'ont aucun sens
    $hasActiveParlMandat = false;
    foreach ($elu['mandats'] as $m) {
        if (!empty($m['date_fin'])) continue;
        $t = mb_strtolower($m['titre'] ?? '', 'UTF-8');
        if (str_contains($t, 'député') || str_contains($t, 'sénateur') || str_contains($t, 'sénatrice') || str_contains($t, 'parlement européen')) {
            $hasActiveParlMandat = true;
            break;
        }
    }
    $stmtActiv = $pdo->prepare("SELECT * FROM activite_parlementaire WHERE elu_id = :id LIMIT 1");
    $stmtActiv->execute([':id' => $elu['id']]);
    $activite = $stmtActiv->fetch();
    // Ne pas retourner activite_parlementaire si données creuses (cas sénateurs : on ne tracke pas
    // leurs votes, le taux 15% est trompeur quand nb_votes=0 ET nb_reunions_convoque=0).
    $hasRealData = $activite && (
        (int)($activite['nb_votes'] ?? 0) > 0
        || (int)($activite['nb_reunions_convoque'] ?? 0) > 0
        || (int)($activite['total_scrutins'] ?? 0) > 0
    );
    if ($activite && $hasActiveParlMandat && $hasRealData) {
        // Détecter si l'élu a/a eu un poste qui l'empêche de voter normalement
        $postesBloquants = [];
        foreach ($elu['mandats'] as $m) {
            $t = mb_strtolower($m['titre'] ?? '', 'UTF-8');
            if (str_contains($t, 'président') && (str_contains($t, 'assemblée') || str_contains($t, 'sénat'))) {
                $postesBloquants[] = $m['titre'];
            } elseif (str_contains($t, 'vice-président') && (str_contains($t, 'assemblée') || str_contains($t, 'sénat'))) {
                $postesBloquants[] = $m['titre'];
            } elseif (str_contains($t, 'questeur')) {
                $postesBloquants[] = $m['titre'];
            } elseif (str_contains($t, 'vice-président') && str_contains($t, 'parlement européen')) {
                $postesBloquants[] = $m['titre'];
            } elseif (str_contains($t, 'ministre') || str_contains($t, 'garde des sceaux') || str_contains($t, 'premier ministre') || str_contains($t, 'secrétaire d')) {
                $postesBloquants[] = $m['titre'];
            }
        }

        $elu['activite_parlementaire'] = [
            'taux_votes' => (float) $activite['taux_votes'],
            'nb_votes' => (int) $activite['nb_votes'],
            'total_scrutins' => (int) $activite['total_scrutins'],
            'taux_commissions' => (float) $activite['taux_commissions'],
            'nb_reunions_present' => (int) $activite['nb_reunions_present'],
            'nb_reunions_convoque' => (int) $activite['nb_reunions_convoque'],
            'nb_questions' => (int) $activite['nb_questions'],
            'taux_global' => (float) $activite['taux_global'],
            'postes_bloquants' => array_unique($postesBloquants),
            'outre_mer' => in_array($elu['departement'] ?? '', ['971','972','973','974','975','976','977','978','986','987','988','ZZ']),
        ];

        // Classement parmi les élus du même type (député vs député, etc.)
        $typeMandat = null;
        foreach ($elu['mandats'] as $m) {
            $t = mb_strtolower($m['titre'] ?? '', 'UTF-8');
            if (str_contains($t, 'député') && !str_contains($t, 'européen')) { $typeMandat = 'depute'; break; }
            if (str_contains($t, 'sénateur') || str_contains($t, 'sénatrice')) { $typeMandat = 'senateur'; break; }
            if (str_contains($t, 'député européen') || str_contains($t, 'européen')) { $typeMandat = 'europeen'; break; }
        }
        // Classement au sein du même type d'élu
        $typeEluMap = ['depute' => 'Député AN', 'senateur' => 'Sénateur', 'europeen' => 'Député européen'];
        if ($typeMandat && isset($typeEluMap[$typeMandat])) {
            // Détecter si la colonne type_elu existe (ajoutée par cron-taux-presence.php)
            $hasTypeElu = false;
            try { $pdo->query("SELECT type_elu FROM activite_parlementaire LIMIT 0"); $hasTypeElu = true; } catch (PDOException $e) {}

            if ($hasTypeElu) {
                $stmtRang = $pdo->prepare("SELECT COUNT(*) + 1 FROM activite_parlementaire WHERE taux_global > :taux AND elu_id != :eid AND type_elu = :type");
                $stmtRang->execute([':taux' => $activite['taux_global'], ':eid' => $elu['id'], ':type' => $typeMandat]);
                $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM activite_parlementaire WHERE taux_global > 0 AND type_elu = :type");
                $stmtTotal->execute([':type' => $typeMandat]);
            } else {
                $stmtRang = $pdo->prepare("SELECT COUNT(*) + 1 FROM activite_parlementaire WHERE taux_global > :taux AND elu_id != :eid");
                $stmtRang->execute([':taux' => $activite['taux_global'], ':eid' => $elu['id']]);
                $stmtTotal = $pdo->query("SELECT COUNT(*) FROM activite_parlementaire WHERE taux_global > 0");
            }
            $elu['activite_parlementaire']['rang'] = (int) $stmtRang->fetchColumn();
            $elu['activite_parlementaire']['total_deputes'] = (int) $stmtTotal->fetchColumn();
            $elu['activite_parlementaire']['type_mandat'] = $typeEluMap[$typeMandat];
        }
    }

    // Supprimer les salaires manuels du patrimoine_detail — tout est calculé dynamiquement via les indemnités
    if (!empty($elu['patrimoine_detail'])) {
        unset($elu['patrimoine_detail']['salaires']);
        unset($elu['patrimoine_detail']['salaire_cumul_mensuel']);
    }

    // --- Indemnités basées sur les mandats ACTIFS (date_fin IS NULL) ---
    // Seuls les montants fixes nationaux sont affichés exactement.
    // Les indemnités locales dépendent de la population → fourchette.
    // Plafond cumul mandats : 8 897,93 €/mois brut (CGCT art. L2123-20).

    // Identifier les mandats en cours
    $mandatsActifs = array_filter($elu['mandats'], fn($m) => empty($m['date_fin']));
    $elu['ancien_elu'] = empty($mandatsActifs);
    $typeMandat = strtolower(trim($elu['type_mandat'] ?? ''));
    $fonction = mb_strtolower(trim($elu['fonction'] ?? ''));

    // Ancien élu : pas d'indemnités actuelles, on garde seulement l'historique
    if (!$elu['ancien_elu']) :

    // Grille indemnités
    $grille = [
        'depute'       => ['brut' => 7637, 'net' => 5953, 'label' => 'Indemnité parlementaire', 'source' => 'Assemblée nationale — barème 2025'],
        'senateur'     => ['brut' => 7637, 'net' => 5676, 'label' => 'Indemnité parlementaire', 'source' => 'Sénat — barème 2025'],
        'europeen'     => ['brut' => 11255, 'net' => 8773, 'label' => 'Indemnité parlementaire européenne', 'source' => 'Parlement européen — barème 2025'],
        'premier_ministre' => ['brut' => 16038, 'net' => 11200, 'label' => 'Traitement du Premier ministre', 'source' => 'Décret n°82-979 — barème 2025'],
        'ministre'     => ['brut' => 10692, 'net' => 7500, 'label' => 'Traitement ministériel', 'source' => 'Décret traitement membres du gouvernement — 2025'],
        'president'    => ['brut' => 16039, 'net' => 11200, 'label' => 'Traitement présidentiel', 'source' => 'Décret n°2012-983 — barème 2025'],
        'pres_region'  => ['brut_min' => 5837, 'brut_max' => 8172, 'label' => 'Indemnité président de région', 'detail' => 'Varie selon la population de la région', 'source' => 'CGCT art. L4135-16'],
        'pres_dept'    => ['brut_min' => 4180, 'brut_max' => 5960, 'label' => 'Indemnité président de département', 'detail' => 'Varie selon la population du département', 'source' => 'CGCT art. L3123-17'],
        'maire'        => ['brut_min' => 1178, 'brut_max' => 5960, 'label' => 'Indemnité de maire', 'detail' => 'De 1 178€ (<500 hab.) à 5 960€ (>100k hab.) — Paris 9 720€', 'source' => 'CGCT art. L2123-23 — barème 2025'],
        'cons_region'  => ['brut_min' => 2013, 'brut_max' => 2818, 'label' => 'Indemnité conseiller régional', 'detail' => 'De 2 013€ (<1M hab.) à 2 818€ (>1M hab.)', 'source' => 'CGCT art. L4135-16'],
        'cons_dept'    => ['brut_min' => 1672, 'brut_max' => 2672, 'label' => 'Indemnité conseiller départemental', 'detail' => 'De 1 672€ (<250k hab.) à 2 672€ (>1M hab.)', 'source' => 'CGCT art. L3123-16'],
        'adjoint'      => ['brut_min' => 297, 'brut_max' => 2805, 'label' => 'Indemnité d\'adjoint au maire', 'detail' => 'Varie selon la population de la commune', 'source' => 'CGCT art. L2123-24'],
        'vp_region'    => ['brut_min' => 2818, 'brut_max' => 5726, 'label' => 'Indemnité vice-président de région', 'detail' => 'Varie selon la population de la région', 'source' => 'CGCT art. L4135-17'],
        'vp_dept'      => ['brut_min' => 2508, 'brut_max' => 4172, 'label' => 'Indemnité vice-président de département', 'detail' => 'Varie selon la population du département', 'source' => 'CGCT art. L3123-17'],
        'cons_arrondt' => ['brut' => 228, 'label' => 'Indemnité conseiller d\'arrondissement', 'source' => 'CGCT art. L2511-33 — barème PLM'],
        'cons_metro'   => ['brut_min' => 0, 'brut_max' => 246, 'label' => 'Indemnité conseiller communautaire', 'detail' => 'Max 6% IB 1027 — souvent 0€ (enveloppe réservée au président/VP)', 'source' => 'CGCT art. L5211-12 — loi NOTRe 2016'],
        'cons_fde'     => ['brut' => 810, 'label' => 'Indemnité conseiller des Français de l\'étranger', 'source' => 'Loi n°2013-659 art. 13'],
        'cons_paris'   => ['brut' => 2510, 'label' => 'Indemnité conseiller de Paris', 'source' => 'CGCT art. L2511-33 — Conseil de Paris'],
        'cons_municipal'=> ['brut_min' => 0, 'brut_max' => 297, 'label' => 'Indemnité conseiller municipal', 'detail' => 'Communes < 100k hab. : aucune indemnité sauf si prévue', 'source' => 'CGCT art. L2123-24-1'],
        'cons_constit' => ['brut' => 13872, 'net' => 12520, 'label' => 'Traitement membre du Conseil constitutionnel', 'source' => 'Ordonnance n°58-1067 — Décret n°59-1292'],
        'pres_constit' => ['brut' => 13872, 'net' => 9700, 'label' => 'Traitement membre du Conseil constitutionnel', 'source' => 'Ordonnance n°58-1067'],
        'cese'         => ['brut' => 4126, 'label' => 'Indemnité membre du CESE', 'source' => 'Décret n°84-558 art. 28'],
        'pres_cese'    => ['brut' => 9940, 'label' => 'Traitement président du CESE', 'source' => 'Ordonnance n°58-1360 — aligné PM'],
        'defenseur'    => ['brut' => 13500, 'label' => 'Traitement Défenseur des droits', 'source' => 'Loi organique n°2011-333 art. 11'],
        'pres_an'      => ['brut' => 14480, 'net' => 11000, 'label' => 'Indemnité président de l\'Assemblée nationale', 'source' => 'Ordonnance n°58-1210 — IPB + IF 100%'],
        'pres_senat'   => ['brut' => 14480, 'net' => 11000, 'label' => 'Indemnité président du Sénat', 'source' => 'Ordonnance n°58-1210 — IPB + IF 100%'],
        'pres_epci'    => ['brut_min' => 1763, 'brut_max' => 5809, 'label' => 'Indemnité président d\'EPCI/métropole', 'detail' => 'Varie selon la population', 'source' => 'CGCT art. L5211-12'],
        'vp_epci'      => ['brut_min' => 801, 'brut_max' => 3245, 'label' => 'Indemnité vice-président d\'EPCI', 'detail' => 'Varie selon la population', 'source' => 'CGCT art. L5211-12 III'],
        'cons_territorial' => ['brut_min' => 1603, 'brut_max' => 1603, 'label' => 'Indemnité conseiller territorial', 'detail' => 'Corse, Guyane, Martinique, SPM', 'source' => 'CGCT art. L7124-3 / L7224-3'],
        'pres_territorial' => ['brut' => 5809, 'label' => 'Indemnité président de collectivité territoriale', 'source' => 'CGCT — collectivités à statut particulier'],
        'maire_arrondt' => ['brut_min' => 2404, 'brut_max' => 3604, 'label' => 'Indemnité maire d\'arrondissement', 'detail' => 'Paris : 3 604€, Lyon/Marseille : 2 404€', 'source' => 'CGCT art. L2511-34'],
        'pres_commission_dept' => ['brut' => 2003, 'label' => 'Indemnité président de commission départementale', 'source' => 'CGCT art. L3123-17 III'],
        'pres_commission_region' => ['brut' => 2684, 'label' => 'Indemnité président de commission régionale', 'source' => 'CGCT art. L4135-17 III'],
    ];

    // Détecter le type de poste depuis un titre de mandat
    // Noms de régions pour matcher "Président Hauts-de-France" etc.
    $regions = ['hauts-de-france','île-de-france','ile-de-france','occitanie','paca','provence',
        'nouvelle-aquitaine','auvergne','grand est','bretagne','normandie','bourgogne',
        'centre-val de loire','pays de la loire','corse','outre-mer','guadeloupe','martinique',
        'guyane','réunion','mayotte'];

    $detectType = function(string $titre) use ($regions) {
        $t = mb_strtolower($titre);
        if (str_contains($t, 'président de la rép')) return 'president';
        if (str_contains($t, 'ministre') && !str_contains($t, 'ancien') && !str_contains($t, 'premier ministre')) return 'ministre';
        if (str_contains($t, 'premier ministre')) return 'premier_ministre';
        // Métropole AVANT européen (sinon "Métropole européenne de Lille" matche europeen)
        if (str_contains($t, 'métropol') || str_contains($t, 'metropol')) return 'cons_metro';
        if (str_contains($t, 'européen') || str_contains($t, 'européenne')) return 'europeen';
        if (str_contains($t, 'député') || str_contains($t, 'deputé')) return 'depute';
        if (str_contains($t, 'sénateur') || str_contains($t, 'sénatrice')) return 'senateur';
        // Président de région (incluant "Président Hauts-de-France" etc.)
        // VP AVANT président (sinon "Vice-président du conseil régional" matche pres_region)
        // Exclure "commission" (Parlement européen : "président de la Commission du développement régional" ≠ président de région)
        if (str_contains($t, 'commission')) {} // skip — pas un mandat rémunéré local
        elseif (str_contains($t, 'vice-président') && (str_contains($t, 'régional') || str_contains($t, 'région') || str_contains($t, 'conseil régional'))) return 'vp_region';
        elseif (str_contains($t, 'vice-président') && (str_contains($t, 'départemental') || str_contains($t, 'département') || str_contains($t, 'conseil départemental'))) return 'vp_dept';
        elseif (str_contains($t, 'président') && (str_contains($t, 'conseil régional') || str_contains($t, 'région'))) return 'pres_region';
        elseif (str_contains($t, 'président') && (str_contains($t, 'conseil départemental') || str_contains($t, 'département'))) return 'pres_dept';
        // Matcher les noms de régions directement
        if (str_contains($t, 'président') || str_contains($t, 'présidente')) {
            foreach ($regions as $r) { if (str_contains($t, $r)) return 'pres_region'; }
        }
        if (str_contains($t, 'maire') && !str_contains($t, 'adjoint')) return 'maire';
        if (str_contains($t, 'adjoint')) return 'adjoint';
        if (str_contains($t, 'conseiller régional') || str_contains($t, 'conseillère régional')) return 'cons_region';
        if (str_contains($t, 'conseiller départemental') || str_contains($t, 'conseillère départemental') || str_contains($t, 'conseiller général')) return 'cons_dept';
        if (str_contains($t, 'secrétaire d\'état') || str_contains($t, "secrétaire d'état")) return 'ministre';
        // Conseil constitutionnel
        if (str_contains($t, 'président') && str_contains($t, 'constitutionnel')) return 'pres_constit';
        if (str_contains($t, 'conseil constitutionnel') || str_contains($t, 'constitutionnel')) return 'cons_constit';
        // CESE
        if (str_contains($t, 'président') && str_contains($t, 'cese')) return 'pres_cese';
        if (str_contains($t, 'cese') || str_contains($t, 'conseil économique') || str_contains($t, 'conseil economique')) return 'cese';
        // Défenseur des droits
        if (str_contains($t, 'défenseur des droits')) return 'defenseur';
        // Présidents de chambre
        if (str_contains($t, 'président de l\'assemblée nationale') || str_contains($t, 'président de l\'an')) return 'pres_an';
        if (str_contains($t, 'président du sénat')) return 'pres_senat';
        if (str_contains($t, 'conseiller de paris') || str_contains($t, 'conseillère de paris')) return 'cons_paris';
        if (str_contains($t, 'conseiller arrondissement') || str_contains($t, 'conseillère arrondissement') || str_contains($t, 'arrondissement municipal')) return 'cons_arrondt';
        if (str_contains($t, 'conseiller municipal') || str_contains($t, 'conseillère municipal') || str_contains($t, 'membre du conseil municipal')) return 'cons_municipal';
        if (str_contains($t, 'fde') || str_contains($t, 'français de l\'étranger') || str_contains($t, "français de l'étranger") || str_contains($t, 'assemblée fde')) return 'cons_fde';
        // EPCI / intercommunalité
        if (str_contains($t, 'président') && (str_contains($t, 'communauté') || str_contains($t, 'aggloméra') || str_contains($t, 'epci'))) return 'pres_epci';
        if (str_contains($t, 'vice-président') && (str_contains($t, 'communauté') || str_contains($t, 'aggloméra') || str_contains($t, 'epci'))) return 'vp_epci';
        // Collectivités territoriales (Corse, Guyane, Martinique, SPM)
        if (str_contains($t, 'président') && str_contains($t, 'territor')) return 'pres_territorial';
        if (str_contains($t, 'conseiller territorial') || str_contains($t, 'conseillère territorial')) return 'cons_territorial';
        // Maire d'arrondissement PLM
        if (str_contains($t, 'maire') && str_contains($t, 'arrondissement')) return 'maire_arrondt';
        // Président de commission locale (pas parlementaire/européenne)
        if (str_contains($t, 'président') && str_contains($t, 'commission') && str_contains($t, 'départemental') && !str_contains($t, 'développement')) return 'pres_commission_dept';
        if (str_contains($t, 'président') && str_contains($t, 'commission') && str_contains($t, 'régional') && !str_contains($t, 'développement') && !str_contains($t, 'parlement')) return 'pres_commission_region';
        // Conseiller communautaire générique
        if (str_contains($t, 'conseiller communautaire') || str_contains($t, 'délégué communautaire')) return 'cons_metro';
        return null;
    };

    // Collecter les indemnités des mandats actifs
    $indemnites = [];
    foreach ($mandatsActifs as $m) {
        $type = $detectType($m['titre'] ?? '');
        if ($type && isset($grille[$type]) && !isset($indemnites[$type])) {
            $entry = $grille[$type];
            $entry['mandat'] = $m['titre'];
            $entry['estimation'] = isset($entry['brut_min']); // fourchette = estimation
            $indemnites[$type] = $entry;
        }
    }
    // Maire inclut conseiller municipal de la même commune — ne pas cumuler
    if (isset($indemnites['maire']) && isset($indemnites['cons_municipal'])) unset($indemnites['cons_municipal']);
    // Non-cumul parlementaire national + exécutif local (LO n°2014-125, art. LO141-1, effectif 18/06/2017)
    $parlNationaux = ['depute', 'senateur'];
    $execLocauxNonCumulables = ['maire', 'adjoint', 'pres_region', 'vp_region', 'pres_dept', 'vp_dept', 'pres_epci', 'vp_epci'];
    $hasParlNational = !empty(array_intersect_key($indemnites, array_flip($parlNationaux)));
    if ($hasParlNational) {
        $incompatibles = array_keys(array_intersect_key($indemnites, array_flip($execLocauxNonCumulables)));
        if (!empty($incompatibles)) {
            foreach ($incompatibles as $t) unset($indemnites[$t]);
            $elu['mandats_incompatibles'] = $incompatibles;
            $elu['mandats_incompatibles_note'] = 'Non-cumul légal (LO n°2014-125) : mandat exécutif local suspendu pendant le mandat parlementaire national.';
        }
    }
    // Ministre/PM/Président = incompatible avec toute autre indemnité (loi organique 2014)
    if (isset($indemnites['ministre']) || isset($indemnites['president']) || isset($indemnites['premier_ministre'])) {
        $ministerType = isset($indemnites['president']) ? 'president' : (isset($indemnites['premier_ministre']) ? 'premier_ministre' : 'ministre');
        $kept = $indemnites[$ministerType];
        $indemnites = [$ministerType => $kept];
    }

    // Fallback : si aucun mandat actif trouvé mais des mandats actifs existent, utiliser type_mandat/fonction
    if (empty($indemnites) && !empty($mandatsActifs)) {
        $type = $detectType($fonction) ?? $detectType($typeMandat);
        if ($type && isset($grille[$type])) {
            $entry = $grille[$type];
            $entry['mandat'] = $elu['fonction'];
            $entry['estimation'] = isset($entry['brut_min']);
            $indemnites[$type] = $entry;
        }
    }

    // Si le salaire exact est en BDD (population connue), remplacer la fourchette
    if (!empty($elu['salaire_brut']) && isset($indemnites['maire'])) {
        $pop = (int)($elu['population'] ?? 0);
        $indemnites['maire'] = [
            'brut' => (int)$elu['salaire_brut'],
            'label' => 'Indemnité de maire',
            'detail' => ($pop > 0 ? number_format($pop, 0, '', ' ') . ' habitants' : 'Population non renseignée'),
            'source' => 'CGCT art. L2123-23 — population INSEE',
            'mandat' => $indemnites['maire']['mandat'],
            'estimation' => false,
        ];
    }

    // Affiner les fourchettes avec la population quand disponible
    $pop = (int)($elu['population'] ?? 0);
    if ($pop > 0) {
        // Conseillers départementaux : barème CGCT L3123-16
        if (isset($indemnites['cons_dept']) && isset($indemnites['cons_dept']['brut_min'])) {
            $brut = $pop >= 1000000 ? 2672 : ($pop >= 250000 ? 1844 : 1672);
            $indemnites['cons_dept']['brut'] = $brut;
            $indemnites['cons_dept']['detail'] = number_format($pop, 0, '', ' ') . ' hab. → ' . number_format($brut, 0, '', ' ') . '€';
            $indemnites['cons_dept']['estimation'] = false;
            unset($indemnites['cons_dept']['brut_min'], $indemnites['cons_dept']['brut_max']);
        }
        // VP département
        if (isset($indemnites['vp_dept']) && isset($indemnites['vp_dept']['brut_min'])) {
            $brut = $pop >= 1000000 ? 4172 : ($pop >= 250000 ? 3000 : 2508);
            $indemnites['vp_dept']['brut'] = $brut;
            $indemnites['vp_dept']['detail'] = number_format($pop, 0, '', ' ') . ' hab. → ' . number_format($brut, 0, '', ' ') . '€';
            $indemnites['vp_dept']['estimation'] = false;
            unset($indemnites['vp_dept']['brut_min'], $indemnites['vp_dept']['brut_max']);
        }
        // Président département
        if (isset($indemnites['pres_dept']) && isset($indemnites['pres_dept']['brut_min'])) {
            $brut = $pop >= 1000000 ? 5960 : ($pop >= 250000 ? 4407 : 3365);
            $indemnites['pres_dept']['brut'] = $brut;
            $indemnites['pres_dept']['detail'] = number_format($pop, 0, '', ' ') . ' hab. → ' . number_format($brut, 0, '', ' ') . '€';
            $indemnites['pres_dept']['estimation'] = false;
            unset($indemnites['pres_dept']['brut_min'], $indemnites['pres_dept']['brut_max']);
        }
        // Maire : barème CGCT art. L2123-23 (quand salaire_brut absent de la BDD)
        if (isset($indemnites['maire']['brut_min'])) {
            $brut = $pop >= 100000 ? 5961
                : ($pop >= 50000 ? 4961
                : ($pop >= 20000 ? 4658
                : ($pop >= 10000 ? 3493
                : ($pop >= 3500  ? 2475
                : ($pop >= 1000  ? 1902
                : ($pop >= 500   ? 1502
                : 1178))))));
            $indemnites['maire']['brut'] = $brut;
            $indemnites['maire']['detail'] = number_format($pop, 0, '', ' ') . ' hab. → ' . number_format($brut, 0, '', ' ') . '€';
            $indemnites['maire']['source'] = 'CGCT art. L2123-23 — barème 2025';
            $indemnites['maire']['estimation'] = false;
            unset($indemnites['maire']['brut_min'], $indemnites['maire']['brut_max']);
        }
    }

    if (!empty($indemnites)) {
        // Séparer parlementaires (hors plafond) et locaux (soumis au plafond CGCT L2123-20)
        $parlementaires = ['depute','senateur','europeen','membre_conseil_constitutionnel','pres_an','pres_senat'];
        $executifs = ['ministre','premier_ministre','president','pres_constit','pres_cese','defenseur'];
        $locaux = [];
        $horsPlafond = [];
        foreach ($indemnites as $type => $ind) {
            if (in_array($type, $parlementaires) || in_array($type, $executifs)) {
                $horsPlafond[$type] = $ind;
            } else {
                $locaux[$type] = $ind;
            }
        }

        $brutLocaux = array_sum(array_map(fn($i) => $i['brut'] ?? $i['brut_max'] ?? 0, $locaux));
        $brutHorsPlafond = array_sum(array_map(fn($i) => $i['brut'] ?? $i['brut_max'] ?? 0, $horsPlafond));

        // Plafond local = 2 965,98€ si parlementaire national, 8 897,93€ sinon
        $hasNational = isset($indemnites['depute']) || isset($indemnites['senateur']);
        $PLAFOND = $hasNational ? 2965.98 : 8897.93;
        $plafondLabel = $hasNational ? '2 965,98' : '8 897,93';

        if (count($indemnites) > 1 && $brutLocaux > $PLAFOND) {
            $elu['indemnites_note'] = "Mandats locaux écrêtés à $plafondLabel €/mois brut" . ($hasNational ? ' (0,5 × indemnité parlementaire)' : ' (CGCT art. L2123-20)');
            $elu['indemnites_plafond'] = round($PLAFOND + $brutHorsPlafond);
            $elu['indemnites_total_theorique'] = round($brutLocaux + $brutHorsPlafond);
        } elseif (count($indemnites) > 1) {
            $elu['indemnites_note'] = 'Cumul des indemnités';
        }

        $elu['indemnites'] = array_values($indemnites);
    }

    endif; // fin bloc indemnités (ancien_elu skip)

    // --- Coût cumulé carrière (calcul expert mois par mois) ---
    require_once __DIR__ . '/calcul-cout.php';
    $coutResult = calculerCoutCarriere($elu['mandats'], !empty($elu['salaire_brut']) ? (float)$elu['salaire_brut'] : null, (int)($elu['population'] ?? 0));
    if ($coutResult['total'] > 0) {
        $elu['cout_carriere'] = $coutResult['total'];
        $elu['cout_detail'] = $coutResult['detail'];
    }

    // --- Activités publiques rémunérées (HATVP, RNE, saisie manuelle) ---
    $stmtAP = $pdo->prepare("
        SELECT * FROM activites_publiques
        WHERE elu_id = :eid
        ORDER BY date_debut DESC
    ");
    try {
        $stmtAP->execute([':eid' => $elu['id']]);
        $apRows = $stmtAP->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $apRows = []; // table pas encore créée en dev
    }

    if (!empty($apRows)) {
        $todayStr = date('Y-m-d');
        $coutAP   = 0;

        foreach ($apRows as &$ap) {
            $actif = !$ap['date_fin'] || $ap['date_fin'] >= $todayStr;
            $ap['actif'] = $actif;

            // Calcul montant mensuel + libellé + explication
            if (!empty($ap['remuneration_exacte'])) {
                $m = (int)$ap['remuneration_exacte'];
                $ap['montant_mensuel'] = $m;
                $ap['montant_label']   = number_format($m, 0, ',', ' ') . ' €/mois';
                $ap['montant_detail']  = match($ap['precision_calcul']) {
                    'exact'         => 'Montant déclaré dans la DIA HATVP.',
                    'grille_fp'     => ($ap['note_calcul'] ?? 'Grille Fonction Publique — échelon médian estimé (±15%).'),
                    'plafond_cgct'  => 'Plafond légal CGCT — montant réel ≤ valeur affichée.',
                    default         => $ap['note_calcul'] ?? '',
                };
            } elseif (!empty($ap['remuneration_min']) && !empty($ap['remuneration_max'])) {
                $moy = (int)(($ap['remuneration_min'] + $ap['remuneration_max']) / 2);
                $pct = $moy > 0 ? (int)round(abs($ap['remuneration_max'] - $ap['remuneration_min']) / $moy * 50) : 0;
                $ap['montant_mensuel'] = $moy;
                $ap['montant_label']   = '~' . number_format($moy, 0, ',', ' ') . ' €/mois';
                $ap['montant_detail']  = "Moyenne de la tranche déclarée dans la DIA HATVP (±{$pct}%). " . ($ap['note_calcul'] ?? '');
            } elseif (!empty($ap['remuneration_min'])) {
                $ap['montant_mensuel'] = (int)$ap['remuneration_min'];
                $ap['montant_label']   = '≥ ' . number_format($ap['remuneration_min'], 0, ',', ' ') . ' €/mois';
                $ap['montant_detail']  = 'Borne basse de la tranche HATVP — montant réel ≥ valeur affichée.';
            } else {
                $ap['montant_mensuel'] = 0;
                $ap['montant_label']   = 'Montant non communiqué';
                $ap['montant_detail']  = 'Activité déclarée sans montant précisé.';
            }

            // Source lisible pour l'affichage
            $ap['source_label'] = match($ap['source']) {
                'hatvp'  => 'DIA HATVP (déclaration obligatoire)',
                'rne'    => 'RNE data.gouv.fr',
                'jo'     => 'Journal Officiel DILA',
                'manual' => 'Saisie manuelle vérifiée',
                default  => $ap['source'],
            };

            // Contribution au coût carrière (durée × mensuel)
            if ($ap['montant_mensuel'] > 0) {
                $deb = DateTimeImmutable::createFromFormat('Y-m-d', substr($ap['date_debut'] ?? '', 0, 10));
                $fin = !empty($ap['date_fin'])
                    ? DateTimeImmutable::createFromFormat('Y-m-d', substr($ap['date_fin'], 0, 10))
                    : new DateTimeImmutable('today');
                if ($deb) {
                    $moisDuree = (int)($deb->diff($fin)->days / 30.44);
                    $coutAP   += $moisDuree * $ap['montant_mensuel'];
                }
            }
        }
        unset($ap);

        $elu['activites_publiques'] = $apRows;

        // Ajouter au coût total carrière sans écraser le calcul mandats
        if ($coutAP > 0) {
            $elu['cout_carriere']           = ($elu['cout_carriere'] ?? 0) + $coutAP;
            $elu['cout_activites_publiques'] = (int)$coutAP;
        }
    }

    // --- Flag transparence patrimoine (HATVP) ---
    // Nationaux : déclaration publique sur hatvp.fr
    // Présidents département/région + maires : déclaration déposée, consultable en préfecture uniquement
    // Autres : pas d'obligation légale
    static $PREFECTURES = [
        '01'=>['Ain','Bourg-en-Bresse'],'02'=>['Aisne','Laon'],'03'=>['Allier','Moulins'],
        '04'=>['Alpes-de-Haute-Provence','Digne-les-Bains'],'05'=>['Hautes-Alpes','Gap'],
        '06'=>['Alpes-Maritimes','Nice'],'07'=>['Ardèche','Privas'],'08'=>['Ardennes','Charleville-Mézières'],
        '09'=>['Ariège','Foix'],'10'=>['Aube','Troyes'],'11'=>['Aude','Carcassonne'],
        '12'=>['Aveyron','Rodez'],'13'=>['Bouches-du-Rhône','Marseille'],'14'=>['Calvados','Caen'],
        '15'=>['Cantal','Aurillac'],'16'=>['Charente','Angoulême'],'17'=>['Charente-Maritime','La Rochelle'],
        '18'=>['Cher','Bourges'],'19'=>['Corrèze','Tulle'],'2A'=>['Corse-du-Sud','Ajaccio'],
        '2B'=>['Haute-Corse','Bastia'],'21'=>['Côte-d\'Or','Dijon'],'22'=>['Côtes-d\'Armor','Saint-Brieuc'],
        '23'=>['Creuse','Guéret'],'24'=>['Dordogne','Périgueux'],'25'=>['Doubs','Besançon'],
        '26'=>['Drôme','Valence'],'27'=>['Eure','Évreux'],'28'=>['Eure-et-Loir','Chartres'],
        '29'=>['Finistère','Quimper'],'30'=>['Gard','Nîmes'],'31'=>['Haute-Garonne','Toulouse'],
        '32'=>['Gers','Auch'],'33'=>['Gironde','Bordeaux'],'34'=>['Hérault','Montpellier'],
        '35'=>['Ille-et-Vilaine','Rennes'],'36'=>['Indre','Châteauroux'],'37'=>['Indre-et-Loire','Tours'],
        '38'=>['Isère','Grenoble'],'39'=>['Jura','Lons-le-Saunier'],'40'=>['Landes','Mont-de-Marsan'],
        '41'=>['Loir-et-Cher','Blois'],'42'=>['Loire','Saint-Étienne'],'43'=>['Haute-Loire','Le Puy-en-Velay'],
        '44'=>['Loire-Atlantique','Nantes'],'45'=>['Loiret','Orléans'],'46'=>['Lot','Cahors'],
        '47'=>['Lot-et-Garonne','Agen'],'48'=>['Lozère','Mende'],'49'=>['Maine-et-Loire','Angers'],
        '50'=>['Manche','Saint-Lô'],'51'=>['Marne','Châlons-en-Champagne'],'52'=>['Haute-Marne','Chaumont'],
        '53'=>['Mayenne','Laval'],'54'=>['Meurthe-et-Moselle','Nancy'],'55'=>['Meuse','Bar-le-Duc'],
        '56'=>['Morbihan','Vannes'],'57'=>['Moselle','Metz'],'58'=>['Nièvre','Nevers'],
        '59'=>['Nord','Lille'],'60'=>['Oise','Beauvais'],'61'=>['Orne','Alençon'],
        '62'=>['Pas-de-Calais','Arras'],'63'=>['Puy-de-Dôme','Clermont-Ferrand'],'64'=>['Pyrénées-Atlantiques','Pau'],
        '65'=>['Hautes-Pyrénées','Tarbes'],'66'=>['Pyrénées-Orientales','Perpignan'],
        '67'=>['Bas-Rhin','Strasbourg'],'68'=>['Haut-Rhin','Colmar'],'69'=>['Rhône','Lyon'],
        '70'=>['Haute-Saône','Vesoul'],'71'=>['Saône-et-Loire','Mâcon'],'72'=>['Sarthe','Le Mans'],
        '73'=>['Savoie','Chambéry'],'74'=>['Haute-Savoie','Annecy'],'75'=>['Paris','Paris'],
        '76'=>['Seine-Maritime','Rouen'],'77'=>['Seine-et-Marne','Melun'],'78'=>['Yvelines','Versailles'],
        '79'=>['Deux-Sèvres','Niort'],'80'=>['Somme','Amiens'],'81'=>['Tarn','Albi'],
        '82'=>['Tarn-et-Garonne','Montauban'],'83'=>['Var','Toulon'],'84'=>['Vaucluse','Avignon'],
        '85'=>['Vendée','La Roche-sur-Yon'],'86'=>['Vienne','Poitiers'],'87'=>['Haute-Vienne','Limoges'],
        '88'=>['Vosges','Épinal'],'89'=>['Yonne','Auxerre'],'90'=>['Territoire de Belfort','Belfort'],
        '91'=>['Essonne','Évry-Courcouronnes'],'92'=>['Hauts-de-Seine','Nanterre'],
        '93'=>['Seine-Saint-Denis','Bobigny'],'94'=>['Val-de-Marne','Créteil'],
        '95'=>['Val-d\'Oise','Cergy-Pontoise'],
        '971'=>['Guadeloupe','Basse-Terre'],'972'=>['Martinique','Fort-de-France'],
        '973'=>['Guyane','Cayenne'],'974'=>['La Réunion','Saint-Denis'],
        '975'=>['Saint-Pierre-et-Miquelon','Saint-Pierre'],'976'=>['Mayotte','Mamoudzou'],
    ];

    $isNational = in_array($typeMandat, ['depute', 'senateur', 'europeen'])
        || str_contains($fonction, 'député') || str_contains($fonction, 'sénateur')
        || str_contains($fonction, 'sénatrice') || str_contains($fonction, 'européen')
        || (str_contains($fonction, 'ministre') && !str_contains($fonction, 'ancien'));

    $isLocalSoumis = str_contains($typeMandat, 'president_region') || str_contains($typeMandat, 'president_departement')
        || (str_contains($fonction, 'président') && (str_contains($fonction, 'région') || str_contains($fonction, 'département')))
        || str_contains($typeMandat, 'maire');

    if ($isNational) {
        $elu['patrimoine_warning'] = [
            'consultable' => true,
            'url' => 'https://www.hatvp.fr/consulter-les-declarations/',
            'message' => 'Déclaration consultable sur hatvp.fr',
            'warning' => false,
            'note_tardivite' => 'La HATVP peut mettre plusieurs mois avant de publier une déclaration. La transparence prend du temps.',
        ];
    } elseif ($isLocalSoumis) {
        $dept = $elu['departement'] ?? '';
        $prefInfo = $PREFECTURES[$dept] ?? null;
        $prefNom  = $prefInfo ? 'Préfecture de ' . $prefInfo[0] : null;
        $prefVille = $prefInfo ? $prefInfo[1] : null;
        $elu['patrimoine_warning'] = [
            'consultable'      => false,
            'url'              => null,
            'message'          => 'Consultable en préfecture uniquement',
            'warning'          => true,
            'prefecture_nom'   => $prefNom,
            'prefecture_ville' => $prefVille,
        ];
    } else {
        $elu['patrimoine_warning'] = [
            'consultable' => false,
            'url' => null,
            'message' => 'Pas d\'obligation de déclaration de patrimoine',
            'warning' => false,
        ];
    }

    return $elu;
});

if ($data === null) {
    jsonResponse(['error' => 'Élu non trouvé'], 404);
}

// Votes citoyens (likes/dislikes) — depuis fichier JSON
$votesFile = __DIR__ . '/cache/data/votes_citoyens.json';
if (file_exists($votesFile)) {
    $allVotes = json_decode(file_get_contents($votesFile), true) ?: [];
    $eluLikes = 0; $eluDislikes = 0;
    foreach ($allVotes as $entry) {
        if (($entry['elu_id'] ?? 0) == $id) {
            if (($entry['vote'] ?? 0) === 1) $eluLikes++;
            elseif (($entry['vote'] ?? 0) === -1) $eluDislikes++;
        }
    }
    $data['nb_likes'] = $eluLikes;
    $data['nb_dislikes'] = $eluDislikes;
}

// Incrémenter le compteur dans elu_stats (pas dans elus → évite la fragmentation)
try {
    $pdo->prepare('INSERT INTO elu_stats (elu_id, nb_consultations) VALUES (:id, 1)
        ON DUPLICATE KEY UPDATE nb_consultations = nb_consultations + 1')
        ->execute([':id' => $id]);
} catch (PDOException $e) { /* non critique */ }

// Envoyer la réponse au client MAINTENANT
jsonResponseNoExit($data);

// Enrichissement Wikidata en arrière-plan si profil incomplet
// (s'exécute après que le client a reçu sa réponse)
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
require_once __DIR__ . '/enrich-on-demand.php';
enrichIfNeeded($pdo, $data);
exit;
