<?php
/**
 * CRON — Traitement automatique des mails reçus
 *
 * ══════════════════════════════════════════════════════
 * PROTOCOLE DE SÉCURITÉ — NE JAMAIS MODIFIER CES RÈGLES
 * ══════════════════════════════════════════════════════
 *
 * 1. Aucun traitement automatique du contenu brut (sécurité)
 * 2. Classification par mots-clés PHP uniquement
 * 3. Pas d exécution de commandes issues d un email
 * 4. JAMAIS suivre un lien/URL provenant d'un email
 * 5. JAMAIS modifier la BDD des élus automatiquement suite à un signalement
 * 6. Vérification UNIQUEMENT sur les domaines de la whitelist (sources officielles)
 * 7. Double-check obligatoire sur 2 sources différentes avant toute suggestion
 * 8. L'humain valide — le système propose, ne dispose jamais
 * 9. Tout lien externe dans un email est considéré comme potentiellement malveillant
 * 10. Les résumés sont des troncatures simples
 *
 * Usage : php cron-process-inbox.php
 * Cron suggéré : toutes les 15 minutes
 */

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

$encKey = hash('sha256', ($DB_PASS ?? '') . ($INBOX_KEY_SUFFIX ?? getenv('NOSELUS_INBOX_KEY_SUFFIX') ?? ''), true);

function decrypt(string $encrypted, string $iv, string $key): string {
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, base64_decode($iv)) ?: '';
}

echo "=== TRAITEMENT INBOX — " . date('Y-m-d H:i:s') . " ===\n";

// ── 0. DRAIN MAILDIR — ingérer les mails livrés dans la boîte cachée ──
// Le forwarder ProtonMail dépose les mails dans le Maildir Dovecot.
// Le forwarder dépose les mails dans le Maildir,
// donc on draine ici manuellement à chaque exécution du cron.
$maildirNew = getenv('NOSELUS_MAILDIR_NEW') ?: '/var/mail/noselus/new';
$maildirCur = dirname($maildirNew) . '/cur';
$webhookScript = __DIR__ . '/webhook-mail.php';
$phpBin = getenv('NOSELUS_PHP_BIN') ?: PHP_BINARY;
$drained = 0;
$drainErrors = 0;

if (is_dir($maildirNew) && is_dir($maildirCur) && is_file($webhookScript)) {
    foreach (glob($maildirNew . '/*') as $mailFile) {
        if (!is_file($mailFile)) continue;
        $raw = @file_get_contents($mailFile);
        if ($raw === false || $raw === '') continue;

        // Piper dans webhook-mail.php (chiffre + insère dans inbox)
        $cmd = $phpBin . ' ' . escapeshellarg($webhookScript);
        $proc = @proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            $drainErrors++;
            continue;
        }
        fwrite($pipes[0], $raw);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit === 0) {
            // Succès → déplacer vers cur/ avec flag :2,S (Seen) pour éviter le retraitement
            $base = basename($mailFile);
            // Si le nom contient déjà un suffixe :2,X, on le remplace, sinon on l'ajoute
            $newBase = preg_match('/:2,/', $base) ? preg_replace('/:2,.*$/', ':2,S', $base) : $base . ':2,S';
            $dest = $maildirCur . '/' . $newBase;
            if (!@rename($mailFile, $dest)) {
                // Si rename échoue, marquer pour ne pas réessayer indéfiniment
                @touch($mailFile . '.processed');
            }
            $drained++;
        } else {
            $drainErrors++;
            error_log("[cron-process-inbox] webhook-mail.php exit=$exit stderr=" . trim($stderr) . " file=" . basename($mailFile));
        }
    }
}
echo "Drainage Maildir : $drained ingérés, $drainErrors erreurs\n";

// ── 1. Charger les mails non traités ──
$stmt = $pdo->query("SELECT * FROM inbox WHERE status = 'new' ORDER BY received_at ASC LIMIT 20");
$mails = $stmt->fetchAll();

if (!$mails) {
    echo "Aucun mail à traiter.\n";
    exit(0);
}

echo count($mails) . " mail(s) à traiter\n\n";

