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
$cachePath = $cacheDir . '/' . md5($slug) . '.png';

// Cache 24h
if (file_exists($cachePath) && filemtime($cachePath) > time() - 86400) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=21600');
    readfile($cachePath);
    exit;
}

$url = 'https://nos-elus.com/api/og-card.php?slug=' . rawurlencode($slug);
$tmpFile = '/tmp/og_' . md5($slug . microtime()) . '.png';

$cmd = sprintf(
    'HOME=/var/www google-chrome --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --user-data-dir=/tmp/chrome-og --window-size=1200,630 --virtual-time-budget=8000 --screenshot=%s %s 2>&1',
    escapeshellarg($tmpFile),
    escapeshellarg($url)
);
@exec($cmd, $out, $rc);

if (!file_exists($tmpFile) || filesize($tmpFile) < 1000) {
    @unlink($tmpFile);
    http_response_code(500);
    exit('Génération échouée');
}

@rename($tmpFile, $cachePath);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=21600');
readfile($cachePath);
