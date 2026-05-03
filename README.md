# nos-elus.com

> Plateforme citoyenne pour rechercher un élu (ou ex-élu) de la République et consulter de manière transparente : ses affaires judiciaires, ses bons points, son réseau politique, ses votes clés, son activité parlementaire et son patrimoine déclaré.

**Site** : [https://nos-elus.com](https://nos-elus.com)
**Licence** : CC BY-NC-SA 4.0

---

## Pourquoi ce repo est public

Ce code est ouvert pour que **chacun puisse vérifier** :
- D'où viennent les données affichées (sources publiques uniquement)
- Comment sont calculés les scores, indemnités et indicateurs (formules en clair)
- Que rien n'est inventé, pondéré arbitrairement ou maquillé

Les chiffres affichés sur le site sont reproductibles à partir des sources officielles.

---

## Stack

- **Frontend** : React 18 + Vite (SPA)
- **Backend** : PHP 8.x natif (API REST, PDO)
- **Base** : MySQL 8 / MariaDB
- **Charts** : Recharts (lazy-loaded)

Pas de framework backend, pas de dépendance lourde côté serveur — c'est volontaire pour rester auditable et déployable sur n'importe quel hébergement PHP standard.

---

## Sources de données

Toutes les données affichées proviennent de sources publiques officielles ou ouvertes. Aucune donnée privée, aucune source confidentielle.

### Élus, mandats, indemnités

| Source | Données récupérées |
|--------|---------------------|
| [Répertoire National des Élus (RNE)](https://www.data.gouv.fr/fr/datasets/repertoire-national-des-elus-1/) | ~500k élus locaux et nationaux : nom, mandat, dates, commune |
| [RNE enrichi nuance politique (data.gouv.fr)](https://www.data.gouv.fr/fr/datasets/communes-enrichies-avec-la-nuance-politique-france/) | Étiquette politique des communes |
| [Annuaire de l'administration (api-lannuaire.service-public.fr)](https://api-lannuaire.service-public.fr/) | Coordonnées officielles des mairies, préfectures, sous-préfectures |

### Activité parlementaire

| Source | Données récupérées |
|--------|---------------------|
| [data.assemblee-nationale.fr](https://data.assemblee-nationale.fr/) | Députés actifs (CSV), scrutins publics (JSON), questions écrites, agenda des commissions |
| [NosDéputés.fr](https://www.nosdeputes.fr/api) | Recherche de députés (fallback DataFetcher) |
| [Parlement européen — europarl.europa.eu](https://www.europarl.europa.eu/meps/fr/full-list/xml) | Liste des eurodéputés français + photos officielles (`/mepphoto/<id>.jpg`) |

### Patrimoine, transparence, justice

| Source | Données récupérées |
|--------|---------------------|
| [HATVP open data](https://www.hatvp.fr/open-data/) | Déclarations de patrimoine et d'intérêts (CSV `liste.csv`) |
| [JUDILIBRE / PISTE (Cour de cassation)](https://piste.gouv.fr/) | Décisions de la Cour de cassation publiées |
| [DILA — Open Data Conseil constitutionnel](https://echanges.dila.gouv.fr/OPENDATA/CONSTIT/) | Décisions du Conseil constitutionnel |
| [LegifrSS](https://legifrss.org/) | Veille juridique (lois, décisions, Conseil constitutionnel) |

### Résultats électoraux

| Source | Données récupérées |
|--------|---------------------|
| [Élections municipales 2020 (data.gouv.fr)](https://www.data.gouv.fr/fr/datasets/elections-municipales-2020-resultats/) | Résultats par commune (CSV) |
| [Élections municipales 2026 (data.gouv.fr)](https://www.data.gouv.fr/fr/datasets/elections-municipales-2026-resultats-du-premier-tour/) | Résultats 1er et 2nd tour |

### Compléments biographiques et iconographiques

| Source | Données récupérées |
|--------|---------------------|
| [Wikipédia FR (API REST)](https://fr.wikipedia.org/api/rest_v1/) | Résumés biographiques, dates, alias |
| [Wikidata / Wikimedia Commons](https://www.wikidata.org/) | Photos sous licences libres, identifiants persistants |

### Notification de référencement

| Source | Usage |
|--------|-------|
| [IndexNow (api.indexnow.org)](https://www.indexnow.org/) | Notification proactive de nouvelles fiches à Bing / Yandex |

### Sources écartées ou non utilisées

- **data.senat.fr** : non exploitée à ce jour (aucun scrutin SENAT_ en BDD). Une intégration nécessiterait un mapping similaire à `an_deputes_mapping`.
- **Pappers, mon-maire.fr et sites de recopiage** : non autorisés comme source primaire (corroboration uniquement).
- **Wikipédia comme source unique** : interdite — toujours croisée avec une source officielle.

Voir [`docs/sources-donnees.md`](docs/sources-donnees.md) pour le détail des champs et fréquence de mise à jour.

---

## Formules et méthodes de calcul

### Taux d'activité parlementaire

Le score d'assiduité (0-10) reflète la **participation aux scrutins publics** de la chambre où siège l'élu, sur les périodes où il était effectivement en fonction.

**Formule (ratio direct, sans pondération arbitraire) :**
```
taux_global    = (nb_votes + nb_reunions_present)
               / (total_scrutins + nb_reunions_convoque) × 100
score_assiduite = round(taux_global / 10, 1)   # borné [0, 10]
```

**Périmètre du dénominateur (`total_scrutins`)** :
- Scrutins publics de la chambre concernée (Assemblée nationale ou Parlement européen)
- Restreints aux périodes de mandat parlementaire
- **Moins** les périodes ministérielles concomitantes (le siège est alors occupé par un suppléant, l'élu titulaire ne peut pas voter)

**Sources de données :**
- Assemblée nationale : `data.assemblee-nationale.fr` (open data des scrutins)
- Parlement européen : `europarl.europa.eu`

**Limites actuelles :**
- Sénateurs : pas de source de scrutins exploitée à ce jour → score laissé à la valeur par défaut, non recalculé.
- Absences justifiées (commission d'enquête, mission temporaire, congé maladie/maternité) : actuellement comptées comme absences. L'API AN les distingue ("Non-votant"), à intégrer dans un prochain import.
- Présence en commission et questions écrites : colonnes BDD prêtes (`nb_reunions_*`, `nb_questions`) mais pas encore peuplées par un cron dédié. Quand ces données arriveront, la formule du `taux_global` les intégrera automatiquement (ratio direct).

Le calcul est exécuté par `api/cron-taux-presence.php`.

### Indemnités d'élus (grille 2025)

Issue de la grille officielle indemnitaire — voir `api/calcul-cout.php` :

| Fonction | Indemnité brute mensuelle |
|----------|---------------------------|
| Président de la République | 16 039 € |
| Premier ministre | 16 038 € |
| Ministre / Garde des Sceaux / Secrétaire d'État | 10 692 € |
| Député / Sénateur | ~7 200 € + frais |
| Maire (variable selon population INSEE) | 1 048 € à 5 706 € |
| Conseiller régional / départemental | variable selon collectivité |

Le coût carrière = somme(indemnité × durée de chaque mandat) sur l'historique connu.

### Statuts judiciaires

Les affaires sont classées strictement selon la terminologie juridique. Valeurs autorisées (ENUM BDD `affaires.statut`) :
- **en_cours** : procédure en cours, pas de jugement
- **condamne** : condamnation prononcée (préciser dans la description : définitive ou en appel)
- **relaxe** : relaxe prononcée
- **classe** : classement sans suite

Chaque affaire **doit** avoir une URL source vérifiable. La présomption d'innocence est respectée dans le wording.

### Scores affichés

Le projet **ne porte pas de jugement moral** sur les élus. Aucune notation d'« intégrité » n'est calculée, ni affichée — la base ne pénalise personne par algorithme. Les affaires judiciaires sont **listées factuellement** avec leur statut juridique (`affaires.statut`), et c'est au visiteur de juger.

Quatre indicateurs sont prévus en BDD (`elus.score_*`, échelle 0-10) :

| Score | État actuel | Détail |
|---|---|---|
| **Assiduité** | ✅ Calculé | Taux d'activité parlementaire (formule ci-dessus). Recalculé par `cron-taux-presence.php`. |
| **Transparence** | ⏳ Non calculé | Champ présent en BDD, valeur par défaut 5/10. Algorithme à implémenter (signal envisagé : présence d'une déclaration HATVP, complétude du profil). |
| **Cohérence** | ⏳ Non calculé | Champ présent en BDD, valeur par défaut 5/10. Algorithme à définir. |
| **Bilan** | ⏳ Non calculé | Champ présent en BDD, valeur par défaut 5/10. Algorithme à définir. |

Tant qu'un score n'est pas calculé, il reste à 5/10 sur toute la base — il n'est donc **pas pertinent** de l'afficher comme une mesure différenciante. Les classements et palmarès n'utilisent que le score d'assiduité aujourd'hui.

### Vote citoyen (👍 / 👎)

Chaque visiteur peut voter pour ou contre un élu. Code complet : `api/vote.php` + `nos-elus-front/src/components/VoteCitoyen.jsx`.

**Règles** :
- **1 vote par fiche par visiteur** — vote remplaçable (changer d'avis ou retirer)
- **Aucun compte, aucune inscription, aucun cookie** — la fiche est notée par n'importe qui sans authentification
- Identité du votant = `SHA256(IP + sel)` — l'IP brute n'est **jamais** stockée, seulement le hash. Le sel applicatif rend les hashs incomparables d'un déploiement à l'autre
- **Synchronisation côté navigateur** via `localStorage` (clé `noselus_votes`) pour afficher l'état du vote sans rappel serveur, sans cookie

**Anti-spam / anti-bot** :
- Rate limit : **10 votes / minute** par hash IP
- Origin CORS strictement restreint au domaine officiel
- Pas de tracking secondaire (pas d'analytics, pas de fingerprint navigateur)

**Stockage** :
- Backend = fichier JSON `cache/data/votes_citoyens.json` avec `flock()` pour les écritures concurrentes (pas une table relationnelle, volontairement simple et auditable)
- Format : `{ "<hashIP>_<elu_id>": { "elu_id": N, "vote": 1|-1, "ts": ... } }`

**Pondération dans les classements** : aucune. Les votes citoyens sont **purement informatifs** sur la fiche de l'élu. Ils n'influencent pas les classements automatiques (top_assidus, top_cout, top_casseroles, etc.) qui restent calculés exclusivement à partir des sources officielles.

---

## Structure

```
nos-elus/
├── nos-elus-front/    # SPA React (pages, composants, hooks)
│   └── src/
│       ├── pages/     # 13 pages (Home, EluProfile, Comparator, MatchMaker,
│       │                 Palmares, Presidentielle2027, About, Contact, etc.)
│       ├── components/# 21 composants (SlotMachine, RadarProfile, BoussolePolique,
│       │                 KarmaGauge, Timeline, etc.)
│       └── styles/
│
├── api/               # Backend PHP — endpoints REST
│   ├── search.php elu.php elus.php affaires.php votes.php compare.php
│   ├── matchmaker.php palmares.php stats.php quote.php random.php
│   ├── vote.php vote2027.php contact.php visit.php
│   ├── calcul-cout.php normalize-parti.php enrich-on-demand.php
│   ├── og-image.php og-meta.php          # SEO + bots sociaux
│   └── fetcher/                          # Wrappers APIs externes
│
├── scripts/           # Scripts d'import / enrichissement (CLI)
├── docs/              # Sources de données détaillées
└── schema.sql         # Schéma MySQL de base
```

---

## Développement local

```bash
# Frontend (dev server avec HMR)
cd nos-elus-front
npm install
npm run dev    # http://localhost:5173

# Frontend (build production)
npm run build  # dist/

# Backend (serveur PHP local — proxifié par Vite)
cd ..
php -S localhost:8080
```

Configurer `api/config.php` (non versionné) avec les credentials BDD locaux. Importer `schema.sql` dans une base MySQL/MariaDB.

---

## Règles éditoriales

- Chaque affaire **doit** avoir une URL source vérifiable
- Distinguer clairement : **mis en examen / condamné / relaxé / classé sans suite**
- Pas d'opinion : uniquement des faits sourcés
- Les bons points (`bonnes_actions`) doivent aussi être sourcés
- Respecter la présomption d'innocence dans le wording

---

## Contribuer

Les corrections de données (erreurs factuelles, nouvelles affaires, sources actualisées) sont les bienvenues via [issues](../../issues). Les pull requests sur le code sont également ouvertes.

---

*Aucun élu n'a été maltraité durant la création de ce projet.*
