#!/usr/bin/env php
<?php
/**
 * Enrichit les photos des députés et sénateurs restants via les sites officiels.
 *
 * Sources :
 *   - Députés  : https://www.nosdeputes.fr/{slug}/photo/200
 *   - Sénateurs : https://www.nossenateurs.fr/{slug}/photo/200
 *
 * Stratégie de slug (plusieurs variantes testées) :
 *   1. prenom-nom (standard)
 *   2. nom-prenom (inversé)
 *   3. prenom composé simplifié (marie-jose -> marie-jose)
 *   4. premier prénom seulement
 *   5. sans particule (de, du, le, la, d')
 *
 * Usage :
 *   php scripts/enrich-photos-officielles.php
 *   php scripts/enrich-photos-officielles.php --dry-run
 *   php scripts/enrich-photos-officielles.php --deputes-only
 *   php scripts/enrich-photos-officielles.php --senateurs-only
 */

$dryRun        = in_array('--dry-run', $argv);
$deputesOnly   = in_array('--deputes-only', $argv);
$senateursOnly = in_array('--senateurs-only', $argv);

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
    fwrite(STDERR, "Erreur BDD : " . $e->getMessage() . "\n");
    exit(1);
}

echo "=== Enrichissement photos officielles (députés + sénateurs) ===\n";
echo "Mode : " . ($dryRun ? "DRY-RUN (aucune écriture)" : "EXECUTION") . "\n\n";

$updateStmt = $pdo->prepare('UPDATE elus SET photo_url = :url WHERE id = :id');
$totalFound = 0;

// ── 1. Députés via NosDéputés.fr ──
if (!$senateursOnly) {
    $totalFound += enrichirParType($pdo, $updateStmt, 'depute', 'https://www.nosdeputes.fr', $dryRun);
}

// ── 2. Sénateurs via NosSénateurs.fr ──
if (!$deputesOnly) {
    $totalFound += enrichirParType($pdo, $updateStmt, 'senateur', 'https://www.nossenateurs.fr', $dryRun);
}

echo "=== Terminé : $totalFound photos enrichies ===\n";

// ════════════════════════════════════════════════════════════════
// Fonctions
// ════════════════════════════════════════════════════════════════

/**
 * Enrichit un type de parlementaire (depute ou senateur).
 */
function enrichirParType(PDO $pdo, PDOStatement $updateStmt, string $typeMandat, string $baseUrl, bool $dryRun): int
{
    $label = $typeMandat === 'depute' ? 'Députés' : 'Sénateurs';
    $site  = $typeMandat === 'depute' ? 'NosDéputés.fr' : 'NosSénateurs.fr';

    echo "--- $label ($site) ---\n";

    $stmt = $pdo->query("
        SELECT id, nom, prenom, slug
        FROM elus
        WHERE type_mandat = " . $pdo->quote($typeMandat) . "
          AND (photo_url IS NULL OR photo_url = '')
          AND source_api != 'manual'
    ");
    $elus = $stmt->fetchAll();
    $total = count($elus);
    echo "$total $label sans photo\n";

    if ($total === 0) {
        echo "  Rien a traiter.\n\n";
        return 0;
    }

    $found = 0;
    $failed = [];

    foreach ($elus as $i => $elu) {
        $prenom = trim($elu['prenom'] ?? '');
        $nom    = trim($elu['nom']);
        $num    = $i + 1;

        // Générer les variantes de slug à tester
        $slugs = genererVariantesSlugs($prenom, $nom);

        $matched = false;
        foreach ($slugs as $slug) {
            $url = "{$baseUrl}/{$slug}/photo/200";

            if (checkImageUrl($url)) {
                if (!$dryRun) {
                    $updateStmt->execute([':url' => $url, ':id' => $elu['id']]);
                }
                $found++;
                $matched = true;
                echo "  [$num/$total] [OK] $prenom $nom -> $slug\n";
                break;
            }
            usleep(100000); // 100ms entre chaque tentative
        }

        if (!$matched) {
            $failed[] = "$prenom $nom (id={$elu['id']})";
            if ($total <= 100) {
                echo "  [$num/$total] [--] $prenom $nom (aucun slug ne marche)\n";
            }
        }

        // Progression tous les 50
        if ($num % 50 === 0) {
            echo "  ... $num/$total traités ($found trouvés)\n";
        }
    }

    echo "$label : $found/$total photos trouvées\n";

    // Afficher les échecs si raisonnable
    $nbFailed = count($failed);
    if ($nbFailed > 0 && $nbFailed <= 80) {
        echo "  Restants sans photo ($nbFailed) :\n";
        foreach ($failed as $f) {
            echo "    - $f\n";
        }
    } elseif ($nbFailed > 80) {
        echo "  $nbFailed restants sans photo (liste trop longue, non affichée)\n";
    }
    echo "\n";

    return $found;
}

/**
 * Génère plusieurs variantes de slug pour un élu.
 * Retourne un tableau de slugs uniques à tester dans l'ordre.
 */
function genererVariantesSlugs(string $prenom, string $nom): array
{
    $slugs = [];

    // 1. Standard : prenom-nom
    $slugs[] = slugify("$prenom $nom");

    // 2. Inversé : nom-prenom
    $slugs[] = slugify("$nom $prenom");

    // 3. Premier prénom seulement (si prénom composé)
    if (preg_match('/[\s\-]/', $prenom)) {
        $premierPrenom = preg_split('/[\s\-]+/', $prenom)[0];
        $slugs[] = slugify("$premierPrenom $nom");
        $slugs[] = slugify("$nom $premierPrenom");
    }

    // 4. Sans particule dans le nom (de, du, le, la, d', des)
    $nomSansParticule = preg_replace('/^(d\'|de |du |le |la |des |l\')/i', '', $nom);
    $nomSansParticule = trim($nomSansParticule);
    if (slugify($nomSansParticule) !== slugify($nom)) {
        $slugs[] = slugify("$prenom $nomSansParticule");
        $slugs[] = slugify("$nomSansParticule $prenom");
    }

    // 5. Nom composé avec tiret -> sans tiret (Le Pen -> le-pen, mais aussi lepen)
    // et inversement : nom sans tiret -> avec tiret entre les parties
    $nomParts = preg_split('/[\s\-]+/', $nom);
    if (count($nomParts) > 1) {
        // Tout collé
        $nomColle = implode('', $nomParts);
        $slugs[] = slugify("$prenom $nomColle");
    }

    // 6. Prénom composé avec trait d'union préservé tel quel
    // (marie-jose restera marie-jose via slugify, pas besoin de variante spéciale)

    // 7. Variante avec accent handling différent
    // Tester le slug BDD s'il existe et est différent
    // (déjà couvert par les variantes ci-dessus)

    // Dédupliquer tout en préservant l'ordre
    return array_values(array_unique($slugs));
}

/**
 * Vérifie qu'une URL renvoie une image (HTTP 200 + Content-Type image/*).
 */
function checkImageUrl(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'nos-elus.fr/1.0 (enrichissement photos)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);

    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
    $curlError   = curl_errno($ch);
    curl_close($ch);

    if ($curlError !== 0) {
        return false;
    }

    return $httpCode === 200 && stripos($contentType, 'image') !== false;
}

/**
 * Transforme un texte en slug URL-safe (minuscule, sans accents, tirets).
 */
function slugify(string $text): string
{
    // Translitération des accents
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    } else {
        $text = mb_strtolower($text, 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    }

    // Remplacer tout ce qui n'est pas alphanum par des tirets
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);

    return trim($text, '-');
}
