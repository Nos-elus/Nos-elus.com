<?php
/**
 * Génère une image Open Graph 1200x630 PNG via screenshot Chrome headless
 * de la fiche élu (rendu identique au site).
 * URL : /api/og-image.php?slug=<slug>
 */
require_once __DIR__ . '/config.php';

$slug = getStringParam('slug', 200);
if (!$slug || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
    http_response_code(400);
    exit('slug invalide');
}

$cacheDir = __DIR__ . '/cache/og';
@mkdir($cacheDir, 0755, true);
// Cache-buster v= dans le nom du fichier : si l'élu change (updated_at), nouveau cache
$cacheVersion = preg_replace('/[^0-9a-zA-Z]/', '', $_GET['v'] ?? '');
$cachePath = $cacheDir . '/' . md5($slug . '_' . $cacheVersion) . '.png';

// Cache 7 jours (clé incluant la version → invalidation auto)
if (file_exists($cachePath) && filemtime($cachePath) > time() - 7 * 86400) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    readfile($cachePath);
    exit;
}

$url = 'https://nos-elus.com/api/og-card.php?slug=' . rawurlencode($slug);
$uniq = md5($slug . microtime() . random_bytes(4));
$tmpFile = '/tmp/og_' . $uniq . '.png';
$userDir = '/tmp/chrome-og-' . $uniq;

$cmd = sprintf(
    'HOME=/var/www google-chrome --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --user-data-dir=%s --window-size=1200,630 --virtual-time-budget=8000 --screenshot=%s %s 2>&1',
    escapeshellarg($userDir),
    escapeshellarg($tmpFile),
    escapeshellarg($url)
);
@exec($cmd, $out, $rc);

// Toujours nettoyer le user-data-dir (sinon /tmp s'accumule)
@exec('rm -rf ' . escapeshellarg($userDir));

if (!file_exists($tmpFile) || filesize($tmpFile) < 1000) {
    @unlink($tmpFile);
    http_response_code(500);
    exit('Génération échouée');
}

@rename($tmpFile, $cachePath);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=21600');
readfile($cachePath);
