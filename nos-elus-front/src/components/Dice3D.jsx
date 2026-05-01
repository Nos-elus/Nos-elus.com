import { useState, useRef, useCallback } from "react";
import { S } from "../utils/constants";

const SEGMENTS = [
  { color: S.red, icon: "🍳" },
  { color: S.gold, icon: "⭐" },
  { color: S.purple, icon: "🏛️" },
  { color: S.green, icon: "✅" },
  { color: S.blue, icon: "🗳️" },
  { color: "#e67e22", icon: "👔" },
  { color: "#e84393", icon: "💘" },
  { color: "#00cec9", icon: "🎭" },
];

const FortuneWheel = ({ onClick, size = 32 }) => {
  const [spinning, setSpinning] = useState(false);
  const [rotation, setRotation] = useState(0);
  const canvasRef = useRef(null);

  const handleClick = useCallback(() => {
    if (spinning) return;
    setSpinning(true);
    const tours = 4 + Math.floor(Math.random() * 3); // 4 à 6 tours
    const newRotation = rotation + tours * 360;
    setRotation(newRotation);
    setTimeout(() => {
      onClick();
      setSpinning(false);
    }, 1200);
  }, [spinning, rotation, onClick]);

  const r = size / 2;
  const segAngle = 360 / SEGMENTS.length;

  return (
    <div
      onClick={handleClick}
      title="Elu au hasard"
      style={{
        width: size, height: size, borderRadius: "50%",
        cursor: "pointer", position: "relative", flexShrink: 0,
        boxShadow: `0 0 12px ${S.gold}44, inset 0 0 4px rgba(0,0,0,0.3)`,
        border: `2px solid ${S.gold}88`,
        overflow: "hidden",
        transition: "transform 0.15s, box-shadow 0.15s",
      }}
      onMouseEnter={e => { if (!spinning) { e.currentTarget.style.transform = "scale(1.15)"; e.currentTarget.style.boxShadow = `0 0 20px ${S.gold}88`; } }}
      onMouseLeave={e => { e.currentTarget.style.transform = "scale(1)"; e.currentTarget.style.boxShadow = `0 0 12px ${S.gold}44, inset 0 0 4px rgba(0,0,0,0.3)`; }}
    >
      {/* Segments */}
      <div style={{
        width: "100%", height: "100%", borderRadius: "50%",
        transform: `rotate(${rotation}deg)`,
        transition: spinning ? "transform 1.2s cubic-bezier(0.2, 0.8, 0.3, 1)" : "none",
      }}>
        <svg viewBox={`0 0 ${size} ${size}`} style={{ width: "100%", height: "100%", display: "block" }}>
          {SEGMENTS.map((seg, i) => {
            const startAngle = (i * segAngle - 90) * (Math.PI / 180);
            const endAngle = ((i + 1) * segAngle - 90) * (Math.PI / 180);
            const x1 = r + r * Math.cos(startAngle);
            const y1 = r + r * Math.sin(startAngle);
            const x2 = r + r * Math.cos(endAngle);
            const y2 = r + r * Math.sin(endAngle);
            const largeArc = segAngle > 180 ? 1 : 0;
            return (
              <path
                key={i}
                d={`M${r},${r} L${x1},${y1} A${r},${r} 0 ${largeArc},1 ${x2},${y2} Z`}
                fill={seg.color}
                opacity={0.7}
                stroke="rgba(0,0,0,0.2)"
                strokeWidth={0.3}
              />
            );
          })}
        </svg>
      </div>

      {/* Centre */}
      <div style={{
        position: "absolute",
        top: "50%", left: "50%",
        transform: "translate(-50%, -50%)",
        width: size * 0.38, height: size * 0.38,
        borderRadius: "50%",
        background: `radial-gradient(circle, ${S.card} 60%, #0f0f1a)`,
        border: `1.5px solid ${S.gold}66`,
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: size * 0.22,
        boxShadow: "0 1px 4px rgba(0,0,0,0.4)",
        pointerEvents: "none",
      }}>
        ?
      </div>

      {/* Flèche en haut */}
      <div style={{
        position: "absolute", top: -2, left: "50%", transform: "translateX(-50%)",
        width: 0, height: 0,
        borderLeft: "4px solid transparent",
        borderRight: "4px solid transparent",
        borderTop: `5px solid ${S.gold}`,
        filter: `drop-shadow(0 1px 2px rgba(0,0,0,0.5))`,
        pointerEvents: "none",
      }} />
    </div>
  );
};

// Garde le nom d'export pour compatibilité avec Navbar/Home
export default FortuneWheel;
