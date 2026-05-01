<?php
/**
 * Calcul du taux d'activité parlementaire depuis les données AN open data.
 *
 * 3 indicateurs combinés :
 * 1. Votes : nb scrutins participés / total scrutins (200+ votants)
 * 2. Commissions : nb réunions présent / total réunions convoqué
 * 3. Questions : nb questions écrites/orales posées
 *
 * Stocke les résultats dans la table elus (score_assiduite mis à jour)
 * et dans une nouvelle table activite_parlementaire.
 */

class ANActiviteFetcher
{
    private PDO $pdo;
    private array $mapping = []; // acteurRef → elu_id

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->initTable();
        $this->loadMapping();
    }

    private function initTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS activite_parlementaire (
                elu_id INT PRIMARY KEY,
                nb_votes INT DEFAULT 0,
                total_scrutins INT DEFAULT 0,
                taux_votes DECIMAL(5,2) DEFAULT 0,
                nb_reunions_present INT DEFAULT 0,
                nb_reunions_convoque INT DEFAULT 0,
                taux_commissions DECIMAL(5,2) DEFAULT 0,
                nb_questions INT DEFAULT 0,
                taux_global DECIMAL(5,2) DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (elu_id) REFERENCES elus(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function loadMapping(): void
    {
        $stmt = $this->pdo->query("SELECT acteur_ref, elu_id FROM an_deputes_mapping WHERE elu_id IS NOT NULL");
        while ($row = $stmt->fetch()) {
            $this->mapping[$row['acteur_ref']] = (int) $row['elu_id'];
        }
        echo count($this->mapping) . " deputes dans le mapping\n";
    }

    /**
     * Calcule les stats de votes depuis la table `votes`.
     */
    public function calcVotes(): void
    {
        echo "Calcul stats votes...\n";
        $totalScrutins = (int) $this->pdo->query("SELECT COUNT(DISTINCT scrutin_id) FROM votes WHERE scrutin_id LIKE 'VTANR%'")->fetchColumn();
        echo "  $totalScrutins scrutins AN en base\n";

        $stmt = $this->pdo->query("
            SELECT elu_id, COUNT(DISTINCT scrutin_id) AS nb_votes
            FROM votes WHERE scrutin_id LIKE 'VTANR%'
            GROUP BY elu_id
        ");

        $stmtUpsert = $this->pdo->prepare("
            INSERT INTO activite_parlementaire (elu_id, nb_votes, total_scrutins, taux_votes)
            VALUES (:eid, :nb, :total, :taux)
            ON DUPLICATE KEY UPDATE nb_votes = VALUES(nb_votes), total_scrutins = VALUES(total_scrutins), taux_votes = VALUES(taux_votes)
        ");

        $count = 0;
        while ($row = $stmt->fetch()) {
            $taux = $totalScrutins > 0 ? round(($row['nb_votes'] / $totalScrutins) * 100, 2) : 0;
            $stmtUpsert->execute([
                ':eid' => $row['elu_id'],
                ':nb' => $row['nb_votes'],
                ':total' => $totalScrutins,
                ':taux' => $taux,
            ]);
            $count++;
        }
        echo "  $count deputes avec stats votes\n";
    }

    /**
     * Importe les présences en commission depuis les fichiers JSON AN.
     */
    public function importCommissions(string $reunionsDir): void
    {
        $files = glob($reunionsDir . '/json/reunion/*.json');
        echo "Calcul presences commissions ($files fichiers)...\n";
        if (empty($files)) { echo "  Aucun fichier\n"; return; }

        // Compter présences par député
        $presences = []; // acteurRef → [present, total]

        foreach ($files as $f) {
            $data = @json_decode(file_get_contents($f), true);
            if (!$data) continue;
            $r = $data['reunion'] ?? $data;
            $pi = $r['participants']['participantsInternes']['participantInterne'] ?? null;
            if (!$pi) continue;
            if (!is_array($pi) || isset($pi['acteurRef'])) $pi = [$pi];

            foreach ($pi as $p) {
                $ref = $p['acteurRef'] ?? '';
                $pres = strtolower($p['presence'] ?? '');
                if (!$ref || !isset($this->mapping[$ref])) continue;

                if (!isset($presences[$ref])) $presences[$ref] = ['present' => 0, 'total' => 0];
                $presences[$ref]['total']++;
                if (str_contains($pres, 'présent')) $presences[$ref]['present']++;
            }
        }

        echo "  " . count($presences) . " deputes avec donnees commissions\n";

        $stmtUpdate = $this->pdo->prepare("
            INSERT INTO activite_parlementaire (elu_id, nb_reunions_present, nb_reunions_convoque, taux_commissions)
            VALUES (:eid, :present, :convoque, :taux)
            ON DUPLICATE KEY UPDATE nb_reunions_present = VALUES(nb_reunions_present), nb_reunions_convoque = VALUES(nb_reunions_convoque), taux_commissions = VALUES(taux_commissions)
        ");

        foreach ($presences as $ref => $d) {
            $eluId = $this->mapping[$ref] ?? null;
            if (!$eluId) continue;
            $taux = $d['total'] > 0 ? round(($d['present'] / $d['total']) * 100, 2) : 0;
            $stmtUpdate->execute([':eid' => $eluId, ':present' => $d['present'], ':convoque' => $d['total'], ':taux' => $taux]);
        }
    }

    /**
     * Importe les questions écrites depuis les fichiers JSON AN.
     */
    public function importQuestions(string $questionsDir): void
    {
        $files = glob($questionsDir . '/json/*.json');
        echo "Comptage questions ecrites (" . count($files) . " fichiers)...\n";
        if (empty($files)) { echo "  Aucun fichier\n"; return; }

        $questions = []; // acteurRef → count

        foreach ($files as $f) {
            $data = @json_decode(file_get_contents($f), true);
            if (!$data) continue;
            $q = $data['question'] ?? $data;
            $ref = $q['auteur']['identite']['acteurRef'] ?? '';
            if ($ref && isset($this->mapping[$ref])) {
                $questions[$ref] = ($questions[$ref] ?? 0) + 1;
            }
        }

        echo "  " . count($questions) . " deputes avec questions\n";

        $stmtUpdate = $this->pdo->prepare("
            INSERT INTO activite_parlementaire (elu_id, nb_questions)
            VALUES (:eid, :nb)
            ON DUPLICATE KEY UPDATE nb_questions = VALUES(nb_questions)
        ");

        foreach ($questions as $ref => $nb) {
            $eluId = $this->mapping[$ref] ?? null;
            if (!$eluId) continue;
            $stmtUpdate->execute([':eid' => $eluId, ':nb' => $nb]);
        }
    }

    /**
     * Calcule le taux global composite et met à jour la fiche élu.
     * Formule : (taux_votes * 0.5) + (taux_commissions * 0.35) + (min(nb_questions, 50) / 50 * 100 * 0.15)
     */
    public function calcTauxGlobal(): void
    {
        echo "Calcul taux global...\n";

        // WHERE type_elu = 'depute' : évite d'écraser les eurodéputés/sénateurs
        // qui n'ont pas de données commissions/questions AN.
        $this->pdo->exec("
            UPDATE activite_parlementaire SET taux_global = ROUND(
                (taux_votes * 0.5) +
                (taux_commissions * 0.35) +
                (LEAST(nb_questions, 50) / 50 * 100 * 0.15)
            , 2)
            WHERE type_elu = 'depute'
        ");

        // Stats
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as total,
                   ROUND(AVG(taux_global), 1) as moy,
                   MIN(taux_global) as min_taux,
                   MAX(taux_global) as max_taux
            FROM activite_parlementaire WHERE taux_global > 0 AND type_elu = 'depute'
        ");
        $stats = $stmt->fetch();
        echo "  {$stats['total']} deputes — moy: {$stats['moy']}% — min: {$stats['min_taux']}% — max: {$stats['max_taux']}%\n";
    }
}
