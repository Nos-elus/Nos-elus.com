# Sources de données — nos-elus.fr

## Sources actives (scripts d'enrichissement existants)

### Élus nationaux

| Source | URL | Données | Script | Élus ciblés |
|--------|-----|---------|--------|-------------|
| **Assemblée nationale (Open Data)** | https://data.assemblee-nationale.fr/acteurs/deputes-en-exercice | Photo HD, date/lieu naissance, profession, mandats, lien HATVP | `enrich-from-an-opendata.php` | Députés |
| **NosDéputés.fr** | https://www.nosdeputes.fr/api | Photo, mandats, groupe politique, activité | `fetcher/NosDeputesFetcher.php` | Députés (législature en cours) |
| **Sénat.fr** | https://www.senat.fr/senateurs/senatl.html | Photos officielles | `enrich-photos-senat.php` | Sénateurs |
| **Parlement européen** | https://data.europarl.europa.eu/api/v2/meps | Photo, date/lieu naissance, groupe politique, mandats | `enrich-europarl.php` | Eurodéputés |
| **Parlement européen (directory)** | https://www.europarl.europa.eu/meps/en/directory/xml/ | Liste complète des MEPs (nom + ID) | `enrich-europarl.php` | Eurodéputés |

### Élus locaux

| Source | URL | Données | Script | Élus ciblés |
|--------|-----|---------|--------|-------------|
| **Répertoire National des Élus (RNE)** | https://www.data.gouv.fr/fr/datasets/repertoire-national-des-elus-1/ | Nom, prénom, date naissance, mandats, commune, département | Import batch initial | Tous élus locaux |
| **API Geo (INSEE)** | https://geo.api.gouv.fr/ | Population commune → calcul salaire maire | `enrich-salaires-maires.php` | Maires |
| **Wikidata** | https://www.wikidata.org/w/api.php | Bio, photo, date naissance, mandats historiques | `enrich-wikidata-full.php`, `enrich-maires-anciennete.php`, `enrich-on-demand.php` | Tous (personnalités notoires) |

### Patrimoine & transparence

| Source | URL | Données | Script |
|--------|-----|---------|--------|
| **HATVP** | https://www.hatvp.fr/ | Déclarations de patrimoine et d'intérêts | Lien intégré via AN Open Data (`uri_hatvp`) |

### Gouvernement

| Source | URL | Données | Notes |
|--------|-----|---------|-------|
| **info.gouv.fr** | https://www.info.gouv.fr/composition-du-gouvernement | Composition gouvernement, photos, fonctions | Protégé Cloudflare — enrichissement manuel |

## Sources à explorer (pas encore de script)

| Source | URL | Données potentielles | Priorité |
|--------|-----|---------------------|----------|
| **HATVP API** | https://www.hatvp.fr/open-data/ | Patrimoine déclaré officiel (immobilier, revenus, participations) | Haute |
| **Europarl déclarations** | https://www.europarl.europa.eu/meps/fr/declarations | Déclarations d'intérêts financiers eurodéputés (PDF) | Moyenne |
| **Sénat Open Data** | https://data.senat.fr/ | Activité sénateurs, votes, questions | Moyenne |
| **la-mairie.com** | https://www.la-mairie.com/ | Infos maires petites communes | Basse |
| **Conseils régionaux** | Sites individuels des régions | Photos/bios conseillers régionaux | Basse |

## Sites départementaux scrapés (photos conseillers départementaux)

### Scrapés avec succès

