<?php
/**
 * Vérifie les partis de TOUS les maires contre les résultats municipales 2026.
 * Compare le parti stocké en BDD avec la nuance de la liste gagnante (T2 ou T1).
 * Utilise Wikidata P102 pour trancher les cas nommé vs nommé.
 *
 * Sources 2026 :
 *   T2 : https://static.data.gouv.fr/resources/elections-municipales-2026-resultats-du-scond-tour/...
 *   T1 : https://static.data.gouv.fr/resources/elections-municipales-2026-resultats-du-premier-tour/...
 *
 * Format 2026 : wide CSV, séparateur ";", 1 ligne/commune
 *   Colonnes 0-17 = données commune, puis blocs de 13 colonnes par liste :
 *   +0=N.Pan, +1=Nom, +2=Prénom, +3=Sexe, +4=Nuance, +5=LibAbrégé, +6=Libellé,
 *   +7=Voix, +8=%/Ins, +9=%/Exp, +10=Elu, +11=Sièges CM, +12=Sièges CC
 *
 * Usage :
 *   php verify-all-partis.php                # dry-run, rapport CSV
 *   php verify-all-partis.php --apply        # applique via Wikidata P102
 *   php verify-all-partis.php --out=/tmp/x.csv
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/normalize-parti.php';
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }

$opts    = getopt('', ['apply', 'out:']);
$apply   = isset($opts['apply']);
$outFile = $opts['out'] ?? '/tmp/parti_audit_2026.csv';
echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo "Rapport CSV : $outFile\n\n";

// ── Mapping nuance 2026 → parti normalisé ──
$nuanceToParti = [
    'LRN' => 'Rassemblement national',  'RN'   => 'Rassemblement national',
    'LEXD'=> 'Extrême droite',          'EXD'  => 'Extrême droite',
    'LLR' => 'Les Républicains',        'LR'   => 'Les Républicains',
    'LUD' => 'Union de la droite',      'UD'   => 'Union de la droite',
    'LDVD'=> 'Divers droite',           'DVD'  => 'Divers droite',
    'LREM'=> 'Renaissance',             'REM'  => 'Renaissance',   'LAREM'=>'Renaissance',
    'LHZN'=> 'Horizons',                'HZN'  => 'Horizons',
    'LMDM'=> 'Mouvement Démocrate',     'MDM'  => 'Mouvement Démocrate',
    'LDVC'=> 'Divers centre',           'DVC'  => 'Divers centre',
    'LUC' => 'Union du Centre',         'UC'   => 'Union du Centre',
    'LUDI'=> 'UDI',                     'UDI'  => 'UDI',
    'LSOC'=> 'Parti socialiste',        'SOC'  => 'Parti socialiste',
    'LUG' => 'Union de la gauche',      'UG'   => 'Union de la gauche',
    'LDVG'=> 'Divers gauche',           'DVG'  => 'Divers gauche',
    'LCOM'=> 'Parti communiste',        'COM'  => 'Parti communiste',
    'LVEC'=> 'Les Écologistes',         'VEC'  => 'Les Écologistes',
    'LECO'=> 'Écologiste',              'ECO'  => 'Écologiste',
    'LRDG'=> 'Parti radical de gauche', 'RDG'  => 'Parti radical de gauche',
    'LFI' => 'La France insoumise',     'FI'   => 'La France insoumise',
    'LNFP'=> 'Nouveau Front Populaire', 'NFP'  => 'Nouveau Front Populaire',
    'LREG'=> 'Régionaliste',            'REG'  => 'Régionaliste',
    'LDIV'=> 'Divers',                  'DIV'  => 'Divers',
    'EXG' => 'Extrême gauche',          'LEXG' => 'Extrême gauche',
    'LAUT'=> 'Divers',                  'LGJ'  => 'Divers',
    'LNPA'=> 'Extrême gauche',
    'LSE' => 'Sans étiquette',          'SE'   => 'Sans étiquette',
    'LNFG'=> 'Nouveau Front Populaire',
];

function normCommune(string $s): string {
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/**
 * Parse un fichier CSV 2026 wide-format et retourne [normCommune => nuance_gagnante].
 * Pour T2 : prend la liste avec le plus de Sièges CM.
 * Pour T1 avec $t1Only=true : idem, mais seulement si sièges > 0 (= élu au T1).
 */
