// Transport composables (the hook half of the reference `tms.tsx`): warehouse / vehicle / driver select options and the
// close / cancel actions of a loaded trip. Call them from `<script setup>` only.
import { computed, toValue } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import { ENDED_TRIP } from './tms';

/** Select options of the user's warehouses: `[code, 'RYD · الرياض المركزي']`. */
export function useWarehouseOptions() {
  const wh = useWarehouse();
  return computed(() => wh.warehouses.map((w) => [w.code, `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}`]));
}

/** Vehicle select options, loaded only while `enabled` (ref / getter) is true — i.e. while the form is open. */
export function useVehicleOpts(enabled) {
  const vehicles = useList('/transport/vehicles', { pageSize: 100 }, { enabled: () => !!toValue(enabled) });
  return computed(() => (vehicles.data.value?.items || []).map((v) => [v.code, `${v.code} · ${v.plateAr}`]));
}

/** Driver select options, loaded only while `enabled` is true. */
export function useDriverOpts(enabled) {
  const drivers = useList('/transport/drivers', { pageSize: 100 }, { enabled: () => !!toValue(enabled) });
  return computed(() => (drivers.data.value?.items || []).map((d) => [d.code, `${d.nameAr} · ${d.code}`]));
}

/**
 * Close / cancel actions + permission gates for a loaded trip (shared by the drawer and the full page).
 * @param {import('vue').MaybeRefOrGetter<object|null|undefined>} trip
 */
export function useTripActions(trip) {
  const auth = useAuth();
  const act = useAction();
  const cur = () => toValue(trip);
  const canClose = computed(() => {
    const x = cur(); const st = x?.status || '';
    return !!x && auth.can('trip.close') && !ENDED_TRIP.includes(st) && ['completed', 'partial', 'returning', 'onroute', 'dispatched'].includes(st);
  });
  const canCancel = computed(() => { const x = cur(); return !!x && auth.can('trip.manage') && !!x.canEdit; });

  async function closeTrip() {
    const x = cur();
    if (!x) return;
    if (!(await confirm({ title: { ar: 'إقفال الرحلة؟', en: 'Close trip?' }, sub: { ar: 'سيتم احتساب التكلفة وإعادة المركبة للمستودع. لا يمكن التراجع.', en: 'Cost is computed and the vehicle returns to the warehouse. This cannot be undone.' }, tone: 'dark', okLabel: { ar: 'إقفال', en: 'Close' } }))) return;
    await act.run(() => api.postIdempotent(`/transport/trips/${x.number}/close`), { success: (r) => r?.message || t('أُقفلت الرحلة', 'Trip closed'), invalidate: ['transport', 'fulfillment', 'delivery'] });
  }
  async function cancelTrip() {
    const x = cur();
    if (!x) return;
    const reason = await ask({ title: { ar: `إلغاء الرحلة ${x.number}؟`, en: `Cancel trip ${x.number}?` }, sub: { ar: 'تعود الطلبات المحمَّلة إلى قائمة الإرسال. لا يمكن التراجع.', en: 'Its orders go back to the dispatch queue. This cannot be undone.' }, label: { ar: 'سبب الإلغاء', en: 'Cancel reason' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء الرحلة', en: 'Cancel trip' }, cancelLabel: { ar: 'تراجع', en: 'Back' } });
    if (!reason) return;
    await act.run(() => api.postIdempotent(`/transport/trips/${x.number}/cancel`, { reason }), { success: (r) => r?.message || t('أُلغيت الرحلة', 'Trip cancelled'), invalidate: ['transport', 'fulfillment'] });
  }
  return { act, canClose, canCancel, closeTrip, cancelTrip };
}
