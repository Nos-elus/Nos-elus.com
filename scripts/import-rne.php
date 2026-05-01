#!/usr/bin/env php
<?php
/**
 * Import batch des elus depuis le CSV RNE (Repertoire National des Elus)
 * Source : https://www.data.gouv.fr/fr/datasets/repertoire-national-des-elus-1/
 *
 * Usage :
 *   php scripts/import-rne.php                        # telecharge et importe
 *   php scripts/import-rne.php /chemin/vers/rne.csv   # utilise un fichier local
 *   php scripts/import-rne.php --dry-run               # simule sans ecrire
 */

// ── Config ──
// URLs des CSV RNE par type d'élu
define('CSV_URLS', [
    'deputes' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104106/elus-deputes-dep.csv',
    'senateurs' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104017/elus-senateurs-sen.csv',
    'maires' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104211/elus-maires-mai.csv',
    'europeens' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-103935/elus-representants-parlement-europeen-rpe.csv',
    'regionaux' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-103814/elus-conseillers-regionaux-cr.csv',
    'departementaux' => 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-103735/elus-conseillers-departementaux-cd.csv',
]);
define('CSV_URL', CSV_URLS['deputes']); // fallback default
define('BATCH_SIZE', 500);
define('LOCAL_CACHE', '/tmp/rne-elus.csv');

// ── CLI args ──
$dryRun = in_array('--dry-run', $argv);
$localFile = null;
foreach ($argv as $i => $arg) {
    if ($i === 0 || str_starts_with($arg, '--')) continue;
    if (is_file($arg)) {
        $localFile = $arg;
    }
}

// ── Connexion BDD ──
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

// Support fichier my.cnf temporaire
if (is_file('/tmp/my.cnf')) {
    $ini = parse_ini_file('/tmp/my.cnf', true);
    if (isset($ini['client'])) {
        $dbHost = $ini['client']['host'] ?? $dbHost;
        $dbName = $ini['client']['database'] ?? $dbName;
        $dbUser = $ini['client']['user'] ?? $dbUser;
        $dbPass = $ini['client']['password'] ?? $dbPass;
    }
}

if ($dryRun) {
    echo "[DRY RUN] Aucune ecriture en BDD\n";
    $pdo = null;
} else {
    try {
        $pdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser, $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "ERREUR BDD : " . $e->getMessage() . "\n");
        exit(1);
    }
}

// ── Fonctions utilitaires ──

function slugify(string $text): string {
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    } else {
        $text = mb_strtolower($text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = strtolower($text);
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function parseDate(?string $date): ?string {
    if (!$date) return null;
    $parts = explode('/', trim($date));
    if (count($parts) === 3) {
        return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
    }
    return null;
}

function mapColonnes(array $header): array {
    $map = [];
    $patterns = [
        'nom'            => ['Nom de l\'élu', 'Nom de l\'elu', 'Nom'],
        'prenom'         => ['Prénom de l\'élu', 'Prenom de l\'elu', 'Prénom', 'Prenom'],
        'date_naissance' => ['Date de naissance'],
        'sexe'           => ['Code sexe', 'Sexe'],
        'fonction'       => ['Libellé de la fonction', 'Libelle de la fonction', 'Libellé de fonction'],
        'parti'          => ['Nuance politique', 'Libellé de la nuance politique'],
        'departement'    => ['Code du département', 'Code departement', 'Code département'],
        'region'         => ['Libellé de la région', 'Libelle de la region', 'Libellé de région'],
        'commune'        => ['Libellé de la commune', 'Libelle de la commune'],
        'date_debut'     => ['Date de début du mandat', 'Date de debut du mandat', 'Date début mandat'],
    ];

    foreach ($patterns as $field => $candidates) {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $header);
            if ($idx !== false) {
                $map[$field] = $idx;
                break;
            }
        }
    }
    return $map;
}

function extractField(array $row, array $colMap, string $field): ?string {
    if (!isset($colMap[$field])) return null;
    $idx = $colMap[$field];
    return isset($row[$idx]) ? trim($row[$idx]) : null;
}

// ── Telechargement CSV ──

