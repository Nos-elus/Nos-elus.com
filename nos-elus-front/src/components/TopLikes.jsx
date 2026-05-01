import { useNavigate, Link } from "react-router-dom";
import { S } from "../utils/constants";
import { useApi } from "../hooks/useApi";
import Avatar from "./Avatar";

const slugify = (t) =>
  t.normalize("NFD").replace(/[\u0300-\u036f]/g, "")
    .toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");

const SIZES = [64, 52, 44];

const PodiumCard = ({ elu, rank, side }) => {
  const navigate = useNavigate();
  const isLike = side === "like";
  const color = isLike ? S.green : S.red;
  const size = SIZES[rank];
  const count = isLike ? elu.nb_likes : elu.nb_dislikes;

  return (
    <div
      onClick={() => navigate("/top-elus")}
      style={{
        display: "flex", flexDirection: "column", alignItems: "center", gap: 6,
        cursor: "pointer", transition: "transform 0.18s",
        minWidth: size + 16,
      }}
      onMouseEnter={e => { e.currentTarget.style.transform = "translateY(-4px)"; }}
      onMouseLeave={e => { e.currentTarget.style.transform = "translateY(0)"; }}
    >
      <div style={{
        borderRadius: "50%",
        boxShadow: `0 0 ${rank === 0 ? 20 : 12}px ${color}${rank === 0 ? "88" : "55"}`,
        border: `2px solid ${color}${rank === 0 ? "cc" : "77"}`,
        lineHeight: 0,
      }}>
        <Avatar elu={elu} size={size} />
      </div>
      <div style={{
        fontFamily: S.font, fontSize: 10, fontWeight: 800, color: S.textMain,
        textAlign: "center", maxWidth: size + 16,
        whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
      }}>
        {elu.nom.split(" ").slice(-1)[0]}
      </div>
      <div style={{
        background: isLike ? "rgba(0,184,148,0.15)" : "rgba(255,107,107,0.15)",
        border: `1px solid ${color}55`,
        borderRadius: 99, padding: "2px 9px",
        fontFamily: S.font, fontSize: 11, fontWeight: 900, color,
      }}>
        {isLike ? "+" : "-"}{count}
      </div>
    </div>
  );
};

const TopLikes = () => {
  const { data, loading } = useApi("stats.php");
  const likes = (data?.top_likes || []).slice(0, 3);
  const dislikes = (data?.top_dislikes || []).slice(0, 3);

  if (loading) return (
    <div style={{ textAlign: "center", padding: "32px 0", color: S.textDim, fontFamily: S.font, fontSize: 13 }}>
      Chargement du classement...
    </div>
  );

  if (!likes.length && !dislikes.length) return null;

  // Left side: #1 far left (biggest), #2 middle, #3 near center
  const leftOrder = [likes[0], likes[1], likes[2]].filter(Boolean);
  // Right side: #3 near center, #2 middle, #1 far right (biggest)
  const rightOrder = [dislikes[2], dislikes[1], dislikes[0]].filter(Boolean);
  const rightRanks = [2, 1, 0];

  return (
    <div style={{ marginTop: 56, animation: "slideUp 0.6s 0.7s cubic-bezier(0.16,1,0.3,1) both" }}>
      <div style={{
        fontFamily: S.fontTitle, fontSize: "clamp(14px,3vw,18px)",
        color: S.gold, textAlign: "center", marginBottom: 4,
        textShadow: `0 0 20px ${S.gold}44`,
      }}>
        Podium citoyens
      </div>
      <div style={{ fontFamily: S.font, fontSize: 11, color: S.textDim, textAlign: "center", marginBottom: 24 }}>
        Likes vs dislikes
      </div>

      <div style={{ display: "flex", alignItems: "flex-end", justifyContent: "center", gap: 12 }}>

        {/* Left group: most liked */}
        <div style={{ display: "flex", alignItems: "flex-end", gap: 8 }}>
          {leftOrder.map((elu, i) => (
            <PodiumCard key={elu.id} elu={elu} rank={i} side="like" />
          ))}
        </div>

        {/* Center divider */}
        <div style={{
          display: "flex", flexDirection: "column", alignItems: "center", gap: 4,
          padding: "0 8px", flexShrink: 0,
        }}>
          <div style={{ width: 1, height: 60, background: `linear-gradient(to bottom, transparent, ${S.border}, transparent)` }} />
          <div style={{
            fontFamily: S.fontTitle, fontSize: 9, color: S.textDim,
            letterSpacing: 1, writingMode: "vertical-rl", textOrientation: "mixed",
            transform: "rotate(180deg)",
          }}>VS</div>
          <div style={{ width: 1, height: 60, background: `linear-gradient(to bottom, transparent, ${S.border}, transparent)` }} />
        </div>

        {/* Right group: most disliked — reversed so #1 is far right */}
        <div style={{ display: "flex", alignItems: "flex-end", gap: 8 }}>
          {rightOrder.map((elu, i) => (
            <PodiumCard key={elu.id} elu={elu} rank={rightRanks[i]} side="dislike" />
          ))}
        </div>

      </div>

      <Link to="/top-elus" style={{
        display: "block", textAlign: "center", marginTop: 16,
        fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.gold,
        textDecoration: "none", opacity: 0.8,
      }}>
        Voir le classement complet →
      </Link>
    </div>
  );
};

export default TopLikes;
