import { useState, useEffect } from "react";
import { S } from "../utils/constants";
import Confetti from "./Confetti";

// Collecte TOUS les jackpots éligibles, en choisit un aléatoirement
const pickJackpot = (elu) => {
  const pool = [];
  const affaires = (elu.affaires || []).filter(a => a.statut !== "clean");
  const nbAffaires = affaires.length;
  const nbCondamne = affaires.filter(a => (a.statut || "").includes("condamn")).length;
  const coutTotal = elu.cout_carriere || 0;
  const mandats = (elu.mandats || []).filter(m => typeof m !== "string");
  // Filtrer les titres qui sont de vrais mandats rémunérés (pas porte-parole, vice-président de parti, etc.)
  const vraiMandat = /député|sénateur|sénatrice|maire|conseill|adjoint|ministre|garde des sceaux|premier ministre|président.*(rép|conseil|sénat|assemblée)|membre.*conseil constitutionnel|secrétaire d.état/i;
  const mandatsReels = mandats.filter(m => vraiMandat.test(m.titre || ""));
  const mandatsTypes = new Set(
    mandatsReels.map(m => (m.titre || "").replace(/\s*—.*/, "").replace(/\s*de\s+.*/, "").replace(/\s*d'.*/, "").replace(/\d+e?r?\s*/g, "").trim().toLowerCase()).filter(Boolean)
  );
  const nbTypesDistincts = mandatsTypes.size;
  const age = elu.age || (elu.date_naissance && elu.date_naissance > "1900-01-01"
    ? Math.floor((Date.now() - new Date(elu.date_naissance).getTime()) / (365.25 * 24 * 60 * 60 * 1000))
    : null);
  const nbMandats = mandatsReels.length;

  // ── Judiciaire (seules les condamnations comptent pour le jackpot) ──
  if (nbCondamne > 0) {
    const icons = nbCondamne === 1 ? ["⚠️"] : nbCondamne === 2 ? ["⚠️","⚠️"] : ["💀","💀","💀"];
    const color = nbCondamne >= 3 ? S.red : S.gold;
    const msg = nbCondamne >= 3 ? `BINGO ! ${nbCondamne} condamnation${nbCondamne > 1 ? "s" : ""} !` : `${nbCondamne} condamnation${nbCondamne > 1 ? "s" : ""}`;
    const prefix = nbCondamne >= 3 ? "💀" : "⚠️";
    pool.push({ type: "judiciaire", label: "Jackpot Judiciaire", icons, color, msg, prefix, emojis: ["⚖️","💰","🤥","🎭","🏛️","💼","🗳️","👔"], priority: 10 });
  } else if (nbAffaires > 0) {
    // Affaires en cours mais pas condamné = priorité moindre
    pool.push({ type: "judiciaire", label: "Jackpot Judiciaire", icons: ["⚠️"], color: S.gold, msg: `${nbAffaires} affaire${nbAffaires > 1 ? "s" : ""} en cours`, prefix: "⏳", emojis: ["⚖️","💰","🤥","🎭","🏛️","💼","🗳️","👔"], priority: 5 });
  }

  // ── Financier (>2M€ carrière) ──
  if (coutTotal >= 2_000_000) {
    const millions = (coutTotal / 1_000_000).toFixed(1).replace(/\.0$/, "");
    pool.push({ type: "financier", label: "Jackpot Financier", icons: ["💸","💸","💸"], color: S.gold, msg: `${millions}M€ cout total carriere !`, prefix: "🤑", emojis: ["💰","💸","🏦","💎","🪙","📈","🤑","💶"], priority: 5 });
  }

  // ── Mandats (3+ types différents) ──
  if (nbTypesDistincts >= 3) {
    pool.push({ type: "mandats", label: "Jackpot des Mandats", icons: ["🏛️","🏛️","🏛️"], color: S.purple, msg: `${nbTypesDistincts} postes differents !`, prefix: "🎰", emojis: ["🏛️","🏳️","🏅","👔","🎖️","📋","⭐","🗳️"], priority: 5 });
  }

  // ── Senior (>70 ans) ──
  if (age && age >= 70) {
    pool.push({ type: "senior", label: "Jackpot Senior", icons: ["🧓","🧓","🧓"], color: "#e17055", msg: `${age} ans et toujours la !`, prefix: "🏆", emojis: ["🧓","👴","🎩","📜","🏛️","⌛","🍷","🪑"], priority: 3 });
  }

  // ── Junior (<35 ans) ──
  if (age && age <= 35) {
    pool.push({ type: "junior", label: "Jackpot Junior", icons: ["🌱","🌱","🌱"], color: "#00b894", msg: `${age} ans seulement !`, prefix: "🚀", emojis: ["🌱","🔥","💫","🎓","📱","⚡","🏃","🌟"], priority: 3 });
  }

  // ── Casier vierge (aucune condamnation) ──
  if (nbCondamne === 0) {
    pool.push({ type: "clean", label: "Jackpot Judiciaire", icons: ["✅","✅","✅"], color: S.green, msg: "Casier vierge ! C'est rare.", prefix: "🎉", emojis: ["⚖️","💰","🤥","🎭","🏛️","💼","🗳️","👔"], priority: 1 });
  }

  // ── Cumulard (5+ mandats) ──
  if (nbMandats >= 5) {
    pool.push({ type: "cumulard", label: "Jackpot Cumulard", icons: ["🪑","🪑","🪑"], color: "#fdcb6e", msg: `${nbMandats} mandats au compteur !`, prefix: "🤹", emojis: ["🪑","🏛️","👔","📋","🗳️","🎪","💺","🎖️"], priority: 3 });
  }

  // ── Populaire (beaucoup de likes) ──
  const nbLikes = elu.nb_likes || 0;
  const nbDislikes = elu.nb_dislikes || 0;
  const totalVotes = nbLikes + nbDislikes;
  if (totalVotes >= 5 && nbLikes / totalVotes >= 0.75) {
    pool.push({ type: "populaire", label: "Jackpot Populaire", icons: ["❤️","❤️","❤️"], color: "#e84393", msg: `${Math.round(nbLikes / totalVotes * 100)}% d'amour !`, prefix: "🥰", emojis: ["❤️","💕","🌹","😍","👏","🎉","💖","✨"], priority: 4 });
  }

  // ── Détesté (beaucoup de dislikes) ──
  if (totalVotes >= 5 && nbDislikes / totalVotes >= 0.75) {
    pool.push({ type: "deteste", label: "Jackpot Impopulaire", icons: ["👎","👎","👎"], color: "#d63031", msg: `${Math.round(nbDislikes / totalVotes * 100)}% de pouces rouges !`, prefix: "😤", emojis: ["👎","💢","🤬","🚫","🗑️","📉","💩","⛔"], priority: 4 });
  }

  // ── Marathonien (carrière >30 ans) ──
  const firstMandat = mandats.reduce((min, m) => {
    const y = m.date_debut ? parseInt(m.date_debut.substring(0, 4)) : 9999;
    return y < min ? y : min;
  }, 9999);
  const anneesCarriere = firstMandat < 9999 ? new Date().getFullYear() - firstMandat : 0;
  if (anneesCarriere >= 30) {
    pool.push({ type: "marathonien", label: "Jackpot Marathonien", icons: ["🏃","🏃","🏃"], color: "#0984e3", msg: `${anneesCarriere} ans de carriere politique !`, prefix: "🏅", emojis: ["🏃","⏰","📅","🗓️","🏛️","🔄","🎖️","⌛"], priority: 3 });
  }

  // ── Touche-à-tout (mandats dans 3+ institutions différentes) ──
  const institutions = new Set(mandats.map(m => (m.institution || "").trim().toLowerCase()).filter(Boolean));
  if (institutions.size >= 3) {
    pool.push({ type: "touche-a-tout", label: "Jackpot Touche-a-tout", icons: ["🎪","🎪","🎪"], color: "#6c5ce7", msg: `${institutions.size} institutions differentes !`, prefix: "🎭", emojis: ["🎪","🏛️","🏰","🗳️","🌍","🎯","🔀","🎲"], priority: 3 });
  }

  // ── Récidiviste (condamné plusieurs fois) ──
  if (nbCondamne >= 2) {
    pool.push({ type: "recidiviste", label: "Jackpot Recidiviste", icons: ["🔒","🔒","🔒"], color: "#d63031", msg: `${nbCondamne} condamnations !`, prefix: "⛓️", emojis: ["🔒","⚖️","🚨","👮","📜","🔗","⛓️","🏴"], priority: 6 });
  }

  // ── Nomade politique (3+ partis) ──
  const partis = elu.historique_partis || (elu.patrimoine_detail?.historique_partis) || [];
  if (partis.length >= 3) {
    pool.push({ type: "nomade", label: "Jackpot Nomade", icons: ["🏳️","🏳️","🏳️"], color: "#e17055", msg: `${partis.length} partis differents !`, prefix: "🦎", emojis: ["🏳️","🔄","🌈","🎨","🦎","💨","🏃","🔀"], priority: 3 });
  }

  if (pool.length === 0) {
    pool.push({ type: "clean", label: "Jackpot Judiciaire", icons: ["✅","✅","✅"], color: S.green, msg: "Casier vierge !", prefix: "🎉", emojis: ["⚖️","💰","🤥","🎭","🏛️","💼","🗳️","👔"], priority: 1 });
  }

  // Choisir aléatoirement parmi les éligibles, pondéré par priorité
  const totalWeight = pool.reduce((s, j) => s + j.priority, 0);
  let rand = Math.random() * totalWeight;
  for (const j of pool) {
    rand -= j.priority;
    if (rand <= 0) return j;
  }
  return pool[0];
};

const SlotMachine = ({ elu, mini = false }) => {
  const [spinning, setSpinning] = useState(false);
  const [revealed, setRevealed] = useState(false);
  const [confetti, setConfetti] = useState(false);

  const jackpot = pickJackpot(elu);
  const nbSlots = jackpot.icons.length;

  const spin = () => {
    setSpinning(true); setRevealed(false);
    setTimeout(() => {
      setSpinning(false); setRevealed(true);
      setConfetti(true);
      setTimeout(() => setConfetti(false), 100);
    }, 1500);
  };
  useEffect(() => { spin(); }, [elu.id]);

  if (mini) {
    return (
      <>
        <Confetti active={confetti} />
        <div style={{
          background: S.card, borderRadius: 12, padding: "10px 16px", marginBottom: 12,
          border: `1px solid ${S.border}`, display: "flex", alignItems: "center", gap: 12, justifyContent: "center",
        }}>
          <span style={{ fontFamily: S.fontTitle, fontSize: 10, color: jackpot.color, textTransform: "uppercase", letterSpacing: "0.15em", opacity: 0.8 }}>
            {jackpot.label}
          </span>
          <div style={{ display: "flex", gap: 6 }}>
            {jackpot.icons.map((_, i) => (
              <div key={i} style={{
                width: 32, height: 36, background: "rgba(255,255,255,0.04)", borderRadius: 6,
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: spinning ? 12 : 18, border: `1px solid ${S.border}`, overflow: "hidden",
                boxShadow: "inset 0 1px 3px rgba(0,0,0,0.3)",
              }}>
                {spinning ? (
                  <div style={{ animation: `slotSpin 0.15s ${i*0.05}s infinite linear`, display: "flex", flexDirection: "column", gap: 2 }}>
                    {jackpot.emojis.map((e, j) => <span key={j}>{e}</span>)}
                  </div>
                ) : <span style={{ animation: "popIn 0.3s cubic-bezier(0.68,-0.55,0.265,1.55)", animationDelay: `${i*0.1}s`, animationFillMode: "both" }}>{jackpot.icons[i]}</span>}
              </div>
            ))}
          </div>
          {revealed && (
            <span style={{ fontFamily: S.font, fontSize: 11, fontWeight: 800, color: jackpot.color }}>
              {jackpot.prefix} {jackpot.msg}
            </span>
          )}
        </div>
      </>
    );
  }

  return (
    <>
      <Confetti active={confetti} />
      <div style={{
        background: S.card, borderRadius: 16, padding: "24px 28px", marginBottom: 24,
        border: `1px solid ${S.border}`,
        boxShadow: "0 0 20px rgba(253,203,110,0.06), 0 4px 16px rgba(0,0,0,0.2)",
      }}>
        <div style={{ display: "flex", gap: 2, marginBottom: 16, borderRadius: 4, overflow: "hidden" }}>
          {Array.from({ length: 16 }, (_, i) => (
            <div key={i} style={{ flex: 1, height: 2, background: [S.red, S.gold, S.green, S.purple][i % 4], opacity: 0.6 }} />
          ))}
        </div>
        <div style={{
          textAlign: "center", marginBottom: 14, fontFamily: S.fontTitle,
          fontSize: 12, color: jackpot.color, textTransform: "uppercase", letterSpacing: "0.2em",
          opacity: 0.9, textShadow: `0 0 8px ${jackpot.color}44`,
        }}>
          {jackpot.label}
        </div>
        <div style={{ display: "flex", justifyContent: "center", gap: 10, marginBottom: 14 }}>
          {jackpot.icons.map((_, i) => (
            <div key={i} style={{
              width: 64, height: 72, background: "rgba(255,255,255,0.04)", borderRadius: 10,
              display: "flex", alignItems: "center", justifyContent: "center",
              fontSize: spinning ? 20 : 32, border: `1px solid ${S.border}`, overflow: "hidden",
              boxShadow: "inset 0 2px 6px rgba(0,0,0,0.3)",
            }}>
              {spinning ? (
                <div style={{ animation: `slotSpin 0.15s ${i*0.05}s infinite linear`, display: "flex", flexDirection: "column", gap: 4 }}>
                  {jackpot.emojis.map((e, j) => <span key={j}>{e}</span>)}
                </div>
              ) : (
                <span style={{ animation: "popIn 0.3s cubic-bezier(0.68,-0.55,0.265,1.55)", animationDelay: `${i*0.15}s`, animationFillMode: "both" }}>
                  {jackpot.icons[i]}
                </span>
              )}
            </div>
          ))}
        </div>
        {revealed && (
          <div style={{ textAlign: "center", animation: "popIn 0.4s 0.5s cubic-bezier(0.68,-0.55,0.265,1.55) both" }}>
            <div style={{
              fontFamily: S.font, fontSize: 14, fontWeight: 800,
              color: jackpot.color,
              textShadow: `0 0 10px ${jackpot.color}44`,
            }}>
              {jackpot.prefix} {jackpot.msg}
            </div>
          </div>
        )}
        <div style={{ textAlign: "center", marginTop: 12 }}>
          <button onClick={spin} style={{
            background: `linear-gradient(135deg, ${jackpot.color}, ${jackpot.color}bb)`, border: "none", borderRadius: 99,
            padding: "7px 20px", fontSize: 12, fontWeight: 800, fontFamily: S.font, color: jackpot.type === "financier" ? S.bg : "#fff",
            cursor: "pointer", transition: "transform 0.15s", boxShadow: `0 0 12px ${jackpot.color}44`,
          }}
          onMouseEnter={e => e.target.style.transform = "scale(1.05)"}
          onMouseLeave={e => e.target.style.transform = "scale(1)"}
          >Relancer</button>
        </div>
      </div>
    </>
  );
};

export default SlotMachine;
