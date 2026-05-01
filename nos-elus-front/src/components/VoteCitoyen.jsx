import { useState, useEffect } from "react";
import { S } from "../utils/constants";

const API = import.meta.env.VITE_API_URL || "/api";
const LS_KEY = "noselus_votes";

function getLocalVotes() {
  try { return JSON.parse(localStorage.getItem(LS_KEY)) || {}; } catch { return {}; }
}
function setLocalVote(eluId, vote) {
  const votes = getLocalVotes();
  if (vote === null) delete votes[eluId];
  else votes[eluId] = vote;
  localStorage.setItem(LS_KEY, JSON.stringify(votes));
}

const VoteCitoyen = ({ eluId }) => {
  const [likes, setLikes] = useState(0);
  const [dislikes, setDislikes] = useState(0);
  const [userVote, setUserVote] = useState(null); // 1, -1, or null
  const [loading, setLoading] = useState(false);

  // Au mount : lire localStorage immédiatement, puis fetch API
  useEffect(() => {
    if (!eluId) return;
    const local = getLocalVotes();
    if (local[eluId] !== undefined) setUserVote(local[eluId]);

    fetch(`${API}/vote.php?elu_id=${eluId}`, { credentials: "same-origin" })
      .then(r => {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(d => {
        if (d && !d.error) {
          setLikes(d.likes ?? 0);
          setDislikes(d.dislikes ?? 0);
          if (d.userVote !== undefined && d.userVote !== null) {
            setUserVote(d.userVote);
            setLocalVote(eluId, d.userVote);
          }
        }
      })
      .catch(() => {
        // Fallback : afficher au moins le vote local
        const localV = local[eluId];
        if (localV === 1) setLikes(1);
        else if (localV === -1) setDislikes(1);
      });
  }, [eluId]);

  const handleVote = async (vote) => {
    if (loading) return;
    setLoading(true);

    // Mise à jour optimiste immédiate
    const prevLikes = likes, prevDislikes = dislikes, prevVote = userVote;
    if (userVote === vote) {
      // Toggle off
      if (vote === 1) setLikes(l => Math.max(0, l - 1));
      else setDislikes(l => Math.max(0, l - 1));
      setUserVote(null);
      setLocalVote(eluId, null);
    } else if (userVote) {
      // Change vote
      if (vote === 1) { setLikes(l => l + 1); setDislikes(l => Math.max(0, l - 1)); }
      else { setDislikes(l => l + 1); setLikes(l => Math.max(0, l - 1)); }
      setUserVote(vote);
      setLocalVote(eluId, vote);
    } else {
      // New vote
      if (vote === 1) setLikes(l => l + 1);
      else setDislikes(l => l + 1);
      setUserVote(vote);
      setLocalVote(eluId, vote);
    }

    try {
      const r = await fetch(`${API}/vote.php`, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ elu_id: eluId, vote }),
      });
      const d = await r.json();
      // Synchroniser avec la réponse serveur (source de vérité)
      setLikes(d.likes ?? prevLikes);
      setDislikes(d.dislikes ?? prevDislikes);
    } catch (e) {
      // Rollback en cas d'erreur réseau
      setLikes(prevLikes);
      setDislikes(prevDislikes);
      setUserVote(prevVote);
      setLocalVote(eluId, prevVote);
    }
    setLoading(false);
  };

  const total = likes + dislikes || 1;
  const likePct = Math.round((likes / total) * 100);

  return (
    <div style={{
      background: S.card, borderRadius: 12, padding: "12px 16px", marginBottom: 16,
      border: `1px solid ${S.border}`,
    }}>
      <div style={{ fontFamily: S.font, fontSize: 10, fontWeight: 800, color: S.textDim, textTransform: "uppercase", letterSpacing: 1, marginBottom: 10, textAlign: "center" }}>
        Vote citoyen
      </div>

      <div style={{ display: "flex", gap: 10, justifyContent: "center", alignItems: "center", marginBottom: 10 }}>
        {/* Like */}
        <button onClick={() => handleVote(1)} disabled={loading} style={{
          display: "flex", alignItems: "center", gap: 6,
          padding: "8px 16px", borderRadius: 99, cursor: loading ? "wait" : "pointer",
          background: userVote === 1 ? "rgba(0,184,148,0.2)" : "rgba(255,255,255,0.04)",
          border: `2px solid ${userVote === 1 ? S.green : S.border}`,
          transition: "all 0.2s",
        }}>
          <span style={{ fontSize: 18 }}>👍</span>
          <span style={{ fontFamily: S.font, fontSize: 14, fontWeight: 900, color: userVote === 1 ? S.green : S.textMuted }}>
            {likes}
          </span>
        </button>

        {/* Dislike */}
        <button onClick={() => handleVote(-1)} disabled={loading} style={{
          display: "flex", alignItems: "center", gap: 6,
          padding: "8px 16px", borderRadius: 99, cursor: loading ? "wait" : "pointer",
          background: userVote === -1 ? "rgba(255,107,107,0.2)" : "rgba(255,255,255,0.04)",
          border: `2px solid ${userVote === -1 ? S.red : S.border}`,
          transition: "all 0.2s",
        }}>
          <span style={{ fontSize: 18 }}>👎</span>
          <span style={{ fontFamily: S.font, fontSize: 14, fontWeight: 900, color: userVote === -1 ? S.red : S.textMuted }}>
            {dislikes}
          </span>
        </button>
      </div>

      {/* Barre de progression */}
      {(likes + dislikes) > 0 && (
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <span style={{ fontFamily: S.font, fontSize: 10, fontWeight: 800, color: S.green }}>{likePct}%</span>
          <div style={{ flex: 1, height: 6, borderRadius: 99, background: S.red + "44", overflow: "hidden" }}>
            <div style={{
              width: `${likePct}%`, height: "100%", borderRadius: 99,
              background: `linear-gradient(90deg, ${S.green}, ${S.green}cc)`,
              transition: "width 0.5s ease",
            }} />
          </div>
          <span style={{ fontFamily: S.font, fontSize: 10, fontWeight: 800, color: S.red }}>{100 - likePct}%</span>
        </div>
      )}

      <div style={{ fontFamily: S.font, fontSize: 8, color: S.textDim, textAlign: "center", marginTop: 6 }}>
        {likes + dislikes} vote{likes + dislikes > 1 ? "s" : ""} ·{" "}
        {userVote
          ? <span style={{ color: S.gold }}>✎ cliquez pour changer</span>
          : "1 vote par personne · modifiable"
        }
      </div>
    </div>
  );
};

export default VoteCitoyen;
