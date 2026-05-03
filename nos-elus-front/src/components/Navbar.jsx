import { useState, useEffect, useRef } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import { useSearch, prefetchElu } from "../hooks/useApi";
import { ELUS } from "../data/elus";
import Avatar from "./Avatar";
import Dice3D from "./Dice3D";
import { Pres2027Pill } from "./Pres2027Countdown";

const slugify = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const norm = (text) => (text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const SOUTENIR_MESSAGES = [
  { text: "Offrir un cafe", icon: "☕" },
  { text: "Offrir un cognac", icon: "🥃" },
  { text: "Payer l'apero", icon: "🍻" },
];

const NAV_LINKS = [
  { to: "/match", label: "Match", icon: "💘", color: S.red, bg: "rgba(255,107,107,0.12)", border: "rgba(255,107,107,0.2)" },
  { to: "/palmares", label: "Palmares", icon: "🏆", color: S.purple, bg: "rgba(108,92,231,0.12)", border: "rgba(108,92,231,0.2)" },
  { to: "/2027", label: "2027", icon: null, color: S.gold, bg: "rgba(253,203,110,0.12)", border: "rgba(253,203,110,0.2)", titleFont: true, pulse: "pulse2027" },
  { to: "/nous-aider", label: "Nous aider", icon: "🤝", color: "#0f0f1a", bg: S.gold, border: S.gold, solid: true },
];

const Navbar = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const isHome = location.pathname === "/";

  const [query, setQuery] = useState("");
  const [showResults, setShowResults] = useState(false);
  const [focusedIndex, setFocusedIndex] = useState(-1);
  const [menuOpen, setMenuOpen] = useState(false);
  const searchRef = useRef(null);
  const searchRefMobile = useRef(null);
  const drawerRef = useRef(null);
  const hamburgerBtnRef = useRef(null);
  const closeBtnRef = useRef(null);
  const wasOpenRef = useRef(false);
  const [soutenirMsg] = useState(() => SOUTENIR_MESSAGES[Math.floor(Math.random() * SOUTENIR_MESSAGES.length)]);

  const { results: apiResults, loading: searchLoading } = useSearch(query);

  const results = query.length >= 2
    ? apiResults.length > 0
      ? apiResults
      : ELUS.filter(e => {
          const q = norm(query);
          return norm(e.nom).includes(q) || (e.alias || []).some(a => norm(a).includes(q));
        })
    : [];

  useEffect(() => {
    setShowResults(query.length >= 2);
    setFocusedIndex(-1);
  }, [query]);

  useEffect(() => {
    setShowResults(false);
    setQuery("");
    setMenuOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    const handleClickOutside = (e) => {
      const inDesktop = searchRef.current && searchRef.current.contains(e.target);
      const inMobile = searchRefMobile.current && searchRefMobile.current.contains(e.target);
      if (!inDesktop && !inMobile) setShowResults(false);
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  // Lock body scroll when drawer open
  useEffect(() => {
    document.body.style.overflow = menuOpen ? "hidden" : "";
    return () => { document.body.style.overflow = ""; };
  }, [menuOpen]);

  useEffect(() => {
    if (!menuOpen) return;
    closeBtnRef.current?.focus();
    const handleKey = (e) => {
      if (e.key === "Escape") { setMenuOpen(false); return; }
      if (e.key !== "Tab" || !drawerRef.current) return;
      const focusables = drawerRef.current.querySelectorAll(
        'a[href], button, input, [tabindex]:not([tabindex="-1"])'
      );
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener("keydown", handleKey);
    return () => document.removeEventListener("keydown", handleKey);
  }, [menuOpen]);

  useEffect(() => {
    if (wasOpenRef.current && !menuOpen) hamburgerBtnRef.current?.focus();
    wasOpenRef.current = menuOpen;
  }, [menuOpen]);

  const handleSelect = (elu) => {
    setShowResults(false); setQuery(""); setMenuOpen(false);
    const slug = elu.slug || slugify(elu.nom);
    if (location.pathname.startsWith('/elu/')) {
      window.location.href = `/elu/${slug}`;
    } else {
      navigate(`/elu/${slug}`, { state: { elu } });
    }
  };

  const handleKeyDown = (e) => {
    if (!showResults || !results.length) return;
    if (e.key === "ArrowDown") { e.preventDefault(); setFocusedIndex(p => Math.min(p + 1, results.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setFocusedIndex(p => Math.max(p - 1, 0)); }
    else if (e.key === "Enter" && focusedIndex >= 0) handleSelect(results[focusedIndex]);
    else if (e.key === "Escape") setShowResults(false);
  };

  const SearchDropdown = () => showResults ? (
    <div style={{
      position: "absolute", top: "100%", left: 0, right: 0,
      background: "rgba(17,24,39,0.98)", border: `1px solid ${S.gold}33`, borderTop: "none",
      borderRadius: "0 0 10px 10px",
      boxShadow: "0 12px 40px rgba(0,0,0,0.6)", overflow: "hidden",
      maxHeight: 320, overflowY: "auto", zIndex: 201,
    }}>
      {searchLoading ? (
        <div style={{ padding: 16, fontSize: 12, color: S.textDim, textAlign: "center" }}>Recherche...</div>
      ) : results.length === 0 ? (
        <div style={{ padding: 16, fontSize: 12, color: S.textDim, textAlign: "center" }}>Aucun résultat pour "{query}"</div>
      ) : results.map((elu, i) => (
        <div key={elu.id} onClick={() => handleSelect(elu)} onMouseEnter={() => { setFocusedIndex(i); prefetchElu(elu.slug || slugify(elu.nom)); }}
          style={{
            padding: "10px 14px", cursor: "pointer",
            display: "flex", alignItems: "center", gap: 10,
            background: focusedIndex === i ? "rgba(253,203,110,0.08)" : "transparent",
            borderBottom: i < results.length - 1 ? "1px solid rgba(255,255,255,0.04)" : "none",
            transition: "background 0.1s", minHeight: 44,
          }}>
          <Avatar elu={elu} size={28} showBorder={false} />
          <div>
            <div style={{ fontSize: 13, fontWeight: 700, color: "#fff" }}>{elu.prenom ? `${elu.prenom} ${elu.nom}` : elu.nom}</div>
            <div style={{ fontSize: 10, color: S.textDim, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 280 }}>{elu.fonction || elu.parti || ''}</div>
          </div>
        </div>
      ))}
    </div>
  ) : null;

  return (
    <>
      <nav style={{
        padding: "10px 16px", display: "flex", justifyContent: "space-between", alignItems: "center",
        borderBottom: "1px solid rgba(255,255,255,0.04)",
        position: "sticky", top: 0, zIndex: 100,
        background: "rgba(10,14,26,0.96)", backdropFilter: "blur(14px)",
        width: "100%",
      }}>
        {/* Logo */}
        <div style={{ display: "flex", alignItems: "center", gap: 6, flexShrink: 0 }}>
          <Link to="/" style={{ textDecoration: "none", display: "flex", alignItems: "center", gap: 6 }}>
            <span style={{ fontSize: 20, animation: "float 3s ease-in-out infinite" }}>🎰</span>
            <span style={{ fontFamily: S.fontTitle, fontSize: 16, color: S.gold, textShadow: "0 0 8px rgba(253,203,110,0.3)" }}>nos-elus</span>
            <span style={{ fontSize: 9, fontWeight: 800, color: S.textDim }}>.com</span>
          </Link>
          <Pres2027Pill />
          {!isHome && (
            <span className="nav-label" style={{
              fontFamily: S.font, fontSize: 11, color: S.textDim, fontWeight: 600,
              fontStyle: "italic", whiteSpace: "nowrap", marginLeft: 8,
            }}>
              Tout est public, on a juste rendu ça simple.
            </span>
          )}
        </div>

        {/* Desktop: Random + Search */}
        {!isHome && (
          <div className="nav-desktop-search" style={{ display: "flex", alignItems: "center", gap: 8, flex: "0 1 360px", margin: "0 12px" }}>
            <Dice3D onClick={() => { window.location.href = "/api/random.php?redirect=1"; }} />
            <div ref={searchRef} style={{ position: "relative", flex: 1 }}>
              <input type="text" value={query} onChange={e => setQuery(e.target.value)} onKeyDown={handleKeyDown}
                placeholder="Rechercher un élu..."
                autoComplete="off"
                style={{
                  width: "100%", padding: "8px 12px 8px 34px", fontSize: 13, fontWeight: 600,
                  fontFamily: S.font, border: `1px solid ${showResults ? S.gold + '66' : 'rgba(255,255,255,0.08)'}`,
                  borderRadius: showResults ? "10px 10px 0 0" : 10, outline: "none",
                  color: "#fff", background: showResults ? "rgba(17,24,39,0.98)" : "rgba(255,255,255,0.06)",
                  transition: "all 0.2s", minHeight: 44,
                }}
                onFocus={() => { if (query.length >= 2) setShowResults(true); }}
              />
              <span style={{ position: "absolute", left: 10, top: "50%", transform: "translateY(-50%)", fontSize: 13, pointerEvents: "none", opacity: 0.5 }}>
                {searchLoading ? "..." : "🔍"}
              </span>
              <SearchDropdown />
            </div>
          </div>
        )}

        {/* Slogan centré — home uniquement */}
        {isHome && (
          <span className="nav-slogan" style={{
            position: "absolute", left: "50%", transform: "translateX(-50%)",
            fontFamily: S.font, fontSize: 18, color: "#fff", fontWeight: 700,
            fontStyle: "italic", whiteSpace: "nowrap", pointerEvents: "none",
          }}>
            Tout est public, on a juste rendu ça simple.
          </span>
        )}

        {/* Desktop Nav links */}
        <div className="nav-desktop-links" style={{ display: "flex", gap: 6, alignItems: "center", flexShrink: 0 }}>
          {NAV_LINKS.map(link => (
            <Link key={link.to} to={link.to} style={{
              textDecoration: "none",
              background: link.bg,
              border: `1px solid ${link.border}`,
              borderRadius: 99, padding: "5px 12px",
              fontSize: link.titleFont ? 12 : 11, fontWeight: 900,
              fontFamily: link.titleFont ? S.fontTitle : S.font,
              color: link.color, minHeight: 44,
              display: "flex", alignItems: "center", gap: 4,
              animation: link.pulse ? `${link.pulse} 2.5s ease-in-out infinite` : link.solid ? "pulseBtn 3s ease-in-out infinite" : undefined,
              textShadow: link.pulse ? `0 0 8px ${S.gold}44` : undefined,
              whiteSpace: "nowrap",
            }}>
              {link.icon && link.icon}{link.label !== "2027" && <span className="nav-label">{link.label}</span>}
              {link.label === "2027" && "2027"}
            </Link>
          ))}
        </div>

        {/* Mobile: hamburger button */}
        <button
          className="nav-hamburger"
          ref={hamburgerBtnRef}
          onClick={() => setMenuOpen(true)}
          aria-label="Ouvrir le menu de navigation"
          aria-expanded={menuOpen}
          aria-controls="mobile-drawer"
          style={{
            display: "none",
            background: "rgba(255,255,255,0.06)", border: "1px solid rgba(255,255,255,0.1)",
            borderRadius: 10, padding: "10px 12px", cursor: "pointer",
            color: "#fff", fontSize: 18, lineHeight: 1,
            minHeight: 44, minWidth: 44, alignItems: "center", justifyContent: "center",
          }}
        >
          ☰
        </button>
      </nav>

      {/* Mobile drawer backdrop */}
      {menuOpen && (
        <div
          onClick={() => setMenuOpen(false)}
          style={{
            position: "fixed", inset: 0, background: "rgba(0,0,0,0.6)",
            zIndex: 199, backdropFilter: "blur(2px)",
            animation: "fadeIn 0.2s ease",
          }}
        />
      )}

      {/* Mobile side drawer */}
      <div
        ref={drawerRef}
        id="mobile-drawer"
        role="dialog"
        aria-modal="true"
        aria-label="Menu de navigation"
        aria-hidden={!menuOpen}
        style={{
          position: "fixed", top: 0, right: 0, bottom: 0,
          width: "min(85vw, 320px)",
          background: "rgba(10,14,26,0.98)", backdropFilter: "blur(20px)",
          borderLeft: "1px solid rgba(255,255,255,0.08)",
          zIndex: 200, display: "flex", flexDirection: "column",
          transform: menuOpen ? "translateX(0)" : "translateX(100%)",
          transition: "transform 0.28s cubic-bezier(0.16,1,0.3,1)",
          padding: "0 0 24px",
          overflowY: "auto",
        }}
      >
        {/* Drawer header */}
        <div style={{
          display: "flex", alignItems: "center", justifyContent: "space-between",
          padding: "16px 20px", borderBottom: "1px solid rgba(255,255,255,0.06)",
          position: "sticky", top: 0, background: "rgba(10,14,26,0.98)", zIndex: 1,
        }}>
          <Link to="/" style={{ textDecoration: "none", display: "flex", alignItems: "center", gap: 6 }}>
            <span style={{ fontSize: 20, animation: "float 3s ease-in-out infinite" }}>🎰</span>
            <span style={{ fontFamily: S.fontTitle, fontSize: 16, color: S.gold }}>nos-elus</span>
            <span style={{ fontSize: 9, fontWeight: 800, color: S.textDim }}>.com</span>
          </Link>
          <button
            ref={closeBtnRef}
            onClick={() => setMenuOpen(false)}
            aria-label="Fermer le menu"
            style={{
              background: "rgba(255,255,255,0.06)", border: "1px solid rgba(255,255,255,0.1)",
              borderRadius: 8, padding: "8px 12px", cursor: "pointer",
              color: S.textDim, fontSize: 16, lineHeight: 1,
              minHeight: 44, minWidth: 44, display: "flex", alignItems: "center", justifyContent: "center",
            }}
          >
            ✕
          </button>
        </div>

        {/* Search + Dice in drawer */}
        <div style={{ padding: "16px 20px 12px", borderBottom: "1px solid rgba(255,255,255,0.04)" }}>
          <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
            <Dice3D onClick={() => { setMenuOpen(false); window.location.href = "/api/random.php?redirect=1"; }} />
            <div ref={searchRefMobile} style={{ position: "relative", flex: 1 }}>
              <input
                type="text" value={query} onChange={e => setQuery(e.target.value)} onKeyDown={handleKeyDown}
                placeholder="Rechercher un élu..."
                autoComplete="off"
                style={{
                  width: "100%", padding: "10px 12px 10px 36px", fontSize: 13, fontWeight: 600,
                  fontFamily: S.font, border: `1px solid ${showResults ? S.gold + '66' : 'rgba(255,255,255,0.1)'}`,
                  borderRadius: showResults ? "10px 10px 0 0" : 10, outline: "none",
                  color: "#fff", background: showResults ? "rgba(17,24,39,0.98)" : "rgba(255,255,255,0.07)",
                  transition: "all 0.2s", minHeight: 44,
                }}
                onFocus={() => { if (query.length >= 2) setShowResults(true); }}
              />
              <span style={{ position: "absolute", left: 11, top: "50%", transform: "translateY(-50%)", fontSize: 13, pointerEvents: "none", opacity: 0.5 }}>
                {searchLoading ? "..." : "🔍"}
              </span>
              <SearchDropdown />
            </div>
          </div>
        </div>

        {/* Nav links in drawer */}
        <div style={{ padding: "16px 20px", display: "flex", flexDirection: "column", gap: 10 }}>
          {NAV_LINKS.map(link => (
            <Link key={link.to} to={link.to} style={{
              textDecoration: "none",
              background: link.bg,
              border: `1px solid ${link.border}`,
              borderRadius: 14, padding: "14px 18px",
              fontSize: 15, fontWeight: 900,
              fontFamily: link.titleFont ? S.fontTitle : S.font,
              color: link.color,
              display: "flex", alignItems: "center", gap: 10,
              minHeight: 54,
            }}>
              {link.icon && <span style={{ fontSize: 20 }}>{link.icon}</span>}
              {link.label}
            </Link>
          ))}
        </div>

        {/* Slogan footer */}
        <div style={{
          marginTop: "auto", padding: "16px 20px 0",
          fontSize: 11, color: S.textDim, fontStyle: "italic", textAlign: "center",
          fontFamily: S.font,
        }}>
          Tout est public, on a juste rendu ça simple.
        </div>
      </div>
    </>
  );
};

export default Navbar;
