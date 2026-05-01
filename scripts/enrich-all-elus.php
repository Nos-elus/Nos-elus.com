#!/usr/bin/env php
<?php
/**
 * enrich-all-elus.php — Vérifie et corrige les données de TOUS les élus en BDD
 *
 * Tout en SQL pur (pas de boucle PHP sur les rows).
 * Usage : php enrich-all-elus.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv);

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Erreur connexion BDD : " . $e->getMessage() . "\n");
    exit(1);
}

echo "=== enrich-all-elus.php ===\n";
echo $dryRun ? "MODE DRY-RUN (aucune modification)\n" : "MODE ÉCRITURE\n";
echo str_repeat('-', 50) . "\n\n";

// ─── 1. Normaliser les noms tout en majuscules ───
// On ne touche que les noms qui sont entièrement en majuscules (BINARY pour case-sensitive)
$countNom = $pdo->query("
    SELECT COUNT(*) FROM elus
    WHERE BINARY nom = UPPER(nom)
      AND CHAR_LENGTH(nom) > 1
      AND source_api != 'manual'
")->fetchColumn();

echo "1) Noms tout en majuscules à normaliser : $countNom\n";

if (!$dryRun && $countNom > 0) {
    // Gère les noms composés avec tiret (ex: LE PEN → Le Pen, DUPONT-MORETTI → Dupont-Moretti)
    $pdo->exec("
        UPDATE elus
        SET nom = (
            SELECT GROUP_CONCAT(
                CONCAT(UPPER(LEFT(part, 1)), LOWER(SUBSTRING(part, 2)))
                SEPARATOR '-'
            )
            FROM (
                SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(e2.nom, '-', numbers.n), '-', -1) AS part
                FROM (SELECT nom FROM elus WHERE id = elus.id) e2
                CROSS JOIN (
                    SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
                ) numbers
                WHERE n <= 1 + CHAR_LENGTH(e2.nom) - CHAR_LENGTH(REPLACE(e2.nom, '-', ''))
            ) parts
        )
        WHERE BINARY nom = UPPER(nom)
          AND CHAR_LENGTH(nom) > 1
          AND source_api != 'manual'
    ");
    // Fallback plus simple si la requête complexe ne matche pas tout :
    // On re-normalise les noms simples (sans tiret) qui sont restés en majuscules
    $pdo->exec("
        UPDATE elus
        SET nom = CONCAT(UPPER(LEFT(nom, 1)), LOWER(SUBSTRING(nom, 2)))
        WHERE BINARY nom = UPPER(nom)
          AND CHAR_LENGTH(nom) > 1
          AND nom NOT LIKE '%-%'
          AND source_api != 'manual'
    ");
    echo "   → Corrigé.\n";
} else {
    echo "   → " . ($dryRun ? "Skipped (dry-run)" : "Rien à faire") . "\n";
}

// Même chose pour les prénoms
$countPrenom = $pdo->query("
    SELECT COUNT(*) FROM elus
    WHERE prenom IS NOT NULL
      AND BINARY prenom = UPPER(prenom)
      AND CHAR_LENGTH(prenom) > 1
      AND source_api != 'manual'
")->fetchColumn();

echo "   Prénoms tout en majuscules à normaliser : $countPrenom\n";

if (!$dryRun && $countPrenom > 0) {
    $pdo->exec("
        UPDATE elus
        SET prenom = CONCAT(UPPER(LEFT(prenom, 1)), LOWER(SUBSTRING(prenom, 2)))
        WHERE prenom IS NOT NULL
          AND BINARY prenom = UPPER(prenom)
          AND CHAR_LENGTH(prenom) > 1
          AND prenom NOT LIKE '%-%'
          AND source_api != 'manual'
    ");
    // Prénoms composés avec tiret (Jean-Pierre, Marie-Claire, etc.)
    $pdo->exec("
        UPDATE elus
        SET prenom = CONCAT(
            UPPER(LEFT(SUBSTRING_INDEX(prenom, '-', 1), 1)),
            LOWER(SUBSTRING(SUBSTRING_INDEX(prenom, '-', 1), 2)),
            '-',
            UPPER(LEFT(SUBSTRING_INDEX(prenom, '-', -1), 1)),
            LOWER(SUBSTRING(SUBSTRING_INDEX(prenom, '-', -1), 2))
        )
        WHERE prenom IS NOT NULL
          AND BINARY prenom = UPPER(prenom)
          AND CHAR_LENGTH(prenom) > 1
          AND prenom LIKE '%-%'
          AND CHAR_LENGTH(prenom) - CHAR_LENGTH(REPLACE(prenom, '-', '')) = 1
          AND source_api != 'manual'
    ");
    echo "   → Corrigé.\n";
} else {
    echo "   → " . ($dryRun ? "Skipped (dry-run)" : "Rien à faire") . "\n";
}

echo "\n";

// ─── 2. Fix "Mairee" → "Maire" dans les fonctions ───
$countMairee = $pdo->query("
    SELECT COUNT(*) FROM elus WHERE fonction LIKE '%Mairee%' AND source_api != 'manual'
")->fetchColumn();

echo "2) Fonctions contenant 'Mairee' : $countMairee\n";

if (!$dryRun && $countMairee > 0) {
    $pdo->exec("
        UPDATE elus SET fonction = REPLACE(fonction, 'Mairee', 'Maire') WHERE fonction LIKE '%Mairee%' AND source_api != 'manual'
    ");
    echo "   → Corrigé.\n";
} else {
    echo "   → " . ($dryRun ? "Skipped (dry-run)" : "Rien à faire") . "\n";
}

// Aussi dans les mandats
$countMaireeMandats = $pdo->query("
    SELECT COUNT(*) FROM mandats WHERE titre LIKE '%Mairee%'
")->fetchColumn();

echo "   Mandats contenant 'Mairee' : $countMaireeMandats\n";

if (!$dryRun && $countMaireeMandats > 0) {
    $pdo->exec("
        UPDATE mandats SET titre = REPLACE(titre, 'Mairee', 'Maire') WHERE titre LIKE '%Mairee%'
    ");
    echo "   → Corrigé.\n";
} else {
    echo "   → " . ($dryRun ? "Skipped (dry-run)" : "Rien à faire") . "\n";
}

echo "\n";

// ─── 3. Fix type_mandat incohérents ───
echo "3) type_mandat incohérents :\n";

$fixes = [
    ['label' => 'Député',    'pattern' => '%Déput%',       'target' => 'depute'],
    ['label' => 'Sénateur',  'pattern' => '%Sénat%',       'target' => 'senateur'],
    ['label' => 'Européen',  'pattern' => '%europé%',      'target' => 'europeen'],
    ['label' => 'Ministre',  'pattern' => '%Ministre%',    'target' => 'ministre'],
    ['label' => 'Maire',     'pattern' => '%Maire %',      'target' => 'maire'],
    ['label' => 'Président', 'pattern' => '%Président %',  'target' => 'president'],
];

foreach ($fixes as $fix) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM elus
        WHERE fonction LIKE ?
          AND (type_mandat IS NULL OR type_mandat != ?)
          AND source_api != 'manual'
    ");
    $stmt->execute([$fix['pattern'], $fix['target']]);
    $count = $stmt->fetchColumn();

    echo "   {$fix['label']} (fonction LIKE '{$fix['pattern']}' mais type_mandat != '{$fix['target']}') : $count\n";

    if (!$dryRun && $count > 0) {
        $stmt = $pdo->prepare("
            UPDATE elus
            SET type_mandat = ?
            WHERE fonction LIKE ?
              AND (type_mandat IS NULL OR type_mandat != ?)
              AND source_api != 'manual'
        ");
        $stmt->execute([$fix['target'], $fix['pattern'], $fix['target']]);
        echo "   → Corrigé.\n";
    } else {
        echo "   → " . ($dryRun ? "Skipped (dry-run)" : "Rien à faire") . "\n";
    }
}

echo "\n";

// ─── 4. Fix partis NULL pour députés (depuis mandats) ───
// On regarde si un député sans parti a un mandat dont le titre contient un groupe parlementaire connu
$countPartiNull = $pdo->query("
    SELECT COUNT(*) FROM elus
    WHERE parti IS NULL
      AND type_mandat = 'depute'
      AND actif = 1
")->fetchColumn();

echo "4) Députés actifs sans parti : $countPartiNull\n";
echo "   (Aucune correction automatique sans source fiable — à enrichir via API AN)\n";

echo "\n";

// ─── 5. Supprimer les mandats orphelins ───
// Normalement le ON DELETE CASCADE gère ça, mais au cas où il y aurait eu des imports foireux
// on vérifie avec LEFT JOIN
$countOrphelins = $pdo->query("
    SELECT COUNT(*) FROM mandats m
    LEFT JOIN elus e ON m.elu_id = e.id
    WHERE e.id IS NULL
")->fetchColumn();

echo "5) Mandats orphelins (elu_id inexistant) : $countOrphelins\n";

if (!$dryRun && $countOrphelins > 0) {
    $pdo->exec("
        DELETE m FROM mandats m
        LEFT JOIN elus e ON m.elu_id = e.id
        WHERE e.id IS NULL
    ");
    echo "   → Supprimé.\n";
} else {
    echo "   → " . ($dryRun ? "Skipped (dry-run)" : "Rien à faire") . "\n";
}

// Même chose pour affaires, bonnes_actions, affiliations, votes
$tables = ['affaires', 'bonnes_actions', 'affiliations', 'votes'];
foreach ($tables as $table) {
    $count = $pdo->query("
        SELECT COUNT(*) FROM $table t
        LEFT JOIN elus e ON t.elu_id = e.id
        WHERE e.id IS NULL
    ")->fetchColumn();

    echo "   $table orphelins : $count\n";

    if (!$dryRun && $count > 0) {
        $pdo->exec("
            DELETE t FROM $table t
            LEFT JOIN elus e ON t.elu_id = e.id
            WHERE e.id IS NULL
        ");
        echo "   → Supprimé.\n";
    }
}

echo "\n";

// ─── 6. Dédupliquer les mandats identiques ───
$countDupes = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT elu_id, titre, date_debut, COUNT(*) AS cnt
        FROM mandats
        GROUP BY elu_id, titre, date_debut
        HAVING cnt > 1
    ) dupes
")->fetchColumn();

// Nombre total de lignes en doublon à supprimer
$countDupeRows = $pdo->query("
    SELECT COALESCE(SUM(cnt - 1), 0) FROM (
        SELECT elu_id, titre, date_debut, COUNT(*) AS cnt
        FROM mandats
        GROUP BY elu_id, titre, date_debut
        HAVING cnt > 1
    ) dupes
")->fetchColumn();

echo "6) Groupes de mandats dupliqués : $countDupes (lignes en trop à supprimer : $countDupeRows)\n";

if (!$dryRun && $countDupeRows > 0) {
    // Supprime les doublons en gardant le mandat avec le plus petit id
    $pdo->exec("
        DELETE m1 FROM mandats m1
        INNER JOIN mandats m2
            ON m1.elu_id = m2.elu_id
            AND m1.titre = m2.titre
            AND (m1.date_debut = m2.date_debut OR (m1.date_debut IS NULL AND m2.date_debut IS NULL))
            AND m1.id > m2.id
    ");
    echo "   → Dédupliqué (conservé le plus ancien id).\n";
} else {
    echo "   → " . ($dryRun ? "Skipped (dry-run)" : "Rien à faire") . "\n";
}

echo "\n";

// ─── Résumé ───
echo str_repeat('=', 50) . "\n";
$totalElus = $pdo->query("SELECT COUNT(*) FROM elus")->fetchColumn();
$totalMandats = $pdo->query("SELECT COUNT(*) FROM mandats")->fetchColumn();
echo "Total élus en BDD    : $totalElus\n";
echo "Total mandats en BDD : $totalMandats\n";
echo $dryRun ? "\n⚠ Dry-run terminé — aucune modification effectuée.\n" : "\nEnrichissement terminé.\n";
