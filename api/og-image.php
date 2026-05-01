<?php
/**
 * Génère une image Open Graph (PNG 1200x630) pour un élu.
 * URL : /api/og-image.php?slug=emmanuel-macron
 */
require_once __DIR__ . '/config.php';

$slug = getStringParam('slug', 200);
$id = getIntParam('id');
if (!$slug && !$id) { http_response_code(400); exit; }

// Cache 1 jour
$cacheKey = 'og_' . ($slug ?: $id);
$cachePath = __DIR__ . '/cache/og/' . md5($cacheKey) . '.png';
if (file_exists($cachePath) && filemtime($cachePath) > time() - 86400) {
    header('Content-Type: image/png');
    header_remove('Cache-Control');
    header_remove('Expires');
    header('Cache-Control: public, max-age=3600');
    readfile($cachePath);
    exit;
}

// Fetch élu
$where = $slug ? "slug = :val" : "id = :val";
$stmt = $pdo->prepare("SELECT id, nom, prenom, fonction, parti, photo_url, date_naissance, couleur FROM elus WHERE $where LIMIT 1");
$stmt->execute([':val' => $slug ?: $id]);
$elu = $stmt->fetch();
if (!$elu) { http_response_code(404); exit; }

// Stats
$stmtM = $pdo->prepare("SELECT COUNT(*) FROM mandats WHERE elu_id = :id");
$stmtM->execute([':id' => $elu['id']]);
$nbMandats = (int) $stmtM->fetchColumn();

$stmtA = $pdo->prepare("SELECT COUNT(*) FROM affaires WHERE elu_id = :id AND statut != 'clean'");
$stmtA->execute([':id' => $elu['id']]);
$nbAffaires = (int) $stmtA->fetchColumn();

require_once __DIR__ . '/calcul-cout.php';
$stmtC = $pdo->prepare("SELECT titre, date_debut, date_fin FROM mandats WHERE elu_id = :id");
$stmtC->execute([':id' => $elu['id']]);
$cout = calculerCoutCarriere($stmtC->fetchAll());

$age = null;
if ($elu['date_naissance'] && $elu['date_naissance'] > '1900-01-01') {
    $age = (int) (new DateTime($elu['date_naissance']))->diff(new DateTime())->y;
}

// Patrimoine
$stmtP = $pdo->prepare("SELECT patrimoine_detail FROM elus WHERE id = :id");
$stmtP->execute([':id' => $elu['id']]);
$pdRaw = $stmtP->fetchColumn();
$pd = $pdRaw ? json_decode($pdRaw, true) : null;
$fortune = $pd ? ($pd['fortune_estimee'] ?? $pd['total'] ?? 0) : 0;

// ── Fonts ──
$fontBold = '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf';
$fontReg = '/usr/share/fonts/dejavu/DejaVuSans.ttf';
if (!file_exists($fontBold)) {
    // Fallback paths
    foreach (['/usr/share/fonts/truetype/dejavu/', '/usr/share/fonts/'] as $dir) {
        if (file_exists($dir . 'DejaVuSans-Bold.ttf')) { $fontBold = $dir . 'DejaVuSans-Bold.ttf'; $fontReg = $dir . 'DejaVuSans.ttf'; break; }
    }
}

// ── Image 1200x630 ──
$W = 1200; $H = 630;
$img = imagecreatetruecolor($W, $H);

$bgDark  = imagecolorallocate($img, 15, 15, 26);
$bgCard  = imagecolorallocate($img, 26, 26, 46);
$gold    = imagecolorallocate($img, 253, 203, 110);
$white   = imagecolorallocate($img, 238, 238, 238);
$muted   = imagecolorallocate($img, 160, 174, 192);
$dim     = imagecolorallocate($img, 131, 149, 167);
$red     = imagecolorallocate($img, 255, 107, 107);
$green   = imagecolorallocate($img, 0, 184, 148);
$purple  = imagecolorallocate($img, 108, 92, 231);
$border  = imagecolorallocate($img, 42, 42, 66);

