<?php
/**
 * Tente de trouver la nuance 2026 pour les communes non matchées,
 * via normalisations alternatives et distance de Levenshtein.
 * Cible : maires actifs >500 hab non trouvés dans les CSV 2026.
 *
 * Usage : php match-communes-2026.php [--apply]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/normalize-parti.php';
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }

$apply = in_array('--apply', $argv ?? []);

// ── Mapping nuance → parti (identique à verify-all-partis) ──
$nuanceToParti = [
    'LRN'=>'Rassemblement national','RN'=>'Rassemblement national',
    'LEXD'=>'Extrême droite','EXD'=>'Extrême droite',
    'LLR'=>'Les Républicains','LR'=>'Les Républicains',
    'LUD'=>'Union de la droite','UD'=>'Union de la droite',
    'LDVD'=>'Divers droite','DVD'=>'Divers droite',
    'LREM'=>'Renaissance','REM'=>'Renaissance','LAREM'=>'Renaissance',
    'LHZN'=>'Horizons','HZN'=>'Horizons',
    'LMDM'=>'Mouvement Démocrate','MDM'=>'Mouvement Démocrate',
    'LDVC'=>'Divers centre','DVC'=>'Divers centre',
    'LUC'=>'Union du Centre','UC'=>'Union du Centre',
    'LUDI'=>'UDI','UDI'=>'UDI',
    'LSOC'=>'Parti socialiste','SOC'=>'Parti socialiste',
    'LUG'=>'Union de la gauche','UG'=>'Union de la gauche',
    'LDVG'=>'Divers gauche','DVG'=>'Divers gauche',
    'LCOM'=>'Parti communiste','COM'=>'Parti communiste',
    'LVEC'=>'Les Écologistes','VEC'=>'Les Écologistes',
    'LECO'=>'Écologiste','ECO'=>'Écologiste',
    'LRDG'=>'Parti radical de gauche','RDG'=>'Parti radical de gauche',
    'LFI'=>'La France insoumise','FI'=>'La France insoumise',
    'LNFP'=>'Nouveau Front Populaire','NFP'=>'Nouveau Front Populaire','LNFG'=>'Nouveau Front Populaire',
    'LREG'=>'Régionaliste','REG'=>'Régionaliste',
    'LDIV'=>'Divers','DIV'=>'Divers',
    'EXG'=>'Extrême gauche','LEXG'=>'Extrême gauche',
    'LAUT'=>'Divers','LGJ'=>'Divers',
    'LNPA'=>'Extrême gauche',
    'LSE'=>'Sans étiquette','SE'=>'Sans étiquette',
];

// Génère toutes les variantes de normalisation d'un nom de commune
function normVariants(string $s): array {
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    $base = preg_replace('/[^a-z0-9]/', '', $s);

    // Variante 1 : retirer les mots de liaison courants
    $noLiaison = preg_replace('/\b(sur|sous|en|de|des|du|les|la|le|aux|au|et|par|près|lez|lès|saint|sainte)\b/', '', $s);
    $noLiaison = preg_replace('/[^a-z0-9]/', '', $noLiaison);

    // Variante 2 : retirer le préfixe "saint(e)"
    $noSaint = preg_replace('/^sainte?/', '', $base);

    // Variante 3 : juste les 10 premiers caractères (pour les communes très longues)
    $short = substr($base, 0, 10);

    // Variante 4 : retirer les tirets et espaces puis enlever les nombres
    $noNum = preg_replace('/[0-9]/', '', $base);

    return array_filter(array_unique([$base, $noLiaison, $noSaint, $short, $noNum]));
}

// Charge un CSV 2026 et retourne [variant => [nuance, nom_officiel]]
function buildIndex(string $url, bool $t1Only = false): array {
    $ctx = stream_context_create(['http' => ['timeout' => 120]]);
    echo "Chargement " . basename($url) . "...\n";
    $data = @file_get_contents($url, false, $ctx);
    if (!$data) { echo "  ERREUR\n"; return []; }
    if (!mb_check_encoding($data, 'UTF-8')) $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');

    $lines = explode("\n", $data); unset($data);
    $header = array_shift($lines);
    $sep = substr_count($header, "\t") > 5 ? "\t" : ";";

    $index = [];
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $cols = str_getcsv($line, $sep);
        $libCom = trim($cols[3] ?? ''); if (!$libCom) continue;

        $bestNuance = null; $bestSieges = -1;
        for ($i = 18; $i + 11 < count($cols); $i += 13) {
            $nuance  = trim($cols[$i + 4] ?? '');
            $siegesCM = (int) str_replace([',', ' '], ['.', ''], $cols[$i + 11] ?? '0');
            if (!$nuance || in_array($nuance, ['LNC', 'NC', ''])) continue;
            if ($t1Only && $siegesCM === 0) continue;
            if ($siegesCM > $bestSieges) { $bestSieges = $siegesCM; $bestNuance = $nuance; }
        }
        if (!$bestNuance) continue;

        foreach (normVariants($libCom) as $v) {
            if (!isset($index[$v])) $index[$v] = [$bestNuance, $libCom];
        }
    }
    unset($lines);
    echo "  " . count($index) . " variants indexés\n";
    return $index;
}

$t2Url = 'https://static.data.gouv.fr/resources/elections-municipales-2026-resultats-du-scond-tour/20260323-180124/municipales-2026-resultats-communes-2026-03-23-16h14.csv';
$t1Url = 'https://static.data.gouv.fr/resources/elections-municipales-2026-resultats-du-premier-tour/20260320-164339/municipales-2026-resultats-communes-2026-03-20.csv';

$idxT2 = buildIndex($t2Url, false);
$idxT1 = buildIndex($t1Url, true);
$idx   = array_merge($idxT1, $idxT2); // T2 prime
echo "Index total : " . count($idx) . " variants\n\n";

// ── Charger les maires non trouvés (>500 hab, actifs, parti assigné) ──
// On repart de la liste complète des maires actifs avec parti assigné
$stmt = $pdo->query("
    SELECT id, nom, prenom, fonction, departement, parti, population
    FROM elus
    WHERE type_mandat = 'maire'
      AND parti IS NOT NULL AND parti NOT IN ('Sans étiquette', '')
      AND fonction NOT LIKE 'Ancien%'
      AND (population IS NULL OR population >= 500)
    ORDER BY population DESC
");
$maires = $stmt->fetchAll();

$found = 0; $notFound = 0; $matched = 0; $applied = 0;

$stmtIsProtected = $pdo->prepare("
    SELECT id FROM elus WHERE id = :id AND parti NOT LIKE 'Divers%' AND parti NOT LIKE 'Union de%' AND parti != 'Sans étiquette'
");

foreach ($maires as $m) {
    if (!preg_match('/Maire\s*[\x{2014}\-]\s*(.+)$/iu', $m['fonction'], $match)) continue;
    $commune = trim(preg_replace('/\s*[\/\|].+$/', '', $match[1]));

    // Vérifier si déjà trouvé dans verify-all-partis (match exact)
    $baseKey = preg_replace('/[^a-z0-9]/', '', transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $commune));
    if (isset($idx[$baseKey])) { $found++; continue; } // déjà géré

    // Essayer les variantes
    $variants = normVariants($commune);
    $hit = null;
    foreach ($variants as $v) {
        if (isset($idx[$v])) { $hit = $idx[$v]; break; }
    }

    // Levenshtein sur les clés courtes si toujours rien
    if (!$hit && strlen($baseKey) >= 6) {
        $best = PHP_INT_MAX;
        $bestKey = null;
        foreach (array_keys($idx) as $k) {
            if (abs(strlen($k) - strlen($baseKey)) > 3) continue;
            $d = levenshtein($baseKey, $k);
            if ($d <= 2 && $d < $best) { $best = $d; $bestKey = $k; }
        }
        if ($bestKey) $hit = $idx[$bestKey];
    }

    if (!$hit) { $notFound++; continue; }

    $nuance2026 = $hit[0];
    $nomCSV     = $hit[1];
    $parti2026  = $nuanceToParti[$nuance2026] ?? null;
    if (!$parti2026) continue;

    $partiBdd = $m['parti'];
    if (mb_strtolower($partiBdd) === mb_strtolower($parti2026)) { $matched++; continue; }

    $isStoredGeneric = str_starts_with($partiBdd, 'Divers') || str_starts_with($partiBdd, 'Union de') || $partiBdd === 'Sans étiquette';
    $is2026Generic   = str_starts_with($parti2026, 'Divers') || str_starts_with($parti2026, 'Union de') || $parti2026 === 'Sans étiquette';

    // Règle : ne pas rétrograder nommé → Divers
    if ($is2026Generic && !$isStoredGeneric) continue;

    // Pour les variantes/Levenshtein, n'appliquer que les corrections Divers→Divers
    // (changements nommé→nommé non fiables avec matching approximatif)
    $isNamedToNamed = !$isStoredGeneric && !$is2026Generic;
    $action = $isNamedToNamed ? 'SKIP (nommé↔nommé, vérif manuelle)' : 'MATCH';

    $matched++;
    $pop = number_format($m['population'] ?? 0, 0, '.', ' ');
    echo "  [{$m['id']}] {$m['prenom']} {$m['nom']} ($commune→$nomCSV) {$pop}hab : $partiBdd → $parti2026 [$nuance2026] [$action]\n";

    if ($apply && $action === 'MATCH') {
        $pdo->prepare("UPDATE elus SET parti = :p WHERE id = :id -- WEB-VERIFIED")->execute([':p' => $parti2026, ':id' => $m['id']]);
        $applied++;
    }
}

echo "\n=== RÉSUMÉ ===\n";
echo "Déjà matchés (exact)  : $found\n";
echo "Matchés (variante/lev): $matched\n";
echo "Toujours introuvables : $notFound\n";
if ($apply) echo "Corrections appliq.   : $applied\n";
if (!$apply && $matched > 0) echo "\nRelancer avec --apply pour appliquer.\n";