$csvPath = $localFile;
if (!$csvPath) {
    if (is_file(LOCAL_CACHE) && filemtime(LOCAL_CACHE) > time() - 86400) {
        echo "Utilisation du cache local : " . LOCAL_CACHE . "\n";
        $csvPath = LOCAL_CACHE;
    } else {
        echo "Telechargement du CSV RNE...\n";
        $ch = curl_init(CSV_URL);
        $fp = fopen(LOCAL_CACHE, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'nos-elus.fr/1.0 (plateforme citoyenne)',
        ]);
        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $httpCode >= 400) {
            fwrite(STDERR, "ERREUR telechargement : HTTP $httpCode — $error\n");
            @unlink(LOCAL_CACHE);
            exit(1);
        }
        $size = round(filesize(LOCAL_CACHE) / 1024 / 1024, 1);
        echo "Telecharge : {$size} Mo\n";
        $csvPath = LOCAL_CACHE;
    }
}

// ── Lecture CSV ──

$handle = fopen($csvPath, 'r');
if (!$handle) {
    fwrite(STDERR, "ERREUR : impossible d'ouvrir $csvPath\n");
    exit(1);
}

// Detecter le separateur (premiere ligne)
$firstLine = fgets($handle);
rewind($handle);
$separator = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

// Header
$header = fgetcsv($handle, 0, $separator);
if (!$header) {
    fwrite(STDERR, "ERREUR : CSV vide ou illisible\n");
    exit(1);
}
$header = array_map('trim', $header);
$colMap = mapColonnes($header);

echo "Colonnes detectees : " . implode(', ', array_keys($colMap)) . "\n";
if (!isset($colMap['nom'])) {
    fwrite(STDERR, "ERREUR : colonne 'nom' introuvable. Colonnes CSV : " . implode(' | ', $header) . "\n");
    exit(1);
}

// Compter les lignes pour la progression
$totalLines = 0;
while (fgets($handle) !== false) $totalLines++;
rewind($handle);
fgetcsv($handle, 0, $separator); // skip header again
echo "Total lignes : $totalLines\n\n";

// ── Charger les slugs existants pour eviter les doublons ──

$existingSlugs = [];
$manualSlugs = [];
if ($pdo) {
    $stmt = $pdo->query('SELECT slug, source_api FROM elus WHERE slug IS NOT NULL');
    while ($row = $stmt->fetch()) {
        $existingSlugs[$row['slug']] = true;
        if ($row['source_api'] === 'manual') {
            $manualSlugs[$row['slug']] = true;
        }
    }
    echo "Elus existants en BDD : " . count($existingSlugs) . " (dont " . count($manualSlugs) . " manuels proteges)\n";
}

// ── Traitement par lots ──

$batch = [];
$processed = 0;
$inserted = 0;
$updated = 0;
$skippedManual = 0;
$skippedInvalid = 0;
$skippedDuplicates = 0;
$startTime = microtime(true);
$seenSlugs = []; // dedup intra-fichier

