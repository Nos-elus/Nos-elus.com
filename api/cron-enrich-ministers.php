<?php
/**
 * CRON — Enrichissement des ministres (depuis 2017)
 *
 * Complète, pour les élus ayant un mandat ministériel ou de Premier ministre
 * depuis 2017-01-01, les champs vides parmi : lieu_naissance, date_naissance,
 * bio, url_fiche. Les données sont collectées via l'API MediaWiki (Wikipedia
 * FR) et Wikidata.
 *
 * RÈGLES STRICTES :
 *  - On n'écrit JAMAIS sur un champ déjà rempli (NULL/''/'0000-00-00' uniquement).
 *  - Un délai de 0.5 s est appliqué entre chaque appel HTTP externe.
 *  - Les erreurs réseau ou 404 sont avalées : on continue avec l'élu suivant.
 *
 * Usage :
 *   php cron-enrich-ministers.php                    (tous les élus cibles)
 *   php cron-enrich-ministers.php --test             (5 premiers seulement)
 *   php cron-enrich-ministers.php --slug=bruno-retailleau  (un seul élu)
 */

require_once __DIR__ . '/config.php';

// ── Protection : CLI ou localhost uniquement ──
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

// ── Parsing arguments CLI ──
$argTest = false;
$argSlug = null;
foreach ($argv as $a) {
    if ($a === '--test') {
        $argTest = true;
    } elseif (strpos($a, '--slug=') === 0) {
        $argSlug = substr($a, 7);
    }
}

// ── Helpers ──
function logLine(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

/**
 * Appel HTTP GET avec timeout 10s. Retourne le body ou null si erreur/404.
 */
function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NosElusBot/1.0; +https://nos-elus.fr)',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Language: fr,en;q=0.5',
        ],
        // Hébergement mutualisé : le bundle CA peut différer du CLI curl
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        logLine("  [HTTP-ERR] curl error: $err — url: " . substr($url, 0, 120));
        return null;
    }
    if ($code >= 400) {
        logLine("  [HTTP-ERR] HTTP $code — url: " . substr($url, 0, 120));
        return null;
    }
    return $body;
}

