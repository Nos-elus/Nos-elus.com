import { useState, useEffect, useRef, lazy, Suspense } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { S } from "../utils/constants";
import { ELUS } from "../data/elus";
import { fetchApi } from "../hooks/useApi";
import Avatar from "../components/Avatar";
import Skeleton from "../components/Skeleton";

const RechartsRadar = lazy(() => import("recharts").then(m => ({
  default: ({ radar, color }) => {
    const { Radar, RadarChart, PolarGrid, PolarAngleAxis, PolarRadiusAxis, ResponsiveContainer } = m;
    const data = [
      { s: "Int.", v: radar.integrite }, { s: "Transp.", v: radar.transparence },
      { s: "Assid.", v: radar.assiduite }, { s: "Cohér.", v: radar.coherence },
      { s: "Bilan", v: radar.bilan },
    ];
    return (
      <ResponsiveContainer width="100%" height={160}>
        <RadarChart data={data}>
          <PolarGrid stroke="#2a2a42" />
          <PolarAngleAxis dataKey="s" tick={{ fill: S.textDim, fontSize: 9, fontWeight: 700 }} />
          <PolarRadiusAxis domain={[0, 10]} tick={false} axisLine={false} />
          <Radar dataKey="v" stroke={color} fill={color} fillOpacity={0.2} strokeWidth={2} />
        </RadarChart>
      </ResponsiveContainer>
    );
  }
})));

const slugify = (text) =>
  text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

// Score global : moyenne radar - malus affaires
const computeScore = (elu) => {
  const r = elu.radar;
  const radarAvg = (r.integrite + r.transparence + r.assiduite + r.coherence + r.bilan) / 5;
  const malus = Math.min(elu.affaires.length * 0.5, 3);
  return Math.max(0, Math.round((radarAvg - malus) * 10) / 10);
};

// Confetti particle
const Confetti = () => {
  const colors = [S.gold, "#ff6b6b", "#4ecdc4", "#a8e6cf", "#ffd3b6", S.purple];
  return (
    <div style={{ position: "absolute", inset: 0, pointerEvents: "none", overflow: "hidden", zIndex: 10 }}>
      {Array.from({ length: 30 }).map((_, i) => {
        const color = colors[i % colors.length];
        const left = `${Math.random() * 100}%`;
        const delay = `${Math.random() * 0.8}s`;
        const size = 4 + Math.floor(Math.random() * 6);
        return (
          <div key={i} style={{
            position: "absolute", top: "-10px", left,
            width: size, height: size,
            background: color, borderRadius: i % 3 === 0 ? "50%" : 2,
            animation: `confettiFall ${1.2 + Math.random() * 0.8}s ${delay} ease-in forwards`,
          }} />
        );
      })}
    </div>
  );
};