while (($row = fgetcsv($handle, 0, $separator)) !== false) {
    $processed++;

    $nom = extractField($row, $colMap, 'nom');
    $prenom = extractField($row, $colMap, 'prenom');
    if (!$nom) {
        $skippedInvalid++;
        continue;
    }

    $slug = slugify(($prenom ? $prenom . ' ' : '') . $nom);
    if (!$slug) {
        $skippedInvalid++;
        continue;
    }

    // Ne jamais ecraser les elus manuels
    if (isset($manualSlugs[$slug])) {
        $skippedManual++;
        continue;
    }

    // Dedup intra-fichier : garder la premiere occurrence
    if (isset($seenSlugs[$slug])) {
        $skippedDuplicates++;
        continue;
    }
    $seenSlugs[$slug] = true;

    $fonction = extractField($row, $colMap, 'fonction');
    $parti = extractField($row, $colMap, 'parti');
    $dept = extractField($row, $colMap, 'departement');
    $region = extractField($row, $colMap, 'region');
    $dateNaissance = parseDate(extractField($row, $colMap, 'date_naissance'));
    $commune = extractField($row, $colMap, 'commune');
    $sourceId = md5($nom . ($prenom ?? '') . ($dateNaissance ?? ''));

    // Deduire le type de mandat
    $typeMandat = 'elu_local';
    if ($fonction) {
        $fLower = mb_strtolower($fonction);
        if (str_contains($fLower, 'maire') || str_contains($fLower, 'adjoint')) $typeMandat = 'maire';
        elseif (str_contains($fLower, 'conseiller municipal') || str_contains($fLower, 'conseillère municipal')) $typeMandat = 'conseiller_municipal';
        elseif (str_contains($fLower, 'conseiller départemental') || str_contains($fLower, 'conseillère départemental')) $typeMandat = 'conseiller_departemental';
        elseif (str_contains($fLower, 'conseiller régional') || str_contains($fLower, 'conseillère régional')) $typeMandat = 'conseiller_regional';
        elseif (str_contains($fLower, 'président') && str_contains($fLower, 'région')) $typeMandat = 'president_region';
        elseif (str_contains($fLower, 'président') && str_contains($fLower, 'département')) $typeMandat = 'president_departement';
    }

    // Enrichir la fonction avec la commune si disponible
    if ($commune && $fonction && !str_contains($fonction, $commune)) {
        $fonction .= ' - ' . $commune;
    }

    $isUpdate = isset($existingSlugs[$slug]);
    $existingSlugs[$slug] = true;

    $batch[] = [
        'nom'            => $nom,
        'prenom'         => $prenom,
        'slug'           => $slug,
        'parti'          => $parti ?: null,
        'fonction'       => $fonction ?: null,
        'departement'    => $dept ?: null,
        'region'         => $region ?: null,
        'date_naissance' => $dateNaissance,
        'type_mandat'    => $typeMandat,
        'source_id'      => $sourceId,
        'is_update'      => $isUpdate,
    ];

    if (count($batch) >= BATCH_SIZE) {
        if (!$dryRun && $pdo) {
            [$ins, $upd] = flushBatch($pdo, $batch);
            $inserted += $ins;
            $updated += $upd;
        } else {
            foreach ($batch as $r) {
                if ($r['is_update']) $updated++; else $inserted++;
            }
        }
        $batch = [];

        // Progression
        $pct = round($processed / $totalLines * 100, 1);
        $elapsed = round(microtime(true) - $startTime, 1);
        echo "\r  $processed / $totalLines ($pct%) — {$inserted} inseres, {$updated} maj — {$elapsed}s";
    }
}

// Flush le dernier lot
if (!empty($batch)) {
    if (!$dryRun && $pdo) {
        [$ins, $upd] = flushBatch($pdo, $batch);
        $inserted += $ins;
        $updated += $upd;
    } else {
        foreach ($batch as $r) {
            if ($r['is_update']) $updated++; else $inserted++;
        }
    }
}

fclose($handle);

// ── Resultats ──

$totalTime = round(microtime(true) - $startTime, 1);
echo "\n\n=== RESULTAT ===\n";
echo "Lignes traitees   : $processed\n";
echo "Inseres           : $inserted\n";
echo "Mis a jour        : $updated\n";
echo "Doublons CSV      : $skippedDuplicates\n";
echo "Ignores (manual)  : $skippedManual\n";
echo "Ignores (invalide): $skippedInvalid\n";
echo "Duree             : {$totalTime}s\n";

// ── Log en BDD ──
if (!$dryRun && $pdo) {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO fetch_log (source, endpoint, status, records_count, error_message, duration_ms)
            VALUES (:source, :endpoint, :status, :count, :error, :duration)
        ');
        $stmt->execute([
            ':source'   => 'rne_csv',
            ':endpoint' => $csvPath,
            ':status'   => 'success',
            ':count'    => $inserted + $updated,
            ':error'    => null,
            ':duration' => (int)($totalTime * 1000),
        ]);
    } catch (PDOException $e) {
        // Non bloquant
    }
}

// ── Fonction batch INSERT ──

