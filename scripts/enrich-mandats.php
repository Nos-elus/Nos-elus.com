#!/usr/bin/env php
<?php
/**
 * enrich-mandats.php — Cree un mandat automatique pour chaque elu qui a
 * une fonction renseignee mais 0 mandat en base.
 *
 * Usage :
 *   php scripts/enrich-mandats.php              # execution reelle
 *   php scripts/enrich-mandats.php --dry-run    # simulation sans ecriture
 *
 * Traite par batch de 500, affiche la progression.
 */

// ── CLI args ──
$dryRun = in_array('--dry-run', $argv, true);
$batchSize = 500;

// ── Connexion BDD ──
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

// Mapping type_mandat -> institution
$institutionMap = [
    'depute'         => 'Assemblée nationale',
    'senateur'       => 'Sénat',
    'maire'          => 'Mairie',
    'europeen'       => 'Parlement européen',
    'regional'       => 'Conseil régional',
    'departemental'  => 'Conseil départemental',
];

echo "=== enrich-mandats.php ===\n";
echo "Mode : " . ($dryRun ? "DRY-RUN (aucune ecriture)" : "EXECUTION REELLE") . "\n\n";

// ── Compter les elus concernes ──
$countStmt = $pdo->query('
    SELECT COUNT(*) FROM elus e
    LEFT JOIN mandats m ON m.elu_id = e.id
    WHERE m.id IS NULL
      AND e.fonction IS NOT NULL
      AND e.fonction != \'\'
      AND e.source_api != \'manual\'
');
$total = (int) $countStmt->fetchColumn();

if ($total === 0) {
    echo "Aucun elu sans mandat a enrichir. Rien a faire.\n";
    exit(0);
}

echo "Elus sans mandat avec fonction renseignee : $total\n";
echo "Traitement par batch de $batchSize...\n\n";

// ── Prepare les statements ──
$selectStmt = $pdo->prepare('
    SELECT e.id, e.nom, e.prenom, e.fonction, e.type_mandat
    FROM elus e
    LEFT JOIN mandats m ON m.elu_id = e.id
    WHERE m.id IS NULL
      AND e.fonction IS NOT NULL
      AND e.fonction != \'\'
      AND e.source_api != \'manual\'
    ORDER BY e.id
    LIMIT :offset, :batch
');

$insertStmt = $pdo->prepare('
    INSERT INTO mandats (elu_id, titre, date_debut, institution)
    VALUES (:elu_id, :titre, CURDATE(), :institution)
');

$processed = 0;
$created = 0;
$skipped = 0;
$offset = 0;

while ($processed < $total) {
    $selectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $selectStmt->bindValue(':batch', $batchSize, PDO::PARAM_INT);
    $selectStmt->execute();
    $elus = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($elus)) {
        break;
    }

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($elus as $elu) {
            $typeMandat = strtolower(trim($elu['type_mandat'] ?? ''));
            $institution = $institutionMap[$typeMandat] ?? null;

            // Si le type_mandat n'est pas dans le mapping, on essaie de deviner
            // a partir de la fonction
            if (!$institution) {
                $institution = guessInstitution($elu['fonction'], $institutionMap);
            }

            if (!$institution) {
                // On ne peut pas deviner -> on met une valeur generique
                $institution = 'Institution non determinee';
            }

            $titre = $elu['fonction'];

            if ($dryRun) {
                $label = trim(($elu['prenom'] ?? '') . ' ' . $elu['nom']);
                echo "  [DRY] #" . $elu['id'] . " $label : \"$titre\" -> $institution\n";
            } else {
                $insertStmt->execute([
                    ':elu_id'      => $elu['id'],
                    ':titre'       => $titre,
                    ':institution' => $institution,
                ]);
            }
            $created++;
        }

        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (\Exception $e) {
        if (!$dryRun) {
            $pdo->rollBack();
        }
        echo "ERREUR batch offset=$offset : " . $e->getMessage() . "\n";
    }

    $processed += count($elus);
    // On ne peut pas increment offset normally because we're inserting mandats
    // which changes the LEFT JOIN result. Use a fresh offset=0 each time in real mode
    // since processed elus now have mandats and won't appear again.
    if ($dryRun) {
        $offset += $batchSize;
    }
    // In real mode, offset stays 0 because processed elus disappear from the query.

    $pct = min(100, round($processed / $total * 100));
    echo "  Progression : $processed / $total ($pct%) — $created mandats " . ($dryRun ? 'a creer' : 'crees') . "\n";
}

echo "\n=== Termine ===\n";
echo "Total elus traites : $processed\n";
echo "Mandats " . ($dryRun ? 'a creer' : 'crees') . " : $created\n";

if ($dryRun) {
    echo "\nRelancez sans --dry-run pour executer.\n";
}

// ── Helpers ──

/**
 * Essaie de deviner l'institution a partir du libelle de la fonction.
 */
function guessInstitution(string $fonction, array $map): ?string {
    $lower = mb_strtolower($fonction);

    $patterns = [
        'député'               => 'Assemblée nationale',
        'députée'              => 'Assemblée nationale',
        'depute'               => 'Assemblée nationale',
        'sénateur'             => 'Sénat',
        'sénatrice'            => 'Sénat',
        'senateur'             => 'Sénat',
        'maire'                => 'Mairie',
        'européen'             => 'Parlement européen',
        'européenne'           => 'Parlement européen',
        'europeen'             => 'Parlement européen',
        'parlement européen'   => 'Parlement européen',
        'conseil régional'     => 'Conseil régional',
        'conseiller régional'  => 'Conseil régional',
        'conseillère régional' => 'Conseil régional',
        'conseil départemental'  => 'Conseil départemental',
        'conseiller départemental' => 'Conseil départemental',
        'conseillère départementale' => 'Conseil départemental',
        'conseil municipal'    => 'Mairie',
        'conseiller municipal' => 'Mairie',
        'conseillère municipale' => 'Mairie',
        'adjoint'              => 'Mairie',
        'adjointe'             => 'Mairie',
        'président'            => 'Conseil régional',
    ];

    foreach ($patterns as $keyword => $institution) {
        if (mb_strpos($lower, $keyword) !== false) {
            return $institution;
        }
    }

    return null;
}
