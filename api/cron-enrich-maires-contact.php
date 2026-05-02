<?php
/**
 * Enrichissement contact des maires 2026 via API service-public.fr
 * Traite 200 maires par exécution — à lancer via cron toutes les 10 min
 * jusqu'à épuisement (10 567 maires → ~9h à 200/10min)
 *
 * Champs remplis : email, telephone, adresse, url_fiche (site mairie)
 * Source de vérité : https://api-lannuaire.service-public.fr
 *
 * Lancement manuel : php cron-enrich-maires-contact.php [--limit=500]
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--limit=')) $limit = (int)substr($a, 8);
}

function emcLog(string $s): void { echo '[' . date('H:i:s') . '] ' . $s . PHP_EOL; }

// ── Récupérer les maires sans contact ──────────────────────────────────────

$stmt = $pdo->prepare("
    SELECT e.id, e.prenom, e.nom, e.departement, m.titre
    FROM elus e
    JOIN mandats m ON m.elu_id = e.id AND m.date_fin IS NULL
    WHERE e.type_mandat = 'maire'
      AND e.actif = 1
      AND e.source_api = 'rne_2026'
      AND (e.email IS NULL OR e.email = '')
      AND (e.telephone IS NULL OR e.telephone = '')
    ORDER BY e.id
    LIMIT :lim
");
$stmt->execute([':lim' => $limit]);
$maires = $stmt->fetchAll();

emcLog(count($maires) . " maires à enrichir (limit=$limit)");
if (!$maires) { emcLog("Rien à faire."); exit(0); }

// ── Extraire commune depuis le titre du mandat ─────────────────────────────

function extractCommune(string $titre): string {
    // "Maire — Nomexy" ou "Maire - Nomexy"
    if (preg_match('/[—\-]\s+(.+)$/u', $titre, $m)) {
        return trim($m[1]);
    }
    return '';
}

// ── Requête API service-public.fr ──────────────────────────────────────────

function fetchServicePublic(string $commune, string $dept): ?array {
    // Nettoyer le nom pour la recherche
    $q = preg_replace("/['\x{2019}\-]/u", '%', $commune); // L'abergement → L%abergement
    $q = trim($q);

    $url = 'https://api-lannuaire.service-public.fr/api/explore/v2.1/catalog/datasets/api-lannuaire-administration/records?'
         . http_build_query([
             'where'  => "pivot like \"mairie\" AND nom like \"Mairie%" . $q . "%\"",
             'limit'  => 5,
         ]);

    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: nos-elus.fr/1.0 (contact@nos-elus.fr)\r\n",
        'timeout' => 8,
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;

    $data = json_decode($json, true);
    $results = $data['results'] ?? [];
    if (empty($results)) return null;

    // Filtrer par département si plusieurs résultats
    if (count($results) > 1) {
        foreach ($results as $r) {
            $adresses = json_decode($r['adresse'] ?? '[]', true) ?: [];
            foreach ($adresses as $a) {
                $cp = $a['code_postal'] ?? '';
                if (str_starts_with($cp, str_pad($dept, 2, '0', STR_PAD_LEFT))) {
                    return $r;
                }
            }
        }
    }

    return $results[0];
}

// ── Extraire les champs utiles d'un résultat ───────────────────────────────

function parseResult(array $r): array {
    $email = $r['adresse_courriel'] ?? null;
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = null;

    $tel = null;
    $tels = json_decode($r['telephone'] ?? '[]', true) ?: [];
    if (!empty($tels[0]['valeur'])) $tel = trim($tels[0]['valeur']);

    $site = null;
    $sites = json_decode($r['site_internet'] ?? '[]', true) ?: [];
    foreach ($sites as $s) {
        $v = trim($s['valeur'] ?? '');
        if ($v && str_starts_with($v, 'http')) { $site = $v; break; }
    }

    $adresse = null;
    $adr = json_decode($r['adresse'] ?? '[]', true) ?: [];
    if (!empty($adr[0])) {
        $a = $adr[0];
        $parts = array_filter([
            trim($a['numero_voie'] ?? ''),
            trim($a['code_postal'] ?? '') . ' ' . trim($a['nom_commune'] ?? ''),
        ]);
        $adresse = implode(', ', $parts) ?: null;
    }

    // Politique éditoriale nos-elus : priorité service-public.gouv.fr (annuaire officiel),
    // fallback site mairie. Wikipedia interdit pour les maires (cron-enrich-ministers.php
    // est le seul autorisé à fallback wikipedia, et uniquement en dernier recours).
    $urlFiche = $r['url_service_public'] ?? null;
    if (!$urlFiche && $site) $urlFiche = $site;

    return compact('email', 'tel', 'site', 'adresse', 'urlFiche');
}

// ── UPDATE BDD ─────────────────────────────────────────────────────────────

$update = $pdo->prepare("
    UPDATE elus
    SET email     = :email,
        telephone = :tel,
        adresse   = :adr,
        url_fiche = :url,
        derniere_sync = NOW()
    WHERE id = :id
      AND source_api = 'rne_2026'
      AND (email IS NULL OR email = '')
      AND (telephone IS NULL OR telephone = '')
");

// ── Boucle principale ──────────────────────────────────────────────────────

$ok = 0; $skip = 0; $err = 0;

foreach ($maires as $maire) {
    $commune = extractCommune($maire['titre']);
    if (!$commune) { $skip++; continue; }

    $r = fetchServicePublic($commune, $maire['departement']);
    usleep(300000); // 300ms entre requêtes ≈ 3 req/s max

    if (!$r) {
        emcLog("  ✗ Non trouvé : {$maire['prenom']} {$maire['nom']} ({$commune}, dept {$maire['departement']})");
        $err++;
        continue;
    }

    $fields = parseResult($r);

    if (!$fields['email'] && !$fields['tel'] && !$fields['adresse']) {
        $skip++;
        continue;
    }

    $update->execute([
        ':email' => $fields['email'] ?? '',
        ':tel'   => $fields['tel']   ?? '',
        ':adr'   => $fields['adresse'] ?? '',
        ':url'   => $fields['urlFiche'] ?? '',
        ':id'    => $maire['id'],
    ]);

    $ok++;
    if ($ok % 20 === 0) {
        emcLog("  ✓ $ok enrichis ({$maire['prenom']} {$maire['nom']} — {$commune})");
    }
}

emcLog("Terminé : $ok enrichis, $skip sans données, $err non trouvés.");

// Compter ce qu'il reste
$reste = $pdo->query("
    SELECT COUNT(*) FROM elus
    WHERE type_mandat = 'maire' AND actif = 1 AND source_api = 'rne_2026'
      AND (email IS NULL OR email = '')
      AND (telephone IS NULL OR telephone = '')
")->fetchColumn();
emcLog("Reste à enrichir : $reste maires.");
