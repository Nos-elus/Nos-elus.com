import { useRef, useCallback } from "react";
import { S } from "../utils/constants";

const W = 600, H = 340;

const drawCard = (ctx, elu) => {
  // Fond dégradé sombre
  const bg = ctx.createLinearGradient(0, 0, W, H);
  bg.addColorStop(0, "#0f0f1a");
  bg.addColorStop(0.5, "#1a1a2e");
  bg.addColorStop(1, "#16213e");
  ctx.fillStyle = bg;
  ctx.fillRect(0, 0, W, H);

  // Bordure dorée
  ctx.strokeStyle = "#fdcb6e";
  ctx.lineWidth = 3;
  ctx.strokeRect(1.5, 1.5, W - 3, H - 3);

  // Ligne décorative en haut
  const colors = ["#FF6B6B", "#fdcb6e", "#00b894", "#6c5ce7"];
  for (let i = 0; i < W; i += 4) {
    ctx.fillStyle = colors[Math.floor(i / 4) % 4];
    ctx.fillRect(i, 0, 4, 3);
  }

  // Cercle photo placeholder (gauche)
  const cx = 90, cy = 130;
  ctx.beginPath();
  ctx.arc(cx, cy, 55, 0, Math.PI * 2);
  ctx.fillStyle = "#2a2a42";
  ctx.fill();
  ctx.strokeStyle = elu.couleur || "#fdcb6e";
  ctx.lineWidth = 3;
  ctx.stroke();

  // Initiales dans le cercle
  const initials = (elu.nom || "").split(" ").map(w => w[0]).filter(Boolean).join("").substring(0, 2).toUpperCase();
  ctx.fillStyle = "#fdcb6e";
  ctx.font = "bold 28px 'Nunito', sans-serif";
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillText(initials, cx, cy);

  // Nom
  ctx.textAlign = "left";
  ctx.fillStyle = "#ffffff";
  ctx.font = "900 26px 'Nunito', sans-serif";
  const nom = elu.prenom ? `${elu.prenom} ${elu.nom}` : elu.nom || "?";
  ctx.fillText(nom.length > 28 ? nom.substring(0, 26) + "..." : nom, 165, 75);

  // Fonction
  ctx.fillStyle = "#a0aec0";
  ctx.font = "700 14px 'Nunito', sans-serif";
  const fn = elu.fonction || "";
  ctx.fillText(fn.length > 50 ? fn.substring(0, 48) + "..." : fn, 165, 100);

  // Parti
  if (elu.parti) {
    ctx.fillStyle = "#fdcb6e";
    ctx.font = "800 13px 'Nunito', sans-serif";
    ctx.fillText(elu.parti, 165, 120);
  }

  // Age
  let age = null;
  if (elu.date_naissance && elu.date_naissance > "1900-01-01") {
    age = Math.floor((Date.now() - new Date(elu.date_naissance).getTime()) / (365.25 * 24 * 60 * 60 * 1000));
  }

  // Stats boxes
  const mandats = Array.isArray(elu.mandats) ? elu.mandats.filter(m => typeof m !== "string") : [];
  const affaires = (elu.affaires || []).filter(a => a.statut !== "clean");

  const stats = [];
  if (age) stats.push({ label: "Age", value: `${age} ans`, color: "#a0aec0" });
  stats.push({ label: "Mandats", value: `${mandats.length}`, color: "#6c5ce7" });
  stats.push({ label: "Affaires", value: `${affaires.length}`, color: affaires.length > 0 ? "#FF6B6B" : "#00b894" });
  if (elu.cout_carriere) {
    const m = (elu.cout_carriere / 1e6).toFixed(1).replace(/\.0$/, "");
    stats.push({ label: "Cout carriere", value: elu.cout_carriere >= 1e6 ? `${m}M` : `${Math.round(elu.cout_carriere / 1e3)}k`, color: "#fdcb6e" });
  }

  const boxW = 95, boxH = 60, startX = 165, startY = 145, gap = 8;
  stats.slice(0, 4).forEach((s, i) => {
    const x = startX + i * (boxW + gap);
    const y = startY;

    // Box fond
    ctx.fillStyle = "rgba(255,255,255,0.04)";
    ctx.beginPath();
    ctx.roundRect(x, y, boxW, boxH, 8);
    ctx.fill();
    ctx.strokeStyle = s.color + "44";
    ctx.lineWidth = 1;
    ctx.stroke();

    // Valeur
    ctx.fillStyle = s.color;
    ctx.font = "900 20px 'Nunito', sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(s.value, x + boxW / 2, y + 28);

    // Label
    ctx.fillStyle = "#8395a7";
    ctx.font = "700 10px 'Nunito', sans-serif";
    ctx.fillText(s.label, x + boxW / 2, y + 48);
  });

  // 5ème stat en dessous si existe
  if (stats.length >= 5) {
    const s = stats[4];
    const x = startX, y = startY + boxH + gap;
    ctx.fillStyle = "rgba(255,255,255,0.04)";
    ctx.beginPath();
    ctx.roundRect(x, y, boxW * 2 + gap, 44, 8);
    ctx.fill();
    ctx.strokeStyle = s.color + "44";
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.fillStyle = s.color;
    ctx.font = "900 16px 'Nunito', sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(`${s.label} : ${s.value} EUR`, x + (boxW * 2 + gap) / 2, y + 27);
  }

  // Casseroles / Clean badge
  ctx.textAlign = "left";
  if (affaires.length === 0) {
    ctx.fillStyle = "#00b894";
    ctx.font = "800 12px 'Nunito', sans-serif";
    ctx.fillText("Casier vierge", 165, 290);
  } else {
    ctx.fillStyle = "#FF6B6B";
    ctx.font = "800 12px 'Nunito', sans-serif";
    ctx.fillText(`${affaires.length} affaire${affaires.length > 1 ? "s" : ""} judiciaire${affaires.length > 1 ? "s" : ""}`, 165, 290);
  }

  // Branding bas
  ctx.fillStyle = "#fdcb6e";
  ctx.font = "900 16px 'Nunito', sans-serif";
  ctx.textAlign = "right";
  ctx.fillText("nos-elus.com", W - 20, H - 18);
  ctx.fillStyle = "#8395a7";
  ctx.font = "600 10px 'Nunito', sans-serif";
  ctx.fillText("Transparence politique citoyenne", W - 20, H - 36);

  // Ligne bas
  for (let i = 0; i < W; i += 4) {
    ctx.fillStyle = colors[Math.floor(i / 4) % 4];
    ctx.fillRect(i, H - 3, 4, 3);
  }
};

