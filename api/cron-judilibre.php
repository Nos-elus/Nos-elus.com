<?php
/**
 * CRON — Surveillance des décisions de justice (API JUDILIBRE / PISTE)
 * Recherche les décisions récentes mentionnant inéligibilité, corruption, etc.
 * Usage : php cron-judilibre.php
 */

require_once __DIR__ . '/config.php';

// ── Protection : CLI ou localhost uniquement ──
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

$startTime = microtime(true);

// ── Config PISTE / JUDILIBRE ──
$PISTE_TOKEN_URL  = 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token';
$PISTE_CLIENT_ID  = getenv('PISTE_CLIENT_ID') ?: '';
$PISTE_CLIENT_SEC = getenv('PISTE_CLIENT_SEC') ?: '';
$PISTE_KEY_ID     = getenv('PISTE_KEY_ID') ?: '';
$JUDILIBRE_BASE   = 'https://sandbox-api.piste.gouv.fr/cassation/judilibre/v1.0';

$SEARCH_QUERIES = [
    'ineligibilite',
    'inéligibilité',
    'corruption elu',
    'detournement fonds publics',
    'prise illegale interets',
];

// ── Helpers ──
function logLine(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

// ── 1. Créer la table si nécessaire ──
logLine('Création table judilibre_decisions si nécessaire...');
$pdo->exec("
    CREATE TABLE IF NOT EXISTS judilibre_decisions (
        id VARCHAR(50) PRIMARY KEY,
        numero VARCHAR(50),
        juridiction VARCHAR(100),
        chambre VARCHAR(200),
        date_decision DATE,
        solution VARCHAR(100),
        themes TEXT,
        resume TEXT,
        texte_extrait TEXT,
        elu_id INT DEFAULT NULL,
        traite TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE SET NULL,
        INDEX idx_date (date_decision),
        INDEX idx_elu (elu_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Authentification OAuth2 ──
logLine('Authentification OAuth2 PISTE...');

$ch = curl_init($PISTE_TOKEN_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $PISTE_CLIENT_ID,
        'client_secret' => $PISTE_CLIENT_SEC,
        'scope'         => 'openid',
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);

$tokenResp = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr   = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$tokenResp) {
    $errMsg = "Erreur OAuth2 (HTTP $httpCode): $curlErr — $tokenResp";
    logLine($errMsg);
    // Log en BDD
    $pdo->prepare("
        INSERT INTO fetch_log (source, endpoint, status, records_count, error_message, duration_ms)
        VALUES ('judilibre', 'oauth/token', 'error', 0, :msg, :duration)
    ")->execute([
        ':msg'      => mb_substr($errMsg, 0, 1000),
        ':duration' => (int)((microtime(true) - $startTime) * 1000),
    ]);
    exit(1);
}

$tokenData   = json_decode($tokenResp, true);
$accessToken = $tokenData['access_token'] ?? null;

if (!$accessToken) {
    logLine('Pas de access_token dans la réponse : ' . $tokenResp);
    exit(1);
}

logLine('Token obtenu (' . strlen($accessToken) . ' chars)');

// ── 3. Charger le top 500 élus pour le matching ──
logLine('Chargement top 500 élus pour matching noms...');
$topElus = $pdo->query("
    SELECT id, nom
    FROM elus
    ORDER BY nb_consultations DESC
    LIMIT 500
")->fetchAll();

// Préparer un tableau nom => id (nettoyé, sans accents pour fuzzy)
$eluIndex = [];
foreach ($topElus as $elu) {
    $nomClean = mb_strtolower(trim($elu['nom']));
    $eluIndex[$nomClean] = (int)$elu['id'];
}
logLine(count($eluIndex) . ' élus chargés pour matching');

// ── 4. Fonction de recherche JUDILIBRE ──
function judilibreSearch(string $query, string $accessToken, string $keyId, string $baseUrl): ?array {
    // Date limite : 3 derniers mois
    $dateAfter = date('Y-m-d', strtotime('-12 months'));

    $url = $baseUrl . '/search?' . http_build_query([
        'query'      => $query,
        'date_start' => $dateAfter,
        'page_size'  => 50,
        'order'      => 'desc',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'KeyId: ' . $keyId,
            'Accept: application/json',
        ],
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        logLine("  Erreur recherche (HTTP $httpCode) pour '$query': $curlErr — " . mb_substr($resp, 0, 300));
        return null;
    }

    return json_decode($resp, true);
}

// ── 5. Fonction de matching élu ──
function matchElu(string $texte, array $eluIndex): ?int {
    $texteLower = mb_strtolower($texte);
    foreach ($eluIndex as $nom => $eluId) {
        // Chercher le nom complet dans le texte
        // Ne matcher que si le nom fait au moins 5 chars (éviter faux positifs)
        if (mb_strlen($nom) >= 5 && mb_strpos($texteLower, $nom) !== false) {
            return $eluId;
        }
    }
    return null;
}

// ── 6. Boucle de recherche ──
$stmtInsert = $pdo->prepare("
    INSERT IGNORE INTO judilibre_decisions
        (id, numero, juridiction, chambre, date_decision, solution, themes, resume, texte_extrait, elu_id)
    VALUES
        (:id, :numero, :juridiction, :chambre, :date_decision, :solution, :themes, :resume, :texte_extrait, :elu_id)
");

$totalFound    = 0;
$totalInserted = 0;
$totalLinked   = 0;

foreach ($SEARCH_QUERIES as $query) {
    logLine("Recherche : '$query'...");

    $result = judilibreSearch($query, $accessToken, $PISTE_KEY_ID, $JUDILIBRE_BASE);

    if (!$result) {
        continue;
    }

    $results = $result['results'] ?? [];
    $nbResults = count($results);
    $totalFound += $nbResults;
    logLine("  -> $nbResults résultat(s)");

    foreach ($results as $dec) {
        $decId = $dec['id'] ?? null;
        if (!$decId) continue;

        // Extraire highlights (résumé avec <em>)
        $highlights = [];
        if (!empty($dec['highlights'])) {
            foreach ($dec['highlights'] as $field => $fragments) {
                if (is_array($fragments)) {
                    $highlights = array_merge($highlights, $fragments);
                }
            }
        }
        $resume = implode(' [...] ', array_slice($highlights, 0, 5));
        if (!$resume) {
            $resume = $dec['text'] ?? $dec['sommaire'] ?? '';
            $resume = mb_substr($resume, 0, 500);
        }

        // Themes
        $themes = '';
        if (!empty($dec['themes'])) {
            $themes = is_array($dec['themes']) ? implode(', ', $dec['themes']) : (string)$dec['themes'];
        }

        // Texte extrait (pour matching)
        $texteExtrait = strip_tags($resume) . ' ' . ($dec['text'] ?? '') . ' ' . ($dec['sommaire'] ?? '');
        $texteExtrait = mb_substr($texteExtrait, 0, 2000);

        // Matching élu
        $eluId = matchElu($texteExtrait, $eluIndex);

        $inserted = $stmtInsert->execute([
            ':id'             => $decId,
            ':numero'         => $dec['number'] ?? $dec['numero'] ?? null,
            ':juridiction'    => $dec['jurisdiction'] ?? $dec['juridiction'] ?? null,
            ':chambre'        => $dec['chamber'] ?? $dec['chambre'] ?? null,
            ':date_decision'  => $dec['decision_date'] ?? $dec['date'] ?? null,
            ':solution'       => $dec['solution'] ?? null,
            ':themes'         => $themes ?: null,
            ':resume'         => $resume ? mb_substr($resume, 0, 2000) : null,
            ':texte_extrait'  => $texteExtrait ?: null,
            ':elu_id'         => $eluId,
        ]);

        if ($stmtInsert->rowCount() > 0) {
            $totalInserted++;
            if ($eluId) {
                $totalLinked++;
                logLine("  [MATCH ELU] Décision $decId liée à élu #$eluId");
            }
        }
    }

    // Petit délai entre requêtes pour ne pas saturer l'API
    usleep(500000); // 500ms
}

// ── 7. Log final ──
$durationMs = (int)((microtime(true) - $startTime) * 1000);

$summary = json_encode([
    'found'    => $totalFound,
    'inserted' => $totalInserted,
    'linked'   => $totalLinked,
    'queries'  => count($SEARCH_QUERIES),
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("
    INSERT INTO fetch_log (source, endpoint, status, records_count, error_message, duration_ms)
    VALUES ('judilibre', 'search', :status, :count, :msg, :duration)
")->execute([
    ':status'   => $totalFound > 0 ? 'success' : 'empty',
    ':count'    => $totalInserted,
    ':msg'      => $summary,
    ':duration' => $durationMs,
]);

logLine('');
logLine('=== BILAN ===');
logLine("Décisions trouvées  : $totalFound");
logLine("Décisions insérées  : $totalInserted");
logLine("Liées à un élu      : $totalLinked");
logLine("Durée               : {$durationMs}ms");
logLine('=== FIN ===');
