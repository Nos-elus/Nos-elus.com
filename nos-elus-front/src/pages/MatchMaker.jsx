import { useState, useEffect, useCallback, useRef } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { S } from "../utils/constants";
import { fetchApi } from "../hooks/useApi";
import Avatar from "../components/Avatar";

const slugify = (t) =>
  t.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");

// ── Phrases d'humour par filtre ──
const HUMOR = {
  age: [
    "Jeune loup affame ou vieux renard ruse ?",
    "L'age, c'est comme les mandats : plus y'en a, moins on sait a quoi ca sert.",
    "On cherche un(e) elu(e) d'experience ou un(e) petit(e) nouveau/nouvelle ?",
  ],
  sexe: [
    "Votre coeur politique bat pour...",
    "Homme, femme... l'important c'est la casserole, non ?",
    "En politique comme en amour, la parite c'est pas gagne.",
  ],
  region: [
    "L'amour n'a pas de frontiere... mais les circonscriptions, si.",
    "Elu(e) du terroir ou de la capitale ? Choisissez votre camp.",
    "La distance n'effraie pas l'amour. Les frais de deplacement, un peu plus.",
  ],
  parti: [
    "La couleur politique, ca compte en amour ?",
    "Rouge, bleu, vert, orange... tous les gouts sont dans la nature.",
    "Choisir un parti, c'est deja tromper les autres.",
  ],
  mandats: [
    "Combien de mandats au compteur ? C'est comme le kilometrage d'une voiture.",
    "Un seul mandat : debutant. Cinq mandats : professionnel du fauteuil.",
    "Plus de mandats que de doigts ? C'est un cumulard.",
  ],
  casseroles: [
    "Quel niveau de casseroles tolerez-vous dans le couple ?",
    "Zero casserole : licorne politique. Trois et plus : collector.",
    "Les casseroles, c'est comme les ex : tout le monde en a, personne n'en parle.",
  ],
  photo: [
    "Vous achetez un produit sans voir l'emballage, vous ?",
    "Avec photo : vous savez a quoi vous attendre. Sans photo : on aime les surprises !",
    "La beaute interieure, c'est bien. Mais une photo, c'est mieux pour le stalking electoral.",
  ],
};

const pickHumor = (key) => HUMOR[key][Math.floor(Math.random() * HUMOR[key].length)];

// ── Composant filtre (slider / select / toggle) ──
const FilterCard = ({ icon, title, humor, children, active }) => (
  <div style={{
    background: active ? `linear-gradient(135deg, ${S.card}, rgba(253,203,110,0.06))` : S.card,
    border: `1px solid ${active ? S.gold + "44" : S.border}`,
    borderRadius: 16, padding: "18px 20px", transition: "all 0.3s",
  }}>
    <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 6 }}>
      <span style={{ fontSize: 24 }}>{icon}</span>
      <span style={{ fontFamily: S.fontTitle, fontSize: 17, color: active ? S.gold : S.textMain }}>{title}</span>
    </div>
    <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginBottom: 14, fontStyle: "italic", lineHeight: 1.5 }}>
      {humor}
    </div>
    {children}
  </div>
);

// ── Select stylise ──
const StyledSelect = ({ value, onChange, options, placeholder }) => (
  <select value={value} onChange={e => onChange(e.target.value)} style={{
    width: "100%", padding: "11px 16px", background: S.bg, color: S.textMain,
    border: `1px solid ${S.border}`, borderRadius: 10, fontFamily: S.font,
    fontSize: 15, fontWeight: 700, outline: "none", cursor: "pointer",
    appearance: "none", backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23a0aec0' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E")`,
    backgroundRepeat: "no-repeat", backgroundPosition: "right 14px center",
  }}>
    <option value="">{placeholder}</option>
    {options.map(o => <option key={o} value={o}>{o}</option>)}
  </select>
);

