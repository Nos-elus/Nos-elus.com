<?php
/**
 * Vote Presidentielle 2027 — Vote Unique Transferable (simplifie)
 * GET  ?candidats=1 : liste des candidats depuis candidats_2027.json
 * GET  ?check=1     : classement du votant courant
 * GET  (defaut)     : resultats agreges (score VUT)
 * POST : soumettre un classement (tout sous-ensemble valide)
 */

require_once __DIR__ . '/config.php';
setApiHeaders();
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

checkRateLimit(30, 60);

const VOTES_FILE    = __DIR__ . '/cache/data/votes_2027.json';
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

// ── Helpers ──

function withVotesLock(callable $callback): mixed {
    $dir = dirname(VOTES_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fp = fopen(VOTES_FILE, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        return null;
    }
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : null;
    if (!$data) $data = ['votes' => [], 'updated' => date('c')];

    $result = $callback($data);

    if ($result !== null) {
        $data['updated'] = date('c');
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function ipHash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash('sha256', $ip . (getenv('NOSELUS_VOTE2027_SALT') ?: ''));
}

// ── GET ?candidats=1 : liste des candidats depuis JSON ──

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['candidats'])) {
    jsonResponse(['candidats' => loadCandidats()]);
}

// ── GET : resultats agreges ──

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hash     = ipHash();
    $validIds = getCandidatIds();

    $response = withVotesLock(function (&$data) use ($hash, $validIds) {
        if (isset($_GET['check'])) {
            $hasVoted  = isset($data['votes'][$hash]);
            $classement = $hasVoted ? $data['votes'][$hash] : null;
            if (!$hasVoted && isset($_COOKIE['noselus_v27'])) {
                setcookie('noselus_v27', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
            }
            jsonResponse(['voted' => $hasVoted, 'classement' => $classement]);
        }

        $votes   = $data['votes'];
        $scores  = [];
        $premiers = [];

        foreach ($votes as $classement) {
            if (!is_array($classement) || empty($classement)) continue;
            $n = count($classement);
            foreach ($classement as $rang => $candidat) {
                // Ignorer les anciens candidats retirés du JSON
                if (!in_array($candidat, $validIds, true)) continue;
                if (!isset($scores[$candidat])) {
                    $scores[$candidat]  = 0;
                    $premiers[$candidat] = 0;
                }
                // Points décroissants basés sur la longueur propre du vote
                $scores[$candidat] += ($n - $rang);
                if ($rang === 0) $premiers[$candidat]++;
            }
        }

        arsort($scores);

        $resultats = [];
        foreach ($scores as $id => $score) {
            $resultats[] = [
                'id'              => $id,
                'score'           => $score,
                'nb_votes_premier' => $premiers[$id] ?? 0,
            ];
        }

        jsonResponse([
            'resultats'    => $resultats,
            'total_votes'  => count($votes),
            'updated'      => $data['updated'],
        ]);

        return null;
    });

    if ($response === null) {
        jsonResponse(['error' => 'Erreur de lecture des votes.'], 500);
    }
}

// ── POST : soumettre un vote ──

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['classement']) || !is_array($input['classement'])) {
        jsonResponse(['error' => 'Format invalide. Envoyez { "classement": ["id1", "id2", ...] }'], 400);
    }

    $classement = $input['classement'];
    $validIds   = getCandidatIds();

    if (empty($classement)) {
        jsonResponse(['error' => 'Le classement ne peut pas etre vide.'], 400);
    }

    // Pas de doublons
    if (count(array_unique($classement)) !== count($classement)) {
        jsonResponse(['error' => 'Doublons detectes dans le classement.'], 400);
    }

    // Tous les IDs doivent être dans le JSON courant
    foreach ($classement as $id) {
        if (!is_string($id) || !in_array($id, $validIds, true)) {
            jsonResponse(['error' => "Candidat invalide : $id"], 400);
        }
    }

    $hash     = ipHash();
    $isChange = isset($input['change']) && $input['change'] === true;

    if (!$isChange) {
        if (isset($_COOKIE['noselus_v27'])) {
            $checkData = json_decode(file_get_contents(VOTES_FILE) ?: '{}', true);
            if (isset($checkData['votes'][$hash])) {
                jsonResponse(['error' => 'Vous avez deja vote. Utilisez "Changer d\'avis" pour modifier.'], 409);
            }
        }
    }

    $result = withVotesLock(function (&$data) use ($hash, $classement, $isChange) {
        $exists = isset($data['votes'][$hash]);
        if ($exists && !$isChange) {
            jsonResponse(['error' => 'Vous avez deja vote. Utilisez "Changer d\'avis" pour modifier.'], 409);
        }
        $data['votes'][$hash] = $classement;
        return true;
    });

    if ($result === null) {
        jsonResponse(['error' => 'Erreur lors de la sauvegarde du vote.'], 500);
    }

    setcookie('noselus_v27', '1', [
        'expires'  => time() + 63072000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    jsonResponse(
        ['success' => true, 'message' => $isChange ? 'Vote modifie.' : 'Vote enregistre.'],
        $isChange ? 200 : 201
    );
}

// ── DELETE : retirer son vote ──

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $hash = ipHash();

    $result = withVotesLock(function (&$data) use ($hash) {
        if (!isset($data['votes'][$hash])) {
            jsonResponse(['error' => 'Aucun vote a retirer.'], 404);
        }
        unset($data['votes'][$hash]);
        return true;
    });

    if ($result === null) {
        jsonResponse(['error' => 'Erreur lors de la suppression.'], 500);
    }

    setcookie('noselus_v27', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    jsonResponse(['success' => true, 'message' => 'Vote retire.']);
}

jsonResponse(['error' => 'Methode non supportee.'], 405);
