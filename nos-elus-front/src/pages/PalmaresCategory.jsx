import { useState, useEffect } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import Avatar from "../components/Avatar";
import { useApi } from "../hooks/useApi";

const MEDALS = ["🥇", "🥈", "🥉"];

const CATEGORY_META = {
  top_cout:               { title: "Coût pour le contribuable",           icon: "💸", color: S.red,    valueLabel: "Coût carrière" },
  top_carriere:           { title: "Plus longue carrière",                icon: "⏳", color: S.gold,   valueLabel: "Années" },
  top_mandats:            { title: "Plus de mandats",                     icon: "🏅", color: S.purple, valueLabel: "Mandats" },
  top_cumulards:          { title: "Cumulards",                           icon: "🤹", color: "#e17a2d", valueLabel: "Mandats actifs" },
  top_casseroles:         { title: "Casseroles",                         icon: "🍳", color: S.red,    valueLabel: "Affaires" },
  top_jeunes:             { title: "Plus jeunes élus",                    icon: "🧑", color: S.green,  valueLabel: "Âge" },
  top_doyens:             { title: "Doyens",                              icon: "👴", color: S.blue,   valueLabel: "Âge" },
  top_salaires:           { title: "Plus hauts salaires",                 icon: "💰", color: S.green,  valueLabel: "Salaire brut" },
  top_assidus_deputes:    { title: "Députés les plus présents",           icon: "🗳️", color: S.green,  valueLabel: "Activité" },
  top_absents_deputes:    { title: "Députés les moins présents",          icon: "🗳️", color: S.gold,   valueLabel: "Activité" },
  top_assidus_europeens:  { title: "Eurodéputés les plus présents",       icon: "🇪🇺", color: S.green,  valueLabel: "Activité" },
  top_absents_europeens:  { title: "Eurodéputés les moins présents",      icon: "🇪🇺", color: S.gold,   valueLabel: "Activité" },
  top_assidus_senateurs:  { title: "Sénateurs les plus actifs",           icon: "🏛️", color: S.blue,   valueLabel: "Questions" },
  top_absents_senateurs:  { title: "Sénateurs les moins actifs",          icon: "🏛️", color: S.gold,   valueLabel: "Questions" },
};

const fmtCout = (n) => {
  if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M€";
  if (n >= 1e3) return Math.round(n / 1e3) + "k€";
  return new Intl.NumberFormat("fr-FR").format(n) + "€";
};

function renderValue(cat, e) {
  switch (cat) {
    case "top_cout":      return fmtCout(e.cout_total);
    case "top_carriere":  return e.annees_carriere ? `${e.annees_carriere} ans` : "?";
    case "top_mandats":   return `${e.nb_mandats} mandats`;
    case "top_cumulards": return `${e.nb_mandats_actifs} en cours`;
    case "top_casseroles": {
      const c = parseInt(e.nb_condamne) || 0;
      const ec = parseInt(e.nb_en_cours) || 0;
      const parts = [];
      if (c > 0) parts.push(`${c} condamné`);
      if (ec > 0) parts.push(`${ec} en cours`);
      return parts.join(" + ") || `${e.nb_affaires} affaires`;
    }
    case "top_jeunes":
    case "top_doyens":    return e.age ? `${e.age} ans` : "?";
    case "top_salaires":  return new Intl.NumberFormat("fr-FR").format(e.salaire_mensuel) + "€/mois";
    case "top_assidus_deputes":
    case "top_absents_deputes":
    case "top_assidus_europeens":
    case "top_absents_europeens": return `${Math.round(e.taux_global)}% présence`;
    case "top_assidus_senateurs":
    case "top_absents_senateurs": return `${e.nb_questions} activités`;
    default: return "";
  }
}

