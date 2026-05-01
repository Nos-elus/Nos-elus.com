<?php
/**
 * Systeme de cache multi-niveaux
 * L1 : Headers HTTP (Cache-Control, ETag) -> navigateur
 * L2 : Fichier JSON local -> /api/cache/
 * L3 : MySQL (api_cache table) -> fallback
 */

define('CACHE_DIR', __DIR__ . '/cache/data/');
define('CACHE_TTL_SHORT', 300);      // 5 min -- recherches, stats
define('CACHE_TTL_MEDIUM', 3600);    // 1h -- fiches elus
define('CACHE_TTL_LONG', 86400);     // 24h -- donnees stables (citations, listes partis)
define('CACHE_TTL_SEARCH', 600);     // 10 min -- resultats de recherche

// Creer le dossier cache si necessaire
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

/**
 * Genere une cle de cache normalisee
 */
function cacheKey(string $prefix, array $params = []): string {
    ksort($params);
    return $prefix . '_' . md5(json_encode($params));
}

/**
 * Lit depuis le cache fichier
 * @return array|null ['data' => mixed, 'etag' => string, 'created' => int] ou null si miss/expire
 */
function cacheGet(string $key, int $ttl = CACHE_TTL_MEDIUM): ?array {
    $file = CACHE_DIR . $key . '.json';

    if (!file_exists($file)) return null;

    $mtime = filemtime($file);
    if ((time() - $mtime) > $ttl) {
        // Expire mais on garde le fichier (stale-while-revalidate)
        return null;
    }

    $raw = file_get_contents($file);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    if ($data === null) return null;

    return [
        'data' => $data,
        'etag' => md5($raw),
        'created' => $mtime,
    ];
}

/**
 * Ecrit dans le cache fichier
 */
function cacheSet(string $key, mixed $data): void {
    $file = CACHE_DIR . $key . '.json';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($file, $json, LOCK_EX);
}

/**
 * Invalide un cache specifique
 */
function cacheDelete(string $key): void {
    $file = CACHE_DIR . $key . '.json';
    if (file_exists($file)) unlink($file);
}

/**
 * Invalide tous les caches d'un prefixe (ex: tous les caches "elu_*")
 */
function cachePurgePrefix(string $prefix): int {
    $protected = ['visits.json', 'votes_2027.json', 'votes_citoyens.json'];
    $count = 0;
    $pattern = CACHE_DIR . $prefix . '_*.json';
    foreach (glob($pattern) as $file) {
        if (in_array(basename($file), $protected, true)) continue;
        unlink($file);
        $count++;
    }
    return $count;
}

/**
 * Nettoie les fichiers expires (a appeler via cron ou occasionnellement)
 * Protege les fichiers de donnees persistantes (votes, visites)
 */
function cacheCleanup(int $maxAge = 86400): int {
    $protected = ['visits.json', 'votes_2027.json', 'votes_citoyens.json'];
    $count = 0;
    $now = time();
    foreach (glob(CACHE_DIR . '*.json') as $file) {
        if (in_array(basename($file), $protected, true)) continue;
        if (($now - filemtime($file)) > $maxAge) {
            unlink($file);
            $count++;
        }
    }
    return $count;
}

/**
 * Envoie les headers HTTP de cache et gere ETag/304
 * Retourne true si le client a un cache valide (304 envoye), false sinon
 */
function httpCache(string $etag, int $maxAge = 300): bool {
    header("Cache-Control: public, max-age=$maxAge, stale-while-revalidate=60");
    header("ETag: \"$etag\"");
    header('Vary: Accept-Encoding');

    // Pas de cache serveur supplémentaire

    // Verifier If-None-Match (ETag client)
    $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $clientEtag = trim($clientEtag, '"');

    if ($clientEtag === $etag) {
        http_response_code(304);
        exit;
    }

    return false;
}

/**
 * Wrapper complet : cache fichier + headers HTTP
 * Usage : $data = cachedResponse('elu', ['id' => 42], CACHE_TTL_MEDIUM, function() { return queryDB(); });
 */
function cachedResponse(string $prefix, array $params, int $ttl, callable $fetcher): mixed {
    $key = cacheKey($prefix, $params);

    // L2 : cache fichier
    $cached = cacheGet($key, $ttl);

    if ($cached !== null) {
        // Hit cache -> envoyer headers HTTP (L1)
        httpCache($cached['etag'], min($ttl, 300));
        return $cached['data'];
    }

    // Miss -> fetch data
    $data = $fetcher();

    // Store in cache
    cacheSet($key, $data);

    // Generer ETag pour la reponse fraiche
    $etag = md5(json_encode($data));
    httpCache($etag, min($ttl, 300));

    return $data;
}

/**
 * Nettoyage occasionnel (1% des requetes)
 */
if (mt_rand(1, 100) === 1) {
    cacheCleanup();
}
