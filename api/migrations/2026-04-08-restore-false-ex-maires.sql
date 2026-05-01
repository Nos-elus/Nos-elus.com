-- ============================================================
-- ⛔⛔⛔ DIAGNOSTIC RÉVISÉ — NE PAS EXÉCUTER LES PARTIES B ET C ⛔⛔⛔
-- ============================================================
--
-- Historique de l'incident (2026-04-08) :
--
-- v1 (matin) : j'ai cru identifier 1032 "faux positifs" d'ex-maires
-- après avoir vu Damienne Fleury marquée "Ancien(ne)" alors que
-- Wikipedia indiquait un mandat 2020-2026 et une qualif au 2e tour.
-- J'ai écrit les parties B et C pour restaurer Fleury et les 1031
-- autres en batch.
--
-- v2 (après-midi, double-check web par échantillonnage) : sur 12
-- candidats vérifiés au hasard via mesinfos.fr + datan.fr + France
-- Bleu, 0 est encore maire, 9 sont des vrais ex-maires confirmés
-- (battus, retirés, non candidats), 3 sont incertains. Fleury elle-
-- même est en réalité BATTUE au 2e tour du 22 mars 2026 avec 33,87%
-- contre Mickaël Juigné (50,27%). Mon correctif initial était faux.
--
-- LEÇON : qualification au 2e tour ≠ victoire. Toujours vérifier les
-- résultats finaux avant de toucher à la BDD prod.
--
-- Les parties B et C ci-dessous sont donc INVALIDÉES et conservées
-- uniquement comme trace de l'incident. Elles ne doivent plus jamais
-- être décommentées ni exécutées. Le script fix-maires-mismatch.php
-- avait ~95% raison sur les 1032 cas.
--
-- Seule la partie A (diagnostic SELECT) reste utilisable pour audit.
--
-- ============================================================
--
-- Ce fichier contient :
--   Partie A — Requêtes de diagnostic (SELECT only, safe)
--   Partie B — Correctif Fleury (UPDATE, à valider humainement)
--   Partie C — Script de restauration batch (UPDATE, commenté par
--              défaut — à décommenter APRÈS validation manuelle d'un
--              échantillon de 10-20 cas au hasard)
--
-- ⚠️  AUCUNE commande de ce fichier ne doit être exécutée sans
--     passage explicite de l'humain. C'est une migration manuelle.
-- ============================================================

-- ============================================================
-- PARTIE A — DIAGNOSTIC (SELECT ONLY, SAFE)
-- ============================================================

-- A.1 — Combien de maires ont été marqués "Ancien(ne)" avec un
--        mandat fermé entre le 1er et le 2e tour des municipales ?
--        Ce sont les suspects principaux : mandat cloturé sur la
--        période 2026-03-15 → 2026-04-15 alors qu'ils étaient
--        probablement candidats à leur succession.
SELECT COUNT(DISTINCT e.id) AS suspects_total
FROM elus e
JOIN mandats m ON m.elu_id = e.id
WHERE e.fonction LIKE 'Ancien(ne) Maire%'
  AND e.actif = 0
  AND m.titre LIKE 'Maire%'
  AND m.date_fin BETWEEN '2026-03-15' AND '2026-04-15';

-- A.2 — Même requête mais avec détail, top 100 par département
--       pour audit visuel manuel
SELECT
    e.id,
    e.nom,
    e.prenom,
    e.fonction,
    e.departement,
    MAX(m.date_fin) AS date_fin_mandat_maire,
    COALESCE(es.nb_consultations, e.nb_consultations) AS hits
FROM elus e
JOIN mandats m ON m.elu_id = e.id
LEFT JOIN elu_stats es ON es.elu_id = e.id
WHERE e.fonction LIKE 'Ancien(ne) Maire%'
  AND e.actif = 0
  AND m.titre LIKE 'Maire%'
  AND m.date_fin BETWEEN '2026-03-15' AND '2026-04-15'
GROUP BY e.id
ORDER BY e.departement, hits DESC
LIMIT 100;

-- A.3 — Distribution par département, pour identifier si le script
--       a tourné en focus sur certaines zones
SELECT
    e.departement,
    COUNT(DISTINCT e.id) AS nb_suspects
FROM elus e
JOIN mandats m ON m.elu_id = e.id
WHERE e.fonction LIKE 'Ancien(ne) Maire%'
  AND e.actif = 0
  AND m.titre LIKE 'Maire%'
  AND m.date_fin BETWEEN '2026-03-15' AND '2026-04-15'
GROUP BY e.departement
ORDER BY nb_suspects DESC
LIMIT 30;

-- A.4 — Cas Fleury spécifique : afficher son état actuel
SELECT
    e.id, e.nom, e.prenom, e.slug, e.fonction, e.actif,
    e.departement, e.salaire_brut, e.population
FROM elus e
WHERE e.slug = 'damienne-fleury'
   OR (LOWER(e.nom) = 'fleury' AND LOWER(e.prenom) = 'damienne');

-- A.5 — Ses mandats
SELECT m.id, m.titre, m.date_debut, m.date_fin, m.institution
FROM mandats m
JOIN elus e ON e.id = m.elu_id
WHERE e.slug = 'damienne-fleury'
   OR (LOWER(e.nom) = 'fleury' AND LOWER(e.prenom) = 'damienne')
ORDER BY m.date_debut DESC;


