<?php
/**
 * Import des votes nominatifs de l'Assemblée Nationale.
 * Source : data.assemblee-nationale.fr — Scrutins JSON (legislature 17)
 *
 * Usage : require + appeler importScrutins()
 * Cron : appelé par cron-votes-an.php quotidiennement
 */

class ANVotesFetcher
{
    private PDO $pdo;
    private string $tmpDir;
    private int $seuilVotants;
    private array $mapping = []; // acteurRef → elu_id

    public function __construct(PDO $pdo, string $tmpDir = '/tmp/scrutins_an', int $seuilVotants = 200)
    {
        $this->pdo = $pdo;
        $this->tmpDir = $tmpDir;
        $this->seuilVotants = $seuilVotants;
    }

    /**
     * Télécharge le ZIP des scrutins AN et le dézippe.
     */
    public function downloadScrutins(): bool
    {
        $zipUrl = 'https://data.assemblee-nationale.fr/static/openData/repository/17/loi/scrutins/Scrutins.json.zip';
        $zipPath = $this->tmpDir . '.zip';

        echo "Telechargement scrutins AN...\n";
        $data = @file_get_contents($zipUrl);
        if (!$data) {
            echo "ERREUR: impossible de telecharger $zipUrl\n";
            return false;
        }
        file_put_contents($zipPath, $data);
        echo "  " . round(strlen($data) / 1e6, 1) . " Mo telecharges\n";

        // Dézipper
        @mkdir($this->tmpDir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            echo "ERREUR: impossible d'ouvrir le ZIP\n";
            return false;
        }
        $zip->extractTo($this->tmpDir);
        $zip->close();
        @unlink($zipPath);

        $count = count(glob($this->tmpDir . '/json/*.json'));
        echo "  $count scrutins extraits\n";
        return $count > 0;
    }

