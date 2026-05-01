import { useState, useEffect, useRef } from "react";
import { S } from "../utils/constants";

// Mapping parti → angle sur le demi-cercle (0° = gauche, 180° = droite)
const PARTI_ANGLE = {
  "Nouveau Parti Anticapitaliste": 8, "NPA": 8,
  "Lutte Ouvrière": 10,
  "Parti communiste français": 15, "PCF": 15,
  "La France insoumise": 18, "LFI": 18, "FI": 18,
  "Parti socialiste": 42, "PS": 42, "Place publique": 42,
  "Parti radical de gauche": 50, "PRG": 50, "Générations": 45,
  "Les Écologistes": 65, "EELV": 65, "EE-LV": 65, "Europe Écologie": 65,
  "Mouvement Démocrate": 90, "MoDem": 90, "MODEM": 90,
  "Union des Démocrates et Indépendants": 88, "UDI": 88,
  "Ensemble": 105,
  "Renaissance": 112, "LREM": 108, "En Marche": 108,
  "Horizons": 118, "HOR": 118,
  "Les Républicains": 138, "LR": 138, "UMP": 135, "RPR": 132,
  "Rassemblement national": 162, "RN": 162, "FN": 160,
  "Reconquête": 168,
  "Debout la France": 155,
  "Régions et peuples solidaires": 55,
  "LIOT": 95,
  "default": 90,
};

// Phrases universelles sur la politique, pas sur un bord en particulier
const FUN_POOL = [
  "La boussole tourne, l'elu reste.",
  "Gauche, droite... l'important c'est le fauteuil.",
  "Position officielle. Peut varier selon les elections.",
  "Tous les chemins menent a l'Assemblee.",
  "Le GPS politique : recalcul en cours...",
  "Ici se situe votre elu. Jusqu'au prochain remaniement.",
  "A cet endroit precis du spectre, on promet beaucoup.",
  "Coordonnees politiques. Sujet a turbulences.",
  "Un elu, un parti, une boussole. Rien de simple.",
  "L'aiguille a parle. L'elu, pas toujours.",
  "En politique, le nord change souvent de direction.",
  "Quelque part entre les promesses et la realite.",
  "Position actuelle. Position d'hier : voir Wikipedia.",
  "Le spectre politique : tout le monde y trouve sa place. Et la perd.",
  "Attention : cette position peut changer sans preavis.",
  "L'elu est ici. Ses electeurs, parfois ailleurs.",
  "Toute ressemblance avec un programme serait fortuite.",
  "La politique, c'est comme une boussole : ca tourne en rond.",
  "Place reservee. Sous reserve de reelection.",
  "Ici on debat. De l'autre cote aussi. Au milieu, on attend.",
  "Parti politique : famille qu'on choisit. Et qu'on quitte.",
  "L'etiquette est la. Le contenu, ca depend des jours.",
  "Elu positionne. Electeurs surpris. Comme d'habitude.",
  "A ce stade, c'est plus une conviction, c'est un bail.",
  "Le spectre politique a plus de nuances que les discours.",
  "Quelque part entre le tract et la realite.",
  "Position calculee. Comme les sondages.",
  "Un pas a gauche, un pas a droite. On appelle ca gouverner.",
  "L'aiguille pointe ici. Le programme pointe ailleurs.",
  "En politique, meme la boussole hesite.",
  "Carte du parti en poche. Plan de carriere en tete.",
  "Chaque elu a sa place. Et chaque place a son indemnite.",
  "Le curseur est la. Pour combien de temps, mystere.",
  "Si la politique etait un sport, ce serait le slalom.",
  "Ideologie : mot complique pour dire 'c'est mon equipe'.",
  "L'important c'est pas ou on est, c'est combien de temps on y reste.",
  "Position affichee. Opinions reelles : nous consulter.",
  "Zone de confort politique atteinte. Priere de ne pas deranger.",
  "Entre ici et le programme, il y a un ocean de nuances.",
];

