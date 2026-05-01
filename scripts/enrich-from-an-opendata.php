#!/usr/bin/env php
<?php
/**
 * enrich-from-an-opendata.php — Enrichit les députés via data.assemblee-nationale.fr (open data JSON)
 * Données : photo, date/lieu naissance, profession, lien HATVP, mandats
 * Pas d'API à rate-limiter : c'est un fichier ZIP téléchargé une fois.
 *
 * Usage : php scripts/enrich-from-an-opendata.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv);

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'nos_elus';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERREUR BDD: " . $e->getMessage() . "\n");
    exit(1);
}

echo "=== Enrichissement Assemblée nationale (Open Data) ===\n";
echo "Dry-run: " . ($dryRun ? 'OUI' : 'NON') . "\n\n";

// ── Télécharger le ZIP ──
$zipUrl = 'https://data.assemblee-nationale.fr/static/openData/repository/17/amo/deputes_actifs_mandats_actifs_organes/AMO10_deputes_actifs_mandats_actifs_organes.json.zip';
$zipPath = '/tmp/an-deputes-enrich.json.zip';
$extractDir = '/tmp/an-deputes-enrich/';

echo "Téléchargement... ";
$ch = curl_init($zipUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
$zipData = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$zipData) {
    echo "❌ échec (HTTP $code)\n";
    exit(1);
}
file_put_contents($zipPath, $zipData);
echo "OK (" . round(strlen($zipData) / 1024) . " Ko)\n";

// ── Extraire ──
echo "Extraction... ";
exec("rm -rf $extractDir && unzip -o $zipPath -d $extractDir 2>/dev/null");
$acteurDir = $extractDir . 'json/acteur/';
if (!is_dir($acteurDir)) {
    echo "❌ répertoire acteur introuvable\n";
    exit(1);
}
$files = glob($acteurDir . 'PA*.json');
echo count($files) . " députés trouvés\n\n";

// ── Charger les députés en BDD ──
$stmt = $pdo->query("
    SELECT id, nom, prenom, slug, photo_url, date_naissance, lieu_naissance, bio, source_api
    FROM elus
    WHERE type_mandat = 'depute' AND source_api != 'manual'
");
$dbDeputes = [];
foreach ($stmt->fetchAll() as $row) {
    $key = mb_strtoupper(removeAccents(trim($row['nom']))) . '_' . mb_strtoupper(removeAccents(trim($row['prenom'] ?? '')));
    $dbDeputes[$key] = $row;
}
echo count($dbDeputes) . " députés en BDD (non-manuels)\n\n";

// ── Préparer les updates ──
$updatePhoto = $pdo->prepare('UPDATE elus SET photo_url = :v WHERE id = :id');
$updateBirth = $pdo->prepare('UPDATE elus SET date_naissance = :v WHERE id = :id');
$updateBirthPlace = $pdo->prepare('UPDATE elus SET lieu_naissance = :v WHERE id = :id');
$updateBio = $pdo->prepare('UPDATE elus SET bio = :v WHERE id = :id');

$stats = ['matched' => 0, 'photos' => 0, 'births' => 0, 'bios' => 0, 'hatvp' => 0, 'skipped' => 0];

foreach ($files as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['acteur'])) continue;

    $a = $data['acteur'];
    $ec = $a['etatCivil']['ident'] ?? [];
    $nom = $ec['nom'] ?? '';
    $prenom = $ec['prenom'] ?? '';
    $uid = is_array($a['uid']) ? ($a['uid']['#text'] ?? '') : ($a['uid'] ?? '');

    $key = mb_strtoupper(removeAccents(trim($nom))) . '_' . mb_strtoupper(removeAccents(trim($prenom)));
    if (!isset($dbDeputes[$key])) {
        $stats['skipped']++;
        continue;
    }

    $elu = $dbDeputes[$key];
    $stats['matched']++;
    $updated = false;

    // Photo
    $photoUrl = "https://www.assemblee-nationale.fr/dyn/deputes/$uid/photo";
    if (empty($elu['photo_url']) || strpos($elu['photo_url'] ?? '', 'dicebear') !== false) {
        if (!$dryRun) $updatePhoto->execute([':v' => $photoUrl, ':id' => $elu['id']]);
        $stats['photos']++;
        $updated = true;
    }

    // Date naissance
    $birthDate = $a['etatCivil']['infoNaissance']['dateNais'] ?? null;
    if (empty($elu['date_naissance']) && $birthDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) && $birthDate >= '1900-01-01') {
        if (!$dryRun) $updateBirth->execute([':v' => $birthDate, ':id' => $elu['id']]);
        $stats['births']++;
        $updated = true;
    }

    // Lieu naissance
    $birthPlace = $a['etatCivil']['infoNaissance']['villeNais'] ?? null;
    if (empty($elu['lieu_naissance']) && $birthPlace) {
        if (!$dryRun) $updateBirthPlace->execute([':v' => $birthPlace, ':id' => $elu['id']]);
        $updated = true;
    }

    // Bio (profession + lieu naissance)
    if (empty($elu['bio'])) {
        $parts = [];
        if ($birthDate) $parts[] = "Né" . (($ec['civ'] ?? '') === 'Mme' ? "e" : "") . " le " . date('d/m/Y', strtotime($birthDate)) . ($birthPlace ? " à $birthPlace" : "");
        $profession = $a['profession']['libelleCourant'] ?? null;
        if ($profession) $parts[] = "Profession : $profession";
        $parts[] = "Député(e) de la XVIIe législature.";
        $hatvp = $a['uri_hatvp'] ?? null;
        if ($hatvp) {
            $parts[] = "Déclaration HATVP : $hatvp";
            $stats['hatvp']++;
        }
        $bio = implode("\n", $parts);
        if (!$dryRun) $updateBio->execute([':v' => $bio, ':id' => $elu['id']]);
        $stats['bios']++;
        $updated = true;
    }

    if ($updated) {
        echo "  ✅ $prenom $nom" . ($uid ? " ($uid)" : "") . "\n";
    }
}

echo "\n=== Résumé ===\n";
echo "Matchés: {$stats['matched']} | Photos: {$stats['photos']} | Naissances: {$stats['births']} | Bios: {$stats['bios']} | HATVP: {$stats['hatvp']} | Non matchés: {$stats['skipped']}\n";

// Cleanup
exec("rm -rf $extractDir $zipPath");

function removeAccents(string $str): string {
    $t = @\Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
    return $t ? $t->transliterate($str) : $str;
}
