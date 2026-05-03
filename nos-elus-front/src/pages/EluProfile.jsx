import { useState, useEffect, useRef } from "react";
import { useParams, useLocation, useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import { ELUS } from "../data/elus";
import SlotMachine from "../components/SlotMachine";
import KarmaGauge from "../components/KarmaGauge";
import Timeline from "../components/Timeline";
import BoussolePolique from "../components/BoussolePolique";
import ShareButtons from "../components/ShareButtons";
import Card from "../components/Card";
import Skeleton from "../components/Skeleton";
import Avatar from "../components/Avatar";
import VoteCitoyen from "../components/VoteCitoyen";
import PartiMembers from "../components/PartiMembers";
import { IconCalendar, IconChart, IconStar, IconBallot, IconCoffre, IconCasserole, IconMandat } from "../components/Icons";

const fmt = (n) => { if (n === null || n === undefined || n === "") return null; const v = Number(n); return isNaN(v) ? null : new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(v); };

const BilanFactuel = ({ elu }) => {
  const mandats = Array.isArray(elu.mandats) ? elu.mandats : [];
  const affaires = Array.isArray(elu.affaires) ? elu.affaires : [];
  const now = new Date().getFullYear();
  let firstYear = now;
  let totalMandatYears = 0;
  mandats.forEach(m => {
    const d = typeof m === "string" ? null : m.date_debut;
    const f = typeof m === "string" ? null : m.date_fin;
    const y0 = d ? parseInt(d.substring(0, 4)) : null;
    const y1 = f ? parseInt(f.substring(0, 4)) : now;
    if (y0) { firstYear = Math.min(firstYear, y0); totalMandatYears += Math.max(0, y1 - y0); }
  });
  const anciennete = firstYear < now ? now - firstYear : 0;
  const condamnations = affaires.filter(a => a.statut === "condamne" || a.statut === "condamné" || (a.statut || "").toLowerCase().includes("condamn")).length;
  const tauxCond = affaires.length > 0 ? Math.round((condamnations / affaires.length) * 100) : 0;
  const pd = elu.patrimoine_detail || {};
  const fortuneD = pd.declare || null;
  const fortuneE = pd.estime || null;
  const metrics = [
    { label: "Mandats exerces", value: mandats.length, max: 15, color: S.gold, suffix: "" },
    { label: "Duree cumulee", value: totalMandatYears, max: 50, color: S.gold, suffix: " ans" },
    { label: "Anciennete politique", value: anciennete, max: 50, color: S.textMuted, suffix: " ans" },
    { label: "Affaires judiciaires", value: affaires.length, max: 10, color: affaires.length > 0 ? S.red : S.green, suffix: "" },
    { label: "Condamnations", value: condamnations, max: Math.max(affaires.length, 1), color: condamnations > 0 ? S.red : S.green, suffix: tauxCond > 0 ? ` (${tauxCond}%)` : "" },
    { label: "Cout total carriere", value: null, display: elu.cout_carriere ? fmt(elu.cout_carriere) : "—", max: 0, color: S.gold, suffix: "" },
  ];
  if (fortuneD) metrics.push({ label: "Patrimoine declare", value: null, display: fmt(fortuneD), max: 0, color: S.textMuted, suffix: "" });
  if (fortuneE) metrics.push({ label: "Patrimoine estime", value: null, display: fmt(fortuneE), max: 0, color: S.textMuted, suffix: "" });
  return (
    <Card>
      <div style={{ fontFamily: S.fontTitle, fontSize: 18, color: S.gold, marginBottom: 16, textAlign: "center" }}>Bilan factuel</div>
      <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
        {metrics.map((m, i) => (
          <div key={i} style={{ display: "flex", alignItems: "center", gap: 10 }}>
            <div style={{ flex: "0 0 140px", fontFamily: S.font, fontSize: 13, color: S.textMuted }}>{m.label}</div>
            <div style={{ flex: 1, position: "relative", height: 22, background: "rgba(255,255,255,0.05)", borderRadius: 11, overflow: "hidden" }}>
              {m.value !== null && m.max > 0 && (
                <div style={{
                  position: "absolute", left: 0, top: 0, height: "100%",
                  width: `${Math.min(100, (m.value / m.max) * 100)}%`,
                  background: `linear-gradient(90deg, ${m.color}44, ${m.color}aa)`,
                  borderRadius: 11, transition: "width 0.6s ease",
                }} />
              )}
              <div style={{
                position: "relative", zIndex: 1, fontFamily: S.font, fontSize: 13,
                fontWeight: 700, color: m.color, padding: "2px 10px", lineHeight: "18px",
              }}>
                {m.display || `${m.value}${m.suffix}`}
              </div>
            </div>
          </div>
        ))}
      </div>
    </Card>
  );
};

// Palmarès — catégories de mandats
const MANDAT_CATS = [
  { key: "president", icon: "🏅", label: "Président", test: t => t.includes("président de la rép") },
  { key: "premier", icon: "🎖️", label: "1er Ministre", test: t => t.includes("premier ministre") },
  { key: "ministre", icon: "⭐", label: "Ministre", test: t => t.includes("ministre") && !t.includes("premier") },
  { key: "europeen", icon: "🇪🇺", label: "Européen·ne", test: t => t.includes("européen") },
  { key: "senat", icon: "🏛️", label: "Sénateur·rice", test: t => t.includes("sénat") || t.includes("sénateur") },
  { key: "depute", icon: "🏛️", label: "Député·e", test: t => t.includes("déput") && !t.includes("européen") },
  { key: "pres_local", icon: "🏛️", label: "Président·e", test: t => t.includes("président") && (t.includes("département") || t.includes("région") || t.includes("communauté") || t.includes("conseil")) },
  { key: "maire", icon: "🏘️", label: "Maire", test: t => t.includes("maire") && !t.includes("adjoint") },
  { key: "elu_local", icon: "📋", label: "Élu local", test: t => t.includes("conseiller") || t.includes("adjoint") || t.includes("municipal") },
  { key: "candidat", icon: "🗳️", label: "Candidature", test: t => t.includes("candidat"), isCandidat: true },
  { key: "other", icon: "📋", label: "Autre", test: () => true },
];

const PalmaresMandat = ({ elu }) => {
  const mandats = Array.isArray(elu.mandats) ? elu.mandats : [];
  const [expandedCat, setExpandedCat] = useState(null);
  if (mandats.length === 0) return null;

  // Bucket mandats par catégorie avec détails + calcul années
  const now = new Date().getFullYear();
  const buckets = {};
  let totalYears = 0;
  mandats.forEach(m => {
    const titre = (typeof m === "string" ? m : m.titre || "");
    const t = titre.toLowerCase();
    const cat = MANDAT_CATS.find(c => c.test(t)) || MANDAT_CATS[MANDAT_CATS.length - 1];
    if (!buckets[cat.key]) buckets[cat.key] = [];
    const debut = typeof m === "string" ? null : m.date_debut;
    const fin = typeof m === "string" ? null : m.date_fin;
    const yearDebut = debut ? parseInt(debut.substring(0, 4)) : null;
    const yearFin = fin ? parseInt(fin.substring(0, 4)) : now;
    const years = yearDebut ? yearFin - yearDebut : 0;
    totalYears += Math.max(0, years);
    const nbMandatsPoste = (typeof m !== "string" && m.nb_mandats_poste > 1) ? m.nb_mandats_poste : null;
    buckets[cat.key].push({ titre, yearDebut: yearDebut ? String(yearDebut) : null, yearFin: fin ? String(yearFin) : null, enCours: !fin, years: Math.max(0, years), nbMandatsPoste: nbMandatsPoste });
  });

  const rows = MANDAT_CATS.filter(c => buckets[c.key]).map(c => {
    const details = buckets[c.key];
    // Années = durée du mandat le plus long de la catégorie (pas la somme, évite les chevauchements)
    const catYears = Math.max(...details.map(d => d.years), 0);
    // Count = somme des nb_mandats_poste (réélections) ou nombre de lignes
    const catCount = details.reduce((s, d) => s + (d.nbMandatsPoste || 1), 0);
    return { ...c, count: catCount, details, years: catYears };
  });
  // Total mandats = somme des counts par catégorie, candidatures exclues
  const total = rows.reduce((sum, r) => sum + (r.isCandidat ? 0 : r.count), 0);
  // Total années = durée entre le plus ancien mandat et maintenant (pas la somme)
  const allDebuts = mandats.map(m => m.date_debut ? parseInt(m.date_debut.substring(0, 4)) : null).filter(Boolean);
  totalYears = allDebuts.length > 0 ? now - Math.min(...allDebuts) : 0;

  // Limiter à 5 catégories visibles, regrouper le reste en "Autres"
  const mainRows = rows.filter(r => !r.isCandidat).sort((a, b) => b.count - a.count);
  const visibleRows = mainRows.slice(0, 5);
  const hiddenRows = mainRows.slice(5);
  if (hiddenRows.length > 0) {
    const hiddenCount = hiddenRows.reduce((s, r) => s + r.count, 0);
    const hiddenYears = Math.max(...hiddenRows.map(r => r.years), 0);
    const hiddenDetails = hiddenRows.flatMap(r => r.details);
    visibleRows.push({ key: "more", icon: "+", label: `+${hiddenRows.length} autres`, count: hiddenCount, details: hiddenDetails, years: hiddenYears });
  }
  // Remettre les candidatures à la fin
  const candidatRows = rows.filter(r => r.isCandidat);
  const displayRows = [...visibleRows, ...candidatRows];

  return (
    <div className="elu-palmares" style={{
      width: "100%",
      background: `linear-gradient(135deg, ${S.card} 0%, #16213e 100%)`,
      borderRadius: 16, padding: "16px 16px 12px",
      border: `1px solid ${S.gold}22`,
      animation: "slideUp 0.4s ease both",
      marginBottom: 16,
    }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 12 }}>
        <div style={{ display: "flex", alignItems: "baseline", gap: 10 }}>
          <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textDim, textTransform: "uppercase", letterSpacing: 1 }}>
            Palmarès
          </div>
          <div style={{ fontFamily: S.fontTitle, fontSize: 24, color: S.gold, lineHeight: 1, textShadow: `0 0 15px ${S.gold}55` }}>
            {total}
          </div>
          <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim }}>mandats{totalYears > 0 ? ` · ${totalYears} ans` : ""}</div>
        </div>
      </div>
      <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
        {displayRows.map((row, i) => {
          const isExpanded = expandedCat === row.key;
          return (
            <div key={row.key}>
              <div onClick={() => setExpandedCat(isExpanded ? null : row.key)} style={{
                display: "flex", alignItems: "center", gap: 8,
                background: isExpanded ? "rgba(253,203,110,0.08)" : "rgba(255,255,255,0.04)",
                borderRadius: isExpanded ? "10px 10px 0 0" : 10,
                padding: "8px 12px", border: `1px solid ${isExpanded ? S.gold + "33" : S.border}`,
                cursor: "pointer", transition: "all 0.2s",
                animation: `popIn 0.35s ${i * 0.07}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
              }}>
                <span style={{ fontSize: 16 }}>{row.icon}</span>
                <span style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textMain }}>{row.label}</span>
                <span style={{ fontFamily: S.fontTitle, fontSize: 14, color: S.gold }}>×{row.count}</span>
                {row.years > 0 && <span style={{ fontFamily: S.font, fontSize: 11, color: S.textDim }}>{row.years}a</span>}
                <span style={{ fontSize: 10, color: S.textDim, marginLeft: 2 }}>{isExpanded ? "▲" : "▼"}</span>
              </div>
              {/* Détail déplié */}
              {isExpanded && (
                <div style={{
                  background: "rgba(0,0,0,0.2)", borderRadius: "0 0 8px 8px",
                  border: `1px solid ${S.gold}22`, borderTop: "none",
                  padding: "6px 8px", animation: "fadeIn 0.2s ease",
                }}>
                  {row.details.map((d, j) => (
                    <div key={j} style={{
                      fontFamily: S.font, fontSize: 12, color: d.enCours ? S.gold : S.textMuted,
                      lineHeight: 1.6, display: "flex", justifyContent: "space-between", gap: 4,
                    }}>
                      <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", flex: 1 }}>
                        {d.titre.length > 40 ? d.titre.substring(0, 38) + "…" : d.titre}
                      </span>
                      <span style={{ flexShrink: 0, fontWeight: 800, fontSize: 12, display: "flex", alignItems: "center", gap: 4 }}>
                        {d.yearDebut || "?"}{d.enCours ? "→" : d.yearFin ? `–${d.yearFin}` : ""}
                        {d.nbMandatsPoste && <span style={{ fontSize: 10, color: S.green, background: `${S.green}15`, borderRadius: 4, padding: "0 4px" }}>×{d.nbMandatsPoste}</span>}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
};

// Moyenne patrimoine tous élus connus (approximation)
const AVG_PATRIMOINE = { immobilier: 580000, mobilier: 85000, revenus_annuels: 130000, total: 665000 };

const TimelineTab = ({ elu, initialFilter = "all" }) => {
  const [visibleCount, setVisibleCount] = useState(10);
  const [filter, setFilter] = useState(initialFilter);
  // Si initialFilter change (clic depuis ministats), mettre à jour
  useEffect(() => { setFilter(initialFilter); }, [initialFilter]);

  const events = [];
  (elu.affaires || []).forEach(a => events.push({
    type: "affaire", date: a.date || a.date_debut, titre: a.titre,
    detail: a.detail || a.description, statut: a.statut, gravite: a.gravite,
    icon: "🍳", color: a.statut === "Condamné" || a.statut === "condamne" ? S.red : a.statut === "Relaxé" || a.statut === "relaxe" ? S.green : S.gold,
  }));
  const mandats = Array.isArray(elu.mandats) ? elu.mandats : [];
  // Détecter si l'élu a un mandat ministre actif (pour expliquer les fins de mandats parlementaires)
  const hasMinistre = mandats.some(m => typeof m !== "string" && !m.date_fin && (m.titre || "").toLowerCase().includes("ministre"));
  mandats.forEach(m => {
    const titre = typeof m === "string" ? m : m.titre;
    const date = typeof m === "string" ? null : (m.date_debut || null);
    const dateFin = typeof m === "string" ? null : m.date_fin;
    const yearMatch = typeof m === "string" ? titre.match(/\((\d{4})/) : null;
    const t = (titre || "").toLowerCase();
    const isParlem = t.includes("député") || t.includes("sénat");
    // Si mandat parlementaire terminé et l'élu est ministre → suspension Art. 23
    let detail = null;
    if (dateFin && isParlem && hasMinistre) {
      detail = `Suspendu (nomination ministre) — Art. 23 Constitution`;
    } else if (dateFin) {
      detail = `Jusqu'en ${new Date(dateFin).getFullYear()}`;
    } else if (titre.includes("présent")) {
      detail = "En cours";
    }
    events.push({
      type: "mandat", date: date || (yearMatch ? yearMatch[1] : null), titre,
      detail, icon: "🏛️", color: dateFin ? S.textDim : S.blue,
    });
  });
  (elu.votes || []).forEach(v => {
    events.push({ type: "vote", date: v.date || null, titre: v.sujet, detail: null, statut: v.position, icon: "🗳️", color: v.position?.includes("Pour") ? S.green : S.red });
  });

  events.sort((a, b) => {
    const da = a.date ? parseInt(String(a.date).substring(0, 4)) : 0;
    const db = b.date ? parseInt(String(b.date).substring(0, 4)) : 0;
    return db - da;
  });

  const typeLabels = { all: "Tout", affaire: "🍳", mandat: "🏛️", vote: "🗳️" };
  const filtered = filter === "all" ? events : events.filter(e => e.type === filter);

  if (filtered.length === 0) return (
    <div style={{ textAlign: "center", padding: 24, background: S.card, borderRadius: 12, border: `1px solid ${S.border}` }}>
      <div style={{ fontSize: 36, marginBottom: 8 }}>🏆</div>
      <div style={{ fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.green }}>Aucun événement</div>
    </div>
  );

  const visible = filtered.slice(0, visibleCount);
  const hasMore = visibleCount < filtered.length;

  return (
    <div>
      {/* Filter pills */}
      <div style={{ display: "flex", gap: 6, marginBottom: 16, flexWrap: "wrap" }}>
        {Object.entries(typeLabels).map(([key, label]) => {
          const count = key === "all" ? events.length : events.filter(e => e.type === key).length;
          if (count === 0 && key !== "all") return null;
          return (
            <button key={key} onClick={() => { setFilter(key); setVisibleCount(10); }} style={{
              padding: "5px 12px", borderRadius: 99, cursor: "pointer",
              background: filter === key ? S.gold : "rgba(255,255,255,0.04)",
              border: `1px solid ${filter === key ? S.gold : S.border}`,
              fontFamily: S.font, fontSize: 13, fontWeight: 800,
              color: filter === key ? S.bg : S.textMuted, transition: "all 0.15s",
            }}>
              {label} {count}
            </button>
          );
        })}
      </div>

      <div style={{ position: "relative", paddingLeft: 28 }}>
        <div style={{ position: "absolute", left: 8, top: 8, bottom: 8, width: 2, background: `linear-gradient(180deg, ${S.blue}44, ${S.gold}44, ${S.red}44)`, borderRadius: 2 }} />
        {visible.map((ev, i) => (
          <div key={i} style={{ marginBottom: 14, position: "relative", animation: `slideUp 0.25s ${Math.min(i, 6) * 0.04}s ease both` }}>
            <div style={{
              position: "absolute", left: -24, top: 8, width: 12, height: 12,
              borderRadius: "50%", background: ev.color, border: `2px solid ${S.bg}`,
              boxShadow: `0 0 6px ${ev.color}44`,
            }} />
            <div style={{ background: S.card, borderRadius: 10, padding: "12px 16px", border: `1px solid ${ev.color}18` }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 8, marginBottom: 4 }}>
                <span style={{ fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.textMain }}>{ev.icon} {ev.titre}</span>
                {ev.statut && (
                  <span style={{ fontSize: 13, fontWeight: 800, padding: "2px 8px", borderRadius: 99, background: `${ev.color}18`, color: ev.color, fontFamily: S.font, whiteSpace: "nowrap", flexShrink: 0 }}>{ev.statut}</span>
                )}
              </div>
              {ev.detail && <div style={{ fontFamily: S.font, fontSize: 14, color: S.textMuted, lineHeight: 1.5 }}>{ev.detail}</div>}
              <div style={{ display: "flex", gap: 8, marginTop: 6, flexWrap: "wrap" }}>
                {ev.date && <span style={{ fontSize: 13, color: S.textDim, fontFamily: S.font }}>📅 {String(ev.date).substring(0, 4)}</span>}
                <span style={{ fontSize: 13, color: ev.color, fontFamily: S.font, fontWeight: 700, textTransform: "uppercase" }}>
                  {ev.type === "affaire" ? "Affaire" : ev.type === "mandat" ? "Mandat" : ev.type === "vote" ? "Vote" : "Action +"}
                </span>
                {ev.gravite && <span style={{ fontSize: 13, color: S.textDim }}>{"🔴".repeat(ev.gravite)}{"⚪".repeat(5 - ev.gravite)}</span>}
              </div>
            </div>
          </div>
        ))}
      </div>
      {hasMore && (
        <button onClick={() => setVisibleCount(c => c + 10)} style={{
          display: "block", width: "100%", marginTop: 8, padding: "12px",
          background: "rgba(255,255,255,0.04)", border: `1px solid ${S.border}`,
          borderRadius: 10, cursor: "pointer", fontFamily: S.font, fontSize: 12,
          fontWeight: 800, color: S.gold, transition: "all 0.2s", textAlign: "center",
        }}
          onMouseEnter={e => { e.currentTarget.style.background = "rgba(253,203,110,0.08)"; e.currentTarget.style.borderColor = S.gold + "44"; }}
          onMouseLeave={e => { e.currentTarget.style.background = "rgba(255,255,255,0.04)"; e.currentTarget.style.borderColor = S.border; }}
        >Voir {Math.min(filtered.length - visibleCount, 10)} de plus ({filtered.length - visibleCount} restants) ↓</button>
      )}
    </div>
  );
};

const EluProfile = () => {
  const { slug } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState("timeline");
  const [timelineFilter, setTimelineFilter] = useState("all");
  const [voteFilter, setVoteFilter] = useState(null);
  const [showMethodo, setShowMethodo] = useState(false);
  const [showContactPopup, setShowContactPopup] = useState(false);
  const contactBtnRef = useRef(null);
  const tabsRef = useRef(null);
  const [showPartiModal, setShowPartiModal] = useState(false);
  const [apiElu, setApiElu] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  // Scroll to top on mount
  useEffect(() => { window.scrollTo(0, 0); }, [slug]);

  const slugify = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

  // Fallback mock (utilisé uniquement si l'API fail)
  const mockElu = ELUS.find(e => {
    const eluSlug = e.slug || slugify(e.nom);
    return eluSlug === slug;
  }) || ELUS.find(e => e.id === parseInt(slug));

  // Normaliser les données API
  const normalizeElu = (data) => {
    const p = (data.prenom || "").trim();
    const n = (data.nom || "").trim();
    const fullNom = !p ? n : n.toLowerCase().startsWith(p.toLowerCase()) ? n : `${p} ${n}`;
    return {
      ...data,
      prenom: null,
      nom: fullNom,
      affaires: data.affaires || [],
      affiliations: data.affiliations || [],
      votes: data.votes || [],
      mandats: data.mandats || [],
      patrimoine: data.patrimoine || data.patrimoine_info || "Déclaration HATVP disponible",
      patrimoine_detail: data.patrimoine_detail || data.patrimoine_details || null,
      alias: data.alias || [],
      historique_partis: data.historique_partis || [],
    };
  };

  // Fetch l'API quand le slug change
  useEffect(() => {
    setActiveTab("timeline");
    setLoading(true);
    setError(false);
    const API_URL = import.meta.env.VITE_API_URL || "/api";
    const controller = new AbortController();

    const fetchOpts = { signal: controller.signal, credentials: "same-origin" };
    const fallback = () =>
      fetch(`${API_URL}/search.php?q=${encodeURIComponent(slug.replace(/-/g, ' '))}`, fetchOpts)
        .then(r => r.ok ? r.json() : [])
        .then(results => {
          if (results.length > 0) {
            return fetch(`${API_URL}/elu.php?id=${results[0].id}`, fetchOpts).then(r => r.ok ? r.json() : null);
          }
          return fetch(`${API_URL}/elu.php?id=${slug}`, fetchOpts).then(r => r.ok ? r.json() : null);
        });

    fetch(`${API_URL}/elu.php?slug=${slug}`, fetchOpts)
      .then(r => r.ok ? r.json() : null)
      .then(data => {
        if (data && !data.error) return data;
        return fallback();
      })
      .then(data => {
        if (data && !data.error) {
          setApiElu(normalizeElu(data));
        } else {
          setError(true);
        }
      })
      .catch((err) => {
        if (err.name !== "AbortError") {
          console.warn("[nos-elus] Fetch API failed:", err.message);
          setError(true);
        }
      })
      .finally(() => setLoading(false));

    return () => controller.abort();
  }, [slug]);

  // API d'abord → mock en fallback → state de navigation en dernier recours
  const stateElu = location.state?.elu;
  const elu = apiElu || mockElu || (stateElu ? normalizeElu(stateElu) : null);

  if (loading) {
    return (
      <div style={{ paddingTop: 32, maxWidth: 960, margin: "0 auto" }}>
        <Skeleton height={200} radius={16} />
        <Skeleton height={120} radius={16} style={{ marginTop: 24 }} />
        <Skeleton height={80} radius={12} style={{ marginTop: 16 }} />
        <Skeleton height={80} radius={12} style={{ marginTop: 10 }} />
      </div>
    );
  }

  if (!elu) {
    return (
      <div style={{ paddingTop: 60, textAlign: "center" }}>
        <div style={{ fontSize: 48, marginBottom: 16 }}>🤷</div>
        <div style={{ fontFamily: S.font, fontSize: 18, fontWeight: 800, color: S.textMuted }}>Élu non trouvé</div>
        <button onClick={() => navigate("/")} style={{
          marginTop: 20, background: `linear-gradient(135deg, ${S.gold}, #f39c12)`,
          border: "none", borderRadius: 99, padding: "10px 24px", fontSize: 13, fontWeight: 800,
          fontFamily: S.font, color: S.bg, cursor: "pointer",
        }}>Retour à l'accueil</button>
      </div>
    );
  }

  const tabs = [
    { id: "timeline", label: "Timeline", icon: <IconCalendar size={16} /> },
    { id: "bilan", label: "Bilan", icon: <IconChart size={16} /> },
    { id: "votes", label: "Votes", icon: <IconBallot size={16} />, count: elu.votes.length },
    { id: "patrimoine", label: "Coffre-fort", icon: <IconCoffre size={16} color={S.gold} /> },
  ];

  const isOgMode = typeof window !== "undefined" && new URLSearchParams(window.location.search).get("og") === "1";

  return (
    <div style={{ animation: "slideUp 0.5s cubic-bezier(0.16,1,0.3,1)", maxWidth: 960, margin: "0 auto", paddingTop: 32 }}>
      {!isOgMode && (
        <button onClick={() => navigate(-1)} style={{
          background: S.card, border: `1px solid ${S.border}`, borderRadius: 99,
          cursor: "pointer", fontFamily: S.font, fontSize: 13, fontWeight: 700,
          color: S.textMuted, marginBottom: 24, padding: "8px 18px", transition: "all 0.2s",
        }}
        onMouseEnter={e => { e.target.style.color = "#fff"; }} onMouseLeave={e => { e.target.style.color = S.textMuted; }}
        >← Retour</button>
      )}

      {/* Jackpot mini — en haut */}
      {!isOgMode && <SlotMachine elu={elu} mini />}

      {/* Header + Vote citoyen + Boussole */}
      <div className="elu-header" style={{ display: "flex", gap: 12, marginBottom: 20, alignItems: "stretch", flexWrap: "wrap" }}>

        {/* Vote citoyen — gauche */}
        {!isOgMode && (
          <div style={{
            width: 160, minWidth: 145, flexShrink: 0,
            display: "flex", alignItems: "center", justifyContent: "center",
          }}>
            <VoteCitoyen eluId={elu.id} />
          </div>
        )}

        {/* Header centre */}
        <div style={{
          flex: 1, minWidth: 240, textAlign: "center", padding: "24px 16px",
          background: `linear-gradient(135deg, ${S.card} 0%, #16213e 100%)`,
          borderRadius: 16, border: `1px solid ${S.border}`, position: "relative",
        }}>
          <div style={{ position: "absolute", inset: 0, opacity: 0.02, pointerEvents: "none", overflow: "hidden", borderRadius: 16, background: "repeating-linear-gradient(45deg, #fff 0px, #fff 1px, transparent 1px, transparent 10px)" }} />
          <div style={{ marginBottom: 10, animation: "popIn 0.5s cubic-bezier(0.68,-0.55,0.265,1.55)", display: "flex", justifyContent: "center" }}>
            <Avatar elu={elu} size={104} />
          </div>
          <h2 style={{ fontFamily: S.fontTitle, fontSize: "clamp(24px,4vw,34px)", color: "#fff", margin: 0 }}>{elu.nom}</h2>
          {elu.date_naissance && elu.date_naissance > "1900-01-01" && (() => {
            const born = new Date(elu.date_naissance);
            const age = Math.floor((Date.now() - born.getTime()) / (365.25 * 24 * 60 * 60 * 1000));
            return age > 0 && age < 120 ? (
              <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginTop: 2 }}>{age} ans</div>
            ) : null;
          })()}
          {Array.isArray(elu.alias) && elu.alias.length > 0 && (
            <div style={{
              display: "flex", gap: 6, justifyContent: "center", flexWrap: "wrap", marginTop: 6,
            }}>
              {elu.alias.map((a, i) => (
                <span key={i} style={{
                  fontFamily: S.font, fontSize: 12, fontWeight: 700, fontStyle: "italic",
                  color: S.textDim, background: "rgba(255,255,255,0.04)",
                  border: `1px solid ${S.border}`, borderRadius: 99, padding: "2px 8px",
                }}>aka {a}</span>
              ))}
            </div>
          )}
          <div style={{ fontFamily: S.font, fontSize: 15, color: S.textMuted, marginTop: 4 }}>{elu.fonction}</div>
          {(() => {
            // Extraire commune + dept pour les élus locaux (maires, conseillers...)
            const mandatLocal = (elu.mandats || []).find(m =>
              !m.date_fin && /maire|adjoint|conseiller\s+(municipal|départ|régional)/i.test(m.titre || "")
            );
            const commune = mandatLocal?.titre?.replace(/^[^—\-]+[—\-]\s*/u, "")?.trim();
            const dept = elu.departement;
            if (!commune) return null;
            return (
              <div style={{ display: "flex", alignItems: "center", gap: 5, marginTop: 3 }}>
                <span style={{ fontFamily: S.font, fontSize: 12, color: S.textDim }}>
                  📍 {commune}{dept ? ` · Dept ${dept}` : ""}
                </span>
              </div>
            );
          })()}
          {!!elu.is_candidat && (
            <div style={{ display: "inline-flex", alignItems: "center", gap: 6, marginTop: 8, padding: "5px 12px", borderRadius: 99, background: "rgba(232,67,147,0.12)", border: "1px solid rgba(232,67,147,0.35)", fontSize: 12, fontWeight: 800, color: "#fd79a8", fontFamily: S.font }}>
              🏁 EN CAMPAGNE{elu.election_cible ? ` — ${elu.election_cible}` : ""}
            </div>
          )}
          {/* Badges élu / auto-élu / nommé — empilés en haut à droite */}
          {(() => {
            const mandatsActifs = (elu.mandats || []).filter(m => typeof m !== "string" && !m.date_fin);
            const fn = elu.fonction || "";

            // Candidat : badge rose dédié
            if (elu.is_candidat) {
              return (
                <div className="badge-elu-container" style={{ position: "absolute", top: 10, right: 10, zIndex: 3, display: "flex", flexDirection: "column", alignItems: "center", gap: 2 }}>
                  <div style={{ width: 36, height: 36, borderRadius: "50%", background: "radial-gradient(circle at 35% 35%, #e84393, #fd79a8cc, #e8439388)", border: "2px solid #fd79a8", boxShadow: "0 2px 8px #fd79a844, inset 0 1px 2px rgba(255,255,255,0.3)", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 17 }}>🏁</div>
                  <span style={{ fontFamily: S.font, fontSize: 8, fontWeight: 900, color: "#fd79a8", textTransform: "uppercase", letterSpacing: 0.5 }}>Candidat</span>
                  <div className="badge-tooltip" style={{ position: "absolute", top: 52, right: 0, width: 200, zIndex: 100, padding: "8px 10px", borderRadius: 8, background: S.card, border: `1px solid ${S.border}`, boxShadow: "0 8px 24px rgba(0,0,0,0.5)", fontFamily: S.font, fontSize: 11, color: S.textMuted, lineHeight: 1.5, opacity: 0, pointerEvents: "none", transition: "opacity 0.2s" }}>
                    <span style={{ color: "#fd79a8", fontWeight: 700 }}>🏁 Candidat</span> — {elu.election_cible || "En campagne électorale"}. Profil non encore élu à ce poste.
                  </div>
                </div>
              );
            }

            // Ancien élu : badge spécifique grisé
            if (elu.ancien_elu || elu.actif === 0 || elu.actif === false || (mandatsActifs.length === 0 && (/^ancien/i.test(fn.trim()) || !fn))) {
              return (
                <div style={{ position: "absolute", top: 10, right: 10, zIndex: 3, display: "flex", flexDirection: "column", alignItems: "center", gap: 2 }}>
                  <div style={{ width: 36, height: 36, borderRadius: "50%", background: "rgba(255,255,255,0.08)", border: "2px solid rgba(255,255,255,0.2)", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 17 }}>🏛️</div>
                  <div style={{ fontSize: 9, color: "rgba(255,255,255,0.4)", fontFamily: S.font, textAlign: "center", lineHeight: 1.1 }}>Ancien<br/>élu</div>
                </div>
              );
            }

            const allTitres = [...mandatsActifs.map(m => m.titre || ""), fn];

            const nommeRegex = /(ministre|garde des sceaux|secrétaire d.état|premier ministre|membre.*conseil constitutionnel)/i;
            const eluDirectRegex = /(président de la rép|député|sénateur|sénatrice|conseill.*(municipal|départemental|régional|communal|arrondissement|paris|fde)|député.*européen|maire|adjoint)/i;
            const autoEluRegex = /(président.*(conseil|sénat|assemblée)|vice-président.*(conseil|départemental|régional))/i;

            const isNomme = allTitres.some(t => nommeRegex.test(t));
            const isEluDirect = allTitres.some(t => eluDirectRegex.test(t));
            const isAutoElu = allTitres.some(t => autoEluRegex.test(t) && !nommeRegex.test(t));

            const badges = [];
            if (isNomme) badges.push({ icon: "👑", label: "Nommé", color: S.purple, border: "#a29bfe", tip: "Poste obtenu par nomination, pas par élection" });
            if (isAutoElu) badges.push({ icon: "👑", label: "Auto-élu", color: "#e17055", border: "#e17055", tip: "Élu par d'autres élus, pas directement par les citoyens" });
            if (isEluDirect && !isNomme) badges.push({ icon: "🗳️", label: "Élu", color: S.gold, border: "#e5a84e", tip: "Élu au suffrage universel direct" });
            if (badges.length === 0) badges.push({ icon: "🗳️", label: "Élu", color: S.gold, border: "#e5a84e", tip: "Élu" });

            return (
              <div className="badge-elu-container" style={{
                position: "absolute", top: 10, right: 10, zIndex: 3,
                display: "flex", flexDirection: "column", alignItems: "center", gap: 6,
              }}>
                {badges.map((badge, i) => (
                  <div key={i} style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 2 }}>
                    <div style={{
                      width: 36, height: 36, borderRadius: "50%",
                      background: `radial-gradient(circle at 35% 35%, ${badge.border}, ${badge.color}cc, ${badge.border}88)`,
                      border: `2px solid ${badge.border}`,
                      boxShadow: `0 2px 8px ${badge.color}44, inset 0 1px 2px rgba(255,255,255,0.3)`,
                      display: "flex", alignItems: "center", justifyContent: "center",
                      fontSize: 17,
                    }}>
                      {badge.icon}
                    </div>
                    <span style={{
                      fontFamily: S.font, fontSize: 8, fontWeight: 900, color: badge.color,
                      textTransform: "uppercase", letterSpacing: 0.5,
                    }}>{badge.label}</span>
                  </div>
                ))}
                <div className="badge-tooltip" style={{
                  position: "absolute", top: badges.length * 52, right: 0, width: 200, zIndex: 100,
                  padding: "8px 10px", borderRadius: 8,
                  background: S.card, border: `1px solid ${S.border}`,
                  boxShadow: "0 8px 24px rgba(0,0,0,0.5)",
                  fontFamily: S.font, fontSize: 11, color: S.textMuted, lineHeight: 1.5,
                  opacity: 0, pointerEvents: "none", transition: "opacity 0.2s",
                }}>
                  {badges.map((b, i) => <div key={i} style={{ marginBottom: i < badges.length - 1 ? 4 : 0 }}><span style={{ color: b.color, fontWeight: 700 }}>{b.icon} {b.label}</span> — {b.tip}</div>)}
                </div>
              </div>
            );
          })()}
          <style>{`
            .badge-elu-container:hover .badge-tooltip { opacity: 1 !important; pointer-events: auto !important; }
          `}</style>
          {elu.parti && (
            <button onClick={() => setShowPartiModal(true)} style={{
              display: "inline-block", marginTop: 8, padding: "3px 12px", fontSize: 13, fontWeight: 800,
              background: "rgba(253,203,110,0.1)", border: "1px solid rgba(253,203,110,0.15)",
              borderRadius: 99, color: S.gold, fontFamily: S.font,
              cursor: "pointer", transition: "all 0.2s",
            }}
            onMouseEnter={e => { e.currentTarget.style.background = "rgba(253,203,110,0.2)"; e.currentTarget.style.borderColor = S.gold; }}
            onMouseLeave={e => { e.currentTarget.style.background = "rgba(253,203,110,0.1)"; e.currentTarget.style.borderColor = "rgba(253,203,110,0.15)"; }}
            >{elu.parti} →</button>
          )}

          {(elu.email || elu.telephone || elu.adresse || elu.url_fiche) && (
            <div style={{ position: "relative", display: "inline-flex", justifyContent: "center", marginTop: 10 }}>
              <button
                ref={contactBtnRef}
                onClick={() => setShowContactPopup(v => !v)}
                title="Informations de contact"
                style={{
                  width: 44, height: 44, borderRadius: "50%", border: `1px solid rgba(253,203,110,0.3)`,
                  background: showContactPopup ? "rgba(253,203,110,0.25)" : "rgba(253,203,110,0.1)",
                  cursor: "pointer", fontSize: 20, display: "flex", alignItems: "center",
                  justifyContent: "center", transition: "all 0.2s", color: S.gold,
                }}
                onMouseEnter={e => { e.currentTarget.style.background = "rgba(253,203,110,0.25)"; e.currentTarget.style.borderColor = S.gold; }}
                onMouseLeave={e => { if (!showContactPopup) { e.currentTarget.style.background = "rgba(253,203,110,0.1)"; e.currentTarget.style.borderColor = "rgba(253,203,110,0.3)"; } }}
              >📇</button>

              {showContactPopup && (
                <>
                  <div
                    onClick={() => setShowContactPopup(false)}
                    style={{ position: "fixed", inset: 0, zIndex: 999 }}
                  />
                  <div style={{
                    position: "absolute", top: "calc(100% + 10px)", left: "50%",
                    transform: "translateX(-50%)", zIndex: 1000,
                    background: "#1a1a2e", border: `1px solid rgba(253,203,110,0.35)`,
                    borderRadius: 16, boxShadow: "0 8px 32px rgba(0,0,0,0.6)",
                    padding: "16px 20px", minWidth: 260, maxWidth: 320,
                    animation: "fadeInDown 0.18s ease",
                    fontFamily: S.font,
                  }}>
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 14 }}>
                      <span style={{ color: S.gold, fontWeight: 700, fontSize: 13, letterSpacing: "0.05em", textTransform: "uppercase" }}>Contact</span>
                      <button onClick={() => setShowContactPopup(false)} style={{
                        background: "none", border: "none", color: "#aaa", cursor: "pointer",
                        fontSize: 18, lineHeight: 1, padding: "2px 4px",
                      }}>✕</button>
                    </div>
                    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
                      {elu.email && (
                        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                          <span style={{ fontSize: 16, flexShrink: 0 }}>✉️</span>
                          <div style={{ overflow: "hidden" }}>
                            <div style={{ color: S.gold, fontSize: 10, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 2 }}>Email</div>
                            <a href={`mailto:${elu.email}`} style={{ color: "#fff", fontSize: 13, textDecoration: "none", wordBreak: "break-all" }}
                              onMouseEnter={e => e.currentTarget.style.color = S.gold}
                              onMouseLeave={e => e.currentTarget.style.color = "#fff"}
                            >{elu.email}</a>
                          </div>
                        </div>
                      )}
                      {elu.telephone && (
                        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                          <span style={{ fontSize: 16, flexShrink: 0 }}>📞</span>
                          <div>
                            <div style={{ color: S.gold, fontSize: 10, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 2 }}>Téléphone</div>
                            <span
                              style={{ color: "#fff", fontSize: 13, cursor: "pointer", userSelect: "all" }}
                              title="Cliquez pour copier"
                              onClick={() => navigator.clipboard?.writeText(elu.telephone)}
                            >{elu.telephone}</span>
                          </div>
                        </div>
                      )}
                      {elu.adresse && (
                        <div style={{ display: "flex", alignItems: "flex-start", gap: 10 }}>
                          <span style={{ fontSize: 16, flexShrink: 0 }}>📍</span>
                          <div>
                            <div style={{ color: S.gold, fontSize: 10, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 2 }}>Adresse</div>
                            <span style={{ color: "#fff", fontSize: 13 }}>{elu.adresse}</span>
                          </div>
                        </div>
                      )}
                      {elu.url_fiche && (
                        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                          <span style={{ fontSize: 16, flexShrink: 0 }}>🏛️</span>
                          <div>
                            <div style={{ color: S.gold, fontSize: 10, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 2 }}>Fiche officielle</div>
                            <a href={elu.url_fiche} target="_blank" rel="noreferrer"
                              style={{ color: "#fff", fontSize: 13, textDecoration: "none" }}
                              onMouseEnter={e => e.currentTarget.style.color = S.gold}
                              onMouseLeave={e => e.currentTarget.style.color = "#fff"}
                            >Voir la fiche →</a>
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </>
              )}
            </div>
          )}

          {/* Mini-encadrés chiffres clés */}
          {(() => {
            const pd = elu.patrimoine_detail || elu.patrimoine_details || null;
            const fmtCompact = (n) => {
              if (!n && n !== 0) return null;
              if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M";
              if (n >= 1e3) return Math.round(n / 1e3) + "k";
              return new Intl.NumberFormat("fr-FR").format(n);
            };
            const fortune = pd?.fortune_estimee || pd?.total || null;
            // Salaire : indemnités API d'abord, patrimoine_detail en fallback
            const indemnites = elu.indemnites || [];
            let salaire = null;
            if (indemnites.length > 0) {
              salaire = elu.indemnites_plafond
                ? elu.indemnites_plafond
                : indemnites.reduce((sum, ind) => sum + (ind.net || ind.brut || ind.brut_max || 0), 0);
            }
            const nbAffaires = (elu.affaires || []).filter(a => a.statut !== "clean").length;
            const nbMandats = (Array.isArray(elu.mandats) ? elu.mandats : []).length;
            const act = elu.activite_parlementaire;
            const tauxGlobal = act?.taux_global || null;
            const coutCarriere = elu.cout_carriere || 0;
            const coutLabel = coutCarriere >= 1e6 ? (coutCarriere / 1e6).toFixed(1).replace(/\.0$/, "") + "M\u20ac" : coutCarriere >= 1e3 ? Math.round(coutCarriere / 1e3) + "k\u20ac" : null;
            const tauxColor = tauxGlobal >= 60 ? S.green : tauxGlobal >= 35 ? S.gold : S.red;
            const chips = [
              tauxGlobal !== null ? { icon: "📊", label: `${Math.round(tauxGlobal)}% actif`, color: tauxColor, tab: "votes", title: "Taux d'activité parlementaire" } : null,
              coutLabel ? { icon: "💸", label: coutLabel, color: S.gold, tab: "patrimoine", title: "Coût total carrière" } : null,
              { icon: <IconCasserole size={16} color={nbAffaires > 0 ? S.red : S.green} />, label: nbAffaires + " affaire" + (nbAffaires !== 1 ? "s" : ""), color: nbAffaires > 0 ? S.red : S.green, tab: "timeline", subFilter: "affaire", title: "Casseroles" },
              { icon: <IconMandat size={16} color={S.purple} />, label: nbMandats + " mandat" + (nbMandats !== 1 ? "s" : ""), color: S.purple, tab: "timeline", subFilter: "mandat", title: "Mandats" },
              fortune ? { icon: <IconCoffre size={16} color={S.gold} />, label: fmtCompact(fortune) + " \u20ac", color: S.gold, tab: "patrimoine", title: "Fortune" } : null,
              salaire ? { icon: <IconCoffre size={16} color={S.green} />, label: new Intl.NumberFormat("fr-FR").format(salaire) + " \u20ac/mois", color: S.green, tab: "patrimoine", title: "Salaire" } : null,
            ].filter(Boolean).filter(c => c.label);
            if (chips.length === 0) return null;
            return (
              <div className="elu-ministats" style={{ display: "flex", gap: 8, flexWrap: "wrap", justifyContent: "center", marginTop: 12 }}>
                {chips.map((c, i) => (
                  <div key={i} onClick={() => {
                    setActiveTab(c.tab);
                    if (c.tab === "timeline") setTimelineFilter(c.subFilter || "all");
                    setTimeout(() => tabsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
                  }} title={c.title} style={{
                    display: "flex", alignItems: "center", gap: 6,
                    background: "rgba(255,255,255,0.04)", border: `1px solid ${c.color}33`,
                    borderRadius: 12, padding: "6px 12px", cursor: "pointer",
                    transition: "all 0.2s", minHeight: 44,
                  }}
                  onMouseEnter={e => { e.currentTarget.style.transform = "translateY(-2px)"; e.currentTarget.style.borderColor = S.gold; e.currentTarget.style.boxShadow = `0 4px 12px ${S.gold}22`; }}
                  onMouseLeave={e => { e.currentTarget.style.transform = "none"; e.currentTarget.style.borderColor = c.color + "33"; e.currentTarget.style.boxShadow = "none"; }}
                  >
                    <span style={{ fontSize: 16, display: "flex", alignItems: "center" }}>{c.icon}</span>
                    <span style={{ fontFamily: S.font, fontSize: 14, fontWeight: 800, color: c.color }}>{c.label}</span>
                  </div>
                ))}
              </div>
            );
          })()}

          {!isOgMode && <div style={{ marginTop: 12 }}><ShareButtons elu={elu} /></div>}
        </div>

        {/* Boussole — droite */}
        {!isOgMode && (
          <div className="elu-boussole" style={{
            width: 280, minWidth: 250, flexShrink: 0,
            background: `linear-gradient(135deg, ${S.card} 0%, #16213e 100%)`,
            borderRadius: 16, border: `1px solid ${S.border}`,
            padding: "10px 12px 14px",
          }}>
            <BoussolePolique elu={elu} />
          </div>
        )}
      </div>

      {isOgMode ? null : (
      <>
      {/* Palmarès — horizontal */}
      <PalmaresMandat elu={elu} />

      {/* Tabs */}
      <div ref={tabsRef} className="elu-tabs" style={{ display: "flex", gap: 4, marginBottom: 20, overflowX: "auto", paddingBottom: 4 }}>
        {tabs.map(tab => (
          <button key={tab.id} onClick={() => setActiveTab(tab.id)} style={{
            background: activeTab === tab.id ? `linear-gradient(135deg, ${S.gold}, #f39c12)` : "rgba(255,255,255,0.04)",
            border: activeTab === tab.id ? "none" : `1px solid ${S.border}`,
            borderRadius: 99, cursor: "pointer", fontFamily: S.font, fontSize: 13, fontWeight: 800,
            color: activeTab === tab.id ? S.bg : S.textMuted, padding: "7px 12px",
            transition: "all 0.2s", whiteSpace: "nowrap", display: "flex", alignItems: "center", gap: 4,
          }}>
            {tab.icon} {tab.label}
            {tab.count !== undefined && (
              <span style={{
                fontSize: 12, fontWeight: 900,
                background: activeTab === tab.id ? "rgba(0,0,0,0.15)" : "rgba(255,255,255,0.06)",
                color: activeTab === tab.id ? S.bg : S.textMuted, borderRadius: 99, padding: "1px 6px",
              }}>{tab.count}</span>
            )}
          </button>
        ))}
      </div>

      <div key={activeTab} style={{ animation: "fadeIn 0.25s ease" }}>
        {activeTab === "timeline" && <TimelineTab elu={elu} initialFilter={timelineFilter} />}
        {activeTab === "bilan" && <BilanFactuel elu={elu} />}
        {activeTab === "votes" && (() => {
          const votes = elu.votes || [];
          const pour = votes.filter(v => (v.position || "").includes("Pour"));
          const contre = votes.filter(v => (v.position || "").includes("Contre"));
          const abst = votes.filter(v => (v.position || "").includes("Abstention"));
          const total = votes.length;

          // Taux d'assiduité depuis l'API (calculé avec votes + commissions + questions)
          const act = elu.activite_parlementaire;
          const totalScrutins = act?.total_scrutins || 1733;
          const tauxAssiduite = act?.taux_votes ? Math.round(act.taux_votes) : (total > 0 ? Math.round((total / totalScrutins) * 100) : 0);

          // Regrouper les votes par sujet+date (un texte EU peut avoir 100+ amendements avec le même titre)
          const groupMap = new Map();
          votes.forEach(v => {
            const key = (v.sujet || "") + "|" + (v.date_vote || "");
            if (!groupMap.has(key)) {
              groupMap.set(key, { ...v, nb_sous_votes: 1 });
            } else {
              groupMap.get(key).nb_sous_votes++;
            }
          });
          const votesGroupes = Array.from(groupMap.values());
          const pourG = votesGroupes.filter(v => (v.position || "").includes("Pour"));
          const contreG = votesGroupes.filter(v => (v.position || "").includes("Contre"));
          const abstG = votesGroupes.filter(v => (v.position || "").includes("Abstention"));

          const filtered = !voteFilter ? votesGroupes
            : voteFilter === "Pour" ? pourG
            : voteFilter === "Contre" ? contreG
            : abstG;

          return total === 0 ? (
            <Card><div style={{ textAlign: "center", padding: 16, fontFamily: S.font, color: S.textMuted }}>
              <div style={{ fontSize: 32, marginBottom: 8 }}>🗳️</div>Pas de votes référencés</div></Card>
          ) : (
            <>
              {/* Stats résumé + assiduité */}
              <Card>
                {/* Bouton méthodologie */}
                <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: 8 }}>
                  <button onClick={() => setShowMethodo(!showMethodo)} style={{
                    width: 28, height: 28, borderRadius: "50%",
                    background: showMethodo ? S.gold : "rgba(255,255,255,0.08)",
                    border: `1px solid ${showMethodo ? S.gold : S.border}`,
                    color: showMethodo ? S.bg : S.textDim,
                    fontFamily: S.font, fontSize: 14, fontWeight: 900,
                    cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center",
                    transition: "all 0.2s",
                  }}>?</button>
                </div>

                {/* Panneau méthodologie */}
                {showMethodo && (() => {
                  const aCommissions = (act?.nb_réunions_convoque || 0) > 0;
                  const aQuestions = (act?.nb_questions || 0) > 0;
                  const tagOK = { color: S.green, fontWeight: 800 };
                  const tagPending = { color: S.textDim, fontStyle: "italic" };
                  return (
                    <div style={{
                      background: "rgba(253,203,110,0.06)", border: `1px solid ${S.gold}33`,
                      borderRadius: 12, padding: "14px 16px", marginBottom: 14,
                      fontFamily: S.font, fontSize: 12, color: S.textMuted, lineHeight: 1.6,
                    }}>
                      <div style={{ fontFamily: S.fontTitle, fontSize: 14, color: S.gold, marginBottom: 10 }}>Comment est calculée l'activité ?</div>
                      <div style={{ marginBottom: 10 }}>
                        Le taux global est un <strong>ratio direct</strong> : nombre d'événements de présence sur nombre d'événements applicables, multiplié par 100. Aucune pondération arbitraire.
                      </div>
                      <table style={{ width: "100%", borderCollapse: "collapse" }}>
                        <thead>
                          <tr style={{ borderBottom: `1px solid ${S.border}` }}>
                            <th style={{ textAlign: "left", padding: "6px 8px", color: S.textMain, fontSize: 12 }}>Dimension</th>
                            <th style={{ textAlign: "center", padding: "6px 8px", color: S.textMain, fontSize: 12 }}>État</th>
                            <th style={{ textAlign: "left", padding: "6px 8px", color: S.textMain, fontSize: 12 }}>Détail</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr style={{ borderBottom: `1px solid ${S.border}22` }}>
                            <td style={{ padding: "6px 8px" }}>🗳️ Votes en séance</td>
                            <td style={{ textAlign: "center", padding: "6px 8px", ...tagOK }}>✓ Calculé</td>
                            <td style={{ padding: "6px 8px", fontSize: 11 }}>
                              {act?.nb_votes ?? 0} votes sur {act?.total_scrutins ?? 0} scrutins applicables. Périmètre = scrutins de la chambre durant les périodes de mandat, moins les fonctions ministérielles concomitantes.
                            </td>
                          </tr>
                          <tr style={{ borderBottom: `1px solid ${S.border}22` }}>
                            <td style={{ padding: "6px 8px" }}>🏛️ Présence en commission</td>
                            <td style={{ textAlign: "center", padding: "6px 8px", ...(aCommissions ? tagOK : tagPending) }}>{aCommissions ? "✓ Calculé" : "À intégrer"}</td>
                            <td style={{ padding: "6px 8px", fontSize: 11 }}>
                              {aCommissions
                                ? `${act.nb_réunions_present} sur ${act.nb_réunions_convoque} réunions de commission.`
                                : "Champs prévus en BDD, source non encore intégrée."}
                            </td>
                          </tr>
                          <tr>
                            <td style={{ padding: "6px 8px" }}>📝 Questions écrites/orales</td>
                            <td style={{ textAlign: "center", padding: "6px 8px", ...(aQuestions ? tagOK : tagPending) }}>{aQuestions ? "✓ Calculé" : "À intégrer"}</td>
                            <td style={{ padding: "6px 8px", fontSize: 11 }}>
                              {aQuestions
                                ? `${act.nb_questions} questions posées.`
                                : "Champs prévus en BDD, source non encore intégrée."}
                            </td>
                          </tr>
                        </tbody>
                      </table>
                      <div style={{ marginTop: 10, fontSize: 11, color: S.textDim }}>
                        <strong>Sources :</strong> Assemblée nationale (data.assemblee-nationale.fr), Parlement européen (europarl.europa.eu).
                      </div>
                      <div style={{ marginTop: 6, fontSize: 11, color: S.textDim, fontStyle: "italic" }}>
                        Limites actuelles : sénateurs non couverts (pas de scrutins en BDD). Absences justifiées (commission d'enquête, mission, maladie) actuellement comptées comme absences faute d'import du statut "Non-votant".
                      </div>
                    </div>
                  );
                })()}
                <div style={{ display: "flex", gap: 16, flexWrap: "wrap", justifyContent: "center", marginBottom: 12 }}>
                  {[
                    { label: "Pour", value: pour.length, color: S.green, pct: Math.round(pour.length / total * 100) },
                    { label: "Contre", value: contre.length, color: S.red, pct: Math.round(contre.length / total * 100) },
                    { label: "Abstention", value: abst.length, color: S.gold, pct: Math.round(abst.length / total * 100) },
                  ].map((s, i) => (
                    <div key={i} style={{ textAlign: "center", minWidth: 80 }}>
                      <div style={{ fontFamily: S.fontTitle, fontSize: 24, color: s.color }}>{s.value}</div>
                      <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim }}>{s.label} ({s.pct}%)</div>
                    </div>
                  ))}
                  <div style={{ textAlign: "center", minWidth: 80 }}>
                    <div style={{ fontFamily: S.fontTitle, fontSize: 24, color: S.purple }}>{total}</div>
                    <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim }}>Total</div>
                  </div>
                </div>

                {/* Barre de répartition */}
                <div style={{ display: "flex", height: 8, borderRadius: 99, overflow: "hidden", marginBottom: 12 }}>
                  <div style={{ flex: pour.length, background: S.green, transition: "flex 0.6s" }} />
                  <div style={{ flex: contre.length, background: S.red, transition: "flex 0.6s" }} />
                  <div style={{ flex: abst.length, background: S.gold, transition: "flex 0.6s" }} />
                </div>

                {/* Taux d'assiduité */}
                <div style={{ textAlign: "center", padding: "10px 0", borderTop: `1px solid ${S.border}` }}>
                  <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, marginBottom: 4 }}>
                    Taux de participation aux votes
                  </div>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 10 }}>
                    <div style={{ width: 200, height: 10, background: S.border, borderRadius: 99, overflow: "hidden" }}>
                      <div style={{
                        height: "100%", borderRadius: 99, transition: "width 0.8s",
                        width: `${Math.min(tauxAssiduite, 100)}%`,
                        background: tauxAssiduite >= 70 ? S.green : tauxAssiduite >= 40 ? S.gold : S.red,
                      }} />
                    </div>
                    <span style={{
                      fontFamily: S.fontTitle, fontSize: 18,
                      color: tauxAssiduite >= 70 ? S.green : tauxAssiduite >= 40 ? S.gold : S.red,
                    }}>{tauxAssiduite}%</span>
                  </div>
                  <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 4 }}>
                    {total} votes sur {totalScrutins} scrutins applicables
                  </div>
                </div>

                {/* Avertissement poste bloquant ou outre-mer */}
                {(act?.postes_bloquants?.length > 0 || act?.outre_mer) && (
                  <div style={{
                    marginTop: 12, padding: "10px 14px", borderRadius: 10,
                    background: "rgba(253,203,110,0.08)", border: `1px solid ${S.gold}33`,
                    fontFamily: S.font, fontSize: 12, color: S.gold, lineHeight: 1.5,
                    display: "flex", alignItems: "center", gap: 8,
                  }}>
                    <span style={{ fontSize: 18 }}>⚠️</span>
                    <div>
                      <div style={{ fontWeight: 800 }}>Taux impacté par sa situation</div>
                      <div style={{ color: S.textDim, fontSize: 11, marginTop: 2 }}>
                        {act?.postes_bloquants?.length > 0 && <>{act.postes_bloquants.join(", ")} — ces postes réduisent mécaniquement la participation aux votes.<br /></>}
                        {act?.outre_mer && <>Élu(e) d'outre-mer ou de l'étranger — les déplacements limitent la présence aux séances parisiennes.</>}
                      </div>
                    </div>
                  </div>
                )}

                {/* Détail activité (commissions + questions) */}
                {act && (act.nb_réunions_convoque > 0 || act.nb_questions > 0) && (
                  <div style={{ display: "flex", gap: 16, justifyContent: "center", flexWrap: "wrap", marginTop: 12, paddingTop: 12, borderTop: `1px solid ${S.border}` }}>
                    {act.nb_réunions_convoque > 0 && (
                      <div style={{ textAlign: "center" }}>
                        <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim }}>Commissions</div>
                        <div style={{ fontFamily: S.fontTitle, fontSize: 16, color: act.taux_commissions >= 50 ? S.green : act.taux_commissions >= 25 ? S.gold : S.red }}>
                          {Math.round(act.taux_commissions)}%
                        </div>
                        <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim }}>{act.nb_réunions_present}/{act.nb_réunions_convoque} réunions</div>
                      </div>
                    )}
                    {act.nb_questions > 0 && (
                      <div style={{ textAlign: "center" }}>
                        <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim }}>Questions écrites</div>
                        <div style={{ fontFamily: S.fontTitle, fontSize: 16, color: S.blue }}>{act.nb_questions}</div>
                        <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim }}>posées au gouvernement</div>
                      </div>
                    )}
                    {act.taux_global > 0 && (() => {
                      const dims = ["votes"];
                      if ((act.nb_réunions_convoque || 0) > 0) dims.push("commissions");
                      if ((act.nb_questions || 0) > 0) dims.push("questions");
                      return (
                        <div style={{ textAlign: "center" }}>
                          <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim }}>Activité globale</div>
                          <div style={{ fontFamily: S.fontTitle, fontSize: 16, color: act.taux_global >= 60 ? S.green : act.taux_global >= 35 ? S.gold : S.red }}>
                            {Math.round(act.taux_global)}%
                          </div>
                          <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim }}>{dims.join(" + ")}</div>
                        </div>
                      );
                    })()}
                    {act.rang > 0 && (
                      <div style={{ textAlign: "center" }}>
                        <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim }}>Classement {act.type_mandat || "Député"}</div>
                        <div style={{ fontFamily: S.fontTitle, fontSize: 16, color: act.rang <= 50 ? S.green : act.rang <= 300 ? S.gold : S.red }}>
                          {act.rang}e / {act.total_deputes}
                        </div>
                        <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim }}>parmi les députés</div>
                      </div>
                    )}
                  </div>
                )}
              </Card>

              {/* Filtres */}
              <div style={{ display: "flex", gap: 6, marginBottom: 12, flexWrap: "wrap" }}>
                {[
                  { label: "Tous", value: null, count: votesGroupes.length },
                  { label: "Pour", value: "Pour", count: pourG.length, color: S.green },
                  { label: "Contre", value: "Contre", count: contreG.length, color: S.red },
                  { label: "Abstention", value: "Abstention", count: abstG.length, color: S.gold },
                ].map(f => (
                  <button key={f.label} onClick={() => { setVoteFilter(f.value); }}
                    style={{
                      padding: "7px 14px", borderRadius: 99, border: "none", cursor: "pointer",
                      background: voteFilter === f.value ? (f.color || S.purple) + "22" : "rgba(255,255,255,0.04)",
                      color: voteFilter === f.value ? (f.color || S.textMain) : S.textDim,
                      fontFamily: S.font, fontSize: 13, fontWeight: 800,
                      borderWidth: 1, borderStyle: "solid",
                      borderColor: voteFilter === f.value ? (f.color || S.purple) + "66" : S.border,
                    }}>
                    {f.label} ({f.count})
                  </button>
                ))}
              </div>

              {/* Liste des votes filtrés */}
              {filtered.slice(0, 50).map((v, i) => (
                <Card key={i}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10 }}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <span style={{ fontFamily: S.font, fontSize: 14, color: S.textMain }}>{v.sujet}</span>
                      {v.date_vote && <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, marginTop: 2 }}>
                        {v.date_vote}{v.nb_sous_votes > 1 && <span style={{ color: S.purple, marginLeft: 6 }}>({v.nb_sous_votes} amendements)</span>}
                      </div>}
                    </div>
                    <span style={{
                      fontFamily: S.font, fontSize: 12, fontWeight: 800, flexShrink: 0,
                      color: (v.position || "").includes("Pour") ? S.green : (v.position || "").includes("Contre") ? S.red : S.gold,
                      background: (v.position || "").includes("Pour") ? "rgba(0,184,148,0.12)" : (v.position || "").includes("Contre") ? "rgba(255,107,107,0.12)" : "rgba(253,203,110,0.12)",
                      padding: "4px 12px", borderRadius: 99,
                    }}>{v.position}</span>
                  </div>
                </Card>
              ))}
              {filtered.length > 50 && (
                <div style={{ textAlign: "center", padding: 12, fontFamily: S.font, fontSize: 13, color: S.textDim }}>
                  ... et {filtered.length - 50} autres votes
                </div>
              )}
            </>
          );
        })()}
        {activeTab === "patrimoine" && (() => {
          const pd = elu.patrimoine_detail || elu.patrimoine_details || null;
          const indemnites = elu.indemnites || [];
          const pw = elu.patrimoine_warning || {};
          const activitesPub = elu.activites_publiques || [];
          const activitesPubActives = activitesPub.filter(a => a.actif);
          // Coût cumulé carrière — calculé côté API (même grille que palmarès)
          const fortuneEstimee = elu.cout_carriere || 0;

          const BarRow = ({ label, value, avgValue, color }) => {
            const max = Math.max(value, avgValue, 1);
            return (
              <div style={{ marginBottom: 14 }}>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 4 }}>
                  <span style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted, fontWeight: 700 }}>{label}</span>
                  <span style={{ fontFamily: S.font, fontSize: 13, fontWeight: 900, color }}>{fmt(value)}</span>
                </div>
                {/* Elu bar */}
                <div style={{ height: 8, background: S.border, borderRadius: 99, overflow: "hidden", marginBottom: 4 }}>
                  <div style={{
                    height: "100%", borderRadius: 99, background: color,
                    width: `${(value / max) * 100}%`, transition: "width 0.8s ease",
                  }} />
                </div>
                {/* Avg bar */}
                <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                  <div style={{ flex: 1, height: 4, background: S.border, borderRadius: 99, overflow: "hidden" }}>
                    <div style={{
                      height: "100%", borderRadius: 99,
                      background: S.textDim + "88",
                      width: `${(avgValue / max) * 100}%`, transition: "width 0.8s ease",
                    }} />
                  </div>
                  <span style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, whiteSpace: "nowrap" }}>moy. {fmt(avgValue)}</span>
                </div>
              </div>
            );
          };

          return (
            <>
              {/* Header coffre */}
              <div style={{
                position: "relative", overflow: "hidden", borderRadius: 16,
                background: `linear-gradient(180deg, ${S.card} 0%, #12121f 100%)`,
                border: `1px solid ${S.gold}22`, marginBottom: 16, padding: "20px 20px 16px",
              }}>
                <div style={{ position: "absolute", inset: 0, pointerEvents: "none", overflow: "hidden" }}>
                  {Array.from({ length: 10 }, (_, i) => (
                    <div key={i} style={{
                      position: "absolute", left: `${6 + i * 9}%`, top: -16,
                      width: 10, height: 10, borderRadius: "50%",
                      background: `linear-gradient(135deg, ${S.gold}, #f39c12)`,
                      animation: `coinDrop ${2.5 + (i % 4) * 0.5}s ${i * 0.35}s infinite ease-in`,
                    }} />
                  ))}
                </div>
                <div style={{ textAlign: "center", position: "relative" }}>
                  <div style={{ fontSize: 32, marginBottom: 6 }}>💎</div>
                  <div style={{ fontFamily: S.fontTitle, fontSize: 18, color: S.gold, marginBottom: 2 }}>Le Coffre-fort</div>
                  <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim }}>{elu.patrimoine || "Patrimoine déclaré HATVP"}</div>
                  {pd && fmt(pd.total) ? (
                    <>
                      <div style={{
                        marginTop: 12, display: "inline-block",
                        fontFamily: S.fontTitle, fontSize: 26, color: S.gold,
                        textShadow: `0 0 20px ${S.gold}66`,
                      }}>
                        {fmt(pd.total)}
                      </div>
                      <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, marginTop: 2 }}>total estimé · déclaration {pd.annee}</div>
                    </>
                  ) : fortuneEstimee > 0 ? (
                    <>
                      <div style={{
                        marginTop: 12, display: "inline-block",
                        fontFamily: S.fontTitle, fontSize: 26, color: S.gold,
                        textShadow: `0 0 20px ${S.gold}66`,
                      }}>
                        ~{fmt(fortuneEstimee)}
                      </div>
                      <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, marginTop: 2 }}>revenus cumulés estimés sur carrière</div>
                    </>
                  ) : null}
                </div>
              </div>

              {/* Badge non-déclarant HATVP */}
              {elu.hatvp_non_declarant === 1 && !elu.ancien_elu && (
                <div style={{
                  background: "rgba(255,107,107,0.08)", borderRadius: 12, padding: "12px 16px",
                  marginBottom: 10, border: "1px solid rgba(255,107,107,0.25)",
                  display: "flex", alignItems: "flex-start", gap: 10,
                }}>
                  <span style={{ fontSize: 16, flexShrink: 0 }}>⚠️</span>
                  <div>
                    <div style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.red }}>DIA non déposée à la HATVP</div>
                    <div style={{ fontFamily: S.font, fontSize: 11, color: "rgba(255,255,255,0.45)", marginTop: 3, lineHeight: 1.5 }}>
                      Cet élu n'a pas déposé sa Déclaration d'Intérêts et d'Activités (obligation légale — loi n°2013-907 du 11 oct. 2013).
                      Les rémunérations liées à d'autres activités publiques ne peuvent pas être vérifiées.
                    </div>
                  </div>
                </div>
              )}

              {/* Ancien élu : pas d'indemnités actuelles */}
              {elu.ancien_elu && (
                <div style={{ background: S.card, borderRadius: 12, padding: "14px 16px", marginBottom: 10, border: "1px solid rgba(255,255,255,0.08)", textAlign: "center" }}>
                  <div style={{ fontFamily: S.font, fontSize: 13, color: "rgba(255,255,255,0.4)" }}>🏛️ Ancien élu — plus de mandat actif</div>
                  <div style={{ fontFamily: S.font, fontSize: 11, color: "rgba(255,255,255,0.25)", marginTop: 4 }}>Le coût de carrière ci-dessous retrace l'ensemble des mandats exercés.</div>
                </div>
              )}

              {/* Indemnités en cours — uniquement si mandat actif */}
              {!elu.ancien_elu && indemnites.length > 0 && (
                <div style={{ background: S.card, borderRadius: 12, padding: "18px 16px", marginBottom: 10, border: `1px solid ${S.green}22` }}>
                  <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.green, marginBottom: 14, display: "flex", alignItems: "center", gap: 8 }}>
                    <div style={{ width: 4, height: 14, borderRadius: 2, background: S.green }} />
                    Indemnités en cours
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                    {indemnites.map((ind, i) => (
                      <div key={i} style={{
                        background: "rgba(255,255,255,0.03)", borderRadius: 10, padding: "12px 14px",
                        border: `1px solid ${S.green}22`,
                      }}>
                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 4 }}>
                          <span style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.textMain }}>{ind.mandat || ind.label}</span>
                          <span style={{ fontFamily: S.fontTitle, fontSize: 16, color: S.green }}>
                            {ind.brut ? fmt(ind.brut) : `${fmt(ind.brut_min) ?? "?"} - ${fmt(ind.brut_max) ?? "?"}`}
                            <span style={{ fontSize: 11, color: S.textDim }}>/mois brut</span>
                          </span>
                        </div>
                        {ind.net && <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim }}>Soit {fmt(ind.net)} net/mois</div>}
                        {ind.estimation && ind.detail && <div style={{ fontFamily: S.font, fontSize: 11, color: S.gold, marginTop: 4, fontStyle: "italic" }}>{ind.detail}</div>}
                        <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 4 }}>Source : {ind.source}</div>
                      </div>
                    ))}
                  </div>
                  {indemnites.length > 1 && (() => {
                    const totalBrut = indemnites.reduce((s, ind) => s + (ind.brut || ind.brut_max || 0), 0);
                    return totalBrut > 0 ? (
                      <div style={{
                        marginTop: 14, textAlign: "center", padding: "12px 16px",
                        background: `linear-gradient(135deg, rgba(0,184,148,0.08), rgba(0,184,148,0.02))`,
                        borderRadius: 10, border: `1px solid ${S.green}33`,
                      }}>
                        <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginBottom: 4 }}>Total cumulé mensuel</div>
                        <div style={{ fontFamily: S.fontTitle, fontSize: 24, color: S.green, textShadow: `0 0 16px ${S.green}44` }}>{fmt(totalBrut)}/mois brut</div>
                      </div>
                    ) : null;
                  })()}
                  {elu.indemnites_note && <div style={{ fontFamily: S.font, fontSize: 11, color: S.gold, marginTop: 10, textAlign: "center", fontStyle: "italic" }}>{elu.indemnites_note}</div>}
                </div>
              )}

              {/* ── Autres rémunérations sur fonds publics ── */}
              {activitesPub.length > 0 && (
                <div style={{ background: S.card, borderRadius: 12, padding: "18px 16px", marginBottom: 10, border: `1px solid ${S.blue}22` }}>
                  <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.blue, marginBottom: 14, display: "flex", alignItems: "center", gap: 8 }}>
                    <div style={{ width: 4, height: 14, borderRadius: 2, background: S.blue }} />
                    Autres rémunérations sur fonds publics
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                    {activitesPub.map((ap, i) => (
                      <div key={i} style={{
                        background: "rgba(255,255,255,0.03)", borderRadius: 10, padding: "12px 14px",
                        border: `1px solid ${ap.actif ? S.blue + "33" : S.border}`,
                        opacity: ap.actif ? 1 : 0.65,
                      }}>
                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 4, gap: 8 }}>
                          <div>
                            <span style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.textMain }}>{ap.titre}</span>
                            <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, marginTop: 2 }}>{ap.organisme}</div>
                          </div>
                          <div style={{ textAlign: "right", flexShrink: 0 }}>
                            <span style={{ fontFamily: S.fontTitle, fontSize: 15, color: ap.actif ? S.blue : S.textMuted }}>
                              {ap.montant_label}
                            </span>
                            {!ap.actif && (
                              <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim }}>
                                {ap.date_fin ? "Terminé " + ap.date_fin.substring(0,7) : "ancien"}
                              </div>
                            )}
                          </div>
                        </div>
                        {ap.date_debut && (
                          <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, marginBottom: 3 }}>
                            {ap.actif ? "Depuis " : ""}{ap.date_debut.substring(0,7)}
                            {!ap.actif && ap.date_fin ? " → " + ap.date_fin.substring(0,7) : ""}
                          </div>
                        )}
                        {ap.montant_detail && (
                          <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 4, fontStyle: "italic", lineHeight: 1.4 }}>
                            {ap.montant_detail}
                          </div>
                        )}
                        <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 3 }}>
                          Source : {ap.source_label}
                          {ap.source_url && (
                            <a href={ap.source_url} target="_blank" rel="noopener noreferrer"
                               style={{ color: S.blue, marginLeft: 6, textDecoration: "underline" }}>↗</a>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* ── Grand total toutes rémunérations publiques ── */}
              {activitesPubActives.length > 0 && !elu.ancien_elu && (() => {
                const totalIndemnites = indemnites.reduce((s, ind) => s + (ind.brut || ind.brut_max || 0), 0);
                const totalActPub = activitesPubActives.reduce((s, ap) => s + (ap.montant_mensuel || 0), 0);
                const grand = totalIndemnites + totalActPub;
                if (grand <= 0) return null;
                const hasPlafonné = !!elu.indemnites_plafond;
                return (
                  <div style={{
                    marginBottom: 10, textAlign: "center", padding: "16px",
                    background: `linear-gradient(135deg, rgba(9,132,227,0.10), rgba(9,132,227,0.03))`,
                    borderRadius: 12, border: `1px solid ${S.blue}44`,
                  }}>
                    <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, marginBottom: 6 }}>
                      Total toutes rémunérations publiques / mois brut
                    </div>
                    <div style={{ fontFamily: S.fontTitle, fontSize: 26, color: S.blue, textShadow: `0 0 16px ${S.blue}44` }}>
                      {new Intl.NumberFormat("fr-FR").format(grand)} €
                    </div>
                    <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 6, lineHeight: 1.5 }}>
                      Indemnités électives{hasPlafonné ? " (plafonnées)" : ""} + autres rémunérations sur fonds publics.
                      {totalActPub > 0 && ` Dont ${new Intl.NumberFormat("fr-FR").format(totalActPub)} € hors mandats électoraux.`}
                    </div>
                  </div>
                );
              })()}

              {pd ? (
                <>
                  {/* Breakdown bars */}
                  <div style={{ background: S.card, borderRadius: 12, padding: "18px 16px", marginBottom: 10, border: `1px solid ${S.gold}22` }}>
                    <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.gold, marginBottom: 14, display: "flex", alignItems: "center", gap: 8 }}>
                      <div style={{ width: 4, height: 14, borderRadius: 2, background: S.gold }} />
                      Répartition vs moyenne des élus
                    </div>
                    <BarRow label="🏠 Immobilier" value={pd.immobilier} avgValue={AVG_PATRIMOINE.immobilier} color={S.blue} />
                    <BarRow label="💼 Mobilier & placements" value={pd.mobilier} avgValue={AVG_PATRIMOINE.mobilier} color={S.purple} />
                    <BarRow label="💰 Revenus annuels" value={pd.revenus_annuels} avgValue={AVG_PATRIMOINE.revenus_annuels} color={S.green} />
                  </div>

                  {/* Salaires cumulés (seulement si pas d'indemnités API dynamiques et élu actif) */}
                  {pd.salaires && pd.salaires.length > 0 && !indemnites.length && !elu.ancien_elu && (
                    <div style={{ background: S.card, borderRadius: 12, padding: "18px 16px", marginBottom: 10, border: `1px solid ${S.gold}22` }}>
                      <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.gold, marginBottom: 14, display: "flex", alignItems: "center", gap: 8 }}>
                        <div style={{ width: 4, height: 14, borderRadius: 2, background: S.green }} />
                        Salaires mensuels
                      </div>
                      <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                        {pd.salaires.map((s, i) => (
                          <div key={i} style={{
                            display: "flex", alignItems: "center", justifyContent: "space-between",
                            background: "rgba(255,255,255,0.03)", borderRadius: 10, padding: "10px 14px",
                            border: `1px solid ${s.actif ? S.green + "33" : S.border}`,
                          }}>
                            <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                              <span style={{
                                fontFamily: S.font, fontSize: 12, fontWeight: 800, padding: "2px 8px",
                                borderRadius: 99, color: s.actif ? S.bg : S.textDim,
                                background: s.actif ? S.green : "rgba(255,255,255,0.08)",
                              }}>{s.actif ? "actif" : "ancien"}</span>
                              <span style={{ fontFamily: S.font, fontSize: 12, color: S.textMain, fontWeight: 600 }}>{s.poste}</span>
                            </div>
                            {s.montant_mensuel > 0 && <span style={{ fontFamily: S.fontTitle, fontSize: 14, color: s.actif ? S.green : S.textMuted }}>{fmt(s.montant_mensuel)}/mois</span>}
                          </div>
                        ))}
                      </div>
                      {indemnites.length > 1 && (() => {
                        const totalBrut = indemnites.reduce((s, ind) => s + (ind.brut || ind.brut_max || 0), 0);
                        return totalBrut > 0 ? (
                          <div style={{
                            marginTop: 14, textAlign: "center", padding: "12px 16px",
                            background: `linear-gradient(135deg, ${S.green}11, ${S.green}05)`,
                            borderRadius: 10, border: `1px solid ${S.green}33`,
                          }}>
                            <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginBottom: 4 }}>Total cumulé mensuel</div>
                            <div style={{ fontFamily: S.fontTitle, fontSize: 24, color: S.green, textShadow: `0 0 16px ${S.green}44` }}>{fmt(totalBrut)}/mois brut</div>
                          </div>
                        ) : null;
                      })()}
                    </div>
                  )}

                  {/* Fortune estimée */}
                  {pd.fortune_estimee > 0 && (
                    <div style={{
                      background: `linear-gradient(135deg, ${S.card}, #1a1a2e)`, borderRadius: 12,
                      padding: "18px 16px", marginBottom: 10,
                      border: `2px dashed ${S.gold}55`,
                    }}>
                      <div style={{ textAlign: "center" }}>
                        <div style={{ fontSize: 28, marginBottom: 6 }}>🏦</div>
                        <div style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.gold, letterSpacing: 1.5, textTransform: "uppercase", marginBottom: 10 }}>Fortune estimée</div>
                        <div style={{
                          fontFamily: S.fontTitle, fontSize: 28, color: S.gold,
                          textShadow: `0 0 24px ${S.gold}55`, marginBottom: 6,
                        }}>{fmt(pd.fortune_estimee)}</div>
                        {pd.fortune_source && (
                          <div style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted, marginBottom: 10 }}>
                            (d'apres {pd.fortune_source})
                          </div>
                        )}
                        <div style={{
                          fontFamily: S.font, fontSize: 12, color: S.textDim,
                          padding: "6px 12px", background: "rgba(255,255,255,0.03)",
                          borderRadius: 8, display: "inline-block",
                        }}>
                          Estimation media — non verifiable a 100%
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Evolution */}
                  {pd.evolution && pd.evolution.length > 1 && (
                    <div style={{ background: S.card, borderRadius: 12, padding: "18px 16px", marginBottom: 10, border: `1px solid ${S.border}` }}>
                      <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textMain, marginBottom: 14, display: "flex", alignItems: "center", gap: 8 }}>
                        <div style={{ width: 4, height: 14, borderRadius: 2, background: S.textDim }} />
                        Évolution du patrimoine
                      </div>
                      <div style={{ display: "flex", alignItems: "flex-end", gap: 8, height: 80 }}>
                        {pd.evolution.map((pt, i) => {
                          const maxVal = Math.max(...pd.evolution.map(p => p.total));
                          const pct = (pt.total / maxVal) * 100;
                          const delta = i > 0 ? pt.total - pd.evolution[i - 1].total : null;
                          return (
                            <div key={i} style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", gap: 4 }}>
                              {delta !== null && (
                                <span style={{ fontFamily: S.font, fontSize: 12, color: delta >= 0 ? S.green : S.red, fontWeight: 800 }}>
                                  {delta >= 0 ? "+" : ""}{fmt(delta)}
                                </span>
                              )}
                              <div style={{ width: "100%", background: S.border, borderRadius: 4, overflow: "hidden", height: 60, display: "flex", alignItems: "flex-end" }}>
                                <div style={{
                                  width: "100%", borderRadius: 4,
                                  background: `linear-gradient(180deg, ${S.gold}, #e67e22)`,
                                  height: `${pct}%`, transition: "height 0.8s ease",
                                }} />
                              </div>
                              <span style={{ fontFamily: S.font, fontSize: 12, color: S.textDim }}>{pt.annee}</span>
                              <span style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.gold }}>{fmt(pt.total)}</span>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  )}

                  {/* Sources */}
                  {pd.sources && pd.sources.length > 0 && (
                    <div style={{ background: S.card, borderRadius: 12, padding: "14px 16px", marginBottom: 10, border: `1px solid ${S.border}` }}>
                      <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textMuted, marginBottom: 10 }}>Sources déclarées</div>
                      {pd.sources.map((s, i) => (
                        <div key={i} style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, padding: "4px 0", borderBottom: i < pd.sources.length - 1 ? `1px solid ${S.border}` : "none" }}>
                          📌 {s}
                        </div>
                      ))}
                    </div>
                  )}

                  {/* Comparaison totale — seulement si pd.total est un nombre valide */}
                  {pd.total > 0 && (
                  <div style={{ background: S.card, borderRadius: 12, padding: "14px 16px", marginBottom: 10, border: `1px solid ${S.border}` }}>
                    <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textMuted, marginBottom: 10 }}>Comparaison avec les élus</div>
                    <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
                      <div style={{ flex: 1 }}>
                        <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginBottom: 4 }}>{elu.nom.split(" ").pop()}</div>
                        <div style={{ height: 10, background: S.border, borderRadius: 99, overflow: "hidden" }}>
                          <div style={{
                            height: "100%", borderRadius: 99,
                            background: pd.total > AVG_PATRIMOINE.total ? S.gold : S.blue,
                            width: `${Math.min((pd.total / Math.max(pd.total, AVG_PATRIMOINE.total)) * 100, 100)}%`,
                            transition: "width 0.8s ease",
                          }} />
                        </div>
                        <div style={{ fontFamily: S.font, fontSize: 13, fontWeight: 900, color: S.gold, marginTop: 3 }}>{fmt(pd.total)}</div>
                      </div>
                      <div style={{ fontFamily: S.fontTitle, fontSize: 13, color: S.textDim }}>vs</div>
                      <div style={{ flex: 1 }}>
                        <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginBottom: 4 }}>Moyenne élus</div>
                        <div style={{ height: 10, background: S.border, borderRadius: 99, overflow: "hidden" }}>
                          <div style={{
                            height: "100%", borderRadius: 99, background: S.textDim + "88",
                            width: `${Math.min((AVG_PATRIMOINE.total / Math.max(pd.total, AVG_PATRIMOINE.total)) * 100, 100)}%`,
                          }} />
                        </div>
                        <div style={{ fontFamily: S.font, fontSize: 13, fontWeight: 900, color: S.textMuted, marginTop: 3 }}>{fmt(AVG_PATRIMOINE.total)}</div>
                      </div>
                    </div>
                    <div style={{ marginTop: 10, padding: "8px 12px", background: "rgba(255,255,255,0.02)", borderRadius: 8, fontFamily: S.font, fontSize: 13, color: pd.total > AVG_PATRIMOINE.total ? S.gold : S.blue }}>
                      {pd.total > AVG_PATRIMOINE.total
                        ? `+${fmt(pd.total - AVG_PATRIMOINE.total)} au-dessus de la moyenne (×${(pd.total / AVG_PATRIMOINE.total).toFixed(1)})`
                        : `${fmt(AVG_PATRIMOINE.total - pd.total)} en-dessous de la moyenne`
                      }
                    </div>
                  </div>
                  )}
                </>
              ) : (
                <>
                  {/* Estimation revenus cumulés carrière */}
                  {fortuneEstimee > 0 && (
                    <div style={{
                      background: `linear-gradient(135deg, ${S.card}, #1a1a2e)`, borderRadius: 12,
                      padding: "18px 16px", marginBottom: 10, border: `2px dashed ${S.gold}55`,
                    }}>
                      <div style={{ textAlign: "center" }}>
                        <div style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.gold, letterSpacing: 1.5, textTransform: "uppercase", marginBottom: 10 }}>
                          Revenus cumulés estimés (carrière)
                        </div>
                        <div style={{
                          fontFamily: S.fontTitle, fontSize: 28, color: S.gold,
                          textShadow: `0 0 24px ${S.gold}55`, marginBottom: 6,
                        }}>{fmt(fortuneEstimee)}</div>
                        <div style={{
                          fontFamily: S.font, fontSize: 11, color: S.textDim, padding: "6px 12px",
                          background: "rgba(255,255,255,0.03)", borderRadius: 8, display: "inline-block",
                        }}>
                          Estimation basée sur les indemnités légales × durée des mandats
                        </div>
                      </div>
                    </div>
                  )}
                </>
              )}

              {/* Détail coût par mandat — toujours affiché si disponible */}
              {elu.cout_detail && Object.keys(elu.cout_detail).length > 0 && (
                <div style={{ background: S.card, borderRadius: 12, padding: "18px 16px", marginBottom: 10, border: `1px solid ${S.gold}22` }}>
                  <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.gold, marginBottom: 14, display: "flex", alignItems: "center", gap: 8 }}>
                    <div style={{ width: 4, height: 14, borderRadius: 2, background: S.gold }} />
                    Coût par type de mandat
                  </div>
                  {Object.entries(elu.cout_detail).sort((a, b) => b[1].reel - a[1].reel).map(([type, d]) => {
                    const label = type.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());
                    const pct = fortuneEstimee > 0 ? (d.reel / fortuneEstimee) * 100 : 0;
                    return (
                      <div key={type} style={{ marginBottom: 10 }}>
                        <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 3 }}>
                          <span style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted }}>{label}</span>
                          <span style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.gold }}>{fmt(d.reel)} <span style={{ fontSize: 11, color: S.textDim }}>({d.mois ?? "?"} mois)</span></span>
                        </div>
                        <div style={{ height: 6, background: S.border, borderRadius: 99, overflow: "hidden" }}>
                          <div style={{
                            height: "100%", borderRadius: 99,
                            background: `linear-gradient(90deg, ${S.gold}66, ${S.gold})`,
                            width: `${Math.min(pct, 100)}%`, transition: "width 0.8s ease",
                          }} />
                        </div>
                      </div>
                    );
                  })}
                  {fortuneEstimee > 0 && (
                    <div style={{ marginTop: 12, textAlign: "center", padding: "10px 14px", background: `linear-gradient(135deg, rgba(253,203,110,0.08), rgba(253,203,110,0.02))`, borderRadius: 10, border: `1px solid ${S.gold}33` }}>
                      <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, marginBottom: 4 }}>Coût total carrière</div>
                      <div style={{ fontFamily: S.fontTitle, fontSize: 22, color: S.gold, textShadow: `0 0 16px ${S.gold}44` }}>~{fmt(fortuneEstimee)}</div>
                      <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 4 }}>Indemnités légales × durée · plafonds et cumuls appliqués</div>
                    </div>
                  )}
                </div>
              )}

              {/* Warning HATVP — toujours affiché */}
              {pw.message && (
                <div style={{
                  background: pw.warning ? "rgba(255,71,87,0.08)" : pw.consultable ? "rgba(0,184,148,0.08)" : "rgba(255,255,255,0.03)",
                  borderRadius: 12, padding: "14px 16px", marginBottom: 10,
                  border: `1px solid ${pw.warning ? "#ff4757" : pw.consultable ? S.green : S.border}`,
                  display: "flex", alignItems: "flex-start", gap: 12,
                }}>
                  <span style={{ fontSize: 22, flexShrink: 0, marginTop: 1 }}>{pw.warning ? "🏛️" : pw.consultable ? "✅" : "ℹ️"}</span>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: pw.warning ? "#ff4757" : S.textMain }}>
                      {pw.message}
                    </div>
                    {pw.prefecture_nom && (
                      <div style={{ fontFamily: S.font, fontSize: 12, color: S.textMuted, marginTop: 5, lineHeight: 1.5 }}>
                        📍 <strong>{pw.prefecture_nom}</strong>
                        {pw.prefecture_ville ? ` — ${pw.prefecture_ville}` : ""}
                        <span style={{ color: S.textDim, fontSize: 11, display: "block", marginTop: 2 }}>
                          Consultation sur place sur simple demande, muni d'une pièce d'identité.
                        </span>
                      </div>
                    )}
                    {!pw.prefecture_nom && pw.warning && (
                      <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, marginTop: 4 }}>
                        Aucune préfecture identifiée — consultez la préfecture du département de l'élu.
                      </div>
                    )}
                    {pw.url && (
                      <a href={pw.url} target="_blank" rel="noreferrer" style={{
                        fontFamily: S.font, fontSize: 12, color: S.green, textDecoration: "underline", marginTop: 4, display: "inline-block",
                      }}>Consulter les déclarations</a>
                    )}
                    {pw.note_tardivite && (
                      <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, marginTop: 6, fontStyle: "italic" }}>
                        ⏳ {pw.note_tardivite}
                      </div>
                    )}
                    {pw.note_absurdite && (
                      <div style={{
                        marginTop: 10, padding: "8px 12px",
                        background: "rgba(255,71,87,0.06)", borderRadius: 8,
                        borderLeft: "3px solid #ff4757",
                      }}>
                        <div style={{ fontFamily: S.font, fontSize: 11, color: "#ff7888", lineHeight: 1.55, fontStyle: "italic" }}>
                          ⚖️ <strong style={{ color: "#ff4757" }}>Aberration juridique :</strong> {pw.note_absurdite}
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}

              <div style={{ textAlign: "center", marginTop: 12 }}>
                <a href={elu.url_hatvp ? `https://www.hatvp.fr${elu.url_hatvp}` : "https://www.hatvp.fr"} target="_blank" rel="noreferrer" style={{
                  display: "inline-block", padding: "10px 24px",
                  background: `linear-gradient(135deg, ${S.gold}, #f39c12)`,
                  color: S.bg, borderRadius: 99, fontSize: 12, fontWeight: 800,
                  fontFamily: S.font, textDecoration: "none",
                }}>{elu.url_hatvp ? "Consulter sa déclaration HATVP" : "Déclarations officielles sur hatvp.fr"}</a>
              </div>

              <style>{`
                @keyframes coinDrop {
                  0% { transform: translateY(-20px) rotate(0deg); opacity: 0; }
                  8% { opacity: 0.7; }
                  100% { transform: translateY(350px) rotate(540deg); opacity: 0; }
                }
              `}</style>
            </>
          );
        })()}
      </div>

      {/* Bouton signaler une erreur */}
      <div style={{ textAlign: "center", marginTop: 32 }}>
        <button onClick={() => navigate("/contact", { state: { sujet: "Signalement", message: `Erreur sur la fiche de ${elu.nom} (ID: ${elu.id}):\n\n` } })} style={{
          background: "rgba(255,255,255,0.04)", border: `1px solid ${S.border}`,
          borderRadius: 10, padding: "10px 20px", cursor: "pointer",
          fontFamily: S.font, fontSize: 12, fontWeight: 700, color: S.textDim,
          transition: "all 0.2s",
        }}
        onMouseEnter={e => { e.currentTarget.style.borderColor = S.red; e.currentTarget.style.color = S.red; }}
        onMouseLeave={e => { e.currentTarget.style.borderColor = S.border; e.currentTarget.style.color = S.textDim; }}
        >Signaler une erreur sur cette fiche</button>
      </div>

      {showPartiModal && elu.parti && (
        <PartiMembers parti={elu.parti} currentEluId={elu.id} onClose={() => setShowPartiModal(false)} />
      )}
      </>
      )}
    </div>
  );
};

export default EluProfile;
