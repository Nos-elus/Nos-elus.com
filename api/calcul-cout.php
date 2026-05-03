<?php
/**
 * Calcul expert du coût cumulé d'un élu sur sa carrière.
 * Mois par mois, avec règles d'incompatibilité et plafond de cumul.
 *
 * Règles appliquées :
 * - Art. 23 Constitution : Ministre = seul traitement, mandats parlementaires suspendus
 * - CGCT L2123-20 : Plafond 8 897,93€/mois pour les mandats locaux cumulés
 * - Le plafond NE s'applique PAS aux parlementaires nationaux ni aux européens
 * - Maire inclut conseiller municipal (pas de double indemnité)
 */

function barème_maire_pop(int $pop): float {
    if ($pop >= 100000) return 5961;
    if ($pop >= 50000)  return 4961;
    if ($pop >= 20000)  return 4658;
    if ($pop >= 10000)  return 3493;
    if ($pop >= 3500)   return 2475;
    if ($pop >= 1000)   return 1902;
    if ($pop >= 500)    return 1502;
    return 1178;
}

/**
 * Retourne le taux mensuel historique réel pour un mandat.
 * Sources : ordonnance 58-1210, décrets 2007-1069 et 2012-766/983,
 *           protocole PPCR 2016-2019, revalorisations point FP.
 *
 * @param string $cle       clé de la grille (depute, ministre, conseiller_regional…)
 * @param int    $annee     année du mois à calculer
 * @param int    $mois      mois (1-12)
 * @param float  $tauxBase  taux 2026 de référence (depuis la grille courante)
 */
