#!/usr/bin/env php
<?php
/**
 * Enrichissement des photos des élus via l'API Wikidata
 *
 * Cherche sur Wikidata les élus sans photo, récupère l'image (propriété P18)
 * et met à jour photo_url en BDD.
 *
 * Usage :
 *   php scripts/enrich-photos.php                  # enrichit jusqu'à 500 élus
 *   php scripts/enrich-photos.php --limit=50       # limite à 50 élus
 *   php scripts/enrich-photos.php --dry-run        # simule sans écrire en BDD
 *   php scripts/enrich-photos.php --dry-run --limit=10
 */

// ── CLI args ──
$dryRun = in_array('--dry-run', $argv);
$limit = 500;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

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
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Erreur connexion BDD : " . $e->getMessage() . "\n");
    exit(1);
}

// ── Récupérer les élus sans photo ──
// On exclut les élus source_api = 'manual' qui pourraient avoir une photo locale vide volontairement
$stmt = $pdo->prepare("
    SELECT id, nom, prenom, fonction
    FROM elus
    WHERE (photo_url IS NULL OR photo_url = '')
      AND (source_api IS NULL OR source_api != 'manual')
    ORDER BY fonction DESC, nom ASC
    LIMIT :lim
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$elus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($elus);
if ($total === 0) {
    echo "Aucun élu sans photo à enrichir.\n";
    exit(0);
}

$mode = $dryRun ? ' [DRY-RUN]' : '';
echo "=== Enrichissement photos Wikidata$mode ===\n";
echo "Élus à traiter : $total\n\n";

// ── Préparer l'UPDATE ──
$updateStmt = $pdo->prepare("UPDATE elus SET photo_url = :url WHERE id = :id");

// ── Compteurs ──
$found = 0;
$notFound = 0;
$errors = 0;

// ── User-Agent requis par Wikidata ──
$userAgent = 'NosElusBot/1.0 (https://nos-elus.fr; contact@nos-elus.fr)';

$streamCtx = stream_context_create([
    'http' => [
        'header' => "User-Agent: $userAgent\r\n",
        'timeout' => 10,
    ],
]);

/**
 * Appel HTTP GET avec gestion d'erreur
 */
function wikidataGet(string $url, $ctx): ?array
{
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return null;
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $data;
}

/**
 * Cherche un élu sur Wikidata et retourne l'URL de la photo ou null
 */
function findPhotoOnWikidata(string $nom, string $prenom, $ctx): ?string
{
    // Étape 1 : rechercher l'entité
    $search = urlencode(trim("$prenom $nom"));
    $searchUrl = "https://www.wikidata.org/w/api.php?action=wbsearchentities&search=$search&language=fr&type=item&format=json&limit=5";

    $data = wikidataGet($searchUrl, $ctx);
    if (!$data || empty($data['search'])) {
        return null;
    }

    // Parcourir les résultats (jusqu'à 5) pour trouver un qui a une image
    foreach ($data['search'] as $result) {
        $qid = $result['id'];

        // Rate limit entre chaque appel claims
        usleep(500000);

        // Étape 2 : récupérer la propriété P18 (image)
        $claimsUrl = "https://www.wikidata.org/w/api.php?action=wbgetclaims&entity=$qid&property=P18&format=json";
        $claims = wikidataGet($claimsUrl, $ctx);

        if (!$claims || empty($claims['claims']['P18'])) {
            continue;
        }

        // Récupérer le nom du fichier image
        $filename = $claims['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
        if (!$filename) {
            continue;
        }

        // Construire l'URL Commons avec redimensionnement
        $filename = str_replace(' ', '_', $filename);
        $encodedFilename = rawurlencode($filename);
        return "https://commons.wikimedia.org/wiki/Special:FilePath/$encodedFilename?width=200";
    }

    return null;
}

// ── Boucle principale ──
foreach ($elus as $i => $elu) {
    $num = $i + 1;
    $nom = $elu['nom'];
    $prenom = $elu['prenom'] ?? '';
    $fonction = $elu['fonction'] ?? '';
    $label = trim("$prenom $nom");

    echo "[$num/$total] $label ($fonction) ... ";

    try {
        $photoUrl = findPhotoOnWikidata($nom, $prenom, $streamCtx);

        if ($photoUrl) {
            $found++;
            echo "TROUVÉ -> $photoUrl\n";

            if (!$dryRun) {
                $updateStmt->execute([
                    ':url' => $photoUrl,
                    ':id'  => $elu['id'],
                ]);
            }
        } else {
            $notFound++;
            echo "pas trouvé\n";
        }
    } catch (Exception $e) {
        $errors++;
        echo "ERREUR : " . $e->getMessage() . "\n";
    }

    // Rate limit entre chaque élu (500ms)
    usleep(500000);
}

// ── Résumé ──
echo "\n=== Résumé$mode ===\n";
echo "Traités  : $total\n";
echo "Trouvés  : $found\n";
echo "Non trouvés : $notFound\n";
echo "Erreurs  : $errors\n";

if ($dryRun && $found > 0) {
    echo "\nMode dry-run : aucune modification en BDD.\n";
    echo "Relancez sans --dry-run pour appliquer.\n";
}
