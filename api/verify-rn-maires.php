<?php
/**
 * Vérification des 63 maires classés RN — compare avec les résultats
 * officiels des municipales 2020 (nuance de la liste gagnante).
 * Usage : php verify-rn-maires.php
 */
require_once __DIR__ . '/config.php';
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }

function normCommune(string $s): string {
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

// Nuances RN légitimes
$rnNuances = ['LRN', 'RN'];

// ── Charger les résultats municipales 2020 (winning nuance par commune) ──
$munic = []; // normCommune → nuance gagnante
$ctx = stream_context_create(['http' => ['timeout' => 60]]);

$urls = [
    'https://static.data.gouv.fr/resources/elections-municipales-2020-resultats/20200525-133704/2020-05-18-resultats-communes-de-1000-et-plus.txt',
    'https://static.data.gouv.fr/resources/elections-municipales-2020-resultats/20200525-133805/2020-05-18-resultats-communes-de-moins-de-1000.txt',
];

foreach ($urls as $url) {
    echo "Téléchargement " . basename($url) . "...\n";
    $data = @file_get_contents($url, false, $ctx);
    if (!$data) { echo "  ERREUR\n"; continue; }
    // Convertir ISO-8859-1 si nécessaire
    if (!mb_check_encoding($data, 'UTF-8')) $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');

    $lines = explode("\n", $data);
    $header = array_shift($lines);
    // Détecter séparateur
    $sep = substr_count($header, "\t") > 5 ? "\t" : ";";

    $before = count($munic);
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $cols = str_getcsv($line, $sep);
        $libCom = trim($cols[3] ?? '');
        if (!$libCom) continue;

        $bestNuance = null; $bestSieges = -1;
        for ($i = 18; $i + 6 < count($cols); $i += 12) {
            $nuance = trim($cols[$i + 1] ?? '');
            $sieges = (int) str_replace([',', ' '], ['.', ''], $cols[$i + 6] ?? '0');
            if (!$nuance || in_array($nuance, ['LNC', 'NC', ''])) continue;
            if ($sieges > $bestSieges) { $bestSieges = $sieges; $bestNuance = $nuance; }
        }
        if ($bestNuance) $munic[normCommune($libCom)] = $bestNuance;
    }
    echo "  +" . (count($munic) - $before) . " communes → total " . count($munic) . "\n";
    unset($data, $lines);
}

// ── Charger les 63 maires RN ──
$stmt = $pdo->query("SELECT id, nom, prenom, fonction, departement FROM elus WHERE type_mandat='maire' AND parti='Rassemblement national' ORDER BY departement, nom");
$maires = $stmt->fetchAll();
echo "\n" . count($maires) . " maires RN à vérifier\n\n";

$erreurs = []; $ok = []; $notFound = [];

foreach ($maires as $m) {
    // Extraire nom de commune
    // Extraire commune — gère "Maire — X", "Ancien(ne) Maire — X", "Député — Y / Maire — X"
    if (!preg_match('/Maire\s*[\x{2014}\-]\s*(.+)$/iu', $m['fonction'], $match)) { $notFound[] = $m; continue; }
    $commune = trim(preg_replace('/\s*[\/\|].+$/', '', $match[1]));
    $commune = trim(preg_replace('/^[\s\-—]+/', '', $commune)); // strip leading dashes
    $key = normCommune($commune);

    if (!isset($munic[$key])) { $notFound[] = array_merge($m, ['commune' => $commune]); continue; }

    $nuanceReelle = $munic[$key];
    $estRn = in_array($nuanceReelle, $rnNuances);

    if ($estRn) {
        $ok[] = array_merge($m, ['commune' => $commune, 'nuance' => $nuanceReelle]);
    } else {
        $erreurs[] = array_merge($m, ['commune' => $commune, 'nuance_reelle' => $nuanceReelle]);
    }
}

echo "=== LÉGITIMEMENT RN (" . count($ok) . ") ===\n";
foreach ($ok as $m) echo "  ✓ {$m['prenom']} {$m['nom']} — {$m['commune']} [{$m['nuance']}]\n";

echo "\n=== MAUVAISE ATTRIBUTION ({". count($erreurs) ."}) ===\n";
foreach ($erreurs as $m) {
    echo "  ✗ [{$m['id']}] {$m['prenom']} {$m['nom']} — {$m['commune']} : RN stocké, nuance réelle = {$m['nuance_reelle']}\n";
}

echo "\n=== COMMUNE NON TROUVÉE (" . count($notFound) . ") ===\n";
foreach ($notFound as $m) echo "  ? [{$m['id']}] {$m['prenom']} {$m['nom']} — " . ($m['commune'] ?? $m['fonction']) . "\n";

echo "\n=== RÉSUMÉ ===\n";
echo "Légitimement RN : " . count($ok) . "\n";
echo "Mauvaise attrib : " . count($erreurs) . "\n";
echo "Commune introuvable : " . count($notFound) . "\n";