| Dept | URL | Élus importés | Date |
|------|-----|--------------|------|
| 02 | https://www.aisne.fr/elus/ | 1 | 2026-03-28 |
| 05 | https://www.hautes-alpes.fr/elus/ | 28 | 2026-03-28 |
| 06 | https://www.departement06.fr/les-elus/ | 2 | 2026-03-28 |
| 07 | https://www.ardeche.fr/elus/ | 10 | 2026-03-27 |
| 09 | https://www.ariege.fr/elus/ | 26 | 2026-03-28 |
| 10 | https://www.aube.fr/elus/ | 2 | 2026-03-27 |
| 11 | https://www.aude.fr/les-elus/ | 13 | 2026-03-27 |
| 14 | https://calvados.fr/elus/ | 24 | 2026-03-27 |
| 15 | https://www.cantal.fr/elus/ | 2 | 2026-03-27 |
| 18 | https://www.departement18.fr/elus/ | 3 | 2026-03-28 |
| 19 | https://www.correze.fr/elus/ | 1 | 2026-03-27 |
| 25 | https://www.cd25.fr/elus/ | 11 | 2026-03-27 |
| 26 | https://www.drome.fr/elus/ | 4 | 2026-03-28 |
| 30 | https://www.cd30.fr/elus/ | 1 | 2026-03-28 |
| 31 | https://www.haute-garonne.fr/elus/ | 1 | 2026-03-27 |
| 33 | https://www.gironde.fr/elus/ | 12 | 2026-03-27 |
| 34 | https://www.herault.fr/elus/ | 8 | 2026-03-27 |
| 38 | https://www.isere.fr/elus/ | 58 | 2026-03-27 |
| 39 | https://www.le39.fr/elus/ | 2 | 2026-03-28 |
| 40 | https://www.cd40.fr/elus/ | 13 | 2026-03-27 |
| 41 | https://www.departement41.fr/elus/ | 6 | 2026-03-28 |
| 43 | https://www.haute-loire.fr/elus/ | 2 | 2026-03-28 |
| 45 | https://www.loiret.fr/elus/ | 1 | 2026-03-28 |
| 46 | https://www.lot.fr/elus/ | 1 | 2026-03-27 |
| 47 | https://www.le47.fr/elus/ | 1 | 2026-03-28 |
| 48 | https://www.lozere.fr/elus/ | 1 | 2026-03-27 |
| 49 | https://www.maine-et-loire.fr/elus/ | 1 | 2026-03-27 |
| 50 | https://www.manche.fr/elus/ | 2 | 2026-03-27 |
| 52 | https://www.haute-marne.fr/elus/ | 2 | 2026-03-27 |
| 56 | https://www.morbihan.fr/elus/ | 1 | 2026-03-27 |
| 58 | https://www.nievre.fr/conseil-departemental/les-elus/ | 8 | 2026-03-27 |
| 67 | https://www.bas-rhin.fr/elus/ | 6 | 2026-03-27 |
| 68 | https://www.haut-rhin.fr/elus/ | 6 | 2026-03-27 |
| 70 | https://www.haute-saone.fr/elus/ | 2 | 2026-03-27 |
| 74 | https://www.cd74.fr/elus/ | 1 | 2026-03-28 |
| 77 | https://www.seine-et-marne.fr/elus/ | 47 | 2026-03-27 |
| 78 | https://www.yvelines.fr/elus/ | 36 | 2026-03-27 |
| 79 | https://www.deux-sevres.fr/les-elus/ | 3 | 2026-03-27 |
| 85 | https://www.vendee.fr/ (photos manuelles) | 33 | 2026-03-28 |
| 86 | https://www.vienne.fr/elus/ | 3 | 2026-03-27 |
| 87 | https://www.haute-vienne.fr/votre-conseil-departemental/les-conseillers-departementaux | 42 (photos manuelles) | 2026-03-28 |
| 88 | https://www.vosges.fr/mon-departement/le-conseil-departemental/elu-e-s/ | 30 | 2026-03-28 |
| 89 | https://www.yonne.fr/conseil-departemental/les-elus/ | 2 | 2026-03-27 |
| 90 | https://www.territoiredebelfort.fr/elus + /annuaire-des-personnes/{slug} | 18 (photos manuelles) | 2026-03-28 |
| 91 | https://www.essonne.fr/le-departement/fonctionnement-du-departement/lassemblee-departementale | 30 | 2026-03-28 |
| 95 | https://www.valdoise.fr/elus/ | 15 | 2026-03-27 |

### IDF (Île-de-France — conseillers régionaux)

| Dept | URL | Élus importés |
|------|-----|--------------|
| 75-95 | https://www.iledefrance.fr/annuaire-des-elus?f[0]=department:{Dept} | 102 total |
| 94 | Photos manuelles Val-de-Marne | 19 |

### CDG (centres de gestion — maires)

| Dept | URL | Élus importés |
|------|-----|--------------|
| 84 | https://www.cdg84.fr/le-cdg84/le-conseil-dadministration/les-elus/ | 6 |

### Non scrapables (Cloudflare / JS / bloqués)

01 Ain, 03 Allier, 04 Alpes-de-Haute-Provence, 08 Ardennes, 12 Aveyron, 13 Bouches-du-Rhône, 16 Charente, 17 Charente-Maritime, 21 Côte-d'Or, 22 Côtes-d'Armor, 23 Creuse, 24 Dordogne, 27 Eure, 28 Eure-et-Loir, 29 Finistère, 32 Gers, 35 Ille-et-Vilaine, 36 Indre, 37 Indre-et-Loire, 42 Loire, 44 Loire-Atlantique, 51 Marne, 53 Mayenne, 54 Meurthe-et-Moselle, 55 Meuse, 57 Moselle, 59 Nord, 60 Oise, 61 Orne, 62 Pas-de-Calais, 64 Pyrénées-Atlantiques, 65 Hautes-Pyrénées, 66 Pyrénées-Orientales, 69 Rhône, 71 Saône-et-Loire, 72 Sarthe, 73 Savoie, 76 Seine-Maritime, 80 Somme, 81 Tarn, 82 Tarn-et-Garonne, 83 Var, 84 Vaucluse, 92 Hauts-de-Seine, 93 Seine-Saint-Denis

### Résultats élections (mise à jour maires)

| Source | URL | Données | Priorité |
|--------|-----|---------|----------|
| **Résultats municipales 2026** | https://www.resultats-elections.interieur.gouv.fr/municipales2026/ | Nouveaux maires élus mars 2026 | **Haute** — dès que le site sort de maintenance |
| **data.gouv.fr résultats** | https://www.data.gouv.fr/ (chercher "municipales 2026") | Résultats structurés JSON/CSV | **Haute** |

## Règles d'enrichissement

1. Les élus `source_api = 'manual'` ne sont JAMAIS écrasés par les scripts batch
2. Les dates Wikidata < 1900 sont rejetées (données historiques erronées)
3. Rate limit : 500ms entre chaque appel Wikidata, 300ms pour Europarl
4. L'enrichissement on-demand (`enrich-on-demand.php`) se déclenche à la consultation d'un profil incomplet
5. Lock file 1 semaine pour éviter le re-enrichissement trop fréquent
