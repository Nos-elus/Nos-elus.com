<?php
/**
 * Compteur de visiteurs uniques (par IP hashée, pas de données personnelles).
 * GET  → {"total": 1234}
 * POST → enregistre la visite si nouvelle IP, retourne le total
 */
require_once __DIR__ . '/config.php';
setApiHeaders();

header('Cache-Control: no-store');

$VISITS_FILE = __DIR__ . '/cache/data/visits.json';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$hash = hash('sha256', $ip . ($VISIT_SALT ?? getenv('NOSELUS_VISIT_SALT') ?? ''));

// Lecture atomique avec flock
$fp = fopen($VISITS_FILE, 'c+');
if (!$fp) { jsonResponse(['error' => 'Erreur serveur'], 500); }
flock($fp, LOCK_EX);

$raw = stream_get_contents($fp);
$data = $raw ? json_decode($raw, true) : null;
if (!$data) $data = ['ips' => [], 'total' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['ips'][$hash])) {
        $data['ips'][$hash] = date('Y-m-d');
        $data['total'] = count($data['ips']);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}

flock($fp, LOCK_UN);
fclose($fp);

jsonResponse(['total' => $data['total']]);
