import { useState } from "react";
import { S } from "../utils/constants";

const YEARS = [
  { id: "y2005", label: "~2005",      sub: "pré-revalorisation" },
  { id: "y2010", label: "~2010",      sub: "post-décret 2007" },
  { id: "y2015", label: "~2015",      sub: "post-Hollande −30%" },
  { id: "y2020", label: "~2020",      sub: "post-PPCR locaux" },
  { id: "y2022", label: "Juil. 2022", sub: "+3,5 % FP" },
  { id: "y2024", label: "2024",       sub: "actuel", current: true },
];

const GROUPS = [
  {
    title: "Gouvernement",
    color: "#e17055",
    note: "Art. 23 de la Constitution : le mandat ministériel est le seul traitement perçu — tous les mandats électoraux sont suspendus pendant la durée des fonctions gouvernementales.",
    laws: [
      { label: "Déc. n°2002-1058 du 6 août 2002 (refonte)", url: "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000000225001" },
      { label: "Déc. n°2007-1069 du 20 juin 2007 (+50 %)", url: "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000000282459" },
      { label: "Déc. n°2012-766 du 9 mai 2012 (−30 %)", url: "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000025803706" },
    ],
    mandates: [
      { label: "Premier ministre",             values: [14500, 21300, 14910, 14910, 14910, 16038] },
      { label: "Ministre / Garde des sceaux",  values: [12000, 14200,  9940,  9940,  9940, 10692] },
      { label: "Secrétaire d'État",            values: [11000, 13490,  9443,  9443,  9443, 10692] },
    ],
  },
  {
    title: "Parlementaires nationaux",
    color: "#6c5ce7",
    note: "L'Indemnité Parlementaire de Base (IPB) est indexée sur la moyenne des traitements hors-échelle de la Fonction publique. Elle comprend l'IPB + résidence (3 % IPB) + fonction (25 %). Le point d'indice FP a été gelé de juillet 2010 à juin 2016.",
    laws: [
      { label: "Ordonnance n°58-1210 du 13 déc. 1958", url: "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000000705195" },
    ],
    mandates: [
      { label: "Député (Assemblée nationale)", values: [6700, 6980, 7127, 7210, 7240, 7637] },
      { label: "Sénateur",                    values: [6700, 6980, 7127, 7210, 7240, 7637] },
    ],
  },
  {
    title: "Parlementaires européens",
    color: "#0984e3",
    note: "Avant juillet 2009 : indemnité identique à l'IPB du député français (même ordonnance). Depuis juillet 2009 : statut unifié UE (décision 2005/684/CE) = 38,5 % du traitement d'un juge de la CJUE, revalorisé indépendamment.",
    laws: [
      { label: "Décision 2005/684/CE — Statut unifié membres PE (juil. 2009)", url: "https://eur-lex.europa.eu/legal-content/FR/TXT/?uri=CELEX:32005Q0684" },
    ],
    mandates: [
      {
        label: "Député européen",
        values: [6700, 7665, 8213, 8484, 9166, 10377],
        asterisk: "Avant juillet 2009 : = IPB député français (~6 700–6 980 €)",
      },
    ],
  },
  {
    title: "Élus régionaux",
    color: "#00b894",
    note: "Indemnité calculée en % de l'IBT (Indice Brut Terminal fonctionnaire de catégorie A). Le protocole PPCR 2016–2019 a fait passer l'IBT de l'IM 1015 à l'IM 1027, entraînant une hausse de la base de calcul de +46 %. Ce saut explique la forte augmentation visible entre 2015 et 2020.",
    laws: [
      { label: "Art. L4135-17 à L4135-19 CGCT", url: "https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006392779" },
      { label: "Protocole PPCR 2016–2019 (IBT ×1,54)", url: "https://www.fonction-publique.gouv.fr/files/files/publications/politiques_RH/textes/PPCR-accord.pdf" },
    ],
    mandates: [
      { label: "Président de région",      values: [3759, 3759, 3759, 5646, 5809, 5809] },
      { label: "Vice-président de région", values: [2265, 2265, 2265, 3402, 3500, 3500] },
      { label: "Conseiller régional",      values: [1860, 1860, 1860, 1970, 2013, 2013] },
    ],
  },
  {
    title: "Élus départementaux",
    color: "#fdcb6e",
    note: "Mêmes mécanismes que les élus régionaux (IBT, PPCR). Les montants varient selon la strate démographique du département.",
    laws: [
      { label: "Art. L3123-17 à L3123-19 CGCT", url: "https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006390620" },
    ],
    mandates: [
      { label: "Président de département",      values: [2851, 2851, 2851, 4284, 4407, 4407] },
      { label: "Vice-président de département", values: [1941, 1941, 1941, 2916, 3000, 3000] },
      { label: "Conseiller départemental",      values: [1300, 1300, 1300, 1600, 1672, 1672] },
    ],
  },
  {
    title: "Maires et élus municipaux",
    color: "#fd79a8",
    note: "L'indemnité du maire est proportionnelle à la population via un barème légal lui-même indexé sur l'IBT — les mêmes effets PPCR s'appliquent. Les montants ci-dessous sont indicatifs pour 3 strates de population. L'adjoint est exprimé pour une commune ≥ 100 000 hab.",
    laws: [
      { label: "Art. L2123-20 à L2123-24 CGCT", url: "https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000033748413" },
    ],
    mandates: [
      { label: "Maire (> 100 000 hab.)",       values: [3856, 3856, 3856, 5794, 5961, 5961] },
      { label: "Maire (20 000–50 000 hab.)",   values: [3014, 3014, 3014, 4528, 4658, 4658] },
      { label: "Maire (1 000–3 500 hab.)",     values: [1231, 1231, 1231, 1849, 1902, 1902] },
      { label: "Adjoint au maire",             values:  [ 647,  647,  647,  972, 1000, 1000] },
    ],
  },
];

