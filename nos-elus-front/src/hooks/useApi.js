import { useState, useEffect, useCallback, useRef } from "react";

const API_URL = import.meta.env.VITE_API_URL || "/api";

// ── Cache mémoire (survit au re-render, perdu au refresh) ──
const memoryCache = new Map();

/**
 * Hook principal pour les appels API
 * L1 : memoryCache (Map) — instantané pendant la navigation
 * L2 : fetch API — serveur (cache fichier 1h côté serveur + BDD)
 */
export function useApi(endpoint, params = {}, options = {}) {
  const { enabled = true } = options;
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const paramsStr = JSON.stringify(params);

  useEffect(() => {
    if (!enabled) { setLoading(false); return; }

    const controller = new AbortController();
    const query = new URLSearchParams(params).toString();
    const url = `${API_URL}/${endpoint}${query ? `?${query}` : ""}`;
    const cacheKey = `${endpoint}_${query}`;

    // L1 : mémoire (instantané si déjà consulté pendant la session)
    if (memoryCache.has(cacheKey)) {
      setData(memoryCache.get(cacheKey));
      setLoading(false);
      return;
    }

    // L2 : fetch serveur (cache fichier 1h côté serveur)
    setLoading(true);
    fetch(url, { signal: controller.signal, credentials: "same-origin" })
      .then((res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
      })
      .then((json) => {
        memoryCache.set(cacheKey, json);
        setData(json);
        setError(null);
      })
      .catch((err) => {
        if (err.name !== "AbortError") setError(err.message);
      })
      .finally(() => setLoading(false));

    return () => controller.abort();
  }, [endpoint, paramsStr, enabled]);

  return { data, loading, error };
}

/**
 * Fetch one-shot avec cache mémoire
 */
export async function fetchApi(endpoint, params = {}) {
  const query = new URLSearchParams(params).toString();
  const url = `${API_URL}/${endpoint}${query ? `?${query}` : ""}`;
  const cacheKey = `${endpoint}_${query}`;

  if (memoryCache.has(cacheKey)) return memoryCache.get(cacheKey);

  const res = await fetch(url, { credentials: "same-origin" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const json = await res.json();
  memoryCache.set(cacheKey, json);
  return json;
}

/**
 * Prefetch un profil élu en arrière-plan (fire & forget).
 * Remplit le cache mémoire pour que le clic soit instantané.
 */
export function prefetchElu(slug) {
  if (!slug) return;
  const cacheKey = `elu.php_slug=${slug}`;
  if (memoryCache.has(cacheKey)) return;
  fetchApi("elu.php", { slug }).catch(() => {});
}

/**
 * Hook de recherche avec debounce intégré
 */
export function useSearch(query, delay = 300) {
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const timerRef = useRef(null);

  useEffect(() => {
    if (query.length < 2) {
      setResults([]);
      setLoading(false);
      return;
    }

    clearTimeout(timerRef.current);

    timerRef.current = setTimeout(async () => {
      setLoading(true);
      try {
        const data = await fetchApi("search.php", { q: query });
        setResults(Array.isArray(data) ? data : []);
      } catch {
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, delay);

    return () => clearTimeout(timerRef.current);
  }, [query, delay]);

  return { results, loading };
}

/**
 * Invalider le cache mémoire
 */
export function invalidateCache(prefix = "") {
  if (prefix) {
    for (const key of memoryCache.keys()) {
      if (key.startsWith(prefix)) memoryCache.delete(key);
    }
  } else {
    memoryCache.clear();
  }
}
