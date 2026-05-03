// UUID anonyme stocké localement (jamais envoyé sauf si l'app le fait dans un body JSON).
// Sert d'identifiant unique de navigateur pour : vote 2027, likes/dislikes citoyens.
// Pas de PII, pas de cookie, pas de tracking tiers.
const KEY = "noselus_voter_uid";

const generate = () => {
  if (typeof crypto !== "undefined" && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return "v-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 12);
};

export const getVoterUid = () => {
  try {
    let uid = localStorage.getItem(KEY);
    if (!uid) {
      uid = generate();
      localStorage.setItem(KEY, uid);
    }
    return uid;
  } catch {
    // localStorage indisponible (mode privé strict, etc.) — fallback session
    return generate();
  }
};
