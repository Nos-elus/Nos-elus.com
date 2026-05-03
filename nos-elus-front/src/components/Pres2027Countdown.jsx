import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { S } from "../utils/constants";

// 1er tour présidentielle 2027 : dimanche 11 avril 2027 à 8h (ouverture des bureaux).
// Mandat Macron expire le 13 mai 2027 ; loi : 1er tour 35j avant.
export const PRES_2027_DATE = new Date("2027-04-11T08:00:00+02:00");

export const usePres2027Countdown = ({ withSeconds = false } = {}) => {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const interval = withSeconds ? 1_000 : 60_000;
    const t = setInterval(() => setNow(Date.now()), interval);
    return () => clearInterval(t);
  }, [withSeconds]);
  const diff = Math.max(0, PRES_2027_DATE.getTime() - now);
  const days = Math.floor(diff / 86_400_000);
  const hours = Math.floor((diff % 86_400_000) / 3_600_000);
  const minutes = Math.floor((diff % 3_600_000) / 60_000);
  const seconds = Math.floor((diff % 60_000) / 1_000);
  return { days, hours, minutes, seconds, expired: diff === 0 && now > PRES_2027_DATE.getTime() };
};

// Compact pour la navbar
export const Pres2027Pill = () => {
  const { days, expired } = usePres2027Countdown();
  if (expired) return null;
  return (
    <Link
      to="/2027"
      title={`Premier tour : ${PRES_2027_DATE.toLocaleDateString("fr-FR")}`}
      style={{
        textDecoration: "none",
        background: `linear-gradient(135deg, ${S.gold}22, ${S.gold}0a)`,
        border: `1px solid ${S.gold}66`,
        borderRadius: 99, padding: "3px 10px",
        fontFamily: S.fontTitle, fontSize: 11, fontWeight: 800,
        color: S.gold, letterSpacing: 0.5,
        display: "inline-flex", alignItems: "center", gap: 4,
        boxShadow: `0 0 8px ${S.gold}22`,
        animation: "pulse2027Pill 3s ease-in-out infinite",
        whiteSpace: "nowrap", flexShrink: 0,
      }}
    >
      <span>J-{days}</span>
      <span style={{ opacity: 0.7, fontSize: 10 }}>2027</span>
      <style>{`
        @keyframes pulse2027Pill {
          0%, 100% { box-shadow: 0 0 8px ${S.gold}22; }
          50% { box-shadow: 0 0 14px ${S.gold}55; }
        }
      `}</style>
    </Link>
  );
};

// Grande version pour la page 2027 (remplace "Grand Prix / 2027 / Présidentielle")
export const Pres2027Hero = () => {
  const { days, hours, minutes, seconds, expired } = usePres2027Countdown({ withSeconds: true });

  if (expired) {
    return (
      <div style={{ textAlign: "center" }}>
        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(36px,7vw,64px)", color: S.gold,
          lineHeight: 1, letterSpacing: 3,
          background: `linear-gradient(180deg, ${S.gold}, #e17a2d)`,
          WebkitBackgroundClip: "text", WebkitTextFillColor: "transparent",
        }}>1er tour engagé</div>
        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(16px,3vw,22px)", color: S.textMain,
          marginTop: 8, letterSpacing: 3,
        }}>Présidentielle</div>
      </div>
    );
  }

  return (
    <div style={{ textAlign: "center" }}>
      <div style={{
        fontFamily: S.fontTitle, fontSize: "clamp(13px,2.4vw,16px)", color: S.textDim,
        letterSpacing: 5, textTransform: "uppercase", marginBottom: 6,
      }}>1er tour dans</div>
      <div style={{
        display: "flex", justifyContent: "center", alignItems: "baseline", gap: "clamp(8px,2vw,16px)",
        fontFamily: S.fontTitle,
        background: `linear-gradient(180deg, ${S.gold}, #e17a2d)`,
        WebkitBackgroundClip: "text", WebkitTextFillColor: "transparent",
        textShadow: `0 0 40px ${S.gold}66, 0 0 80px ${S.gold}25`,
      }}>
        <CountUnit value={days} label="jours" big />
        <Sep />
        <CountUnit value={hours} label="h" />
        <Sep />
        <CountUnit value={minutes} label="min" />
        <Sep />
        <CountUnit value={seconds} label="sec" />
      </div>
      <div style={{
        fontFamily: S.fontTitle, fontSize: "clamp(18px,3.2vw,26px)", color: S.textMain,
        marginTop: 12, letterSpacing: 4,
      }}>Présidentielle <span style={{ color: S.gold }}>2027</span></div>
      <div style={{
        fontFamily: S.font, fontSize: 12, color: S.textDim, marginTop: 6, fontStyle: "italic",
      }}>Dimanche 11 avril 2027</div>
    </div>
  );
};

const CountUnit = ({ value, label, big = false }) => (
  <span style={{ display: "inline-flex", flexDirection: "column", alignItems: "center", lineHeight: 0.95 }}>
    <span style={{ fontSize: big ? "clamp(56px,11vw,100px)" : "clamp(28px,5.5vw,52px)", fontWeight: 900, letterSpacing: 2 }}>
      {String(value).padStart(2, "0")}
    </span>
    <span style={{
      fontSize: big ? 13 : 11, letterSpacing: 2, opacity: 0.85,
      WebkitTextFillColor: S.textDim, marginTop: 4,
    }}>{label}</span>
  </span>
);

const Sep = () => (
  <span style={{
    fontSize: "clamp(36px,7vw,64px)", opacity: 0.4,
    WebkitTextFillColor: "#e17a2d", lineHeight: 0.95, fontWeight: 300,
  }}>:</span>
);
