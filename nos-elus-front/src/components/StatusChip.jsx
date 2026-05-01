import { S } from "../utils/constants";

const StatusChip = ({ statut }) => {
  const map = {
    "Condamné": { bg: "rgba(255,107,107,0.15)", color: S.red, icon: "🚨" },
    "En cours": { bg: "rgba(253,203,110,0.15)", color: S.gold, icon: "⏳" },
    "Relaxé": { bg: "rgba(0,184,148,0.15)", color: S.green, icon: "✅" },
    "Classé sans suite": { bg: "rgba(131,149,123,0.15)", color: S.textMuted, icon: "📁" },
    "Classé": { bg: "rgba(131,149,123,0.15)", color: S.textMuted, icon: "📁" },
  };
  const s = map[statut] || map["En cours"];
  return (
    <span style={{
      display: "inline-flex", alignItems: "center", gap: 4,
      padding: "3px 10px", borderRadius: 99, background: s.bg, color: s.color,
      fontSize: 13, fontWeight: 800, fontFamily: S.font, whiteSpace: "nowrap",
    }}>{s.icon} {statut}</span>
  );
};

export default StatusChip;