// ── Range slider double ──
const RangeSlider = ({ min, max, valueMin, valueMax, onChangeMin, onChangeMax, unit, step = 1 }) => (
  <div>
    <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6 }}>
      <span style={{ fontFamily: S.font, fontSize: 14, color: S.gold, fontWeight: 800 }}>
        {valueMin}{unit}
      </span>
      <span style={{ fontFamily: S.font, fontSize: 14, color: S.gold, fontWeight: 800 }}>
        {valueMax}{unit}
      </span>
    </div>
    <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
      <span style={{ fontSize: 12, color: S.textDim }}>{min}</span>
      <input type="range" min={min} max={max} step={step} value={valueMin}
        onChange={e => onChangeMin(Math.min(+e.target.value, valueMax))}
        style={{ flex: 1, accentColor: S.gold, height: 6 }} />
      <input type="range" min={min} max={max} step={step} value={valueMax}
        onChange={e => onChangeMax(Math.max(+e.target.value, valueMin))}
        style={{ flex: 1, accentColor: S.gold, height: 6 }} />
      <span style={{ fontSize: 12, color: S.textDim }}>{max}</span>
    </div>
  </div>
);

// ── Toggle buttons ──
const ToggleGroup = ({ options, value, onChange }) => (
  <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
    {options.map(opt => (
      <button key={opt.value} onClick={() => onChange(value === opt.value ? "" : opt.value)} style={{
        padding: "10px 18px", borderRadius: 99,
        background: value === opt.value ? `${S.gold}22` : "rgba(255,255,255,0.04)",
        border: `1px solid ${value === opt.value ? S.gold + "66" : S.border}`,
        color: value === opt.value ? S.gold : S.textMuted,
        fontFamily: S.font, fontSize: 14, fontWeight: 800,
        cursor: "pointer", transition: "all 0.2s",
      }}>
        {opt.icon} {opt.label}
      </button>
    ))}
  </div>
);

// ── Carte resultat elu ──
const EluCard = ({ elu, rank, navigate }) => {
  const medals = ["", "🥇", "🥈", "🥉"];
  const medal = medals[rank] || "";

  return (
    <div onClick={() => navigate(`/elu/${elu.slug || slugify(elu.nom)}`)} style={{
      background: rank === 1 ? `linear-gradient(135deg, ${S.card}, ${S.gold}12)` : S.card,
      border: `1px solid ${rank === 1 ? S.gold + "44" : S.border}`,
      borderRadius: 16, padding: "16px 18px",
      display: "flex", alignItems: "center", gap: 14,
      cursor: "pointer", transition: "all 0.2s",
      animation: `slideUp 0.4s cubic-bezier(0.16,1,0.3,1) ${rank * 0.03}s both`,
    }}
      onMouseEnter={e => { e.currentTarget.style.transform = "translateY(-2px)"; e.currentTarget.style.boxShadow = `0 8px 24px rgba(0,0,0,0.3)`; }}
      onMouseLeave={e => { e.currentTarget.style.transform = "translateY(0)"; e.currentTarget.style.boxShadow = "none"; }}
    >
      {/* Rank */}
      <div style={{
        width: 36, height: 36, borderRadius: "50%", flexShrink: 0,
        background: rank <= 3 ? `${S.gold}22` : "rgba(255,255,255,0.04)",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontFamily: S.fontTitle, fontSize: rank <= 3 ? 18 : 13,
        color: rank <= 3 ? S.gold : S.textDim,
      }}>
        {medal || rank}
      </div>

      {/* Avatar */}
      <Avatar elu={elu} size={54} showBorder={false} />

      {/* Info */}
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontFamily: S.font, fontSize: 16, fontWeight: 800, color: S.textMain, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
          {elu.nom}
        </div>
        <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, marginTop: 2 }}>
          {elu.parti || "Sans etiquette"} {elu.fonction ? `· ${elu.fonction.substring(0, 40)}` : ""}
        </div>
      </div>

      {/* Badges */}
      <div style={{ display: "flex", gap: 8, alignItems: "center", flexShrink: 0 }}>
        <span style={{
          padding: "4px 10px", borderRadius: 99, fontSize: 12, fontWeight: 800,
          fontFamily: S.font,
          background: elu.nb_casseroles === 0 ? `${S.green}22` : `${S.red}22`,
          color: elu.nb_casseroles === 0 ? S.green : S.red,
        }}>
          {elu.nb_casseroles === 0 ? "Clean" : `${elu.nb_casseroles} 🍳`}
        </span>
        {elu.nb_mandats > 0 && (
          <span style={{
            padding: "4px 10px", borderRadius: 99, fontSize: 12, fontWeight: 800,
            fontFamily: S.font, background: `${S.purple}22`, color: S.purple,
          }}>
            {elu.nb_mandats} 🏛️
          </span>
        )}
        {elu.age ? (
          <span style={{ fontSize: 12, fontWeight: 800, fontFamily: S.font, color: S.textDim, whiteSpace: "nowrap" }}>
            {elu.age} ans
          </span>
        ) : null}
      </div>
    </div>
  );
};

