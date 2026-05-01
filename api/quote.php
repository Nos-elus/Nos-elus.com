<?php
require_once __DIR__ . '/config.php';
setApiHeaders();
checkRateLimit();

$stmt = $pdo->query('SELECT * FROM citations_humour ORDER BY RAND() LIMIT 1');
$quote = $stmt->fetch();

if ($quote) {
    echo json_encode($quote);
} else {
    echo json_encode(['texte' => 'La politique, c\'est l\'art d\'empêcher les gens de se mêler de ce qui les regarde.', 'auteur' => 'Paul Valéry']);
}
