<?php
header('Content-Type: application/json; charset=utf-8');
$allowedOrigins = ['https://nos-elus.com', 'https://www.nos-elus.com', 'http://localhost:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: https://nos-elus.com');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']); exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$hash = hash('sha256', $ip . '_contact_' . date('Y-m-d-H'));
$cacheDir = __DIR__ . '/cache/data/';
$rateFile = $cacheDir . 'rl_contact_' . $hash . '.json';

if (is_dir($cacheDir)) {
    $maxReq = 5; $window = 300;
    $rdata = ['count' => 0, 'start' => time()];
    if (file_exists($rateFile)) {
        $raw = @file_get_contents($rateFile);
        $rdata = $raw ? json_decode($raw, true) : $rdata;
        if ((time() - ($rdata['start'] ?? 0)) > $window) $rdata = ['count' => 0, 'start' => time()];
    }
    $rdata['count']++;
    @file_put_contents($rateFile, json_encode($rdata), LOCK_EX);
    if ($rdata['count'] > $maxReq) {
        http_response_code(429);
        echo json_encode(['error' => 'Trop de messages envoyés. Réessayez dans 5 minutes.']); exit;
    }
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['error' => 'Corps JSON invalide']); exit; }

if (!empty($data['website'])) { echo json_encode(['success' => true]); exit; }

$nom     = trim(strip_tags($data['nom']     ?? ''));
$email   = trim(strip_tags($data['email']   ?? ''));
$message = trim(strip_tags($data['message'] ?? ''));
$sujet   = trim(strip_tags($data['sujet']   ?? 'Contact'));
$errors  = [];

if (mb_strlen($nom) < 2 || mb_strlen($nom) > 100)          $errors[] = 'Le nom doit contenir entre 2 et 100 caractères.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))              $errors[] = 'Adresse email invalide.';
if (mb_strlen($message) < 10 || mb_strlen($message) > 3000) $errors[] = 'Le message doit contenir entre 10 et 3000 caractères.';

if (!empty($errors)) { http_response_code(422); echo json_encode(['error' => implode(' ', $errors)]); exit; }

$to      = 'Noselusforms@protonmail.com';
$subject = '=?UTF-8?B?' . base64_encode('[nos-elus.com] ' . $sujet) . '?=';
$ipHash  = substr(hash('sha256', $ip . ($IP_SALT ?? getenv('NOSELUS_IP_SALT') ?? '')), 0, 12);
$body    = "Nouveau message via nos-elus.com\n" . str_repeat('-', 50) . "\n";
$body   .= "Nom    : $nom\nEmail  : $email\nSujet  : $sujet\nIP hash: $ipHash\nDate   : " . date('d/m/Y H:i:s') . "\n" . str_repeat('-', 50) . "\n\n$message\n";
$email = str_replace(["\r", "\n", "%0a", "%0d"], '', $email);
$headers = "From: noreply@nos-elus.com\r\nReply-To: $email\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: nos-elus.com\r\n";

// Stocker en BDD chiffré pour traitement ultérieur
try {
    require_once __DIR__ . '/config.php';
    $pdo->exec("CREATE TABLE IF NOT EXISTS inbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_email VARCHAR(255), from_name VARCHAR(255), subject VARCHAR(500),
        body_encrypted TEXT, body_iv VARCHAR(64),
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('new','read','processed','archived') DEFAULT 'new',
        agent_summary TEXT DEFAULT NULL, agent_action TEXT DEFAULT NULL,
        processed_at DATETIME DEFAULT NULL,
        INDEX idx_status (status), INDEX idx_date (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $encKey = hash('sha256', ($DB_PASS ?? '') . ($INBOX_KEY_SUFFIX ?? getenv('NOSELUS_INBOX_KEY_SUFFIX') ?? ''), true);
    $iv = random_bytes(16);
    $encBody = openssl_encrypt($body, 'aes-256-cbc', $encKey, 0, $iv);

    $stmtInbox = $pdo->prepare("INSERT INTO inbox (from_email, from_name, subject, body_encrypted, body_iv) VALUES (:e, :n, :s, :b, :iv)");
    $stmtInbox->execute([
        ':e' => mb_substr($email, 0, 255),
        ':n' => mb_substr($nom, 0, 255),
        ':s' => mb_substr('[nos-elus.com] ' . $sujet, 0, 500),
        ':b' => $encBody,
        ':iv' => base64_encode($iv),
    ]);
} catch (Exception $e) {
    // Silencieux — le mail part quand même
}

// Envoyer aussi par email à ProtonMail (pour consultation manuelle)
@mail($to, $subject, $body, $headers);

echo json_encode(['success' => true, 'message' => 'Message envoyé avec succès.']);
