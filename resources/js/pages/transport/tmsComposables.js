// Transport composables (the hook half of the reference `tms.tsx`): warehouse / vehicle / driver select options and the
// close / cancel actions of a loaded trip. Call them from `<script setup>` only.
import { computed, toValue } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm, toast } from '@/stores/ui';
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

  /**
   * Close. What really happens (TripsService::close): the trip is closed, the vehicle goes back to the warehouse, its
   * odometer is set (the reading typed here, else the planned distance is added), the driver's figures are updated and
   * the cost sheet is computed from what is recorded — the toast is the server's own sentence, which only claims a
   * computed cost when there is one. A costing problem never blocks the close.
   */
  async function closeTrip() {
    const x = cur();
    if (!x) return;
    const sub = {
      ar: 'تُقفل الرحلة وتعود المركبة للمستودع ويُحدَّث أداء السائق. تُحسب التكلفة من المسجَّل فعلًا (وقود، ساعات السائق، سندات الرحلة) — وما ليس له مصدر يظهر «غير مُعدّ» في تبويب التكلفة. لا يمكن التراجع.',
      en: 'The trip is closed, the vehicle returns to the warehouse and the driver figures are updated. The cost is computed from what is actually recorded (fuel, driver hours, trip vouchers) — a component with no source shows "not configured" in the Cost tab. This cannot be undone.',
    };
    const body = {};
    if (x.vehicle) {
      const current = Number(x.vehicle.odometer ?? 0);
      const typed = await ask({ title: { ar: 'إقفال الرحلة؟', en: 'Close trip?' }, sub, label: { ar: `قراءة عداد ${x.vehicle.code} عند العودة (اختياري — الحالي ${current})`, en: `${x.vehicle.code} odometer at return (optional — now ${current})` }, placeholder: { ar: 'اتركه فارغًا لإضافة مسافة الرحلة المخططة', en: 'Leave empty to add the planned trip distance' }, tone: 'dark', okLabel: { ar: 'إقفال', en: 'Close' } });
      if (typed === null) return;
      if (typed !== '') {
        const reading = Number(String(typed).replace(/[,\s]/g, ''));
        if (!Number.isInteger(reading) || reading < current) {
          toast.say({ ar: `قراءة العداد يجب أن تكون رقمًا صحيحًا لا يقل عن ${current}`, en: `The odometer reading must be a whole number of at least ${current}` }, 3200);
          return;
        }
        body.odometer = reading;
      }
    } else if (!(await confirm({ title: { ar: 'إقفال الرحلة؟', en: 'Close trip?' }, sub, tone: 'dark', okLabel: { ar: 'إقفال', en: 'Close' } }))) return;
    await act.run(() => api.postIdempotent(`/transport/trips/${x.number}/close`, body), { success: (r) => (lang.value === 'en' && r?.messageEn) || r?.message || t('أُقفلت الرحلة', 'Trip closed'), invalidate: ['transport', 'fulfillment', 'delivery'] });
  }
  /**
   * Cancel (planning stage only). The orders are released from the trip and stay as they are — packed / picked — ready
   * for another trip; nothing "returns to a queue" and nothing loaded is touched: a trip with loaded orders is refused
   * by the server until they are unloaded.
   */
  async function cancelTrip() {
    const x = cur();
    if (!x) return;
    const reason = await ask({ title: { ar: `إلغاء الرحلة ${x.number}؟`, en: `Cancel trip ${x.number}?` }, sub: { ar: 'تُفك طلبات الرحلة عنها وتبقى بحالتها (مجهَّزة / مُلتقطة) لتُخطَّط على رحلة أخرى، وتعود المركبة «متاحة». لا تُلغى رحلة عليها طلبات محمَّلة — أنزل الحمولة أولًا. لا يمكن التراجع.', en: 'The trip\'s orders are released and stay as they are (packed / picked) to be planned on another trip, and the vehicle becomes available. A trip with loaded orders cannot be cancelled — unload first. This cannot be undone.' }, label: { ar: 'سبب الإلغاء', en: 'Cancel reason' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء الرحلة', en: 'Cancel trip' }, cancelLabel: { ar: 'تراجع', en: 'Back' } });
    if (!reason) return;
    await act.run(() => api.postIdempotent(`/transport/trips/${x.number}/cancel`, { reason }), { success: (r) => r?.message || t('أُلغيت الرحلة', 'Trip cancelled'), invalidate: ['transport', 'fulfillment'] });
  }
  return { act, canClose, canCancel, closeTrip, cancelTrip };
}
