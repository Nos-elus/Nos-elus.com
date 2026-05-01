import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import Avatar from "../components/Avatar";
import { useApi } from "../hooks/useApi";

const fmt = (n) => {
  if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M€";
  if (n >= 1e3) return Math.round(n / 1e3) + "k€";
  return new Intl.NumberFormat("fr-FR").format(n) + "€";
};

const MEDALS = ["🥇", "🥈", "🥉"];

const PalmaresCard = ({ title, icon, color, data, renderValue, navigate, categoryKey }) => {
  const [expanded, setExpanded] = useState(false);
  if (!data || data.length === 0) return null;
  const shown = expanded ? data : data.slice(0, 5);

  return (
    <div style={{
      background: S.card, borderRadius: 18, padding: "22px 20px",
      border: `1px solid ${color}22`, overflow: "hidden",
    }}>
      <div style={{
        display: "flex", alignItems: "center", gap: 10, marginBottom: 16,
      }}>
        <span style={{ fontSize: 26 }}>{icon}</span>
        <div style={{ fontFamily: S.fontTitle, fontSize: 20, color, flex: 1 }}>{title}</div>
      </div>

      <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
        {shown.map((item, i) => {
          const displayName = item.prenom ? `${item.prenom} ${item.nom}` : item.nom;
          const slug = item.slug || displayName.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-");
          const isTop3 = i < 3;

          return (
            <div key={item.id || i}
              onClick={() => navigate(`/elu/${slug}`)}
              style={{
                display: "flex", alignItems: "center", gap: 10, padding: "9px 10px",
                borderRadius: 8, cursor: "pointer", transition: "all 0.12s",
                background: isTop3 ? `${color}06` : "transparent",
              }}
              onMouseEnter={e => { e.currentTarget.style.background = `${color}12`; }}
              onMouseLeave={e => { e.currentTarget.style.background = isTop3 ? `${color}06` : "transparent"; }}
            >
              <span style={{
                fontFamily: S.fontTitle, fontSize: isTop3 ? 22 : 16,
                width: 30, textAlign: "center", color: isTop3 ? color : S.textDim,
              }}>
                {MEDALS[i] || `${i + 1}`}
              </span>
              <Avatar elu={item} size={isTop3 ? 56 : 42} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{
                  fontFamily: S.font, fontSize: isTop3 ? 15 : 13, fontWeight: 800, color: S.textMain,
                  whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                }}>{displayName}</div>
                <div style={{
                  fontFamily: S.font, fontSize: 11, color: S.textDim,
                  whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                }}>{item.parti || item.fonction}</div>
              </div>
              <div style={{
                fontFamily: S.fontTitle, fontSize: isTop3 ? 18 : 14, color,
                whiteSpace: "nowrap",
              }}>
                {renderValue(item)}
              </div>
            </div>
          );
        })}
      </div>

      {data.length > 5 && (
        <button onClick={(e) => { e.stopPropagation(); setExpanded(!expanded); }} style={{
          display: "block", width: "100%", marginTop: 8, padding: "8px 0",
          background: "none", border: `1px solid ${color}22`, borderRadius: 8,
          fontFamily: S.font, fontSize: 11, fontWeight: 800, color,
          cursor: "pointer", transition: "all 0.15s",
        }}
        onMouseEnter={e => { e.currentTarget.style.background = `${color}08`; }}
        onMouseLeave={e => { e.currentTarget.style.background = "none"; }}
        >
          {expanded ? "Voir moins ▲" : `Voir le top 10 ▼`}
        </button>
      )}
      {categoryKey && (
        <button
          onClick={(e) => { e.stopPropagation(); navigate(`/palmares/${categoryKey.replace(/_/g, "-")}`); }}
          style={{
            display: "block", width: "100%", marginTop: 6, padding: "8px 0",
            background: "none", border: `1px solid ${color}44`, borderRadius: 8,
            fontFamily: S.font, fontSize: 11, fontWeight: 800, color,
            cursor: "pointer", transition: "all 0.15s",
          }}
          onMouseEnter={e => { e.currentTarget.style.background = `${color}12`; }}
          onMouseLeave={e => { e.currentTarget.style.background = "none"; }}
        >
          Voir le classement complet →
        </button>
      )}
    </div>
  );
};

