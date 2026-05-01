import { useState, useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import QuoteCarousel from "../components/QuoteCarousel";
import { useSearch, useApi, prefetchElu } from "../hooks/useApi";
import { ELUS } from "../data/elus";
import Card from "../components/Card";
import Avatar from "../components/Avatar";
import PresidentielBanner from "../components/PresidentielBanner";
import { IconCasserole, IconStar, IconNetwork, IconVS, IconSearch } from "../components/Icons";
import Dice3D from "../components/Dice3D";
// TopLikes intégré dans le carrousel PresidentielBanner

const Home = () => {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const [showResults, setShowResults] = useState(false);
  const [focusedIndex, setFocusedIndex] = useState(-1);
  const [activeFeature, setActiveFeature] = useState(null);
  const [networkCenterId, setNetworkCenterId] = useState(5); // Macron par défaut (id=5)
  const searchRef = useRef(null);

  const slugify = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const normalize = (text) => (text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

  const { results: apiResults, loading: searchLoading } = useSearch(query);
  const { data: statsData } = useApi("stats.php");
  const topCasseroles = statsData?.top_casseroles || [];

  const results = query.length >= 2
    ? apiResults.length > 0
      ? apiResults
      : ELUS.filter(e => {
          const q = normalize(query);
          return normalize(e.nom).includes(q)
            || (e.alias || []).some(a => normalize(a).includes(q));
        })
    : [];

  useEffect(() => { setShowResults(query.length >= 2); setFocusedIndex(-1); }, [query]);

  // Prefetch les 3 premiers résultats pour clic instantané
  useEffect(() => {
    results.slice(0, 3).forEach(elu => {
      const slug = elu.slug || slugify(elu.nom);
      prefetchElu(slug);
    });
  }, [results]);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (searchRef.current && !searchRef.current.contains(e.target)) setShowResults(false);
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const handleSelect = (elu) => {
    setShowResults(false); setQuery("");
    const slug = elu.slug || slugify(elu.nom);
    navigate(`/elu/${slug}`, { state: { elu } });
  };

  const handleKeyDown = (e) => {
    if (!showResults || !results.length) return;
    if (e.key === "ArrowDown") { e.preventDefault(); setFocusedIndex(p => Math.min(p+1, results.length-1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setFocusedIndex(p => Math.max(p-1, 0)); }
    else if (e.key === "Enter" && focusedIndex >= 0) handleSelect(results[focusedIndex]);
    else if (e.key === "Escape") setShowResults(false);
  };

  const handleCompareRandom = () => {
    const shuffled = [...ELUS].sort(() => Math.random() - 0.5);
    navigate("/comparer", { state: { eluA: shuffled[0], eluB: shuffled[1] } });
  };

  const features = [
    { id: "casseroles", icon: <IconCasserole size={22} color={S.red} />, label: "Casseroles", desc: "Classement affaires", color: S.red },
    { id: "bande", icon: <IconNetwork size={22} color={S.purple} />, label: "La Bande", desc: "Réseau politique", color: S.purple },
    { id: "comparer", icon: <IconVS size={22} color={S.blue} />, label: "Comparer", desc: "Face à face aléatoire", color: S.blue },
    { id: "match", icon: "💘", label: "Match", desc: "L'élu de votre coeur", color: "#FF6B6B", link: "/match" },
  ];

  return (
    <div style={{ paddingTop: "6vh" }}>
      {/* Backdrop quand dropdown ouverte */}
      {showResults && (
        <div style={{
          position: "fixed", inset: 0, background: "rgba(0,0,0,0.3)",
          zIndex: 49,
        }} onClick={() => setShowResults(false)} />
      )}

      {/* Header : titre + citation + recherche */}
      <div style={{ textAlign: "center", animation: "slideUp 0.6s cubic-bezier(0.16,1,0.3,1)", marginBottom: 32 }}>
        <h1 style={{
          fontFamily: S.fontTitle, fontSize: "clamp(24px,5vw,44px)", color: S.gold,
          lineHeight: 1.1, marginBottom: 8,
          textShadow: "0 0 12px rgba(253,203,110,0.25), 0 0 30px rgba(253,203,110,0.1)",
        }}>
          Cherche un élu.
        </h1>
        <QuoteCarousel />
      </div>

      {/* Search + Random */}
      <div style={{
        display: "flex", alignItems: "center", gap: 10, maxWidth: 540, margin: "0 auto",
        position: "relative", zIndex: 50,
        animation: "slideUp 0.6s 0.15s cubic-bezier(0.16,1,0.3,1) both",
      }}>
        <Dice3D size={28} onClick={() => { window.location.href = "/api/random.php?redirect=1"; }} />
      <div ref={searchRef} style={{ position: "relative", flex: 1 }}>
        <input type="text" value={query} onChange={e => setQuery(e.target.value)} onKeyDown={handleKeyDown}
          placeholder="Nom d'un élu..."
          autoComplete="off"
          style={{
            width: "100%", padding: "16px 20px 16px 48px", fontSize: 15, fontWeight: 700,
            fontFamily: S.font, border: `1px solid ${searchLoading ? S.gold : S.gold + "44"}`, borderRadius: 12, outline: "none",
            color: "#fff", background: showResults ? S.card : "rgba(255,255,255,0.04)",
            boxShadow: searchLoading ? `0 0 20px ${S.gold}33, 0 0 40px ${S.gold}11` : showResults ? "0 0 30px rgba(253,203,110,0.15)" : "0 0 10px rgba(253,203,110,0.06)",
            transition: "all 0.3s", position: "relative", zIndex: 51,
            animation: searchLoading ? "searchPulse 1.2s ease-in-out infinite" : "none",
          }}
          onFocus={e => { if (query.length >= 2) setShowResults(true); }}
        />
        <span style={{
          position: "absolute", left: 18, top: "50%", transform: "translateY(-50%)",
          fontSize: 16, pointerEvents: "none", zIndex: 52,
          opacity: searchLoading ? 0.6 : 1, transition: "opacity 0.2s",
        }}>
          <IconSearch size={16} color={searchLoading ? S.gold : "#aaa"} />
        </span>

        {showResults && (
          <div style={{
            position: "absolute", top: "calc(100% + 4px)", left: 0, right: 0,
            background: S.card, border: `1px solid ${S.border}`, borderRadius: "0 0 12px 12px",
            boxShadow: "0 16px 48px rgba(0,0,0,0.7)", overflow: "hidden", zIndex: 999,
            maxHeight: 360, overflowY: "auto",
          }}>
            {searchLoading ? (
              <div style={{ padding: 20, textAlign: "center" }}>
                <div style={{
                  display: "inline-block", width: 24, height: 24, borderRadius: "50%",
                  border: `2px solid ${S.border}`, borderTopColor: S.gold,
                  animation: "searchSpin 0.6s linear infinite",
                  marginBottom: 8,
                }} />
                <div style={{ fontFamily: S.font, fontSize: 12, color: S.textMuted }}>
                  Recherche parmi 40 000+ elus...
                </div>
              </div>
            ) : results.length === 0 ? (
              <div style={{ padding: 20, fontSize: 13, color: S.textDim, textAlign: "center" }}>🤔 Personne trouvé pour « {query} »</div>
            ) : results.map((elu, i) => (
              <div key={elu.id} onClick={() => handleSelect(elu)} onMouseEnter={() => { setFocusedIndex(i); prefetchElu(elu.slug || slugify(elu.nom)); }}
                style={{
                  padding: "12px 18px", cursor: "pointer",
                  display: "flex", justifyContent: "space-between", alignItems: "center",
                  background: focusedIndex === i ? "rgba(253,203,110,0.08)" : "transparent",
                  borderBottom: i < results.length-1 ? "1px solid rgba(255,255,255,0.04)" : "none",
                  transition: "background 0.1s",
                }}>
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  <Avatar elu={elu} size={36} />
                  <div>
                    <div style={{ fontSize: 14, fontWeight: 800 }}>{elu.prenom ? `${elu.prenom} ${elu.nom}` : elu.nom}</div>
                    <div style={{ fontSize: 13, color: S.textDim }}>{elu.fonction}</div>
                  </div>
                </div>
                {Array.isArray(elu.affaires) && (
                  <span style={{
                    fontSize: 13, fontWeight: 800,
                    color: elu.affaires.length === 0 ? S.green : S.red,
                    background: elu.affaires.length === 0 ? "rgba(0,184,148,0.1)" : "rgba(255,107,107,0.1)",
                    padding: "3px 10px", borderRadius: 99,
                  }}>
                    {elu.affaires.length === 0 ? "✅ Clean" : `🍳 ${elu.affaires.length}`}
                  </span>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
      </div>

      {/* Catégories d'élus */}
      <div className="quick-tags" style={{
        display: "flex", gap: 8, marginTop: 18, flexWrap: "wrap", justifyContent: "center",
        animation: "slideUp 0.6s 0.3s cubic-bezier(0.16,1,0.3,1) both",
      }}>
        {[
          { label: "Députés", icon: "🏛️", color: S.purple },
          { label: "Sénateurs", icon: "🏛️", color: S.blue },
          { label: "Maires", icon: "🏘️", color: S.gold },
          { label: "Ministres", icon: "👔", color: S.red },
          { label: "Européens", icon: "🇪🇺", color: "#29b6f6" },
          { label: "Régionaux", icon: "🗺️", color: S.green },
          { label: "Départementaux", icon: "📍", color: "#e17055" },
        ].map((cat, i) => (
          <button key={cat.label} onClick={() => {
            const q = cat.label === "Députés" ? "Député" : cat.label === "Sénateurs" ? "Sénateur" : cat.label === "Maires" ? "Maire" : cat.label === "Ministres" ? "Ministre" : cat.label === "Européens" ? "européen" : cat.label === "Régionaux" ? "régional" : "départemental";
            navigate("/match", { state: { defaultSearch: q } });
          }} style={{
            background: "rgba(255,255,255,0.04)", border: `1px solid ${cat.color}33`,
            borderRadius: 99, padding: "6px 16px", fontSize: 14, fontWeight: 800,
            fontFamily: S.font, color: cat.color, cursor: "pointer", transition: "all 0.2s",
            display: "flex", alignItems: "center", gap: 6,
            whiteSpace: "nowrap", flexShrink: 0,
            animation: `stagger1 0.4s ${0.3 + i * 0.06}s cubic-bezier(0.16,1,0.3,1) both`,
          }}
          onMouseEnter={e => { e.currentTarget.style.background = cat.color + "22"; e.currentTarget.style.borderColor = cat.color; e.currentTarget.style.transform = "translateY(-2px)"; }}
          onMouseLeave={e => { e.currentTarget.style.background = "rgba(255,255,255,0.04)"; e.currentTarget.style.borderColor = cat.color + "33"; e.currentTarget.style.transform = "none"; }}
          >{cat.icon} {cat.label}</button>
        ))}
      </div>

      {/* Carrousel bannière */}
      <div style={{ marginTop: 32 }}>
        <PresidentielBanner />
      </div>

      {/* Disclaimer en bas */}
      <div style={{
        maxWidth: 900, margin: "40px auto 20px", padding: "8px 18px", borderRadius: 10,
        background: "rgba(255,107,107,0.04)", border: `1px solid ${S.red}22`,
        fontFamily: S.font, fontSize: 11, color: S.textDim, lineHeight: 1.5,
        textAlign: "center",
      }}>
        <span style={{ color: S.red, fontWeight: 900 }}>!</span> Ce site analyse plus de <strong style={{ color: S.textMuted }}>1 147 121</strong> documents publics. Certaines données peuvent être incomplètes ou déduites. Certaines données du gouvernement peuvent être obsolètes. Si vous constatez une erreur, <a href="/contact" style={{ color: S.red, textDecoration: "none", fontWeight: 800 }}>signalez-la</a>.
      </div>

    </div>
  );
};

export default Home;