const PARTI_COLORS = {
  "RN": "#003189", "Rassemblement National": "#003189",
  "LFI": "#CC2529", "La France insoumise": "#CC2529", "France Insoumise": "#CC2529",
  "PS": "#FF8EB4", "Parti socialiste": "#FF8EB4", "Socialiste": "#FF8EB4",
  "LR": "#0066CC", "Les Républicains": "#0066CC", "Républicains": "#0066CC",
  "LREM": "#FFCC00", "Renaissance": "#FFCC00", "Ensemble": "#FFCC00", "RE": "#FFCC00",
  "MoDem": "#FF6600", "Mouvement Démocrate": "#FF6600",
  "Horizons": "#00B7EB",
  "Les Écologistes": "#2e7d32", "EELV": "#2e7d32", "Écologiste": "#2e7d32", "Verts": "#00A550",
  "PCF": "#DD0000", "Parti communiste": "#DD0000", "Communiste": "#DD0000",
  "UDI": "#0078A0", "Union des Démocrates": "#0078A0",
  "LIOT": "#8E44AD",
  "Parti radical": "#e91e63", "PRG": "#e91e63", "Radical": "#e91e63",
  "Divers droite": "#4A90D9", "DVD": "#4A90D9",
  "Divers gauche": "#E88E8E", "DVG": "#E88E8E",
  "Divers centre": "#F5A623", "DVC": "#F5A623",
  "Sans étiquette": "#888", "SE": "#888",
  "Reconquête": "#1a1a2e", "Reconquête!": "#1a1a2e",
  "Droite Républicaine": "#0066CC",
};
function partiColor(parti, eluCouleur) {
  if (eluCouleur && eluCouleur !== "#888" && eluCouleur !== "#000") return eluCouleur;
  if (!parti) return S.border;
  if (PARTI_COLORS[parti]) return PARTI_COLORS[parti];
  for (const [k, v] of Object.entries(PARTI_COLORS)) {
    if (parti.includes(k) || k.includes(parti)) return v;
  }
  return S.gold;
}