// Fond
imagefilledrectangle($img, 0, 0, $W, $H, $bgDark);

// Bordure dorée
for ($i = 0; $i < 3; $i++) imagerectangle($img, $i, $i, $W - 1 - $i, $H - 1 - $i, $gold);

// Ligne dorée fine haut/bas
imagefilledrectangle($img, 3, 3, $W - 4, 5, $gold);
imagefilledrectangle($img, 3, $H - 6, $W - 4, $H - 4, $gold);

// ── Photo (cercle) ──
$photoCx = 180; $photoCy = 280; $photoR = 100;

// Dessiner cercle fond
imagefilledellipse($img, $photoCx, $photoCy, $photoR * 2, $photoR * 2, $border);

$photoLoaded = false;
if ($elu['photo_url']) {
    $photoPath = dirname(__DIR__) . '/' . ltrim($elu['photo_url'], '/');
    if (file_exists($photoPath)) {
        $ext = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
        $photo = null;
        if (in_array($ext, ['jpg', 'jpeg'])) $photo = @imagecreatefromjpeg($photoPath);
        elseif ($ext === 'png') $photo = @imagecreatefrompng($photoPath);
        if ($photo) {
            $pw = imagesx($photo); $ph = imagesy($photo);
            $size = min($pw, $ph);
            $sx = ($pw - $size) / 2; $sy = ($ph - $size) / 2;
            // Crop circulaire : dessiner dans un masque
            $mask = imagecreatetruecolor($photoR * 2, $photoR * 2);
            $black = imagecolorallocate($mask, 0, 0, 0);
            imagefill($mask, 0, 0, $black);
            imagecolortransparent($mask, $black);
            $maskWhite = imagecolorallocate($mask, 255, 255, 255);
            imagefilledellipse($mask, $photoR, $photoR, $photoR * 2 - 2, $photoR * 2 - 2, $maskWhite);
            // Dessiner la photo redimensionnée
            $resized = imagecreatetruecolor($photoR * 2, $photoR * 2);
            imagecopyresampled($resized, $photo, 0, 0, (int)$sx, (int)$sy, $photoR * 2, $photoR * 2, $size, $size);
            // Appliquer masque pixel par pixel
            for ($y = 0; $y < $photoR * 2; $y++) {
                for ($x = 0; $x < $photoR * 2; $x++) {
                    $mc = imagecolorat($mask, $x, $y);
                    if ($mc != $black) {
                        $pc = imagecolorat($resized, $x, $y);
                        imagesetpixel($img, $photoCx - $photoR + $x, $photoCy - $photoR + $y, $pc);
                    }
                }
            }
            imagedestroy($photo);
            imagedestroy($mask);
            imagedestroy($resized);
            $photoLoaded = true;
        }
    }
}

// Initiales si pas de photo
if (!$photoLoaded && file_exists($fontBold)) {
    $initials = mb_strtoupper(
        ($elu['prenom'] ? mb_substr($elu['prenom'], 0, 1, 'UTF-8') : '') .
        ($elu['nom'] ? mb_substr($elu['nom'], 0, 1, 'UTF-8') : ''),
        'UTF-8'
    );
    $bb = imagettfbbox(50, 0, $fontBold, $initials);
    imagettftext($img, 50, 0, $photoCx - ($bb[2] - $bb[0]) / 2, $photoCy + 18, $gold, $fontBold, $initials);
}

// Bordure cercle
$eluColor = $elu['couleur'] ? imagecolorallocate($img, ...array_map('hexdec', str_split(ltrim($elu['couleur'], '#'), 2))) : $gold;
imageellipse($img, $photoCx, $photoCy, $photoR * 2 + 4, $photoR * 2 + 4, $eluColor);
imageellipse($img, $photoCx, $photoCy, $photoR * 2 + 5, $photoR * 2 + 5, $eluColor);

// ── Textes (tailles augmentées) ──
$tx = 340;

// Nom — gros titre
$nom = trim(($elu['prenom'] ?: '') . ' ' . $elu['nom']);
imagettftext($img, 46, 0, $tx, 110, $white, $fontBold, mb_substr($nom, 0, 24, 'UTF-8'));

