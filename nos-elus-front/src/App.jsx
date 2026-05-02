import { lazy, Suspense, useMemo, useEffect, useState } from "react";
import { BrowserRouter, Routes, Route, Navigate, useLocation } from "react-router-dom";
import { S } from "./utils/constants";
import "./styles/global.css";
import Navbar from "./components/Navbar";
import Footer from "./components/Footer";
import Home from "./pages/Home";
import Skeleton from "./components/Skeleton";

// Lazy load des pages lourdes (Recharts dans EluProfile + Comparator)
const EluProfile = lazy(() => import("./pages/EluProfile"));
const Comparator = lazy(() => import("./pages/Comparator"));
const About = lazy(() => import("./pages/About"));
const CGU = lazy(() => import("./pages/CGU"));
const MentionsLegales = lazy(() => import("./pages/MentionsLegales"));
const Confidentialite = lazy(() => import("./pages/Confidentialite"));
const Contact = lazy(() => import("./pages/Contact"));
const Presidentielle2027 = lazy(() => import("./pages/Presidentielle2027"));
const TopElus = lazy(() => import("./pages/TopElus"));
const Palmares = lazy(() => import("./pages/Palmares"));
const PalmaresCategory = lazy(() => import("./pages/PalmaresCategory"));
const MatchMaker = lazy(() => import("./pages/MatchMaker"));
const GrilleIndemnites = lazy(() => import("./pages/GrilleIndemnites"));
const NousAider = lazy(() => import("./pages/NousAider"));

// Scroll to top on route change
function ScrollToTop() {
  const { pathname } = useLocation();
  useEffect(() => { window.scrollTo({ top: 0, behavior: "instant" }); }, [pathname]);
  return null;
}

// Bouton retour en haut (apparaît après 400px de scroll)
function BackToTop() {
  const [show, setShow] = useState(false);
  useEffect(() => {
    const onScroll = () => setShow(window.scrollY > 400);
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);
  if (!show) return null;
  return (
    <button
      onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}
      aria-label="Retour en haut"
      style={{
        position: "fixed", bottom: 24, right: 24, zIndex: 90,
        width: 48, height: 48, borderRadius: "50%",
        background: S.gold, color: "#0f0f1a",
        border: "none", cursor: "pointer", fontSize: 22, fontWeight: 900,
        boxShadow: "0 6px 20px rgba(0,0,0,0.4)",
        display: "flex", alignItems: "center", justifyContent: "center",
        animation: "fadeIn 0.2s ease",
      }}
    >↑</button>
  );
}

// Background dots (mémorisé pour éviter le re-render)
function Dots() {
  const dots = useMemo(() => Array.from({ length: 25 }, (_, i) => ({
    id: i,
    left: `${(Math.sin(i * 2.4) * 50 + 50).toFixed(1)}%`,
    top: `${(Math.cos(i * 3.1) * 50 + 50).toFixed(1)}%`,
    opacity: 0.15 + (i % 5) * 0.04,
  })), []);

  return (
    <div style={{ position: "fixed", inset: 0, pointerEvents: "none", zIndex: 0, overflow: "hidden" }}>
      {/* Lignes diagonales subtiles comme la bannière */}
      <div style={{
        position: "absolute", inset: 0,
        background: "repeating-linear-gradient(135deg, transparent, transparent 80px, rgba(245,166,35,0.015) 80px, rgba(245,166,35,0.015) 81px)",
      }} />
      <div style={{
        position: "absolute", inset: 0,
        background: "repeating-linear-gradient(135deg, transparent, transparent 200px, rgba(245,166,35,0.02) 200px, rgba(245,166,35,0.02) 201px)",
      }} />
      {/* Points lumineux */}
      {dots.map(d => (
        <div key={d.id} style={{
          position: "absolute", left: d.left, top: d.top,
          width: d.id % 3 === 0 ? 3 : 2, height: d.id % 3 === 0 ? 3 : 2,
          background: S.gold, borderRadius: d.id % 4 === 0 ? 1 : "50%",
          opacity: d.opacity, boxShadow: `0 0 6px rgba(245,166,35,0.2)`,
        }} />
      ))}
    </div>
  );
}

// Page loading fallback
function PageLoader() {
  return (
    <div style={{ paddingTop: 32, maxWidth: 960, margin: "0 auto" }}>
      <Skeleton height={200} radius={16} />
      <Skeleton height={120} radius={16} style={{ marginTop: 24 }} />
      <Skeleton height={80} radius={12} style={{ marginTop: 16 }} />
      <Skeleton height={80} radius={12} style={{ marginTop: 10 }} />
    </div>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <ScrollToTop />
      <div style={{ minHeight: "100vh", background: `linear-gradient(160deg, ${S.bg} 0%, #0d1525 40%, #141b2d 70%, #0a0e1a 100%)`, fontFamily: S.font, color: S.textMain }}>
        <div style={{
          background: `linear-gradient(90deg, ${S.gold}22, ${S.gold}11)`,
          borderBottom: `1px solid ${S.gold}33`,
          color: S.gold,
          fontFamily: S.font,
          fontSize: 12,
          fontWeight: 700,
          textAlign: "center",
          padding: "6px 12px",
          letterSpacing: 0.4,
          position: "relative",
          zIndex: 50,
        }}>
          🚧 Version BETA — Site en cours de développement
        </div>
        <Navbar />

        <main style={{ padding: "0 24px", maxWidth: 1080, margin: "0 auto", position: "relative", zIndex: 1 }}>
          <Suspense fallback={<PageLoader />}>
            <Routes>
              <Route path="/" element={<Home />} />
              <Route path="/elu/:slug" element={<EluProfile />} />
              <Route path="/comparer" element={<Comparator />} />
              <Route path="/a-propos" element={<About />} />
              <Route path="/cgu" element={<CGU />} />
              <Route path="/mentions-legales" element={<MentionsLegales />} />
              <Route path="/confidentialite" element={<Confidentialite />} />
              <Route path="/contact" element={<Contact />} />
              <Route path="/2027" element={<Presidentielle2027 />} />
              <Route path="/top-elus" element={<TopElus />} />
              <Route path="/palmares" element={<Palmares />} />
              <Route path="/palmares/:category" element={<PalmaresCategory />} />
              <Route path="/match" element={<MatchMaker />} />
              <Route path="/grille-indemnites" element={<GrilleIndemnites />} />
              <Route path="/nous-aider" element={<NousAider />} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </Suspense>
        </main>

        <Footer />
        <BackToTop />
      </div>
    </BrowserRouter>
  );
}
