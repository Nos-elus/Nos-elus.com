import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import Avatar from "./Avatar";

const HIERARCHY = [
  { key: "president", label: "Presidence", match: t => t.includes("président de la rép"), color: S.gold },
  { key: "premier", label: "Premier Ministre", match: t => t.includes("premier ministre"), color: S.gold },
  { key: "ministre", label: "Ministres", match: t => t.includes("ministre") && !t.includes("premier") && !t.includes("ancien"), color: "#e17a2d" },
  { key: "europeen", label: "Deputes europeens", match: t => t.includes("européen") || t.includes("europeenne"), color: S.blue },
  { key: "senateur", label: "Senateurs", match: t => t.includes("sénat") || t.includes("senat"), color: S.purple },
  { key: "depute", label: "Deputes", match: t => t.includes("déput") || t.includes("deput"), color: S.blue },
  { key: "president_region", label: "Presidents de region", match: t => t.includes("président") && t.includes("région"), color: S.green },
  { key: "president_dept", label: "Presidents de departement", match: t => t.includes("président") && t.includes("département"), color: S.green },
  { key: "maire", label: "Maires", match: t => t.includes("maire") && !t.includes("adjoint"), color: "#e84c3d" },
  { key: "adjoint", label: "Adjoints", match: t => t.includes("adjoint"), color: S.textMuted },
  { key: "conseiller", label: "Conseillers", match: t => t.includes("conseiller") || t.includes("conseillère"), color: S.textMuted },
  { key: "autre", label: "Autres", match: () => true, color: S.textDim },
];

const API = import.meta.env.VITE_API_URL ?? "/api";

export default function PartiMembers({ parti, currentEluId, onClose }) {
  const navigate = useNavigate();
  const [members, setMembers] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!parti) return;
    setLoading(true);
    fetch(`${API}/elus.php?parti=${encodeURIComponent(parti)}&limit=100&sort=importance`, { credentials: "same-origin" })
      .then(r => r.json())
      .then(data => {
        const list = (data.data || data || [])
          .filter(e => e.id !== currentEluId);
        setMembers(list);
      })
      .catch(() => setMembers([]))
      .finally(() => setLoading(false));
  }, [parti, currentEluId]);

  const slugify = (t) =>
    t.normalize("NFD").replace(/[\u0300-\u036f]/g, "")
      .toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");

  // Organiser par hiérarchie
  const sections = [];
  const placed = new Set();

  HIERARCHY.forEach(h => {
    const sectionMembers = members.filter(m => {
      if (placed.has(m.id)) return false;
      const f = (m.fonction || "").toLowerCase();
      return h.match(f);
    });
    sectionMembers.forEach(m => placed.add(m.id));
    if (sectionMembers.length > 0) {
      sections.push({ ...h, members: sectionMembers });
    }
  });

  return (
    <div style={{
      position: "fixed", inset: 0, zIndex: 1000,
      display: "flex", alignItems: "center", justifyContent: "center",
    }} onClick={onClose}>
      <div style={{ position: "absolute", inset: 0, background: "rgba(0,0,0,0.6)" }} />
      <div onClick={e => e.stopPropagation()} style={{
        position: "relative", width: "90%", maxWidth: 520, maxHeight: "85vh",
        background: S.card, border: `1px solid ${S.gold}33`, borderRadius: 18,
        padding: "24px 20px", overflowY: "auto",
        boxShadow: "0 20px 60px rgba(0,0,0,0.5)",
        animation: "popIn 0.3s cubic-bezier(0.68,-0.55,0.265,1.55)",
      }}>
        {/* Header */}
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 20 }}>
          <div>
            <div style={{ fontFamily: S.fontTitle, fontSize: 20, color: S.gold }}>{parti}</div>
            <div style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, marginTop: 2 }}>
              {members.length} membre{members.length > 1 ? "s" : ""} en poste
            </div>
          </div>
          <button onClick={onClose} style={{
            background: "none", border: "none", color: S.textDim,
            fontSize: 22, cursor: "pointer", padding: 4, lineHeight: 1,
          }}>&times;</button>
        </div>

        {loading ? (
          <div style={{ textAlign: "center", padding: 32 }}>
            <div style={{
              display: "inline-block", width: 24, height: 24, borderRadius: "50%",
              border: `2px solid ${S.border}`, borderTopColor: S.gold,
              animation: "searchSpin 0.6s linear infinite",
            }} />
          </div>
        ) : members.length === 0 ? (
          <div style={{ textAlign: "center", padding: 32, color: S.textDim, fontSize: 13 }}>
            Aucun autre membre trouve pour ce parti
          </div>
        ) : (
          <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
            {sections.map((section) => (
              <div key={section.key}>
                {/* Section header */}
                <div style={{
                  display: "flex", alignItems: "center", gap: 8, marginBottom: 8,
                }}>
                  <div style={{ width: 3, height: 14, borderRadius: 2, background: section.color }} />
                  <div style={{ fontFamily: S.font, fontSize: 11, fontWeight: 800, color: section.color, textTransform: "uppercase", letterSpacing: 1 }}>
                    {section.label}
                  </div>
                  <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim }}>
                    ({section.members.length})
                  </div>
                </div>

                {/* Members */}
                <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                  {section.members.map((m) => (
                    <div key={m.id}
                      onClick={() => { onClose(); navigate(`/elu/${m.slug || slugify(m.nom)}`); }}
                      style={{
                        display: "flex", alignItems: "center", gap: 10,
                        padding: "8px 10px", borderRadius: 8,
                        background: "rgba(255,255,255,0.02)", border: `1px solid ${S.border}`,
                        cursor: "pointer", transition: "all 0.15s",
                      }}
                      onMouseEnter={e => { e.currentTarget.style.borderColor = section.color + "66"; e.currentTarget.style.background = section.color + "08"; }}
                      onMouseLeave={e => { e.currentTarget.style.borderColor = S.border; e.currentTarget.style.background = "rgba(255,255,255,0.02)"; }}
                    >
                      <Avatar elu={m} size={56} />
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.textMain, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                          {m.prenom ? `${m.prenom} ${m.nom}` : m.nom}
                        </div>
                        <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                          {m.fonction}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