-- ============================================================
-- PARTIE B — CORRECTIF FLEURY (à valider puis exécuter)
-- ============================================================
-- Source vérification : 2026-04-08
--   - https://fr.wikipedia.org/wiki/Yvr%C3%A9-l%27%C3%89v%C3%AAque
--     « Damienne Fleury, cadre bancaire, divers gauche, élue en
--       juillet 2020, mandat 2020-2026 »
--   - https://www.francebleu.fr/pays-de-la-loire/sarthe-72/yvre-l-eveque/elections
--     Qualifiée pour le 2e tour le 22 mars 2026 avec 32,80%
--   - https://www.ville-yvreleveque.fr/discours-voeux-a-la-population-2026/
--     Vœux 2026 prononcés en tant que maire en exercice
--
-- Commenté par défaut : décommenter les 3 requêtes après validation.

-- B.1 — Retirer le préfixe "Ancien(ne)" et remettre actif=1
-- UPDATE elus
-- SET actif = 1,
--     fonction = 'Maire — Yvré-l''Évêque'
-- WHERE (slug = 'damienne-fleury'
--        OR (LOWER(nom) = 'fleury' AND LOWER(prenom) = 'damienne'))
--   AND fonction LIKE 'Ancien(ne) Maire%';

-- B.2 — Rouvrir le mandat de maire indûment clos
-- UPDATE mandats m
-- JOIN elus e ON e.id = m.elu_id
-- SET m.date_fin = NULL
-- WHERE (e.slug = 'damienne-fleury'
--        OR (LOWER(e.nom) = 'fleury' AND LOWER(e.prenom) = 'damienne'))
--   AND m.titre LIKE 'Maire%'
--   AND m.date_fin = '2026-03-22';

-- B.3 — Purger le cache JSON de sa fiche (à faire côté shell, pas SQL)
-- Commande à exécuter sur le serveur :
-- rm api/cache/data/elu_damienne-fleury.json
-- rm api/cache/data/palmares_*.json   (regeneration propre)
-- rm api/cache/data/stats_*.json


-- ============================================================
-- PARTIE C — RESTAURATION BATCH (DANGEREUX, à décommenter
--             uniquement après validation d'un échantillon)
-- ============================================================
--
-- PRÉREQUIS AVANT D'EXÉCUTER :
--   1. Avoir lancé la requête A.1 et connaître le total des suspects
--   2. Avoir lancé A.2 et vérifié manuellement 10-20 cas au hasard
--      sur Wikipedia / site commune / data.gouv.fr RNE
--   3. Avoir fait un mysqldump de sauvegarde
--   4. Avoir rapidement audité si des suspects doivent rester
--      "Ancien(ne)" (vrais ex-maires perdus au 1er tour non-candidats)
--
-- Si tu te rends compte que >95% des suspects sont de faux positifs
-- (ce qui est le scénario attendu vu le bug de fix-maires-mismatch),
-- alors la restauration en masse est le bon choix.

-- C.1 — Restaurer le statut actif et la fonction (retrait préfixe)
-- UPDATE elus e
-- JOIN mandats m ON m.elu_id = e.id
-- SET
--     e.actif = 1,
--     e.fonction = TRIM(REPLACE(e.fonction, 'Ancien(ne) ', ''))
-- WHERE e.fonction LIKE 'Ancien(ne) Maire%'
--   AND e.actif = 0
--   AND m.titre LIKE 'Maire%'
--   AND m.date_fin BETWEEN '2026-03-15' AND '2026-04-15';

-- C.2 — Rouvrir les mandats faussement clos
-- UPDATE mandats m
-- JOIN elus e ON e.id = m.elu_id
-- SET m.date_fin = NULL
-- WHERE m.titre LIKE 'Maire%'
--   AND m.date_fin BETWEEN '2026-03-15' AND '2026-04-15'
--   AND (
--         e.actif = 1  -- déjà restauré par C.1
--         OR e.fonction NOT LIKE 'Ancien(ne)%'
--       );

-- C.3 — Purger tout le cache des fiches et palmarès après restauration
-- À faire côté shell :
-- rm -f api/cache/data/elu_*.json
-- rm -f api/cache/data/palmares_*.json
-- rm -f api/cache/data/stats_*.json
-- rm -f api/cache/data/matchmaker_*.json
-- (Attention : respecter la règle projet "NE JAMAIS rm *.json dans
--  cache/" telle quelle — ici on liste les fichiers par préfixe, pas
--  un rm générique)


-- ============================================================
-- PARTIE D — POST-RESTAURATION, CONTRÔLE QUALITÉ
-- ============================================================

-- D.1 — Aucun "Ancien(ne) Maire" ne devrait plus avoir un mandat
--        encore ouvert (incohérence résiduelle)
SELECT e.id, e.nom, e.fonction, COUNT(m.id) AS mandats_maire_ouverts
FROM elus e
JOIN mandats m ON m.elu_id = e.id
WHERE e.fonction LIKE 'Ancien(ne) Maire%'
  AND m.titre LIKE 'Maire%'
  AND m.date_fin IS NULL
GROUP BY e.id;

-- D.2 — Distribution actif / inactif / ancien après restauration
SELECT
    SUM(CASE WHEN actif = 1 AND fonction LIKE 'Maire%' THEN 1 ELSE 0 END) AS maires_actifs,
    SUM(CASE WHEN actif = 0 AND fonction LIKE 'Ancien(ne) Maire%' THEN 1 ELSE 0 END) AS ex_maires,
    SUM(CASE WHEN fonction LIKE '%Maire%' THEN 1 ELSE 0 END) AS total_maires
FROM elus
WHERE type_mandat = 'maire' OR fonction LIKE '%aire%';
