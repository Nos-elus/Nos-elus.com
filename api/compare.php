<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

$a = getIntParam('a');
$b = getIntParam('b');

if (!$a || !$b) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres a et b requis']);
    exit;
}

function getEluComplet(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM elus WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $elu = $stmt->fetch();
    if (!$elu) return null;

    $stmt = $pdo->prepare('SELECT * FROM mandats WHERE elu_id = :id');
    $stmt->execute([':id' => $id]);
    $elu['mandats'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM affaires WHERE elu_id = :id');
    $stmt->execute([':id' => $id]);
    $elu['affaires'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM affiliations WHERE elu_id = :id');
    $stmt->execute([':id' => $id]);
    $elu['affiliations'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM votes WHERE elu_id = :id');
    $stmt->execute([':id' => $id]);
    $elu['votes'] = $stmt->fetchAll();

    return $elu;
}

$eluA = getEluComplet($pdo, $a);
$eluB = getEluComplet($pdo, $b);

if (!$eluA || !$eluB) {
    http_response_code(404);
    echo json_encode(['error' => 'Un ou plusieurs élus non trouvés']);
    exit;
}

echo json_encode(['eluA' => $eluA, 'eluB' => $eluB]);
