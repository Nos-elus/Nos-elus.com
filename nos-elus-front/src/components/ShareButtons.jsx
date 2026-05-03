import { useState } from "react";
import { S } from "../utils/constants";

const slugify = (str) =>
  str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/\s+/g, "-").replace(/[^a-z0-9-]/g, "");

const ShareButtons = ({ elu }) => {
  const slug = elu.slug || slugify(elu.nom);
  const rawUrl = `https://nos-elus.com/elu/${slug}`;
  const url = encodeURIComponent(rawUrl);
  // Pas de "nos-elus.com" dans le texte : sinon X interprète comme URL séparée et
  // génère la carte de la home au lieu de celle de l'élu (param &url= ignoré).
  const fullName = `${elu.prenom || ""} ${elu.nom || ""}`.trim() || elu.nom || "";
  const text = encodeURIComponent(`Découvrez la fiche de ${fullName}`);
  const ogImageUrl = `/api/og-image.php?slug=${slug}`;

  const [copied, setCopied] = useState(false);

  // Partage natif avec image (mobile)
  const shareWithImage = async () => {
    try {
      const res = await fetch(ogImageUrl);
      const blob = await res.blob();
      const file = new File([blob], `nos-elus-${slug}.png`, { type: "image/png" });
      if (navigator.share && navigator.canShare({ files: [file] })) {
        await navigator.share({
          title: `Fiche de ${fullName}`,
          text: `Découvrez la fiche de ${fullName}`,
          url: rawUrl,
          files: [file],
        });
        return true;
      }
    } catch {}
    return false;
  };

  // Télécharger l'image
  const downloadImage = async () => {
    try {
      const res = await fetch(ogImageUrl);
      const blob = await res.blob();
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = `nos-elus-${slug}.png`;
      link.click();
      URL.revokeObjectURL(link.href);
    } catch {}
  };

  const buttons = [
    { label: "X", bg: "#000", action: async () => {
      const shared = await shareWithImage();
      if (!shared) window.open(`https://x.com/intent/tweet?text=${text}&url=${url}`, "_blank");
    }},
    { label: "f", bg: "#1877F2", action: () => window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}`, "_blank") },
    { label: "in", bg: "#0A66C2", action: () => window.open(`https://www.linkedin.com/sharing/share-offsite/?url=${url}`, "_blank") },
    { label: "Copier", bg: S.border, action: () => {
      navigator.clipboard?.writeText(rawUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }},
  ];

  return (
    <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
      <span style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, fontWeight: 700 }}>Partager :</span>
      {buttons.map((b, i) => (
        <button key={i} onClick={b.action} style={{
          minWidth: 44, height: 44, borderRadius: 8, background: b.bg,
          display: "flex", alignItems: "center", justifyContent: "center",
          color: b.color || "#fff", fontSize: b.label.length > 3 ? 11 : 14, fontWeight: 900,
          fontFamily: S.font, transition: "transform 0.15s", padding: "0 10px",
          border: "none", cursor: "pointer",
        }}
        onMouseEnter={e => e.currentTarget.style.transform = "scale(1.1)"}
        onMouseLeave={e => e.currentTarget.style.transform = "scale(1)"}
        >{b.label === "Copier" && copied ? "Copie !" : b.label}</button>
      ))}
    </div>
  );
};

export default ShareButtons;
