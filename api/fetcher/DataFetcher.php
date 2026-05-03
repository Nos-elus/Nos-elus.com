<?php
require_once __DIR__ . '/NosDeputesFetcher.php';
require_once __DIR__ . '/RNEFetcher.php';

/**
 * Orchestrateur de data fetching.
 * Stratégie : chercher en local d'abord, fetch depuis les APIs si pas assez de résultats.
 */
class DataFetcher {
    private PDO $pdo;
    private NosDeputesFetcher $nosdeputes;
    private RNEFetcher $rne;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->nosdeputes = new NosDeputesFetcher($pdo);
        $this->rne = new RNEFetcher($pdo);
    }

    /**
     * Recherche multi-source : local → NosDéputés → RNE
     * Retourne les IDs des élus trouvés
     */
    public function search(string $query, int $limit = 20): array {
        if (mb_strlen($query) < 2) return [];

        // 1. Chercher en local (FULLTEXT + LIKE fallback)
        $ids = $this->searchLocal($query, $limit);

        // 2. Si pas assez de résultats, fetch depuis les APIs externes
        if (count($ids) < 3) {
            // Vérifier qu'on n'a pas déjà fetché récemment pour cette query
            if (!$this->recentlyFetched($query)) {
                $newIds = $this->nosdeputes->searchAndFetch($query);
                $ids = array_unique(array_merge($ids, $newIds));
                $this->markFetched($query);
            }
        }

        return array_slice($ids, 0, $limit);
    }

    /**
     * Recherche locale optimisée
     */
    private function searchLocal(string $query, int $limit): array {
        // FULLTEXT search (rapide sur 500k+ rows)
        $stmt = $this->pdo->prepare('
            SELECT id, MATCH(nom, prenom) AGAINST(:q IN BOOLEAN MODE) AS relevance
            FROM elus
            WHERE MATCH(nom, prenom) AGAINST(:q2 IN BOOLEAN MODE)
            ORDER BY relevance DESC, nb_consultations DESC
            LIMIT :lim
        ');
        // Échapper les opérateurs FULLTEXT BOOLEAN MODE dans la query brute
        $cleanQuery = preg_replace('/[+\-><()~*"@]/', ' ', $query);
        $cleanQuery = trim(preg_replace('/\s+/', ' ', $cleanQuery));
        if ($cleanQuery === '') return [];
        $boolQuery = implode(' ', array_map(fn($w) => '+' . $w . '*', explode(' ', $cleanQuery)));
        $stmt->bindValue(':q', $boolQuery);
        $stmt->bindValue(':q2', $boolQuery);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fallback LIKE si FULLTEXT ne donne rien (accents, noms partiels)
        if (empty($results)) {
            $stmt = $this->pdo->prepare('
                SELECT id FROM elus
                WHERE nom LIKE :like1 OR prenom LIKE :like2
                ORDER BY nb_consultations DESC
                LIMIT :lim
            ');
            $stmt->bindValue(':like1', '%' . $query . '%');
            $stmt->bindValue(':like2', '%' . $query . '%');
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return $results;
    }

    /**
     * Vérifie si on a déjà fetché pour cette query récemment (< 1h)
     */
    private function recentlyFetched(string $query): bool {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM fetch_log
            WHERE endpoint LIKE :q AND status = "success"
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ');
        $stmt->execute([':q' => '%' . $query . '%']);
        return $stmt->fetchColumn() > 0;
    }

    private function markFetched(string $query): void {
        // Implicit via fetch_log entries in the fetchers
    }

    /**
     * Incrémenter le compteur de consultations
     */
    public function incrementConsultations(int $eluId): void {
        $this->pdo->prepare('UPDATE elus SET nb_consultations = nb_consultations + 1 WHERE id = :id')
            ->execute([':id' => $eluId]);
    }
}
