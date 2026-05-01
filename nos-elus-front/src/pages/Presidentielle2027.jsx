import { useState, useEffect, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { S } from "../utils/constants";
import Avatar from "../components/Avatar";

const API = import.meta.env.VITE_API_URL || "/api";

const Presidentielle2027 = () => {
  const navigate = useNavigate();
  const [candidats, setCandidats] = useState([]);
  const [ranking, setRanking] = useState([]);
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(true);
  const [voted, setVoted] = useState(false);
  const [isChanging, setIsChanging] = useState(false);
  const [sending, setSending] = useState(false);
  const [totalVotes, setTotalVotes] = useState(0);
  const [showVutInfo, setShowVutInfo] = useState(false);
  const [dragIndex, setDragIndex] = useState(null);
  const [dragOverIndex, setDragOverIndex] = useState(null);

  const reorderRanking = (fromIdx, toIdx) => {
    if (fromIdx === toIdx || fromIdx == null || toIdx == null) return;
    setRanking(prev => {
      const next = [...prev];
      const [moved] = next.splice(fromIdx, 1);
      next.splice(toIdx, 0, moved);
      return next;
    });
  };

  const fetchResults = useCallback(async () => {
    try {
      const r = await fetch(`${API}/vote2027.php`, { credentials: "same-origin" });
      if (!r.ok) throw new Error("HTTP " + r.status);
      const d = await r.json();
      if (d.resultats) {
        const sorted = [...d.resultats].sort((a, b) => b.score - a.score);
        setResults(sorted);
        setTotalVotes(d.total_votes || 0);
      }
    } catch (e) { console.warn("[2027] fetch fail:", e.message); }
    setLoading(false);
  }, []);

  useEffect(() => {
    fetch(`${API}/vote2027.php?candidats=1`, { credentials: "same-origin" })
      .then(r => r.json())
      .then(d => { if (d.candidats) setCandidats(d.candidats); })
      .catch(() => {});
    fetchResults();
    // L'API est la source de vérité
    fetch(`${API}/vote2027.php?check=1`, { credentials: "same-origin" })
      .then(r => {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(d => {
        if (d.voted) {
          setVoted(true);
          if (d.classement && Array.isArray(d.classement)) {
            setRanking(d.classement);
          }
        } else {
          setVoted(false);
          localStorage.removeItem("noselus_voted2027");
        }
      })
      .catch(() => {
        // Fallback localStorage
        if (localStorage.getItem("noselus_voted2027") === "1") {
          setVoted(true);
        }
      });
  }, [fetchResults]);

  const toggleCandidat = (id) => {
    if (voted) return;
    setRanking(prev => {
      if (prev.includes(id)) return prev.filter(x => x !== id);
      if (prev.length >= candidats.length) return prev;
      return [...prev, id];
    });
  };

  const handleVote = async () => {
    if (ranking.length !== candidats.length || sending) return;
    setSending(true);
    try {
      const res = await fetch(`${API}/vote2027.php`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ classement: ranking, change: isChanging }),
      });
      if (res.ok) {
        setVoted(true);
        setIsChanging(false);
        localStorage.setItem("noselus_voted2027", "1");
      }
      fetchResults();
    } catch (e) { /* silent */ }
    setSending(false);
  };

  const handleChangeAvis = () => {
    setVoted(false);
    setIsChanging(true);
    // Purger les anciens IDs qui ne sont plus dans la liste courante (ex: 'wauquiez')
    setRanking(prev => prev.filter(id => candidats.some(c => c.id === id)));
  };

  const getRank = (id) => {
    const i = ranking.indexOf(id);
    return i === -1 ? null : i + 1;
  };

  const top3 = results.slice(0, 3);
  const maxScore = results.length > 0 ? results[0].score : 1;
  const ready = candidats.length > 0 && ranking.length === candidats.length;

  const podiumOrder = [1, 0, 2];
  const podiumHeights = [160, 200, 130];
  const podiumSizes = [72, 90, 64];
  const podiumLabels = ["P2", "P1", "P3"];

  return (
    <div style={{ paddingTop: 24, paddingBottom: 80, maxWidth: 700, margin: "0 auto" }}>

      <div style={{ textAlign: "center", marginBottom: 48, position: "relative" }}>
        <div style={{
          position: "absolute", top: "50%", left: "50%", transform: "translate(-50%,-50%)",
          width: "clamp(200px,60vw,400px)", height: "clamp(200px,60vw,400px)", borderRadius: "50%",
          background: `radial-gradient(circle, ${S.gold}08 0%, transparent 70%)`,
          pointerEvents: "none",
        }} />
        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(14px,2.5vw,18px)", color: S.textDim,
          letterSpacing: 6, textTransform: "uppercase", marginBottom: 4,
        }}>Grand Prix</div>
        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(52px,10vw,90px)", color: S.gold,
          lineHeight: 0.95, letterSpacing: 4,
          textShadow: `0 0 40px ${S.gold}66, 0 0 80px ${S.gold}25`,
          background: `linear-gradient(180deg, ${S.gold}, #e17a2d)`,
          WebkitBackgroundClip: "text", WebkitTextFillColor: "transparent",
        }}>2027</div>
        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(16px,3vw,24px)", color: S.textMain,
          marginTop: 8, letterSpacing: 3,
        }}>Presidentielle</div>
        <div style={{
          display: "flex", alignItems: "center", justifyContent: "center", gap: 12, marginTop: 12,
        }}>
          <div style={{ height: 1, width: 40, background: `linear-gradient(90deg, transparent, ${S.gold}66)` }} />
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <div style={{
              fontFamily: S.font, fontSize: 12, color: S.textDim, fontStyle: "italic", letterSpacing: 1,
            }}>Vote Unique Transferable</div>
            <div onClick={() => setShowVutInfo(!showVutInfo)} style={{
              width: 22, height: 22, borderRadius: "50%", cursor: "pointer",
              background: showVutInfo ? S.gold : `${S.gold}22`,
              border: `2px solid ${S.gold}`,
              display: "flex", alignItems: "center", justifyContent: "center",
              fontFamily: S.font, fontSize: 12, fontWeight: 900,
              color: showVutInfo ? S.bg : S.gold,
              transition: "all 0.2s",
              boxShadow: `0 0 8px ${S.gold}33`,
            }}>?</div>
          </div>
          <div style={{ height: 1, width: 40, background: `linear-gradient(90deg, ${S.gold}66, transparent)` }} />
        </div>

        {showVutInfo && (
          <div style={{
            marginTop: 16, padding: "20px 20px 16px", borderRadius: 16,
            background: `linear-gradient(135deg, ${S.card}ee, #16213e)`,
            border: `1px solid ${S.gold}33`,
            animation: "slideUp 0.3s ease",
            textAlign: "left", maxWidth: 600, margin: "16px auto 0",
          }}>
            <div style={{ fontFamily: S.fontTitle, fontSize: 15, color: S.gold, marginBottom: 12 }}>
              Pourquoi le Vote Unique Transférable ?
            </div>
            <div style={{ fontFamily: S.font, fontSize: 13, color: "#ddd", lineHeight: 1.8 }}>
              <p style={{ margin: "0 0 10px" }}>
                Le scrutin uninominal à deux tours utilisé en France oblige souvent les électeurs à voter
                "contre" plutôt que "pour". Le <strong style={{ color: S.gold }}>Vote Unique Transférable (VUT)</strong> résout
                ce problème : vous classez tous les candidats par ordre de préférence.
              </p>
              <p style={{ margin: "0 0 10px" }}>
                <strong style={{ color: S.green }}>Comment ça marche ici :</strong> votre 1er choix reçoit N points,
                le 2e N-1 points, etc. (N = nombre total de candidats). Le classement final reflète la <strong>préférence réelle</strong> des
                participants, pas un choix binaire.
              </p>
              <p style={{ margin: "0 0 10px" }}>
                <strong style={{ color: S.purple }}>Pourquoi c'est mieux :</strong> plus de "vote utile" ni de
                dilemme stratégique. Chaque voix compte intégralement. Les candidats de consensus sont
                naturellement valorisés, pas seulement les plus polarisants.
              </p>
              <p style={{ margin: "0 0 10px", fontStyle: "italic", color: "#bbb" }}>
                Ce n'est pas un vote officiel — c'est une estimation citoyenne de la volonté populaire,
                basée sur un mode de scrutin que de nombreux spécialistes de la démocratie considèrent
                comme plus représentatif.
              </p>
            </div>
            <div style={{
              marginTop: 12, padding: "12px 16px", borderRadius: 10,
              background: "rgba(255,255,255,0.05)", border: `1px solid ${S.gold}22`,
            }}>
              <div style={{ fontFamily: S.font, fontSize: 12, color: "#ccc", fontStyle: "italic", lineHeight: 1.6 }}>
                "Le vote unique transférable est le système qui donne le résultat le plus fidèle
                à la volonté des électeurs, car il élimine le vote stratégique et permet à chacun
                d'exprimer ses préférences réelles sans crainte de gaspiller sa voix."
              </div>
              <div style={{ fontFamily: S.font, fontSize: 11, color: S.gold, marginTop: 6 }}>
                — Hervé Moulin, économiste et spécialiste de la théorie du choix social
              </div>
              <a href="https://aceproject.org/ace-fr/topics/es/esd/esd02/esd02d/default"
                target="_blank" rel="noreferrer" style={{
                  display: "inline-block", marginTop: 8,
                  fontFamily: S.font, fontSize: 11, color: S.green,
                  textDecoration: "underline",
                }}>
                En savoir plus sur le VUT — ACE Electoral Knowledge Network
              </a>
            </div>
          </div>
        )}
      </div>

      {!loading && results.length >= 3 && (
        <div style={{ marginBottom: 56 }}>
          <div style={{
            fontFamily: S.fontTitle, fontSize: 13, color: S.gold, letterSpacing: 4,
            textAlign: "center", marginBottom: 24, textTransform: "uppercase",
          }}>Grille de depart</div>

          <div className="podium-row" style={{
            display: "flex", justifyContent: "center", alignItems: "flex-end",
            gap: "clamp(8px,2vw,20px)", padding: "0 16px",
          }}>
            {podiumOrder.map((podiumIdx, visualIdx) => {
              const c = top3[podiumIdx];
              if (!c) return null;
              const candidat = candidats.find(x => x.id === c.id) || {};
              const h = podiumHeights[visualIdx];
              const sz = podiumSizes[visualIdx];
              const isFirst = podiumIdx === 0;
              return (
                <div key={c.id}
                  onClick={() => candidat.slug && navigate(`/elu/${candidat.slug}`)}
                  style={{
                  display: "flex", flexDirection: "column", alignItems: "center",
                  animation: `f1SlideUp 0.6s ${0.1 + visualIdx * 0.15}s cubic-bezier(0.22,1,0.36,1) both`,
                  flex: isFirst ? "1.3" : "1", maxWidth: isFirst ? 180 : 150,
                  cursor: candidat.slug ? "pointer" : "default",
                }}>
                  <div style={{
                    fontFamily: S.fontTitle, fontSize: isFirst ? 22 : 16,
                    color: S.gold, letterSpacing: 2, marginBottom: 8,
                    textShadow: isFirst ? `0 0 20px ${S.gold}66` : "none",
                  }}>{podiumLabels[visualIdx]}</div>

                  <div style={{
                    position: "relative", marginBottom: 10,
                  }}>
                    <div style={{
                      position: "absolute", inset: -3, borderRadius: "50%",
                      background: isFirst
                        ? `conic-gradient(${S.gold}, ${candidat.couleur}, ${S.gold})`
                        : `conic-gradient(${candidat.couleur}88, ${candidat.couleur}33, ${candidat.couleur}88)`,
                      animation: isFirst ? "f1Spin 4s linear infinite" : "none",
                    }} />
                    <div style={{ position: "relative" }}>
                      <Avatar elu={{ nom: candidat.nom, couleur: candidat.couleur, photo_url: candidat.photo }} size={sz} />
                    </div>
                  </div>

                  <div style={{
                    background: `linear-gradient(135deg, ${candidat.couleur}dd, ${candidat.couleur}88)`,
                    borderRadius: 20, padding: "6px 14px", marginBottom: 4,
                    boxShadow: `0 4px 16px ${candidat.couleur}44`,
                    maxWidth: "100%",
                  }}>
                    <div style={{
                      fontFamily: S.font, fontSize: isFirst ? 14 : 12, fontWeight: 900,
                      color: "#fff", textAlign: "center",
                      whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                      textShadow: "0 1px 3px rgba(0,0,0,0.5)",
                    }}>{candidat.nom}</div>
                  </div>

                  <div style={{
                    fontFamily: S.font, fontSize: 11, color: candidat.couleur, fontWeight: 700,
                    marginBottom: 4,
                  }}>{candidat.parti}</div>

                  <div style={{
                    fontFamily: S.fontTitle, fontSize: isFirst ? 24 : 18, color: S.gold,
                    textShadow: isFirst ? `0 0 16px ${S.gold}55` : "none",
                  }}>{c.score}</div>
                  <div style={{
                    fontFamily: S.font, fontSize: 9, color: S.textDim, letterSpacing: 1,
                  }}>PTS VUT</div>

                  <div style={{
                    width: "100%", height: h, marginTop: 12, borderRadius: "12px 12px 0 0",
                    background: `linear-gradient(180deg, ${candidat.couleur}44, ${candidat.couleur}11)`,
                    border: `1px solid ${candidat.couleur}33`, borderBottom: "none",
                    position: "relative", overflow: "hidden",
                  }}>
                    <div style={{
                      position: "absolute", top: 0, left: 0, right: 0, height: 3,
                      background: candidat.couleur,
                    }} />
                    {isFirst && <div style={{
                      position: "absolute", inset: 0,
                      background: `repeating-linear-gradient(45deg, transparent, transparent 10px, ${candidat.couleur}08 10px, ${candidat.couleur}08 20px)`,
                    }} />}
                  </div>
                </div>
              );
            })}
          </div>

          <div style={{
            height: 3, maxWidth: 500, margin: "0 auto",
            background: `linear-gradient(90deg, transparent, ${S.gold}44, ${S.gold}, ${S.gold}44, transparent)`,
          }} />
        </div>
      )}

      {!loading && results.length > 0 && (
        <div style={{
          background: `linear-gradient(180deg, ${S.card}ee, ${S.card}99)`,
          borderRadius: 20, border: `1px solid ${S.border}`,
          padding: "28px 20px", marginBottom: 48,
          backdropFilter: "blur(10px)",
        }}>
          <div style={{
            display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 20,
          }}>
            <div>
              <div style={{
                fontFamily: S.fontTitle, fontSize: 18, color: S.gold, letterSpacing: 2,
              }}>Classement</div>
              <div style={{
                fontFamily: S.font, fontSize: 11, color: S.textDim, letterSpacing: 1,
              }}>{totalVotes} vote{totalVotes > 1 ? "s" : ""}</div>
            </div>
            <div style={{
              fontFamily: S.font, fontSize: 10, color: S.textDim, letterSpacing: 2,
              textTransform: "uppercase", padding: "4px 10px",
              border: `1px solid ${S.border}`, borderRadius: 20,
            }}>Temps reel</div>
          </div>

          {results.map((c, i) => {
            const candidat = candidats.find(x => x.id === c.id) || {};
            const pct = maxScore > 0 ? (c.score / maxScore) * 100 : 0;
            const isTop3 = i < 3;
            return (
              <div key={c.id}
                onClick={() => candidat.slug && navigate(`/elu/${candidat.slug}`)}
                style={{
                display: "flex", alignItems: "center", gap: 12,
                padding: "10px 14px", marginBottom: 6,
                borderRadius: 14, position: "relative", overflow: "hidden",
                background: isTop3
                  ? `linear-gradient(90deg, ${candidat.couleur}15, transparent)`
                  : `${S.bg}66`,
                borderLeft: `4px solid ${candidat.couleur}`,
                transition: "all 0.3s",
                animation: `f1SlideIn 0.4s ${i * 0.05}s ease both`,
                cursor: candidat.slug ? "pointer" : "default",
              }}>
                <div style={{
                  position: "absolute", bottom: 0, left: 0, height: 2,
                  width: `${pct}%`, background: `linear-gradient(90deg, ${candidat.couleur}88, ${candidat.couleur}22)`,
                  transition: "width 1s ease",
                }} />

                <div style={{
                  fontFamily: S.fontTitle, fontSize: isTop3 ? 16 : 13,
                  color: isTop3 ? S.gold : S.textDim,
                  width: 36, textAlign: "center", flexShrink: 0,
                  textShadow: isTop3 ? `0 0 8px ${S.gold}44` : "none",
                }}>P{i + 1}</div>

                <Avatar elu={{ nom: candidat.nom, couleur: candidat.couleur, photo_url: candidat.photo }} size={38} showBorder={false} />

                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                    <span style={{
                      fontFamily: S.font, fontSize: 14, fontWeight: 800, color: S.textMain,
                      whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                    }}>{candidat.nom}</span>
                    <span style={{
                      fontFamily: S.font, fontSize: 10, color: "#fff", fontWeight: 700,
                      background: `${candidat.couleur}88`, borderRadius: 10, padding: "1px 8px",
                      flexShrink: 0,
                    }}>{candidat.parti}</span>
                  </div>
                  <div style={{
                    height: 5, borderRadius: 3, marginTop: 5,
                    background: `${S.border}66`, overflow: "hidden",
                  }}>
                    <div style={{
                      height: "100%", borderRadius: 3, width: `${pct}%`,
                      background: `linear-gradient(90deg, ${candidat.couleur}, ${candidat.couleur}66)`,
                      transition: "width 0.8s ease",
                      boxShadow: `0 0 6px ${candidat.couleur}44`,
                    }} />
                  </div>
                </div>

                <div style={{
                  fontFamily: S.fontTitle, fontSize: 16, color: S.gold, flexShrink: 0,
                  minWidth: 44, textAlign: "right",
                }}>{c.score}</div>
              </div>
            );
          })}
        </div>
      )}

      {loading && (
        <div style={{ textAlign: "center", padding: 40, color: S.textDim, fontFamily: S.font }}>
          Chargement...
        </div>
      )}

      <div style={{
        background: `linear-gradient(180deg, ${S.card}ee, ${S.bg}cc)`,
        borderRadius: 20, border: `1px solid ${S.gold}33`,
        padding: "32px 20px", position: "relative", overflow: "hidden",
        boxShadow: `0 0 60px ${S.gold}11, inset 0 1px 0 ${S.gold}22`,
      }}>
        <div style={{
          position: "absolute", top: 0, left: 0, right: 0, height: 3,
          background: `linear-gradient(90deg, transparent, ${S.gold}, transparent)`,
        }} />

        <div style={{
          fontFamily: S.fontTitle, fontSize: "clamp(22px,5vw,32px)", color: S.gold,
          textAlign: "center", marginBottom: 4, letterSpacing: 3,
          textShadow: `0 0 20px ${S.gold}44`,
        }}>Votre classement</div>
        <div style={{
          fontFamily: S.font, fontSize: 13, color: S.textDim, textAlign: "center", marginBottom: 24,
        }}>
          {voted
            ? "Merci pour votre vote !"
            : `Classez tous les candidats (${ranking.length}/${candidats.length})`
          }
        </div>

        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          {ranking.length > 0 && (
            <div style={{ marginBottom: 8 }}>
              {ranking.map((id, i) => {
                const c = candidats.find(x => x.id === id);
                if (!c) return null;
                const isDragging = dragIndex === i;
                const isDragOver = dragOverIndex === i && dragIndex !== i;
                return (
                  <div key={c.id}
                    draggable={!voted}
                    onDragStart={() => setDragIndex(i)}
                    onDragOver={(e) => { e.preventDefault(); setDragOverIndex(i); }}
                    onDragLeave={() => setDragOverIndex(null)}
                    onDrop={(e) => { e.preventDefault(); reorderRanking(dragIndex, i); setDragIndex(null); setDragOverIndex(null); }}
                    onDragEnd={() => { setDragIndex(null); setDragOverIndex(null); }}
                    style={{
                    display: "flex", alignItems: "center", gap: 12,
                    padding: "8px 14px", marginBottom: 4, borderRadius: 12,
                    background: isDragOver
                      ? `linear-gradient(90deg, ${S.gold}33, ${c.couleur}22)`
                      : `linear-gradient(90deg, ${c.couleur}22, transparent)`,
                    borderLeft: `3px solid ${isDragOver ? S.gold : c.couleur}`,
                    boxShadow: isDragOver ? `0 0 12px ${S.gold}55` : "none",
                    cursor: voted ? "default" : "grab",
                    animation: `f1SlideIn 0.3s ease`,
                    opacity: voted ? 0.6 : (isDragging ? 0.4 : 1),
                    transition: "all 0.15s",
                    userSelect: "none",
                  }}>
                    {/* Drag handle */}
                    {!voted && (
                      <div style={{
                        color: S.textDim, fontSize: 14, lineHeight: 1, fontWeight: 900,
                        flexShrink: 0, opacity: 0.6, letterSpacing: -2,
                      }} title="Glisser pour réordonner">⋮⋮</div>
                    )}
                    <div style={{
                      width: 30, height: 30, borderRadius: "50%",
                      background: `linear-gradient(135deg, ${S.gold}, #e17a2d)`,
                      display: "flex", alignItems: "center", justifyContent: "center",
                      fontFamily: S.fontTitle, fontSize: 14, color: S.bg, flexShrink: 0,
                      boxShadow: `0 0 12px ${S.gold}55`,
                    }}>{i + 1}</div>
                    <Avatar elu={{ nom: c.nom, couleur: c.couleur, photo_url: c.photo }} size={32} showBorder={false} />
                    <span style={{
                      fontFamily: S.font, fontSize: 13, fontWeight: 800, color: S.textMain, flex: 1,
                    }}>{c.nom}</span>
                    <span style={{
                      fontFamily: S.font, fontSize: 10, color: "#fff", fontWeight: 700,
                      background: `${c.couleur}88`, borderRadius: 10, padding: "2px 8px",
                    }}>{c.parti}</span>
                    {/* Bouton retirer explicite */}
                    {!voted && (
                      <button
                        onClick={(e) => { e.stopPropagation(); toggleCandidat(c.id); }}
                        title="Retirer du classement"
                        style={{
                          background: "transparent", border: "none", cursor: "pointer",
                          color: S.textDim, fontSize: 14, padding: "4px 6px",
                          opacity: 0.6, lineHeight: 1,
                        }}
                      >✕</button>
                    )}
                  </div>
                );
              })}
            </div>
          )}

          <div className="vote-grid" style={{
            display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(150px, 1fr))",
            gap: 8,
          }}>
            {candidats.filter(c => !ranking.includes(c.id)).map(c => {
              return (
                <button key={c.id} onClick={() => toggleCandidat(c.id)} style={{
                  background: `${S.bg}cc`,
                  border: `2px solid ${S.border}`,
                  borderRadius: 14, padding: "12px 10px",
                  cursor: voted ? "default" : "pointer",
                  display: "flex", alignItems: "center", gap: 10,
                  transition: "all 0.2s", position: "relative", minHeight: 52,
                  opacity: voted ? 0.5 : 1,
                  borderLeftWidth: 4, borderLeftColor: c.couleur,
                }}>
                  <Avatar elu={{ nom: c.nom, couleur: c.couleur, photo_url: c.photo }} size={34} showBorder={false} />
                  <div style={{ textAlign: "left", minWidth: 0 }}>
                    <div style={{
                      fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textMain,
                      whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
                    }}>{c.nom}</div>
                    <div style={{ display: "flex", alignItems: "center", gap: 4, flexWrap: "wrap" }}>
                      <span style={{ fontFamily: S.font, fontSize: 10, color: c.couleur, fontWeight: 700 }}>{c.parti}</span>
                      <span style={{
                        fontFamily: S.font, fontSize: 9, fontWeight: 700, padding: "1px 5px", borderRadius: 6,
                        background: c.statut === "declare" ? "rgba(0,184,148,0.15)" : c.statut === "appel" ? "rgba(253,203,110,0.12)" : "rgba(255,255,255,0.06)",
                        color: c.statut === "declare" ? "#00b894" : c.statut === "appel" ? "#fdcb6e" : "rgba(255,255,255,0.3)",
                        border: c.statut === "declare" ? "1px solid rgba(0,184,148,0.3)" : c.statut === "appel" ? "1px solid rgba(253,203,110,0.3)" : "1px solid rgba(255,255,255,0.1)",
                      }}>{c.statut === "declare" ? "Déclaré ✓" : c.statut === "appel" ? "Appel ⚖️" : "Probable"}</span>
                    </div>
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        {!voted && (
          <button onClick={handleVote} disabled={!ready || sending} style={{
            display: "block", width: "100%", marginTop: 28, padding: "20px 24px",
            background: ready
              ? `linear-gradient(135deg, ${S.gold}, #e17a2d, ${S.gold})`
              : S.border,
            backgroundSize: ready ? "200% 200%" : "100% 100%",
            animation: ready ? "f1Shimmer 2s ease infinite" : "none",
            border: "none", borderRadius: 16,
            cursor: ready ? "pointer" : "not-allowed",
            fontFamily: S.fontTitle, fontSize: "clamp(22px,5vw,28px)", letterSpacing: 4,
            color: ready ? S.bg : S.textDim,
            boxShadow: ready ? `0 0 40px ${S.gold}55, 0 8px 32px rgba(0,0,0,0.4)` : "none",
            transition: "all 0.3s",
            minHeight: 64,
            transform: ready ? "scale(1)" : "scale(0.97)",
            textTransform: "uppercase",
          }}>
            {sending ? "Envoi..." : "Valider mon vote"}
          </button>
        )}

        {voted && (
          <div style={{ marginTop: 24 }}>
            <div style={{
              textAlign: "center", padding: 20,
              background: `linear-gradient(135deg, ${S.green}15, ${S.green}08)`,
              borderRadius: 16, border: `1px solid ${S.green}33`,
              marginBottom: 12,
            }}>
              <div style={{
                fontFamily: S.fontTitle, fontSize: 20, color: S.green, marginBottom: 4,
              }}>Vote enregistre !</div>
              <div style={{
                fontFamily: S.font, fontSize: 12, color: S.textDim,
              }}>Les resultats se mettent a jour en temps reel</div>
            </div>
            <button onClick={handleChangeAvis} disabled={sending} style={{
              display: "block", width: "100%", padding: "14px 20px",
              background: "transparent",
              border: `1px solid ${S.textDim}44`,
              borderRadius: 12, cursor: "pointer",
              fontFamily: S.font, fontSize: 13, fontWeight: 700,
              color: S.textDim, transition: "all 0.2s",
              letterSpacing: 1,
            }}
              onMouseEnter={e => { e.currentTarget.style.borderColor = S.gold + "66"; e.currentTarget.style.color = S.gold; }}
              onMouseLeave={e => { e.currentTarget.style.borderColor = S.textDim + "44"; e.currentTarget.style.color = S.textDim; }}
            >
              {sending ? "..." : "Changer d'avis"}
            </button>
          </div>
        )}
      </div>

      <style>{`
        @keyframes f1SlideUp {
          from { opacity: 0; transform: translateY(40px); }
          to { opacity: 1; transform: translateY(0); }
        }
        @keyframes f1SlideIn {
          from { opacity: 0; transform: translateX(-20px); }
          to { opacity: 1; transform: translateX(0); }
        }
        @keyframes f1Spin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }
        @keyframes f1Shimmer {
          0% { background-position: 0% 50%; }
          50% { background-position: 100% 50%; }
          100% { background-position: 0% 50%; }
        }
        @keyframes popIn {
          from { opacity: 0; transform: scale(0.5); }
          to { opacity: 1; transform: scale(1); }
        }
      `}</style>
    </div>
  );
};

export default Presidentielle2027;