const ShareCard = ({ elu }) => {
  const canvasRef = useRef(null);

  const generate = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext("2d");
    drawCard(ctx, elu);

    // Si photo dispo, la charger et redessiner
    if (elu.photo_url) {
      const img = new Image();
      img.crossOrigin = "anonymous";
      img.onload = () => {
        // Redessiner le cercle avec la photo
        ctx.save();
        ctx.beginPath();
        ctx.arc(90, 130, 53, 0, Math.PI * 2);
        ctx.clip();
        ctx.drawImage(img, 90 - 53, 130 - 53, 106, 106);
        ctx.restore();
      };
      img.src = elu.photo_url;
    }
  }, [elu]);

  const download = () => {
    generate();
    setTimeout(() => {
      const canvas = canvasRef.current;
      if (!canvas) return;
      const link = document.createElement("a");
      const slug = (elu.slug || elu.nom || "elu").replace(/\s+/g, "-").toLowerCase();
      link.download = `nos-elus-${slug}.png`;
      link.href = canvas.toDataURL("image/png");
      link.click();
    }, 300);
  };

  const share = async () => {
    generate();
    setTimeout(async () => {
      const canvas = canvasRef.current;
      if (!canvas) return;
      try {
        const blob = await new Promise(r => canvas.toBlob(r, "image/png"));
        const file = new File([blob], `nos-elus-${elu.slug || "elu"}.png`, { type: "image/png" });
        if (navigator.share && navigator.canShare({ files: [file] })) {
          await navigator.share({ title: `${elu.nom} — nos-elus.com`, files: [file] });
        } else {
          download();
        }
      } catch {
        download();
      }
    }, 300);
  };

  return (
    <div style={{ marginTop: 12 }}>
      <canvas ref={canvasRef} style={{ display: "none" }} />
      <div style={{ display: "flex", gap: 8, justifyContent: "center" }}>
        <button onClick={share} onMouseEnter={generate} style={{
          background: `linear-gradient(135deg, ${S.gold}, #e5a84e)`,
          border: "none", borderRadius: 99, padding: "9px 20px",
          fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.bg,
          cursor: "pointer", display: "flex", alignItems: "center", gap: 6,
          transition: "transform 0.15s",
        }}
          onMouseOver={e => e.currentTarget.style.transform = "scale(1.03)"}
          onMouseOut={e => e.currentTarget.style.transform = "scale(1)"}
        >
          Partager la fiche
        </button>
      </div>
    </div>
  );
};

export default ShareCard;
