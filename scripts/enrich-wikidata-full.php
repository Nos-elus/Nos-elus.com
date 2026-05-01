#!/usr/bin/env php
<?php
/**
 * enrich-wikidata-full.php — Enrichit les élus via Wikidata (photo + bio + mandats)
 *
 * Usage :
 *   php scripts/enrich-wikidata-full.php [--limit=50] [--dry-run] [--verbose]
 *
 * Env vars : DB_HOST, DB_NAME, DB_USER, DB_PASS
 */

// ── CLI args ──
$opts = getopt('', ['limit:', 'dry-run', 'verbose', 'help']);
if (isset($opts['help'])) {
    echo <<<HELP
    Usage: php enrich-wikidata-full.php [OPTIONS]

    Options:
      --limit=N    Nombre max d'élus à traiter (défaut: 50)
      --dry-run    Affiche les modifications sans les appliquer
      --verbose    Affiche le détail de chaque requête Wikidata
      --help       Affiche cette aide

    HELP;
    exit(0);
}

$limit   = (int) ($opts['limit'] ?? 50);
$dryRun  = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);

if ($limit < 1) $limit = 50;

$USER_AGENT = 'nos-elus.fr/1.0 (https://nos-elus.fr; contact@nos-elus.fr)';
$RATE_DELAY = 500000; // 500ms entre chaque élu

// ── Connexion BDD ──
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "ERREUR BDD : " . $e->getMessage() . "\n");
    exit(1);
}

echo "╔══════════════════════════════════════════════╗\n";
echo "║  Enrichissement Wikidata — nos-elus.fr       ║\n";
echo "╚══════════════════════════════════════════════╝\n";
echo "  Limite : $limit | Dry-run : " . ($dryRun ? 'OUI' : 'NON') . "\n\n";

// ── Sélection des élus à enrichir ──
$sql = "SELECT id, nom, prenom, slug, type_mandat, photo_url, date_naissance, bio
        FROM elus
        WHERE (photo_url IS NULL OR photo_url = '' OR date_naissance IS NULL OR bio IS NULL OR bio = '')
          AND source_api != 'manual'
          AND type_mandat IN ('depute', 'senateur', 'europeen', 'president', 'ministre')
        ORDER BY type_mandat, id
        LIMIT :limit";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$elus = $stmt->fetchAll();

$total = count($elus);
echo "→ $total élu(s) à enrichir trouvé(s)\n\n";

if ($total === 0) {
    echo "Rien à faire.\n";
    exit(0);
}

// ── Compteurs ──
$stats = [
    'traites'       => 0,
    'enrichis'      => 0,
    'photos'        => 0,
    'naissances'    => 0,
    'bios'          => 0,
    'mandats_added' => 0,
    'not_found'     => 0,
    'errors'        => 0,
];

// ── Prépare les statements ──
$updateStmt = $pdo->prepare("
    UPDATE elus SET
        photo_url       = COALESCE(NULLIF(:photo, ''), photo_url),
        date_naissance  = COALESCE(:naissance, date_naissance),
        bio             = COALESCE(NULLIF(:bio, ''), bio),
        derniere_sync   = NOW()
    WHERE id = :id AND source_api != 'manual'
");

$checkMandatStmt = $pdo->prepare("
    SELECT COUNT(*) FROM mandats
    WHERE elu_id = :elu_id AND titre = :titre AND date_debut = :date_debut
");

$insertMandatStmt = $pdo->prepare("
    INSERT INTO mandats (elu_id, titre, date_debut, date_fin, institution)
    VALUES (:elu_id, :titre, :date_debut, :date_fin, :institution)
");

// ── Fonctions utilitaires ──

function wikidataSearch(string $query, string $userAgent): ?array
{
    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities',
        'search' => $query,
        'language' => 'fr',
        'type' => 'item',
        'format' => 'json',
        'limit' => 3,
    ]);

    $json = httpGet($url, $userAgent);
    if (!$json) return null;

    $data = json_decode($json, true);
    return $data['search'] ?? null;
}

function wikidataGetEntity(string $qid, string $userAgent): ?array
{
    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities',
        'ids' => $qid,
        'props' => 'claims|descriptions|labels',
        'languages' => 'fr',
        'format' => 'json',
    ]);

    $json = httpGet($url, $userAgent);
    if (!$json) return null;

    $data = json_decode($json, true);
    return $data['entities'][$qid] ?? null;
}

function wikidataGetLabel(string $qid, string $userAgent): string
{
    $entity = wikidataGetEntity($qid, $userAgent);
    if (!$entity) return '';
    return $entity['labels']['fr']['value'] ?? '';
}

function httpGet(string $url, string $userAgent): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: $userAgent\r\nAccept: application/json\r\n",
            'timeout' => 15,
        ],
    ]);

    $result = @file_get_contents($url, false, $ctx);
    return $result !== false ? $result : null;
}