function grilleMensuelle(string $cle, int $annee, int $mois, float $tauxBase): float {
    $ym = $annee * 12 + $mois; // entier comparable (ex : 2022*12+7 = juillet 2022)

    switch ($cle) {
        // ── Parlementaires nationaux (hors-échelle FP) ─────────────────────────
        case 'depute':
        case 'senateur':
            if ($ym >= 2024 * 12 + 1) return $tauxBase;   // jan. 2024+ : 7 637 €
            if ($ym >= 2022 * 12 + 7) return 7240.0;      // juil. 2022 – déc. 2023 (+3,5 %)
            if ($ym >= 2017 * 12 + 1) return 7210.0;      // jan. 2017 – juin 2022 (gel)
            if ($ym >= 2010 * 12 + 7) return 7127.0;      // juil. 2010 – déc. 2016 (gel)
            if ($ym >= 2007 * 12 + 1) return 6980.0;      // 2007 – 2010
            if ($ym >= 2002 * 12 + 1) return 6700.0;      // 2002 – 2006
            return 6500.0;                                  // avant 2002

        // ── Eurodéputés (statut unifié UE depuis juil. 2009) ───────────────────
        case 'depute_europeen':
            if ($ym >= 2025 * 12 + 4) return $tauxBase;   // avr. 2025+ : 10 927 €
            if ($ym >= 2024 * 12 + 1) return 10377.0;     // jan. 2024 – mars 2025
            if ($ym >= 2021 * 12 + 7) return 9166.0;      // juil. 2021 – déc. 2023
            if ($ym >= 2016 * 12 + 1) return 8484.0;      // 2016 – juin 2021
            if ($ym >= 2015 * 12 + 1) return 8213.0;      // 2015
            if ($ym >= 2011 * 12 + 1) return 7957.0;      // 2011 – 2014
            if ($ym >= 2009 * 12 + 7) return 7665.0;      // juil. 2009 – 2010 (statut unifié)
            return 7210.0;                                  // avant juil. 2009 : = IPB député FR

        // ── Gouvernement ────────────────────────────────────────────────────────
        case 'premier_ministre':
            if ($ym >= 2024 * 12 + 1) return $tauxBase;   // jan. 2024+ : 16 038 €
            if ($ym >= 2012 * 12 + 5) return 14910.0;     // mai 2012 – déc. 2023 (−30 %)
            if ($ym >= 2007 * 12 + 7) return 21300.0;     // juil. 2007 – avr. 2012 (+50 %)
            if ($ym >= 2002 * 12 + 8) return 14500.0;     // août 2002 – juin 2007
            return 7500.0;                                  // avant août 2002

        case 'ministre':
        case 'garde_des_sceaux':
            if ($ym >= 2024 * 12 + 1) return $tauxBase;   // jan. 2024+ : 10 692 €
            if ($ym >= 2012 * 12 + 5) return 9940.0;      // mai 2012 – déc. 2023
            if ($ym >= 2007 * 12 + 7) return 14200.0;     // juil. 2007 – avr. 2012
            if ($ym >= 2002 * 12 + 8) return 12000.0;     // août 2002 – juin 2007
            return 8000.0;                                  // avant août 2002

        case 'secretaire_etat':
            if ($ym >= 2024 * 12 + 1) return $tauxBase;   // jan. 2024+ : 10 692 €
            if ($ym >= 2012 * 12 + 5) return 9443.0;      // mai 2012 – déc. 2023
            if ($ym >= 2007 * 12 + 7) return 13490.0;     // juil. 2007 – avr. 2012
            if ($ym >= 2002 * 12 + 8) return 11000.0;     // août 2002 – juin 2007
            return 7000.0;                                  // avant août 2002

        // ── Élus locaux (IBT — rupture PPCR 2017-2019 : IBT ×1,54) ────────────
        // Avant 2017 : IBT ≈ 2 660 €  |  après PPCR : IBT = 4 110 € (coeff ~0,647)
        case 'president_region':
        case 'president_departement':
            if ($ym >= 2022 * 12 + 7) return $tauxBase;
            if ($ym >= 2019 * 12 + 1) return round($tauxBase * 0.972);  // post-PPCR, pré +3,5 %
            if ($ym >= 2017 * 12 + 1) return round($tauxBase * 0.920);  // PPCR en cours
            return round($tauxBase * 0.647);                              // pré-PPCR (avant 2017)

        case 'vice_president_region':
        case 'vice_president_departement':
            if ($ym >= 2022 * 12 + 7) return $tauxBase;
            if ($ym >= 2019 * 12 + 1) return round($tauxBase * 0.972);
            if ($ym >= 2017 * 12 + 1) return round($tauxBase * 0.920);
            return round($tauxBase * 0.647);

        case 'conseiller_regional':
            if ($ym >= 2022 * 12 + 7) return $tauxBase;   // 2 013 €
            if ($ym >= 2019 * 12 + 1) return 1970.0;
            if ($ym >= 2017 * 12 + 1) return 1900.0;
            return 1860.0;                                  // pré-PPCR

        case 'conseiller_departemental':
            if ($ym >= 2022 * 12 + 7) return $tauxBase;   // 1 672 €
            if ($ym >= 2019 * 12 + 1) return 1600.0;
            if ($ym >= 2017 * 12 + 1) return 1540.0;
            return 1300.0;                                  // pré-PPCR (conseiller général)

        case 'maire':
        case 'adjoint_maire':
            // L'indemnité du maire dépend de la population (barème IBT-based),
            // on applique le même coefficient IBT que les autres locaux.
            if ($ym >= 2022 * 12 + 7) return $tauxBase;
            if ($ym >= 2019 * 12 + 1) return round($tauxBase * 0.972);
            if ($ym >= 2017 * 12 + 1) return round($tauxBase * 0.920);
            return round($tauxBase * 0.647);

        // ── Indemnités de fonction Bureau (AN / Sénat) ─────────────────────────
        // Pas d'historique fin disponible publiquement → application du taux 2024 sur
        // toute la période. Approximation acceptable car peu d'évolution entre 2017 et 2024.
        case 'fct_president_an':
        case 'fct_president_senat':
        case 'fct_vp_an':
        case 'fct_vp_senat':
        case 'fct_questeur_an':
        case 'fct_questeur_senat':
            return $tauxBase;

        default:
            return $tauxBase;
    }
}