const fmt = (n) =>
  typeof n === "number"
    ? new Intl.NumberFormat("fr-FR", { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(n) + " €"
    : n;

function cellStyle(values, idx) {
  if (idx === 0) return {};
  const prev = values[idx - 1];
  const curr = values[idx];
  if (curr > prev) return { background: "rgba(0,184,148,0.12)", color: S.green };
  if (curr < prev) return { background: "rgba(255,107,107,0.12)", color: S.red };
  return {};
}

function DeltaBadge({ values, idx }) {
  if (idx === 0) return null;
  const prev = values[idx - 1];
  const curr = values[idx];
  if (curr === prev) return null;
  const pct = Math.round(((curr - prev) / prev) * 100);
  const up = curr > prev;
  return (
    <span style={{
      fontSize: 10, fontWeight: 800,
      color: up ? S.green : S.red,
      background: up ? "rgba(0,184,148,0.15)" : "rgba(255,107,107,0.15)",
      borderRadius: 4, padding: "1px 4px", marginLeft: 4,
    }}>
      {up ? "+" : ""}{pct} %
    </span>
  );
}

export default function GrilleIndemnites() {
  const [openGroup, setOpenGroup] = useState(null);

  return (
    <div style={{ paddingTop: 32, paddingBottom: 64 }}>

      {/* ── HERO ── */}
      <div style={{ textAlign: "center", marginBottom: 40 }}>
        <h1 style={{
          fontFamily: S.fontTitle, fontSize: "clamp(22px, 5vw, 38px)",
          color: S.gold, margin: "0 0 12px",
          textShadow: "0 0 30px rgba(245,166,35,0.3)",
        }}>
          Grille des indemnités électorales
        </h1>
        <p style={{ color: S.textMuted, fontSize: 15, maxWidth: 640, margin: "0 auto 16px", lineHeight: 1.6 }}>
          Montants bruts mensuels utilisés par nos-elus.com pour calculer le coût cumulé de chaque élu.
          Chaque mois de mandat est comptabilisé au <strong style={{ color: S.textMain }}>taux historique réel</strong> de l'époque,
          pas au taux actuel.
        </p>
        <div style={{
          display: "inline-flex", gap: 16, flexWrap: "wrap", justifyContent: "center",
          padding: "10px 20px",
          background: "rgba(255,255,255,0.03)", borderRadius: 10,
          border: `1px solid ${S.border}`,
        }}>
          <span style={{ fontSize: 12, color: S.textMuted }}>
            <span style={{ color: S.green, fontWeight: 700 }}>↑ vert</span> = hausse vs période précédente
          </span>
          <span style={{ fontSize: 12, color: S.textMuted }}>
            <span style={{ color: S.red, fontWeight: 700 }}>↓ rouge</span> = baisse vs période précédente
          </span>
          <span style={{ fontSize: 12, color: S.textMuted }}>
            Tous les montants sont en <strong>€ brut / mois</strong>
          </span>
        </div>
      </div>

      {/* ── TIMELINE PERIODS ── */}
      <div style={{
        display: "flex", gap: 0, marginBottom: 40, overflowX: "auto",
        borderRadius: 12, border: `1px solid ${S.border}`,
        background: "rgba(255,255,255,0.02)",
      }}>
        {YEARS.map((y, i) => (
          <div key={y.id} style={{
            flex: "1 1 0", minWidth: 110, padding: "14px 12px", textAlign: "center",
            borderRight: i < YEARS.length - 1 ? `1px solid ${S.border}` : "none",
            background: y.current ? "rgba(245,166,35,0.08)" : "transparent",
          }}>
            <div style={{
              fontFamily: S.fontTitle, fontSize: 15,
              color: y.current ? S.gold : S.textMain,
            }}>{y.label}</div>
            <div style={{ fontSize: 11, color: S.textMuted, marginTop: 2 }}>{y.sub}</div>
          </div>
        ))}
      </div>

      {/* ── GROUPS ── */}
      {GROUPS.map((g) => (
        <div key={g.title} style={{
          marginBottom: 28,
          background: S.card, borderRadius: 16,
          border: `1px solid ${S.border}`,
          overflow: "hidden",
        }}>
          {/* Header */}
          <button
            onClick={() => setOpenGroup(openGroup === g.title ? null : g.title)}
            style={{
              width: "100%", textAlign: "left",
              display: "flex", alignItems: "center", gap: 12,
              padding: "18px 24px",
              background: "transparent", border: "none", cursor: "pointer",
              borderBottom: openGroup === g.title ? `1px solid ${S.border}` : "none",
            }}
          >
            <span style={{
              width: 6, height: 32, borderRadius: 3,
              background: g.color, flexShrink: 0,
            }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontFamily: S.fontTitle, fontSize: 17, color: S.textMain }}>
                {g.title}
              </div>
              <div style={{ fontSize: 12, color: S.textMuted, marginTop: 2 }}>
                {g.mandates.length} type{g.mandates.length > 1 ? "s" : ""} de mandat
              </div>
            </div>
            <span style={{ color: S.textMuted, fontSize: 20, transition: "transform 0.2s",
              transform: openGroup === g.title ? "rotate(90deg)" : "none" }}>›</span>
          </button>

          {/* Content (always visible) */}
          <div>
            {/* Table */}
            <div style={{ overflowX: "auto" }}>
              <table style={{ width: "100%", borderCollapse: "collapse", minWidth: 680 }}>
                <thead>
                  <tr>
                    <th style={{
                      textAlign: "left", padding: "12px 24px",
                      fontSize: 12, color: S.textMuted, fontWeight: 700,
                      background: "rgba(255,255,255,0.02)",
                      borderBottom: `1px solid ${S.border}`,
                      position: "sticky", left: 0,
                      backdropFilter: "blur(4px)",
                    }}>
                      Mandat
                    </th>
                    {YEARS.map((y) => (
                      <th key={y.id} style={{
                        padding: "10px 16px", textAlign: "right",
                        fontSize: 12, color: y.current ? S.gold : S.textMuted,
                        fontWeight: 700,
                        background: y.current ? "rgba(245,166,35,0.05)" : "rgba(255,255,255,0.02)",
                        borderBottom: `1px solid ${S.border}`,
                        whiteSpace: "nowrap",
                      }}>
                        {y.label}
                        <div style={{ fontSize: 10, fontWeight: 400, color: S.textDim, marginTop: 1 }}>{y.sub}</div>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {g.mandates.map((m, ri) => (
                    <tr key={m.label} style={{
                      borderBottom: ri < g.mandates.length - 1 ? `1px solid ${S.border}` : "none",
                    }}>
                      <td style={{
                        padding: "13px 24px", fontSize: 13, color: S.textMain,
                        fontWeight: 600, background: S.card,
                        position: "sticky", left: 0,
                        borderRight: `1px solid ${S.border}`,
                        minWidth: 220,
                      }}>
                        {m.label}
                        {m.asterisk && (
                          <div style={{ fontSize: 10, color: S.textDim, marginTop: 2, fontWeight: 400 }}>
                            * {m.asterisk}
                          </div>
                        )}
                      </td>
                      {m.values.map((v, ci) => (
                        <td key={ci} style={{
                          padding: "13px 16px", textAlign: "right",
                          fontSize: 13, fontWeight: 700,
                          ...cellStyle(m.values, ci),
                          background: YEARS[ci].current
                            ? "rgba(245,166,35,0.05)"
                            : cellStyle(m.values, ci).background || "transparent",
                        }}>
                          {fmt(v)}
                          <DeltaBadge values={m.values} idx={ci} />
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Note + laws */}
            {(openGroup === g.title) && (
              <div style={{ padding: "18px 24px", borderTop: `1px solid ${S.border}` }}>
                <p style={{ fontSize: 13, color: S.textMuted, margin: "0 0 12px", lineHeight: 1.65 }}>
                  {g.note}
                </p>
                <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
                  {g.laws.map((l) => (
                    <a key={l.label} href={l.url} target="_blank" rel="noopener noreferrer" style={{
                      fontSize: 11, color: g.color,
                      background: `${g.color}18`,
                      border: `1px solid ${g.color}40`,
                      borderRadius: 6, padding: "4px 10px",
                      textDecoration: "none", fontWeight: 600,
                    }}>
                      📄 {l.label}
                    </a>
                  ))}
                </div>
              </div>
            )}

            {/* Toggle note/laws */}
            <button onClick={() => setOpenGroup(openGroup === g.title ? null : g.title)}
              style={{
                display: "block", width: "100%", padding: "10px",
                background: "transparent", border: "none",
                borderTop: `1px solid ${S.border}`,
                cursor: "pointer", fontSize: 11, color: S.textDim,
                textAlign: "center",
              }}>
              {openGroup === g.title ? "▲ Masquer les références légales" : "▼ Voir les références légales et notes"}
            </button>
          </div>
        </div>
      ))}

      {/* ── RÉCAPITULATIF ÉVÉNEMENTS CLÉS ── */}
      <div style={{
        marginTop: 40, padding: "28px 28px",
        background: S.card, borderRadius: 16, border: `1px solid ${S.border}`,
      }}>
        <h2 style={{ fontFamily: S.fontTitle, fontSize: 18, color: S.gold, margin: "0 0 20px" }}>
          Chronologie des réformes majeures
        </h2>
        <div style={{ display: "flex", flexDirection: "column", gap: 0 }}>
          {[
            { date: "Août 2002",    color: "#e17055", event: "Décret n°2002-1058 — refonte du traitement gouvernemental (PM, ministres, secrétaires d'État)" },
            { date: "Juil. 2007",   color: "#e17055", event: "Décret n°2007-1069 — revalorisation +50 % du traitement du Premier ministre et des ministres" },
            { date: "Juil. 2009",   color: "#0984e3", event: "Statut unifié des eurodéputés (décision 2005/684/CE) — rupture totale avec l'IPB française" },
            { date: "Juil. 2010",   color: "#6c5ce7", event: "Gel du point d'indice FP — l'IPB des parlementaires nationaux est gelée jusqu'en 2016" },
            { date: "Mai 2012",     color: "#e17055", event: "Décrets n°2012-766/983 — baisse de −30 % du traitement gouvernemental (décision Hollande)" },
            { date: "Juil. 2016–\nfév. 2017", color: "#6c5ce7", event: "Dégel partiel du point FP (+0,6 % × 2) — légère hausse de l'IPB parlementaire" },
            { date: "2017–2019",    color: "#00b894", event: "Protocole PPCR — l'Indice Brut Terminal passe de l'IM 1015 à 1027 : +46 % pour les élus locaux (régionaux, départementaux, maires)" },
            { date: "Juil. 2022",   color: "#6c5ce7", event: "Revalorisation du point d'indice FP +3,5 % — hausse de l'IPB parlementaire et des indemnités locales indexées" },
            { date: "Janv. 2024",   color: S.gold, event: "Dernière revalorisation : IPB = 7 637 €, ministre = 10 692 €, eurodéputé = 10 377 €" },
            { date: "Avr. 2025",    color: "#0984e3", event: "Revalorisation statut PE — eurodéputé = 10 927 €" },
          ].map((e, i, arr) => (
            <div key={i} style={{ display: "flex", gap: 16, paddingBottom: i < arr.length - 1 ? 0 : 0 }}>
              <div style={{ display: "flex", flexDirection: "column", alignItems: "center", width: 2, flexShrink: 0, marginLeft: 7 }}>
                <div style={{ width: 14, height: 14, borderRadius: "50%", background: e.color, flexShrink: 0, marginTop: 16 }} />
                {i < arr.length - 1 && <div style={{ flex: 1, width: 2, background: S.border, minHeight: 24 }} />}
              </div>
              <div style={{ paddingTop: 10, paddingBottom: i < arr.length - 1 ? 16 : 0, flex: 1 }}>
                <span style={{ fontSize: 11, fontWeight: 800, color: e.color, letterSpacing: "0.04em" }}>{e.date}</span>
                <p style={{ margin: "2px 0 0", fontSize: 13, color: S.textMuted, lineHeight: 1.5 }}>{e.event}</p>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* ── NOTE MÉTHODOLOGIQUE ── */}
      <div style={{
        marginTop: 24, padding: "20px 24px",
        background: "rgba(245,166,35,0.05)", borderRadius: 12,
        border: `1px solid rgba(245,166,35,0.2)`,
      }}>
        <h3 style={{ fontFamily: S.fontTitle, fontSize: 14, color: S.gold, margin: "0 0 8px" }}>Note méthodologique</h3>
        <p style={{ fontSize: 12, color: S.textMuted, margin: 0, lineHeight: 1.7 }}>
          Les montants affichés sont des <strong style={{ color: S.textMain }}>indemnités brutes de charges patronales</strong>, hors cotisations salariales,
          hors frais de représentation et hors avances sur frais remboursables (ex-IRFM supprimée en 2017).
          Pour les maires, l'indemnité réelle dépend de la population de la commune selon le barème de l'Art. L2123-23 CGCT.
          Les valeurs historiques antérieures à 2002 sont des estimations issues de l'indexation sur le point d'indice FP
          et ne sont fournies qu'à titre indicatif. Source des taux actuels : fiches officielles AN, Sénat, et
          Parlement européen (2024).
        </p>
      </div>

    </div>
  );
}
