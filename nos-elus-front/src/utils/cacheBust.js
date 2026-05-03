// Cache-bust pour les images locales : ajoute ?v=<digits-de-updated_at>
// Utile car nginx envoie Cache-Control: immutable sur /photos/, donc le navigateur
// ne refresh jamais sans changement d'URL.
// Pour les URLs externes (Wikimedia, Senat, etc.), retourne l'URL inchangée.
export const withCacheBust = (url, updatedAt) => {
  if (!url || !updatedAt) return url;
  if (!/^\/(photos|assets)\//.test(url)) return url;
  const sep = url.includes("?") ? "&" : "?";
  const v = String(updatedAt).replace(/[^0-9]/g, "").slice(0, 14) || "1";
  return url + sep + "v=" + v;
};
