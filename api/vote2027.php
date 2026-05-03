<?php
/**
 * Vote Présidentielle 2027 — Vote Unique Transférable
 * Anti-revote : hash(IP + sel) en BDD (table votes_2027). IP fournie par client
 * (REMOTE_ADDR cassé par proxy = loopback). Aucune IP en clair stockée.
 *
 * GET  ?candidats=1     : liste candidats (candidats_2027.json)
 * GET  ?check=1&ip=...  : classement du votant courant
 * GET  (défaut)         : résultats agrégés (score VUT)
 * POST {classement, ip, change?}  : soumettre un classement
 * DELETE {ip}           : retirer son vote
 */

require_once __DIR__ . '/config.php';
setApiHeaders();
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

checkRateLimit(30, 60);

const CANDIDATS_FILE = __DIR__ . '/candidats_2027.json';

function loadCandidats(): array {
    $json = @file_get_contents(CANDIDATS_FILE);
    if (!$json) return [];
    $data = json_decode($json, true);
    return $data['candidats'] ?? [];
}

function getCandidatIds(): array {
    return array_column(loadCandidats(), 'id');
}

/** Hash IP sécurisé. Aucune IP en clair stockée nulle part. */
function ipHash(?array $body = null): ?string {
    $salt = getenv('NOSELUS_VOTE2027_SALT') ?: '';
    $ip = null;
    if (is_array($body) && !empty($body['ip']) && filter_var($body['ip'], FILTER_VALIDATE_IP)) {
        $ip = $body['ip'];
    } elseif (!empty($_GET['ip']) && filter_var($_GET['ip'], FILTER_VALIDATE_IP)) {
        $ip = $_GET['ip'];
    }
    // Fallback REMOTE_ADDR (non fiable derrière proxy, mais conserve compat)
    if (!$ip) {
        $ra = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ra && filter_var($ra, FILTER_VALIDATE_IP)) $ip = $ra;
    }
    if (!$ip) return null;
    return hash('sha256', $ip . $salt);
}

// ── GET ?candidats=1 ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['candidats'])) {
    jsonResponse(['candidats' => loadCandidats()]);
}

// ── GET ?check=1 : a-t-il déjà voté ? ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
    $hash = ipHash();
    if (!$hash) jsonResponse(['voted' => false, 'classement' => null]);
    $stmt = $pdo->prepare("SELECT classement FROM votes_2027 WHERE ip_hash = :h LIMIT 1");
    $stmt->execute([':h' => $hash]);
    $row = $stmt->fetch();
    if ($row) {
        $cl = json_decode($row['classement'], true);
        jsonResponse(['voted' => true, 'classement' => is_array($cl) ? $cl : null]);
    }
    jsonResponse(['voted' => false, 'classement' => null]);
}

// ── GET (défaut) : résultats agrégés ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $validIds = getCandidatIds();
    $rows = $pdo->query("SELECT classement, updated_at FROM votes_2027")->fetchAll();
    $scores = []; $premiers = []; $updated = null;
    foreach ($rows as $r) {
        if (!$updated || $r['updated_at'] > $updated) $updated = $r['updated_at'];
        $classement = json_decode($r['classement'], true);
        if (!is_array($classement) || empty($classement)) continue;
        $n = count($classement);
        foreach ($classement as $rang => $candidat) {
            if (!in_array($candidat, $validIds, true)) continue;
            if (!isset($scores[$candidat])) { $scores[$candidat] = 0; $premiers[$candidat] = 0; }
            $scores[$candidat] += ($n - $rang);
            if ($rang === 0) $premiers[$candidat]++;
        }
    }
    arsort($scores);
    $resultats = [];
    foreach ($scores as $id => $score) {
        $resultats[] = ['id' => $id, 'score' => $score, 'nb_votes_premier' => $premiers[$id] ?? 0];
    }
    jsonResponse([
        'resultats'   => $resultats,
        'total_votes' => count($rows),
        'updated'     => $updated ?: date('c'),
    ]);
}

// ── POST : soumettre un vote ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['classement']) || !is_array($input['classement'])) {
        jsonResponse(['error' => 'Format invalide. Envoyez { "classement": ["id1", ...], "ip": "x.x.x.x" }'], 400);
    }

    // Anti-CSRF
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $csrfOk = preg_match('#^https?://(www\.)?nos-elus\.com(/|$)#', $origin)
           || preg_match('#^https?://(www\.)?nos-elus\.com(/|$)#', $referer)
           || preg_match('#^http://localhost:(5173|3000)(/|$)#', $referer);
    if (!$csrfOk) {
        jsonResponse(['error' => 'Origin/Referer invalide.'], 403);
    }

    $classement = $input['classement'];
    $validIds   = getCandidatIds();

    if (empty($classement)) jsonResponse(['error' => 'Le classement ne peut pas être vide.'], 400);
    if (count(array_unique($classement)) !== count($classement)) {
        jsonResponse(['error' => 'Doublons détectés dans le classement.'], 400);
    }
    foreach ($classement as $id) {
        if (!is_string($id) || !in_array($id, $validIds, true)) {
            jsonResponse(['error' => "Candidat invalide : $id"], 400);
        }
    }

    $hash = ipHash($input);
    if (!$hash) jsonResponse(['error' => 'IP requise (anti-revote).'], 400);

    $isChange = isset($input['change']) && $input['change'] === true;

    $stmt = $pdo->prepare("SELECT id FROM votes_2027 WHERE ip_hash = :h LIMIT 1");
    $stmt->execute([':h' => $hash]);
    $exists = (bool) $stmt->fetchColumn();

    if ($exists && !$isChange) {
        jsonResponse(['error' => 'Vous avez déjà voté. Utilisez "Changer d\'avis" pour modifier.'], 409);
    }

    $stmtUp = $pdo->prepare(
        "INSERT INTO votes_2027 (ip_hash, classement) VALUES (:h, :c)
         ON DUPLICATE KEY UPDATE classement = VALUES(classement)"
    );
    $stmtUp->execute([':h' => $hash, ':c' => json_encode($classement, JSON_UNESCAPED_UNICODE)]);

    jsonResponse(
        ['success' => true, 'message' => $isChange ? 'Vote modifié.' : 'Vote enregistré.'],
        $isChange ? 200 : 201
    );
}

// ── DELETE : retirer son vote ──
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?: null;
    $hash = ipHash($input);
    if (!$hash) jsonResponse(['error' => 'IP requise.'], 400);
    $stmt = $pdo->prepare("DELETE FROM votes_2027 WHERE ip_hash = :h");
    $stmt->execute([':h' => $hash]);
    if ($stmt->rowCount() === 0) jsonResponse(['error' => 'Aucun vote à retirer.'], 404);
    jsonResponse(['success' => true, 'message' => 'Vote retiré.']);
}

jsonResponse(['error' => 'Méthode non supportée.'], 405);
