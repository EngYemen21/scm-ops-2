// Mapbox access for the client: provider config from the server, the GL library as its own lazy chunk, and place search.
// The token is a Mapbox PUBLIC token served to signed-in users by GET /transport/map/config — it is never in the bundle.
import { api } from '@/api/client';

const RTL_PLUGIN = 'https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-rtl-text/v0.3.0/mapbox-gl-rtl-text.js';

let configPromise = null;
let libPromise = null;

/** → { provider, configured, token, status, gps: { status } } (cached for the page's lifetime; a failure is retried next call). */
export function mapConfig() {
  return (configPromise ??= api.get('/transport/map/config').catch((e) => { configPromise = null; throw e; }));
}

/** mapbox-gl + its stylesheet, loaded on first use. Arabic labels need the RTL text plugin (lazy: fetched only when RTL text is drawn). */
export function loadMapbox() {
  return (libPromise ??= Promise.all([import('mapbox-gl'), import('mapbox-gl/dist/mapbox-gl.css')]).then(([m]) => {
    const gl = m.default;
    if (gl.getRTLTextPluginStatus() === 'unavailable') gl.setRTLTextPlugin(RTL_PLUGIN, null, true);
    return gl;
  }).catch((e) => { libPromise = null; throw e; }));
}

/** Forward geocoding limited to Saudi Arabia. → [{ id, name, place, lat, lng }] */
export async function searchPlaces(q, { language = 'ar', proximity = null, signal } = {}) {
  const { token } = await mapConfig();
  if (!token || !q.trim()) return [];
  const url = new URL('https://api.mapbox.com/search/geocode/v6/forward');
  url.search = new URLSearchParams({ q: q.trim(), country: 'sa', language, limit: '6', access_token: token, ...(proximity ? { proximity: `${proximity.lng},${proximity.lat}` } : {}) }).toString();
  const res = await fetch(url, { signal });
  if (!res.ok) throw new Error(`geocoding HTTP ${res.status}`);
  const data = await res.json();
  return (data.features || []).map((f) => ({
    id: f.id, name: f.properties?.name_preferred || f.properties?.name || '', place: f.properties?.place_formatted || '',
    lng: f.geometry?.coordinates?.[0], lat: f.geometry?.coordinates?.[1],
  })).filter((x) => Number.isFinite(x.lat) && Number.isFinite(x.lng));
}

export const hasPoint = (p) => !!p && Number.isFinite(p.lat) && Number.isFinite(p.lng);