function getClaimValue(array $entity, string $property): ?array
{
    $claims = $entity['claims'][$property] ?? [];
    if (empty($claims)) return null;
    return $claims[0]['mainsnak']['datavalue'] ?? null;
}

function getAllClaims(array $entity, string $property): array
{
    return $entity['claims'][$property] ?? [];
}

function isRelevantResult(array $result): bool
{
    $desc = mb_strtolower($result['description'] ?? '');
    $keywords = ['politique', 'député', 'deputé', 'sénateur', 'senateur', 'français',
                 'française', 'france', 'homme politique', 'femme politique',
                 'membre de l', 'parlementaire', 'minister', 'premier ministre',
                 'président', 'depute', 'senateur',
                 'politician', 'french politi', 'member of the', 'senator', 'deputy',
                 'mayor', 'ministre', 'representative'];
    foreach ($keywords as $kw) {
        if (mb_strpos($desc, $kw) !== false) return true;
    }
    return false;
}

function parseWikidataDate(?array $datavalue): ?string
{
    if (!$datavalue || ($datavalue['type'] ?? '') !== 'time') return null;
    $time = $datavalue['value']['time'] ?? '';
    // Format : +YYYY-MM-DDT00:00:00Z
    if (preg_match('/^\+?(\d{4}-\d{2}-\d{2})/', $time, $m)) {
        return $m[1];
    }
    return null;
}

function getCommonsPhotoUrl(string $filename): string
{
    $filename = str_replace(' ', '_', $filename);
    return "https://commons.wikimedia.org/wiki/Special:FilePath/" . rawurlencode($filename) . "?width=200";
}

