<?php
/**
 * CRON — Veille juridique via LegifrSS (proxy RSS Legifrance)
 * Fetche lois + decisions CC, stocke en BDD, detecte mentions d'elus
 *
 * Usage: php cron-veille-juridique.php
 * Cron suggere: 0 6 * * * (1x/jour a 6h)
 */

ini_set('max_execution_time', 120);
$startTime = microtime(true);

require_once __DIR__ . '/config.php';

// ── Protection : CLI ou localhost uniquement ──
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

// ── 1. Creer la table si elle n'existe pas ──
$pdo->exec("
    CREATE TABLE IF NOT EXISTS veille_juridique (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(50) NOT NULL,
        titre VARCHAR(500) NOT NULL,
        url VARCHAR(500),
        date_publication DATE,
        nature VARCHAR(50),
        contenu TEXT,
        traite TINYINT(1) DEFAULT 0,
        elu_ids_detectes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_url (url(255)),
        INDEX idx_nature (nature),
        INDEX idx_date (date_publication)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Flux a fetcher ──
$feeds = [
    ['url' => 'https://legifrss.org/latest?nature=LOI',                         'source' => 'legifrss', 'nature' => 'LOI'],
    ['url' => 'https://legifrss.org/latest?nature=DECISION',                    'source' => 'legifrss', 'nature' => 'DECISION'],
    ['url' => 'https://legifrss.org/latest?author=Conseil%20constitutionnel',   'source' => 'legifrss', 'nature' => 'DECISION_CC'],
];

// ── 3. Charger top 200 elus les plus consultes (pour detection de noms) ──
$topElus = $pdo->query("
    SELECT id, nom, prenom
    FROM elus
    WHERE actif = 1
    ORDER BY nb_consultations DESC
    LIMIT 200
")->fetchAll();

// Construire un index nom => id pour la detection
$eluIndex = [];
foreach ($topElus as $elu) {
    // Nom complet "Prenom Nom" et "Nom" seul
    $nom = trim($elu['nom']);
    $prenom = trim($elu['prenom'] ?? '');

    if ($nom) {
        $eluIndex[$nom] = $elu['id'];
    }
    if ($prenom && $nom) {
        $eluIndex[$prenom . ' ' . $nom] = $elu['id'];
        $eluIndex[$nom . ' ' . $prenom] = $elu['id']; // ordre inverse
    }
}

// ── 4. Fetch + parse chaque flux ──
$stmtInsert = $pdo->prepare("
    INSERT IGNORE INTO veille_juridique (source, titre, url, date_publication, nature, contenu)
    VALUES (:source, :titre, :url, :date_pub, :nature, :contenu)
");

$stmtUpdateElus = $pdo->prepare("
    UPDATE veille_juridique SET elu_ids_detectes = :ids, traite = 1 WHERE url = :url
");

$totalInserted = 0;
$countByNature = ['LOI' => 0, 'DECISION' => 0, 'DECISION_CC' => 0];
$errors = [];

foreach ($feeds as $feed) {
    $feedUrl = $feed['url'];
    $nature  = $feed['nature'];
    $source  = $feed['source'];

    // Fetch XML
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'nos-elus.fr/veille-juridique (cron)',
            'header' => "Accept: application/atom+xml, application/xml, text/xml\r\n",
        ],
    ]);

    $xml = @file_get_contents($feedUrl, false, $ctx);

    if ($xml === false) {
        $errors[] = "Echec fetch: $feedUrl";
        continue;
    }

    // Parser Atom XML
    libxml_use_internal_errors(true);
    $atom = simplexml_load_string($xml);

    if ($atom === false) {
        $errors[] = "Echec parse XML: $feedUrl";
        continue;
    }

    // Gerer les namespaces Atom
    $namespaces = $atom->getNamespaces(true);
    $atomNs = $namespaces[''] ?? 'http://www.w3.org/2005/Atom';
    $atom->registerXPathNamespace('atom', $atomNs);

    $entries = $atom->xpath('//atom:entry');
    if (empty($entries)) {
        // Essayer sans namespace
        $entries = $atom->entry ?? [];
    }

    $feedInserted = 0;

    foreach ($entries as $entry) {
        $titre = trim((string)($entry->title ?? ''));
        $url   = trim((string)($entry->id ?? ''));
        $date  = trim((string)($entry->updated ?? $entry->published ?? ''));

        // Extraire contenu (peut etre dans content ou summary)
        $contenu = trim((string)($entry->content ?? $entry->summary ?? ''));

        // Nettoyer la date pour MySQL
        $datePub = null;
        if ($date) {
            $ts = strtotime($date);
            if ($ts !== false) {
                $datePub = date('Y-m-d', $ts);
            }
        }

        if (!$titre || !$url) {
            continue;
        }

        // Tronquer si trop long
        $titre = mb_substr($titre, 0, 500);
        $url   = mb_substr($url, 0, 500);

        try {
            $stmtInsert->execute([
                ':source'   => $source,
                ':titre'    => $titre,
                ':url'      => $url,
                ':date_pub' => $datePub,
                ':nature'   => $nature,
                ':contenu'  => $contenu ?: null,
            ]);

            if ($stmtInsert->rowCount() > 0) {
                $feedInserted++;
                $totalInserted++;
                $countByNature[$nature]++;

                // ── 5. Detecter mentions d'elus dans le contenu ──
                $textToSearch = $titre . ' ' . $contenu;
                $detectedIds = [];

                foreach ($eluIndex as $name => $eluId) {
                    // Recherche insensible a la casse avec limites de mots
                    if (mb_strlen($name) >= 4 && mb_stripos($textToSearch, $name) !== false) {
                        $detectedIds[$eluId] = true;
                    }
                }

                if (!empty($detectedIds)) {
                    $idsStr = implode(',', array_keys($detectedIds));
                    $stmtUpdateElus->execute([':ids' => $idsStr, ':url' => $url]);
                }
            }
        } catch (PDOException $e) {
            // IGNORE duplicates silently (INSERT IGNORE), log others
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                $errors[] = "Insert error: " . $e->getMessage();
            }
        }
    }

    echo "[OK] $feedUrl -> $feedInserted nouvelles entrees ($nature)\n";
}

// ── 6. Log dans fetch_log ──
$durationMs = (int)((microtime(true) - $startTime) * 1000);
$status = empty($errors) ? 'success' : (($totalInserted > 0) ? 'partial' : 'error');
$errorMsg = !empty($errors) ? implode(' | ', $errors) : null;

$pdo->prepare("
    INSERT INTO fetch_log (source, endpoint, status, records_count, error_message, duration_ms)
    VALUES (:source, :endpoint, :status, :count, :error, :duration)
")->execute([
    ':source'   => 'legifrss',
    ':endpoint' => 'cron-veille-juridique',
    ':status'   => $status,
    ':count'    => $totalInserted,
    ':error'    => $errorMsg,
    ':duration' => $durationMs,
]);

// ── Resume ──
echo "\n=== RESUME VEILLE JURIDIQUE ===\n";
echo "Total insere: $totalInserted\n";
echo "  LOI:         {$countByNature['LOI']}\n";
echo "  DECISION:    {$countByNature['DECISION']}\n";
echo "  DECISION_CC: {$countByNature['DECISION_CC']}\n";
echo "Duree: {$durationMs}ms\n";
echo "Statut: $status\n";

if (!empty($errors)) {
    echo "\nErreurs:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

// Compter le total en BDD
$totalBdd = $pdo->query("SELECT COUNT(*) FROM veille_juridique")->fetchColumn();
$withElus = $pdo->query("SELECT COUNT(*) FROM veille_juridique WHERE elu_ids_detectes IS NOT NULL")->fetchColumn();
echo "\nTotal en BDD: $totalBdd entrees (dont $withElus avec elus detectes)\n";