// Age
$yPos = 150;
if ($age) {
    imagettftext($img, 22, 0, $tx, $yPos, $dim, $fontReg, "$age ans");
    $yPos += 38;
}

// Fonction
$fn = mb_substr($elu['fonction'] ?: '', 0, 45, 'UTF-8');
imagettftext($img, 22, 0, $tx, $yPos, $muted, $fontReg, $fn);
$yPos += 38;

// Parti
if ($elu['parti']) {
    imagettftext($img, 20, 0, $tx, $yPos, $gold, $fontBold, mb_substr($elu['parti'], 0, 35, 'UTF-8'));
    $yPos += 40;
} else {
    $yPos += 10;
}

// ── Stats — une seule ligne de grosses boxes ──
$allStats = [];
$allStats[] = ['label' => 'Mandats', 'value' => (string)$nbMandats, 'color' => $purple];
$allStats[] = ['label' => 'Affaires', 'value' => (string)$nbAffaires, 'color' => $nbAffaires > 0 ? $red : $green];
if ($cout['total'] > 0) {
    $cv = $cout['total'] >= 1e6 ? round($cout['total'] / 1e6, 1) . 'M' : round($cout['total'] / 1e3) . 'k';
    $allStats[] = ['label' => 'Cout', 'value' => $cv, 'color' => $gold];
}
if ($fortune > 0) {
    $fv = $fortune >= 1e6 ? round($fortune / 1e6, 1) . 'M' : round($fortune / 1e3) . 'k';
    $allStats[] = ['label' => 'Fortune', 'value' => $fv, 'color' => $gold];
}

$nbStats = min(count($allStats), 5);
$gap = 12;
$totalW = $W - $tx - 40;
$boxW = ($totalW - ($nbStats - 1) * $gap) / $nbStats;
$boxH = 80;
$statsY = max($yPos, 290);

foreach (array_slice($allStats, 0, $nbStats) as $i => $s) {
    $bx = $tx + $i * ($boxW + $gap);
    imagefilledrectangle($img, (int)$bx, $statsY, (int)($bx + $boxW), $statsY + $boxH, $bgCard);
    imagerectangle($img, (int)$bx, $statsY, (int)($bx + $boxW), $statsY + $boxH, $border);
    // Valeur centrée
    $bb = imagettfbbox(30, 0, $fontBold, $s['value']);
    $vw = $bb[2] - $bb[0];
    imagettftext($img, 30, 0, (int)($bx + ($boxW - $vw) / 2), $statsY + 40, $s['color'], $fontBold, $s['value']);
    // Label centré
    $bb2 = imagettfbbox(12, 0, $fontReg, $s['label']);
    $lw = $bb2[2] - $bb2[0];
    imagettftext($img, 12, 0, (int)($bx + ($boxW - $lw) / 2), $statsY + 65, $dim, $fontReg, $s['label']);
}

// Badge casier — gros texte
$badgeY = $statsY + $boxH + 30;
if ($nbAffaires === 0) {
    imagettftext($img, 22, 0, $tx, $badgeY, $green, $fontBold, 'Casier vierge');
} else {
    $txt = "$nbAffaires affaire" . ($nbAffaires > 1 ? 's' : '') . " judiciaire" . ($nbAffaires > 1 ? 's' : '');
    imagettftext($img, 22, 0, $tx, $badgeY, $red, $fontBold, $txt);
}

// ── Branding — plus gros ──
imagettftext($img, 36, 0, $W - 310, $H - 40, $gold, $fontBold, 'nos-elus.fr');
imagettftext($img, 13, 0, $W - 410, $H - 65, $dim, $fontReg, 'Transparence politique citoyenne');

// ── Save & output ──
@mkdir(dirname($cachePath), 0755, true);
imagepng($img, $cachePath, 6);
header('Content-Type: image/png');
header_remove('Cache-Control');
header_remove('Expires');
header('Cache-Control: public, max-age=3600');
imagepng($img, null, 6);
imagedestroy($img);
