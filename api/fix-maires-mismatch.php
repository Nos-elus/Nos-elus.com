<?php
/**
 * Traitement des MISMATCH : maires dans notre BDD qui ne correspondent plus
 * au maire actuel sur la-mairie.com (changement de maire, municipales 2026)
 *
 * Pour chaque mismatch :
 * 1. Vérifie sur la-mairie.com qui est le VRAI maire actuel
 * 2. Passe l'ancien maire en inactif (fonction "Ancien(ne) Maire")
 * 3. Cherche le nouveau maire dans la BDD (peut déjà exister comme conseiller)
 * 4. Si pas trouvé → crée le nouveau maire
 * 5. Attribue le contact au nouveau maire
 *
 * Usage : php fix-maires-mismatch.php [--dry-run] [--limit=100]
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

$dryRun = in_array('--dry-run', $argv ?? []);
$force  = in_array('--force-election-period', $argv ?? []);
$limit = 200;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
}

// ============================================================
// GARDE-FOU ÉLECTORAL — Municipales 2026
// ============================================================
// Pendant les périodes électorales, la-mairie.com peut renvoyer
// des noms incohérents (maire sortant, nouveau maire provisoire,
// candidats en lice entre 1er et 2e tour). Un mismatch détecté
// ne signifie PAS qu'il y a vraiment eu changement de maire.
//
// Incident du 8 avril 2026 : Damienne Fleury (maire d'Yvré-l'Évêque,
// Sarthe, candidate à sa succession au 2e tour le 22 mars 2026) a été
// faussement désactivée par ce script — et probablement avec elle des
// centaines/milliers d'autres maires sortants candidats.
//
// Blocage : période du 2026-02-01 au 2026-05-31 inclus.
// Peut être contourné via --force-election-period (à vos risques).
// ============================================================
$today = date('Y-m-d');
if (!$force && !$dryRun && $today >= '2026-02-01' && $today <= '2026-05-31') {
    fwrite(STDERR, "⛔ REFUS D'EXÉCUTION — Période électorale (municipales 2026).\n");
    fwrite(STDERR, "   Date courante : $today\n");
    fwrite(STDERR, "   Période bloquée : 2026-02-01 → 2026-05-31\n");
    fwrite(STDERR, "   La-mairie.com n'est pas une source fiable pendant cette fenêtre.\n");
    fwrite(STDERR, "   Pour forcer (déconseillé) : ajouter --force-election-period\n");
    fwrite(STDERR, "   Pour vérifier sans modifier : ajouter --dry-run\n");
    exit(2);
}

echo "=== FIX MISMATCH MAIRES — " . date('Y-m-d H:i:s') . " ===\n";
echo "Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE') . " | Limit: $limit\n";
if ($force) echo "⚠️  --force-election-period ACTIF — période électorale contournée\n";
echo "\n";

// Charger les maires actifs sans contact (ceux que le scraping a raté)
$stmt = $pdo->prepare("
    SELECT e.id, e.nom, e.prenom, e.fonction, e.departement, e.sexe
    FROM elus e
    WHERE e.fonction LIKE 'Maire — %'
    AND e.actif = 1
    AND (e.telephone IS NULL OR e.telephone = '')
    AND (e.email IS NULL OR e.email = '')
    AND e.departement IS NOT NULL
    ORDER BY e.departement, e.nom
    LIMIT :lim
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$maires = $stmt->fetchAll();
echo count($maires) . " maires à vérifier\n\n";

function slugify(string $s): string {
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
    $s = preg_replace('/[^a-z0-9-]/', '-', $s);
    return preg_replace('/-+/', '-', trim($s, '-'));
}

function fetchPage(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NosElusFr/1.0; +https://nos-elus.fr)',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $html) ? $html : null;
}

function extractFullInfo(string $html): array {
    $data = ['tel' => '', 'email' => '', 'adresse' => '', 'site' => '', 'maire_nom' => '', 'maire_prenom' => '', 'sexe' => ''];

    // Nom du maire (format: "Prénom NOM" ou "Prénom-Composé NOM-COMPOSÉ")
    if (preg_match('/(?:Monsieur le maire|Madame la maire)\s+([A-ZÀ-Üa-zà-ü-]+)\s+([A-ZÀ-Ü][A-ZÀ-Ü\s-]+)/u', $html, $m)) {
        $data['maire_prenom'] = ucfirst(mb_strtolower(trim($m[1])));
        $data['maire_nom'] = trim($m[2]);
        $data['sexe'] = (strpos($html, 'Madame la maire') !== false) ? 'F' : 'M';
    }

    // JSON-LD
    if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m)) {
        $json = json_decode($m[1], true);
        if ($json) {
            $data['tel']   = $json['telephone'] ?? '';
            $data['email'] = $json['email'] ?? '';
            if (!empty($json['address'])) {
                $addr = $json['address'];
                $parts = array_filter([$addr['streetAddress'] ?? '', ($addr['postalCode'] ?? '') . ' ' . ($addr['addressLocality'] ?? '')]);
                $data['adresse'] = implode(', ', $parts);
            }
        }
    }

    if (!$data['tel'] && preg_match('/href="tel:([^"]+)"/', $html, $m)) $data['tel'] = trim($m[1]);
    if (!$data['email'] && preg_match('/href="mailto:([^"]+)"/', $html, $m)) $data['email'] = trim(strtolower($m[1]));
    if (preg_match('/Site\s*(?:internet|web|officiel)\s*:\s*<a[^>]+href="(https?:\/\/[^"]+)"/i', $html, $m)) $data['site'] = $m[1];
    $data['tel'] = preg_replace('/[^\d+]/', '', $data['tel']);

    return $data;
}

function normName(string $n): string {
    return mb_strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', trim($n)));
}

$fixedOld = 0;
$createdNew = 0;
$updatedExisting = 0;
$skipped = 0;

$stmtFindElu = $pdo->prepare("
    SELECT id FROM elus WHERE LOWER(nom) = LOWER(:nom) AND LOWER(prenom) = LOWER(:prenom) LIMIT 1
");

$stmtDeactivate = $pdo->prepare("
    UPDATE elus SET actif = 0, fonction = CONCAT('Ancien(ne) ', fonction) WHERE id = :id AND actif = 1
");

$stmtCloseMandats = $pdo->prepare("
    UPDATE mandats SET date_fin = CURDATE() WHERE elu_id = :id AND date_fin IS NULL AND titre LIKE 'Maire%'
");

$stmtCreateElu = $pdo->prepare("
    INSERT INTO elus (nom, prenom, slug, fonction, departement, sexe, actif, type_mandat, source_api)
    VALUES (:nom, :prenom, :slug, :fonction, :dept, :sexe, 1, 'maire', 'la-mairie.com')
");

$stmtCreateMandat = $pdo->prepare("
    INSERT INTO mandats (elu_id, titre, date_debut, institution) VALUES (:elu_id, :titre, CURDATE(), 'Mairie')
");

$stmtUpdateContact = $pdo->prepare("
    UPDATE elus SET telephone = :tel, email = :email, adresse = :adresse, url_fiche = :url, actif = 1,
    fonction = :fonction WHERE id = :id
");

foreach ($maires as $i => $maire) {
    $idx = $i + 1;

    $commune = '';
    if (preg_match('/Maire\s*(?:—|-)\s*(.+)$/i', $maire['fonction'], $m)) {
        $commune = trim(explode('/', $m[1])[0]);
    }
    if (!$commune) { $skipped++; continue; }

    $slug = slugify($commune);
    $dept = strtolower($maire['departement']);

    // Fetch la page
    $urls = [];
    if ($dept) $urls[] = "https://www.la-mairie.com/{$slug}-{$dept}";
    $urls[] = "https://www.la-mairie.com/$slug";

    $html = null;
    $usedUrl = '';
    foreach ($urls as $url) {
        $html = fetchPage($url);
        if ($html) { $usedUrl = $url; break; }
        usleep(200000);
    }
    if (!$html) { $skipped++; usleep(400000); continue; }

    $info = extractFullInfo($html);
    if (!$info['maire_nom']) { $skipped++; usleep(400000); continue; }

    // Vérifier si c'est le même maire
    $nomBdd = normName($maire['nom']);
    $nomSite = normName($info['maire_nom']);
    if (strpos($nomSite, $nomBdd) !== false || strpos($nomBdd, $nomSite) !== false) {
        // Même maire → juste ajouter le contact
        if ($info['tel'] || $info['email']) {
            if (!$dryRun) {
                $stmtUpdateContact->execute([
                    ':tel' => $info['tel'], ':email' => $info['email'],
                    ':adresse' => $info['adresse'], ':url' => $info['site'] ?: $usedUrl,
                    ':fonction' => $maire['fonction'], ':id' => $maire['id'],
                ]);
            }
            $updatedExisting++;
        }
        usleep(400000);
        continue;
    }

    // MISMATCH — maire différent
    echo "[$idx] CHANGEMENT $commune: {$maire['prenom']} {$maire['nom']} → {$info['maire_prenom']} {$info['maire_nom']}\n";

    if ($dryRun) {
        $fixedOld++;
        usleep(400000);
        continue;
    }

    // 1. Désactiver l'ancien maire
    $stmtDeactivate->execute([':id' => $maire['id']]);
    $stmtCloseMandats->execute([':id' => $maire['id']]);
    $fixedOld++;

    // 2. Chercher le nouveau maire dans la BDD
    $stmtFindElu->execute([':nom' => $info['maire_nom'], ':prenom' => $info['maire_prenom']]);
    $newEluId = $stmtFindElu->fetchColumn();

    if ($newEluId) {
        // Existe déjà → mettre à jour contact + fonction
        $stmtUpdateContact->execute([
            ':tel' => $info['tel'], ':email' => $info['email'],
            ':adresse' => $info['adresse'], ':url' => $info['site'] ?: $usedUrl,
            ':fonction' => "Maire — $commune", ':id' => $newEluId,
        ]);
        // Créer mandat si pas déjà
        $stmtCreateMandat->execute([':elu_id' => $newEluId, ':titre' => "Maire — $commune"]);
        $updatedExisting++;
        echo "  → Existant mis à jour (id=$newEluId)\n";
    } else {
        // Créer le nouveau maire
        $newSlug = slugify($info['maire_prenom'] . ' ' . $info['maire_nom']);
        $stmtCreateElu->execute([
            ':nom' => $info['maire_nom'], ':prenom' => $info['maire_prenom'],
            ':slug' => $newSlug, ':fonction' => "Maire — $commune",
            ':dept' => $maire['departement'], ':sexe' => $info['sexe'] ?: null,
        ]);
        $newEluId = $pdo->lastInsertId();
        // Mandat
        $stmtCreateMandat->execute([':elu_id' => $newEluId, ':titre' => "Maire — $commune"]);
        // Contact
        $stmtUpdateContact->execute([
            ':tel' => $info['tel'], ':email' => $info['email'],
            ':adresse' => $info['adresse'], ':url' => $info['site'] ?: $usedUrl,
            ':fonction' => "Maire — $commune", ':id' => $newEluId,
        ]);
        $createdNew++;
        echo "  → Nouveau maire créé (id=$newEluId)\n";
    }

    usleep(400000);

    if ($idx % 100 === 0) {
        echo "\n--- Progress: $idx/" . count($maires) . " | Désactivés: $fixedOld | Créés: $createdNew | MàJ: $updatedExisting ---\n\n";
    }
}

echo "\n=== BILAN ===\n";
echo "Vérifiés       : " . count($maires) . "\n";
echo "Anciens maires : $fixedOld (désactivés)\n";
echo "Nouveaux créés : $createdNew\n";
echo "Existants MàJ  : $updatedExisting\n";
echo "Skippés        : $skipped\n";
echo "=== FIN ===\n";
