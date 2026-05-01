<?php
/**
 * CRON — Import des activités publiques rémunérées depuis HATVP
 * Source : hatvp.fr/livraison/merge/declarations.xml (78MB, fichier fusionné)
 *
 * Structure XML réelle (après analyse) :
 *   <declaration>
 *     <general>
 *       <declarant><nom>, <prenom></declarant>
 *       <qualiteMandat><codTypeMandatFichier>depute</codTypeMandatFichier></qualiteMandat>
 *     </general>
 *     <activProfCinqDerniereDto>   ← activités pro (dont emplois publics en cours)
 *       <items><items>
 *         <description>, <employeur>
 *         <remuneration><montant><montant><annee>, <montant> (€/an réel)
 *         <dateDebut> (MM/YYYY), <dateFin> (MM/YYYY ou vide)
 *       </items></items>
 *     </activProfCinqDerniereDto>
 *     <mandatElectifDto>            ← mandats électoraux (SIVU, EPCI, etc.)
 *       <items><items>
 *         <descriptionMandat>, <remuneration>..., <dateDebut>, <dateFin>
 *       </items></items>
 *     </mandatElectifDto>
 *   </declaration>
 *
 * Montants : valeurs réelles €/an (pas des fourchettes) → divisé par 12 = €/mois
 *
 * Usage :
 *   php cron-hatvp-activites.php            → tous les parlementaires actifs
 *   php cron-hatvp-activites.php --test     → 5 premiers seulement
 *   php cron-hatvp-activites.php --slug=X   → un seul élu (slug BDD)
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); exit('Forbidden');
}

ini_set('max_execution_time', 0);
ini_set('memory_limit', '128M');

$testMode = in_array('--test', $argv ?? []);
$testSlug = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--slug=')) $testSlug = substr($arg, 7);
}

function logLine(string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . "\n"; }
logLine('=== HATVP ACTIVITES — ' . date('Y-m-d H:i:s') . ' ===');

// ── 1. Créer les tables / colonnes si absentes ────────────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS activites_publiques (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    elu_id               INT NOT NULL,
    titre                VARCHAR(500) NOT NULL,
    organisme            VARCHAR(500) NOT NULL,
    type_organisme       ENUM(
                           'universite','cnrs_inserm','epa_epic','sem_spl',
                           'sivu_sivom','epci','collectivite',
                           'entreprise_publique','autre_public'
                         ) NOT NULL DEFAULT 'autre_public',
    date_debut           DATE,
    date_fin             DATE,
    remuneration_min     INT DEFAULT NULL,
    remuneration_max     INT DEFAULT NULL,
    remuneration_exacte  INT DEFAULT NULL,
    precision_calcul     ENUM('exact','grille_fp','plafond_cgct','fourchette_hatvp') NOT NULL DEFAULT 'exact',
    note_calcul          VARCHAR(500) DEFAULT NULL,
    source               ENUM('hatvp','rne','jo','manual') NOT NULL,
    source_url           VARCHAR(500) DEFAULT NULL,
    hatvp_declaration_id VARCHAR(100) DEFAULT NULL,
    verified             TINYINT(1) DEFAULT 0,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE,
    UNIQUE KEY  uniq_elu_org_debut (elu_id, organisme(200), date_debut),
    INDEX idx_elu    (elu_id),
    INDEX idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

try { $pdo->exec("ALTER TABLE elus ADD COLUMN hatvp_non_declarant TINYINT(1) DEFAULT 0"); }
catch (PDOException) {}

logLine('Tables OK');

// ── 2. Mots-clés secteur public ───────────────────────────────────────────────
const KEYWORDS_PUBLIC = [
    'universit','cnrs','inserm','inrae','inria','onera','cea ','ifremer','ird ','brgm',
    'ensam','centrale ','mines paristech','polytechnique','ens ','ecole normale',
    'école normale','inp ','chu ','chr ','aphp','ap-hp',
    'région','conseil régional','conseil départemental','département',
    'mairie','ville de ','commune de ','communauté de communes','communauté urbaine',
    'agglomération','métropole','grand paris',
    'sivu','sivom','syndicat intercommunal','syndicat mixte','syndicat des ',
    'epci','communauté d\'agglomération','smectom','sm ','smc ',
    'sem ','s.e.m.','économie mixte','publique locale','spl ',
    'sncf','ratp',' edf ','la poste','france télévisions','radio france',
    'banque de france','caisse des dépôts','aéroports de paris',
    'établissement public','agence nationale','agence régionale',
    'hôpital ','hopital ','office public','opac ',
    'ministère','préfecture','tribunal ','cour admin',
    'école nationale','inspection générale',
    'office de tourisme','office du tourisme',
];

function estSecteurPublic(string $org): bool {
    // Double passe : avec accents puis sans accents (translitération)
    $o = mb_strtolower($org, 'UTF-8');
    foreach (KEYWORDS_PUBLIC as $kw) { if (str_contains($o, $kw)) return true; }
    if (function_exists('transliterator_transliterate')) {
        $o2 = mb_strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $org), 'UTF-8');
        foreach (KEYWORDS_PUBLIC as $kw) {
            $kw2 = transliterator_transliterate('Any-Latin; Latin-ASCII', $kw);
            if (str_contains($o2, $kw2)) return true;
        }
    }
    return false;
}

function detecterTypeOrg(string $org): string {
    $o = mb_strtolower($org, 'UTF-8');
    if (preg_match('/universit|cnrs|inserm|inrae|inria|onera|\bcea\b|ifremer|\bird\b|brgm|chu\b|chr\b|aphp|ap-hp|polytechnique|centrale |mines /', $o))
        return 'universite';
    if (preg_match('/sivu|sivom|syndicat intercommunal|syndicat mixte|syndicat des eaux|syndicat de communes|smectom|\bsm\b/', $o))
        return 'sivu_sivom';
    if (preg_match('/epci|communauté de communes|communauté urbaine|agglomération|métropole/', $o))
        return 'epci';
    if (preg_match('/sem\b|économie mixte|publique locale|\bspl\b/', $o))
        return 'sem_spl';
    if (preg_match('/sncf|ratp|\bedf\b|la poste|france télévisions|radio france|banque de france|caisse des dépôts|aéroports de paris/', $o))
        return 'entreprise_publique';
    if (preg_match('/région|conseil régional|département|mairie|ville de|commune de/', $o))
        return 'collectivite';
    return 'epa_epic';
}

function detecterGradeFP(string $titre): ?int {
    $t = mb_strtolower($titre, 'UTF-8');
    if (str_contains($t, 'professeur des universités'))      return 6200;
    if (str_contains($t, 'maître de conférences'))           return 3100;
    if (str_contains($t, 'directeur de recherche'))          return 6500;
    if (str_contains($t, 'chargé de recherche'))             return 3400;
    if (str_contains($t, 'ingénieur de recherche'))          return 4100;
    if (str_contains($t, 'ingénieur d\'études'))             return 2900;
    if (str_contains($t, 'professeur agrégé'))               return 3800;
    return null;
}

// Nettoyer un montant HATVP : "1 734" → 1734
function parseMontant(string $s): int {
    return (int)preg_replace('/[^0-9]/', '', $s);
}

// Convertir date HATVP "MM/YYYY" → "YYYY-MM-01"
function parseDate(string $s): ?string {
    if (preg_match('/^(\d{2})\/(\d{4})$/', trim($s), $m)) return "{$m[2]}-{$m[1]}-01";
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($s)))  return trim($s);
    return null;
}

// Normaliser nom pour le matching
function normNom(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    if (function_exists('transliterator_transliterate'))
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
    $s = preg_replace('/[^a-z\s\'-]/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// ── 3. Récupérer les parlementaires à traiter (index nom|prenom → elu_id) ─────
if ($testSlug) {
    $stmt = $pdo->prepare("SELECT id, nom, prenom FROM elus WHERE slug = :s LIMIT 1");
    $stmt->execute([':s' => $testSlug]);
} elseif ($testMode) {
    $stmt = $pdo->prepare("
        SELECT id, nom, prenom FROM elus
        WHERE type_mandat IN ('depute','senateur','europeen')
          AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = id AND m.date_fin IS NULL)
        LIMIT 5
    ");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("
        SELECT id, nom, prenom FROM elus
        WHERE type_mandat IN ('depute','senateur','europeen')
          AND EXISTS (SELECT 1 FROM mandats m WHERE m.elu_id = id AND m.date_fin IS NULL)
    ");
    $stmt->execute();
}

$elusIndex = [];  // 'normNom|normPrenom' => elu_id
$elusIds   = [];  // elu_id => true (pour détecter non-déclarants)
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = normNom($row['nom']) . '|' . normNom($row['prenom']);
    $elusIndex[$key] = (int)$row['id'];
    $elusIds[(int)$row['id']] = false; // false = pas encore vu dans le XML
}
logLine(count($elusIndex) . ' parlementaire(s) à traiter');

// ── 4. Préparer l'UPSERT ──────────────────────────────────────────────────────
// Insérer un mandat électif dans la table mandats (sans doublon)
$stmtMandatCheck  = $pdo->prepare("SELECT id FROM mandats WHERE elu_id = :eid AND LOWER(titre) LIKE LOWER(:pat) LIMIT 1");
$stmtMandatInsert = $pdo->prepare("INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution) VALUES (:eid, :titre, :debut, :fin, :inst)");

$stmtUpsert = $pdo->prepare("
    INSERT INTO activites_publiques
        (elu_id, titre, organisme, type_organisme, date_debut, date_fin,
         remuneration_exacte, precision_calcul, note_calcul,
         source, source_url, hatvp_declaration_id)
    VALUES
        (:eid, :titre, :org, :typeorg, :debut, :fin,
         :rexacte, :precision, :note,
         'hatvp', 'https://www.hatvp.fr/livraison/merge/declarations.xml', :uuid)
    ON DUPLICATE KEY UPDATE
        titre               = VALUES(titre),
        type_organisme      = VALUES(type_organisme),
        date_fin            = VALUES(date_fin),
        remuneration_exacte = VALUES(remuneration_exacte),
        precision_calcul    = VALUES(precision_calcul),
        note_calcul         = VALUES(note_calcul),
        hatvp_declaration_id= VALUES(hatvp_declaration_id),
        updated_at          = NOW()
");
$stmtFlag = $pdo->prepare("UPDATE elus SET hatvp_non_declarant = :v WHERE id = :id");

// ── 5. Streamer declarations.xml avec XMLReader ───────────────────────────────
$xmlUrl = 'https://www.hatvp.fr/livraison/merge/declarations.xml';
logLine("Téléchargement + parsing streaming de declarations.xml (~78MB)...");

$reader = new XMLReader();
if (!$reader->open($xmlUrl)) { logLine('ERREUR : impossible d\'ouvrir le XML'); exit(1); }

$stats  = ['match' => 0, 'activites' => 0, 'skip' => 0];
$domDoc = new DOMDocument();

while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'declaration') continue;

    // Charger la déclaration entière en mémoire (un seul nœud à la fois)
    $node = $reader->expand($domDoc);
    if (!$node) continue;

    $sx = simplexml_import_dom($node);
    if (!$sx) continue;

    // Lire nom + prénom du déclarant
    $nom    = trim((string)($sx->general->declarant->nom    ?? ''));
    $prenom = trim((string)($sx->general->declarant->prenom ?? ''));
    if (!$nom) continue;

    $key   = normNom($nom) . '|' . normNom($prenom);
    $eluId = $elusIndex[$key] ?? null;
    if (!$eluId) { $stats['skip']++; continue; }

    // Marquer comme vu (déclaration trouvée → pas non-déclarant)
    $elusIds[$eluId] = true;
    $stmtFlag->execute([':v' => 0, ':id' => $eluId]);

    $uuid = trim((string)($sx->uuid ?? ''));
    $n    = 0;

    // ── 5a. Activités professionnelles (emplois publics en cours) ────────────
    foreach ($sx->activProfCinqDerniereDto->items->items ?? [] as $act) {
        $employeur   = trim((string)($act->employeur   ?? ''));
        $description = trim((string)($act->description ?? ''));
        $dateFin     = trim((string)($act->dateFin     ?? ''));

        if (!$employeur || !estSecteurPublic($employeur)) continue;
        // Ignorer les activités terminées (dateFin remplie = passé)
        if ($dateFin) continue;
        // Ne pas écraser une entrée manuellement vérifiée pour le même organisme
        $orgPrefix = mb_strtolower(mb_substr($employeur, 0, 30));
        $stmtVerifCheck = $pdo->prepare("SELECT id FROM activites_publiques WHERE elu_id=:eid AND LOWER(organisme) LIKE :pat AND verified=1 LIMIT 1");
        $stmtVerifCheck->execute([':eid' => $eluId, ':pat' => $orgPrefix . '%']);
        if ($stmtVerifCheck->fetchColumn()) continue;

        $dateDebutStr = parseDate(trim((string)($act->dateDebut ?? '')));

        // Extraire le montant : prendre la dernière année disponible / 12
        $montantMensuel = null;
        $montantNote    = null;
        $anneesRef      = [];
        foreach ($act->remuneration->montant->montant ?? [] as $m) {
            $annee   = (int)(string)($m->annee   ?? 0);
            $montant = parseMontant((string)($m->montant ?? ''));
            if ($annee && $montant) $anneesRef[$annee] = $montant;
        }
        if ($anneesRef) {
            $derniereAnnee  = max(array_keys($anneesRef));
            $montantAnnuel  = $anneesRef[$derniereAnnee];
            $montantMensuel = (int)round($montantAnnuel / 12);
            $montantNote    = "Déclaré {$derniereAnnee} : {$montantAnnuel} € brut/an ÷12 (DIA HATVP — montant réel déclaré)";
        }

        // Si grade FP détecté, remplacer par la grille officielle (plus fiable)
        $gradeMontant = detecterGradeFP($description);
        $precision    = 'exact';
        if ($gradeMontant && (!$montantMensuel || abs($gradeMontant - $montantMensuel) > 500)) {
            $montantMensuel = $gradeMontant;
            $montantNote    = 'Grille FP — échelon médian (fonction-publique.gouv.fr)';
            $precision      = 'grille_fp';
        }

        $titre = $description ?: 'Activité professionnelle publique';
        $stmtUpsert->execute([
            ':eid'       => $eluId,
            ':titre'     => mb_substr($titre,    0, 500),
            ':org'       => mb_substr($employeur,0, 500),
            ':typeorg'   => detecterTypeOrg($employeur),
            ':debut'     => $dateDebutStr,
            ':fin'       => null,
            ':rexacte'   => $montantMensuel,
            ':precision' => $precision,
            ':note'      => $montantNote,
            ':uuid'      => $uuid ?: null,
        ]);
        $n++;
    }

    // ── 5b. Mandats électoraux → table mandats (pas activites_publiques) ─────
    // On n'insère que les mandats encore ouverts (dateFin vide) au moment de la DIA,
    // et seulement si absent de la table mandats (évite les doublons RNE/Wikidata).
    foreach ($sx->mandatElectifDto->items->items ?? [] as $mel) {
        $desc    = trim((string)($mel->descriptionMandat ?? ''));
        $dateFin = trim((string)($mel->dateFin ?? ''));
        if (!$desc || $dateFin) continue; // mandat terminé → ignorer

        $pat = '%' . mb_substr($desc, 0, 40) . '%';
        $stmtMandatCheck->execute([':eid' => $eluId, ':pat' => $pat]);
        if ($stmtMandatCheck->fetchColumn()) continue; // déjà en base

        $stmtMandatInsert->execute([
            ':eid'   => $eluId,
            ':titre' => mb_substr($desc, 0, 500),
            ':debut' => parseDate(trim((string)($mel->dateDebut ?? ''))),
            ':fin'   => null,
            ':inst'  => null,
        ]);
        if ($stmtMandatInsert->rowCount() > 0) {
            logLine("  + Mandat HATVP → mandats : {$desc} ({$nom})");
            $stats['mandats_inserts'] = ($stats['mandats_inserts'] ?? 0) + 1;
        }
    }

    if ($n > 0) {
        logLine("  ✓ {$nom} {$prenom} — {$n} activité(s) publique(s)");
        $stats['activites'] += $n;
    }
    $stats['match']++;
}

$reader->close();

// ── 6. Flaguer les non-déclarants ─────────────────────────────────────────────
$nonDeclarants = 0;
foreach ($elusIds as $eluId => $vu) {
    if (!$vu) {
        $stmtFlag->execute([':v' => 1, ':id' => $eluId]);
        $nonDeclarants++;
    }
}

logLine('');
logLine('=== RÉSULTAT ===');
logLine("Déclarations matchées : {$stats['match']}");
logLine("Activités insérées    : {$stats['activites']}");
logLine("Mandats → table       : " . ($stats['mandats_inserts'] ?? 0));
logLine("Non-déclarants        : {$nonDeclarants} (DIA non déposée — obligation légale)");
logLine("Décl. non matchées    : {$stats['skip']} (élus locaux, sénat, autre)");
