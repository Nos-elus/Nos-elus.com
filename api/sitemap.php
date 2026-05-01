<?php
/**
 * Sitemap XML dynamique : pages statiques + tous les élus avec photo OU score > seuil.
 * Cacheé 24h.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$base = 'https://nos-elus.com';
$pages = [
    ['path' => '/',                   'changefreq' => 'daily',   'priority' => '1.0'],
    ['path' => '/2027',               'changefreq' => 'daily',   'priority' => '0.95'],
    ['path' => '/match',              'changefreq' => 'weekly',  'priority' => '0.9'],
    ['path' => '/palmares',           'changefreq' => 'daily',   'priority' => '0.9'],
    ['path' => '/comparer',           'changefreq' => 'weekly',  'priority' => '0.7'],
    ['path' => '/grille-indemnites',  'changefreq' => 'monthly', 'priority' => '0.6'],
    ['path' => '/about',              'changefreq' => 'monthly', 'priority' => '0.5'],
    ['path' => '/contact',            'changefreq' => 'monthly', 'priority' => '0.5'],
    ['path' => '/cgu',                'changefreq' => 'yearly',  'priority' => '0.3'],
    ['path' => '/mentions-legales',   'changefreq' => 'yearly',  'priority' => '0.3'],
    ['path' => '/confidentialite',    'changefreq' => 'yearly',  'priority' => '0.3'],
    ['path' => '/nous-aider',         'changefreq' => 'monthly', 'priority' => '0.6'],
];

// Élus prioritaires : tous les VIPs + les plus consultés
$stmt = $pdo->query("
    SELECT slug, derniere_sync
    FROM elus
    WHERE actif = 1
      AND slug IS NOT NULL
      AND (
        fonction LIKE '%inistre%'
        OR fonction LIKE '%résident de la République%'
        OR fonction LIKE '%onseil constitutionnel%'
        OR fonction LIKE '%énateur%'
        OR fonction LIKE '%éputé%'
        OR fonction LIKE '%urodéputé%'
        OR fonction LIKE '%uropéen%'
        OR nb_consultations > 50
        OR photo_url IS NOT NULL
      )
    ORDER BY nb_consultations DESC
    LIMIT 5000
");

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($pages as $p) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($base . $p['path']) . "</loc>\n";
    echo "    <changefreq>" . $p['changefreq'] . "</changefreq>\n";
    echo "    <priority>" . $p['priority'] . "</priority>\n";
    echo "  </url>\n";
}

foreach ($stmt as $row) {
    $slug = $row['slug'];
    $lastmod = $row['derniere_sync'] ? date('Y-m-d', strtotime($row['derniere_sync'])) : null;
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($base . '/elu/' . $slug) . "</loc>\n";
    if ($lastmod) echo "    <lastmod>$lastmod</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