function calculerCoutCarriere(array $mandats, ?float $salaire_brut_maire = null, int $population = 0): array
{
    $grille = [
        'president_republique'       => 16039, 'premier_ministre' => 16038,
        'ministre'                   => 10692, 'garde_des_sceaux' => 10692, 'secretaire_etat' => 10692,
        'depute'                     => 7637,  'senateur'         => 7637,  'depute_europeen' => 11255,
        'president_region'           => 5809,  'vice_president_region' => 3500, 'conseiller_regional' => 2013,
        'president_departement'      => 4407,  'vice_president_departement' => 3000, 'conseiller_departemental' => 1672,
        'maire'                      => 2500,  'adjoint_maire' => 1000, 'conseiller_municipal' => 0,
        'conseiller_arrondissement'  => 228,   'conseiller_metropolitain' => 246, 'conseiller_communautaire' => 246,
        'conseiller_paris'           => 2510,  'conseiller_fde' => 810,
        'membre_conseil_constitutionnel' => 13872,
        // Indemnités de fonction Bureau AN/Sénat (s'ajoutent à l'indemnité parlementaire de base)
        // Sources : assemblee-nationale.fr/dyn/synthese/deputes-groupes-parlementaires/la-situation-materielle-du-depute
        //          senat.fr/connaitre-le-senat/role-et-fonctionnement/lindemnite-parlementaire.html
        'fct_president_an'           => 7698.50,  'fct_president_senat' => 7591.58,
        'fct_vp_an'                  => 1099.79,  'fct_vp_senat'        => 2184.30,
        'fct_questeur_an'            => 5300.36,  'fct_questeur_senat'  => 4444.97,
    ];

    $PLAFOND_LOCAL = 8897.93;

    // Normalisation titre → clé grille
    $normaliser = function(string $titre) {
        $t = mb_strtolower(trim($titre), 'UTF-8');
        // Rejeter les titres non-électoraux (pollution Wikidata)
        if (preg_match('/(directeur|pdg|président-directeur|candidat|avocat|médecin|professeur|chapelle|organiste|compositeur|journaliste|ingénieur|architecte|chef d)/', $t)) {
            // Sauf si c'est un vrai mandat (ex: "Maire — La Chapelle")
            if (!str_contains($t, 'maire') && !str_contains($t, 'député') && !str_contains($t, 'conseill') && !str_contains($t, 'sénateur') && !str_contains($t, 'ministre') && !str_contains($t, 'président') && !str_contains($t, 'adjoint'))
                return '';
        }
        // MÉTROPOLE avant européen !
        if (str_contains($t, 'métropol') || str_contains($t, 'metropol')) return 'conseiller_metropolitain';
        if (str_contains($t, 'conseiller communautaire') || str_contains($t, 'délégué communautaire')) return 'conseiller_communautaire';
        if (str_contains($t, 'président de la rép')) return 'president_republique';
        if (str_contains($t, 'premier ministre')) return 'premier_ministre';
        if (str_contains($t, 'garde des sceaux')) return 'garde_des_sceaux';
        if (str_contains($t, "secrétaire d'état") || str_contains($t, "secrétaire d'état") || str_contains($t, "secrétaire d état")) return 'secretaire_etat';
        // SG de la présidence de la République ≈ niveau secrétaire d'État (avant le check 'adjoint')
        if (str_contains($t, 'secrétaire général') && (str_contains($t, 'présidence') || str_contains($t, 'élysée') || str_contains($t, 'elysée'))) return 'secretaire_etat';
        // Porte-parole du gouvernement = niveau secrétaire d'État
        if (str_contains($t, 'porte-parole') && str_contains($t, 'gouvernement')) return 'secretaire_etat';
        if (str_contains($t, 'ministre')) return 'ministre';
        if (str_contains($t, 'député européen') || str_contains($t, 'parlement européen')) return 'depute_europeen';
        if (str_contains($t, 'européen') && !str_contains($t, 'métropol')) return 'depute_europeen';
        // Fonctions parlementaires (Bureau) : s'ajoutent à l'indemnité de base sans la remplacer.
        // Le mandat parlementaire (Député/Sénateur) coexiste dans mandats[], il est mappé séparément.
        if (str_contains($t, 'questeur') && str_contains($t, 'sénat'))      return 'fct_questeur_senat';
        if (str_contains($t, 'questeur') && str_contains($t, 'assemblée'))  return 'fct_questeur_an';
        if (str_contains($t, 'vice-président') && str_contains($t, 'sénat'))               return 'fct_vp_senat';
        if (str_contains($t, 'vice-président') && str_contains($t, 'assemblée nationale')) return 'fct_vp_an';
        if (str_contains($t, 'président') && str_contains($t, 'sénat'))                    return 'fct_president_senat';
        if (str_contains($t, 'président') && str_contains($t, 'assemblée nationale'))      return 'fct_president_an';
        if (str_contains($t, 'député')) return 'depute';
        if (str_contains($t, 'sénateur') || str_contains($t, 'sénatrice') || str_contains($t, 'sénateur')) return 'senateur';
        if (str_contains($t, 'conseil constitutionnel')) return 'membre_conseil_constitutionnel';
        if (str_contains($t, 'commission')) return ''; // skip commissions parlementaires
        // Wikidata "ou" patterns : "vice-président(e)" + zone géographique
        if ((str_contains($t, 'vice-président') || str_contains($t, 'vice-présidente')) && (str_contains($t, 'conseil régional') || str_contains($t, 'région') || preg_match('/\b(auvergne|normandie|occitanie|bretagne|bourgogne|nouvelle-aquitaine|pays de la loire|centre-val|hauts-de-france|grand est|paca|provence|île-de-france|corse|dom|outre-mer)\b/', $t))) return 'vice_president_region';
        if ((str_contains($t, 'vice-président') || str_contains($t, 'vice-présidente')) && (str_contains($t, 'conseil départemental') || str_contains($t, 'département'))) return 'vice_president_departement';
        if ((str_contains($t, 'président') || str_contains($t, 'présidente')) && (str_contains($t, 'conseil régional') || str_contains($t, 'région'))) return 'president_region';
        if ((str_contains($t, 'président') || str_contains($t, 'présidente')) && (str_contains($t, 'conseil départemental') || str_contains($t, 'département') || str_contains($t, 'conseil général'))) return 'president_departement';
        if (str_contains($t, 'conseiller') && str_contains($t, 'paris')) return 'conseiller_paris';
        if (str_contains($t, 'arrondissement')) return 'conseiller_arrondissement';
        if (str_contains($t, 'conseiller régional') || str_contains($t, 'conseillère régional')) return 'conseiller_regional';
        if (str_contains($t, 'conseiller départemental') || str_contains($t, 'conseiller général') || str_contains($t, 'conseillère départemental') || str_contains($t, 'conseillère général')) return 'conseiller_departemental';
        if (str_contains($t, 'fde') || str_contains($t, "français de l'étranger")) return 'conseiller_fde';
        // adjoint_maire : vérifier le contexte maire pour éviter les faux positifs (ex: secrétaire général adjoint)
        if (str_contains($t, 'adjoint') && (str_contains($t, 'maire') || str_contains($t, 'municipal'))) return 'adjoint_maire';
        if (str_contains($t, 'premier adjoint') || str_contains($t, 'première adjointe')) return 'adjoint_maire';
        if (str_contains($t, 'maire')) return 'maire';
        if (str_contains($t, 'conseiller municipal') || str_contains($t, 'conseillère municipal') || str_contains($t, 'membre du conseil municipal')) return 'conseiller_municipal';
        return '';
    };

    $estExecutif = fn($c) => in_array($c, ['president_republique','premier_ministre','ministre','garde_des_sceaux','secretaire_etat']);
    $estParlementaire = fn($c) => in_array($c, ['depute','senateur','depute_europeen','membre_conseil_constitutionnel']);
    $estFonctionParlementaire = fn($c) => str_starts_with($c, 'fct_');

    // Préparer les mandats
    $mandatsNorm = [];
    $today = new DateTimeImmutable('today');
    foreach ($mandats as $m) {
        $cle = $normaliser($m['titre'] ?? '');
        if (!$cle || !isset($grille[$cle])) continue;
        $debut = DateTimeImmutable::createFromFormat('Y-m-d', substr($m['date_debut'] ?? '', 0, 10));
        if (!$debut || $debut->format('Y') < '1900') continue;
        $fin = !empty($m['date_fin']) ? DateTimeImmutable::createFromFormat('Y-m-d', substr($m['date_fin'], 0, 10)) : null;
        if (!$fin) $fin = $today;
        $indemniteBase = $grille[$cle];
        if ($cle === 'maire') {
            if ($salaire_brut_maire) $indemniteBase = $salaire_brut_maire;
            elseif ($population > 0) $indemniteBase = barème_maire_pop($population);
        }
        $mandatsNorm[] = ['cle' => $cle, 'debut' => $debut, 'fin' => $fin, 'indemnite_base' => $indemniteBase];
    }

    if (empty($mandatsNorm)) return ['total' => 0, 'detail' => []];

    // Bornes
    usort($mandatsNorm, fn($a,$b) => $a['debut'] <=> $b['debut']);
    $moisCourant = $mandatsNorm[0]['debut']->modify('first day of this month');
    $finCarriere = $today->modify('first day of this month');

    $totalCout = 0;
    $detailType = [];
    $dateNonCumul = new DateTimeImmutable('2017-06-18');
    $execLocauxNonCumulables = ['maire','adjoint_maire','president_region','vice_president_region','president_departement','vice_president_departement'];

    while ($moisCourant <= $finCarriere) {
        $finMois = $moisCourant->modify('last day of this month');
        $anneeM  = (int)$moisCourant->format('Y');
        $moisM   = (int)$moisCourant->format('n');

        // Mandats actifs ce mois — taux historique appliqué
        $actifs = [];
        foreach ($mandatsNorm as $m) {
            if ($m['debut'] <= $finMois && $m['fin'] >= $moisCourant) {
                $indH = grilleMensuelle($m['cle'], $anneeM, $moisM, $m['indemnite_base']);
                $actifs[] = ['cle' => $m['cle'], 'debut' => $m['debut'], 'fin' => $m['fin'], 'indemnite' => $indH];
            }
        }
        if (empty($actifs)) { $moisCourant = $moisCourant->modify('first day of next month'); continue; }

        // Règle 1 : Exécutif national = seul traitement
        $aExecutif = false;
        $montantExec = 0; $cleExec = '';
        foreach ($actifs as $m) {
            if ($estExecutif($m['cle']) && $m['indemnite'] > $montantExec) {
                $aExecutif = true; $montantExec = $m['indemnite']; $cleExec = $m['cle'];
            }
        }
        if ($aExecutif) {
            $totalCout += $montantExec;
            $detailType[$cleExec] = ($detailType[$cleExec] ?? ['mois'=>0,'brut'=>0,'reel'=>0]);
            $detailType[$cleExec]['mois']++; $detailType[$cleExec]['brut'] += $montantExec; $detailType[$cleExec]['reel'] += $montantExec;
            $moisCourant = $moisCourant->modify('first day of next month'); continue;
        }

        // Règle 2 : Non-cumul parlementaire national + exécutif local (LO n°2014-125, art. LO141-1, effectif 18/06/2017)
        if ($moisCourant >= $dateNonCumul) {
            $hasParlNational = false;
            foreach ($actifs as $m) {
                if (in_array($m['cle'], ['depute', 'senateur'])) { $hasParlNational = true; break; }
            }
            if ($hasParlNational) {
                $actifs = array_values(array_filter($actifs, fn($m) => !in_array($m['cle'], $execLocauxNonCumulables)));
            }
        }

        // Règle 3 : Maire inclut conseiller municipal
        $aMaire = false;
        foreach ($actifs as $m) { if ($m['cle'] === 'maire') { $aMaire = true; break; } }
        if ($aMaire) $actifs = array_values(array_filter($actifs, fn($m) => $m['cle'] !== 'conseiller_municipal'));

        // Séparer en 3 catégories :
        //   - parlementaires (hors plafond local)
        //   - fonctions parlementaires Bureau (s'ajoutent au mandat de base, sans plafond)
        //   - locaux (soumis au plafond cumul local)
        $parlementaires = []; $fcts = []; $locaux = [];
        foreach ($actifs as $m) {
            if ($estParlementaire($m['cle']))                $parlementaires[] = $m;
            elseif ($estFonctionParlementaire($m['cle']))    $fcts[] = $m;
            else                                             $locaux[] = $m;
        }

        $brutLocaux = array_sum(array_map(fn($m) => $m['indemnite'], $locaux));
        $brutParlem = array_sum(array_map(fn($m) => $m['indemnite'], $parlementaires));
        $brutFcts   = array_sum(array_map(fn($m) => $m['indemnite'], $fcts));

        // Si parlementaire national (député/sénateur) + locaux : plafond local = 2 965,98€ (0,5 × IPB)
        // Si seulement locaux : plafond = 8 897,93€
        // Si européen + locaux : plafond local = 8 897,93€ (l'européenne est hors droit français)
        $hasNational = false;
        foreach ($parlementaires as $p) {
            if (in_array($p['cle'], ['depute', 'senateur'])) { $hasNational = true; break; }
        }
        $plafondLocal = $hasNational ? 2965.98 : $PLAFOND_LOCAL;
        $ecreteLocaux = min($brutLocaux, $plafondLocal);

        $coutMois = $ecreteLocaux + $brutParlem + $brutFcts;
        $totalCout += $coutMois;

        // Détail locaux (répartition proportionnelle écrêtement)
        foreach ($locaux as $m) {
            $c = $m['cle'];
            $detailType[$c] = ($detailType[$c] ?? ['mois'=>0,'brut'=>0,'reel'=>0]);
            $detailType[$c]['mois']++;
            $detailType[$c]['brut'] += $m['indemnite'];
            $detailType[$c]['reel'] += ($brutLocaux > 0) ? ($m['indemnite'] / $brutLocaux) * $ecreteLocaux : 0;
        }
        foreach ($parlementaires as $m) {
            $c = $m['cle'];
            $detailType[$c] = ($detailType[$c] ?? ['mois'=>0,'brut'=>0,'reel'=>0]);
            $detailType[$c]['mois']++;
            $detailType[$c]['brut'] += $m['indemnite'];
            $detailType[$c]['reel'] += $m['indemnite'];
        }
        foreach ($fcts as $m) {
            $c = $m['cle'];
            $detailType[$c] = ($detailType[$c] ?? ['mois'=>0,'brut'=>0,'reel'=>0]);
            $detailType[$c]['mois']++;
            $detailType[$c]['brut'] += $m['indemnite'];
            $detailType[$c]['reel'] += $m['indemnite'];
        }

        $moisCourant = $moisCourant->modify('first day of next month');
    }

    return ['total' => round($totalCout), 'detail' => $detailType];
}
