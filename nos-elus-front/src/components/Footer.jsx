import { Link } from "react-router-dom";
import { S } from "../utils/constants";

const LINKS = [
  { label: "CGU", to: "/cgu" },
  { label: "Mentions légales", to: "/mentions-legales" },
  { label: "Confidentialité", to: "/confidentialite" },
  { label: "Contact", to: "/contact" },
  { label: "À propos", to: "/a-propos" },
  { label: "Nous aider", to: "/nous-aider" },
  { label: "Grille calcul", to: "/grille-indemnites" },
];

export default function Footer() {
  return (
    <footer style={{
      marginTop: 80,
      background: "linear-gradient(180deg, transparent 0%, rgba(10,10,20,0.95) 20%, #07070f 100%)",
      borderTop: `2px solid ${S.border}`,
      padding: "40px 24px 32px",
      position: "relative", zIndex: 1,
    }}>
      <div style={{
        width: 60, height: 2,
        background: `linear-gradient(90deg, transparent, ${S.gold}, transparent)`,
        margin: "0 auto 28px",
      }} />
      <p style={{
        textAlign: "center", fontFamily: S.fontTitle,
        fontSize: "clamp(13px, 2vw, 16px)", color: S.gold,
        letterSpacing: "0.03em", margin: "0 0 28px",
        textShadow: "0 0 20px rgba(253,203,110,0.25)",
        padding: "0 16px",
      }}>
        Aucun élu n'a été maltraité durant la création de ce site
      </p>
      <nav style={{
        display: "flex", justifyContent: "center",
        gap: "8px 24px", flexWrap: "wrap", marginBottom: 28,
      }}>
        {LINKS.map(({ label, to }) => (
          <Link key={to} to={to} style={{
            fontSize: 13, color: S.textMuted, fontWeight: 600,
            textDecoration: "none", padding: "8px 4px",
            minHeight: 44, display: "inline-flex", alignItems: "center",
            borderBottom: "1px solid transparent", transition: "all 0.2s",
          }}
          onMouseEnter={e => { e.currentTarget.style.color = S.gold; e.currentTarget.style.borderBottomColor = S.gold; }}
          onMouseLeave={e => { e.currentTarget.style.color = S.textMuted; e.currentTarget.style.borderBottomColor = "transparent"; }}
          >{label}</Link>
        ))}
      </nav>
      <div style={{
        width: "100%", maxWidth: 400, height: 1,
        background: `linear-gradient(90deg, transparent, ${S.border}, transparent)`,
        margin: "0 auto 20px",
      }} />
      <div style={{ textAlign: "center", marginBottom: 12 }}>
        <img src="/Fabrique_en_france.svg" alt="Fabriqué en France" style={{ height: 36, opacity: 0.8 }} />
      </div>
      <p style={{ textAlign: "center", fontSize: 11, color: S.textDim, margin: 0, letterSpacing: "0.05em" }}>
        nos-elus.com &mdash; MVP 2026 &mdash; Données issues de sources publiques 🍩
      </p>
    </footer>
  );
}