// ── Messages de resultat humoristiques ──
const RESULT_MESSAGES = {
  0: [
    "Aucun(e) elu(e) ne correspond... Vous etes trop exigeant(e), ou la politique est trop decevante.",
    "Zero resultat. Meme Diogene avec sa lanterne n'aurait rien trouve.",
    "L'elu(e) ideal(e) n'existe pas. La preuve.",
  ],
  few: [
    "Peu de candidats... l'amour politique est une denree rare.",
    "C'est la creme de la creme. Ou ce qu'il en reste.",
    "Selection VIP. Quasiment un club prive.",
  ],
  many: [
    "L'embarras du choix ! Comme un dimanche d'election.",
    "Vous n'etes pas difficile, et ca se voit.",
    "Beau panel. Maintenant, le plus dur : choisir.",
  ],
  huge: [
    "Tout le monde vous plait ? C'est beau l'ouverture d'esprit.",
    "Avec autant de resultats, autant voter au hasard. Ah non, attendez...",
    "On dirait une liste electorale. C'en est une.",
  ],
};

const getResultMessage = (total) => {
  const pool = total === 0 ? RESULT_MESSAGES[0]
    : total <= 10 ? RESULT_MESSAGES.few
    : total <= 100 ? RESULT_MESSAGES.many
    : RESULT_MESSAGES.huge;
  return pool[Math.floor(Math.random() * pool.length)];
};

// Mapping Home.jsx defaultSearch → valeur poste MatchMaker
const mapDefaultSearch = (s) => {
  if (!s) return "";
  const v = s.toLowerCase();
  if (v === "député") return "Député AN";
  if (v === "sénateur") return "Sénateur";
  if (v === "maire") return "Maire";
  if (v === "ministre") return "Ministre";
  if (v.includes("europ")) return "Eurodéputé";
  if (v.includes("région") || v === "régional") return "Conseiller régional";
  if (v.includes("départ") || v === "départemental") return "Conseiller départemental";
  return "";
};

