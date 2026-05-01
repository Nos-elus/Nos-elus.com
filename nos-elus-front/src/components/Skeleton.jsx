import { S } from "../utils/constants";

const Skeleton = ({ width = "100%", height = 20, radius = 8, style = {}, count = 1 }) => {
  const items = Array.from({ length: count }, (_, i) => i);

  return (
    <>
      {items.map(i => (
        <div key={i} style={{
          width,
          height,
          borderRadius: radius,
          background: `linear-gradient(90deg, ${S.card} 25%, ${S.border} 50%, ${S.card} 75%)`,
          backgroundSize: "200% 100%",
          animation: "shimmer 1.5s infinite ease-in-out",
          marginBottom: count > 1 ? 10 : 0,
          ...style,
        }} />
      ))}
      <style>{`
        @keyframes shimmer {
          0% { background-position: 200% 0; }
          100% { background-position: -200% 0; }
        }
      `}</style>
    </>
  );
};

export default Skeleton;