export default function PalmaresCategory() {
  const { category } = useParams();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);

  // URL uses dashes, API uses underscores
  const catKey = category.replace(/-/g, "_");
  const meta = CATEGORY_META[catKey] || { title: catKey, icon: "🏅", color: S.gold, valueLabel: "Valeur" };

  const { data, loading, error } = useApi("palmares.php", { category: catKey, limit: 50, page });

  // Reset page when category changes
  useEffect(() => { setPage(1); }, [catKey]);

  // Scroll to top when page changes
  useEffect(() => { window.scrollTo({ top: 0, behavior: "smooth" }); }, [page]);

  const color = meta.color;

  const spinner = (
    <div style={{ paddingTop: 60, textAlign: "center", color: S.textDim }}>
      <div style={{
        display: "inline-block", width: 32, height: 32, borderRadius: "50%",
        border: `3px solid ${S.border}`, borderTopColor: S.gold,
        animation: "searchSpin 0.6s linear infinite",
      }} />
    </div>
  );

  return (
    <div style={{ maxWidth: 760, margin: "0 auto", paddingTop: 32, paddingBottom: 80 }}>
      {/* Bouton retour */}
      <button
        onClick={() => navigate("/palmares")}
        style={{
          display: "inline-flex", alignItems: "center", gap: 6,
          background: "none", border: `1px solid ${S.border}`, borderRadius: 8,
          fontFamily: S.font, fontSize: 13, color: S.textDim, cursor: "pointer",
          padding: "8px 14px", marginBottom: 24, transition: "all 0.15s",
        }}
        onMouseEnter={e => { e.currentTarget.style.borderColor = color; e.currentTarget.style.color = color; }}
        onMouseLeave={e => { e.currentTarget.style.borderColor = S.border; e.currentTarget.style.color = S.textDim; }}
      >
        ← Retour au palmarès
      </button>

      {/* Header */}
      <div style={{ textAlign: "center", marginBottom: 28 }}>
        <span style={{ fontSize: 48, display: "block", marginBottom: 8 }}>{meta.icon}</span>
        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(28px,6vw,44px)", color,
          lineHeight: 1.1, letterSpacing: 2,
        }}>{meta.title}</div>
        {data?.total && (
          <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginTop: 8 }}>
            {data.total} élus classés
          </div>
        )}
      </div>

      {loading && spinner}
      {error && (
        <div style={{ textAlign: "center", color: S.red, fontFamily: S.font, marginTop: 40 }}>
          Impossible de charger ce classement.
        </div>
      )}

      {!loading && !error && data?.data && (
        <>
          {/* Liste */}
          <div style={{
            background: S.card, borderRadius: 18,
            border: `1px solid ${color}22`, overflow: "hidden",
          }}>
            {data.data.map((item, i) => {
              const globalRank = ((page - 1) * (data.per_page || 50)) + i;
              const displayName = item.prenom ? `${item.prenom} ${item.nom}` : item.nom;
              const slug = item.slug || displayName.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-");
              const isTop3 = globalRank < 3;
              const pc = partiColor(item.parti, item.couleur);

              return (
                <div
                  key={item.id || i}
                  onClick={() => navigate(`/elu/${slug}`)}
                  style={{
                    display: "flex", alignItems: "center", gap: 12,
                    padding: "12px 16px",
                    borderBottom: `1px solid rgba(255,255,255,0.04)`,
                    cursor: "pointer", transition: "background 0.12s",
                    background: isTop3 ? `${color}06` : "transparent",
                    minHeight: 44,
                  }}
                  onMouseEnter={e => { e.currentTarget.style.background = `${color}10`; }}
                  onMouseLeave={e => { e.currentTarget.style.background = isTop3 ? `${color}06` : "transparent"; }}
                >
                  {/* Rang */}
                  <span style={{
                    fontFamily: S.fontTitle,
                    fontSize: isTop3 ? 24 : 15,
                    width: 36, textAlign: "center", flexShrink: 0,
                    color: isTop3 ? color : S.textDim,
                  }}>
                    {MEDALS[globalRank] || `${globalRank + 1}`}
                  </span>

                  {/* Avatar */}
                  <Avatar elu={item} size={isTop3 ? 96 : 72} />

                  {/* Nom + meta */}
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{
                      fontFamily: S.font, fontSize: isTop3 ? 15 : 13,
                      fontWeight: 800, color: S.textMain,
                      whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                    }}>{displayName}</div>
                    <div style={{ display: "flex", alignItems: "center", gap: 6, marginTop: 2, flexWrap: "wrap" }}>
                      {item.parti && (
                        <span style={{
                          fontFamily: S.font, fontSize: 12, fontWeight: 700,
                          background: `${pc}22`, color: pc, borderRadius: 99,
                          padding: "2px 9px", border: `1px solid ${pc}44`,
                          whiteSpace: "nowrap",
                        }}>{item.parti}</span>
                      )}
                      {item.fonction && (
                        <span style={{
                          fontFamily: S.font, fontSize: 10, color: S.textDim,
                          whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 160,
                        }}>{item.fonction}</span>
                      )}
                    </div>
                  </div>

                  {/* Valeur */}
                  <div style={{
                    fontFamily: S.fontTitle,
                    fontSize: isTop3 ? 17 : 13,
                    color, whiteSpace: "nowrap", flexShrink: 0,
                    textAlign: "right",
                  }}>
                    {renderValue(catKey, item)}
                  </div>
                </div>
              );
            })}
          </div>

          {/* Pagination */}
          {data.pages > 1 && (() => {
            const total = data.pages;
            // Afficher toutes les pages si <= 10, sinon afficher les voisines + première/dernière
            let pageNums = [];
            if (total <= 12) {
              pageNums = Array.from({ length: total }, (_, i) => i + 1);
            } else {
              const s = new Set([1, 2, total - 1, total]);
              for (let i = Math.max(1, page - 2); i <= Math.min(total, page + 2); i++) s.add(i);
              pageNums = [...s].sort((a, b) => a - b);
            }

            const btnStyle = (active, disabled) => ({
              padding: "8px 12px", borderRadius: 8, minWidth: 40, minHeight: 40,
              background: active ? color : disabled ? "rgba(255,255,255,0.02)" : "rgba(255,255,255,0.04)",
              border: `1px solid ${active ? color : S.border}`,
              fontFamily: S.font, fontSize: 13, fontWeight: 700,
              color: active ? "#fff" : disabled ? S.textDim : S.textMain,
              cursor: disabled ? "default" : "pointer", transition: "all 0.15s",
            });

            return (
              <div style={{
                display: "flex", alignItems: "center", justifyContent: "center",
                gap: 6, marginTop: 24, flexWrap: "wrap",
              }}>
                <button disabled={page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))} style={btnStyle(false, page <= 1)}>←</button>

                {pageNums.map((p, i) => {
                  const showEllipsis = i > 0 && p - pageNums[i - 1] > 1;
                  return (
                    <span key={p} style={{ display: "flex", alignItems: "center", gap: 6 }}>
                      {showEllipsis && <span style={{ color: S.textDim, fontSize: 13 }}>…</span>}
                      <button onClick={() => setPage(p)} style={btnStyle(p === page, false)}>{p}</button>
                    </span>
                  );
                })}

                <button disabled={page >= total} onClick={() => setPage(p => Math.min(total, p + 1))} style={btnStyle(false, page >= total)}>→</button>
              </div>
            );
          })()}
        </>
      )}
    </div>
  );
}