function flushBatch(PDO $pdo, array $batch): array {
    if (empty($batch)) return [0, 0];

    $inserted = 0;
    $updated = 0;

    // Construire le INSERT ... ON DUPLICATE KEY UPDATE
    $placeholders = [];
    $values = [];
    $i = 0;

    foreach ($batch as $row) {
        $placeholders[] = "(:nom{$i}, :prenom{$i}, :slug{$i}, :parti{$i}, :fonction{$i}, "
            . ":dept{$i}, :region{$i}, :dob{$i}, :type{$i}, :srcid{$i}, 'rne_csv', NOW())";

        $values[":nom{$i}"]      = $row['nom'];
        $values[":prenom{$i}"]   = $row['prenom'];
        $values[":slug{$i}"]     = $row['slug'];
        $values[":parti{$i}"]    = $row['parti'];
        $values[":fonction{$i}"] = $row['fonction'];
        $values[":dept{$i}"]     = $row['departement'];
        $values[":region{$i}"]   = $row['region'];
        $values[":dob{$i}"]      = $row['date_naissance'];
        $values[":type{$i}"]     = $row['type_mandat'];
        $values[":srcid{$i}"]    = $row['source_id'];

        if ($row['is_update']) $updated++;
        else $inserted++;

        $i++;
    }

    $sql = "INSERT INTO elus (nom, prenom, slug, parti, fonction, departement, region, "
        . "date_naissance, type_mandat, source_id, source_api, derniere_sync) VALUES "
        . implode(",\n", $placeholders)
        . " ON DUPLICATE KEY UPDATE "
        . "nom = IF(source_api != 'manual', VALUES(nom), nom), "
        . "prenom = IF(source_api != 'manual', VALUES(prenom), prenom), "
        . "parti = IF(source_api != 'manual', VALUES(parti), parti), "
        . "fonction = IF(source_api != 'manual', VALUES(fonction), fonction), "
        . "departement = IF(source_api != 'manual', VALUES(departement), departement), "
        . "region = IF(source_api != 'manual', VALUES(region), region), "
        . "date_naissance = IF(source_api != 'manual', VALUES(date_naissance), date_naissance), "
        . "type_mandat = IF(source_api != 'manual', VALUES(type_mandat), type_mandat), "
        . "source_id = IF(source_api != 'manual', VALUES(source_id), source_id), "
        . "derniere_sync = NOW()";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    } catch (PDOException $e) {
        fwrite(STDERR, "\nERREUR SQL batch : " . $e->getMessage() . "\n");
        fwrite(STDERR, "Fallback : insertion ligne par ligne...\n");
        $inserted = 0;
        $updated = 0;
        foreach ($batch as $row) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO elus (nom, prenom, slug, parti, fonction, departement, region,
                        date_naissance, type_mandat, source_id, source_api, derniere_sync)
                    VALUES (:nom, :prenom, :slug, :parti, :fonction, :dept, :region,
                        :dob, :type, :srcid, 'rne_csv', NOW())
                    ON DUPLICATE KEY UPDATE
                        nom = IF(source_api != 'manual', VALUES(nom), nom),
                        prenom = IF(source_api != 'manual', VALUES(prenom), prenom),
                        parti = IF(source_api != 'manual', VALUES(parti), parti),
                        fonction = IF(source_api != 'manual', VALUES(fonction), fonction),
                        departement = IF(source_api != 'manual', VALUES(departement), departement),
                        region = IF(source_api != 'manual', VALUES(region), region),
                        date_naissance = IF(source_api != 'manual', VALUES(date_naissance), date_naissance),
                        type_mandat = IF(source_api != 'manual', VALUES(type_mandat), type_mandat),
                        source_id = IF(source_api != 'manual', VALUES(source_id), source_id),
                        derniere_sync = NOW()
                ");
                $stmt->execute([
                    ':nom'     => $row['nom'],
                    ':prenom'  => $row['prenom'],
                    ':slug'    => $row['slug'],
                    ':parti'   => $row['parti'],
                    ':fonction'=> $row['fonction'],
                    ':dept'    => $row['departement'],
                    ':region'  => $row['region'],
                    ':dob'     => $row['date_naissance'],
                    ':type'    => $row['type_mandat'],
                    ':srcid'   => $row['source_id'],
                ]);
                if ($row['is_update']) $updated++; else $inserted++;
            } catch (PDOException $e2) {
                fwrite(STDERR, "  Skip: {$row['slug']} — {$e2->getMessage()}\n");
            }
        }
    }

    return [$inserted, $updated];
}
