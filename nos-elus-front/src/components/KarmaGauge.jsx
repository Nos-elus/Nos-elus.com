import { S } from "../utils/constants";

const KarmaGauge = ({ bonnes, mauvaises }) => {
  const total = bonnes + mauvaises || 1;
  const pct = (bonnes / total) * 100;
  return (
    <div style={{ marginBottom: 24 }}>
      <div style={{ display: "flex", justifyContent: "space-between", fontFamily: S.font, fontSize: 14, fontWeight: 800, marginBottom: 6 }}>
        <span style={{ color: S.green }}>😇 +{bonnes}</span>
        <span style={{ color: S.red }}>🍳 {mauvaises}</span>
      </div>
      <div style={{ height: 10, borderRadius: 99, background: S.card, overflow: "hidden", border: `1px solid ${S.border}`, display: "flex" }}>
        <div style={{ width: `${pct}%`, background: S.green, borderRadius: "99px 0 0 99px", transition: "width 1s cubic-bezier(0.34,1.56,0.64,1)" }} />
        <div style={{ flex: 1, background: "#e17055", borderRadius: "0 99px 99px 0" }} />
      </div>
    </div>
  );
};

export default KarmaGauge;
