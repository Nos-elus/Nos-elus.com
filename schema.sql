-- ============================================
-- NOS-ELUS.COM — Schéma MySQL
-- Version 2.1 — Avril 2026
-- Schéma de base
-- ============================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ── Tables ──

CREATE TABLE IF NOT EXISTS elus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    prenom VARCHAR(255),
    slug VARCHAR(255) UNIQUE,
    parti VARCHAR(255),
    fonction VARCHAR(500),
    emoji VARCHAR(10),
    couleur VARCHAR(10),
    photo_url VARCHAR(500),
    date_naissance DATE,
    lieu_naissance VARCHAR(255),
    bio TEXT,
    patrimoine_info TEXT,
    score_transparence TINYINT DEFAULT 5,
    score_assiduite TINYINT DEFAULT 5,
    score_coherence TINYINT DEFAULT 5,
    score_bilan TINYINT DEFAULT 5,
    source_api VARCHAR(50),
    source_id VARCHAR(255),
    derniere_sync DATETIME,
    nb_consultations INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    departement VARCHAR(5),
    region VARCHAR(100),
    type_mandat VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nom (nom),
    INDEX idx_parti (parti),
    FULLTEXT INDEX ft_nom_prenom (nom, prenom),
    INDEX idx_source (source_api, source_id),
    INDEX idx_actif_parti (actif, parti),
    INDEX idx_departement (departement),
    INDEX idx_consultations (nb_consultations)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mandats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    elu_id INT NOT NULL,
    titre VARCHAR(500) NOT NULL,
    date_debut DATE,
    date_fin DATE,
    institution VARCHAR(255),
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE,
    INDEX idx_elu (elu_id),
    INDEX idx_elu_datefin (elu_id, date_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS affaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    elu_id INT NOT NULL,
    titre VARCHAR(500) NOT NULL,
    description TEXT,
    statut ENUM('condamne', 'en_cours', 'relaxe', 'classe') NOT NULL DEFAULT 'en_cours',
    date_debut DATE,
    date_fin DATE,
    gravite TINYINT DEFAULT 1,
    source_url VARCHAR(500),
    source_nom VARCHAR(255),
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE,
    INDEX idx_elu (elu_id),
    INDEX idx_statut (statut),
    INDEX idx_elu_statut (elu_id, statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bonnes_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    elu_id INT NOT NULL,
    titre VARCHAR(500) NOT NULL,
    description TEXT,
    date_action DATE,
    source_url VARCHAR(500),
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE,
    INDEX idx_elu (elu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS affiliations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    elu_id INT NOT NULL,
    personne_liee_id INT,
    nom_personne VARCHAR(255) NOT NULL,
    type_lien VARCHAR(255),
    emoji VARCHAR(10),
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE,
    FOREIGN KEY (personne_liee_id) REFERENCES elus(id) ON DELETE SET NULL,
    INDEX idx_elu (elu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    elu_id INT NOT NULL,
    sujet VARCHAR(500) NOT NULL,
    position VARCHAR(100),
    date_vote DATE,
    scrutin_id VARCHAR(100),
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE,
    INDEX idx_elu (elu_id),
    INDEX idx_elu_date (elu_id, date_vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS citations_humour (
    id INT AUTO_INCREMENT PRIMARY KEY,
    texte TEXT NOT NULL,
    auteur VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_cache (
    cache_key VARCHAR(255) PRIMARY KEY,
    cache_data LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fetch_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    status ENUM('success', 'error', 'partial') NOT NULL,
    records_count INT DEFAULT 0,
    error_message TEXT,
    duration_ms INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source (source),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash VARCHAR(64) PRIMARY KEY,
    requests INT DEFAULT 1,
    window_start DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Index composites supplémentaires ──
ALTER TABLE elus ADD INDEX IF NOT EXISTS idx_slug (slug);
ALTER TABLE elus ADD INDEX IF NOT EXISTS idx_type_mandat (type_mandat);
ALTER TABLE elus ADD INDEX IF NOT EXISTS idx_parti_dept (parti, departement);
ALTER TABLE mandats ADD INDEX IF NOT EXISTS idx_elu_debut (elu_id, date_debut DESC);
ALTER TABLE votes ADD INDEX IF NOT EXISTS idx_elu_datevote (elu_id, date_vote DESC);
ALTER TABLE bonnes_actions ADD INDEX IF NOT EXISTS idx_elu_date (elu_id, date_action DESC);

-- ============================================
-- v2.1 (avril 2026) — Ajouts apportés en production
-- ============================================

-- ── Colonnes ajoutées à `elus` ──
ALTER TABLE elus ADD COLUMN IF NOT EXISTS alias JSON;
ALTER TABLE elus ADD COLUMN IF NOT EXISTS sexe ENUM('M','F');
ALTER TABLE elus ADD COLUMN IF NOT EXISTS adresse VARCHAR(500);
ALTER TABLE elus ADD COLUMN IF NOT EXISTS email VARCHAR(255);
ALTER TABLE elus ADD COLUMN IF NOT EXISTS telephone VARCHAR(30);
ALTER TABLE elus ADD COLUMN IF NOT EXISTS url_fiche VARCHAR(500);
ALTER TABLE elus ADD COLUMN IF NOT EXISTS url_hatvp VARCHAR(500);
ALTER TABLE elus ADD COLUMN IF NOT EXISTS profession VARCHAR(255);
ALTER TABLE elus ADD COLUMN IF NOT EXISTS population INT;
ALTER TABLE elus ADD COLUMN IF NOT EXISTS salaire_brut DECIMAL(12,2);
ALTER TABLE elus ADD COLUMN IF NOT EXISTS patrimoine_detail JSON;

-- ── Colonne ajoutée à `mandats` ──
ALTER TABLE mandats ADD COLUMN IF NOT EXISTS nb_mandats_poste INT DEFAULT 0;

-- ── Compteur de consultations séparé (évite fragmentation de `elus`) ──
CREATE TABLE IF NOT EXISTS elu_stats (
    elu_id INT PRIMARY KEY,
    nb_consultations INT DEFAULT 0,
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Mapping acteurs Assemblée Nationale → élus ──
CREATE TABLE IF NOT EXISTS an_deputes_mapping (
    acteur_ref VARCHAR(20) PRIMARY KEY,
    elu_id INT NOT NULL,
    nom VARCHAR(255),
    prenom VARCHAR(255),
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE,
    INDEX idx_elu (elu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Activité parlementaire agrégée ──
CREATE TABLE IF NOT EXISTS activite_parlementaire (
    elu_id INT PRIMARY KEY,
    nb_votes INT DEFAULT 0,
    total_scrutins INT DEFAULT 0,
    taux_votes DECIMAL(5,2) DEFAULT 0,
    nb_reunions_present INT DEFAULT 0,
    nb_reunions_convoque INT DEFAULT 0,
    taux_commissions DECIMAL(5,2) DEFAULT 0,
    nb_questions INT DEFAULT 0,
    taux_global DECIMAL(5,2) DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Veille juridique (LegifrSS Atom) ──
CREATE TABLE IF NOT EXISTS veille_juridique (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(100),
    titre VARCHAR(500),
    url VARCHAR(500) UNIQUE,
    nature VARCHAR(100),
    contenu TEXT,
    elu_ids_detectes JSON,
    date_publication DATETIME,
    traite TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (date_publication),
    INDEX idx_traite (traite)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Décisions judiciaires JUDILIBRE (API PISTE) ──
CREATE TABLE IF NOT EXISTS judilibre_decisions (
    id VARCHAR(50) PRIMARY KEY,
    elu_id INT,
    numero VARCHAR(100),
    juridiction VARCHAR(255),
    chambre VARCHAR(255),
    date_decision DATE,
    solution VARCHAR(255),
    themes TEXT,
    resume TEXT,
    texte_extrait TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE SET NULL,
    INDEX idx_elu (elu_id),
    INDEX idx_date (date_decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Inbox mail chiffrée (pipe ProtonMail → webhook-mail.php) ──
CREATE TABLE IF NOT EXISTS inbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_email VARCHAR(255),
    from_name VARCHAR(255),
    subject VARCHAR(500),
    body_encrypted LONGTEXT,
    body_iv VARCHAR(100),
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('new','processed','ignored') DEFAULT 'new',
    agent_summary TEXT,
    agent_action VARCHAR(100),
    processed_at DATETIME,
    INDEX idx_status (status),
    INDEX idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note : `votes_2027` et `visits` restent en fichiers JSON dans api/cache/data/
-- (cf. api/vote2027.php et api/visit.php)

-- ── Seed : Élus ──

INSERT INTO elus (id, nom, parti, fonction, emoji, couleur, patrimoine_info, score_transparence, score_assiduite, score_coherence, score_bilan, slug, source_api) VALUES
(1, 'Marine Le Pen', 'RN', 'Députée du Pas-de-Calais', '🦅', '#1a237e', 'Déclaration HATVP disponible', 3, 7, 6, 4, 'marine-le-pen', 'manual'),
(2, 'Nicolas Sarkozy', 'LR', 'Ancien Président de la République', '🏛️', '#0d47a1', 'Déclaration HATVP disponible', 2, 8, 5, 6, 'nicolas-sarkozy', 'manual'),
(3, 'François Hollande', 'PS', 'Ancien Président / Député', '🌹', '#e91e63', 'Déclaration HATVP disponible', 5, 6, 4, 5, 'francois-hollande', 'manual'),
(4, 'Jean-Luc Mélenchon', 'LFI', 'Député des Bouches-du-Rhône', '✊', '#c62828', 'Déclaration HATVP disponible', 4, 8, 7, 5, 'jean-luc-melenchon', 'manual'),
(5, 'Emmanuel Macron', 'Renaissance', 'Président de la République', '🟡', '#ff8f00', 'Déclaration HATVP disponible', 3, 9, 4, 5, 'emmanuel-macron', 'manual'),
(6, 'Éric Zemmour', 'Reconquête', 'Ancien candidat présidentiel', '⚔️', '#37474f', 'Non déclaré (non élu)', 3, 2, 6, 2, 'eric-zemmour', 'manual'),
(7, 'Édouard Philippe', 'Horizons', 'Maire du Havre / Ancien 1er Ministre', '🌊', '#00838f', 'Déclaration HATVP disponible', 6, 8, 7, 7, 'edouard-philippe', 'manual'),
(8, 'François Fillon', 'LR', 'Ancien Premier Ministre', '🧊', '#283593', 'Déclaration HATVP disponible', 2, 7, 5, 5, 'francois-fillon', 'manual'),
(9, 'Rachida Dati', 'LR / Renaissance', 'Ministre de la Culture', '⚡', '#6a1b9a', 'Déclaration HATVP disponible', 3, 7, 3, 5, 'rachida-dati', 'manual'),
(10, 'Sandrine Rousseau', 'EELV', 'Députée de Paris', '🌿', '#2e7d32', 'Déclaration HATVP disponible', 6, 6, 5, 4, 'sandrine-rousseau', 'manual'),
(11, 'Gérald Darmanin', 'Renaissance', 'Ministre de la Justice', '🛡️', '#e65100', 'Déclaration HATVP disponible', 4, 8, 5, 5, 'gerald-darmanin', 'manual'),
(12, 'François Bayrou', 'MoDem', 'Premier Ministre', '🟠', '#ef6c00', 'Déclaration HATVP disponible', 5, 7, 6, 5, 'francois-bayrou', 'manual');

-- ── Seed : Mandats ──

INSERT INTO mandats (elu_id, titre, date_debut, date_fin, institution) VALUES
(1, 'Députée du Pas-de-Calais', '2012-06-01', NULL, 'Assemblée nationale'),
(1, 'Députée européenne', '2004-06-01', '2017-06-01', 'Parlement européen'),
(2, 'Président de la République', '2007-05-16', '2012-05-15', 'Élysée'),
(2, 'Ministre de l''Intérieur', '2002-05-07', '2007-03-26', 'Gouvernement'),
(3, 'Président de la République', '2012-05-15', '2017-05-14', 'Élysée'),
(3, 'Député de Corrèze', '1997-06-01', '2012-05-15', 'Assemblée nationale'),
(3, 'Député', '2024-07-01', NULL, 'Assemblée nationale'),
(4, 'Député des Bouches-du-Rhône', '2017-06-01', NULL, 'Assemblée nationale'),
(4, 'Sénateur', '1986-01-01', '2000-01-01', 'Sénat'),
(5, 'Président de la République', '2017-05-14', NULL, 'Élysée'),
(5, 'Ministre de l''Économie', '2014-08-26', '2016-08-30', 'Gouvernement'),
(7, 'Premier ministre', '2017-05-15', '2020-07-03', 'Gouvernement'),
(7, 'Maire du Havre', '2010-03-01', '2017-05-15', 'Mairie du Havre'),
(7, 'Maire du Havre', '2020-07-04', NULL, 'Mairie du Havre'),
(8, 'Premier ministre', '2007-05-17', '2012-05-10', 'Gouvernement'),
(8, 'Député de Paris', '2012-06-01', '2017-06-01', 'Assemblée nationale'),
(9, 'Ministre de la Culture', '2024-01-01', NULL, 'Gouvernement'),
(9, 'Garde des Sceaux', '2007-05-18', '2009-06-23', 'Gouvernement'),
(9, 'Maire du 7e arrondissement', '2008-03-01', '2024-01-01', 'Mairie de Paris'),
(10, 'Députée de Paris', '2022-06-01', NULL, 'Assemblée nationale'),
(11, 'Ministre de la Justice', '2024-01-01', NULL, 'Gouvernement'),
(11, 'Ministre de l''Intérieur', '2020-07-06', '2024-01-01', 'Gouvernement'),
(11, 'Maire de Tourcoing', '2014-03-01', '2020-07-06', 'Mairie de Tourcoing'),
(12, 'Premier ministre', '2024-12-13', NULL, 'Gouvernement'),
(12, 'Maire de Pau', '2014-03-01', NULL, 'Mairie de Pau'),
(12, 'Ministre de l''Éducation nationale', '1993-03-29', '1997-06-02', 'Gouvernement');

-- ── Seed : Affaires ──

INSERT INTO affaires (elu_id, titre, description, statut, date_debut, gravite) VALUES
(1, 'Emplois fictifs PE', 'Détournement de fonds publics — condamnée en première instance', 'condamne', '2024-01-01', 4),
(1, 'Prêt russe au FN', 'Financement du parti par une banque russe', 'en_cours', '2014-01-01', 3),
(2, 'Affaire Bygmalion', 'Financement illégal de campagne — 1 an ferme', 'condamne', '2021-01-01', 5),
(2, 'Affaire des écoutes', 'Corruption et trafic d''influence — 3 ans dont 1 ferme', 'condamne', '2021-01-01', 5),
(2, 'Affaire Libyenne', 'Financement libyen présumé de la campagne 2007', 'en_cours', '2013-01-01', 4),
(4, 'Perquisition LFI', 'Rébellion lors d''une perquisition au siège de LFI', 'relaxe', '2019-01-01', 2),
(5, 'Affaire McKinsey', 'Recours massif à des cabinets de conseil — enquête du Sénat', 'en_cours', '2022-01-01', 3),
(5, 'Affaire Uber Files', 'Liens privilégiés avec Uber quand ministre de l''Économie', 'en_cours', '2022-01-01', 3),
(6, 'Condamnation incitation haine', 'Provocation à la haine raciale — multiples condamnations', 'condamne', '2022-01-01', 4),
(8, 'Penelopegate', 'Emplois fictifs de Penelope Fillon — 5 ans dont 2 ferme', 'condamne', '2020-01-01', 5),
(8, 'Costumes de luxe', 'Acceptation de costumes offerts par un avocat', 'classe', '2017-01-01', 2),
(9, 'Affaire Ghosn/Renault', 'Soupçons de corruption passive — mise en examen', 'en_cours', '2020-01-01', 4),
(11, 'Accusations de viol', 'Plainte classée sans suite puis relancée — non-lieu', 'classe', '2018-01-01', 3),
(12, 'Emplois fictifs MoDem', 'Assistants parlementaires européens — relaxé en première instance', 'relaxe', '2024-01-01', 3);

-- ── Seed : Bonnes actions ──

INSERT INTO bonnes_actions (elu_id, titre) VALUES
(1, 'Mobilisation contre la hausse du prix de l''énergie'),
(2, 'Plan de relance 2008 crise financière'),
(3, 'Mariage pour tous'),
(3, 'Accord de Paris COP21'),
(4, 'Promotion de la 6ème République'),
(4, 'Lutte précarité énergétique'),
(5, 'Plan France 2030'),
(5, 'Service National Universel'),
(7, 'Gestion Covid 1ère vague'),
(7, 'Réforme fonction publique'),
(8, 'Réforme des retraites 2010'),
(9, 'Réforme de la carte judiciaire'),
(10, 'Lutte contre les violences sexistes'),
(11, 'Lutte contre le narcotrafic'),
(11, 'OQTF renforcées'),
(12, 'Plan lecture scolaire'),
(12, 'Centrisme et dialogue');

-- ── Seed : Affiliations ──

INSERT INTO affiliations (elu_id, nom_personne, type_lien, emoji) VALUES
(1, 'Jordan Bardella', 'Président du RN', '🤝'),
(1, 'Louis Aliot', 'Vice-président du RN', '🔗'),
(2, 'Brice Hortefeux', 'Proche historique', '🤝'),
(2, 'Claude Guéant', 'Ancien dir. cabinet', '🔗'),
(3, 'Julie Gayet', 'Conjointe', '💑'),
(3, 'Manuel Valls', 'Ancien 1er ministre', '🔗'),
(4, 'Mathilde Panot', 'Présidente groupe LFI', '🤝'),
(4, 'Manuel Bompard', 'Coordinateur LFI', '🔗'),
(5, 'Brigitte Macron', 'Conjointe', '💑'),
(5, 'Gabriel Attal', 'Ancien 1er ministre', '🤝'),
(6, 'Marion Maréchal', 'Ex vice-présidente', '🔗'),
(6, 'Sarah Knafo', 'Compagne', '💑'),
(7, 'Emmanuel Macron', 'Président', '🤝'),
(7, 'Gérald Darmanin', 'Allié politique', '🔗'),
(8, 'Penelope Fillon', 'Conjointe', '💑'),
(8, 'Bruno Retailleau', 'Soutien politique', '🔗'),
(9, 'Nicolas Sarkozy', 'Mentor politique', '🤝'),
(9, 'Emmanuel Macron', 'Président', '🔗'),
(10, 'Yannick Jadot', 'Ex-rival primaire', '🔗'),
(10, 'Marine Tondelier', 'Secrétaire nationale EELV', '🤝'),
(11, 'Emmanuel Macron', 'Président', '🤝'),
(11, 'Nicolas Sarkozy', 'Ancien mentor', '🔗'),
(12, 'Emmanuel Macron', 'Président', '🤝'),
(12, 'Marielle de Sarnez', 'Bras droit historique (†)', '🕊️');

-- ── Seed : Votes ──

INSERT INTO votes (elu_id, sujet, position) VALUES
(1, 'Réforme des retraites 2023', 'Contre'),
(1, 'Loi immigration 2023', 'Pour'),
(3, 'Loi Travail (El Khomri)', 'Pour (49.3)'),
(3, 'Mariage pour tous', 'Pour'),
(4, 'Réforme des retraites 2023', 'Contre'),
(4, 'Loi immigration 2023', 'Contre'),
(10, 'Réforme des retraites 2023', 'Contre'),
(10, 'Loi immigration 2023', 'Contre');

-- ── Seed : Citations ──

INSERT INTO citations_humour (texte, auteur) VALUES
('Les hommes politiques, il y en a, pour briller en société, ils mangeraient du cirage.', 'Coluche'),
('La politique, c''est pas compliqué, il suffit d''avoir une bonne conscience, et pour ça il faut avoir une mauvaise mémoire.', 'Coluche'),
('C''est pas parce qu''ils sont nombreux à avoir tort qu''ils ont raison.', 'Coluche'),
('Un homme politique, c''est un homme qui vous demande de lui donner votre voix. C''est déjà suspect.', 'Coluche'),
('La moitié des hommes politiques sont des bons à rien. Les autres sont prêts à tout.', 'Coluche'),
('Dieu a dit : « Il faut partager. » Les riches auront la nourriture, les pauvres de l''appétit.', 'Coluche'),
('Vivons heureux en attendant la mort.', 'Pierre Desproges'),
('On peut rire de tout, mais pas avec n''importe qui.', 'Pierre Desproges'),
('L''homme politique est un acrobate : il se maintient en équilibre en disant le contraire de ce qu''il fait.', 'Pierre Desproges'),
('En politique, on succède à des imbéciles et on est remplacé par des incapables.', 'Georges Clemenceau'),
('La politique, c''est l''art d''empêcher les gens de se mêler de ce qui les regarde.', 'Paul Valéry'),
('Un politicien ne pourrait pas exister s''il avait un miroir à la place de la conscience.', 'Guy Bedos'),
('Les promesses des hommes politiques n''engagent que ceux qui les écoutent.', 'Henri Queuille'),
('Je ne fais pas de politique. C''est comme l''andouillette, ça sent la merde et c''est toujours les mêmes qui en redemandent.', 'Michel Audiard'),
('Les cons, ça ose tout. C''est même à ça qu''on les reconnaît.', 'Michel Audiard'),
('La démocratie, c''est cinq minutes dans l''isoloir et cinq ans les doigts dans le nez.', 'Thierry Le Luron'),
('La France est un pays extrêmement fertile : on y plante des fonctionnaires et il y pousse des impôts.', 'Georges Clemenceau'),
('Être de gauche ou de droite, c''est comme être d''accord ou pas d''accord… avec personne.', 'Raymond Devos'),
('Heureux les fêlés, car ils laisseront passer la lumière.', 'Michel Audiard'),
('Si les Français savaient le rôle que joue l''argent en politique, la porte des prisons ne serait pas assez grande.', 'Honoré de Balzac'),
('Il n''y a pas de rapport entre ce que les hommes politiques disent et ce qu''ils pensent.', 'André Malraux'),
('La politique, c''est comme le hockey : on finit toujours à la bande.', 'Pierre Dac'),
('L''homme politique pense aux prochaines élections, l''homme d''État à la prochaine génération.', 'Winston Churchill');
