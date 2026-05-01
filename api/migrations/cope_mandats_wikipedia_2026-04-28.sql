-- ============================================================
-- MANDATS MANQUANTS — Jean-François Copé (Q62354)
-- Source vérifiée : fr.wikipedia.org/wiki/Jean-François_Copé
-- Date vérification : 2026-04-28
-- ============================================================

-- WEB-VERIFIED https://fr.wikipedia.org/wiki/Jean-Fran%C3%A7ois_Cop%C3%A9

SET @elu_id = (SELECT id FROM elus WHERE slug = 'jean-francois-cope' LIMIT 1);
SELECT @elu_id AS 'elu_id Copé (vérifier avant de continuer)';

-- Arrêt si élu non trouvé
SELECT IF(@elu_id IS NULL, CONCAT(CHAR(27), '[31mERREUR : slug jean-francois-cope introuvable', CHAR(27), '[0m'), 'OK') AS check_elu;

-- ── 1. Maire de Meaux — 2e mandat (01/12/2005 → 23/03/2014)
-- Wikipedia : "En fonction depuis le 1er décembre 2005"
-- Wikidata P39 saute de 2002 à 2014 (lacune confirmée)
INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution)
SELECT @elu_id, 'Maire de Meaux', '2005-12-01', '2014-03-23', 'Commune de Meaux'
WHERE @elu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM mandats
      WHERE elu_id = @elu_id
        AND LOWER(titre) LIKE '%maire%meaux%'
        AND YEAR(date_debut) = 2005
  ); -- WEB-VERIFIED https://fr.wikipedia.org/wiki/Jean-Fran%C3%A7ois_Cop%C3%A9

-- ── 2. Conseiller régional Île-de-France (15/03/1998 → été 2007)
-- Wikipedia : quitte en "été 2007" pour respecter le cumul — date approx. 12/07/2007
INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution)
SELECT @elu_id, 'Conseiller régional d''Île-de-France', '1998-03-15', '2007-07-12', 'Conseil régional d''Île-de-France'
WHERE @elu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM mandats
      WHERE elu_id = @elu_id
        AND LOWER(titre) LIKE '%r%gional%'
        AND YEAR(date_debut) = 1998
  ); -- WEB-VERIFIED https://fr.wikipedia.org/wiki/Jean-Fran%C3%A7ois_Cop%C3%A9

-- ── 3. Secrétaire d'État aux Relations avec le Parlement (07/05/2002 → 30/03/2004)
-- Gouvernement Raffarin I & II
INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution)
SELECT @elu_id, 'Secrétaire d''État aux Relations avec le Parlement', '2002-05-07', '2004-03-30', 'Gouvernement Raffarin'
WHERE @elu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM mandats
      WHERE elu_id = @elu_id
        AND (LOWER(titre) LIKE '%secr%taire%parlement%' OR LOWER(titre) LIKE '%porte-parole%')
        AND YEAR(date_debut) = 2002
  ); -- WEB-VERIFIED https://fr.wikipedia.org/wiki/Jean-Fran%C3%A7ois_Cop%C3%A9

-- ── 4. Ministre délégué à l'Intérieur (31/03/2004 → 29/11/2004)
-- Gouvernement Raffarin III
INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution)
SELECT @elu_id, 'Ministre délégué à l''Intérieur', '2004-03-31', '2004-11-29', 'Gouvernement Raffarin III'
WHERE @elu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM mandats
      WHERE elu_id = @elu_id
        AND LOWER(titre) LIKE '%int%rieur%'
        AND YEAR(date_debut) = 2004
  ); -- WEB-VERIFIED https://fr.wikipedia.org/wiki/Jean-Fran%C3%A7ois_Cop%C3%A9

-- ── 5. Ministre délégué au Budget (29/11/2004 → 15/05/2007)
-- Gouvernements Raffarin III puis Villepin
INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution)
SELECT @elu_id, 'Ministre délégué au Budget', '2004-11-29', '2007-05-15', 'Gouvernements Raffarin III et Villepin'
WHERE @elu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM mandats
      WHERE elu_id = @elu_id
        AND LOWER(titre) LIKE '%budget%'
        AND YEAR(date_debut) = 2004
  ); -- WEB-VERIFIED https://fr.wikipedia.org/wiki/Jean-Fran%C3%A7ois_Cop%C3%A9

-- ── 6. Porte-parole du gouvernement (07/05/2002 → 15/05/2007)
-- NB : rôle transverse, pas d'indemnité distincte — mais complète l'historique.
-- Si déjà en BDD via Wikidata, l'INSERT IGNORE est sans effet.
INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution)
SELECT @elu_id, 'Porte-parole du gouvernement', '2002-05-07', '2007-05-15', 'Gouvernement Raffarin / Villepin'
WHERE @elu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM mandats
      WHERE elu_id = @elu_id
        AND LOWER(titre) LIKE '%porte-parole%'
  ); -- WEB-VERIFIED https://fr.wikipedia.org/wiki/Jean-Fran%C3%A7ois_Cop%C3%A9

-- ── Vérification finale ──
SELECT titre, date_debut, date_fin, institution
FROM mandats
WHERE elu_id = @elu_id
ORDER BY date_debut;

-- ── Invalider le cache ──
-- (à faire manuellement après en PHP ou via l'API)
-- rm -f /path/to/cache/elu_<id>*.json
