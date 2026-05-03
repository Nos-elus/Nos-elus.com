import { useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import Avatar from "../components/Avatar";
import { useApi } from "../hooks/useApi";

const TopElus = () => {
  const navigate = useNavigate();
  const { data, loading } = useApi("stats.php");

  const liked = data?.top_likes || [];
  const disliked = data?.top_dislikes || [];
  const count = Math.max(liked.length, disliked.length);
  const maxLikes = liked[0]?.nb_likes || 1;
  const maxDislikes = disliked[0]?.nb_dislikes || 1;

  const MEDAL_COLORS = ["#FFD700", "#C0C0C0", "#CD7F32"];
  const avatarS = (i) => i === 0 ? 56 : i === 1 ? 48 : i === 2 ? 42 : 34;
  const barH = (i) => avatarS(i) + 14;
  const fontSize = (i) => i < 3 ? [16, 14, 13][i] : 12;
  const rankSize = (i) => i < 3 ? [32, 26, 22][i] : 16;
  const rankColor = (i) => i < 3 ? MEDAL_COLORS[i] : S.textDim;

  const EluEntry = ({ elu, rank, side, maxVal, valKey }) => {
    if (!elu) return <div style={{ flex: 1 }} />;
    const val = elu[valKey] || 0;
    const pct = Math.max((val / maxVal) * 100, 15);
    const h = barH(rank);
    const av = avatarS(rank);
    const fs = fontSize(rank);
    const color = side === "left" ? S.green : S.red;
    const isLeft = side === "left";

    const gradient = isLeft
      ? `linear-gradient(to right, transparent 0%, ${color}33 40%, ${color}aa 100%)`
      : `linear-gradient(to left, transparent 0%, ${color}33 40%, ${color}aa 100%)`;

    return (
      <div
        onClick={() => navigate(`/elu/${elu.slug}`)}
        style={{
          flex: 1, display: "flex", alignItems: "center", cursor: "pointer",
          // Barre collée au # central : flex-end pour gauche (barre à droite du conteneur),
          // flex-start pour droite (barre à gauche du conteneur)
          justifyContent: isLeft ? "flex-end" : "flex-start",
          minHeight: h + 8, transition: "transform 0.15s",
        }}
        onMouseEnter={e => e.currentTarget.style.transform = "scale(1.02)"}
        onMouseLeave={e => e.currentTarget.style.transform = "scale(1)"}
      >
        <div style={{
          width: `${pct}%`, height: h, borderRadius: 99,
          background: gradient, position: "relative",
          boxShadow: `0 2px 8px ${color}18`,
          display: "flex", alignItems: "center",
          justifyContent: isLeft ? "flex-end" : "flex-start",
          transition: "width 0.6s ease",
          minWidth: av + 80,
        }}>
          <div style={{
            position: "absolute",
            [isLeft ? "right" : "left"]: -2,
            top: "50%", transform: "translateY(-50%)",
            borderRadius: "50%", lineHeight: 0,
            boxShadow: `0 2px 10px ${color}44`,
            background: S.card,
          }}>
            <Avatar elu={elu} size={av} showBorder={false} />
          </div>
          <div style={{
            display: "flex", flexDirection: "column",
            alignItems: isLeft ? "flex-start" : "flex-end",
            [isLeft ? "marginLeft" : "marginRight"]: 12,
            [isLeft ? "marginRight" : "marginLeft"]: av + 8,
          }}>
            <span style={{
              fontFamily: S.font, fontSize: fs, fontWeight: 800,
              color: "#fff", whiteSpace: "nowrap",
              textShadow: "0 1px 3px rgba(0,0,0,0.6)",
            }}>
              {elu.prenom} {elu.nom}
            </span>
            <span style={{ fontFamily: S.font, fontSize: 10, fontWeight: 700, color, whiteSpace: "nowrap", opacity: 0.9 }}>
              {val} vote{val > 1 ? "s" : ""}
            </span>
          </div>
        </div>
      </div>
    );
  };

  return (
    <div style={{ paddingBottom: 60, animation: "slideUp .5s ease" }}>
      <div style={{ textAlign: "center", padding: "32px 0 24px" }}>
        <h1 style={{ fontFamily: S.fontTitle, color: S.gold, fontSize: 28, margin: 0 }}>
          Vos élus préférés
        </h1>
        <p style={{ color: S.textDim, margin: "8px 0 0", fontSize: 15 }}>
          Classement des votes citoyens
        </p>
      </div>

      {loading ? (
        <div style={{ textAlign: "center", color: S.textDim, padding: 40 }}>Chargement...</div>
      ) : count === 0 ? (
        <div style={{ textAlign: "center", color: S.textDim, padding: 40 }}>Aucun vote pour le moment</div>
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
          {/* Header */}
          <div style={{
            display: "flex", alignItems: "center", gap: 12, padding: "0 0 8px",
            justifyContent: "center",
          }}>
            <span style={{ flex: 1, textAlign: "right", fontFamily: S.fontTitle, fontSize: 14, color: S.green }}>
              Les plus aimés
            </span>
            <span style={{ width: 40, textAlign: "center", color: S.textDim, fontSize: 12 }}>#</span>
            <span style={{ flex: 1, textAlign: "left", fontFamily: S.fontTitle, fontSize: 14, color: S.red }}>
              Les moins aimés
            </span>
          </div>

          {Array.from({ length: count }, (_, i) => (
            <div key={i} className="top-row" style={{
              display: "flex", alignItems: "center", gap: 8,
            }}>
              <EluEntry elu={liked[i]} rank={i} side="left" maxVal={maxLikes} valKey="nb_likes" />
              <div style={{
                width: 40, minWidth: 40, textAlign: "center",
                fontFamily: S.fontTitle, fontSize: rankSize(i),
                color: rankColor(i),
                textShadow: i < 3 ? `0 0 12px ${rankColor(i)}66` : "none",
              }}>
                {i + 1}
              </div>
              <EluEntry elu={disliked[i]} rank={i} side="right" maxVal={maxDislikes} valKey="nb_dislikes" />
            </div>
          ))}
        </div>
      )}

      <style>{`
        @media (max-width: 640px) {
          .top-row { flex-direction: column !important; gap: 2px !important; }
          .top-row > div:first-child { order: 1; }
          .top-row > div:nth-child(2) { order: 0; }
          .top-row > div:last-child { order: 2; }
        }
      `}</style>
    </div>
  );
};

export default TopElus;