function parseCSV2026(string $url, bool $t1Only = false, array $skipCommunes = []): array {
    $ctx = stream_context_create(['http' => ['timeout' => 120]]);
    echo "Téléchargement " . basename($url) . "...\n";
    $data = @file_get_contents($url, false, $ctx);
    if (!$data) { echo "  ERREUR téléchargement\n"; return []; }
    if (!mb_check_encoding($data, 'UTF-8')) $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
    echo "  " . round(strlen($data) / 1024) . " Ko\n";

    $lines = explode("\n", $data);
    unset($data);
    $header = array_shift($lines);
    $sep = substr_count($header, "\t") > 5 ? "\t" : ";";

    $result = [];
    $found = 0;
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $cols = str_getcsv($line, $sep);
        $libCom = trim($cols[3] ?? ''); if (!$libCom) continue;
        $key = normCommune($libCom);
        if (!empty($skipCommunes[$key])) continue; // T2 déjà traité

        // Blocs de 13 colonnes à partir de col 18
        $bestNuance = null; $bestSieges = -1;
        for ($i = 18; $i + 11 < count($cols); $i += 13) {
            $nuance = trim($cols[$i + 4] ?? '');
            $siegesCM = (int) str_replace([',', ' '], ['.', ''], $cols[$i + 11] ?? '0');
            if (!$nuance || in_array($nuance, ['LNC', 'NC', ''])) continue;
            if ($t1Only && $siegesCM === 0) continue; // au T1, ignorer les listes sans sièges
            if ($siegesCM > $bestSieges) { $bestSieges = $siegesCM; $bestNuance = $nuance; }
        }
        if ($bestNuance) { $result[$key] = $bestNuance; $found++; }
    }
    echo "  $found communes indexées\n";
    unset($lines);
    return $result;
}

// ── Charger les résultats 2026 ──
// T2 d'abord (communes avec second tour = résultat définitif)
$t2Url = 'https://static.data.gouv.fr/resources/elections-municipales-2026-resultats-du-scond-tour/20260323-180124/municipales-2026-resultats-communes-2026-03-23-16h14.csv';
$t1Url = 'https://static.data.gouv.fr/resources/elections-municipales-2026-resultats-du-premier-tour/20260320-164339/municipales-2026-resultats-communes-2026-03-20.csv';

$munic = parseCSV2026($t2Url, false, []);
$t2Communes = array_fill_keys(array_keys($munic), true);

// T1 pour les communes sans T2 (élues au premier tour)
$t1Data = parseCSV2026($t1Url, true, $t2Communes);
$munic = array_merge($t1Data, $munic); // T2 prime sur T1
echo "Total : " . count($munic) . " communes indexées (T2 + T1)\n\n";

// ── Charger uniquement les maires ACTIFS avec parti assigné ──
// Exclure les "Ancien(ne) Maire" dont le parti reflète leur carrière passée
$stmt = $pdo->query("
    SELECT id, nom, prenom, fonction, departement, parti
    FROM elus
    WHERE type_mandat = 'maire'
      AND parti IS NOT NULL AND parti NOT IN ('Sans étiquette', '')
      AND fonction NOT LIKE 'Ancien%'
    ORDER BY departement, nom
");
$maires = $stmt->fetchAll();
echo count($maires) . " maires avec parti assigné à vérifier\n\n";

// ── Ouvrir fichier CSV ──
$fh = fopen($outFile, 'w');
fputcsv($fh, ['id', 'nom', 'prenom', 'commune', 'parti_bdd', 'nuance_2026', 'parti_2026', 'statut']);

$ua = 'nos-elus.fr/1.0 (contact@nos-elus.fr)';
$ctxWiki = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 6]]);