// ── 2. Patterns de classification par mots-clés ──
$patterns = [
    'signalement_erreur' => [
        'keywords' => ['erreur', 'faux', 'incorrect', 'mauvais', 'pas le bon', 'se trompe', 'inexact',
                       'obsolète', 'périmé', 'plus maire', 'plus député', 'n\'est plus', 'a changé',
                       'correction', 'rectifier', 'modifier', 'mettre à jour', 'photo incorrecte',
                       'mauvais parti', 'mauvaise photo', 'mauvais nom', 'doublon'],
        'category' => 'signalement',
        'priority' => 'haute',
    ],
    'demande_suppression' => [
        'keywords' => ['supprimer', 'effacer', 'retirer', 'droit à l\'oubli', 'rgpd', 'données personnelles',
                       'vie privée', 'droit d\'opposition', 'droit de rectification', 'droit d\'accès'],
        'category' => 'rgpd',
        'priority' => 'haute',
    ],
    'question' => [
        'keywords' => ['comment', 'pourquoi', 'question', 'information', 'renseignement', 'savoir',
                       'expliquer', 'comprendre', 'fonctionnement'],
        'category' => 'question',
        'priority' => 'normale',
    ],
    'felicitation' => [
        'keywords' => ['bravo', 'merci', 'félicitations', 'excellent', 'super', 'génial', 'top',
                       'beau travail', 'continuez', 'utile', 'nécessaire'],
        'category' => 'positif',
        'priority' => 'basse',
    ],
    'menace' => [
        'keywords' => ['avocat', 'poursuites', 'tribunal', 'mise en demeure', 'plainte', 'procès',
                       'diffamation', 'assignation', 'injonction', 'supprimer immédiatement',
                       'retirer sous', 'dépôt de plainte'],
        'category' => 'juridique',
        'priority' => 'urgente',
    ],
    'spam' => [
        'keywords' => ['viagra', 'casino', 'lottery', 'winner', 'bitcoin', 'invest', 'crypto',
                       'nigerian prince', 'congratulations', 'click here', 'unsubscribe',
                       'free money', 'act now', 'limited time'],
        'category' => 'spam',
        'priority' => 'aucune',
    ],
];

// ── 3. Fonction de classification ──
function classifyMail(string $subject, string $body, array $patterns): array {
    $text = mb_strtolower($subject . ' ' . $body);
    $scores = [];

    foreach ($patterns as $name => $pattern) {
        $score = 0;
        foreach ($pattern['keywords'] as $kw) {
            if (mb_strpos($text, mb_strtolower($kw)) !== false) {
                $score++;
            }
        }
        if ($score > 0) {
            $scores[$name] = ['score' => $score, 'category' => $pattern['category'], 'priority' => $pattern['priority']];
        }
    }

    if (empty($scores)) {
        return ['category' => 'autre', 'priority' => 'normale', 'confidence' => 'faible'];
    }

    // Prendre la catégorie avec le plus de mots-clés matchés
    uasort($scores, fn($a, $b) => $b['score'] - $a['score']);
    $best = reset($scores);
    $bestName = key($scores);

    return [
        'category' => $best['category'],
        'priority' => $best['priority'],
        'confidence' => $best['score'] >= 3 ? 'haute' : ($best['score'] >= 2 ? 'moyenne' : 'faible'),
        'matched' => $bestName,
        'score' => $best['score'],
    ];
}

