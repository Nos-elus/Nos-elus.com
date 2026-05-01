<?php
/**
 * Sert un HTML avec les meta Open Graph dynamiques pour les crawlers sociaux.
 * Redirige les humains vers le SPA normalement.
 */
require_once __DIR__ . '/config.php';

$uri = $_SERVER['REQUEST_URI'] ?? '';
preg_match('#/elu/([^/?]+)#', $uri, $m);
$slug = $m[1] ?? '';

if (!$slug) {
    // Pas un profil élu → servir l'index.html normal
    readfile(dirname(__DIR__) . '/index.html');
    exit;
}

// Fetch élu
$stmt = $pdo->prepare("SELECT nom, prenom, fonction, parti, photo_url FROM elus WHERE slug = :s LIMIT 1");
$stmt->execute([':s' => $slug]);
$elu = $stmt->fetch();

if (!$elu) {
    readfile(dirname(__DIR__) . '/index.html');
    exit;
}

$nom = htmlspecialchars(trim(($elu['prenom'] ?: '') . ' ' . $elu['nom']));
$desc = htmlspecialchars(($elu['fonction'] ?: 'Élu') . ($elu['parti'] ? ' — ' . $elu['parti'] : ''));
$slugSafe = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
$url = "https://nos-elus.fr/elu/$slugSafe";
$ogImage = "https://nos-elus.fr/api/og-image.php?slug=" . urlencode($slug);
$photo = $elu['photo_url'] ? "https://nos-elus.fr" . htmlspecialchars($elu['photo_url'], ENT_QUOTES, 'UTF-8') : $ogImage;

// Lire index.html et injecter les meta OG dynamiques
$html = file_get_contents(dirname(__DIR__) . '/index.html');

$meta = <<<HTML
    <meta property="og:title" content="$nom — nos-elus.fr" />
    <meta property="og:description" content="$desc — Affaires, mandats, patrimoine. Tout est sourcé." />
    <meta property="og:type" content="profile" />
    <meta property="og:url" content="$url" />
    <meta property="og:image" content="$ogImage" />
    <meta property="og:site_name" content="nos-elus.fr" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="$nom — nos-elus.fr" />
    <meta name="twitter:description" content="$desc" />
    <meta name="twitter:image" content="$ogImage" />
    <title>$nom — nos-elus.fr</title>
HTML;

// Remplacer les meta existantes
$html = preg_replace('#<meta property="og:[^>]+>\s*#', '', $html);
$html = preg_replace('#<meta name="twitter:[^>]+>\s*#', '', $html);
$html = preg_replace('#<title>[^<]+</title>#', '', $html);
$html = str_replace('</head>', $meta . "\n  </head>", $html);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
