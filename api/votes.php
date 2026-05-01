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
        SELECT * FROM votes
        WHERE elu_id = :elu_id
        ORDER BY date_vote DESC
    ');
    $stmt->execute([':elu_id' => $elu_id]);
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur interne du serveur']);
}
