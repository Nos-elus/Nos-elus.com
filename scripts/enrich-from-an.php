#!/usr/bin/env php
<?php
/**
 * Enrichit les députés depuis l'API NosDéputés.fr
 * Usage: php enrich-from-an.php [--dry-run] [--limit=N]
 */

// ── Args ──
$dryRun = in_array('--dry-run', $argv);
$limit  = 0;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1];
}

// ── BDD ──
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_NAME') ?: 'nos_elus'
    ),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ── Helpers ──
function fetch_json(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: NosElus-Enricher/1.0\r\nAccept: application/json\r\n",
        'timeout' => 15,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) { echo "  ✗ Fetch failed: $url\n"; return null; }
    return json_decode($raw, true);
}

function normalize(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = str_replace(['é','è','ê','ë'], 'e', $s);
    $s = str_replace(['à','â','ä'], 'a', $s);
    $s = str_replace(['ù','û','ü'], 'u', $s);
    $s = str_replace(['î','ï'], 'i', $s);
    $s = str_replace(['ô','ö'], 'o', $s);
    $s = str_replace(['ç'], 'c', $s);
    $s = str_replace(['-', "'", "'"], ' ', $s);
    return preg_replace('/\s+/', ' ', $s);
}

// ── 1. Fetch liste complète des députés ──
echo "→ Téléchargement de la liste NosDéputés.fr...\n";
$data = fetch_json('https://www.nosdeputes.fr/deputes/json');
if (!$data || !isset($data['deputes'])) {
    die("✗ Impossible de récupérer la liste des députés.\n");
}

$deputes = $data['deputes'];
echo "  " . count($deputes) . " députés trouvés sur NosDéputés.fr\n";

// ── 2. Charger nos élus (députés) ──
$stmt = $pdo->query("SELECT id, nom, prenom, photo_url, slug FROM elus WHERE (type_mandat LIKE '%déput%' OR fonction LIKE '%déput%' OR type_mandat LIKE '%Assemblée%' OR fonction LIKE '%Assemblée%') AND source_api != 'manual'");
$nosElus = $stmt->fetchAll();
echo "  " . count($nosElus) . " députés dans notre BDD\n";

// Index par nom normalisé pour matching rapide
$elusByName = [];
foreach ($nosElus as $elu) {
    // Clé "prenom nom" et "nom" pour fallback
    $prenom = normalize($elu['prenom'] ?? '');
    $nom = normalize($elu['nom']);
    // Le champ nom peut contenir "Prénom Nom" si prenom est vide
    if (!$prenom && str_contains($nom, ' ')) {
        $parts = explode(' ', $nom);
        $prenom = array_shift($parts);
        $nom = implode(' ', $parts);
    }
    $key = trim("$prenom $nom");
    $elusByName[$key] = $elu;
    // Aussi indexer par nom seul si unique
    if (!isset($elusByName[$nom])) {
        $elusByName[$nom] = $elu;
    }
}

// ── Compteurs mandats existants ──
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM mandats WHERE elu_id = ?");

// ── Prepared statements ──
$stmtPhoto = $pdo->prepare("UPDATE elus SET photo_url = ?, derniere_sync = NOW() WHERE id = ?");
$stmtMandat = $pdo->prepare("INSERT INTO mandats (elu_id, titre, date_debut, date_fin, institution) VALUES (?, ?, ?, ?, ?)");
$stmtBio = $pdo->prepare("UPDATE elus SET bio = ? WHERE id = ? AND (bio IS NULL OR bio = '')");
$stmtDept = $pdo->prepare("UPDATE elus SET departement = ? WHERE id = ? AND (departement IS NULL OR departement = '')");

// ── 3. Matching & enrichissement ──
$stats = ['matched' => 0, 'photo_updated' => 0, 'mandats_added' => 0, 'bio_updated' => 0, 'skipped' => 0];
$processed = 0;

