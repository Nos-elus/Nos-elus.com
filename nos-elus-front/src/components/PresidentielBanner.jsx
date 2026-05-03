import { useState, useEffect, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import { useApi } from "../hooks/useApi";
import Avatar from "./Avatar";
import { withCacheBust } from "../utils/cacheBust";

const API = import.meta.env.VITE_API_URL ?? "/api";

const SLIDES = [
  { id: "2027", label: "Présidentielle 2027" },
  { id: "preferes", label: "Vos élus préférés" },
  { id: "palmares", label: "Le Plus Gros Palmarès" },
  { id: "fortunes", label: "Les Plus Fortunés" },
];

const RARITY_COLORS = ["#FFD700", "#C0C0C0", "#CD7F32", "#6c5ce7", "#0984e3"];

// ── Carte Pokémon ──
const PokeCard = ({ elu, rank, onClick }) => {
  const [tilt, setTilt] = useState({ x: 0, y: 0 });
  const [isHovered, setIsHovered] = useState(false);
  const rarityColor = RARITY_COLORS[Math.min(rank, 4)];
  const isTop3 = rank < 3;
  const stars = Math.max(1, 5 - rank);

  const handleMouseMove = (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width - 0.5) * 20;
    const y = ((e.clientY - rect.top) / rect.height - 0.5) * -20;
    setTilt({ x, y });
  };

  const slugify = (t) =>
    t.normalize("NFD").replace(/[\u0300-\u036f]/g, "")
      .toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");

  return (
    <div
      onClick={() => onClick(elu)}
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => { setIsHovered(false); setTilt({ x: 0, y: 0 }); }}
      onMouseMove={handleMouseMove}
      style={{
        width: "clamp(130px, 20vw, 160px)", cursor: "pointer",
        perspective: 600,
        animation: `popIn 0.5s ${0.1 + rank * 0.08}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
      }}
    >
      <div style={{
        borderRadius: 16, overflow: "hidden", position: "relative",
        background: `linear-gradient(145deg, ${S.card} 0%, #16213e 100%)`,
        border: `2px solid ${isHovered ? rarityColor : S.border}`,
        boxShadow: isHovered
          ? `0 0 30px ${rarityColor}44, 0 12px 40px rgba(0,0,0,0.5), inset 0 0 30px ${rarityColor}11`
          : `0 4px 16px rgba(0,0,0,0.3)`,
        transition: "all 0.3s ease",
        transform: isHovered
          ? `rotateX(${tilt.y}deg) rotateY(${tilt.x}deg) scale(1.08)`
          : "rotateX(0) rotateY(0) scale(1)",
        transformStyle: "preserve-3d",
      }}>
        {/* Holographic shimmer */}
        {isHovered && (
          <div style={{
            position: "absolute", inset: 0, zIndex: 3, pointerEvents: "none",
            background: `linear-gradient(${105 + tilt.x * 3}deg, transparent 30%, rgba(255,255,255,0.08) 45%, rgba(253,203,110,0.12) 50%, rgba(255,255,255,0.08) 55%, transparent 70%)`,
          }} />
        )}

        {/* Rang badge */}
        <div style={{
          position: "absolute", top: 8, left: 8, zIndex: 4,
          width: 24, height: 24, borderRadius: "50%",
          background: isTop3 ? rarityColor : S.card,
          border: `2px solid ${isTop3 ? rarityColor : S.border}`,
          display: "flex", alignItems: "center", justifyContent: "center",
          fontFamily: S.fontTitle, fontSize: 13, color: isTop3 ? "#000" : S.textDim,
          boxShadow: isTop3 ? `0 0 10px ${rarityColor}66` : "none",
        }}>#{rank + 1}</div>

        {/* Likes badge */}
        <div style={{
          position: "absolute", top: 8, right: 8, zIndex: 4,
          background: "rgba(0,184,148,0.15)", border: `1px solid ${S.green}44`,
          borderRadius: 99, padding: "2px 8px",
          fontFamily: S.font, fontSize: 12, fontWeight: 900, color: S.green,
        }}>+{elu.nb_likes || 0}</div>

        {/* Photo */}
        <div style={{
          width: "100%", aspectRatio: "1", overflow: "hidden",
          background: `linear-gradient(135deg, ${elu.couleur || S.purple}44, ${S.card})`,
        }}>
          {elu.photo_url ? (
            <img src={withCacheBust(elu.photo_url, elu.updated_at)} alt={elu.nom} loading="lazy"
              style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }}
              onError={(e) => { e.target.style.display = "none"; }}
            />
          ) : (
            <div style={{
              width: "100%", height: "100%", display: "flex",
              alignItems: "center", justifyContent: "center",
              fontFamily: S.fontTitle, fontSize: 36, color: "#fff",
              textShadow: `0 0 20px ${elu.couleur || S.purple}`,
            }}>
              {(elu.nom || "").split(" ").map(w => w[0]).filter(Boolean).join("").substring(0, 2).toUpperCase()}
            </div>
          )}
        </div>

        {/* Infos bas de carte */}
        <div style={{ padding: "10px 10px 12px", position: "relative" }}>
          {/* Ligne déco */}
          <div style={{
            position: "absolute", top: 0, left: 10, right: 10, height: 2,
            background: `linear-gradient(90deg, transparent, ${rarityColor}66, transparent)`,
          }} />

          <div style={{
            fontFamily: S.font, fontSize: 14, fontWeight: 900, color: S.textMain,
            whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
            marginTop: 4,
          }}>{elu.nom}</div>

          <div style={{
            fontFamily: S.font, fontSize: 11, color: S.textDim, marginTop: 2,
            whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
          }}>{elu.fonction || elu.parti}</div>

          {/* Étoiles rareté */}
          <div style={{ marginTop: 6, display: "flex", gap: 2 }}>
            {Array.from({ length: 5 }).map((_, j) => (
              <div key={j} style={{
                width: 8, height: 8, borderRadius: 2,
                background: j < stars ? rarityColor : S.border,
                opacity: j < stars ? 1 : 0.3,
                boxShadow: j < stars ? `0 0 4px ${rarityColor}44` : "none",
                transition: "all 0.3s",
              }} />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

// ── Composant principal ──
const PresidentielBanner = () => {
  const navigate = useNavigate();
  const [activeSlide, setActiveSlide] = useState(0);
  const [hovered2027, setHovered2027] = useState(null);
  const [hoverPaused, setHoverPaused] = useState(false);
  const [userPaused, setUserPaused] = useState(false);
  const paused = hoverPaused || userPaused;
  const [candidats2027, setCandidats2027] = useState([]);

  useEffect(() => {
    fetch(`${API}/vote2027.php?candidats=1`)
      .then(r => r.json())
      .then(data => {
        const list = data.candidats || data;
        if (Array.isArray(list)) setCandidats2027(list);
      })
      .catch(() => {});
  }, []);

  const { data: statsData } = useApi("stats.php");
  const topLikes = statsData?.top_likes || [];
  const topDislikes = statsData?.top_dislikes || [];
  const topMandats = statsData?.top_mandats || [];
  const topFortunes = statsData?.top_fortunes || [];

  // Auto-rotate slides
  useEffect(() => {
    if (paused) return;
    const timer = setInterval(() => {
      setActiveSlide(s => (s + 1) % SLIDES.length);
    }, 8000);
    return () => clearInterval(timer);
  }, [paused]);

  const handleEluClick = useCallback((elu) => {
    const slugify = (t) => t.normalize("NFD").replace(/[\u0300-\u036f]/g, "")
      .toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    navigate(`/elu/${elu.slug || slugify(elu.nom)}`, { state: { elu } });
  }, [navigate]);

  return (
    <div
      className="banner-slide"
      onMouseEnter={() => setHoverPaused(true)}
      onMouseLeave={() => setHoverPaused(false)}
      style={{
        textAlign: "center", marginBottom: 48, padding: "32px 16px 24px",
        background: "radial-gradient(ellipse at 50% 0%, rgba(253,203,110,0.10) 0%, rgba(108,92,231,0.05) 40%, transparent 70%)",
        borderRadius: 24, border: `1px solid ${S.gold}22`,
        position: "relative", overflow: "hidden", minHeight: 280,
      }}
    >
      {/* Bouton pause/play en haut à droite */}
      <button
        onClick={() => setUserPaused(p => !p)}
        aria-label={userPaused ? "Reprendre le défilement" : "Mettre en pause"}
        title={userPaused ? "Reprendre" : "Pause"}
        style={{
          position: "absolute", top: 12, right: 12, zIndex: 5,
          width: 32, height: 32, borderRadius: "50%",
          background: userPaused ? S.gold : "rgba(255,255,255,0.08)",
          border: `1px solid ${userPaused ? S.gold : "rgba(255,255,255,0.15)"}`,
          color: userPaused ? S.bg : S.gold,
          cursor: "pointer", fontSize: 12, lineHeight: 1, fontWeight: 900,
          display: "flex", alignItems: "center", justifyContent: "center",
          transition: "all 0.2s",
        }}
      >
        {userPaused ? "▶" : "❚❚"}
      </button>

      {/* Navigation dots + labels */}
      <div className="banner-slides-nav" style={{
        display: "flex", justifyContent: "center", gap: 6, marginBottom: 20,
      }}>
        {SLIDES.map((slide, i) => (
          <button key={slide.id} onClick={() => setActiveSlide(i)} style={{
            background: activeSlide === i ? S.gold : "rgba(255,255,255,0.06)",
            border: `1px solid ${activeSlide === i ? S.gold : S.border}`,
            borderRadius: 99, padding: "5px 14px", cursor: "pointer",
            fontFamily: S.font, fontSize: 13, fontWeight: 800,
            color: activeSlide === i ? S.bg : S.textDim,
            transition: "all 0.3s",
            boxShadow: activeSlide === i ? `0 0 12px ${S.gold}33` : "none",
          }}>{slide.label}</button>
        ))}
      </div>

      {/* ── SLIDE : Présidentielle 2027 ── */}
      {activeSlide === 0 && (
        <div style={{ animation: "slideUp 0.4s ease" }}>
          <div style={{
            fontFamily: S.fontTitle, fontSize: "clamp(48px,9vw,80px)", color: S.gold,
            lineHeight: 0.9, letterSpacing: 4,
            textShadow: "0 0 40px rgba(253,203,110,0.4), 0 0 80px rgba(253,203,110,0.15)",
          }}>
            <span onClick={() => navigate("/2027")} style={{ cursor: "pointer", display: "inline-block", animation: "pulse2027 2.5s ease-in-out infinite" }}>2027</span>
          </div>
          <div style={{
            fontFamily: S.font, fontSize: 14, color: S.textDim, marginTop: 8,
            fontStyle: "italic",
          }}>Qui veut le fauteuil ?</div>
          <div onClick={() => navigate("/2027")} style={{
            display: "inline-block", marginTop: 10, marginBottom: 16, padding: "8px 24px",
            background: `linear-gradient(135deg, ${S.gold}, #e8941c)`,
            borderRadius: 99, fontFamily: S.font, fontSize: 14, fontWeight: 900,
            color: "#0a0e1a", cursor: "pointer", transition: "all 0.2s",
            boxShadow: `0 2px 12px ${S.gold}44`,
          }}
            onMouseEnter={e => { e.currentTarget.style.transform = "scale(1.05)"; }}
            onMouseLeave={e => { e.currentTarget.style.transform = "scale(1)"; }}
          >🗳️ Votez pour vos candidats</div>

          <div className="banner-candidats" style={{
            display: "flex", justifyContent: "center", alignItems: "flex-start",
            flexWrap: "wrap", gap: "clamp(6px,1.2vw,12px)", maxWidth: 640, margin: "0 auto",
          }}>
            {candidats2027.map((c, i) => {
              const isHov = hovered2027 === i;

              return (
                <div key={c.id}
                  onClick={() => c.slug ? navigate(`/elu/${c.slug}`) : navigate("/2027")}
                  onMouseEnter={() => setHovered2027(i)}
                  onMouseLeave={() => setHovered2027(null)}
                  style={{
                    cursor: "pointer", textAlign: "center",
                    transition: "all 0.3s cubic-bezier(0.34,1.56,0.64,1)",
                    transform: isHov ? "translateY(-12px) scale(1.15)" : "translateY(0) scale(1)",
                    animation: `popIn 0.5s ${0.15 + i * 0.07}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
                    zIndex: isHov ? 10 : 1,
                    filter: hovered2027 !== null && !isHov ? "brightness(0.6)" : "brightness(1)",
                  }}
                >
                  <div style={{
                    width: 66, height: 66,
                    borderRadius: "50%", margin: "0 auto", overflow: "hidden",
                    border: `3px solid ${isHov ? S.gold : c.couleur}`,
                    boxShadow: isHov
                      ? `0 0 30px ${S.gold}66, 0 8px 24px rgba(0,0,0,0.4)`
                      : `0 0 15px ${c.couleur}44`,
                    transition: "all 0.3s",
                    background: `linear-gradient(135deg, ${c.couleur}, ${c.couleur}aa)`,
                    display: "flex", alignItems: "center", justifyContent: "center",
                    position: "relative",
                  }}>
                    <span style={{
                      fontFamily: S.fontTitle, fontSize: 66 * 0.32,
                      color: "#fff", position: "absolute",
                    }}>{c.nom.substring(0, 2).toUpperCase()}</span>
                    {c.photo && (
                      <img src={c.photo} alt={c.nom} loading="lazy"
                        referrerPolicy="no-referrer"
                        style={{ width: "100%", height: "100%", objectFit: "cover", display: "block", position: "relative", zIndex: 1 }}
                        onError={(e) => { e.target.style.display = "none"; }}
                      />
                    )}
                  </div>
                  <div style={{ marginTop: 6, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "flex-start" }}>
                    <div style={{
                      fontFamily: S.font, fontSize: 12, fontWeight: 900,
                      color: isHov ? S.gold : S.textMain, transition: "all 0.2s",
                      lineHeight: 1.2, textAlign: "center",
                    }}>{c.nom}</div>
                    <div style={{
                      fontFamily: S.font, fontSize: 10, fontWeight: 700, color: c.couleur, marginTop: 1,
                      lineHeight: 1.2, textAlign: "center",
                    }}>{c.parti}</div>
                  </div>
                  <div style={{
                    display: "inline-block",
                    background: c.statut === "declare" ? "rgba(0,184,148,0.18)" : "rgba(180,180,180,0.12)",
                    border: `1px solid ${c.statut === "declare" ? S.green : "#888"}44`,
                    borderRadius: 99, padding: "1px 7px",
                    fontFamily: S.font, fontSize: 9, fontWeight: 800,
                    color: c.statut === "declare" ? S.green : "#aaa",
                    letterSpacing: 0.3,
                  }}>{c.statut === "declare" ? "Déclaré" : "Probable"}</div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* ── SLIDE : Élus préférés (Double Podium) ── */}
      {activeSlide === 1 && (
        <div onClick={() => navigate("/top-elus")} style={{ animation: "slideUp 0.4s ease", cursor: "pointer" }}>
          <div style={{
            fontFamily: S.fontTitle, fontSize: "clamp(18px,4vw,26px)", color: S.gold,
            textShadow: `0 0 20px ${S.gold}44`,
            marginBottom: 4,
          }}>Podium citoyens</div>
          <div style={{
            fontFamily: S.font, fontSize: 14, color: S.textDim, marginBottom: 20,
            fontStyle: "italic",
          }}>Les plus aimés vs les moins aimés</div>

          {topLikes.length === 0 && topDislikes.length === 0 ? (
            <div style={{ color: S.textDim, fontSize: 13, padding: 40 }}>
              Pas encore de votes — soyez le premier !
            </div>
          ) : (
            <div style={{
              display: "flex", alignItems: "center", justifyContent: "center",
              maxWidth: 700, margin: "0 auto",
            }}>
              {/* Gauche : top 3 likés */}
              <div style={{ flex: 1, display: "flex", alignItems: "flex-end", justifyContent: "flex-end", gap: "clamp(8px,2vw,16px)" }}>
                {topLikes.slice(0, 3).map((elu, i) => {
                  const sizes = [80, 64, 52];
                  return (
                    <div key={elu.id} style={{
                      textAlign: "center", display: "flex", flexDirection: "column", alignItems: "center",
                      animation: `popIn 0.5s ${0.1 + i * 0.08}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
                      transition: "transform 0.2s",
                    }}
                      onMouseEnter={e => e.currentTarget.style.transform = "translateY(-6px) scale(1.05)"}
                      onMouseLeave={e => e.currentTarget.style.transform = "translateY(0) scale(1)"}
                    >
                      <div style={{
                        borderRadius: "50%", lineHeight: 0,
                        boxShadow: `0 0 ${i === 0 ? 20 : 12}px ${S.green}${i === 0 ? "88" : "55"}`,
                        border: `2px solid ${S.green}${i === 0 ? "cc" : "77"}`,
                      }}>
                        <Avatar elu={elu} size={sizes[i]} />
                      </div>
                      <div style={{
                        fontFamily: S.font, fontSize: 11, fontWeight: 900, color: S.textMain,
                        marginTop: 6, maxWidth: sizes[i] + 20,
                        whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                      }}>{elu.nom}</div>
                      <div style={{
                        background: "rgba(0,184,148,0.15)", border: `1px solid ${S.green}55`,
                        borderRadius: 99, padding: "2px 9px", marginTop: 4,
                        fontFamily: S.font, fontSize: 11, fontWeight: 900, color: S.green,
                      }}>+{elu.nb_likes}</div>
                    </div>
                  );
                })}
              </div>

              {/* Séparateur VS */}
              <div style={{
                display: "flex", flexDirection: "column", alignItems: "center", gap: 4,
                padding: "0 12px", flexShrink: 0,
              }}>
                <div style={{ width: 1, height: 40, background: `linear-gradient(to bottom, transparent, ${S.border}, transparent)` }} />
                <div style={{ fontFamily: S.fontTitle, fontSize: 12, color: S.textDim, letterSpacing: 1 }}>VS</div>
                <div style={{ width: 1, height: 40, background: `linear-gradient(to bottom, transparent, ${S.border}, transparent)` }} />
              </div>

              {/* Droite : top 3 dislikés */}
              <div style={{ flex: 1, display: "flex", alignItems: "flex-end", justifyContent: "flex-start", gap: "clamp(8px,2vw,16px)" }}>
                {[...(topDislikes.slice(0, 3))].reverse().map((elu, i) => {
                  const rank = 2 - i;
                  const sizes = [80, 64, 52];
                  return (
                    <div key={elu.id} style={{
                      textAlign: "center", display: "flex", flexDirection: "column", alignItems: "center",
                      animation: `popIn 0.5s ${0.4 + i * 0.08}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
                      transition: "transform 0.2s",
                    }}
                      onMouseEnter={e => e.currentTarget.style.transform = "translateY(-6px) scale(1.05)"}
                      onMouseLeave={e => e.currentTarget.style.transform = "translateY(0) scale(1)"}
                    >
                      <div style={{
                        borderRadius: "50%", lineHeight: 0,
                        boxShadow: `0 0 ${rank === 0 ? 20 : 12}px ${S.red}${rank === 0 ? "88" : "55"}`,
                        border: `2px solid ${S.red}${rank === 0 ? "cc" : "77"}`,
                      }}>
                        <Avatar elu={elu} size={sizes[rank]} />
                      </div>
                      <div style={{
                        fontFamily: S.font, fontSize: 11, fontWeight: 900, color: S.textMain,
                        marginTop: 6, maxWidth: sizes[rank] + 20,
                        whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                      }}>{elu.nom}</div>
                      <div style={{
                        background: "rgba(255,107,107,0.15)", border: `1px solid ${S.red}55`,
                        borderRadius: 99, padding: "2px 9px", marginTop: 4,
                        fontFamily: S.font, fontSize: 11, fontWeight: 900, color: S.red,
                      }}>-{elu.nb_dislikes}</div>
                    </div>
                );
              })}
              </div>
            </div>
          )}

          <div style={{
            fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.gold,
            marginTop: 16, opacity: 0.8,
          }}>Voir le classement complet →</div>
        </div>
      )}

      {/* ── SLIDE : Le Plus Gros Palmarès ── */}
      {activeSlide === 2 && (
        <div style={{ animation: "slideUp 0.4s ease" }}>
          <div style={{
            fontFamily: S.fontTitle, fontSize: "clamp(18px,4vw,26px)", color: S.purple,
            textShadow: "0 0 20px rgba(108,92,231,0.3)",
            marginBottom: 4,
          }}>Le Plus Gros Palmarès</div>
          <div style={{
            fontFamily: S.font, fontSize: "clamp(14px,2.5vw,18px)", color: S.gold, marginBottom: 24,
            fontStyle: "italic", fontWeight: 700, opacity: 0.85,
            textShadow: `0 0 12px ${S.gold}22`,
          }}>« Ils cumulent les mandats comme d'autres les points fidélité »</div>

          {topMandats.length === 0 ? (
            <div style={{ color: S.textDim, fontSize: 13, padding: 40 }}>Chargement...</div>
          ) : (
            <div style={{
              display: "flex", justifyContent: "center", alignItems: "flex-end",
              gap: "clamp(12px,3vw,24px)", maxWidth: 700, margin: "0 auto",
            }}>
              {topMandats.slice(0, 5).map((elu, i) => {
                const barH = [120, 100, 80, 65, 50][i] || 50;
                const medals = ["🥇", "🥈", "🥉", "4e", "5e"];
                const colors = [S.gold, "#C0C0C0", "#CD7F32", S.purple, S.blue];
                return (
                  <div key={elu.id}
                    onClick={() => handleEluClick(elu)}
                    style={{
                      cursor: "pointer", textAlign: "center", display: "flex",
                      flexDirection: "column", alignItems: "center",
                      animation: `popIn 0.5s ${0.1 + i * 0.08}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
                      transition: "transform 0.2s",
                    }}
                    onMouseEnter={e => e.currentTarget.style.transform = "translateY(-8px) scale(1.05)"}
                    onMouseLeave={e => e.currentTarget.style.transform = "translateY(0) scale(1)"}
                  >
                    <div style={{ fontSize: i < 3 ? 24 : 14, marginBottom: 6 }}>{medals[i]}</div>
                    <Avatar elu={elu} size={65} />
                    <div style={{
                      fontFamily: S.font, fontSize: 13, fontWeight: 900,
                      color: S.textMain, marginTop: 6, maxWidth: 80,
                      whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                    }}>{elu.nom}</div>
                    {/* Barre mandats */}
                    <div style={{
                      width: 36, height: barH, marginTop: 6, borderRadius: "6px 6px 0 0",
                      background: `linear-gradient(180deg, ${colors[i]}44, ${colors[i]}11)`,
                      border: `1px solid ${colors[i]}33`, borderBottom: "none",
                      display: "flex", alignItems: "flex-start", justifyContent: "center",
                      paddingTop: 8,
                    }}>
                      <span style={{
                        fontFamily: S.font, fontSize: 12, fontWeight: 900, color: colors[i],
                      }}>{elu.nb_mandats}</span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          <div style={{ marginTop: 16 }}
            onClick={() => navigate("/palmares")}
          >
            <button style={{
              background: `linear-gradient(135deg, ${S.purple}, ${S.purple}cc)`,
              border: "none", borderRadius: 99, padding: "8px 20px",
              fontFamily: S.font, fontSize: 12, fontWeight: 900, color: "#fff",
              cursor: "pointer", animation: "pulseBtn 2.5s ease-in-out infinite",
              boxShadow: `0 0 16px ${S.purple}44`,
            }}>Voir tous les palmarès →</button>
          </div>
        </div>
      )}

      {/* ── SLIDE : Les Plus Fortunés ── */}
      {activeSlide === 3 && (
        <div style={{ animation: "slideUp 0.4s ease" }}>
          <div style={{
            fontFamily: S.fontTitle, fontSize: "clamp(18px,4vw,26px)", color: S.gold,
            textShadow: "0 0 20px rgba(253,203,110,0.3)",
            marginBottom: 4,
          }}>Les Plus Fortunés</div>
          <div style={{
            fontFamily: S.font, fontSize: 14, color: S.textDim, marginBottom: 24,
            fontStyle: "italic",
          }}>Patrimoine estimé d'après les médias</div>

          {topFortunes.length === 0 ? (
            <div style={{ color: S.textDim, fontSize: 13, padding: 40 }}>Chargement...</div>
          ) : (
            <div style={{
              display: "flex", justifyContent: "center", alignItems: "flex-end",
              gap: "clamp(12px,3vw,24px)", maxWidth: 750, margin: "0 auto",
            }}>
              {topFortunes.map((elu, i) => {
                const barH = [130, 105, 85, 68, 55][i] || 55;
                const medals = ["🥇", "🥈", "🥉", "4e", "5e"];
                return (
                  <div key={elu.id}
                    onClick={() => handleEluClick(elu)}
                    style={{
                      cursor: "pointer", textAlign: "center", display: "flex",
                      flexDirection: "column", alignItems: "center",
                      animation: `popIn 0.5s ${0.1 + i * 0.08}s cubic-bezier(0.68,-0.55,0.265,1.55) both`,
                      transition: "transform 0.2s",
                    }}
                    onMouseEnter={e => e.currentTarget.style.transform = "translateY(-8px) scale(1.05)"}
                    onMouseLeave={e => e.currentTarget.style.transform = "translateY(0) scale(1)"}
                  >
                    <div style={{ fontSize: i < 3 ? 24 : 14, marginBottom: 6 }}>{medals[i]}</div>
                    <Avatar elu={elu} size={68} />
                    <div style={{
                      fontFamily: S.font, fontSize: 13, fontWeight: 900,
                      color: S.textMain, marginTop: 6, maxWidth: 90,
                      whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                    }}>{elu.nom}</div>
                    {elu.patrimoine_info && (
                      <div style={{
                        fontFamily: S.font, fontSize: 11, color: S.gold,
                        marginTop: 4, maxWidth: 100,
                        whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                      }}>{elu.patrimoine_info}</div>
                    )}
                    <div style={{
                      width: 44, height: barH, marginTop: 6, borderRadius: "8px 8px 0 0",
                      background: `linear-gradient(180deg, ${S.gold}55, ${S.gold}11)`,
                      border: `1px solid ${S.gold}33`, borderBottom: "none",
                    }} />
                  </div>
                );
              })}
            </div>
          )}

          <div style={{ marginTop: 16 }}
            onClick={() => navigate("/palmares")}
          >
            <button style={{
              background: `linear-gradient(135deg, ${S.gold}, #e17a2d)`,
              border: "none", borderRadius: 99, padding: "8px 20px",
              fontFamily: S.font, fontSize: 12, fontWeight: 900, color: S.bg,
              cursor: "pointer", animation: "pulseBtn 2.5s ease-in-out infinite",
              boxShadow: `0 0 16px ${S.gold}44`,
            }}>Voir tous les palmarès →</button>
          </div>

          <div style={{
            fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 10,
            fontStyle: "italic", opacity: 0.7,
          }}>Sources : Le Point, Capital, BFM — estimations non officielles</div>
        </div>
      )}

      {/* Progress bar auto-rotate */}
      {!paused && (
        <div style={{
          position: "absolute", bottom: 0, left: 0, height: 2,
          background: `linear-gradient(90deg, ${S.gold}, ${S.green})`,
          animation: "progressBar 8s linear infinite",
          borderRadius: "0 2px 2px 0", pointerEvents: "none",
        }} />
      )}

      <style>{`
        @keyframes progressBar {
          from { width: 0; }
          to { width: 100%; }
        }
      `}</style>
    </div>
  );
};

export default PresidentielBanner;