    /**
     * Construit la table de mapping acteurRef → elu_id.
     * Télécharge le CSV des députés AN et croise avec notre BDD.
     */
    public function buildMapping(): int
    {
        // Créer la table si nécessaire
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS an_deputes_mapping (
                acteur_ref VARCHAR(20) PRIMARY KEY,
                elu_id INT,
                nom VARCHAR(255),
                prenom VARCHAR(255),
                INDEX idx_elu (elu_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Vérifier si le mapping existe déjà
        $existing = (int) $this->pdo->query("SELECT COUNT(*) FROM an_deputes_mapping WHERE elu_id IS NOT NULL")->fetchColumn();
        if ($existing > 400) {
            echo "Mapping existant: $existing deputes\n";
            $this->loadMapping();
            return $existing;
        }

        // Télécharger le CSV des députés actifs
        $csvUrl = 'https://data.assemblee-nationale.fr/static/openData/repository/17/amo/deputes_actifs_csv_opendata/liste_deputes_libre_office.csv';
        $csvData = @file_get_contents($csvUrl);
        if (!$csvData) {
            echo "ERREUR: impossible de telecharger le CSV deputes\n";
            return 0;
        }

        // Parser le CSV (guillemets + virgules)
        $lines = explode("\n", $csvData);
        $deputes = [];
        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);
            if (!$line) continue;
            // Parser CSV avec guillemets
            $fields = str_getcsv($line, ',', '"');
            if (count($fields) < 3) continue;
            $uid = 'PA' . trim($fields[0]);
            $prenom = trim($fields[1]);
            $nom = trim($fields[2]);
            $deputes[$uid] = ['prenom' => $prenom, 'nom' => $nom];
        }

        echo count($deputes) . " deputes AN parses\n";

        // Croiser avec notre BDD
        $stmtFind = $this->pdo->prepare("
            SELECT id FROM elus
            WHERE LOWER(nom) = LOWER(:nom) AND LOWER(prenom) = LOWER(:prenom)
            LIMIT 1
        ");
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO an_deputes_mapping (acteur_ref, elu_id, nom, prenom)
            VALUES (:ref, :elu_id, :nom, :prenom)
            ON DUPLICATE KEY UPDATE elu_id = VALUES(elu_id)
        ");

        $matched = 0;
        foreach ($deputes as $ref => $d) {
            $stmtFind->execute([':nom' => $d['nom'], ':prenom' => $d['prenom']]);
            $elu = $stmtFind->fetch();
            $eluId = $elu ? (int) $elu['id'] : null;
            $stmtInsert->execute([':ref' => $ref, ':elu_id' => $eluId, ':nom' => $d['nom'], ':prenom' => $d['prenom']]);
            if ($eluId) $matched++;
        }

        echo "Mapping: $matched / " . count($deputes) . " deputes matches\n";
        $this->loadMapping();
        return $matched;
    }

    /**
     * Charge le mapping en mémoire.
     */
    private function loadMapping(): void
    {
        $this->mapping = [];
        $stmt = $this->pdo->query("SELECT acteur_ref, elu_id FROM an_deputes_mapping WHERE elu_id IS NOT NULL");
        while ($row = $stmt->fetch()) {
            $this->mapping[$row['acteur_ref']] = (int) $row['elu_id'];
        }
    }

    /**
     * Importe les scrutins depuis les fichiers JSON.
     * Ne traite que les scrutins postérieurs à $sinceDate (pour l'incrémental).
     */
    public function importScrutins(?string $sinceDate = null): array
    {
        $jsonDir = $this->tmpDir . '/json';
        if (!is_dir($jsonDir)) {
            echo "ERREUR: repertoire $jsonDir introuvable\n";
            return ['scrutins' => 0, 'votes' => 0, 'skipped' => 0];
        }

        $files = glob($jsonDir . '/*.json');
        echo count($files) . " fichiers scrutins a traiter\n";

        if (empty($this->mapping)) {
            $this->loadMapping();
        }

        // Préparer les requêtes
        $stmtCheck = $this->pdo->prepare("SELECT 1 FROM votes WHERE scrutin_id = :sid AND elu_id = :eid LIMIT 1");
        $stmtInsert = $this->pdo->prepare("
            INSERT IGNORE INTO votes (elu_id, sujet, position, date_vote, scrutin_id)
            VALUES (:elu_id, :sujet, :position, :date_vote, :scrutin_id)
        ");

        $stats = ['scrutins' => 0, 'votes' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($files as $file) {
            $data = @json_decode(file_get_contents($file), true);
            if (!$data) { $stats['errors']++; continue; }

            $sc = $data['scrutin'] ?? $data;
            $uid = $sc['uid'] ?? '';
            $date = $sc['dateScrutin'] ?? '';
            $titre = mb_substr($sc['titre'] ?? '', 0, 500, 'UTF-8');
            $nbVotants = (int) ($sc['syntheseVote']['nombreVotants'] ?? 0);

            // Filtrer par seuil de votants
            if ($nbVotants < $this->seuilVotants) {
                $stats['skipped']++;
                continue;
            }

            // Filtrer par date si incrémental
            if ($sinceDate && $date && $date < $sinceDate) {
                $stats['skipped']++;
                continue;
            }

            // Extraire les votes nominatifs
            $groupes = $sc['ventilationVotes']['organe']['groupes']['groupe'] ?? [];
            if (!is_array($groupes)) continue;

            $votesInserted = 0;
            foreach ($groupes as $groupe) {
                $decompte = $groupe['vote']['decompteNominatif'] ?? null;
                if (!$decompte) continue;

                $positions = [
                    'pours' => 'Pour',
                    'contres' => 'Contre',
                    'abstentions' => 'Abstention',
                ];

                foreach ($positions as $key => $position) {
                    $votants = $decompte[$key]['votant'] ?? [];
                    if (!$votants) continue;
                    if (!is_array($votants) || isset($votants['acteurRef'])) {
                        $votants = [$votants]; // Votant unique → array
                    }

                    foreach ($votants as $votant) {
                        $acteurRef = $votant['acteurRef'] ?? '';
                        if (!$acteurRef) continue;

                        $eluId = $this->mapping[$acteurRef] ?? null;
                        if (!$eluId) continue;

                        $stmtInsert->execute([
                            ':elu_id' => $eluId,
                            ':sujet' => $titre,
                            ':position' => $position,
                            ':date_vote' => $date,
                            ':scrutin_id' => $uid,
                        ]);
                        if ($stmtInsert->rowCount() > 0) $votesInserted++;
                    }
                }
            }

            if ($votesInserted > 0) $stats['scrutins']++;
            $stats['votes'] += $votesInserted;
        }

        return $stats;
    }

    /**
     * Log dans fetch_log.
     */
    public function log(string $status, array $stats, int $durationMs): void
    {
        $this->pdo->prepare("
            INSERT INTO fetch_log (source, endpoint, status, records_count, error_message, duration_ms)
            VALUES ('an_votes', 'scrutins.json.zip', :status, :count, :msg, :duration)
        ")->execute([
            ':status' => $status,
            ':count' => $stats['votes'],
            ':msg' => json_encode($stats),
            ':duration' => $durationMs,
        ]);
    }
}