// ── Boucle principale ──
foreach ($elus as $i => $elu) {
    $num = $i + 1;
    $nom = trim(($elu['prenom'] ?? '') . ' ' . $elu['nom']);
    echo "[$num/$total] $nom ({$elu['type_mandat']}) ... ";

    $stats['traites']++;

    // 1. Recherche Wikidata
    $results = wikidataSearch($nom, $USER_AGENT);
    if (!$results) {
        echo "❌ pas de résultat\n";
        $stats['not_found']++;
        usleep($RATE_DELAY);
        continue;
    }

    // Trouver le meilleur match politique
    $bestMatch = null;
    foreach ($results as $result) {
        if (isRelevantResult($result)) {
            $bestMatch = $result;
            break;
        }
    }

    if (!$bestMatch) {
        echo "❌ aucun match politique\n";
        if ($verbose) {
            foreach ($results as $r) {
                echo "    → {$r['id']}: {$r['label']} — " . ($r['description'] ?? '(pas de desc)') . "\n";
            }
        }
        $stats['not_found']++;
        usleep($RATE_DELAY);
        continue;
    }

    $qid = $bestMatch['id'];
    echo "→ $qid ";

    // 2. Récupérer l'entité complète
    $entity = wikidataGetEntity($qid, $USER_AGENT);
    if (!$entity) {
        echo "❌ erreur fetch entité\n";
        $stats['errors']++;
        usleep($RATE_DELAY);
        continue;
    }

    // ── Extraire les données ──

    // Photo (P18)
    $photoUrl = '';
    if (empty($elu['photo_url'])) {
        $photoVal = getClaimValue($entity, 'P18');
        if ($photoVal && ($photoVal['type'] ?? '') === 'string') {
            $photoUrl = getCommonsPhotoUrl($photoVal['value']);
            $stats['photos']++;
        }
    }

    // Date de naissance (P569)
    $dateNaissance = null;
    if (empty($elu['date_naissance'])) {
        $birthVal = getClaimValue($entity, 'P569');
        $dateNaissance = parseWikidataDate($birthVal);
        if ($dateNaissance) $stats['naissances']++;
    }

    // Bio (description Wikidata)
    $bio = '';
    if (empty($elu['bio'])) {
        $bio = $entity['descriptions']['fr']['value'] ?? '';
        if ($bio) $stats['bios']++;
    }

    // Mandats (P39) — résolution batch des labels en 1 requête
    $mandats = [];
    $p39Claims = getAllClaims($entity, 'P39');
    $posQids = [];
    foreach ($p39Claims as $claim) {
        $posVal = $claim['mainsnak']['datavalue'] ?? null;
        if ($posVal && ($posVal['type'] ?? '') === 'wikibase-entityid') {
            $posQids[] = $posVal['value']['id'] ?? '';
        }
    }
    // Résoudre tous les labels de mandats en 1 requête
    $posLabels = [];
    $posQids = array_filter(array_unique($posQids));
    if (!empty($posQids)) {
        $batchUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action' => 'wbgetentities',
            'ids' => implode('|', array_slice($posQids, 0, 50)),
            'props' => 'labels',
            'languages' => 'fr',
            'format' => 'json',
        ]);
        $batchJson = httpGet($batchUrl, $USER_AGENT);
        if ($batchJson) {
            $batchData = json_decode($batchJson, true);
            foreach (($batchData['entities'] ?? []) as $qid => $ent) {
                $posLabels[$qid] = $ent['labels']['fr']['value'] ?? '';
            }
        }
    }

    foreach ($p39Claims as $claim) {
        $posVal = $claim['mainsnak']['datavalue'] ?? null;
        if (!$posVal || ($posVal['type'] ?? '') !== 'wikibase-entityid') continue;
        $posQid = $posVal['value']['id'] ?? '';
        $posLabel = $posLabels[$posQid] ?? '';
        if (!$posLabel) continue;

        $qualifiers = $claim['qualifiers'] ?? [];
        $debut = null;
        $fin   = null;
        if (!empty($qualifiers['P580'][0]['datavalue'])) {
            $debut = parseWikidataDate($qualifiers['P580'][0]['datavalue']);
        }
        if (!empty($qualifiers['P582'][0]['datavalue'])) {
            $fin = parseWikidataDate($qualifiers['P582'][0]['datavalue']);
        }

        $mandats[] = [
            'titre'       => $posLabel,
            'date_debut'  => $debut,
            'date_fin'    => $fin,
            'institution' => '',
        ];
    }

    // ── Résumé pour cet élu ──
    $changes = [];
    if ($photoUrl)      $changes[] = 'photo';
    if ($dateNaissance) $changes[] = "né le $dateNaissance";
    if ($bio)           $changes[] = 'bio';
    if ($mandats)       $changes[] = count($mandats) . ' mandat(s)';

    if (empty($changes) && empty($mandats)) {
        echo "→ rien de nouveau\n";
        usleep($RATE_DELAY);
        continue;
    }

    echo "→ " . implode(', ', $changes) . "\n";

    if ($verbose) {
        if ($photoUrl)      echo "    📷 $photoUrl\n";
        if ($bio)           echo "    📝 $bio\n";
        foreach ($mandats as $m) {
            echo "    🏛️  {$m['titre']} ({$m['date_debut']} → " . ($m['date_fin'] ?? '...') . ")\n";
        }
    }

    $stats['enrichis']++;

    if ($dryRun) {
        usleep($RATE_DELAY);
        continue;
    }

    // ── 3. UPDATE BDD ──
    try {
        $updateStmt->execute([
            ':photo'     => $photoUrl,
            ':naissance' => $dateNaissance,
            ':bio'       => $bio,
            ':id'        => $elu['id'],
        ]);
    } catch (PDOException $e) {
        echo "    ⚠️  Erreur UPDATE : " . $e->getMessage() . "\n";
        $stats['errors']++;
    }

    // ── 4. INSERT mandats ──
    foreach ($mandats as $m) {
        if (!$m['date_debut']) continue; // Pas de date = inutile

        try {
            $checkMandatStmt->execute([
                ':elu_id'     => $elu['id'],
                ':titre'      => $m['titre'],
                ':date_debut' => $m['date_debut'],
            ]);
            $exists = (int) $checkMandatStmt->fetchColumn();

            if ($exists === 0) {
                $insertMandatStmt->execute([
                    ':elu_id'      => $elu['id'],
                    ':titre'       => $m['titre'],
                    ':date_debut'  => $m['date_debut'],
                    ':date_fin'    => $m['date_fin'],
                    ':institution' => $m['institution'],
                ]);
                $stats['mandats_added']++;
            }
        } catch (PDOException $e) {
            echo "    ⚠️  Erreur INSERT mandat : " . $e->getMessage() . "\n";
            $stats['errors']++;
        }
    }

    usleep($RATE_DELAY);
}

// ── Résumé final ──
echo "\n╔══════════════════════════════════════════════╗\n";
echo "║  RÉSUMÉ                                       ║\n";
echo "╚══════════════════════════════════════════════╝\n";
echo "  Élus traités   : {$stats['traites']}\n";
echo "  Élus enrichis  : {$stats['enrichis']}\n";
echo "  Photos ajoutées: {$stats['photos']}\n";
echo "  Naissances     : {$stats['naissances']}\n";
echo "  Bios            : {$stats['bios']}\n";
echo "  Mandats ajoutés : {$stats['mandats_added']}\n";
echo "  Non trouvés     : {$stats['not_found']}\n";
echo "  Erreurs         : {$stats['errors']}\n";
if ($dryRun) {
    echo "\n  ⚠️  Mode DRY-RUN — aucune modification en BDD\n";
}
echo "\nTerminé.\n";