// Autocomplete search field
const EluSearch = ({ selected, onChange, color, label }) => {
  const [query, setQuery] = useState(selected?.nom || "");
  const [open, setOpen] = useState(false);
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [focusedIdx, setFocusedIdx] = useState(-1);
  const timerRef = useRef(null);
  const containerRef = useRef(null);

  useEffect(() => {
    setQuery(selected?.nom || "");
  }, [selected?.id]);

  useEffect(() => {
    const handleOutside = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", handleOutside);
    return () => document.removeEventListener("mousedown", handleOutside);
  }, []);

  const search = (val) => {
    setQuery(val);
    setFocusedIdx(-1);
    if (val.length < 2) { setResults([]); setOpen(false); return; }
    setLoading(true);
    setOpen(true);
    clearTimeout(timerRef.current);
    timerRef.current = setTimeout(async () => {
      try {
        const api = await fetchApi("search.php", { q: val });
        const apiList = Array.isArray(api) ? api : [];
        const local = ELUS.filter(e => e.nom.toLowerCase().includes(val.toLowerCase()));
        const merged = apiList.length > 0 ? apiList : local;
        setResults(merged.slice(0, 8));
      } catch {
        setResults(ELUS.filter(e => e.nom.toLowerCase().includes(val.toLowerCase())).slice(0, 8));
      } finally {
        setLoading(false);
      }
    }, 250);
  };

  const select = (elu) => {
    onChange(elu);
    setQuery(elu.nom);
    setOpen(false);
    setResults([]);
  };

  const handleKey = (e) => {
    if (!open || !results.length) return;
    if (e.key === "ArrowDown") { e.preventDefault(); setFocusedIdx(p => Math.min(p + 1, results.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setFocusedIdx(p => Math.max(p - 1, 0)); }
    else if (e.key === "Enter" && focusedIdx >= 0) select(results[focusedIdx]);
    else if (e.key === "Escape") setOpen(false);
  };

  return (
    <div ref={containerRef} style={{ position: "relative" }}>
      <div style={{ fontSize: 10, fontWeight: 800, color, marginBottom: 5, fontFamily: S.font, textTransform: "uppercase", letterSpacing: 1 }}>
        {label}
      </div>
      <div style={{ position: "relative" }}>
        <input
          value={query}
          onChange={e => search(e.target.value)}
          onKeyDown={handleKey}
          onFocus={() => { if (query.length >= 2 && results.length) setOpen(true); }}
          placeholder="Tapez un nom..."
          autoComplete="off"
          style={{
            width: "100%", boxSizing: "border-box",
            padding: "10px 14px 10px 38px",
            background: S.card, color: S.textMain,
            border: `1px solid ${open ? color + "88" : S.border}`,
            borderRadius: open && results.length ? "8px 8px 0 0" : 8,
            fontFamily: S.font, fontSize: 13, fontWeight: 700,
            outline: "none", transition: "border 0.2s",
          }}
        />
        <span style={{ position: "absolute", left: 12, top: "50%", transform: "translateY(-50%)", fontSize: 14, pointerEvents: "none" }}>
          {loading ? "⏳" : "🔍"}
        </span>
      </div>
      {open && results.length > 0 && (
        <div style={{
          position: "absolute", top: "100%", left: 0, right: 0, zIndex: 100,
          background: S.card, border: `1px solid ${color + "44"}`,
          borderTop: "none", borderRadius: "0 0 8px 8px",
          boxShadow: "0 12px 32px rgba(0,0,0,0.6)", overflow: "hidden",
        }}>
          {results.map((elu, i) => (
            <div key={elu.id} onClick={() => select(elu)} onMouseEnter={() => setFocusedIdx(i)}
              style={{
                padding: "9px 14px", cursor: "pointer",
                display: "flex", alignItems: "center", gap: 10,
                background: focusedIdx === i ? `${color}18` : "transparent",
                borderBottom: i < results.length - 1 ? `1px solid ${S.border}` : "none",
                transition: "background 0.1s",
              }}>
              <Avatar elu={elu} size={30} showBorder={false} />
              <div>
                <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textMain }}>{elu.nom}</div>
                <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim }}>{elu.parti}</div>
              </div>
              {Array.isArray(elu.affaires) && (
                <span style={{
                  marginLeft: "auto", fontSize: 10, fontWeight: 800,
                  color: elu.affaires.length === 0 ? S.green : S.red,
                }}>
                  {elu.affaires.length === 0 ? "✅" : `🍳 ${elu.affaires.length}`}
                </span>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

// Visual comparison bar
const CompareBar = ({ valA, valB, reverse, color }) => {
  const total = valA + valB;
  if (total === 0) {
    return (
      <div style={{ display: "flex", alignItems: "center", gap: 4, marginTop: 6 }}>
        <div style={{ flex: 1, height: 4, background: S.border, borderRadius: 99 }} />
        <span style={{ fontSize: 9, color: S.textDim, fontFamily: S.font }}>égalité</span>
        <div style={{ flex: 1, height: 4, background: S.border, borderRadius: 99 }} />
      </div>
    );
  }
  const pctA = (valA / total) * 100;
  const winA = reverse ? valA < valB : valA > valB;
  const winB = reverse ? valB < valA : valB > valA;
  const colorA = winA ? S.gold : S.textDim + "66";
  const colorB = winB ? S.purple : S.textDim + "66";

  return (
    <div style={{ display: "flex", alignItems: "center", gap: 4, marginTop: 6 }}>
      <div style={{ flex: pctA, height: 5, background: colorA, borderRadius: 99, transition: "flex 0.6s ease", minWidth: 4 }} />
      <div style={{ flex: 100 - pctA, height: 5, background: colorB, borderRadius: 99, transition: "flex 0.6s ease", minWidth: 4 }} />
    </div>
  );
};

const Comparator = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const elus = ELUS;

  const [eluA, setEluA] = useState(location.state?.eluA || elus[0]);
  const [eluB, setEluB] = useState(location.state?.eluB || elus[1]);
  const [expandedStat, setExpandedStat] = useState(null);
  // spinning remplacé par spinSide
  const [showConfetti, setShowConfetti] = useState(false);

  useEffect(() => { window.scrollTo(0, 0); }, []);

  useEffect(() => {
    setShowConfetti(true);
    const t = setTimeout(() => setShowConfetti(false), 2000);
    return () => clearTimeout(t);
  }, [eluA.id, eluB.id]);

  const scoreA = computeScore(eluA);
  const scoreB = computeScore(eluB);
  const winnerA = scoreA > scoreB;
  const winnerB = scoreB > scoreA;
  const tie = scoreA === scoreB;

  const handleChangeA = (elu) => { setEluA(elu); setExpandedStat(null); };
  const handleChangeB = (elu) => { setEluB(elu); setExpandedStat(null); };

  const [spinSide, setSpinSide] = useState(null); // "left", "right", "both", null

  const randomize = (side = "both") => {
    setSpinSide(side);
    setExpandedStat(null);
    setTimeout(() => {
      const shuffled = [...elus].sort(() => Math.random() - 0.5);
      if (side === "left" || side === "both") {
        const pick = shuffled.find(e => e.id !== eluB.id);
        if (pick) setEluA(pick);
      }
      if (side === "right" || side === "both") {
        const pick = shuffled.find(e => e.id !== eluA.id);
        if (pick) setEluB(pick);
      }
      setSpinSide(null);
    }, 600);
  };

  const stats = [
    { id: "casseroles", label: "🍳 Casseroles", getVal: e => e.affaires.length, reverse: true },
    { id: "votes", label: "🗳️ Votes", getVal: e => e.votes.length, reverse: false },
    { id: "reseau", label: "🎭 Réseau", getVal: e => e.affiliations.length, reverse: false },
    { id: "mandats", label: "📋 Mandats", getVal: e => e.mandats.length, reverse: false },
  ];

  return (
    <div style={{ animation: "slideUp 0.5s cubic-bezier(0.16,1,0.3,1)", maxWidth: 720, margin: "0 auto", paddingTop: 32 }}>
      <button onClick={() => navigate(-1)} style={{
        background: S.card, border: `1px solid ${S.border}`, borderRadius: 99,
        cursor: "pointer", fontFamily: S.font, fontSize: 13, fontWeight: 700,
        color: S.textMuted, marginBottom: 24, padding: "8px 18px", transition: "all 0.2s",
      }}
        onMouseEnter={e => { e.target.style.color = "#fff"; }}
        onMouseLeave={e => { e.target.style.color = S.textMuted; }}
      >← Retour</button>

      <div style={{
        textAlign: "center", marginBottom: 24,
        fontFamily: S.fontTitle, fontSize: 22, color: S.gold,
        textShadow: "0 0 10px rgba(253,203,110,0.2)",
      }}>⚔️ Le Face à Face</div>

      {/* Autocomplete selectors */}
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16, marginBottom: 24 }}>
        <EluSearch selected={eluA} onChange={handleChangeA} color={S.gold} label="Combattant A" />
        <EluSearch selected={eluB} onChange={handleChangeB} color={S.purple} label="Combattant B" />
      </div>

      {/* Profiles */}
      <div style={{ display: "grid", gridTemplateColumns: "1fr auto 1fr", gap: 8, marginBottom: 24, alignItems: "center" }}>

        {/* Elu A card */}
        <div style={{
          position: "relative", overflow: "hidden",
          background: winnerA && !tie ? `linear-gradient(135deg, ${S.card}, ${S.gold}18)` : S.card,
          borderRadius: 16, padding: 20,
          border: `2px solid ${winnerA && !tie ? S.gold : tie ? S.border : S.border + "88"}`,
          textAlign: "center",
          opacity: (spinSide === "left" || spinSide === "both") ? 0.3 : winnerB && !tie ? 0.55 : 1,
          transition: "opacity 0.4s, border 0.4s, background 0.4s",
          filter: winnerB && !tie ? "grayscale(0.6)" : "none",
          boxShadow: winnerA && !tie ? `0 0 30px ${S.gold}33` : "none",
        }}>
          {winnerA && !tie && showConfetti && <Confetti />}
          {winnerA && !tie && (
            <div style={{
              position: "absolute", top: 0, left: 0, right: 0,
              background: `linear-gradient(90deg, ${S.gold}, #f39c12, ${S.gold})`,
              backgroundSize: "200% 100%",
              padding: "6px 0",
              fontFamily: S.fontTitle, fontSize: 11, color: S.bg,
              letterSpacing: 3, animation: "shimmer 2s linear infinite",
            }}>
              ★ WINNER ★
            </div>
          )}
          <div style={{ marginTop: winnerA && !tie ? 28 : 0 }}>
            <button onClick={(e) => { e.stopPropagation(); randomize("left"); }} title="Changer ce combattant" style={{
              position: "absolute", top: winnerA && !tie ? 32 : 6, right: 6, width: 24, height: 24, borderRadius: "50%",
              background: "rgba(255,255,255,0.08)", border: `1px solid ${S.border}`, cursor: "pointer",
              display: "flex", alignItems: "center", justifyContent: "center",
              fontSize: 12, color: S.textDim, transition: "all 0.2s", zIndex: 2,
            }}
            onMouseEnter={e => { e.currentTarget.style.background = S.gold; e.currentTarget.style.color = S.bg; }}
            onMouseLeave={e => { e.currentTarget.style.background = "rgba(255,255,255,0.08)"; e.currentTarget.style.color = S.textDim; }}
            >↻</button>
            <Avatar elu={eluA} size={72} />
            <div style={{ fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.textMain, marginTop: 10 }}>{eluA.nom}</div>
            <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, marginTop: 2 }}>{eluA.parti}</div>
            <div style={{
              fontFamily: S.fontTitle, fontSize: 22, marginTop: 8,
              color: winnerA && !tie ? S.gold : S.textMuted,
            }}>{scoreA}</div>
            <div style={{ fontFamily: S.font, fontSize: 9, color: S.textDim }}>score</div>
            <Suspense fallback={<Skeleton height={160} radius={8} />}>
              <RechartsRadar radar={eluA.radar} color={S.gold} />
            </Suspense>
          </div>
        </div>

        {/* Revanche button */}
        <button onClick={randomize} style={{
          width: 64, height: 64, borderRadius: "50%",
          background: `linear-gradient(135deg, ${S.gold}, #e67e22)`,
          border: "3px solid rgba(255,255,255,0.15)",
          cursor: "pointer", fontSize: 26,
          display: "flex", alignItems: "center", justifyContent: "center",
          boxShadow: "0 0 28px rgba(253,203,110,0.5), 0 0 8px rgba(253,203,110,0.3)",
          animation: "pulseGlow 1.8s infinite ease-in-out",
          transition: "transform 0.2s, box-shadow 0.2s",
          flexShrink: 0,
        }}
          onMouseEnter={e => { e.currentTarget.style.transform = "scale(1.2) rotate(15deg)"; e.currentTarget.style.boxShadow = "0 0 48px rgba(253,203,110,0.8)"; }}
          onMouseLeave={e => { e.currentTarget.style.transform = "scale(1) rotate(0deg)"; e.currentTarget.style.boxShadow = "0 0 28px rgba(253,203,110,0.5)"; }}
          title="Revanche — match aléatoire"
        >
          <span style={{ display: "inline-block", animation: spinSide ? "spin 0.6s linear" : "none" }}>↻</span>
        </button>

        {/* Elu B card */}
        <div style={{
          position: "relative", overflow: "hidden",
          background: winnerB && !tie ? `linear-gradient(135deg, ${S.card}, ${S.purple}18)` : S.card,
          borderRadius: 16, padding: 20,
          border: `2px solid ${winnerB && !tie ? S.purple : tie ? S.border : S.border + "88"}`,
          textAlign: "center",
          opacity: (spinSide === "right" || spinSide === "both") ? 0.3 : winnerA && !tie ? 0.55 : 1,
          transition: "opacity 0.4s, border 0.4s, background 0.4s",
          filter: winnerA && !tie ? "grayscale(0.6)" : "none",
          boxShadow: winnerB && !tie ? `0 0 30px ${S.purple}33` : "none",
        }}>
          {winnerB && !tie && showConfetti && <Confetti />}
          {winnerB && !tie && (
            <div style={{
              position: "absolute", top: 0, left: 0, right: 0,
              background: `linear-gradient(90deg, ${S.purple}, #a29bfe, ${S.purple})`,
              backgroundSize: "200% 100%",
              padding: "6px 0",
              fontFamily: S.fontTitle, fontSize: 11, color: "#fff",
              letterSpacing: 3, animation: "shimmer 2s linear infinite",
            }}>
              ★ WINNER ★
            </div>
          )}
          <div style={{ marginTop: winnerB && !tie ? 28 : 0 }}>
            <button onClick={(e) => { e.stopPropagation(); randomize("right"); }} title="Changer ce combattant" style={{
              position: "absolute", top: winnerB && !tie ? 32 : 6, right: 6, width: 24, height: 24, borderRadius: "50%",
              background: "rgba(255,255,255,0.08)", border: `1px solid ${S.border}`, cursor: "pointer",
              display: "flex", alignItems: "center", justifyContent: "center",
              fontSize: 12, color: S.textDim, transition: "all 0.2s", zIndex: 2,
            }}
            onMouseEnter={e => { e.currentTarget.style.background = S.purple; e.currentTarget.style.color = "#fff"; }}
            onMouseLeave={e => { e.currentTarget.style.background = "rgba(255,255,255,0.08)"; e.currentTarget.style.color = S.textDim; }}
            >↻</button>
            <Avatar elu={eluB} size={72} />
            <div style={{ fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.textMain, marginTop: 10 }}>{eluB.nom}</div>
            <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, marginTop: 2 }}>{eluB.parti}</div>
            <div style={{
              fontFamily: S.fontTitle, fontSize: 22, marginTop: 8,
              color: winnerB && !tie ? S.purple : S.textMuted,
            }}>{scoreB}</div>
            <div style={{ fontFamily: S.font, fontSize: 9, color: S.textDim }}>score</div>
            <Suspense fallback={<Skeleton height={160} radius={8} />}>
              <RechartsRadar radar={eluB.radar} color={S.purple} />
            </Suspense>
          </div>
        </div>
      </div>

      {/* Tie banner */}
      {tie && (
        <div style={{
          textAlign: "center", padding: "10px 20px", marginBottom: 16,
          background: "rgba(253,203,110,0.08)", borderRadius: 10,
          border: `1px solid ${S.gold}44`,
          fontFamily: S.fontTitle, fontSize: 13, color: S.gold,
        }}>
          🤝 MATCH NUL — Score {scoreA} / {scoreB}
        </div>
      )}

      {/* Stats */}
      <div style={{ background: S.card, borderRadius: 16, border: `1px solid ${S.border}`, overflow: "hidden" }}>
        {stats.map((stat) => {
          const valA = stat.getVal(eluA);
          const valB = stat.getVal(eluB);
          const winA = stat.reverse ? valA < valB : valA > valB;
          const winB = stat.reverse ? valB < valA : valB > valA;
          const tie = valA === valB;
          const expanded = expandedStat === stat.id;

          return (
            <div key={stat.id}>
              <div onClick={() => setExpandedStat(expanded ? null : stat.id)} style={{
                cursor: "pointer", transition: "background 0.15s",
                background: expanded ? "rgba(253,203,110,0.04)" : "transparent",
                borderBottom: `1px solid ${S.border}`,
                padding: "12px 20px",
              }}
                onMouseEnter={e => { if (!expanded) e.currentTarget.style.background = "rgba(255,255,255,0.02)"; }}
                onMouseLeave={e => { if (!expanded) e.currentTarget.style.background = "transparent"; }}
              >
                <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                  <span style={{ flex: 1, textAlign: "right", fontFamily: S.font, fontSize: 14, fontWeight: 800, color: winA && !tie ? S.gold : S.textMuted }}>
                    {winA && !tie ? "🏆 " : ""}{valA}
                  </span>
                  <span style={{ width: 110, textAlign: "center", fontFamily: S.font, fontSize: 11, color: S.textDim, fontWeight: 700 }}>
                    {stat.label} {expanded ? "▲" : "▼"}
                  </span>
                  <span style={{ flex: 1, textAlign: "left", fontFamily: S.font, fontSize: 14, fontWeight: 800, color: winB && !tie ? S.purple : S.textMuted }}>
                    {valB}{winB && !tie ? " 🏆" : ""}
                  </span>
                </div>
                {/* Visual bar */}
                <CompareBar valA={valA} valB={valB} reverse={stat.reverse} />
              </div>

              {/* Expanded details */}
              {expanded && (
                <div style={{
                  display: "grid", gridTemplateColumns: "1fr 1fr", gap: 1,
                  background: S.border, animation: "slideUp 0.2s ease",
                  borderBottom: `1px solid ${S.border}`,
                }}>
                  {[eluA, eluB].map((elu) => (
                    <div key={elu.id} style={{ background: S.bg, padding: "10px 14px" }}>
                      {stat.id === "casseroles" && (
                        elu.affaires.length === 0
                          ? <div style={{ fontFamily: S.font, fontSize: 11, color: S.green, textAlign: "center" }}>✅ Aucune affaire</div>
                          : elu.affaires.map((a, i) => (
                            <div key={i} style={{ fontFamily: S.font, fontSize: 11, color: S.textMuted, marginBottom: 6, lineHeight: 1.4 }}>
                              <span style={{ fontWeight: 800, color: S.textMain }}>{a.titre}</span>
                              <br /><span style={{ fontSize: 10, color: a.statut === "Condamné" ? S.red : a.statut === "Relaxé" ? S.green : S.gold }}>{a.statut}</span>
                            </div>
                          ))
                      )}
                      {stat.id === "votes" && (
                        elu.votes.length === 0
                          ? <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, textAlign: "center" }}>Aucun vote</div>
                          : elu.votes.map((v, i) => (
                            <div key={i} style={{ fontFamily: S.font, fontSize: 11, color: S.textMuted, marginBottom: 4 }}>
                              {v.sujet} — <span style={{ color: v.position.includes("Pour") ? S.green : S.red, fontWeight: 700 }}>{v.position}</span>
                            </div>
                          ))
                      )}
                      {stat.id === "reseau" && (
                        elu.affiliations.map((a, i) => (
                          <div key={i} style={{ fontFamily: S.font, fontSize: 11, color: S.textMuted, marginBottom: 4 }}>
                            {a.emoji} {a.nom || a.nom_personne} <span style={{ color: S.textDim }}>({a.lien || a.type_lien})</span>
                          </div>
                        ))
                      )}
                      {stat.id === "mandats" && (
                        (Array.isArray(elu.mandats) ? elu.mandats : []).map((m, i) => (
                          <div key={i} style={{ fontFamily: S.font, fontSize: 11, color: S.textMuted, marginBottom: 4 }}>
                            🏛️ {typeof m === "string" ? m : m.titre}
                          </div>
                        ))
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>

      <style>{`
        @keyframes pulseGlow {
          0%, 100% { box-shadow: 0 0 28px rgba(253,203,110,0.5), 0 0 8px rgba(253,203,110,0.3); }
          50% { box-shadow: 0 0 48px rgba(253,203,110,0.8), 0 0 20px rgba(253,203,110,0.5); }
        }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @keyframes shimmer {
          0% { background-position: 200% 0; }
          100% { background-position: -200% 0; }
        }
        @keyframes confettiFall {
          0% { transform: translateY(0) rotate(0deg); opacity: 1; }
          100% { transform: translateY(300px) rotate(720deg); opacity: 0; }
        }
      `}</style>
    </div>
  );
};

export default Comparator;
