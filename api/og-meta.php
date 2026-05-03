<?php
/**
 * Sert un HTML avec les meta Open Graph dynamiques pour les crawlers sociaux.
 * Redirige les humains vers le SPA normalement.
 */
require_once __DIR__ . '/config.php';

$publicRoot = realpath(__DIR__ . '/../public') ?: dirname(__DIR__) . '/public';
$indexFile  = $publicRoot . '/index.html';

$uri = $_SERVER['REQUEST_URI'] ?? '';
preg_match('#/elu/([^/?]+)#', $uri, $m);
$slug = $m[1] ?? '';

if (!$slug) {
    readfile($indexFile);
    exit;
}

$stmt = $pdo->prepare("SELECT nom, prenom, fonction, parti, photo_url, updated_at FROM elus WHERE slug = :s LIMIT 1");
$stmt->execute([':s' => $slug]);
$elu = $stmt->fetch();

if (!$elu) {
    readfile($indexFile);
    exit;
}

$nom = htmlspecialchars(trim(($elu['prenom'] ?: '') . ' ' . $elu['nom']));
$desc = htmlspecialchars(($elu['fonction'] ?: 'Élu') . ($elu['parti'] ? ' — ' . $elu['parti'] : ''));
$slugSafe = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
$url = "https://nos-elus.com/elu/$slugSafe";
// Cache-buster basé sur updated_at de la fiche (change à chaque modif → force Twitter à re-fetch).
// Fallback date du jour si updated_at indisponible.
$cacheV = preg_replace('/\D/', '', $elu['updated_at'] ?? '') ?: date('Ymd');
$ogImage = "https://nos-elus.com/api/og-image.php?slug=" . urlencode($slug) . "&v=" . $cacheV;
$photo = $elu['photo_url'] ? "https://nos-elus.com" . htmlspecialchars($elu['photo_url'], ENT_QUOTES, 'UTF-8') : $ogImage;

$html = file_get_contents($indexFile);

$meta = <<<HTML
    <meta property="og:title" content="$nom — nos-elus.com" />
    <meta property="og:description" content="$desc — Affaires, mandats, patrimoine. Tout est sourcé." />
    <meta property="og:type" content="profile" />
    <meta property="og:url" content="$url" />
    <meta property="og:image" content="$ogImage" />
    <meta property="og:site_name" content="nos-elus.com" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="$nom — nos-elus.com" />
    <meta name="twitter:description" content="$desc" />
    <meta name="twitter:image" content="$ogImage" />
    <title>$nom — nos-elus.com</title>
HTML;

$html = preg_replace('#<meta property="og:[^>]+>\s*#', '', $html);
$html = preg_replace('#<meta name="twitter:[^>]+>\s*#', '', $html);
$html = preg_replace('#<title>[^<]+</title>#', '', $html);
$html = str_replace('</head>', $meta . "\n  </head>", $html);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
