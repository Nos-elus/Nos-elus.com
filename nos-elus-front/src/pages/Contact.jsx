import { useState } from "react";
import { useLocation } from "react-router-dom";
import { S } from "../utils/constants";

const API = import.meta.env.VITE_API_URL ?? "/api";

const inputStyle = (focused) => ({
  width: "100%", padding: "12px 16px", borderRadius: 10,
  border: `1px solid ${focused ? S.gold : S.border}`,
  background: "rgba(255,255,255,0.04)", color: S.textMain,
  fontFamily: S.font, fontSize: 15, outline: "none",
  boxSizing: "border-box", transition: "border-color 0.2s",
});

const labelStyle = { display: "block", fontSize: 13, fontWeight: 700, color: S.textMuted, marginBottom: 6 };

export default function Contact() {
  const location = useLocation();
  const prefill = location.state || {};
  const [form, setForm] = useState({
    nom: "", email: "", website: "",
    sujet: prefill.sujet || "",
    message: prefill.message || "",
    sources: "",
  });
  const [focused, setFocused] = useState("");
  const [status, setStatus] = useState(null);
  const [errMsg, setErrMsg] = useState("");

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const validate = () => {
    if (!form.nom.trim()) return "Le nom est requis.";
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) return "Email invalide.";
    if (form.message.trim().length < 10) return "Le message doit faire au moins 10 caractères.";
    return null;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const err = validate();
    if (err) { setErrMsg(err); setStatus("error"); return; }
    setStatus("loading"); setErrMsg("");
    try {
      const res = await fetch(`${API}/contact.php`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(form),
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error ?? "Erreur serveur");
      setStatus("success");
      setForm({ nom: "", email: "", sujet: "", message: "", website: "", sources: "" });
    } catch (err) {
      setErrMsg(err.message);
      setStatus("error");
    }
  };

  return (
    <div style={{ paddingTop: 48, paddingBottom: 80 }}>
      <div style={{ textAlign: "center", marginBottom: 48 }}>
        <h1 style={{ fontFamily: S.fontTitle, fontSize: "clamp(28px, 6vw, 44px)", color: S.gold, margin: 0 }}>
          Contact
        </h1>
        <p style={{ color: S.textMuted, marginTop: 12, fontSize: 15, maxWidth: 480, margin: "12px auto 0" }}>
          Une question, un signalement, une suggestion ? On lit tout. 🦆
        </p>
      </div>

      <div style={{
        maxWidth: 560, margin: "0 auto",
        background: S.card, borderRadius: 18,
        border: `1px solid ${S.border}`, padding: "36px 32px",
      }}>
        {status === "success" ? (
          <div style={{ textAlign: "center", padding: "40px 24px", color: S.green, fontSize: 16, fontWeight: 700 }}>
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke={S.green} strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ marginBottom: 16 }}>
              <circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/>
            </svg>
            <div>Message envoyé avec succès !</div>
            <p style={{ color: S.textMuted, fontSize: 13, fontWeight: 400, marginTop: 8 }}>
              Nous vous répondrons dans les meilleurs délais.
            </p>
            <button onClick={() => setStatus(null)} style={{
              marginTop: 20, padding: "10px 24px", borderRadius: 10,
              background: "transparent", border: `1px solid ${S.green}`,
              color: S.green, cursor: "pointer", fontFamily: S.font, fontSize: 14, fontWeight: 700,
            }}>Envoyer un autre message</button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} noValidate>
            <input type="text" name="website" value={form.website} onChange={set("website")}
              tabIndex={-1} autoComplete="off" aria-hidden="true"
              style={{ position: "absolute", left: "-9999px", opacity: 0, width: 1, height: 1 }}
            />
            <div style={{ marginBottom: 20 }}>
              <label style={labelStyle}>Nom *</label>
              <input type="text" value={form.nom} onChange={set("nom")}
                onFocus={() => setFocused("nom")} onBlur={() => setFocused("")}
                placeholder="Votre nom" required maxLength={100}
                style={inputStyle(focused === "nom")} />
            </div>
            <div style={{ marginBottom: 20 }}>
              <label style={labelStyle}>Email *</label>
              <input type="email" value={form.email} onChange={set("email")}
                onFocus={() => setFocused("email")} onBlur={() => setFocused("")}
                placeholder="vous@exemple.fr" required
                style={inputStyle(focused === "email")} />
            </div>
            <div style={{ marginBottom: 20 }}>
              <label style={labelStyle}>Sujet</label>
              <select value={form.sujet} onChange={set("sujet")}
                onFocus={() => setFocused("sujet")} onBlur={() => setFocused("")}
                style={{ ...inputStyle(focused === "sujet"), appearance: "none" }}>
                <option value="">-- Choisissez un sujet --</option>
                <option value="Signalement">Signalement d'erreur</option>
                <option value="Suggestion">Suggestion d'amélioration</option>
                <option value="Presse">Demande presse</option>
                <option value="Autre">Autre</option>
              </select>
            </div>
            <div style={{ marginBottom: 20 }}>
              <label style={labelStyle}>Message * <span style={{ color: S.textDim, fontWeight: 400 }}>({form.message.length}/3000)</span></label>
              <textarea value={form.message} onChange={set("message")}
                onFocus={() => setFocused("message")} onBlur={() => setFocused("")}
                placeholder="Votre message..." required rows={5} maxLength={3000}
                style={{ ...inputStyle(focused === "message"), resize: "vertical", minHeight: 120 }} />
            </div>
            {form.sujet === "Signalement" && (
              <div style={{
                marginBottom: 24, padding: 16, borderRadius: 12,
                background: "rgba(255,107,107,0.05)", border: `1px solid ${S.red}22`,
              }}>
                <div style={{
                  fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.red,
                  marginBottom: 10, display: "flex", alignItems: "center", gap: 6,
                }}>
                  <span style={{ fontSize: 14 }}>&#9888;</span> Signalement d'erreur
                </div>
                <label style={labelStyle}>Sources / preuves <span style={{ color: S.textDim, fontWeight: 400 }}>(liens articles, captures...)</span></label>
                <textarea value={form.sources} onChange={set("sources")}
                  onFocus={() => setFocused("sources")} onBlur={() => setFocused("")}
                  placeholder="Ex: https://www.lemonde.fr/article... / Décision de justice du 12/03/2025..."
                  rows={3} maxLength={2000}
                  style={{ ...inputStyle(focused === "sources"), resize: "vertical", minHeight: 80 }} />
                <div style={{ fontFamily: S.font, fontSize: 10, color: S.textDim, marginTop: 6, fontStyle: "italic" }}>
                  Ajoutez des liens ou références pour appuyer votre signalement.
                </div>
              </div>
            )}
            {status === "error" && errMsg && (
              <div style={{
                background: "rgba(255,107,107,0.1)", border: `1px solid ${S.red}`,
                borderRadius: 10, padding: "10px 14px", marginBottom: 20,
                color: S.red, fontSize: 13, fontWeight: 600,
              }}>{errMsg}</div>
            )}
            <button type="submit" disabled={status === "loading"} style={{
              width: "100%", padding: "14px 24px", borderRadius: 12,
              background: status === "loading" ? S.border : `linear-gradient(135deg, ${S.gold} 0%, #e17055 100%)`,
              border: "none", color: status === "loading" ? S.textMuted : "#1a1a2e",
              fontFamily: S.fontTitle, fontSize: 16,
              cursor: status === "loading" ? "not-allowed" : "pointer",
              opacity: status === "loading" ? 0.7 : 1, minHeight: 44, transition: "opacity 0.2s",
            }}>{status === "loading" ? "Envoi en cours..." : "Envoyer le message"}</button>
          </form>
        )}
      </div>
      <p style={{ textAlign: "center", marginTop: 32, fontSize: 12, color: S.textDim }}>
        Aucun élu n'est averti de vos messages. Promis.
      </p>
    </div>
  );
}