const Palmares = () => {
  const navigate = useNavigate();
  const { data, loading } = useApi("palmares.php");

  if (loading) return (
    <div style={{ paddingTop: 60, textAlign: "center", color: S.textDim }}>
      <div style={{
        display: "inline-block", width: 32, height: 32, borderRadius: "50%",
        border: `3px solid ${S.border}`, borderTopColor: S.gold,
        animation: "searchSpin 0.6s linear infinite",
      }} />
    </div>
  );

  const fmtSal = (n) => new Intl.NumberFormat("fr-FR").format(n) + "€/mois";

  const sections = [
    { key: "top_salaires",          title: "Plus hauts salaires", icon: "💰", color: S.green, data: data?.top_salaires, renderValue: e => fmtSal(e.salaire_mensuel) },
    { key: "top_cout",              title: "Coût pour le contribuable", icon: "💸", color: S.red, data: data?.top_cout, renderValue: e => fmt(e.cout_total) },
    { key: "top_carriere",          title: "Plus longue carrière", icon: "⏳", color: S.gold, data: data?.top_carriere, renderValue: e => e.annees_carriere ? `${e.annees_carriere} ans` : "?" },
    { key: "top_mandats",           title: "Plus de mandats", icon: "🏅", color: S.purple, data: data?.top_mandats, renderValue: e => `${e.nb_mandats}` },
    { key: "top_cumulards",         title: "Cumulards", icon: "🤹", color: "#e17a2d", data: data?.top_cumulards, renderValue: e => `${e.nb_mandats_actifs} en cours` },
    { key: "top_casseroles",        title: "Casseroles", icon: "🍳", color: S.red, data: data?.top_casseroles, renderValue: e => {
      const c = parseInt(e.nb_condamne) || 0;
      const ec = parseInt(e.nb_en_cours) || 0;
      const parts = [];
      if (c > 0) parts.push(`${c} condamné`);
      if (ec > 0) parts.push(`${ec} en cours`);
      return parts.join(" + ") || `${e.nb_affaires}`;
    }},
    { key: "top_jeunes",            title: "Plus jeunes élus", icon: "🧑", color: S.green, data: data?.top_jeunes, renderValue: e => e.age ? `${e.age} ans` : "?" },
    { key: "top_doyens",            title: "Doyens", icon: "👴", color: S.blue, data: data?.top_doyens, renderValue: e => e.age ? `${e.age} ans` : "?" },
    { key: "top_assidus_deputes",   title: "Députés les plus présents", icon: "🗳️", color: S.green, data: data?.top_assidus_deputes, renderValue: e => `${Math.round(e.taux_global)}% de présence` },
    { key: "top_absents_deputes",   title: "Députés les moins présents", icon: "🗳️", color: S.gold, data: data?.top_absents_deputes, renderValue: e => `${Math.round(e.taux_global)}% de présence` },
    { key: "top_assidus_europeens", title: "Eurodéputés les plus présents", icon: "🇪🇺", color: S.green, data: data?.top_assidus_europeens, renderValue: e => `${Math.round(e.taux_global)}% de présence` },
    { key: "top_absents_europeens", title: "Eurodéputés les moins présents", icon: "🇪🇺", color: S.gold, data: data?.top_absents_europeens, renderValue: e => `${Math.round(e.taux_global)}% de présence` },
    { key: "top_assidus_senateurs", title: "Sénateurs les plus actifs (indicateur partiel)", icon: "🏛️", color: S.blue, data: data?.top_assidus_senateurs, renderValue: e => `${e.nb_questions} activités` },
    { key: "top_absents_senateurs", title: "Sénateurs les moins actifs (indicateur partiel)", icon: "🏛️", color: S.gold, data: data?.top_absents_senateurs, renderValue: e => `${e.nb_questions} activités` },
  ];

  return (
    <div style={{ maxWidth: 900, margin: "0 auto", paddingTop: 32, paddingBottom: 80 }}>
      {/* Header */}
      <div style={{ textAlign: "center", marginBottom: 20 }}>
        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(36px,8vw,56px)", color: S.gold,
          lineHeight: 1, letterSpacing: 3,
          background: `linear-gradient(180deg, ${S.gold}, #e17a2d)`,
          WebkitBackgroundClip: "text", WebkitTextFillColor: "transparent",
        }}>Palmarès</div>
        <div style={{
          fontFamily: S.font, fontSize: 14, color: S.textDim, marginTop: 8, fontStyle: "italic",
        }}>Les records de la vie politique française</div>
      </div>

      {/* Podium : ceux qui cumulent le plus de palmarès */}
      {data?.podium?.length > 0 && (
        <div style={{
          background: `linear-gradient(135deg, ${S.card}, #16213e)`,
          borderRadius: 18, padding: "24px 20px", marginBottom: 32,
          border: `1px solid ${S.gold}33`, position: "relative", overflow: "hidden",
        }}>
          <div style={{
            position: "absolute", inset: 0, pointerEvents: "none",
            background: "repeating-linear-gradient(45deg, transparent 0px, transparent 8px, rgba(253,203,110,0.015) 8px, rgba(253,203,110,0.015) 16px)",
          }} />
          <div style={{ textAlign: "center", marginBottom: 16, position: "relative" }}>
            <span style={{ fontSize: 28 }}>🏆</span>
            <div style={{ fontFamily: S.fontTitle, fontSize: 18, color: S.gold, marginTop: 4 }}>
              Les champions du palmarès
            </div>
            <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, fontStyle: "italic" }}>
              Présents dans le plus de classements
            </div>
          </div>

          <div style={{
            display: "flex", justifyContent: "center", alignItems: "flex-end",
            gap: "clamp(16px,4vw,32px)", position: "relative",
          }}>
            {[1, 0, 2].map(rank => {
              const e = data.podium[rank];
              if (!e) return null;
              const sizes = [80, 64, 56];
              const heights = [110, 80, 60];
              const colors = ["#FFD700", "#C0C0C0", "#CD7F32"];
              const medals = ["🥇", "🥈", "🥉"];
              const displayName = e.prenom ? `${e.prenom} ${e.nom}` : e.nom;
              const slug = e.slug || displayName.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-");

              return (
                <div key={e.id} onClick={() => navigate(`/elu/${slug}`)}
                  style={{
                    textAlign: "center", cursor: "pointer", transition: "transform 0.2s",
                    animation: `popIn 0.5s ${0.1 + rank * 0.12}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
                  }}
                  onMouseEnter={ev => ev.currentTarget.style.transform = "translateY(-8px) scale(1.05)"}
                  onMouseLeave={ev => ev.currentTarget.style.transform = "none"}
                >
                  <div style={{ fontSize: rank === 0 ? 32 : 22, marginBottom: 6 }}>{medals[rank]}</div>
                  <div style={{
                    width: sizes[rank] + 8, height: sizes[rank] + 8,
                    borderRadius: "50%", margin: "0 auto",
                    display: "flex", alignItems: "center", justifyContent: "center",
                    background: `linear-gradient(135deg, ${colors[rank]}, ${colors[rank]}88)`,
                    boxShadow: `0 0 ${rank === 0 ? 24 : 14}px ${colors[rank]}66`,
                  }}>
                    <Avatar elu={e} size={sizes[rank]} showBorder={false} />
                  </div>
                  <div style={{
                    fontFamily: S.font, fontSize: rank === 0 ? 15 : 13, fontWeight: 900,
                    color: S.textMain, marginTop: 8, maxWidth: sizes[rank] + 30,
                    whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                  }}>{displayName}</div>
                  <div style={{
                    fontFamily: S.font, fontSize: 11, color: colors[rank], fontWeight: 800, marginTop: 2,
                  }}>{e.nb_categories} palmarès</div>
                  <div style={{
                    display: "flex", flexWrap: "wrap", justifyContent: "center", gap: 3, marginTop: 6, maxWidth: 140,
                  }}>
                    {(e.categories || []).map((cat, j) => (
                      <span key={j} style={{
                        fontFamily: S.font, fontSize: 9, fontWeight: 700, color: S.textDim,
                        background: "rgba(255,255,255,0.06)", borderRadius: 99, padding: "2px 6px",
                      }}>{cat}</span>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Grille 2 colonnes */}
      <div style={{
        display: "grid",
        gridTemplateColumns: "repeat(auto-fit, minmax(340px, 1fr))",
        gap: 16,
      }}>
        {sections.map((s, i) => (
          <PalmaresCard key={i} {...s} categoryKey={s.key} navigate={navigate} />
        ))}
      </div>

      <div style={{
        textAlign: "center", fontFamily: S.font, fontSize: 11, color: S.textDim,
        marginTop: 32, fontStyle: "italic", opacity: 0.7,
      }}>
        Estimations basées sur les indemnités légales en vigueur — données nos-elus.com
      </div>
    </div>
  );
};

export default Palmares;
