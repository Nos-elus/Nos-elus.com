import { useState } from "react";
import { S } from "../utils/constants";

// Génère une URL DiceBear (avatar unique et déterministe par nom)
const dicebearUrl = (name, size) =>
  `https://api.dicebear.com/9.x/initials/svg?seed=${encodeURIComponent(name)}&size=${size}&backgroundColor=1a1a2e,16213e,0a3d62,1e3a5f,2d3436&textColor=fdcb6e,ffffff,dfe6e9&fontFamily=Arial&fontWeight=700`;

const Avatar = ({ elu, size = 64, showBorder = true }) => {
  const [imgError, setImgError] = useState(false);
  const [dicebearError, setDicebearError] = useState(false);

  if (!elu) return (
    <div style={{ width: size, height: size, borderRadius: "50%", flexShrink: 0, background: S.border, display: "flex", alignItems: "center", justifyContent: "center" }}>
      <span style={{ fontFamily: S.fontTitle, fontSize: size * 0.35, color: S.textDim }}>?</span>
    </div>
  );

  const nom = elu.nom || "";
  const initials = nom.split(" ").map(w => w[0]).filter(Boolean).join("").substring(0, 2).toUpperCase();
  const color = elu.couleur || S.purple;

  const containerStyle = {
    width: size, height: size, borderRadius: "50%",
    flexShrink: 0, position: "relative",
    border: showBorder ? `3px solid ${color}` : "none",
    boxShadow: `0 0 12px ${color}33`,
  };

  // Niveau 1 : photo réelle (locale ou BDD)
  if (elu.photo_url && !imgError) {
    return (
      <div style={containerStyle}>
        <img
          src={elu.photo_url}
          alt={nom}
          onError={() => setImgError(true)}
          loading="lazy"
          style={{
            width: "100%", height: "100%", borderRadius: "50%",
            objectFit: "cover", display: "block",
          }}
        />
      </div>
    );
  }

  // Niveau 2 : avatar DiceBear (unique par nom, gratuit, pas de clé API)
  if (!dicebearError) {
    return (
      <div style={containerStyle}>
        <img
          src={dicebearUrl(nom, size * 2)}
          alt={nom}
          onError={() => setDicebearError(true)}
          loading="lazy"
          style={{
            width: "100%", height: "100%", borderRadius: "50%",
            objectFit: "cover", display: "block",
          }}
        />
      </div>
    );
  }

  // Niveau 3 : initiales CSS (fallback ultime, offline)
  return (
    <div style={{
      ...containerStyle,
      background: `linear-gradient(135deg, ${color}, ${color}aa)`,
      display: "flex", alignItems: "center", justifyContent: "center",
    }}>
      <span style={{
        fontFamily: S.fontTitle, fontSize: size * 0.35,
        color: "#fff", lineHeight: 1, letterSpacing: 1,
      }}>{initials}</span>
    </div>
  );
};

export default Avatar;
