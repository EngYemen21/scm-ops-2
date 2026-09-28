<script setup>
// One trip on the map: warehouse → stops in sequence → back, the drive line / distance / time from the maps provider,
// the last GPS fix a driver's phone recorded, and "optimise stop order" (trip.manage, planning stage only).
//   <TripMap :number="trip.number" :height="230" />
import { computed } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, MapView } from '@/components';
import { fmtAgo, fmtNum, lang, t } from '@/i18n';
import { STOP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { STOP_DOT } from './tms';

const props = defineProps({
  number: { type: String, required: true },
  height: { type: Number, default: 230 },
});

const auth = useAuth();
const act = useAction();
const q = useGet(() => `/transport/trips/${encodeURIComponent(props.number)}/map`, null, { staleTime: 15_000, refetchInterval: (query) => (['dispatched', 'onroute', 'partial', 'returning'].includes(query.state.data?.status) ? 20_000 : false) });
const d = computed(() => q.data.value);
const route = computed(() => d.value?.route);
const label = (map, k) => (map[k] ? (lang.value === 'en' ? map[k].en : map[k].ar) : k);

const markers = computed(() => {
  const x = d.value;
  if (!x) return [];
  const out = [];
  if (x.origin) out.push({ id: 'origin', kind: 'warehouse', lat: x.origin.lat, lng: x.origin.lng, text: x.origin.code, title: { ar: `مستودع ${x.origin.nameAr}`, en: `${x.origin.nameEn} warehouse` }, sub: t('نقطة الانطلاق والعودة', 'Start and return point') });
  for (const s of x.stops) {
    out.push({
      id: s.id, kind: 'stop', lat: s.lat, lng: s.lng, text: String(s.seq), color: STOP_DOT[s.status], title: (lang.value === 'en' && s.customerEn) || s.customerAr,
      sub: [label(STOP_LABELS, s.status), s.window && `${t('نافذة التسليم', 'Window')}: ${s.window}`, s.address].filter(Boolean),
    });
  }
  if (x.lastFix) out.push({ id: 'fix', kind: 'fix', lat: x.lastFix.lat, lng: x.lastFix.lng, title: t('آخر موقع سجّله جوال السائق', 'Last fix from the driver phone'), sub: [t('عند إثبات التسليم', 'At proof of delivery'), fmtAgo(x.lastFix.at)] });
  if (x.vehicle) out.push({ id: 'vehicle', kind: x.vehicle.gpsOnline ? 'vehicle' : 'vehicle off', lat: x.vehicle.lat, lng: x.vehicle.lng, text: '🚚', title: `${x.vehicle.code}${x.vehicle.plateAr ? ` · ${x.vehicle.plateAr}` : ''}`, sub: [x.vehicle.gpsOnline ? `${t('متصلة', 'Online')} · ${fmtNum(x.vehicle.speedKph ?? 0)} ${t('كم/س', 'km/h')}` : t('غير متصلة', 'Offline'), x.vehicle.at && `${t('آخر موقع', 'Last fix')} ${fmtAgo(x.vehicle.at)}`].filter(Boolean) });
  const ph = x.phone;
  if (ph?.lat != null) out.push({ id: 'phone', kind: ph.status === 'live' ? 'phone' : 'phone off', lat: ph.lat, lng: ph.lng, text: '📱', title: `${t('جوال السائق', 'Driver phone')} · ${ph.driverAr}`, sub: [phoneLine.value, ph.at && `${t('آخر موقع', 'Last fix')} ${fmtAgo(ph.at)}`].filter(Boolean) });
  return out;
});

/** One honest line about the driver's phone on this trip (tracking state + distance from the truck). */
const phoneLine = computed(() => {
  const ph = d.value?.phone;
  if (!ph) return null;
  const base = { live: t('يعمل', 'live'), stale: t('توقف الإرسال', 'stopped reporting'), waiting: t('بانتظار أول موقع', 'waiting for a first fix'), no_consent: t('لم يوافق السائق على التتبع بعد', 'driver has not accepted tracking') }[ph.status] || ph.status;
  const speed = ph.status === 'live' && ph.speedKph != null ? ` · ${fmtNum(ph.speedKph)} ${t('كم/س', 'km/h')}` : '';
  const apart = ph.metresFromTruck != null ? ` · ${t('يبعد عن الشاحنة', 'from the truck')} ${ph.metresFromTruck >= 1000 ? `${fmtNum(ph.metresFromTruck / 1000, 1)} ${t('كم', 'km')}` : `${fmtNum(ph.metresFromTruck)} ${t('م', 'm')}`}` : '';
  return `${base}${speed}${apart}`;
});

/** Provider line when there is one; otherwise straight dashed links between the located points (clearly not a road). */
const lines = computed(() => {
  const x = d.value;
  if (!x) return [];
  const trail = (x.trail || []).map((p) => [p.lng, p.lat]);
  // the phone trail snapped to the streets by the server; the recorded fix-to-fix line only when that is missing
  const phoneTrail = (x.phoneTrail || []).map((p) => [p.lng, p.lat]);
  const phoneLines = x.phoneRoute?.lines?.length ? x.phoneRoute.lines : [phoneTrail];
  const trailLine = [
    ...(trail.length > 1 ? [{ id: 'trail', geometry: { type: 'LineString', coordinates: trail }, color: '#3C79F5', width: 3 }] : []),
    ...phoneLines.filter((l) => l.length > 1).map((coordinates, i) => ({ id: `phone-trail-${i}`, geometry: { type: 'LineString', coordinates }, color: '#1d7a3e', width: 4 })),
  ];
  if (route.value?.status === 'ok' && route.value.geometry) return [{ id: 'route', geometry: route.value.geometry }, ...trailLine];
  const pts = [x.origin, ...x.stops].filter((p) => p && p.lat != null).map((p) => [p.lng, p.lat]);
  return [...(pts.length > 1 ? [{ id: 'links', geometry: { type: 'LineString', coordinates: pts }, dashed: true, color: '#7d7990' }] : []), ...trailLine];
});

const duration = computed(() => {
  const m = route.value?.minutes;
  if (m == null) return null;
  return m >= 60 ? t(`${Math.floor(m / 60)} س ${m % 60} د`, `${Math.floor(m / 60)} h ${m % 60} min`) : t(`${m} دقيقة`, `${m} min`);
});
const canManage = computed(() => auth.can('trip.manage'));

async function optimize() {
  const ok = await confirm({
    title: { ar: 'تحسين ترتيب المحطات', en: 'Optimise stop order' },
    sub: { ar: 'سيُحسب أقصر مسار ينطلق من المستودع ويعود إليه، ثم يُعاد ترتيب محطات الرحلة وفقه. يمكنك إعادة الترتيب يدويًا بعد ذلك.', en: 'The shortest round trip from the warehouse is computed and the stops are re-sequenced accordingly. You can still reorder manually afterwards.' },
    okLabel: { ar: 'حسّن الترتيب', en: 'Optimise' }, tone: 'primary',
  });
  if (!ok) return;
  await act.run(() => api.post(`/transport/trips/${encodeURIComponent(props.number)}/optimize`), {
    success: (r) => (r.changed
      ? t(`أُعيد ترتيب المحطات — ${fmtNum(r.totalKm, 1)} كم`, `Stops re-sequenced — ${fmtNum(r.totalKm, 1)} km`)
      : t('الترتيب الحالي هو الأفضل ✓', 'The current order is already the best ✓')),
    invalidate: ['transport'],
  });
}
</script>

<template>
  <div>
    <MapView :height="height" :markers="markers" :lines="lines" :fit-key="`${number}|${d ? d.stops.map((s) => s.id).join() : ''}`" :pending-label="{ ar: `خريطة المسار — ${number}`, en: `Route map — ${number}` }" />

    <div v-if="d" class="row wrap mt-2 !gap-x-3 !gap-y-1.5 text-[10px]">
      <template v-if="route?.status === 'ok'">
        <span class="font-extrabold"><span class="num">{{ fmtNum(route.distanceKm, 1) }}</span> {{ t('كم', 'km') }}</span>
        <span class="font-extrabold">{{ duration }}</span>
        <span class="text-faint">{{ t('ذهابًا وعودة للمستودع · حسب حركة المرور الآن', 'Round trip from the warehouse · with current traffic') }}</span>
      </template>
      <span v-else-if="route?.status === 'integration_pending'" class="text-faint">{{ t('المسافة والزمن: Integration Pending — مزود الخرائط غير مربوط', 'Distance & time: Integration Pending — no maps provider') }}</span>
      <span v-else-if="route?.status === 'no_coordinates'" class="text-warn">{{ t('لا توجد مواقع كافية على الخريطة لحساب المسار', 'Not enough located points to compute a route') }}</span>
      <span v-else-if="route?.status === 'error'" class="text-bad">{{ t('تعذّر حساب المسار من مزود الخرائط', 'The maps provider could not compute the route') }}</span>

      <span v-if="d.vehicle" class="font-bold" :class="d.vehicle.gpsOnline ? 'text-ok' : 'text-muted'">🚚 {{ d.vehicle.code }} · {{ d.vehicle.gpsOnline ? `${fmtNum(d.vehicle.speedKph ?? 0)} ${t('كم/س', 'km/h')}` : t('غير متصلة', 'offline') }}{{ d.vehicle.at ? ` · ${fmtAgo(d.vehicle.at)}` : '' }}</span>
      <span v-if="d.phone" class="font-bold" :class="d.phone.status === 'live' ? 'text-ok' : d.phone.status === 'stale' ? 'text-bad' : 'text-muted'">📱 {{ d.phone.driverAr }} · {{ phoneLine }}</span>
      <span class="flex-1" />
      <Btn v-if="canManage && d.optimize.allowed" tone="softPurple" size="sm" :loading="act.pending.value" :label="{ ar: 'تحسين ترتيب المحطات', en: 'Optimise stop order' }" @click="optimize" />
    </div>

    <div v-if="d?.phone?.apart" class="mt-2 rounded-[10px] bg-[#fdecec] px-3 py-2 text-[10.5px] font-bold text-bad">
      ⚠ {{ t('جوال السائق بعيد عن الشاحنة', 'The driver\'s phone is away from the truck') }} ({{ fmtNum(d.phone.metresFromTruck / 1000, 1) }} {{ t('كم', 'km') }}) — {{ t('تحقّق من السائق', 'check with the driver') }}
    </div>
    <div v-else-if="d?.phone?.status === 'stale'" class="mt-2 rounded-[10px] bg-[#fbf0dd] px-3 py-2 text-[10.5px] font-bold text-warn">
      {{ t('توقف جوال السائق عن إرسال موقعه منذ', 'The driver\'s phone stopped reporting') }} {{ fmtAgo(d.phone.at) }} — {{ t('غالبًا أُغلق التطبيق أو انقطعت الشبكة؛ موقع الشاحنة من جهاز التتبع مستمر.', 'the app was probably closed or offline; the truck GPS keeps reporting.') }}
    </div>
    <div v-if="d?.unlocated.length" class="mt-2 rounded-[10px] bg-[#fbf0dd] px-3 py-2 text-[10px] font-bold text-warn">
      {{ t('محطات بلا موقع على الخريطة', 'Stops without a map location') }}:
      <span v-for="(u, i) in d.unlocated" :key="u.seq"><span class="num">{{ u.seq }}</span> · {{ u.customerAr }}{{ i < d.unlocated.length - 1 ? '، ' : '' }}</span>
      <div class="mt-0.5 font-normal text-muted">{{ t('حدّد موقع العميل من بطاقة العميل (المبيعات ← العملاء ← تعديل).', 'Set the customer location from the customer card (Sales → Customers → Edit).') }}</div>
    </div>
    <div v-else-if="d && canManage && !d.optimize.allowed && d.optimize.code === 'MAP_NO_ORIGIN'" class="mt-2 text-[10px] text-warn">{{ lang === 'en' ? d.optimize.reasonEn : d.optimize.reasonAr }}</div>
  </div>
</template>
