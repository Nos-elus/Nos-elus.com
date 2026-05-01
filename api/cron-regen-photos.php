<?php
/**
 * Régénère les photos manquantes dans /photos/cached/
 * en les re-téléchargeant depuis Wikidata/Wikimedia Commons.
 *
 * Usage CLI : php cron-regen-photos.php [--limit=100] [--dry-run]
 * À exécuter en tant que www-data ou avec permissions sur /photos/cached/
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$limit  = 200;
$dryRun = false;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/--limit=(\d+)/', $arg, $m)) $limit = (int)$m[1];
    if ($arg === '--dry-run') $dryRun = true;
}

$photoDir = '/var/www/noselus/public/photos/cached/';
$ua = 'nos-elus.com/1.0 (https://nos-elus.com; Noselusforms@protonmail.com)';

function wikiGet(string $url, string $ua): ?array {
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: $ua\r\n",
        'timeout' => 10,
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    return $json ? json_decode($json, true) : null;
}

function downloadImage(string $url, string $dest, string $ua): bool {
    $ctx = stream_context_create(['http' => [
        'header'          => "User-Agent: $ua\r\n",
        'timeout'         => 20,
        'follow_location' => true,
        'max_redirects'   => 5,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if (!$data || strlen($data) < 1000) return false;
    return file_put_contents($dest, $data) !== false;
}

function toWebp(string $src, string $dest): bool {
    // Essaie cwebp d'abord, puis convert (ImageMagick)
    $srcEsc  = escapeshellarg($src);
    $destEsc = escapeshellarg($dest);
    exec("cwebp -q 80 $srcEsc -o $destEsc 2>/dev/null", $out, $code);
    if ($code === 0 && file_exists($dest)) return true;
    exec("convert $srcEsc -resize 300x300> -quality 80 $destEsc 2>/dev/null", $out, $code);
    return $code === 0 && file_exists($dest);
}

// 1. Récupérer les élus avec photo_url /photos/cached/ manquante sur disque
$stmt = $pdo->query("
    SELECT id, nom, prenom, slug, photo_url
    FROM elus
    WHERE photo_url LIKE '/photos/cached/%'
    ORDER BY nb_consultations DESC
    LIMIT $limit
");
$elus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total     = count($elus);
$fetched   = 0;
$skipped   = 0;
$errors    = 0;

echo "=== REGEN PHOTOS === " . date('Y-m-d H:i:s') . "\n";
echo "Élus à traiter : $total (limit=$limit, dry-run=" . ($dryRun ? 'oui' : 'non') . ")\n\n";

foreach ($elus as $elu) {
    $slug    = $elu['slug'] ?? '';
    $urlPath = $elu['photo_url']; // ex: /photos/cached/gabriel-attal.png
    $filename = basename($urlPath);
    $filePath = $photoDir . $filename;

    // Vérifier si le fichier existe déjà
    if (file_exists($filePath) && filesize($filePath) > 1000) {
        $skipped++;
        continue;
    }

    $nom = trim(($elu['prenom'] ?? '') . ' ' . $elu['nom']);
    echo "[$fetched/$total] $nom ($slug) ... ";

    // 2. Recherche Wikidata par nom
    $searchData = wikiGet('https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities', 'search' => $nom,
        'language' => 'fr', 'type' => 'item', 'format' => 'json', 'limit' => 5,
    ]), $ua);

    $qid = null;
    $keywords = ['politi', 'député', 'sénateur', 'maire', 'minister', 'french', 'français', 'élu'];
    foreach ($searchData['search'] ?? [] as $r) {
        $desc = mb_strtolower($r['description'] ?? '');
        foreach ($keywords as $kw) {
            if (mb_strpos($desc, $kw) !== false) { $qid = $r['id']; break 2; }
        }
    }

    // Fallback : premier résultat si rien trouvé
    if (!$qid && !empty($searchData['search'][0]['id'])) {
        $qid = $searchData['search'][0]['id'];
    }

    if (!$qid) {
        echo "pas de QID trouvé\n";
        $errors++;
        usleep(500000);
        continue;
    }

    // 3. Récupérer P18 (photo)
    $entityData = wikiGet('https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities', 'ids' => $qid,
        'props' => 'claims', 'format' => 'json',
    ]), $ua);

    $p18 = $entityData['entities'][$qid]['claims']['P18'][0]['mainsnak']['datavalue'] ?? null;
    if (!$p18 || ($p18['type'] ?? '') !== 'string') {
        echo "pas de photo P18 ($qid)\n";
        $errors++;
        usleep(500000);
        continue;
    }

    $wikimediaFile = str_replace(' ', '_', $p18['value']);
    $ext = strtolower(pathinfo($wikimediaFile, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) $ext = 'jpg';

    // 4. URL de téléchargement (Wikimedia Commons Special:FilePath)
    $downloadUrl = 'https://commons.wikimedia.org/wiki/Special:FilePath/'
        . rawurlencode($wikimediaFile) . '?width=300';

    if ($dryRun) {
        echo "DRY-RUN → $downloadUrl\n";
        $fetched++;
        usleep(300000);
        continue;
    }

    // 5. Télécharger et convertir en WebP
    $tmpFile  = $photoDir . $slug . '_tmp.' . $ext;
    $destWebp = $photoDir . $slug . '.webp';

    if (!downloadImage($downloadUrl, $tmpFile, $ua)) {
        echo "erreur téléchargement\n";
        @unlink($tmpFile);
        $errors++;
        usleep(500000);
        continue;
    }

    $converted = ($ext === 'webp')
        ? rename($tmpFile, $destWebp)
        : toWebp($tmpFile, $destWebp);

    @unlink($tmpFile);

    if (!$converted) {
        // Garder en format original si la conversion échoue
        $destFinal = $photoDir . $slug . '.' . $ext;
        rename($tmpFile, $destFinal);
        $newUrl = '/photos/cached/' . $slug . '.' . $ext;
    } else {
        $newUrl = '/photos/cached/' . $slug . '.webp';
    }

    // 6. Mettre à jour photo_url en BDD si différent
    if ($newUrl !== $urlPath) {
        try {
            $pdo->prepare("UPDATE elus SET photo_url = :url WHERE id = :id AND source_api != 'manual'")
                ->execute([':url' => $newUrl, ':id' => $elu['id']]);
        } catch (PDOException $e) {
            echo "BDD erreur: " . $e->getMessage() . "\n";
        }
    }

    echo "OK → $newUrl\n";
    $fetched++;
    usleep(400000); // ~2.5 req/s Wikidata (polite)
}

echo "\n=== RÉSULTAT ===\n";
echo "Récupérées : $fetched\n";
echo "Déjà présentes (ignorées) : $skipped\n";
echo "Erreurs : $errors\n";
echo "Durée : " . (time() - $_SERVER['REQUEST_TIME']) . "s\n";
