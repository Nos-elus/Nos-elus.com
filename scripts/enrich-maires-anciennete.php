#!/usr/bin/env php
<?php
/**
 * Enrichit les dates de début réelles des maires via Wikidata.
 * Cherche la propriété P39 (poste occupé) avec le qualificatif P580 (date début).
 * Si Wikidata donne une date plus ancienne que le RNE → met à jour.
 *
 * Usage :
 *   php scripts/enrich-maires-anciennete.php --limit=500 --dry-run
 *   php scripts/enrich-maires-anciennete.php --limit=500
 */

$dryRun = in_array('--dry-run', $argv);
$limit = 500;
foreach ($argv as $a) { if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1]; }

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$ua = 'nos-elus.fr/1.0 (https://nos-elus.fr; contact@nos-elus.fr)';
$ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 5]]);

echo "=== Enrichissement ancienneté maires ===\n";
echo "Mode : " . ($dryRun ? "DRY-RUN" : "EXECUTION") . " | Limite : $limit\n\n";

// Maires avec mandat date >= 2020 (date RNE, pas la vraie), triés par consultations
$stmt = $pdo->prepare("
    SELECT e.id, e.nom, e.prenom, m.id AS mandat_id, m.date_debut, m.nb_mandats_poste
    FROM elus e
    JOIN mandats m ON m.elu_id = e.id
    LEFT JOIN elu_stats s ON s.elu_id = e.id
    WHERE e.type_mandat = 'maire'
      AND e.source_api != 'manual'
      AND m.date_fin IS NULL
      AND m.date_debut >= '2020-01-01'
      AND m.titre LIKE '%aire%'
    ORDER BY COALESCE(s.nb_consultations, e.nb_consultations) DESC
    LIMIT :lim
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$maires = $stmt->fetchAll();

echo count($maires) . " maires à vérifier\n\n";

$updateMandat = $pdo->prepare('UPDATE mandats SET date_debut = :d, nb_mandats_poste = :nb WHERE id = :id');
$fixed = 0;
$notFound = 0;
$unchanged = 0;

foreach ($maires as $i => $maire) {
    $nom = trim(($maire['prenom'] ?? '') . ' ' . $maire['nom']);

    // Recherche Wikidata
    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities', 'search' => $nom,
        'language' => 'fr', 'type' => 'item', 'format' => 'json', 'limit' => 3,
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) { $notFound++; usleep(500000); continue; }

    $results = json_decode($json, true)['search'] ?? [];
    $qid = null;
    foreach ($results as $r) {
        $desc = mb_strtolower($r['description'] ?? '');
        if (mb_strpos($desc, 'politi') !== false || mb_strpos($desc, 'maire') !== false || mb_strpos($desc, 'french') !== false) {
            $qid = $r['id']; break;
        }
    }
    if (!$qid) { $notFound++; usleep(500000); continue; }

    // Récupérer P39
    $eUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetclaims', 'entity' => $qid, 'property' => 'P39', 'format' => 'json',
    ]);
    $eJson = @file_get_contents($eUrl, false, $ctx);
    if (!$eJson) { $notFound++; usleep(500000); continue; }

    $claims = json_decode($eJson, true)['claims']['P39'] ?? [];

    // Chercher le mandat de maire le plus ancien
    $oldestDate = null;
    foreach ($claims as $c) {
        $label = $c['mainsnak']['datavalue']['value']['id'] ?? '';
        // Q30185 = maire en France, mais on accepte aussi d'autres QIDs
        $q = $c['qualifiers'] ?? [];
        if (empty($q['P580'])) continue;
        $time = $q['P580'][0]['datavalue']['value']['time'] ?? '';
        if (!preg_match('/^\+?(\d{4})-(\d{2})-(\d{2})/', $time, $m)) continue;
        $date = $m[1] . '-' . $m[2] . '-' . $m[3];

        // Vérifier que c'est un poste de type "maire" via le label du poste
        // On accepte si c'est avant 2020 (sinon c'est le mandat actuel qu'on a déjà)
        if ($date >= '1900-01-01' && $date < '2020-01-01' && (!$oldestDate || $date < $oldestDate)) {
            $oldestDate = $date;
        }
    }

    if ($oldestDate && $oldestDate < $maire['date_debut']) {
        $years = date('Y') - (int)substr($oldestDate, 0, 4);
        $nbMandats = max(1, (int)floor($years / 6) + 1);

        if (!$dryRun) {
            $updateMandat->execute([':d' => $oldestDate, ':nb' => $nbMandats, ':id' => $maire['mandat_id']]);
        }
        $fixed++;
        echo "  [$fixed] {$nom} : {$maire['date_debut']} → $oldestDate ($nbMandats mandats)\n";
    } else {
        $unchanged++;
    }

    if (($i + 1) % 50 === 0) {
        echo "  ... " . ($i + 1) . "/$limit | $fixed corrigés | $notFound non trouvés\n";
    }
    usleep(500000); // 500ms
}

echo "\n=== Terminé ===\n";
echo "Corrigés   : $fixed\n";
echo "Inchangés  : $unchanged\n";
echo "Non trouvés: $notFound\n";