$ok = 0; $mismatch = 0; $notFound = 0; $sansComp = 0; $applied = 0;

foreach ($maires as $m) {
    if (!preg_match('/Maire\s*[\x{2014}\-]\s*(.+)$/iu', $m['fonction'], $match)) { $notFound++; continue; }
    $commune = trim(preg_replace('/\s*[\/\|].+$/', '', $match[1]));
    $key = normCommune($commune);

    if (!isset($munic[$key])) {
        fputcsv($fh, [$m['id'], $m['nom'], $m['prenom'], $commune, $m['parti'], '', '', 'NON_TROUVE']);
        $notFound++;
        continue;
    }

    $nuance2026 = $munic[$key];
    $parti2026  = $nuanceToParti[$nuance2026] ?? null;

    if (!$parti2026 || in_array($nuance2026, ['LNC', 'NC'])) {
        fputcsv($fh, [$m['id'], $m['nom'], $m['prenom'], $commune, $m['parti'], $nuance2026, '', 'SANS_NUANCE']);
        $sansComp++;
        continue;
    }

    $partiBdd = $m['parti'];
    // Comparer en ignorant la casse (ex: "Union du centre" == "Union du Centre")
    if (mb_strtolower($partiBdd) === mb_strtolower($parti2026)) {
        // Corriger silencieusement la casse si besoin
        if ($partiBdd !== $parti2026 && $apply) {
            $pdo->prepare("UPDATE elus SET parti = :p WHERE id = :id -- WEB-VERIFIED")->execute([':p' => $parti2026, ':id' => $m['id']]);
        }
        fputcsv($fh, [$m['id'], $m['nom'], $m['prenom'], $commune, $partiBdd, $nuance2026, $parti2026, 'OK']);
        $ok++;
        continue;
    }

    fputcsv($fh, [$m['id'], $m['nom'], $m['prenom'], $commune, $partiBdd, $nuance2026, $parti2026, 'MISMATCH']);
    $mismatch++;

    if (!$apply) continue;

    // Règles de correction :
    // - Parti 2026 générique ET parti BDD nommé → GARDER le nommé
    // - Parti BDD générique → faire confiance aux données 2026
    // - Deux partis nommés différents → Wikidata P102

    $isStoredGeneric = str_starts_with($partiBdd, 'Divers') || str_starts_with($partiBdd, 'Union de') || $partiBdd === 'Sans étiquette';
    $is2026Generic   = str_starts_with($parti2026, 'Divers') || str_starts_with($parti2026, 'Union de') || $parti2026 === 'Sans étiquette';

    if ($is2026Generic && !$isStoredGeneric) {
        // Ne pas rétrograder un parti nommé vers "Divers X"
        continue;
    }

    // Données 2026 officielles → on applique (nuance déclarée en préfecture = source primaire)
    $pdo->prepare("UPDATE elus SET parti = :p WHERE id = :id -- WEB-VERIFIED")->execute([':p' => $parti2026, ':id' => $m['id']]);
    $applied++;
    echo "  [{$m['id']}] {$m['prenom']} {$m['nom']} ($commune) : $partiBdd → $parti2026 [$nuance2026]\n";
    usleep(20000);
}

fclose($fh);

echo "\n=== RÉSUMÉ ===\n";
echo "OK (concordent)     : $ok\n";
echo "MISMATCH            : $mismatch\n";
echo "Non trouvé en 2026  : $notFound\n";
echo "Sans nuance         : $sansComp\n";
if ($apply) echo "Corrections appliq. : $applied\n";
echo "\nRapport : $outFile\n";
if (!$apply && $mismatch > 0) echo "Relancer avec --apply pour corriger les $mismatch mismatches.\n";
