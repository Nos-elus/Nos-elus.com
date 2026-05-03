<?php
/**
 * Page HTML 1200x630 pour la prévisualisation OG (Twitter/Discord/LinkedIn).
 * Server-side, pas de SPA. Reproduit la carte centrale de la fiche élu.
 * URL : /api/og-card.php?slug=<slug>
 */
require_once __DIR__ . '/config.php';

$slug = getStringParam('slug', 200);
if (!$slug || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
    http_response_code(400); exit('slug invalide');
}

$stmt = $pdo->prepare("SELECT id, nom, prenom, fonction, parti, photo_url, couleur, date_naissance, alias, actif FROM elus WHERE slug = :s LIMIT 1");
$stmt->execute([':s' => $slug]);
$elu = $stmt->fetch();
if (!$elu) { http_response_code(404); exit('Élu non trouvé'); }

$stmtM = $pdo->prepare("SELECT COUNT(*) FROM mandats WHERE elu_id = :id");
$stmtM->execute([':id' => $elu['id']]);
$nbMandats = (int) $stmtM->fetchColumn();

$stmtA = $pdo->prepare("SELECT COUNT(*) FROM affaires WHERE elu_id = :id AND statut != 'clean'");
$stmtA->execute([':id' => $elu['id']]);
$nbAffaires = (int) $stmtA->fetchColumn();

require_once __DIR__ . '/calcul-cout.php';
$stmtC = $pdo->prepare("SELECT titre, date_debut, date_fin FROM mandats WHERE elu_id = :id");
$stmtC->execute([':id' => $elu['id']]);
$coutResult = calculerCoutCarriere($stmtC->fetchAll());
$cout = (int) ($coutResult['total'] ?? 0);

$stmtP = $pdo->prepare("SELECT patrimoine_detail FROM elus WHERE id = :id");
$stmtP->execute([':id' => $elu['id']]);
$pdRaw = $stmtP->fetchColumn();
$pd = $pdRaw ? json_decode($pdRaw, true) : null;
$fortune = $pd ? ((int)($pd['fortune_estimee'] ?? $pd['total'] ?? 0)) : 0;

// Salaire mensuel net approximatif (brut × 0.7) — basé sur le mandat actif le plus rémunéré
$salaireNet = 0;
if (isset($coutResult['detail']) && is_array($coutResult['detail'])) {
    $maxBrutMensuel = 0;
    foreach ($coutResult['detail'] as $info) {
        if (isset($info['mois']) && $info['mois'] > 0 && isset($info['brut'])) {
            $b = $info['brut'] / $info['mois'];
            if ($b > $maxBrutMensuel) $maxBrutMensuel = $b;
        }
    }
    $salaireNet = (int) round($maxBrutMensuel * 0.7);
}

$age = null;
if ($elu['date_naissance'] && $elu['date_naissance'] > '1900-01-01') {
    $age = (int)(new DateTime($elu['date_naissance']))->diff(new DateTime())->y;
}

$aliases = [];
if ($elu['alias']) {
    $d = json_decode($elu['alias'], true);
    if (is_array($d)) $aliases = $d;
}

$nom      = htmlspecialchars(strtoupper(trim(($elu['prenom'] ?: '') . ' ' . $elu['nom'])));
$fonction = htmlspecialchars($elu['fonction'] ?: '');
$parti    = htmlspecialchars($elu['parti'] ?: '');
$couleur  = htmlspecialchars($elu['couleur'] ?: '#fdcb6e');
$alias    = $aliases ? htmlspecialchars($aliases[0]) : '';
$photoUrl = $elu['photo_url'] ? 'https://nos-elus.com' . htmlspecialchars($elu['photo_url']) : '';
$initials = htmlspecialchars(mb_substr($elu['prenom'] ?: '?', 0, 1) . mb_substr($elu['nom'] ?: '', 0, 1));

