// Driver phone tracking in the native app. Runs only for a signed-in driver, only while the server says a trip is
// active and the driver has consented (GET /delivery/tracking). Positions come from the background-geolocation
// plugin — an Android foreground service with a visible notification, iOS "Always" location — so they keep arriving
// with the screen off or another app in front. They are queued on the phone (survives no network / app restarts) and
// sent in batches; the server answers `tracking:false` when the trip ends and the watcher stops.
// Honest limit: if the driver force-closes the app (swipe away / Force stop), the OS stops it — the dispatcher's map
// then shows the phone as "stale" while the truck's own GPS (Wialon) keeps reporting.
import { reactive } from 'vue';
import { registerPlugin } from '@capacitor/core';
import { api } from '@/api/client';
import { isNative, platform } from './native';

const QUEUE_KEY = 'scm.trackQueue';
const QUEUE_MAX = 3000;
const FLUSH_EVERY_MS = 30_000;
const FLUSH_AT = 10;
const POLL_MS = 60_000;

export const tracking = reactive({
  supported: isNative,
  state: null,            // server answer: { tracking, needsConsent, trip, ... }
  running: false,         // watcher active on this phone
  permission: 'unknown',  // unknown | granted | denied
  queued: 0,
  lastSentAt: null,
  error: null,
});

let BG = null;
let watcherId = null;
let pollTimer = null;
let lastFlush = 0;
let flushing = false;
let started = false;

const readQueue = () => { try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); } catch { return []; } };
const writeQueue = (q) => { try { localStorage.setItem(QUEUE_KEY, JSON.stringify(q.slice(-QUEUE_MAX))); } catch { /* storage full: keep going */ } tracking.queued = Math.min(q.length, QUEUE_MAX); };

async function flush(force = false) {
  if (flushing) return;
  const q = readQueue();
  if (!q.length || (!force && q.length < FLUSH_AT && Date.now() - lastFlush < FLUSH_EVERY_MS)) return;
  flushing = true;
  try {
    const batch = q.slice(0, 500);
    const r = await api.post('/delivery/tracking/points', { platform, points: batch });
    writeQueue(readQueue().slice(batch.length));
    lastFlush = Date.now();
    tracking.lastSentAt = new Date().toISOString();
    tracking.error = null;
    if (r && r.tracking === false) await stopWatcher();
  } catch (e) {
    tracking.error = e?.message || String(e); // kept in the queue, retried on the next fix / poll
  } finally { flushing = false; }
}

function onLocation(location, error) {
  if (error) {
    if (error.code === 'NOT_AUTHORIZED') { tracking.permission = 'denied'; void stopWatcher(); }
    else tracking.error = error.message || String(error.code);
    return;
  }
  if (!location) return;
  tracking.permission = 'granted';
  const q = readQueue();
  q.push({ lat: location.latitude, lng: location.longitude, accuracy: location.accuracy, speed: location.speed ?? null, heading: location.bearing ?? null, at: location.time || Date.now() });
  writeQueue(q);
  void flush();
}

async function startWatcher() {
  if (watcherId || !BG) return;
  watcherId = await BG.addWatcher({
    backgroundTitle: 'B2B ops — تتبع الرحلة',
    backgroundMessage: 'يُرسل موقعك أثناء الرحلة فقط ويتوقف تلقائيًا عند انتهائها',
    requestPermissions: true,
    stale: false,
    distanceFilter: tracking.state?.distanceFilterMetres ?? 25,
  }, onLocation);
  tracking.running = true;
}

async function stopWatcher() {
  if (watcherId && BG) { try { await BG.removeWatcher({ id: watcherId }); } catch { /* already gone */ } }
  watcherId = null;
  tracking.running = false;
  void flush(true);
}

/** Asks the server what to do and starts / stops the watcher accordingly. */
export async function refreshTracking() {
  if (!isNative) return;
  try {
    tracking.state = await api.get('/delivery/tracking');
  } catch (e) {
    tracking.error = e?.message || String(e);
    return; // offline: keep the current state, the queue waits
  }
  if (tracking.state.tracking && tracking.permission !== 'denied') await startWatcher();
  else if (!tracking.state.tracking && watcherId) await stopWatcher();
  void flush(true);
}

export async function acceptTracking() {
  tracking.state = await api.post('/delivery/tracking/consent', { accepted: true });
  tracking.permission = 'unknown';
  await refreshTracking();
}

export async function withdrawTracking() {
  await stopWatcher();
  tracking.state = await api.post('/delivery/tracking/consent', { accepted: false });
}

export const openLocationSettings = () => BG?.openSettings?.();

/** Called once by the shell for a signed-in driver in the native app. */
export async function startDriverTracking() {
  if (!isNative || started) return;
  started = true;
  BG = registerPlugin('BackgroundGeolocation');
  tracking.queued = readQueue().length;
  const { App } = await import('@capacitor/app');
  App.addListener('appStateChange', ({ isActive }) => { if (isActive) { if (tracking.permission === 'denied') tracking.permission = 'unknown'; void refreshTracking(); } });
  pollTimer = setInterval(() => { void refreshTracking(); }, POLL_MS);
  await refreshTracking();
}

export function stopDriverTracking() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
  started = false;
  void stopWatcher();
}
