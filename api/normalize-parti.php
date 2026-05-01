<?php
/**
 * Normalisation des noms de partis politiques
 * Utilisé par tous les scripts d'import et d'enrichissement
 */

function normalizeParti(?string $parti): ?string {
    if (!$parti || trim($parti) === '') return null;
    $p = trim($parti);

    // Table de correspondance sigle → nom officiel
    $map = [
        // Extrême gauche
        'NPA' => 'Nouveau Parti Anticapitaliste',
        'LO' => 'Lutte Ouvrière',

        // Gauche radicale
        'LFI' => 'La France insoumise',
        'FI' => 'La France insoumise',
        'PCF' => 'Parti communiste français',
        'GDR' => 'Parti communiste français',

        // Gauche
        'PS' => 'Parti socialiste',
        'PS-PP' => 'Parti socialiste',
        'Socialistes et apparentés' => 'Parti socialiste',
        'PRG' => 'Parti radical de gauche',

        // Écologistes
        'EELV' => 'Les Écologistes',
        'EE-LV' => 'Les Écologistes',
        'Europe Écologie' => 'Les Écologistes',
        'Europe Écologie Les Verts' => 'Les Écologistes',
        'Europe Écologie-Les Verts' => 'Les Écologistes',
        'Les Écologistes – Europe Écologie Les Verts' => 'Les Écologistes',

        // Centre
        'MoDem' => 'Mouvement Démocrate',
        'MODEM' => 'Mouvement Démocrate',
        'UDI' => 'Union des Démocrates et Indépendants',
        'HOR' => 'Horizons',

        // Majorité présidentielle
        'LREM' => 'Renaissance',
        'En Marche' => 'Renaissance',
        'La République En Marche' => 'Renaissance',
        'REN' => 'Renaissance',
        'ENS' => 'Ensemble',
        'LR / Renaissance' => 'Renaissance',

        // Droite
        'LR' => 'Les Républicains',
        'UMP' => 'Les Républicains',
        'RPR' => 'Les Républicains',
        'Union pour un mouvement populaire' => 'Les Républicains',
        'Rassemblement pour la République' => 'Les Républicains',
        'UDF' => 'Union pour la démocratie française',

        // Extrême droite
        'RN' => 'Rassemblement national',
        'FN' => 'Rassemblement national',
        'REC' => 'Reconquête',
        'Reconquête !' => 'Reconquête',
        'UPR' => 'Union populaire républicaine',
        'DLF' => 'Debout la France',
    ];

    // Match exact
    if (isset($map[$p])) return $map[$p];

    // Match case-insensitive
    $pLower = mb_strtolower($p);
    foreach ($map as $key => $value) {
        if (mb_strtolower($key) === $pLower) return $value;
    }

    // Si c'est déjà un nom complet connu, retourner tel quel
    $known = [
        'La France insoumise', 'Rassemblement national', 'Les Républicains',
        'Parti socialiste', 'Les Écologistes', 'Renaissance', 'Ensemble',
        'Mouvement Démocrate', 'Horizons', 'Reconquête', 'Parti communiste français',
        'Parti radical de gauche', 'Nouveau Parti Anticapitaliste', 'Lutte Ouvrière',
        'Debout la France', 'Union des Démocrates et Indépendants',
        'Régions et peuples solidaires', 'LIOT', 'Place publique',
        'Sans étiquette', 'Alliance centriste', 'Résistons',
    ];
    foreach ($known as $k) {
        if (mb_strtolower($k) === $pLower) return $k;
    }

    // Sinon retourner tel quel (parti local, outre-mer, etc.)
    return $p;
}
