<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

$elu_id = getIntParam('elu_id');
if (!$elu_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre elu_id requis']);
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT a.*, e.nom AS personne_liee_nom, e.emoji AS personne_liee_emoji
        FROM affiliations a
        LEFT JOIN elus e ON a.personne_liee_id = e.id
        WHERE a.elu_id = :elu_id
    ');
    $stmt->execute([':elu_id' => $elu_id]);
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur interne du serveur']);
}
