#!/usr/bin/env node
/**
 * Import RNE (Répertoire National des Élus) + Enrichissement NosDéputés.fr
 *
 * Usage :
 *   node scripts/import-rne.mjs                    # Import députés + sénateurs
 *   node scripts/import-rne.mjs --maires           # + maires (34k)
 *   node scripts/import-rne.mjs --enrichir         # Enrichir via NosDéputés.fr
 *   node scripts/import-rne.mjs --all              # Tout
 *   node scripts/import-rne.mjs --dry-run          # Simuler, écrire un JSON local
 *
 * En dry-run : génère scripts/output/ avec les données parsées (pas besoin de MySQL)
 */

import { writeFileSync, mkdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

// ── Config ──
const CSV_URLS = {
  deputes:   'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104106/elus-deputes-dep.csv',
  senateurs: 'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104017/elus-senateurs-sen.csv',
  maires:    'https://static.data.gouv.fr/resources/repertoire-national-des-elus-1/20251223-104211/elus-maires-mai.csv',
};
const NOSDEPUTES = 'https://www.nosdeputes.fr';

// ── Args ──
const args = process.argv.slice(2);
const dryRun   = args.includes('--dry-run');
const doMaires = args.includes('--maires') || args.includes('--all');
const doEnrich = args.includes('--enrichir') || args.includes('--all');

// ── Helpers ──
function info(msg) { console.log(`${new Date().toLocaleTimeString('fr')} [INFO] ${msg}`); }
function error(msg) { console.error(`${new Date().toLocaleTimeString('fr')} [ERREUR] ${msg}`); }

function slugify(text) {
  return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function parseDateFr(d) {
  if (!d) return null;
  const parts = d.trim().split('/');
  if (parts.length === 3) return `${parts[2]}-${parts[1]}-${parts[0]}`;
  return null;
}

function parseCsvLine(line) {
  const result = [];
  let current = '';
  let inQuotes = false;
  for (const char of line) {
    if (char === '"') { inQuotes = !inQuotes; continue; }
    if (char === ';' && !inQuotes) { result.push(current.trim()); current = ''; continue; }
    current += char;
  }
  result.push(current.trim());
  return result;
}

function titleCase(str) {
  return str.toLowerCase().split(/[\s-]+/).map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

// ── Import CSV ──
async function importCsv(type, url) {
  info(`\n--- Import ${type} ---`);
  info(`URL : ${url}`);

  const start = Date.now();
  const res = await fetch(url);
  if (!res.ok) { error(`HTTP ${res.status}`); return []; }
  const raw = await res.text();
  info(`Téléchargé : ${Math.round(raw.length / 1024)} Ko en ${((Date.now() - start) / 1000).toFixed(1)}s`);

  const lines = raw.split('\n').filter(l => l.trim());
  const header = parseCsvLine(lines.shift());

  const elus = [];
  let skipped = 0;

  for (const line of lines) {
    const cols = parseCsvLine(line);
    if (cols.length < header.length) { skipped++; continue; }

    const row = {};
    header.forEach((h, i) => { row[h] = cols[i] || ''; });

    const elu = mapRow(row, type);
    if (!elu.nom) { skipped++; continue; }
    elus.push(elu);
  }

  info(`Parsé : ${elus.length} élus, ${skipped} ignorés`);
  return elus;
}

function mapRow(row, type) {
  const nom    = row["Nom de l'élu"] || '';
  const prenom = row["Prénom de l'élu"] || '';
  const dept   = row['Code du département'] || '';
  const deptNom = row['Libellé du département'] || '';
  const sexe   = row['Code sexe'] || '';
  const dob    = row['Date de naissance'] || '';
  const csp    = row['Libellé de la catégorie socio-professionnelle'] || '';
  const debut  = row['Date de début du mandat'] || '';
  const circo  = row['Libellé de la circonscription législative'] || '';
  const commune = row['Libellé de la commune'] || '';

  const nomFormatted = titleCase(nom);
  const prenomFormatted = titleCase(prenom);

  const fonctions = {
    depute: `Député${sexe === 'F' ? 'e' : ''} — ${deptNom}${circo ? ` (${circo})` : ''}`,
    senateur: `Sénateur${sexe === 'F' ? 'rice' : ''} — ${deptNom}`,
    maire: `Maire — ${commune || deptNom}`,
  };

  return {
    nom: nomFormatted,
    prenom: prenomFormatted,
    slug: slugify(`${prenomFormatted} ${nomFormatted}`),
    parti: null,
    fonction: fonctions[type] || type,
    date_naissance: parseDateFr(dob),
    departement: dept,
    type_mandat: type,
    source_api: 'rne',
    source_id: slugify(`${nom}-${prenom}-${dob}`),
    csp,
    sexe,
    mandat_debut: parseDateFr(debut),
  };
}

// ── Enrichissement NosDéputés.fr ──
async function enrichirDeputes(deputes) {
  info(`\n=== Enrichissement NosDéputés.fr ===`);
  info(`Députés à enrichir : ${deputes.length}`);

  let enriched = 0;
  let errors = 0;

  for (const dep of deputes) {
    const ndSlug = slugify(`${dep.prenom} ${dep.nom}`);
    try {
      const res = await fetch(`${NOSDEPUTES}/${ndSlug}/json`, { signal: AbortSignal.timeout(5000) });
      if (!res.ok) { errors++; continue; }

      const data = await res.json();
      const d = data.depute;
      if (!d) { errors++; continue; }

      dep.photo_url = `${NOSDEPUTES}/depute/photo/${ndSlug}/120`;
      dep.parti = d.parti_ratt_financier || d.groupe_sigle || dep.parti;
      dep.groupe = d.groupe_sigle || null;
      dep.lieu_naissance = d.lieu_naissance || null;
      dep.nb_mandats = (d.anciens_mandats || []).length;
      dep.mandats_detail = (d.anciens_mandats || []).map(m => {
        const mandat = m.mandat || m;
        return { titre: mandat.mandat || mandat.titre, debut: mandat.date_debut, fin: mandat.date_fin };
      });

      enriched++;
      if (enriched % 20 === 0) info(`  ... ${enriched} enrichis`);

    } catch (e) {
      errors++;
    }

    // Politesse : 300ms entre chaque requête
    await new Promise(r => setTimeout(r, 300));
  }

  info(`Enrichis : ${enriched} / ${deputes.length} (${errors} non trouvés)`);
  return deputes;
}

// ── SQL Generator ──
function generateSql(allElus) {
  const lines = [];
  lines.push('-- ══════════════════════════════════════');
  lines.push('-- Import RNE — Généré le ' + new Date().toISOString().split('T')[0]);
  lines.push(`-- Total : ${allElus.length} élus`);
  lines.push('-- ══════════════════════════════════════');
  lines.push('');

  // Batch insert elus (par lots de 100)
  for (let i = 0; i < allElus.length; i += 100) {
    const batch = allElus.slice(i, i + 100);
    lines.push(`-- Batch ${Math.floor(i/100) + 1}`);
    lines.push('INSERT INTO elus (nom, prenom, parti, fonction, slug, departement, type_mandat, date_naissance, photo_url, source_api, source_id, derniere_sync, actif) VALUES');

    const rows = batch.map(e => {
      const esc = (s) => s ? `'${s.replace(/'/g, "''")}'` : 'NULL';
      return `(${esc(e.nom)}, ${esc(e.prenom)}, ${esc(e.parti)}, ${esc(e.fonction)}, ${esc(e.slug)}, ${esc(e.departement)}, ${esc(e.type_mandat)}, ${esc(e.date_naissance)}, ${esc(e.photo_url || null)}, 'rne', ${esc(e.source_id)}, NOW(), 1)`;
    });
    lines.push(rows.join(',\n'));
    lines.push('ON DUPLICATE KEY UPDATE nom=VALUES(nom), prenom=VALUES(prenom), fonction=VALUES(fonction), parti=COALESCE(VALUES(parti),parti), photo_url=COALESCE(VALUES(photo_url),photo_url), derniere_sync=NOW();');
    lines.push('');
  }

  // Mandats pour les enrichis
  const withMandats = allElus.filter(e => e.mandats_detail?.length > 0);
  if (withMandats.length > 0) {
    lines.push('-- Mandats (enrichis via NosDéputés.fr)');
    for (const e of withMandats) {
      for (const m of e.mandats_detail) {
        const esc = (s) => s ? `'${s.replace(/'/g, "''")}'` : 'NULL';
        lines.push(`INSERT IGNORE INTO mandats (elu_id, titre, date_debut, date_fin, institution) SELECT id, ${esc(m.titre)}, ${esc(m.debut)}, ${esc(m.fin)}, 'Assemblée nationale' FROM elus WHERE slug = ${esc(e.slug)} LIMIT 1;`);
      }
    }
  }

  return lines.join('\n');
}

// ── Main ──
async function main() {
  info('=== Import RNE ===');
  if (dryRun) info('Mode dry-run — génération de fichiers locaux');

  let allElus = [];

  // Députés
  const deputes = await importCsv('depute', CSV_URLS.deputes);
  allElus.push(...deputes);

  // Sénateurs
  const senateurs = await importCsv('senateur', CSV_URLS.senateurs);
  allElus.push(...senateurs);

  // Maires
  if (doMaires) {
    const maires = await importCsv('maire', CSV_URLS.maires);
    allElus.push(...maires);
  }

  info(`\nTotal : ${allElus.length} élus`);

  // Enrichissement
  if (doEnrich) {
    await enrichirDeputes(deputes);
  }

  // Output
  const outDir = join(__dirname, 'output');
  mkdirSync(outDir, { recursive: true });

  // JSON
  const jsonPath = join(outDir, 'elus-rne.json');
  writeFileSync(jsonPath, JSON.stringify(allElus, null, 2));
  info(`JSON → ${jsonPath} (${Math.round(JSON.stringify(allElus).length / 1024)} Ko)`);

  // SQL
  const sqlPath = join(outDir, 'import-rne.sql');
  writeFileSync(sqlPath, generateSql(allElus));
  info(`SQL  → ${sqlPath}`);

  // Stats
  info('\n=== Résumé ===');
  info(`Députés    : ${deputes.length}`);
  info(`Sénateurs  : ${senateurs.length}`);
  if (doMaires) info(`Maires     : ${allElus.length - deputes.length - senateurs.length}`);
  const withPhotos = allElus.filter(e => e.photo_url).length;
  const withParti = allElus.filter(e => e.parti).length;
  info(`Avec photo : ${withPhotos}`);
  info(`Avec parti : ${withParti}`);
  info('\nTerminé.');
}

main().catch(e => { error(e.message); process.exit(1); });
