// Driver app ("My Trips") helpers: browser geolocation capture, the driver-facing stop labels and the big touch buttons.
import { CLOSED_STOP } from './tms';

/**
 * One-shot position of the phone (the browser's own geolocation — NOT vehicle telematics, which is not connected).
 * Never rejects. @returns {Promise<{ status: 'captured'|'denied'|'unavailable', gps?: { lat: number, lng: number, accuracy?: number } }>}
 */
export function captureGps() {
  return new Promise((resolve) => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) { resolve({ status: 'unavailable' }); return; }
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ status: 'captured', gps: { lat: +p.coords.latitude.toFixed(6), lng: +p.coords.longitude.toFixed(6), accuracy: Math.round(p.coords.accuracy) } }),
      (e) => resolve({ status: e.code === 1 ? 'denied' : 'unavailable' }),
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 30_000 },
    );
  });
}

/** Stop chip as the driver sees it ('next' = the pending stop that is next in sequence). */
export const STOP_DRIVER_LABELS = {
  delivered: { ar: 'مسلّم ✓', en: 'Delivered ✓', fg: '#1d7a3e', bg: '#e6f9ec' }, partial: { ar: 'جزئي', en: 'Partial', fg: '#b26a16', bg: '#fbf0dd' },
  failed: { ar: 'فشل — يُعاد للمستودع', en: 'Failed — back to WH', fg: '#b23b3b', bg: '#fdecec' }, rejected: { ar: 'رفض العميل', en: 'Customer rejected', fg: '#b23b3b', bg: '#fdecec' },
  arrived: { ar: 'وصلت — سلّم الآن', en: 'Arrived — deliver now', fg: '#b26a16', bg: '#fbf0dd' }, next: { ar: 'التالي', en: 'Next', fg: '#3C79F5', bg: '#e8effe' }, pending: { ar: 'بالانتظار', en: 'Pending', fg: '#a8a4b8', bg: '#F1EFF6' }, skipped: { ar: 'تخطي', en: 'Skipped', fg: '#7d7990', bg: '#F1EFF6' },
};
/** A stop is closed once it has an outcome — it can never get a second POD. */
export const isClosedStop = (s) => CLOSED_STOP.includes(s.status);

/** Big touch button (mobile-first): add height / width / radius / colours / text size per use. */
export const BIG = 'flex cursor-pointer items-center justify-center font-extrabold disabled:cursor-not-allowed';
