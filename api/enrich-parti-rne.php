<?php
/**
 * Enrichissement parti politique des maires sans parti
 * Sources : 1) Municipales 2020 (data.gouv.fr) — 2) CSV RNE nuances enrichi
 * RÈGLE ABSOLUE : UPDATE uniquement si parti IS NULL/vide/SE/Sans étiquette
 *
 * Usage : php enrich-parti-rne.php [--dry-run]
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

$dryRun = in_array('--dry-run', $argv ?? []);
echo "=== ENRICHISSEMENT PARTI MAIRES — " . date('Y-m-d H:i:s') . " ===\n";
echo "Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE') . "\n\n";

// ── Mapping nuance → parti ──
$partiMap = [
    // Nuances RNE (préfixe L)
    'LRN'  => 'Rassemblement National', 'LEXD' => 'Extrême droite',
    'LLR'  => 'Les Républicains', 'LUD'  => 'Union de la Droite', 'LDVD' => 'Divers droite',
    'LREM' => 'Renaissance', 'LMDM' => 'MoDem', 'LDVC' => 'Divers centre',
    'LUC'  => 'Union du Centre', 'LUDI' => 'UDI',
    'LSOC' => 'Parti socialiste', 'LUG'  => 'Union de la Gauche', 'LDVG' => 'Divers gauche',
    'LCOM' => 'Parti communiste', 'LVEC' => 'Les Écologistes', 'LRDG' => 'Parti radical de gauche',
    'LECO' => 'Écologiste', 'LREG' => 'Régionaliste', 'LDIV' => 'Divers',
    // Nuances municipales 2020 (sans préfixe L)
    'RN'   => 'Rassemblement National', 'EXD'  => 'Extrême droite',
    'LR'   => 'Les Républicains', 'UD'   => 'Union de la Droite', 'DVD'  => 'Divers droite',
    'REM'  => 'Renaissance', 'LAREM'=> 'Renaissance', 'MDM'  => 'MoDem',
    'DVC'  => 'Divers centre', 'UC'   => 'Union du Centre', 'UDI'  => 'UDI',
    'SOC'  => 'Parti socialiste', 'UG'   => 'Union de la Gauche', 'DVG'  => 'Divers gauche',
    'COM'  => 'Parti communiste', 'VEC'  => 'Les Écologistes', 'RDG'  => 'Parti radical de gauche',
    'ECO'  => 'Écologiste', 'REG'  => 'Régionaliste', 'DIV'  => 'Divers',
    'FI'   => 'La France insoumise', 'EXG'  => 'Extrême gauche',
];

// ── Fonction normalisation nom commune ──
function normCommune(string $s): string {
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
}

// ══════════════════════════════════════════════════════════════
// SOURCE 1 : Résultats municipales 2020 (data.gouv.fr, TSV)
// ══════════════════════════════════════════════════════════════
// Index : code_insee => nuance de la liste gagnante (1ère ligne = plus de sièges)
$munic2020 = []; // code_insee => nuance
$munic2020ByName = []; // nom_normalisé => nuance

$tsvUrls = [
    'https://static.data.gouv.fr/resources/elections-municipales-2020-resultats/20200525-133704/2020-05-18-resultats-communes-de-1000-et-plus.txt',
    'https://static.data.gouv.fr/resources/elections-municipales-2020-resultats/20200525-133805/2020-05-18-resultats-communes-de-moins-de-1000.txt',
];

foreach ($tsvUrls as $url) {
    $basename = basename($url);
    echo "Téléchargement $basename...\n";
    $ctx = stream_context_create(['http' => ['timeout' => 120]]);
    $data = @file_get_contents($url, false, $ctx);
    if (!$data) { echo "  ERREUR: téléchargement échoué, skip\n"; continue; }
    // Convertir ISO-8859-1 → UTF-8
    if (!mb_check_encoding($data, 'UTF-8')) {
        $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
    }
    echo "  " . round(strlen($data) / 1024) . " Ko\n";

    $lines = explode("\n", $data);
    $firstLine = $lines[0] ?? '';
    // Détecter le séparateur (tab ou point-virgule)
    $sep = (substr_count($firstLine, "\t") > 5) ? "\t" : ";";
    $header = str_getcsv(array_shift($lines), $sep);
    echo "  Séparateur: " . ($sep === "\t" ? "TAB" : ";") . " — " . count($header) . " colonnes\n";

    // Colonnes fixes : 0=dept, 2=code_com, 3=lib_com, 19=nuance
    $colDept = 0; $colCom = 2; $colLibCom = 3; $colNuance = 19;
    echo "  Colonnes: dept=$colDept com=$colCom lib=$colLibCom nuance=$colNuance\n";

    // Format large : 1 ligne/commune, toutes les listes en colonnes (blocs de 12).
    // Cols 0-17 = données commune. Blocs liste : offset 1=Nuance, 6=Sièges/Elu.
    // On cherche la liste avec le max de Sièges/Elu (vrai gagnant, pas N.Pan.=1).
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $cols = str_getcsv($line, $sep);
        $dept = trim($cols[$colDept] ?? '');
        $com  = trim($cols[$colCom] ?? '');
        $libCom = trim($cols[$colLibCom] ?? '');
        if (!$dept || !$com) continue;

        $deptPad = str_pad($dept, strlen($dept) <= 2 ? 2 : 3, '0', STR_PAD_LEFT);
        $comPad  = str_pad($com, 3, '0', STR_PAD_LEFT);
        $insee   = $deptPad . $comPad;

        $bestNuance = null;
        $bestSieges = -1;
        for ($i = 18; $i + 6 < count($cols); $i += 12) {
            $nuance = trim($cols[$i + 1] ?? '');
            $sieges = (int) str_replace([',', ' '], ['.', ''], $cols[$i + 6] ?? '0');
            if (!$nuance || in_array($nuance, ['LNC', 'NC', 'LDIV', 'DIV', ''])) continue;
            if ($sieges > $bestSieges) { $bestSieges = $sieges; $bestNuance = $nuance; }
        }

        if (!$bestNuance) continue;
        $munic2020[$insee] = $bestNuance;
        if ($libCom) $munic2020ByName[normCommune($libCom)] = $bestNuance;
    }
    unset($data, $lines); // Libérer mémoire

    echo "  → " . count($munic2020) . " communes indexées (cumul)\n";
}
echo "\n";

// ══════════════════════════════════════════════════════════════
// SOURCE 2 : CSV RNE nuances enrichi (2693 communes)
// ══════════════════════════════════════════════════════════════
$rneByInsee = []; // code_insee => nuance
$rneByName  = []; // nom_normalisé => code_insee

$csvUrl = 'https://static.data.gouv.fr/resources/communes-enrichies-avec-la-nuance-politique-france/20241202-093208/rne-enrichi-couleur-politique.csv';
echo "Téléchargement CSV RNE nuances...\n";
$csvData = @file_get_contents($csvUrl);
if ($csvData) {
    echo "  " . round(strlen($csvData) / 1024) . " Ko\n";
    $lines = explode("\n", $csvData);
    $header = str_getcsv(array_shift($lines));
    $colCog = array_search('cog_commune', $header);
    $colNuance = array_search('nuance_politique', $header);
    $colNom = array_search('nom_commune', $header);

    if ($colCog !== false && $colNuance !== false) {
        foreach ($lines as $line) {
            if (!trim($line)) continue;
            $cols = str_getcsv($line);
            $code   = trim($cols[$colCog] ?? '');
            $nuance = trim($cols[$colNuance] ?? '');
            $nom    = trim($cols[$colNom ?? 0] ?? '');

            if (!$code || !$nuance || in_array($nuance, ['NC', 'LNC'])) continue;

            // Si plusieurs nuances, prendre la première
            if (strpos($nuance, ',') !== false) $nuance = trim(explode(',', $nuance)[0]);

            $rneByInsee[$code] = $nuance;
            if ($nom) $rneByName[normCommune($nom)] = $code;
        }
        echo "  → " . count($rneByInsee) . " communes avec nuance\n";
        echo "  → " . count($rneByName) . " communes indexées par nom\n";
    } else {
        echo "  ERREUR: colonnes introuvables\n";
    }
    unset($csvData, $lines);
} else {
    echo "  ERREUR: téléchargement échoué\n";
}
echo "\n";

// ══════════════════════════════════════════════════════════════
// CHARGEMENT MAIRES SANS PARTI
// ══════════════════════════════════════════════════════════════
echo "Chargement maires sans parti...\n";
$stmt = $pdo->query("
    SELECT id, nom, prenom, fonction, departement
    FROM elus
    WHERE (parti IS NULL OR parti = '' OR parti = 'Sans étiquette' OR parti = 'SE')
      AND fonction LIKE 'Maire%'
    ORDER BY departement, nom
");
$maires = $stmt->fetchAll();
echo count($maires) . " maires sans parti\n\n";

if (!$maires) { echo "Rien à faire.\n"; exit(0); }

// Prepared statement avec DOUBLE SÉCURITÉ dans le WHERE
$stmtUpdate = $pdo->prepare("
    UPDATE elus SET parti = :parti
    WHERE id = :id
      AND (parti IS NULL OR parti = '' OR parti = 'Sans étiquette' OR parti = 'SE')
");

// ══════════════════════════════════════════════════════════════
// BOUCLE D'ENRICHISSEMENT
// ══════════════════════════════════════════════════════════════
$updated = 0;
$notFound = 0;
$noMapping = 0;
$details = ['src1' => 0, 'src2' => 0]; // compteurs par source

foreach ($maires as $i => $m) {
    // Extraire nom de commune depuis "Maire — NomCommune"
    $commune = '';
    if (preg_match('/Maire\s*(?:—|-)\s*(.+)$/i', $m['fonction'], $match)) {
        $commune = trim($match[1]);
        if (strpos($commune, '/') !== false) $commune = trim(explode('/', $commune)[0]);
    }
    if (!$commune) { $notFound++; continue; }

    $communeKey = normCommune($commune);
    $nuance = null;
    $source = '';

    // --- Tentative 1 : Municipales 2020 par nom de commune ---
    if (isset($munic2020ByName[$communeKey])) {
        $nuance = $munic2020ByName[$communeKey];
        $source = 'munic2020-nom';
    }

    // --- Tentative 2 : RNE par nom → code INSEE → municipales 2020 ---
    if (!$nuance && isset($rneByName[$communeKey])) {
        $codeInsee = $rneByName[$communeKey];
        if (isset($munic2020[$codeInsee])) {
            $nuance = $munic2020[$codeInsee];
            $source = 'munic2020-insee';
        }
    }

    // --- Tentative 3 : RNE directement ---
    if (!$nuance && isset($rneByName[$communeKey])) {
        $codeInsee = $rneByName[$communeKey];
        if (isset($rneByInsee[$codeInsee])) {
            $nuance = $rneByInsee[$codeInsee];
            $source = 'rne';
        }
    }

    if (!$nuance) { $notFound++; continue; }

    // Mapper nuance → parti lisible
    $parti = $partiMap[$nuance] ?? null;
    if (!$parti) { $noMapping++; continue; }

    if ($dryRun) {
        echo "[DRY] {$m['prenom']} {$m['nom']} ($commune) → $parti [$nuance] ($source)\n";
        $updated++;
        $details[$source === 'rne' ? 'src2' : 'src1']++;
    } else {
        $stmtUpdate->execute([':parti' => $parti, ':id' => $m['id']]);
        if ($stmtUpdate->rowCount() > 0) {
            $updated++;
            $details[$source === 'rne' ? 'src2' : 'src1']++;
        }
    }

    if (($i + 1) % 2000 === 0) {
        echo "  Progress: " . ($i + 1) . "/" . count($maires) . " — MAJ: $updated\n";
    }
}

echo "\n=== BILAN ===\n";
echo "Maires traités    : " . count($maires) . "\n";
echo "Parti attribué    : $updated\n";
echo "  via Munic. 2020 : {$details['src1']}\n";
echo "  via RNE nuances : {$details['src2']}\n";
echo "Non trouvé        : $notFound\n";
echo "Nuance sans parti : $noMapping\n";
echo "=== FIN ===\n";
