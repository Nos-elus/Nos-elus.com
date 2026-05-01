<?php
/**
 * Classe de base pour les fetchers de données publiques.
 * Pattern : fetch on demand → store in MySQL → serve from cache.
 */

require_once __DIR__ . '/../normalize-parti.php';

abstract class FetcherBase {
    protected PDO $pdo;
    protected string $source;
    protected int $timeout = 3; // secondes (autocomplete, pas de blocage long)

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Requête HTTP GET avec gestion d'erreurs
     */
    protected function httpGet(string $url, array $headers = []): ?array {
        $start = microtime(true);

        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'header' => array_merge([
                    'Accept: application/json',
                    'User-Agent: nos-elus.fr/1.0 (plateforme citoyenne)'
                ], $headers),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ];

        $context = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $context);
        $duration = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) {
            $this->log($url, 'error', 0, 'Connection failed', $duration);
            return null;
        }

        // Vérifier le code HTTP
        $status = 0;
        if (isset($http_response_header[0])) {
            preg_match('/\d{3}/', $http_response_header[0], $m);
            $status = (int)($m[0] ?? 0);
        }

        if ($status >= 400) {
            $this->log($url, 'error', 0, "HTTP $status", $duration);
            return null;
        }

        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->log($url, 'error', 0, 'Invalid JSON: ' . json_last_error_msg(), $duration);
            return null;
        }

        return $data;
    }

    /**
     * Log dans la table fetch_log
     */
    protected function log(string $endpoint, string $status, int $count, ?string $error, int $durationMs): void {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO fetch_log (source, endpoint, status, records_count, error_message, duration_ms)
                VALUES (:source, :endpoint, :status, :count, :error, :duration)
            ');
            $stmt->execute([
                ':source' => $this->source,
                ':endpoint' => mb_substr($endpoint, 0, 500),
                ':status' => $status,
                ':count' => $count,
                ':error' => $error,
                ':duration' => $durationMs,
            ]);
        } catch (PDOException $e) {
            // Silently fail — logging shouldn't break the app
        }
    }

    /**
     * Vérifie si un élu existe déjà avec cette source
     */
    protected function eluExistsBySource(string $sourceId): ?int {
        $stmt = $this->pdo->prepare('SELECT id FROM elus WHERE source_api = :src AND source_id = :sid');
        $stmt->execute([':src' => $this->source, ':sid' => $sourceId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Génère un slug URL-friendly
     */
    protected function slugify(string $text): string {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Insert ou update un élu, retourne l'id (ou 0 si insertion refusée).
     *
     * Règle éditoriale : on n'ajoute PAS un nouvel élu déjà inactif à
     * l'import. Les mises à jour des élus déjà en BDD restent autorisées.
     * Le marqueur d'inactivité accepté dans $data :
     *   - $data['date_fin_mandat']  : date au format Y-m-d, si < today → inactif
     *   - $data['actif'] = false    : explicite
     */
    protected function upsertElu(array $data): int {
        $existingId = null;
        if (!empty($data['source_id'])) {
            $existingId = $this->eluExistsBySource($data['source_id']);
        }

        // Garde : refuser l'INSERT d'un nouvel ex-élu
        if (!$existingId) {
            $isInactive = false;
            if (isset($data['actif']) && $data['actif'] === false) {
                $isInactive = true;
            }
            if (!empty($data['date_fin_mandat'])
                && $data['date_fin_mandat'] < date('Y-m-d')) {
                $isInactive = true;
            }
            if ($isInactive) {
                $this->log(
                    'upsertElu:skip-inactive',
                    'partial',
                    0,
                    'Nouvel élu inactif refusé: ' . ($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''),
                    0
                );
                return 0;
            }
        }

        if ($existingId) {
            // Update
            $stmt = $this->pdo->prepare('
                UPDATE elus SET
                    nom = :nom, prenom = :prenom, parti = :parti, fonction = :fonction,
                    photo_url = :photo, date_naissance = :dob, lieu_naissance = :lieu,
                    departement = :dept, region = :region, type_mandat = :type_mandat,
                    derniere_sync = NOW(), updated_at = NOW()
                WHERE id = :id
            ');
            $stmt->execute([
                ':nom' => $data['nom'],
                ':prenom' => $data['prenom'] ?? null,
                ':parti' => normalizeParti($data['parti'] ?? null),
                ':fonction' => $data['fonction'] ?? null,
                ':photo' => $data['photo_url'] ?? null,
                ':dob' => $data['date_naissance'] ?? null,
                ':lieu' => $data['lieu_naissance'] ?? null,
                ':dept' => $data['departement'] ?? null,
                ':region' => $data['region'] ?? null,
                ':type_mandat' => $data['type_mandat'] ?? null,
                ':id' => $existingId,
            ]);
            return $existingId;
        }

        // Insert
        $slug = $this->slugify(($data['prenom'] ?? '') . ' ' . $data['nom']);
        // Vérifier unicité du slug
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM elus WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        if ($stmt->fetchColumn() > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 4);
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO elus (nom, prenom, parti, fonction, slug, emoji, photo_url,
                date_naissance, lieu_naissance, departement, region, type_mandat,
                source_api, source_id, derniere_sync, patrimoine_info)
            VALUES (:nom, :prenom, :parti, :fonction, :slug, :emoji, :photo,
                :dob, :lieu, :dept, :region, :type_mandat,
                :source_api, :source_id, NOW(), :patrimoine)
        ');
        $stmt->execute([
            ':nom' => $data['nom'],
            ':prenom' => $data['prenom'] ?? null,
            ':parti' => normalizeParti($data['parti'] ?? null),
            ':fonction' => $data['fonction'] ?? null,
            ':slug' => $slug,
            ':emoji' => $data['emoji'] ?? '🏛️',
            ':photo' => $data['photo_url'] ?? null,
            ':dob' => $data['date_naissance'] ?? null,
            ':lieu' => $data['lieu_naissance'] ?? null,
            ':dept' => $data['departement'] ?? null,
            ':region' => $data['region'] ?? null,
            ':type_mandat' => $data['type_mandat'] ?? null,
            ':source_api' => $this->source,
            ':source_id' => $data['source_id'] ?? null,
            ':patrimoine' => $data['patrimoine_info'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Méthode principale : chercher un élu par nom, le fetch si absent
     */
    abstract public function searchAndFetch(string $query): array;
}
