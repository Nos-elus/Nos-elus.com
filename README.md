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

Toutes les données affichées proviennent de sources publiques officielles :

| Source | Données |
|--------|---------|
| [Répertoire National des Élus](https://www.data.gouv.fr/fr/datasets/repertoire-national-des-elus-1/) | ~500k élus locaux et nationaux |
| [data.assemblee-nationale.fr](https://data.assemblee-nationale.fr/) | Députés, votes, scrutins, mandats |
| [data.senat.fr](https://data.senat.fr/) | Sénateurs, commissions |
| [NosDéputés.fr](https://www.nosdeputes.fr/api) | Activité parlementaire des députés |
| [HATVP](https://www.hatvp.fr/open-data/) | Déclarations de patrimoine et d'intérêts |
| [Parlement européen Open Data](https://data.europarl.europa.eu/) | Eurodéputés, votes UE |
| [JUDILIBRE (PISTE)](https://piste.gouv.fr/) | Décisions de justice publiées |
| [Wikidata / Wikimedia Commons](https://www.wikidata.org/) | Photos sous licences libres, biographies |
| [api-geo.gouv.fr (INSEE)](https://geo.api.gouv.fr/) | Population des communes (calcul indemnités) |

Voir [`docs/sources-donnees.md`](docs/sources-donnees.md) pour le détail.

---

## Formules et méthodes de calcul

### Taux d'activité parlementaire (députés)

Pondération composite officielle :
```
taux_activite = (votes / total_scrutins) × 50%
              + (commissions / max_commissions) × 35%
              + (questions / max_questions) × 15%
```

Source des chiffres : `data.assemblee-nationale.fr` + `nosdeputes.fr/api`. Le diviseur est le maximum constaté sur la législature en cours, recalculé à chaque mise à jour.

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

Les affaires sont classées strictement selon la terminologie juridique :
- **mis_en_examen** : mise en examen prononcée
- **en_cours** : procédure en cours, pas de jugement
- **condamne** : condamnation prononcée (préciser : définitive ou en appel)
- **relaxe** : relaxe prononcée
- **classe** : classement sans suite

Chaque affaire **doit** avoir une URL source vérifiable. La présomption d'innocence est respectée dans le wording.

### Scores affichés

Quatre indicateurs sur 5 :
- **Intégrité** : pénalisé par les affaires condamnées (pondéré par gravité)
- **Transparence** : présence des déclarations HATVP, complétude du profil public
- **Assiduité** : taux d'activité parlementaire (formule ci-dessus, élus locaux : taux de présence aux délibérations si connu)
- **Cohérence** : analyse des votes vs étiquette politique annoncée

Tous les coefficients sont en clair dans `api/calcul-cout.php` et `api/palmares.php`.

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