const FUN_LABELS = {
  "extrême gauche": FUN_POOL,
  "gauche": FUN_POOL,
  "centre-gauche": FUN_POOL,
  "écologiste": FUN_POOL,
  "centre": FUN_POOL,
  "centre-droit": FUN_POOL,
  "droite": FUN_POOL,
  "extrême droite": FUN_POOL,
};

const POSITIONS = [
  { label: "Ex.G", angle: 15, short: "extrême gauche" },
  { label: "G", angle: 42, short: "gauche" },
  { label: "C.G", angle: 65, short: "centre-gauche" },
  { label: "", angle: 82, short: "écologiste" },
  { label: "C", angle: 90, short: "centre" },
  { label: "C.D", angle: 108, short: "centre-droit" },
  { label: "D", angle: 135, short: "droite" },
  { label: "Ex.D", angle: 165, short: "extrême droite" },
];

function getPositionLabel(angle) {
  let closest = POSITIONS[0];
  let minDist = Math.abs(angle - POSITIONS[0].angle);
  POSITIONS.forEach(p => {
    const d = Math.abs(angle - p.angle);
    if (d < minDist) { minDist = d; closest = p; }
  });
  return closest.short;
}

function getFunLabel(angle) {
  const pos = getPositionLabel(angle);
  const labels = FUN_LABELS[pos] || ["Un élu, c'est un élu."];
  return labels[Math.floor(Math.random() * labels.length)];
}

function angleToXY(angleDeg, r, cx, cy) {
  const rad = (angleDeg * Math.PI) / 180;
  return { x: cx - r * Math.cos(rad), y: cy - r * Math.sin(rad) };
}

function getPartiAngle(parti) {
  if (!parti) return PARTI_ANGLE.default;
  for (const key of Object.keys(PARTI_ANGLE)) {
    if (parti.toLowerCase().includes(key.toLowerCase())) return PARTI_ANGLE[key];
  }
  return PARTI_ANGLE.default;
}

function arcPath(cx, cy, r, a1, a2) {
  const p1 = angleToXY(a1, r, cx, cy);
  const p2 = angleToXY(a2, r, cx, cy);
  const large = a2 - a1 > 90 ? 1 : 0;
  return `M ${p1.x} ${p1.y} A ${r} ${r} 0 ${large} 0 ${p2.x} ${p2.y}`;
}

const NO_PARTI_LABELS = [
  "Aucun parti déclaré.", "Électron libre !", "Sans étiquette.",
  "Ni carte, ni rosette.", "Franc-tireur politique.",
];