function httpGetJson(string $url): ?array {
    $body = httpGet($url);
    if ($body === null) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function pause(): void {
    usleep(500_000); // 0.5 s
}

/**
 * Normalise pour comparaison souple (sans accents, minuscule).
 */
function normalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $tr = [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
        'ç'=>'c',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ñ'=>'n','ý'=>'y','ÿ'=>'y',
        '’'=>"'", '‘'=>"'",
    ];
    $s = strtr($s, $tr);
    $s = preg_replace('/[^a-z0-9 \-\']/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/**
 * Recherche un titre Wikipedia FR pour un élu.
 * Retourne le titre exact (ex : "Bruno Retailleau") ou null.
 */
function findWikipediaTitle(string $prenom, string $nom): ?string {
    $needle = normalize($nom);

    // Deux tentatives : avec qualificatif "politique" puis sans
    $queries = [
        trim($prenom . ' ' . $nom . ' politique france'),
        trim($prenom . ' ' . $nom),
    ];

    foreach ($queries as $query) {
        $url = 'https://fr.wikipedia.org/w/api.php?'
             . http_build_query([
                    'action'   => 'query',
                    'list'     => 'search',
                    'srsearch' => $query,
                    'srlimit'  => 5,
                    'format'   => 'json',
                    'utf8'     => 1,
               ]);
        $data = httpGetJson($url);
        if (!$data) {
            logLine("  [wiki] httpGetJson null pour: $query");
            continue;
        }
        if (empty($data['query']['search'])) {
            logLine("  [wiki] 0 résultats pour: $query — clés: " . implode(',', array_keys($data)));
            continue;
        }
        foreach ($data['query']['search'] as $hit) {
            $title = $hit['title'] ?? '';
            if ($title === '') continue;
            if (strpos(normalize($title), $needle) !== false) {
                return $title;
            }
        }
        // Log les titres trouvés mais non matchés
        $found = array_map(fn($h) => $h['title'] ?? '?', $data['query']['search']);
        logLine("  [wiki] résultats pour \"$query\" : " . implode(' | ', $found) . " — needle: $needle");
        usleep(300_000);
    }
    return null;
}

/**
 * Récupère l'entité Wikidata associée à un titre Wikipedia FR.
 * Retourne ['id' => 'Q123', 'claims' => [...]] ou null.
 */
function fetchWikidataEntity(string $wikipediaTitle): ?array {
    $url = 'https://www.wikidata.org/w/api.php?'
         . http_build_query([
                'action'    => 'wbgetentities',
                'sites'     => 'frwiki',
                'titles'    => $wikipediaTitle,
                'props'     => 'claims|labels',
                'languages' => 'fr',
                'format'    => 'json',
         ]);
    $data = httpGetJson($url);
    if (!$data || empty($data['entities'])) {
        return null;
    }
    foreach ($data['entities'] as $qid => $entity) {
        if ($qid === '-1' || strpos($qid, 'Q') !== 0) continue;
        return [
            'id'     => $qid,
            'claims' => $entity['claims'] ?? [],
            'labels' => $entity['labels'] ?? [],
        ];
    }
    return null;
}

/**
 * Récupère le label FR d'une entité Wikidata par QID.
 * Tente fallback en label par défaut si pas de FR.
 */
function fetchWikidataLabel(string $qid): ?string {
    $url = 'https://www.wikidata.org/w/api.php?'
         . http_build_query([
                'action'    => 'wbgetentities',
                'ids'       => $qid,
                'props'     => 'labels|claims',
                'languages' => 'fr|en',
                'format'    => 'json',
         ]);
    $data = httpGetJson($url);
    if (!$data || empty($data['entities'][$qid])) {
        return null;
    }
    $entity = $data['entities'][$qid];
    $label  = $entity['labels']['fr']['value']
           ?? $entity['labels']['en']['value']
           ?? null;
    if (!$label) return null;

    // Tenter d'ajouter le département (P131 = "located in administrative
    // territorial entity") en remontant l'arbre jusqu'à un département FR.
    $dept = null;
    if (!empty($entity['claims']['P131'])) {
        foreach ($entity['claims']['P131'] as $cl) {
            $parentQid = $cl['mainsnak']['datavalue']['value']['id'] ?? null;
            if (!$parentQid) continue;
            $deptCandidate = resolveFrenchDepartment($parentQid, 0);
            if ($deptCandidate) {
                $dept = $deptCandidate;
                break;
            }
        }
    }

    return $dept ? ($label . ' (' . $dept . ')') : $label;
}

/**
 * Remonte l'arbre P131 (max 3 niveaux) pour trouver un département FR.
 * Identifie un département via P31 (instance of) → Q6465 (département français).
 */
function resolveFrenchDepartment(string $qid, int $depth): ?string {
    if ($depth > 3) return null;

    $url = 'https://www.wikidata.org/w/api.php?'
         . http_build_query([
                'action'    => 'wbgetentities',
                'ids'       => $qid,
                'props'     => 'labels|claims',
                'languages' => 'fr|en',
                'format'    => 'json',
         ]);
    $data = httpGetJson($url);
    pause();
    if (!$data || empty($data['entities'][$qid])) {
        return null;
    }
    $entity = $data['entities'][$qid];

    // Est-ce un département français ?
    if (!empty($entity['claims']['P31'])) {
        foreach ($entity['claims']['P31'] as $cl) {
            $instanceQid = $cl['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($instanceQid === 'Q6465') {
                return $entity['labels']['fr']['value']
                    ?? $entity['labels']['en']['value']
                    ?? null;
            }
        }
    }

    // Sinon, remonter d'un cran via P131
    if (!empty($entity['claims']['P131'])) {
        foreach ($entity['claims']['P131'] as $cl) {
            $parentQid = $cl['mainsnak']['datavalue']['value']['id'] ?? null;
            if (!$parentQid) continue;
            $found = resolveFrenchDepartment($parentQid, $depth + 1);
            if ($found) return $found;
        }
    }

    return null;
}

/**
 * Construit l'URL d'une image Wikimedia Commons à partir d'un nom de fichier.
 * Format standard : https://upload.wikimedia.org/wikipedia/commons/X/XY/Filename
 * où X et XY proviennent du MD5 du nom de fichier (espaces → underscores).
 */
function buildCommonsImageUrl(string $filename): string {
    $clean = str_replace(' ', '_', $filename);
    $hash  = md5($clean);
    return 'https://upload.wikimedia.org/wikipedia/commons/'
         . $hash[0] . '/' . $hash[0] . $hash[1] . '/'
         . rawurlencode($clean);
}

/**
 * Récupère le résumé Wikipedia (premier paragraphe) via REST.
 */
function fetchWikipediaSummary(string $wikipediaTitle): ?string {
    $url = 'https://fr.wikipedia.org/api/rest_v1/page/summary/'
         . rawurlencode(str_replace(' ', '_', $wikipediaTitle));
    $data = httpGetJson($url);
    if (!$data || empty($data['extract'])) {
        return null;
    }
    $extract = trim($data['extract']);
    if ($extract === '') return null;
    if (mb_strlen($extract) > 300) {
        $extract = mb_substr($extract, 0, 300);
        // Ne pas couper en plein mot
        $lastSpace = mb_strrpos($extract, ' ');
        if ($lastSpace !== false && $lastSpace > 200) {
            $extract = mb_substr($extract, 0, $lastSpace);
        }
        $extract = rtrim($extract, " ,;:") . '…';
    }
    return $extract;
}

/**
 * Construit l'URL canonique de la page Wikipedia FR depuis le titre.
 */
function buildWikipediaUrl(string $wikipediaTitle): string {
    return 'https://fr.wikipedia.org/wiki/'
         . rawurlencode(str_replace(' ', '_', $wikipediaTitle));
}

/**
 * Vérifie si un champ DB est "vide" et donc éligible à l'enrichissement.
 */
function isEmptyField($value): bool {
    if ($value === null) return true;
    $v = trim((string)$value);
    if ($v === '') return true;
    return false;
}

function isEmptyDate($value): bool {
    if ($value === null) return true;
    $v = trim((string)$value);
    if ($v === '' || $v === '0000-00-00') return true;
    return false;
}

function isEmptyBio($value): bool {
    if ($value === null) return true;
    return mb_strlen(trim((string)$value)) < 50;
}

// ── 1. Récupérer les cibles ──
logLine('Récupération des ministres (depuis 2017) avec champs manquants...');

$sql = "
    SELECT DISTINCT e.id, e.nom, e.prenom, e.slug,
                    e.lieu_naissance, e.date_naissance, e.bio, e.url_fiche
    FROM elus e
    JOIN mandats m ON m.elu_id = e.id
    WHERE (LOWER(m.titre) LIKE '%ministre%' OR LOWER(m.titre) LIKE '%premier ministre%')
      AND m.date_debut >= '2017-01-01'
      AND (
            e.lieu_naissance = '' OR e.lieu_naissance IS NULL
         OR e.date_naissance IS NULL OR e.date_naissance = '0000-00-00'
         OR e.bio IS NULL OR LENGTH(e.bio) < 50
         OR e.url_fiche IS NULL OR e.url_fiche = ''
      )
";

$params = [];
if ($argSlug) {
    $sql .= " AND e.slug = :slug";
    $params[':slug'] = $argSlug;
}
$sql .= " ORDER BY e.nom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($argTest) {
    $cibles = array_slice($cibles, 0, 5);
}

$total = count($cibles);
logLine("Cibles à enrichir : $total" . ($argTest ? ' (mode --test)' : '') . ($argSlug ? " (slug=$argSlug)" : ''));

if ($total === 0) {
    logLine('Rien à faire. Fin.');
    exit(0);
}

// ── 2. Boucle d'enrichissement ──
$nbTraites    = 0;
$nbMisAJour   = 0;
$nbIntrouvables = 0;

$updateSql = "UPDATE elus SET %FIELDS% WHERE id = :id";

foreach ($cibles as $elu) {
    $nbTraites++;
    $label = trim(($elu['prenom'] ?? '') . ' ' . ($elu['nom'] ?? ''));
    logLine("[$nbTraites/$total] $label (id={$elu['id']})");

    // 2.1 — Recherche Wikipedia
    $wikiTitle = findWikipediaTitle((string)$elu['prenom'], (string)$elu['nom']);
    pause();

    if (!$wikiTitle) {
        logLine("  ✗ Wikipedia : aucun résultat exploitable.");
        $nbIntrouvables++;
        continue;
    }
    logLine("  → Wikipedia : « $wikiTitle »");

    // 2.2 — Wikidata
    $entity = fetchWikidataEntity($wikiTitle);
    pause();

    $newDateNaissance = null;
    $newLieuNaissance = null;

    if ($entity) {
        $claims = $entity['claims'];

        // P569 → date de naissance
        if (!empty($claims['P569'])) {
            foreach ($claims['P569'] as $cl) {
                $time = $cl['mainsnak']['datavalue']['value']['time'] ?? null;
                if ($time && preg_match('/([+-])(\d{4})-(\d{2})-(\d{2})/', $time, $m)) {
                    if ($m[1] === '+' && $m[2] !== '0000' && $m[3] !== '00' && $m[4] !== '00') {
                        $newDateNaissance = "{$m[2]}-{$m[3]}-{$m[4]}";
                        break;
                    }
                }
            }
        }

        // P19 → lieu de naissance (QID → label FR)
        if (!empty($claims['P19'])) {
            foreach ($claims['P19'] as $cl) {
                $qid = $cl['mainsnak']['datavalue']['value']['id'] ?? null;
                if ($qid) {
                    $newLieuNaissance = fetchWikidataLabel($qid);
                    pause();
                    if ($newLieuNaissance) break;
                }
            }
        }
    } else {
        logLine("  ! Wikidata : entité introuvable.");
    }

    // 2.3 — Bio depuis le résumé Wikipedia
    $newBio = fetchWikipediaSummary($wikiTitle);
    pause();

    // 2.4 — URL fiche Wikipedia
    $newUrlFiche = buildWikipediaUrl($wikiTitle);

    // 2.5 — Préparer l'UPDATE — UNIQUEMENT champs vides
    $sets   = [];
    $vals   = [':id' => $elu['id']];
    $report = [];

    if ($newLieuNaissance && isEmptyField($elu['lieu_naissance'])) {
        $sets[]              = 'lieu_naissance = :lieu_naissance';
        $vals[':lieu_naissance'] = $newLieuNaissance;
        $report[]            = "lieu_naissance: $newLieuNaissance";
    }
    if ($newDateNaissance && isEmptyDate($elu['date_naissance'])) {
        $sets[]              = 'date_naissance = :date_naissance';
        $vals[':date_naissance'] = $newDateNaissance;
        $report[]            = "date_naissance: $newDateNaissance";
    }
    if ($newBio && isEmptyBio($elu['bio'])) {
        $sets[]      = 'bio = :bio';
        $vals[':bio'] = $newBio;
        $bioPreview  = mb_substr($newBio, 0, 60) . '…';
        $report[]    = "bio: $bioPreview";
    }
    if ($newUrlFiche && isEmptyField($elu['url_fiche'])) {
        $sets[]            = 'url_fiche = :url_fiche';
        $vals[':url_fiche'] = $newUrlFiche;
        $report[]          = "url_fiche: $newUrlFiche";
    }

    if (empty($sets)) {
        logLine("  · Aucun champ à mettre à jour (déjà remplis ou données absentes).");
        continue;
    }

    $finalSql = str_replace('%FIELDS%', implode(', ', $sets), $updateSql);

    try {
        $up = $pdo->prepare($finalSql);
        $up->execute($vals);
        $nbMisAJour++;
        foreach ($report as $r) {
            logLine("  ✓ $label | $r → mis à jour [source: Wikipedia/Wikidata]");
        }
    } catch (Throwable $e) {
        logLine("  ✗ Erreur SQL : " . $e->getMessage());
    }
}

// ── 3. Stats finales ──
logLine('────────────────────────────────────────');
logLine("Élus traités     : $nbTraites");
logLine("Élus mis à jour  : $nbMisAJour");
logLine("Élus introuvables: $nbIntrouvables");
logLine('Fin.');
