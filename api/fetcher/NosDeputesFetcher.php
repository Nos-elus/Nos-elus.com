<?php
require_once __DIR__ . '/FetcherBase.php';

/**
 * Fetcher pour l'API NosDéputés.fr
 * Doc : https://www.nosdeputes.fr/api
 * Donne : députés, mandats, activité, votes
 */
class NosDeputesFetcher extends FetcherBase {
    protected string $source = 'nosdeputes';
    private string $baseUrl = 'https://www.nosdeputes.fr';

    public function searchAndFetch(string $query): array {
        $url = $this->baseUrl . '/recherche/' . urlencode($query) . '?format=json';
        $data = $this->httpGet($url);

        if (!$data || !isset($data['results'])) {
            return [];
        }

        $elus = [];
        $start = microtime(true);
        $count = 0;

        foreach (array_slice($data['results'], 0, 20) as $result) {
            if (!isset($result['depute'])) continue;

            $dep = $result['depute'];
            $eluId = $this->upsertElu([
                'nom' => $dep['nom_de_famille'] ?? $dep['nom'] ?? '',
                'prenom' => $dep['prenom'] ?? null,
                'parti' => normalizeParti($dep['parti_ratt_financier'] ?? $dep['groupe_sigle'] ?? null),
                'fonction' => 'Député' . (!empty($dep['nom_circo']) ? ' — ' . $dep['nom_circo'] : ''),
                'photo_url' => !empty($dep['slug']) ? $this->baseUrl . '/depute/photo/' . $dep['slug'] . '/120' : null,
                'date_naissance' => $dep['date_naissance'] ?? null,
                'lieu_naissance' => $dep['lieu_naissance'] ?? null,
                'departement' => $dep['num_deptmt'] ?? null,
                'type_mandat' => 'depute',
                'source_id' => $dep['slug'] ?? ($dep['id'] ?? null),
            ]);

            // Fetch et store mandats si disponibles
            if (!empty($dep['anciens_mandats'])) {
                foreach ($dep['anciens_mandats'] as $m) {
                    $mandat = $m['mandat'] ?? $m;
                    $this->insertMandatIfNew($eluId, $mandat);
                }
            }

            $elus[] = $eluId;
            $count++;
        }

        $duration = (int)((microtime(true) - $start) * 1000);
        $this->log($url, 'success', $count, null, $duration);

        return $elus;
    }

    /**
     * Fetch le détail complet d'un député par son slug
     */
    public function fetchDetail(string $slug): ?int {
        $url = $this->baseUrl . '/' . urlencode($slug) . '/json';
        $data = $this->httpGet($url);

        if (!$data || !isset($data['depute'])) return null;

        $dep = $data['depute'];
        $eluId = $this->upsertElu([
            'nom' => $dep['nom_de_famille'] ?? $dep['nom'] ?? '',
            'prenom' => $dep['prenom'] ?? null,
            'parti' => normalizeParti($dep['parti_ratt_financier'] ?? $dep['groupe_sigle'] ?? null),
            'fonction' => 'Député' . (!empty($dep['nom_circo']) ? ' — ' . $dep['nom_circo'] : ''),
            'photo_url' => $this->baseUrl . '/depute/photo/' . $slug . '/120',
            'date_naissance' => $dep['date_naissance'] ?? null,
            'lieu_naissance' => $dep['lieu_naissance'] ?? null,
            'departement' => $dep['num_deptmt'] ?? null,
            'type_mandat' => 'depute',
            'source_id' => $slug,
        ]);

        return $eluId;
    }

    private function insertMandatIfNew(int $eluId, array $mandat): void {
        $titre = $mandat['mandat'] ?? ($mandat['titre'] ?? null);
        if (!$titre) return;

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM mandats WHERE elu_id = :eid AND titre = :titre
        ');
        $stmt->execute([':eid' => $eluId, ':titre' => $titre]);
        if ($stmt->fetchColumn() > 0) return;

        $stmt = $this->pdo->prepare('
            INSERT INTO mandats (elu_id, titre, date_debut, date_fin, institution)
            VALUES (:eid, :titre, :debut, :fin, :inst)
        ');
        $stmt->execute([
            ':eid' => $eluId,
            ':titre' => $titre,
            ':debut' => $mandat['date_debut'] ?? null,
            ':fin' => $mandat['date_fin'] ?? null,
            ':inst' => $mandat['institution'] ?? 'Assemblée nationale',
        ]);
    }
}
