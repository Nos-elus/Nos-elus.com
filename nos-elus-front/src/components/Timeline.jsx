import { S } from "../utils/constants";
import StatusChip from "./StatusChip";

const Timeline = ({ affaires }) => {
  if (affaires.length === 0) return (
    <div style={{ textAlign: "center", padding: 24, background: S.card, borderRadius: 12, border: `1px solid ${S.border}` }}>
      <div style={{ fontSize: 36, marginBottom: 8 }}>🏆</div>
      <div style={{ fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.green }}>Aucune affaire !</div>
      <div style={{ fontFamily: S.font, fontSize: 14, color: S.textMuted, marginTop: 4 }}>Casier judiciaire immaculé 👏</div>
    </div>
  );

  const sorted = [...affaires].sort((a, b) => parseInt(a.date) - parseInt(b.date));
  return (
    <div style={{ position: "relative", paddingLeft: 28 }}>
      <div style={{
        position: "absolute", left: 8, top: 8, bottom: 8, width: 2,
        background: `linear-gradient(180deg, ${S.gold}44, ${S.red}44)`,
        borderRadius: 2,
      }} />
      {sorted.map((a, i) => {
        const dotColor = a.statut === "Condamné" ? S.red : a.statut === "Relaxé" ? S.green : S.gold;
        return (
          <div key={i} style={{
            marginBottom: 16, position: "relative",
            animation: `slideUp 0.4s ${i * 0.1}s cubic-bezier(0.16,1,0.3,1) both`,
          }}>
            <div style={{
              position: "absolute", left: -24, top: 8, width: 12, height: 12,
              borderRadius: "50%", background: dotColor, border: `2px solid ${S.bg}`,
              boxShadow: `0 0 8px ${dotColor}44`,
            }} />
            <div style={{
              background: S.card, borderRadius: 12, padding: "14px 18px",
              border: `1px solid ${dotColor}22`,
            }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 6 }}>
                <span style={{ fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.textMain }}>{a.titre}</span>
                <StatusChip statut={a.statut} />
              </div>
              <div style={{ fontFamily: S.font, fontSize: 14, color: S.textMuted, lineHeight: 1.5 }}>{a.detail}</div>
              <div style={{ display: "flex", gap: 10, marginTop: 8 }}>
                <span style={{ fontSize: 13, color: S.textDim, fontFamily: S.font }}>📅 {a.date}</span>
                <span style={{ fontSize: 13, color: S.textDim, fontFamily: S.font }}>
                  {"🔴".repeat(a.gravite)}{"⚪".repeat(5 - a.gravite)}
                </span>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default Timeline;