foreach ($deputes as $entry) {
    $dep = $entry['depute'] ?? $entry;
    if (!$dep) continue;

    $slug_nd = $dep['slug'] ?? null;
    $nomDep = normalize(($dep['prenom'] ?? '') . ' ' . ($dep['nom'] ?? $dep['nom_de_famille'] ?? ''));
    $nomSeul = normalize($dep['nom'] ?? $dep['nom_de_famille'] ?? '');

    // Match
    $elu = $elusByName[$nomDep] ?? $elusByName[$nomSeul] ?? null;
    if (!$elu) {
        $stats['skipped']++;
        continue;
    }

    $stats['matched']++;
    $processed++;
    if ($limit > 0 && $processed > $limit) break;

    $eluId = $elu['id'];
    echo "  ✓ Match: {$dep['prenom']} {$dep['nom']} → elu #{$eluId} ({$elu['slug']})\n";

    // ── Photo ──
    if (empty($elu['photo_url']) && $slug_nd) {
        $photoUrl = "https://www.nosdeputes.fr/depute/photo/{$slug_nd}/200";
        echo "    📷 Photo: $photoUrl\n";
        if (!$dryRun) {
            $stmtPhoto->execute([$photoUrl, $eluId]);
        }
        $stats['photo_updated']++;
    }

    // ── Mandats — fetch détail si < 2 mandats ──
    $stmtCount->execute([$eluId]);
    $nbMandats = (int)$stmtCount->fetchColumn();

    if ($nbMandats < 2 && $slug_nd) {
        usleep(200000); // 200ms entre chaque fetch détail
        $detail = fetch_json("https://www.nosdeputes.fr/{$slug_nd}/json");
        $depDetail = $detail['depute'] ?? null;

        if ($depDetail) {
            // Anciens mandats
            $anciens = $depDetail['anciens_mandats'] ?? [];
            foreach ($anciens as $m) {
                $mandat = $m['mandat'] ?? $m;
                $titre = $mandat['mandat'] ?? $mandat['titre'] ?? 'Mandat';
                $debut = $mandat['date_debut'] ?? null;
                $fin = $mandat['date_fin'] ?? null;
                // Convertir formats
                if ($debut && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) {
                    $t = strtotime($debut);
                    $debut = $t ? date('Y-m-d', $t) : null;
                }
                if ($fin && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
                    $t = strtotime($fin);
                    $fin = $t ? date('Y-m-d', $t) : null;
                }
                $institution = 'Assemblée nationale';
                if (stripos($titre, 'sénat') !== false) $institution = 'Sénat';
                elseif (stripos($titre, 'europ') !== false) $institution = 'Parlement européen';
                elseif (stripos($titre, 'région') !== false || stripos($titre, 'conseil régional') !== false) $institution = 'Conseil régional';
                elseif (stripos($titre, 'municipal') !== false || stripos($titre, 'maire') !== false) $institution = 'Municipalité';

                echo "    📜 Mandat: $titre ($debut → $fin)\n";
                if (!$dryRun) {
                    $stmtMandat->execute([$eluId, $titre, $debut, $fin, $institution]);
                }
                $stats['mandats_added']++;
            }

            // Bio (profession)
            $profession = $depDetail['profession'] ?? null;
            if ($profession) {
                echo "    📝 Bio/profession: $profession\n";
                if (!$dryRun) {
                    $stmtBio->execute([$profession, $eluId]);
                    $stats['bio_updated']++;
                }
            }

            // Département
            $numDept = $depDetail['num_departement'] ?? null;
            if ($numDept) {
                if (!$dryRun) {
                    $stmtDept->execute([$numDept, $eluId]);
                }
            }
        }
    }
}

// ── Résumé ──
echo "\n══════════════════════════════════\n";
echo $dryRun ? "  MODE DRY-RUN (aucune écriture)\n" : "  ENRICHISSEMENT TERMINÉ\n";
echo "  Matchés:        {$stats['matched']}\n";
echo "  Photos ajoutées: {$stats['photo_updated']}\n";
echo "  Mandats ajoutés: {$stats['mandats_added']}\n";
echo "  Bios ajoutées:   {$stats['bio_updated']}\n";
echo "  Non trouvés:     {$stats['skipped']}\n";
echo "══════════════════════════════════\n";
