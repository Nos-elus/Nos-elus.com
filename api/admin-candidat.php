<?php
/**
 * Gestion des candidats — CLI uniquement
 *
 * Usage :
 *   php admin-candidat.php --list
 *   php admin-candidat.php --add --nom=Dupont --prenom=Jean --parti="LREM" \
 *       --election="Législatives 2027 - Haute-Garonne (3e circ.)" [--slug=jean-dupont]
 *   php admin-candidat.php --promote --slug=jean-dupont [--type_mandat=depute]
 *   php admin-candidat.php --remove --slug=jean-dupont
 *   php admin-candidat.php --set-election --slug=jean-dupont --election="Nouveau libellé"
 *
 * Règles :
 * - is_candidat=1 est protégé des imports (source_api='candidat' OU is_candidat=1)
 * - --promote : passe is_candidat=0 + actif=1 + type_mandat
 * - --remove  : passe is_candidat=0 sans toucher aux votes
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accès CLI uniquement.');
}

require_once __DIR__ . '/config.php';

function slug(string $prenom, string $nom): string {
    $s = mb_strtolower(trim("$prenom-$nom"), 'UTF-8');
    $tr = ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
           'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $s = strtr($s, $tr);
    $s = preg_replace('/[^a-z0-9\-]/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

function logLine(string $s): void { echo '[' . date('H:i:s') . '] ' . $s . PHP_EOL; }

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
        $args[$k] = $v;
    }
}

// ── LIST ──────────────────────────────────────────────────────────────────────
if (isset($args['list'])) {
    $rows = $pdo->query("SELECT id, prenom, nom, slug, parti, election_cible, actif, created_at FROM elus WHERE is_candidat=1 ORDER BY nom, prenom")->fetchAll();
    if (!$rows) { logLine('Aucun candidat enregistré.'); exit; }
    logLine(count($rows) . ' candidat(s) :');
    foreach ($rows as $r) {
        printf("  [%d] %-30s %-20s %s\n",
            $r['id'],
            "{$r['prenom']} {$r['nom']} ({$r['slug']})",
            $r['parti'] ?: '(sans parti)',
            $r['election_cible'] ?: '(élection non précisée)'
        );
    }
    exit;
}

// ── ADD ───────────────────────────────────────────────────────────────────────
if (isset($args['add'])) {
    $nom    = $args['nom']      ?? null;
    $prenom = $args['prenom']   ?? null;
    $parti  = $args['parti']    ?? '';
    $election = $args['election'] ?? '';
    if (!$nom || !$prenom) {
        logLine('Usage : --add --nom=X --prenom=Y --election="..." [--parti=X --slug=X]');
        exit(1);
    }
    $s = $args['slug'] ?? slug($prenom, $nom);
    $exist = $pdo->prepare('SELECT id, is_candidat FROM elus WHERE slug=:s OR (LOWER(nom)=LOWER(:n) AND LOWER(prenom)=LOWER(:p))');
    $exist->execute([':s' => $s, ':n' => $nom, ':p' => $prenom]);
    $found = $exist->fetch();
    if ($found) {
        logLine("Élu existant trouvé (id={$found['id']}, is_candidat={$found['is_candidat']}).");
        logLine("Utilisez --set-election --slug=$s --election=\"...\" pour mettre à jour.");
        exit(1);
    }
    $ins = $pdo->prepare(
        'INSERT INTO elus (nom, prenom, slug, parti, fonction, actif, is_candidat, election_cible, source_api, type_mandat)
         VALUES (:nom, :prenom, :slug, :parti, :fn, 0, 1, :election, "candidat", "candidat")'
    );
    $ins->execute([
        ':nom' => $nom, ':prenom' => $prenom, ':slug' => $s,
        ':parti' => $parti,
        ':fn' => 'Candidat' . ($election ? " — $election" : ''),
        ':election' => $election,
    ]);
    logLine("✓ Candidat ajouté : $prenom $nom (slug: $s, id: " . $pdo->lastInsertId() . ')');
    exit;
}

// ── PROMOTE (élu après l'élection) ────────────────────────────────────────────
if (isset($args['promote'])) {
    $s  = $args['slug']        ?? null;
    $tm = $args['type_mandat'] ?? null;
    if (!$s) { logLine('Usage : --promote --slug=X [--type_mandat=depute]'); exit(1); }
    $up = $pdo->prepare('UPDATE elus SET is_candidat=0, actif=1' . ($tm ? ', type_mandat=:tm' : '') . ' WHERE slug=:s AND is_candidat=1');
    $params = [':s' => $s];
    if ($tm) $params[':tm'] = $tm;
    $up->execute($params);
    if ($up->rowCount()) logLine("✓ Promu : $s" . ($tm ? " (type_mandat=$tm)" : ''));
    else logLine("Aucune ligne modifiée — slug introuvable ou déjà non-candidat.");
    exit;
}

// ── REMOVE (retirer le statut candidat sans supprimer) ────────────────────────
if (isset($args['remove'])) {
    $s = $args['slug'] ?? null;
    if (!$s) { logLine('Usage : --remove --slug=X'); exit(1); }
    $up = $pdo->prepare('UPDATE elus SET is_candidat=0, election_cible=NULL WHERE slug=:s');
    $up->execute([':s' => $s]);
    logLine($up->rowCount() ? "✓ Statut candidat retiré : $s" : 'Aucune ligne modifiée.');
    exit;
}

// ── SET-ELECTION (modifier le libellé de l'élection) ─────────────────────────
if (isset($args['set-election'])) {
    $s = $args['slug']     ?? null;
    $e = $args['election'] ?? null;
    if (!$s || !$e) { logLine('Usage : --set-election --slug=X --election="Nouveau libellé"'); exit(1); }
    $up = $pdo->prepare('UPDATE elus SET election_cible=:e WHERE slug=:s');
    $up->execute([':e' => $e, ':s' => $s]);
    logLine($up->rowCount() ? "✓ Élection mise à jour : $s → $e" : 'Aucune ligne modifiée.');
    exit;
}

echo <<<HELP
admin-candidat.php — gestion des candidats

  --list
  --add --nom=X --prenom=Y --election="Législatives 2027 - Loire (1e)" [--parti=X --slug=X]
  --promote --slug=X [--type_mandat=depute]      (après élection → passe actif=1)
  --remove --slug=X                              (retire is_candidat sans supprimer)
  --set-election --slug=X --election="Libellé"

HELP;
