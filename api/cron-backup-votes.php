<?php
/**
 * CRON — Backup quotidien des fichiers critiques (votes, visites)
 * Usage : php cron-backup-votes.php
 * Cron suggéré : 0 2 * * * (tous les jours à 2h)
 *
 * Garde les 30 derniers backups. Stocke hors webroot.
 */

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

$cacheDir  = __DIR__ . '/cache/data';
$backupDir = dirname(dirname(__DIR__)) . '/backups/votes';
$keep      = 30;

// Fichiers critiques à sauvegarder
$files = [
    'votes_citoyens.json',
    'votes_2027.json',
    'visits.json',
];

// Créer le dossier backup
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$date = date('Y-m-d_H-i');
$backed = 0;

foreach ($files as $f) {
    $src = "$cacheDir/$f";
    if (!file_exists($src)) {
        echo "SKIP $f — fichier absent\n";
        continue;
    }
    $size = filesize($src);
    if ($size < 3) {
        echo "SKIP $f — fichier vide ($size octets)\n";
        continue;
    }
    $dest = "$backupDir/{$date}_{$f}";
    if (copy($src, $dest)) {
        echo "OK $f → $dest ($size octets)\n";
        $backed++;
    } else {
        echo "ERREUR copie $f\n";
    }
}

// Rotation : garder les N derniers backups par fichier
foreach ($files as $f) {
    $pattern = $backupDir . '/*_' . $f;
    $existing = glob($pattern);
    rsort($existing);
    foreach (array_slice($existing, $keep) as $old) {
        unlink($old);
        echo "PURGE ancien backup : " . basename($old) . "\n";
    }
}

echo "\n=== Backup terminé : $backed fichier(s) sauvegardé(s) ===\n";