function fmtCompact(int $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000) return round($n / 1_000) . 'k';
    return (string)$n;
}
function fmtNumber(int $n): string { return number_format($n, 0, ',', ' '); }

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bangers&family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { width: 1200px; height: 630px; overflow: hidden; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; }
  body {
    background:
      radial-gradient(circle at 20% 0%, rgba(253,203,110,0.08), transparent 50%),
      linear-gradient(135deg, #0a0e1a 0%, #16213e 50%, #0d1525 100%);
    color: #fff; position: relative;
  }
  .logo {
    position: absolute; top: 22px; left: 32px;
    font-family: 'Bangers', 'Impact', sans-serif;
    font-size: 32px; color: #fdcb6e;
    text-shadow: 0 0 16px rgba(253,203,110,0.6);
    letter-spacing: 2px;
    display: flex; align-items: center; gap: 8px;
    z-index: 10;
  }
  .logo .dice { font-size: 28px; }
  .logo .ext { font-size: 13px; color: #cdd6e4; opacity: 0.85; letter-spacing: 1px; font-family: Inter, sans-serif; font-weight: 800; }
  .elu-badge {
    position: absolute; top: 26px; right: 38px;
    width: 64px; height: 64px; border-radius: 50%;
    background: radial-gradient(circle at 35% 30%, #ffd96e, #d97a1f);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 0 25px rgba(253,203,110,0.5);
    z-index: 10;
  }
  .elu-badge .cube { font-size: 26px; }
  .elu-badge .lbl {
    position: absolute; bottom: -18px; left: 50%; transform: translateX(-50%);
    font-size: 11px; font-weight: 900; color: #cdd6e4; letter-spacing: 1.5px;
  }
  .container {
    width: 100%; height: 100%;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 56px 32px 36px;
  }
  .photo {
    width: 150px; height: 150px; border-radius: 50%;
    background: linear-gradient(135deg, <?= $couleur ?>, <?= $couleur ?>aa);
    border: 4px solid #fdcb6e;
    box-shadow: 0 0 35px rgba(253,203,110,0.4);
    overflow: hidden; flex-shrink: 0; margin-bottom: 14px;
    display: flex; align-items: center; justify-content: center;
  }
  .photo img { width: 100%; height: 100%; object-fit: cover; }
  .photo .initials { font-family: 'Bangers', sans-serif; font-size: 56px; color: #fff; letter-spacing: 2px; }
  .nom {
    font-family: 'Bangers', 'Impact', sans-serif;
    font-size: 60px; color: #fff;
    line-height: 1; letter-spacing: 2.5px;
    margin-bottom: 8px;
    text-align: center;
    text-shadow: 0 2px 12px rgba(0,0,0,0.6);
  }
  .age { font-size: 13px; color: #a0aec0; margin-bottom: 4px; font-weight: 600; }
  .alias-badge {
    display: inline-block;
    font-size: 12px; color: #cdd6e4;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 99px; padding: 3px 14px;
    margin-bottom: 8px;
    font-style: italic;
  }
  .fonction { font-size: 16px; color: #cdd6e4; margin-bottom: 10px; font-weight: 500; }
  .parti-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 14px; font-weight: 800;
    color: #0a0e1a;
    background: linear-gradient(135deg, #fdcb6e, #e8941c);
    border-radius: 99px; padding: 7px 18px;
    margin-bottom: 16px;
    box-shadow: 0 2px 12px rgba(253,203,110,0.4);
  }
  .stats { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin-bottom: 12px; }
  .stat {
    background: rgba(20,30,50,0.7);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px; padding: 9px 16px;
    font-size: 15px; font-weight: 800;
    display: flex; align-items: center; gap: 7px;
  }
  .stat.cout     { color: #00b894; border-color: #00b89455; }
  .stat.affaires { color: #ff6b6b; border-color: #ff6b6b55; }
  .stat.mandats  { color: #6c5ce7; border-color: #6c5ce755; }
  .stat.fortune  { color: #fdcb6e; border-color: #fdcb6e55; }
  .salaire {
    background: rgba(0,184,148,0.18);
    border: 1px solid #00b89488;
    border-radius: 99px; padding: 8px 20px;
    font-size: 15px; font-weight: 800; color: #00b894;
    display: inline-flex; align-items: center; gap: 7px;
  }
</style>
</head><body>
<div class="logo">
  <span class="dice">🎰</span>
  <span>nos-elus</span>
  <span class="ext">.com</span>
</div>
<?php if ($elu['actif']): ?>
<div class="elu-badge"><span class="cube">📦</span><span class="lbl">ÉLU</span></div>
<?php endif; ?>
<div class="container">
  <div class="photo">
    <?php if ($photoUrl): ?><img src="<?= $photoUrl ?>" alt=""><?php else: ?><span class="initials"><?= $initials ?></span><?php endif; ?>
  </div>
  <div class="nom"><?= $nom ?></div>
  <?php if ($age !== null): ?><div class="age"><?= $age ?> ans</div><?php endif; ?>
  <?php if ($alias): ?><div class="alias-badge">aka <?= $alias ?></div><?php endif; ?>
  <?php if ($fonction): ?><div class="fonction"><?= $fonction ?></div><?php endif; ?>
  <?php if ($parti): ?><div class="parti-badge"><?= $parti ?> →</div><?php endif; ?>
  <div class="stats">
    <?php if ($cout > 0): ?><span class="stat cout">💸 <?= fmtCompact($cout) ?>€</span><?php endif; ?>
    <?php if ($nbAffaires > 0): ?><span class="stat affaires">🍩 <?= $nbAffaires ?> affaire<?= $nbAffaires > 1 ? 's' : '' ?></span><?php endif; ?>
    <?php if ($nbMandats > 0): ?><span class="stat mandats">🗂️ <?= $nbMandats ?> mandat<?= $nbMandats > 1 ? 's' : '' ?></span><?php endif; ?>
    <?php if ($fortune > 0): ?><span class="stat fortune">🔓 <?= fmtCompact($fortune) ?>€</span><?php endif; ?>
  </div>
  <?php if ($salaireNet > 0): ?><div class="salaire">🔓 <?= fmtNumber($salaireNet) ?> €/mois</div><?php endif; ?>
</div>
</body></html>