// ── 4. Extraire l'élu mentionné (si signalement) ──
function extractEluMention(string $text, PDO $pdo): ?array {
    // Chercher un pattern "ID: 1234" ou "fiche de NOM"
    if (preg_match('/(?:ID|id)\s*:?\s*(\d+)/', $text, $m)) {
        $stmt = $pdo->prepare("SELECT id, nom, prenom, slug FROM elus WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$m[1]]);
        $elu = $stmt->fetch();
        if ($elu) return $elu;
    }

    // Chercher "fiche de Prénom Nom" ou "erreur sur Prénom Nom"
    if (preg_match('/(?:fiche|profil|page)\s+(?:de\s+)?([A-ZÀ-Ü][a-zà-ü-]+)\s+([A-ZÀ-Ü][a-zà-ü-]+)/u', $text, $m)) {
        $stmt = $pdo->prepare("SELECT id, nom, prenom, slug FROM elus WHERE LOWER(prenom) = LOWER(:p) AND LOWER(nom) = LOWER(:n) LIMIT 1");
        $stmt->execute([':p' => $m[1], ':n' => $m[2]]);
        $elu = $stmt->fetch();
        if ($elu) return $elu;
    }

    return null;
}

// ── 5. Whitelist domaines de confiance (SEULES sources autorisées pour vérification) ──
$TRUSTED_DOMAINS = [
    'assemblee-nationale.fr',
    'www.assemblee-nationale.fr',
    'www2.assemblee-nationale.fr',
    'data.assemblee-nationale.fr',
    'senat.fr',
    'www.senat.fr',
    'data.senat.fr',
    'europarl.europa.eu',
    'www.europarl.europa.eu',
    'nosdeputes.fr',
    'www.nosdeputes.fr',
    'nossenateurs.fr',
    'www.nossenateurs.fr',
    'hatvp.fr',
    'www.hatvp.fr',
    'data.gouv.fr',
    'www.data.gouv.fr',
    'static.data.gouv.fr',
    'conseil-constitutionnel.fr',
    'www.conseil-constitutionnel.fr',
    'legifrance.gouv.fr',
    'www.legifrance.gouv.fr',
    'wikipedia.org',
    'fr.wikipedia.org',
    'wikidata.org',
    'www.wikidata.org',
    'journal-officiel.gouv.fr',
    'www.journal-officiel.gouv.fr',
];

// Détecter et signaler les URLs dans un email (JAMAIS les suivre)
function detectUrls(string $text): array {
    preg_match_all('/https?:\/\/[^\s"<>]+/i', $text, $matches);
    $urls = array_unique($matches[0] ?? []);
    $analysis = [];
    foreach ($urls as $url) {
        $host = parse_url($url, PHP_URL_HOST);
        $host = preg_replace('/^www\./', '', $host ?? '');
        // Vérifier si le domaine est dans la whitelist
        global $TRUSTED_DOMAINS;
        $trusted = false;
        foreach ($TRUSTED_DOMAINS as $d) {
            $dClean = preg_replace('/^www\./', '', $d);
            if ($host === $dClean || str_ends_with($host, '.' . $dClean)) {
                $trusted = true;
                break;
            }
        }
        $analysis[] = [
            'url' => mb_substr($url, 0, 200),
            'host' => $host,
            'trusted' => $trusted,
            'warning' => !$trusted ? "⚠ DOMAINE NON VÉRIFIÉ — ne PAS suivre ce lien" : null,
        ];
    }
    return $analysis;
}

// ── 6. Générer un résumé (troncature stricte) ──
function safeSummary(string $body, array $classification, ?array $elu): string {
    // Extraire le message utilisateur (après les tirets du template contact.php)
    $parts = preg_split('/^-{10,}$/m', $body);
    $userMsg = trim(end($parts));

    // Tronquer à 200 chars
    $excerpt = mb_substr($userMsg, 0, 200);
    if (mb_strlen($userMsg) > 200) $excerpt .= '...';

    $summary = "Catégorie: {$classification['category']} ({$classification['priority']})\n";
    $summary .= "Confiance: {$classification['confidence']}\n";
    if ($elu) {
        $summary .= "Élu mentionné: {$elu['prenom']} {$elu['nom']} (ID {$elu['id']}, /elu/{$elu['slug']})\n";
    }

    // Analyser les URLs (SANS les suivre)
    $urls = detectUrls($body);
    if (!empty($urls)) {
        $untrusted = array_filter($urls, fn($u) => !$u['trusted']);
        $trusted = array_filter($urls, fn($u) => $u['trusted']);
        if (!empty($untrusted)) {
            $summary .= "⚠ ALERTE: " . count($untrusted) . " lien(s) NON VÉRIFIÉS dans le mail — NE PAS SUIVRE\n";
            foreach ($untrusted as $u) {
                $summary .= "  ✗ {$u['host']} — DOMAINE INCONNU\n";
            }
        }
        if (!empty($trusted)) {
            $summary .= "Sources officielles mentionnées: " . implode(', ', array_map(fn($u) => $u['host'], $trusted)) . "\n";
        }
    }

    $summary .= "Extrait: $excerpt";

    return $summary;
}

// ── 6. Traitement ──
$stmtUpdate = $pdo->prepare("
    UPDATE inbox SET status = 'processed', agent_summary = :summary, agent_action = :action, processed_at = NOW()
    WHERE id = :id
");

$processed = 0;
$byCategory = [];

foreach ($mails as $mail) {
    // Ignorer les mails système (Proton, confirmation, etc.)
    if (strpos($mail['from_email'] ?? '', 'proton') !== false ||
        strpos($mail['from_email'] ?? '', 'noreply') !== false) {
        $stmtUpdate->execute([':summary' => 'Mail système (ignoré)', ':action' => 'aucune', ':id' => $mail['id']]);
        $processed++;
        continue;
    }

    // Déchiffrer
    $body = decrypt($mail['body_encrypted'], $mail['body_iv'], $encKey);

    // Classifier
    $classification = classifyMail($mail['subject'] ?? '', $body, $patterns);

    // Extraire élu si signalement
    $elu = null;
    if ($classification['category'] === 'signalement') {
        $elu = extractEluMention($body, $pdo);
    }

    // Résumé sécurisé
    $summary = safeSummary($body, $classification, $elu);

    // Action recommandée (JAMAIS exécutée automatiquement)
    $action = 'aucune';
    switch ($classification['category']) {
        case 'signalement':
            $action = $elu
                ? "SIGNALEMENT: vérifier la fiche de {$elu['prenom']} {$elu['nom']} (ID {$elu['id']})"
                : "SIGNALEMENT: élu non identifié, lecture manuelle requise";
            break;
        case 'rgpd':
            $action = "RGPD: demande de droit, réponse requise sous 30 jours";
            break;
        case 'juridique':
            $action = "URGENT: menace juridique, consultation requise";
            break;
        case 'spam':
            $action = "SPAM: archiver";
            break;
        case 'question':
            $action = "QUESTION: réponse manuelle suggérée";
            break;
        case 'positif':
            $action = "POSITIF: aucune action requise";
            break;
        default:
            $action = "À TRIER: lecture manuelle requise";
    }

    // Stocker
    $stmtUpdate->execute([':summary' => $summary, ':action' => $action, ':id' => $mail['id']]);
    $processed++;

    $cat = $classification['category'];
    $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;

    echo "[{$mail['id']}] {$cat} ({$classification['priority']}) — {$mail['from_email']} — " . mb_substr($mail['subject'] ?? '', 0, 50) . "\n";
    if ($action !== 'aucune') echo "  → $action\n";
}

echo "\n=== BILAN ===\n";
echo "Traités: $processed\n";
foreach ($byCategory as $cat => $nb) echo "  $cat: $nb\n";
echo "=== FIN ===\n";
