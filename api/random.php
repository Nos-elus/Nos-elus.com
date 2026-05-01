<?php
require_once __DIR__ . '/config.php';

checkRateLimit(60, 60);

// Anti-cache absolu
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Mode redirect : /api/random.php → /elu/{slug}
if (isset($_GET['redirect'])) {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM elus')->fetchColumn();
    $offset = mt_rand(0, max(0, $total - 1));
    $stmt = $pdo->prepare('SELECT slug FROM elus LIMIT 1 OFFSET :off');
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $slug = $stmt->fetchColumn();
    if ($slug) {
        header("Location: /elu/$slug", true, 302);
        exit;
    }
}

// Mode JSON (API)
setApiHeaders();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$total = (int)$pdo->query('SELECT COUNT(*) FROM elus')->fetchColumn();
$offset = mt_rand(0, max(0, $total - 1));
$stmt = $pdo->prepare('SELECT id, nom, prenom, slug, parti, fonction, photo_url, couleur FROM elus LIMIT 1 OFFSET :off');
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$elu = $stmt->fetch();

echo json_encode($elu ?: ['error' => 'Aucun élu'], JSON_UNESCAPED_UNICODE);
