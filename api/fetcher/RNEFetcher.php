<?php
require_once __DIR__ . '/FetcherBase.php';

/**
 * Fetcher pour le Repertoire National des Elus (RNE)
 * Source : https://www.data.gouv.fr/fr/datasets/repertoire-national-des-elus-1/
 *
 * Les 80k+ elus sont deja importes en BDD via scripts/import-rne.php.
 * searchAndFetch() fait une recherche locale intelligente (FULLTEXT + LIKE + sans accents).
 */
class RNEFetcher extends FetcherBase {
    protected string $source = 'rne';

    /**
     * Recherche locale optimisee : FULLTEXT booleen, puis LIKE fallback, puis sans accents.
     * Pas de fetch externe : tout est deja en BDD.
     *
     * @return int[] IDs des elus trouves
     */
    public function searchAndFetch(string $query): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $ids = [];

        // --- 1) FULLTEXT en mode boolean (rapide, gere les prefixes) ---
        $ftQuery = $this->buildFulltextQuery($query);
        if ($ftQuery !== '') {
            $stmt = $this->pdo->prepare('
                SELECT id, MATCH(nom, prenom) AGAINST(:q IN BOOLEAN MODE) AS score
                FROM elus
                WHERE MATCH(nom, prenom) AGAINST(:q2 IN BOOLEAN MODE)
                ORDER BY score DESC
                LIMIT 30
            ');
            $stmt->execute([':q' => $ftQuery, ':q2' => $ftQuery]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // --- 2) LIKE fallback si pas assez de resultats ---
        if (count($ids) < 5) {
            $like = '%' . $query . '%';
            $placeholders = '';
            $params = [':like_nom' => $like, ':like_prenom' => $like];

            if (!empty($ids)) {
                $placeholders = ' AND id NOT IN (' . implode(',', array_map('intval', $ids)) . ')';
            }

            $stmt = $this->pdo->prepare("
                SELECT id FROM elus
                WHERE (nom LIKE :like_nom OR prenom LIKE :like_prenom) $placeholders
                ORDER BY nb_consultations DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':like_nom', $like, PDO::PARAM_STR);
            $stmt->bindValue(':like_prenom', $like, PDO::PARAM_STR);
            $stmt->bindValue(':lim', 30 - count($ids), PDO::PARAM_INT);
            $stmt->execute();
            $extra = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $ids = array_merge($ids, $extra);
        }

        // --- 3) Recherche sans accents si toujours pas assez ---
        if (count($ids) < 5) {
            $stripped = $this->stripAccents($query);
            if ($stripped !== $query) {
                $like = '%' . $stripped . '%';
                $exclude = '';
                if (!empty($ids)) {
                    $exclude = ' AND id NOT IN (' . implode(',', array_map('intval', $ids)) . ')';
                }

                // CONVERT permet de comparer sans accents via latin1
                $stmt = $this->pdo->prepare("
                    SELECT id FROM elus
                    WHERE (CONVERT(nom USING latin1) LIKE :like_nom
                        OR CONVERT(prenom USING latin1) LIKE :like_prenom) $exclude
                    ORDER BY nb_consultations DESC
                    LIMIT :lim
                ");
                $stmt->bindValue(':like_nom', $like, PDO::PARAM_STR);
                $stmt->bindValue(':like_prenom', $like, PDO::PARAM_STR);
                $stmt->bindValue(':lim', 30 - count($ids), PDO::PARAM_INT);
                $stmt->execute();
                $extra = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $ids = array_merge($ids, $extra);
            }
        }

        // Deduplique et garde l'ordre
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Construit une requete FULLTEXT boolean a partir du texte utilisateur.
     * "jean dupont" -> "+jean* +dupont*"
     */
    private function buildFulltextQuery(string $input): string {
        $words = preg_split('/\s+/', trim($input));
        $words = array_filter($words, fn($w) => mb_strlen($w) >= 2);
        if (empty($words)) {
            return '';
        }
        return implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
    }

    /**
     * Supprime les accents d'une chaine UTF-8.
     */
    private function stripAccents(string $str): string {
        if (!function_exists('transliterator_transliterate')) {
            // Fallback basique si intl n'est pas dispo
            $map = [
                'a' => '[aàâäã]', 'e' => '[eéèêë]', 'i' => '[iîï]',
                'o' => '[oôöò]', 'u' => '[uùûü]', 'c' => '[cç]', 'n' => '[nñ]',
            ];
            $str = mb_strtolower($str);
            foreach ($map as $plain => $pattern) {
                $str = preg_replace("/$pattern/u", $plain, $str);
            }
            return $str;
        }
        return transliterator_transliterate('Any-Latin; Latin-ASCII', $str);
    }

    /**
     * Import batch depuis un fichier CSV du RNE (pour import initial).
     * A appeler manuellement ou par cron via scripts/import-rne.php.
     */
    public function importBatch(string $csvUrl, string $type = 'depute', int $limit = 1000): int {
        $start = microtime(true);
        $count = 0;

        $handle = @fopen($csvUrl, 'r');
        if (!$handle) {
            $this->log($csvUrl, 'error', 0, 'Cannot open CSV', 0);
            return 0;
        }

        // Lire le header
        $header = fgetcsv($handle, 0, ';');
        if (!$header) {
            fclose($handle);
            return 0;
        }
        $header = array_map('trim', $header);

        $this->pdo->beginTransaction();

        try {
            while (($row = fgetcsv($handle, 0, ';')) !== false && $count < $limit) {
                $data = @array_combine($header, $row);
                if (!$data) continue;

                $nom = $data['Nom de l\'elu'] ?? ($data['Nom'] ?? null);
                $prenom = $data['Prenom de l\'elu'] ?? ($data['Prenom'] ?? null);
                if (!$nom) continue;

                $this->upsertElu([
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'parti' => $data['Nuance politique'] ?? null,
                    'fonction' => $data['Libelle de la fonction'] ?? $type,
                    'date_naissance' => $this->parseDate($data['Date de naissance'] ?? null),
                    'departement' => $data['Code du departement'] ?? null,
                    'type_mandat' => $type,
                    'source_id' => md5($nom . $prenom . ($data['Date de naissance'] ?? '')),
                ]);
                $count++;
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->log($csvUrl, 'error', $count, $e->getMessage(), $duration);
            fclose($handle);
            return $count;
        }

        fclose($handle);
        $duration = (int)((microtime(true) - $start) * 1000);
        $this->log($csvUrl, 'success', $count, null, $duration);
        return $count;
    }

    private function parseDate(?string $date): ?string {
        if (!$date) return null;
        // Format RNE : DD/MM/YYYY
        $parts = explode('/', $date);
        if (count($parts) === 3) {
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
        return null;
    }
}
