#!/usr/bin/env php
<?php
/**
 * Import + enrichissement complet des parlementaires (députés + sénateurs)
 *
 * 1. Télécharge les CSV RNE (députés + sénateurs)
 * 2. Importe en BDD avec type_mandat correct
 * 3. Enrichit les photos via NosDéputés.fr et Sénat.fr
 * 4. Crée les mandats manquants
 *
 * Usage :
 *   php scripts/import-parlementaires.php
 *   php scripts/import-parlementaires.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv);

// ── BDD ──
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo "Erreur BDD : " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Import parlementaires ===\n";
echo "Mode : " . ($dryRun ? "DRY-RUN" : "EXECUTION") . "\n\n";

// ── CSV sources ──
$sources = [
    'depute' => [
        'url' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104106/elus-deputes-dep.csv',
        'institution' => 'Assemblée nationale',
        'photo_pattern' => null, // enrichi via NosDéputés.fr après
    ],
    'senateur' => [
        'url' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104017/elus-senateurs-sen.csv',
        'institution' => 'Sénat',
        'photo_pattern' => null, // enrichi via senat.fr après
    ],
];

$totalInserted = 0;
$totalUpdated = 0;

foreach ($sources as $type => $config) {
    echo "--- Import $type ---\n";

    // Télécharger le CSV
    $tmpFile = "/tmp/rne-{$type}.csv";
    if (!is_file($tmpFile) || filemtime($tmpFile) < time() - 86400) {
        echo "Téléchargement CSV $type...\n";
        $ch = curl_init($config['url']);
        $fp = fopen($tmpFile, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'nos-elus.fr/1.0',
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($httpCode >= 400) {
            echo "ERREUR téléchargement HTTP $httpCode\n";
            continue;
        }
        echo "OK (" . round(filesize($tmpFile) / 1024) . " Ko)\n";
    } else {
        echo "Cache local utilisé\n";
    }

    // Lire le CSV
    $handle = fopen($tmpFile, 'r');
    $firstLine = fgets($handle);
    rewind($handle);
    $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
    $header = array_map('trim', fgetcsv($handle, 0, $sep));

    // Mapper les colonnes
    $colMap = mapColonnes($header);
    echo "Colonnes : " . implode(', ', array_keys($colMap)) . "\n";

    // Charger les slugs manuels
    $manualSlugs = [];
    $stmt = $pdo->query("SELECT slug FROM elus WHERE source_api = 'manual'");
    while ($r = $stmt->fetch()) $manualSlugs[$r['slug']] = true;

    $inserted = 0;
    $updated = 0;
    $lineNum = 0;
    $seen = [];

    $upsertStmt = $pdo->prepare("
        INSERT INTO elus (nom, prenom, slug, parti, fonction, departement, date_naissance,
            type_mandat, source_id, source_api, derniere_sync)
        VALUES (:nom, :prenom, :slug, :parti, :fonction, :dept, :dob,
            :type, :srcid, 'rne_csv', NOW())
        ON DUPLICATE KEY UPDATE
            parti = IF(source_api != 'manual', VALUES(parti), parti),
            fonction = IF(source_api != 'manual', VALUES(fonction), fonction),
            departement = IF(source_api != 'manual', VALUES(departement), departement),
            date_naissance = IF(source_api != 'manual' AND date_naissance IS NULL, VALUES(date_naissance), date_naissance),
            type_mandat = IF(source_api != 'manual', VALUES(type_mandat), type_mandat),
            derniere_sync = NOW()
    ");

    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        $lineNum++;
        $nom = extractField($row, $colMap, 'nom');
        $prenom = extractField($row, $colMap, 'prenom');
        if (!$nom) continue;

        $slug = slugify(($prenom ? $prenom . ' ' : '') . $nom);
        if (!$slug || isset($manualSlugs[$slug]) || isset($seen[$slug])) continue;
        $seen[$slug] = true;

        $fonction = extractField($row, $colMap, 'fonction');
        $dept = extractField($row, $colMap, 'departement');
        $dob = parseDate(extractField($row, $colMap, 'date_naissance'));
        $parti = extractField($row, $colMap, 'parti');

        // Construire le libellé fonction
        $fonctionLabel = ucfirst($type === 'depute' ? 'Député' : 'Sénateur');
        if ($dept) $fonctionLabel .= " — $dept";
        if ($fonction && !str_contains(mb_strtolower($fonctionLabel), mb_strtolower($fonction))) {
            $fonctionLabel .= " ($fonction)";
        }

        if (!$dryRun) {
            $upsertStmt->execute([
                ':nom' => $nom,
                ':prenom' => $prenom,
                ':slug' => $slug,
                ':parti' => $parti,
                ':fonction' => $fonctionLabel,
                ':dept' => $dept,
                ':dob' => $dob,
                ':type' => $type,
                ':srcid' => md5($nom . $prenom . ($dob ?? '')),
            ]);
        }
        $inserted++;

        if ($lineNum % 100 === 0) echo "  $lineNum lignes...\n";
    }
    fclose($handle);

    echo "$type : $inserted importés\n\n";
    $totalInserted += $inserted;
}

// ── Enrichissement photos députés via NosDéputés.fr ──
echo "--- Enrichissement photos députés (NosDéputés.fr) ---\n";

$stmt = $pdo->query("
    SELECT id, nom, prenom, slug FROM elus
    WHERE type_mandat = 'depute'
      AND (photo_url IS NULL OR photo_url = '')
      AND source_api != 'manual'
");
$deputes = $stmt->fetchAll();
echo count($deputes) . " députés sans photo\n";

$photoUpdate = $pdo->prepare('UPDATE elus SET photo_url = :url WHERE id = :id');
$photosFound = 0;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'nos-elus.fr/1.0']]);

foreach ($deputes as $i => $dep) {
    $slug = slugify(($dep['prenom'] ?? '') . ' ' . $dep['nom']);
    $url = "https://www.nosdeputes.fr/depute/photo/{$slug}/200";

    // HEAD request pour vérifier
    $headers = @get_headers($url, true, $ctx);
    if ($headers && strpos($headers[0], '200') !== false) {
        if (!$dryRun) {
            $photoUpdate->execute([':url' => $url, ':id' => $dep['id']]);
        }
        $photosFound++;
        if ($photosFound <= 20 || $photosFound % 50 === 0) {
            echo "  " . ($dep['prenom'] ?? '') . " " . $dep['nom'] . " -> OK\n";
        }
    }
    usleep(150000); // 150ms
    if (($i + 1) % 100 === 0) echo "  ... " . ($i + 1) . "/" . count($deputes) . " ($photosFound trouvés)\n";
}
echo "Photos députés : $photosFound\n\n";

// ── Enrichissement photos sénateurs via Sénat.fr ──
echo "--- Enrichissement photos sénateurs (senat.fr) ---\n";

$stmt = $pdo->query("
    SELECT id, nom, prenom, slug FROM elus
    WHERE type_mandat = 'senateur'
      AND (photo_url IS NULL OR photo_url = '')
      AND source_api != 'manual'
");
$senateurs = $stmt->fetchAll();
echo count($senateurs) . " sénateurs sans photo\n";

$photosFoundSen = 0;

foreach ($senateurs as $i => $sen) {
    // Tester plusieurs patterns Sénat
    $prenomClean = slugifyUnderscore($sen['prenom'] ?? '');
    $nomClean = slugifyUnderscore($sen['nom']);

    $urls = [
        "https://www.senat.fr/senimg/{$nomClean}_{$prenomClean}.jpg",
        "https://www.senat.fr/senimg/" . strtolower(slugifyUnderscore($sen['nom'])) . "_" . strtolower(slugifyUnderscore($sen['prenom'] ?? '')) . ".jpg",
    ];

    foreach ($urls as $url) {
        $headers = @get_headers($url, true, $ctx);
        if ($headers && strpos($headers[0], '200') !== false) {
            $ct = $headers['Content-Type'] ?? '';
            if (is_array($ct)) $ct = end($ct);
            if (strpos($ct, 'image') !== false) {
                if (!$dryRun) {
                    $photoUpdate->execute([':url' => $url, ':id' => $sen['id']]);
                }
                $photosFoundSen++;
                if ($photosFoundSen <= 20 || $photosFoundSen % 50 === 0) {
                    echo "  " . ($sen['prenom'] ?? '') . " " . $sen['nom'] . " -> OK\n";
                }
                break;
            }
        }
    }
    usleep(150000);
    if (($i + 1) % 100 === 0) echo "  ... " . ($i + 1) . "/" . count($senateurs) . " ($photosFoundSen trouvés)\n";
}
echo "Photos sénateurs : $photosFoundSen\n\n";

// ── Créer mandats manquants ──
echo "--- Mandats manquants ---\n";

$stmt = $pdo->query("
    SELECT e.id, e.fonction, e.type_mandat FROM elus e
    LEFT JOIN mandats m ON m.elu_id = e.id
    WHERE m.id IS NULL AND e.type_mandat IN ('depute', 'senateur') AND e.fonction IS NOT NULL
");
$sansMandats = $stmt->fetchAll();
echo count($sansMandats) . " parlementaires sans mandat\n";

$mandatInsert = $pdo->prepare("
    INSERT INTO mandats (elu_id, titre, date_debut, institution)
    VALUES (:eid, :titre, CURDATE(), :inst)
");

$mandatsCreated = 0;
$instMap = ['depute' => 'Assemblée nationale', 'senateur' => 'Sénat'];

if (!$dryRun) {
    $pdo->beginTransaction();
    foreach ($sansMandats as $row) {
        $mandatInsert->execute([
            ':eid' => $row['id'],
            ':titre' => $row['fonction'],
            ':inst' => $instMap[$row['type_mandat']] ?? 'Parlement',
        ]);
        $mandatsCreated++;
    }
    $pdo->commit();
}
echo "Mandats créés : " . ($dryRun ? count($sansMandats) . " (dry-run)" : $mandatsCreated) . "\n\n";

// ── Résumé ──
echo "=== RÉSUMÉ ===\n";
echo "Parlementaires importés : $totalInserted\n";
echo "Photos députés         : $photosFound\n";
echo "Photos sénateurs       : $photosFoundSen\n";
echo "Mandats créés          : $mandatsCreated\n";

// ── Helpers ──

function slugify(string $text): string {
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    } else {
        $text = mb_strtolower($text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    }
    return trim(preg_replace('/[^a-z0-9]+/', '-', $text), '-');
}

function slugifyUnderscore(string $text): string {
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    } else {
        $text = mb_strtolower($text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    }
    return trim(preg_replace('/[^a-z0-9]+/', '_', $text), '_');
}

function parseDate(?string $date): ?string {
    if (!$date) return null;
    $parts = explode('/', trim($date));
    return count($parts) === 3 ? $parts[2] . '-' . $parts[1] . '-' . $parts[0] : null;
}

function mapColonnes(array $header): array {
    $patterns = [
        'nom'            => ['Nom de l\'élu', 'Nom de l\'elu', 'Nom'],
        'prenom'         => ['Prénom de l\'élu', 'Prenom de l\'elu', 'Prénom'],
        'date_naissance' => ['Date de naissance'],
        'fonction'       => ['Libellé de la fonction', 'Libelle de la fonction'],
        'parti'          => ['Nuance politique', 'Libellé de la nuance politique'],
        'departement'    => ['Code du département', 'Code departement'],
    ];
    $map = [];
    foreach ($patterns as $field => $candidates) {
        foreach ($candidates as $c) {
            $idx = array_search($c, $header);
            if ($idx !== false) { $map[$field] = $idx; break; }
        }
    }
    return $map;
}

function extractField(array $row, array $colMap, string $field): ?string {
    if (!isset($colMap[$field])) return null;
    return isset($row[$colMap[$field]]) ? trim($row[$colMap[$field]]) : null;
}