// ── Composant principal ──
const MatchMaker = () => {
  const navigate = useNavigate();
  const location = useLocation();

  // Filtres — poste initialisé depuis navigation Home si présent
  const [ageMin, setAgeMin] = useState(18);
  const [ageMax, setAgeMax] = useState(95);
  const [sexe, setSexe] = useState("");
  const [region, setRegion] = useState("");
  const [parti, setParti] = useState("");
  const [mandatsMin, setMandatsMin] = useState(0);
  const [mandatsMax, setMandatsMax] = useState(20);
  const [casserolesMax, setCasserolesMax] = useState(10);
  const [photo, setPhoto] = useState("");
  const [poste, setPoste] = useState(() => mapDefaultSearch(location.state?.defaultSearch));
  const [salaireMin, setSalaireMin] = useState(0);
  const [salaireMax, setSalaireMax] = useState(20000);
  const [sort, setSort] = useState("score");

  // Resultats
  const [results, setResults] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(0);
  const [loading, setLoading] = useState(false);
  const [options, setOptions] = useState({ regions: [], partis: [] });
  const [resultMsg, setResultMsg] = useState("");

  // Humor par filtre (fixe au mount pour eviter le re-render dance)
  const [humors] = useState(() => ({
    age: pickHumor("age"),
    sexe: pickHumor("sexe"),
    region: pickHumor("region"),
    parti: pickHumor("parti"),
    mandats: pickHumor("mandats"),
    casseroles: pickHumor("casseroles"),
    photo: pickHumor("photo"),
    poste: "Quel type d'élu vous fait craquer ?",
    salaire: "L'argent public, ça vous parle ?",
  }));

  const timerRef = useRef(null);
  const firstLoad = useRef(true);

  // Fetch avec debounce
  const doFetch = useCallback((p = 1) => {
    clearTimeout(timerRef.current);
    timerRef.current = setTimeout(async () => {
      setLoading(true);
      try {
        const params = { page: p, limit: 30, sort };
        if (ageMin > 18 || ageMax < 95) {
          params.age_min = ageMin;
          params.age_max = ageMax;
        }
        if (sexe) params.sexe = sexe;
        if (region) params.region = region;
        if (parti) params.parti = parti;
        if (mandatsMin > 0) params.mandats_min = mandatsMin;
        if (mandatsMax < 20) params.mandats_max = mandatsMax;
        if (casserolesMax < 10) params.casseroles_max = casserolesMax;
        if (photo) params.photo = photo;
        if (poste) params.poste = poste;
        if (salaireMin > 0) params.salaire_min = salaireMin;
        if (salaireMax < 20000) params.salaire_max = salaireMax;

        const res = await fetchApi("matchmaker.php", params);
        setResults(res.data || []);
        setTotal(res.total || 0);
        setPage(res.page || 1);
        setPages(res.pages || 0);
        if (res.options) setOptions(res.options);
        setResultMsg(getResultMessage(res.total || 0));
      } catch (err) {
        console.error("Matchmaker fetch error:", err);
      } finally {
        setLoading(false);
      }
    }, firstLoad.current ? 0 : 400);
    firstLoad.current = false;
  }, [ageMin, ageMax, sexe, region, parti, mandatsMin, mandatsMax, casserolesMax, photo, poste, salaireMin, salaireMax, sort]);

  useEffect(() => { doFetch(1); }, [doFetch]);
  useEffect(() => { window.scrollTo(0, 0); }, []);

  const hasFilters = ageMin > 18 || ageMax < 95 || sexe || region || parti || mandatsMin > 0 || mandatsMax < 20 || casserolesMax < 10 || photo || poste || salaireMin > 0 || salaireMax < 20000;

  const resetFilters = () => {
    setAgeMin(18); setAgeMax(95); setSexe(""); setRegion(""); setParti("");
    setMandatsMin(0); setMandatsMax(20); setCasserolesMax(10);
    setPhoto(""); setPoste(""); setSalaireMin(0); setSalaireMax(20000); setSort("score");
  };

  return (
    <div style={{ animation: "slideUp 0.5s cubic-bezier(0.16,1,0.3,1)", maxWidth: 1100, margin: "0 auto", paddingTop: 40, paddingBottom: 60 }}>
      {/* Header */}
      <div style={{ textAlign: "center", marginBottom: 36 }}>
        <div style={{ fontSize: 56, marginBottom: 10 }}>💘</div>
        <h1 style={{
          fontFamily: S.fontTitle, fontSize: 32, color: S.gold, margin: 0,
          textShadow: "0 0 20px rgba(253,203,110,0.3)",
        }}>L'elu(e) de votre coeur</h1>
        <p style={{
          fontFamily: S.font, fontSize: 17, color: S.textDim, marginTop: 10, maxWidth: 560, margin: "10px auto 0",
          lineHeight: 1.5,
        }}>
          Decrivez l'elu(e) de vos reves et on vous trouve le match parfait.
          <br />
          <span style={{ fontSize: 14, color: S.textDim }}>Spoiler : la perfection n'existe pas en politique.</span>
        </p>
      </div>

      {/* Layout : filtres + resultats */}
      <div style={{ display: "grid", gridTemplateColumns: "380px 1fr", gap: 24, alignItems: "start" }}
        className="matchmaker-layout"
      >
        {/* Colonne filtres */}
        <div style={{ display: "flex", flexDirection: "column", gap: 14, position: "sticky", top: 70, maxHeight: "calc(100vh - 90px)", overflowY: "auto", paddingRight: 4 }} className="matchmaker-filters">

          {/* Poste — remonté en premier filtre */}
          <FilterCard icon="🏛️" title="Type de poste" humor={humors.poste} active={!!poste}>
            <StyledSelect value={poste} onChange={setPoste} options={[
              "Député AN", "Sénateur", "Eurodéputé", "Maire", "Ministre",
              "Conseiller régional", "Conseiller départemental",
            ]} placeholder="Tous les postes" />
          </FilterCard>

          {/* Age */}
          <FilterCard icon="🎂" title="Âge" humor={humors.age} active={ageMin > 18 || ageMax < 95}>
            <RangeSlider min={18} max={95} valueMin={ageMin} valueMax={ageMax}
              onChangeMin={setAgeMin} onChangeMax={setAgeMax} unit=" ans" />
          </FilterCard>

          {/* Sexe */}
          <FilterCard icon="💃" title="Genre" humor={humors.sexe} active={!!sexe}>
            <ToggleGroup value={sexe} onChange={setSexe} options={[
              { value: "F", label: "Femme", icon: "👩" },
              { value: "H", label: "Homme", icon: "👨" },
            ]} />
          </FilterCard>

          {/* Region */}
          <FilterCard icon="🗺️" title="Région" humor={humors.region} active={!!region}>
            <StyledSelect value={region} onChange={setRegion} options={options.regions}
              placeholder="Toutes les régions" />
          </FilterCard>

          {/* Parti */}
          <FilterCard icon="🏳️" title="Parti politique" humor={humors.parti} active={!!parti}>
            <StyledSelect value={parti} onChange={setParti} options={options.partis}
              placeholder="Tous les partis" />
          </FilterCard>

          {/* Mandats */}
          <FilterCard icon="🏛️" title="Nombre de mandats" humor={humors.mandats} active={mandatsMin > 0 || mandatsMax < 20}>
            <RangeSlider min={0} max={20} valueMin={mandatsMin} valueMax={mandatsMax}
              onChangeMin={setMandatsMin} onChangeMax={setMandatsMax} unit="" />
          </FilterCard>

          {/* Casseroles */}
          <FilterCard icon="🍳" title="Casseroles max" humor={humors.casseroles} active={casserolesMax < 10}>
            <div>
              <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6 }}>
                <span style={{ fontFamily: S.font, fontSize: 14, color: S.gold, fontWeight: 800 }}>
                  {casserolesMax === 10 ? "Illimité" : casserolesMax === 0 ? "Zéro tolérance" : `Max ${casserolesMax}`}
                </span>
              </div>
              <input type="range" min={0} max={10} step={1} value={casserolesMax}
                onChange={e => setCasserolesMax(+e.target.value)}
                style={{ width: "100%", accentColor: casserolesMax <= 2 ? S.green : casserolesMax <= 5 ? S.gold : S.red, height: 6 }} />
            </div>
          </FilterCard>

          {/* Salaire */}
          <FilterCard icon="💰" title="Indemnités publiques mensuelles" humor={humors.salaire} active={salaireMin > 0 || salaireMax < 20000}>
            <RangeSlider min={0} max={20000} valueMin={salaireMin} valueMax={salaireMax}
              onChangeMin={setSalaireMin} onChangeMax={setSalaireMax} unit=" €" step={500} />
          </FilterCard>


          {/* Photo */}
          <FilterCard icon="📸" title="Photo" humor={humors.photo} active={!!photo}>
            <ToggleGroup value={photo} onChange={setPhoto} options={[
              { value: "oui", label: "Avec photo", icon: "🖼️" },
              { value: "non", label: "Surprise !", icon: "🎁" },
            ]} />
          </FilterCard>

          {/* Reset */}
          {hasFilters && (
            <button onClick={resetFilters} style={{
              padding: "13px 20px", borderRadius: 12,
              background: "rgba(255,107,107,0.1)", border: `1px solid ${S.red}44`,
              color: S.red, fontFamily: S.font, fontSize: 14, fontWeight: 800,
              cursor: "pointer", transition: "all 0.2s", width: "100%",
            }}>
              🗑️ Remettre les compteurs a zero
            </button>
          )}
        </div>

        {/* Colonne resultats */}
        <div>
          {/* Barre resultat */}
          <div style={{ marginBottom: 20 }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, flexWrap: "wrap" }}>
              <span style={{ fontFamily: S.fontTitle, fontSize: 21, color: S.textMain }}>
                {loading ? "..." : `${total.toLocaleString("fr-FR")} match${total > 1 ? "s" : ""}`}
              </span>
              <select value={sort} onChange={e => setSort(e.target.value)} style={{
                padding: "8px 14px", background: S.bg, color: S.textMain,
                border: `1px solid ${S.border}`, borderRadius: 10, fontFamily: S.font,
                fontSize: 14, fontWeight: 700, outline: "none", cursor: "pointer",
              }}>
                <option value="score">Meilleur score</option>
                <option value="casseroles">Plus de casseroles</option>
                <option value="mandats">Plus de mandats</option>
                <option value="age_asc">Plus jeune</option>
                <option value="age_desc">Plus âgé</option>
                <option value="salaire">Salaire le plus élevé</option>
                <option value="consultations">Les plus consultés</option>
              </select>
            </div>
            {resultMsg && !loading && (
              <div style={{ fontFamily: S.font, fontSize: 13, color: S.textDim, fontStyle: "italic", marginTop: 3 }}>
                {resultMsg}
              </div>
            )}
          </div>

          {/* Liste */}
          {loading ? (
            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
              {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} style={{
                  background: S.card, borderRadius: 16, height: 86,
                  border: `1px solid ${S.border}`,
                  animation: `pulse 1.2s ease-in-out ${i * 0.1}s infinite`,
                }} />
              ))}
            </div>
          ) : results.length === 0 ? (
            <div style={{
              textAlign: "center", padding: "80px 24px",
              background: S.card, borderRadius: 20, border: `1px solid ${S.border}`,
            }}>
              <div style={{ fontSize: 62, marginBottom: 16 }}>💔</div>
              <div style={{ fontFamily: S.fontTitle, fontSize: 21, color: S.textMuted }}>
                Aucun match
              </div>
              <div style={{ fontFamily: S.font, fontSize: 15, color: S.textDim, marginTop: 10 }}>
                {resultMsg}
              </div>
              <button onClick={resetFilters} style={{
                marginTop: 20, padding: "11px 26px", borderRadius: 99,
                background: S.gold, border: "none", color: S.bg,
                fontFamily: S.font, fontSize: 15, fontWeight: 800,
                cursor: "pointer",
              }}>
                Reessayer avec moins d'exigences
              </button>
            </div>
          ) : (
            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
              {results.map((elu, i) => (
                <EluCard key={elu.id} elu={elu} rank={(page - 1) * 30 + i + 1} navigate={navigate} />
              ))}
            </div>
          )}

          {/* Pagination */}
          {pages > 1 && (
            <div style={{ display: "flex", justifyContent: "center", gap: 8, marginTop: 24 }}>
              {page > 1 && (
                <button onClick={() => doFetch(page - 1)} style={{
                  padding: "11px 20px", borderRadius: 10, background: S.card,
                  border: `1px solid ${S.border}`, color: S.textMuted,
                  fontFamily: S.font, fontSize: 15, fontWeight: 700, cursor: "pointer",
                }}>← Prec.</button>
              )}
              <span style={{ padding: "11px 16px", fontFamily: S.font, fontSize: 15, color: S.textDim }}>
                {page} / {pages}
              </span>
              {page < pages && (
                <button onClick={() => doFetch(page + 1)} style={{
                  padding: "11px 20px", borderRadius: 10, background: S.card,
                  border: `1px solid ${S.border}`, color: S.textMuted,
                  fontFamily: S.font, fontSize: 15, fontWeight: 700, cursor: "pointer",
                }}>Suiv. →</button>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Responsive CSS */}
      <style>{`
        @keyframes pulse {
          0%, 100% { opacity: 0.6; }
          50% { opacity: 0.3; }
        }
        .matchmaker-filters::-webkit-scrollbar { width: 4px; }
        .matchmaker-filters::-webkit-scrollbar-track { background: transparent; }
        .matchmaker-filters::-webkit-scrollbar-thumb { background: ${S.border}; border-radius: 4px; }
        .matchmaker-filters { scrollbar-width: thin; scrollbar-color: ${S.border} transparent; }
        @media (max-width: 768px) {
          .matchmaker-layout {
            grid-template-columns: 1fr !important;
          }
          .matchmaker-layout > div:first-child {
            position: static !important;
            max-height: none !important;
            overflow-y: visible !important;
          }
        }
      `}</style>
    </div>
  );
};

export default MatchMaker;
