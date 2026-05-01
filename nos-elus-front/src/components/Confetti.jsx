import { useState, useEffect } from "react";
import { S } from "../utils/constants";

const Confetti = ({ active }) => {
  const [particles, setParticles] = useState([]);
  useEffect(() => {
    if (!active) return;
    const colors = [S.red, S.gold, S.green, S.purple, "#fd79a8", S.blue];
    const p = Array.from({ length: 35 }, (_, i) => ({
      id: i, x: Math.random() * 100,
      color: colors[Math.floor(Math.random() * colors.length)],
      delay: Math.random() * 0.5,
      duration: 1.5 + Math.random() * 1.5,
      size: 5 + Math.random() * 7,
    }));
    setParticles(p);
    const t = setTimeout(() => setParticles([]), 3000);
    return () => clearTimeout(t);
  }, [active]);
  if (!particles.length) return null;
  return (
    <div style={{ position: "fixed", inset: 0, pointerEvents: "none", zIndex: 999, overflow: "hidden" }}>
      {particles.map(p => (
        <div key={p.id} style={{
          position: "absolute", left: `${p.x}%`, top: -20,
          width: p.size, height: p.size * 0.6,
          background: p.color, borderRadius: 2,
          animation: `confettiFall ${p.duration}s ${p.delay}s ease-in forwards`,
        }} />
      ))}
    </div>
  );
};

export default Confetti;
