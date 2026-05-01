<?php
/**
 * Vote citoyen — 1 vote par IP par élu (pas 1 vote global)
 * Stockage JSON fichier (pas MySQL)
 * GET  ?elu_id=X → {likes, dislikes, userVote}
 * POST {elu_id, vote} → {status: voted|removed|changed, likes, dislikes}
 */

// Headers manuels (on n'utilise pas config.php pour éviter la connexion PDO inutile)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Rate limit simple (10 votes/min par IP)
$voteIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$voteRateFile = __DIR__ . '/cache/data/rate_vote_' . hash('sha256', $voteIp . date('Y-m-d-H-i')) . '.json';
$voteRateData = ['count' => 0, 'start' => time()];
if (file_exists($voteRateFile)) {
    $voteRateData = json_decode(@file_get_contents($voteRateFile), true) ?: $voteRateData;
    if ((time() - ($voteRateData['start'] ?? 0)) > 60) $voteRateData = ['count' => 0, 'start' => time()];
}
$voteRateData['count']++;
@file_put_contents($voteRateFile, json_encode($voteRateData), LOCK_EX);
if ($voteRateData['count'] > 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Trop de votes. Réessayez dans 1 minute.']);
    exit;
}
$allowedOrigins = ['https://nos-elus.fr', 'https://www.nos-elus.fr', 'http://localhost:5173', 'http://localhost:3000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: https://nos-elus.fr');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Config ──
$VOTES_DIR = __DIR__ . '/cache/data';
$VOTES_FILE = $VOTES_DIR . '/votes_citoyens.json';

if (!is_dir($VOTES_DIR)) {
    @mkdir($VOTES_DIR, 0755, true);
}

// ── Helpers ──
function getIpHash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash('sha256', $ip . (getenv('NOSELUS_VOTE_SALT') ?: ''));
}

function withVotesLock(string $file, callable $callback): mixed {
    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        return null;
    }
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : null;
    if (!$data) $data = [];

    $result = $callback($data);

    // Si le callback a modifié les données, réécrire
    if ($result !== null) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function getEluTotals(array $votes, int $eluId): array {
    $likes = 0;
    $dislikes = 0;
    foreach ($votes as $key => $entry) {
        if (($entry['elu_id'] ?? 0) === $eluId) {
            if ($entry['vote'] === 1) $likes++;
            elseif ($entry['vote'] === -1) $dislikes++;
        }
    }
    return ['likes' => $likes, 'dislikes' => $dislikes];
}

// Clé unique : hash(IP) + elu_id
function voteKey(string $ipHash, int $eluId): string {
    return $ipHash . '_' . $eluId;
}

// ── GET ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eluId = filter_input(INPUT_GET, 'elu_id', FILTER_VALIDATE_INT);
    if (!$eluId || $eluId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'elu_id requis']);
        exit;
    }

    $ipHash = getIpHash();
    $key = voteKey($ipHash, $eluId);

    $response = withVotesLock($VOTES_FILE, function (&$votes) use ($eluId, $key) {
        $totals = getEluTotals($votes, $eluId);
        $userVote = isset($votes[$key]) ? $votes[$key]['vote'] : null;

        echo json_encode([
            'likes' => $totals['likes'],
            'dislikes' => $totals['dislikes'],
            'userVote' => $userVote,
        ]);
        exit;
    });

    if ($response === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur de lecture des votes.']);
        exit;
    }
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $eluId = intval($input['elu_id'] ?? 0);
    $vote = intval($input['vote'] ?? 0);

    if ($eluId <= 0 || $eluId > 200000 || !in_array($vote, [1, -1], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'elu_id et vote (1 ou -1) requis']);
        exit;
    }

    $ipHash = getIpHash();
    $key = voteKey($ipHash, $eluId);

    // Cookie anti-revote par élu : vérifier avant le lock
    $cookieKey = 'noselus_vc_' . $eluId;

    $result = withVotesLock($VOTES_FILE, function (&$votes) use ($eluId, $vote, $key) {
        $status = 'voted';

        if (isset($votes[$key])) {
            $existing = $votes[$key]['vote'];
            if ($existing === $vote) {
                // Toggle : même vote → retirer
                unset($votes[$key]);
                $status = 'removed';
            } else {
                // Changer de vote
                $votes[$key]['vote'] = $vote;
                $votes[$key]['updated'] = date('c');
                $status = 'changed';
            }
        } else {
            // Nouveau vote
            $votes[$key] = [
                'elu_id' => $eluId,
                'vote' => $vote,
                'created' => date('c'),
            ];
            $status = 'voted';
        }

        $totals = getEluTotals($votes, $eluId);
        return [
            'status' => $status,
            'likes' => $totals['likes'],
            'dislikes' => $totals['dislikes'],
        ];
    });

    if ($result === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la sauvegarde du vote.']);
        exit;
    }

    // Cookie anti-revote par élu (expire dans 2 ans)
    if ($result['status'] === 'removed') {
        // Supprimer le cookie si vote retiré
        setcookie('noselus_vc_' . $eluId, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie('noselus_vc_' . $eluId, (string)$vote, [
            'expires' => time() + 63072000,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
