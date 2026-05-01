<?php
/**
 * Réception des mails transférés depuis ProtonMail
 * Endpoint webhook pour la réception d un mail entrant via STDIN
 * Stocke le mail chiffré (AES-256) en BDD pour traitement ultérieur
 */

require_once __DIR__ . '/config.php';

// ── Créer la table si nécessaire ──
$pdo->exec("
    CREATE TABLE IF NOT EXISTS inbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_email VARCHAR(255),
        from_name VARCHAR(255),
        subject VARCHAR(500),
        body_encrypted TEXT,
        body_iv VARCHAR(64),
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('new','read','processed','archived') DEFAULT 'new',
        agent_summary TEXT DEFAULT NULL,
        agent_action TEXT DEFAULT NULL,
        processed_at DATETIME DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_date (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Clé de chiffrement (dérivée des credentials BDD — jamais en clair dans le code) ──
$encKey = hash('sha256', ($DB_PASS ?? '') . ($INBOX_KEY_SUFFIX ?? getenv('NOSELUS_INBOX_KEY_SUFFIX') ?? ''), true);

function encryptBody(string $plaintext, string $key): array {
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, 0, $iv);
    return ['encrypted' => $encrypted, 'iv' => base64_encode($iv)];
}

function decryptBody(string $encrypted, string $iv, string $key): string {
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, base64_decode($iv));
}

// ── Mode API : lecture des mails par l'agent ──
if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Protection : CLI ou localhost uniquement
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
        http_response_code(403);
        exit('Forbidden');
    }

    $status = $_GET['status'] ?? 'new';
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

    $stmt = $pdo->prepare("SELECT id, from_email, from_name, subject, body_encrypted, body_iv, received_at, status, agent_summary, agent_action FROM inbox WHERE status = :s ORDER BY received_at DESC LIMIT :l");
    $stmt->bindValue(':s', $status);
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $mails = $stmt->fetchAll();

    // Déchiffrer les corps
    foreach ($mails as &$m) {
        $m['body'] = decryptBody($m['body_encrypted'], $m['body_iv'], $encKey);
        unset($m['body_encrypted'], $m['body_iv']);
    }

    header('Content-Type: application/json');
    echo json_encode(['mails' => $mails, 'count' => count($mails)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Mode pipe : réception d un mail depuis STDIN ──
if (php_sapi_name() === 'cli') {
    $raw = file_get_contents('php://stdin');
    if (!$raw) exit(0);

    // Parser les headers et le corps
    $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
    $headerBlock = $parts[0] ?? '';
    $body = $parts[1] ?? '';

    // Extraire From
    $from = '';
    $fromName = '';
    if (preg_match('/^From:\s*(.+)$/mi', $headerBlock, $m)) {
        $fromRaw = trim($m[1]);
        if (preg_match('/^"?([^"<]+)"?\s*<([^>]+)>/', $fromRaw, $fm)) {
            $fromName = trim($fm[1]);
            $from = trim($fm[2]);
        } else {
            $from = $fromRaw;
        }
    }

    // Extraire Subject
    $subject = '';
    if (preg_match('/^Subject:\s*(.+)$/mi', $headerBlock, $m)) {
        $subject = trim($m[1]);
        // Décoder MIME si nécessaire
        if (preg_match('/=\?/', $subject)) {
            $decoded = mb_decode_mimeheader($subject);
            if ($decoded) $subject = $decoded;
        }
    }

    // Décoder le corps si base64 ou quoted-printable
    if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headerBlock)) {
        $body = base64_decode($body);
    } elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $headerBlock)) {
        $body = quoted_printable_decode($body);
    }

    // Convertir en UTF-8 si nécessaire
    if (preg_match('/charset="?([^"\s;]+)/i', $headerBlock, $m)) {
        $charset = strtoupper(trim($m[1]));
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }
    }

    // Nettoyer HTML → texte
    if (preg_match('/Content-Type:\s*text\/html/i', $headerBlock)) {
        $body = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $body));
    }

    $body = trim($body);
    if (!$body && !$subject) exit(0);

    // Chiffrer le corps
    $enc = encryptBody($body, $encKey);

    // Stocker en BDD
    $stmt = $pdo->prepare("INSERT INTO inbox (from_email, from_name, subject, body_encrypted, body_iv) VALUES (:from, :name, :subject, :body, :iv)");
    $stmt->execute([
        ':from' => mb_substr($from, 0, 255),
        ':name' => mb_substr($fromName, 0, 255),
        ':subject' => mb_substr($subject, 0, 500),
        ':body' => $enc['encrypted'],
        ':iv' => $enc['iv'],
    ]);

    // Log
    error_log("[webhook-mail] Received from=$from subject=" . mb_substr($subject, 0, 80));
    exit(0);
}