const BoussolePolique = ({ elu }) => {
  const parti = elu.parti || "";
  const hasParti = parti && !["sans étiquette", "sans parti", "divers", "se", "dvd", "dvg", "div"].includes(parti.toLowerCase().trim());
  const historique = elu.historique_partis || [];
  const targetAngle = hasParti ? getPartiAngle(parti) : 90;
  const [currentAngle, setCurrentAngle] = useState(90);
  const [hoveredBookmark, setHoveredBookmark] = useState(null);
  const [showHelp, setShowHelp] = useState(false);
  const funLabel = useRef(hasParti ? getFunLabel(targetAngle) : NO_PARTI_LABELS[Math.floor(Math.random() * NO_PARTI_LABELS.length)]).current;

  useEffect(() => {
    const start = performance.now();
    const duration = 1200;
    const from = 90;
    const to = targetAngle;
    const frame = (now) => {
      const t = Math.min((now - start) / duration, 1);
      const ease = t === 1 ? 1 : 1 - Math.pow(2, -8 * t) * Math.cos((t * 10 - 0.75) * (2 * Math.PI) / 3);
      setCurrentAngle(from + (to - from) * ease);
      if (t < 1) requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
  }, [targetAngle]);

  // Géométrie SVG — arc ∩, textes en HTML dessous
  const R = 120, R_inner = 80, R_tick = 108, R_label = 148;
  const W = 340;
  const cx = W / 2;
  const cy = R_label + 8;
  const H = cy + 16; // juste assez pour l'arc, textes en HTML dessous

  const needleLen = R_inner - 6;
  const needlePt = angleToXY(currentAngle, needleLen, cx, cy);
  const needleBase1 = angleToXY(currentAngle + 90, 6, cx, cy);
  const needleBase2 = angleToXY(currentAngle - 90, 6, cx, cy);

  const needleColor = currentAngle < 45 ? "#c62828"
    : currentAngle < 75 ? "#e57373"
    : currentAngle < 95 ? "#9b59b6"
    : currentAngle < 115 ? "#29b6f6"
    : currentAngle < 145 ? "#1565c0"
    : "#0d47a1";

  const posLabel = getPositionLabel(targetAngle);
  const posLabelDisplay = posLabel.charAt(0).toUpperCase() + posLabel.slice(1);

  return (
    <div style={{ position: "relative", width: "100%" }}>
      {/* Titre */}
      <div style={{ textAlign: "center", marginBottom: 4 }}>
        <span style={{ fontFamily: S.font, fontSize: 12, fontWeight: 900, color: S.gold, textTransform: "uppercase", letterSpacing: 1.5 }}>
          Boussole Politique
        </span>
      </div>

      {/* Bouton ? */}
      <div onClick={() => setShowHelp(!showHelp)} style={{
        position: "absolute", top: 0, right: 0, width: 22, height: 22, borderRadius: "50%",
        background: showHelp ? S.gold : "rgba(255,255,255,0.08)", border: `1px solid ${showHelp ? S.gold : S.border}`,
        display: "flex", alignItems: "center", justifyContent: "center",
        cursor: "pointer", fontSize: 12, fontWeight: 900, color: showHelp ? S.bg : S.textDim,
        fontFamily: S.font, transition: "all 0.2s", zIndex: 2,
      }}>?</div>
      {showHelp && (
        <div style={{
          position: "absolute", top: 28, right: 0, width: 200, padding: "10px 12px",
          background: S.card, border: `1px solid ${S.gold}44`, borderRadius: 10,
          fontFamily: S.font, fontSize: 10, color: S.textMuted, lineHeight: 1.5,
          zIndex: 10, boxShadow: "0 8px 24px rgba(0,0,0,0.4)",
        }}>
          <div style={{ fontWeight: 800, color: S.gold, marginBottom: 6 }}>Boussole politique</div>
          <div style={{ marginBottom: 4 }}>L'aiguille indique la position politique de l'élu selon son parti actuel.</div>
          <div style={{ marginBottom: 4 }}>Les numéros autour du cadran retracent ses changements de parti au fil des années.</div>
          <div style={{ marginTop: 6, fontSize: 9, color: S.textDim, borderTop: `1px solid ${S.border}`, paddingTop: 4 }}>
            Sources : <a href="https://www.nosdeputes.fr" target="_blank" rel="noreferrer" style={{ color: S.gold, textDecoration: "none" }}>NosDéputés.fr</a> · <a href="https://www.senat.fr" target="_blank" rel="noreferrer" style={{ color: S.gold, textDecoration: "none" }}>Sénat</a> · <a href="https://www.data.gouv.fr/fr/datasets/repertoire-national-des-elus-1/" target="_blank" rel="noreferrer" style={{ color: S.gold, textDecoration: "none" }}>data.gouv.fr</a>
          </div>
        </div>
      )}

      {/* SVG : arc + labels + aiguille */}
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ display: "block", overflow: "visible" }}>
        <defs>
          <filter id="glow">
            <feGaussianBlur stdDeviation="2" result="blur" />
            <feMerge><feMergeNode in="blur" /><feMergeNode in="SourceGraphic" /></feMerge>
          </filter>
        </defs>

        {/* Arc fond transparent */}
        <path d={arcPath(cx, cy, R, 5, 175)} fill="none" stroke="transparent" strokeWidth="16" strokeLinecap="round" />

        {/* Graduations */}
        {POSITIONS.map((pos, i) => {
          const inner = angleToXY(pos.angle, R_tick, cx, cy);
          const outer = angleToXY(pos.angle, R + 2, cx, cy);
          return <line key={i} x1={inner.x} y1={inner.y} x2={outer.x} y2={outer.y} stroke="#fff" strokeWidth="1.5" opacity="0.4" />;
        })}

        {/* Labels orientations */}
        {POSITIONS.filter(pos => pos.label !== "").map((pos, i) => {
          const pt = angleToXY(pos.angle, R_label, cx, cy);
          return (
            <text key={i} x={pt.x} y={pt.y} textAnchor="middle" dominantBaseline="middle"
              fill="#fff" fontSize="13" fontFamily="Nunito, sans-serif" fontWeight="800">
              {pos.label}
            </text>
          );
        })}

        {/* Historique bookmarks */}
        {historique.map((h, i) => {
          const hAngle = getPartiAngle(h.parti);
          const bPt = angleToXY(hAngle, R - 22, cx, cy);
          const isHovered = hoveredBookmark === i;
          return (
            <g key={i} style={{ cursor: "pointer" }}
              onMouseEnter={() => setHoveredBookmark(i)}
              onMouseLeave={() => setHoveredBookmark(null)}>
              <circle cx={bPt.x} cy={bPt.y} r={isHovered ? 10 : 7}
                fill={isHovered ? S.gold : "#2a2a42"}
                stroke={S.gold + "88"} strokeWidth="1.5"
                style={{ transition: "all 0.15s" }} />
              <text x={bPt.x} y={bPt.y} textAnchor="middle" dominantBaseline="middle"
                fill={isHovered ? S.bg : S.gold} fontSize="9" fontFamily="Nunito, sans-serif" fontWeight="900">
                {i + 1}
              </text>
            </g>
          );
        })}

        {/* Historique tooltips */}
        {historique.map((h, i) => {
          if (hoveredBookmark !== i) return null;
          const hAngle = getPartiAngle(h.parti);
          const bPt = angleToXY(hAngle, R - 22, cx, cy);
          const label = `${h.parti} · ${h.annee}`;
          const tw = Math.max(80, label.length * 6);
          const tx = Math.max(tw / 2 + 4, Math.min(bPt.x, W - tw / 2 - 4));
          const ty = bPt.y - 26;
          return (
            <g key={`tt-${i}`} style={{ pointerEvents: "none" }}>
              <rect x={tx - tw / 2} y={ty - 2} width={tw} height={24} rx={6}
                fill="#0f0f1a" stroke={S.gold} strokeWidth="1.5"
                style={{ filter: "drop-shadow(0 4px 8px rgba(0,0,0,0.6))" }} />
              <text x={tx} y={ty + 9} textAnchor="middle" dominantBaseline="middle"
                fill={S.gold} fontSize="10" fontFamily="Nunito, sans-serif" fontWeight="800">
                {label}
              </text>
            </g>
          );
        })}

        {/* Aiguille ombre */}
        <polygon
          points={`${needlePt.x},${needlePt.y} ${needleBase1.x},${needleBase1.y} ${needleBase2.x},${needleBase2.y}`}
          fill="#000" opacity="0.25" transform="translate(2,2)"
        />
        {/* Aiguille */}
        <polygon
          points={`${needlePt.x},${needlePt.y} ${needleBase1.x},${needleBase1.y} ${needleBase2.x},${needleBase2.y}`}
          fill={needleColor} filter="url(#glow)"
        />
        {/* Hub */}
        <circle cx={cx} cy={cy} r={9} fill={S.card} stroke={S.border} strokeWidth="2" />
        <circle cx={cx} cy={cy} r={5} fill={needleColor} />
      </svg>

      {/* Textes sous l'arc — en HTML pour un contrôle total */}
      <div style={{ textAlign: "center", marginTop: 6 }}>
        <div style={{ fontFamily: S.font, fontSize: 18, fontWeight: 800, color: hasParti ? needleColor : S.textDim }}>
          {hasParti ? posLabelDisplay : "Pas de parti"}
        </div>
        <div style={{ fontFamily: S.font, fontSize: 17, fontStyle: "italic", color: "#fff", opacity: 0.9, marginTop: 4 }}>
          {`« ${funLabel} »`}
        </div>
      </div>
    </div>
  );
};

export default BoussolePolique;
