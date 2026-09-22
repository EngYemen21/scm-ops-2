// Live tracking helpers shared by the fleet screens (status of the provider, the "online / offline / unpaired" label
// of a vehicle, and the provider's unit list for pairing).
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { fmtAgo, fmtNum, t } from '@/i18n';

/** Provider status (cached 60 s; every screen shares the same query). */
export function useGpsStatus() {
  const q = useGet('/transport/gps/status', null, { staleTime: 60_000 });
  return { q, status: computed(() => q.data.value), configured: computed(() => !!q.data.value?.configured) };
}

/** The provider's units with the vehicle each one is paired to — only fetched when `enabled` (needs vehicle.manage). */
export function useGpsUnits(enabled) {
  return useGet('/transport/gps/units', null, { enabled, staleTime: 30_000, retry: false });
}

/**
 * One-line GPS state of a vehicle row: { text, color, online }.
 * unpaired → "غير مقترن"; paired but never seen → "غير مطابق"; seen → "متصل · 42 كم/س · قبل 2 د" or "غير متصل · قبل 3 س".
 */
export function gpsLabel(v, configured) {
  if (!configured) return { text: t('Integration Pending', 'Integration Pending'), color: '#a8a4b8', online: false };
  if (!v.gpsDeviceId) return { text: t('غير مقترن بجهاز', 'Not paired'), color: '#a8a4b8', online: false };
  if (!v.gpsAt) return { text: t(`غير مطابق: ${v.gpsDeviceId}`, `No unit matches: ${v.gpsDeviceId}`), color: '#b26a16', online: false };
  const ago = fmtAgo(v.gpsAt);
  return v.gpsOnline
    ? { text: `${t('متصل', 'Online')} · ${fmtNum(v.speedKph ?? 0)} ${t('كم/س', 'km/h')} · ${ago}`, color: '#1d7a3e', online: true }
    : { text: `${t('غير متصل', 'Offline')} · ${ago}`, color: '#7d7990', online: false };
}
