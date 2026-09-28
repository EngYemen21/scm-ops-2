<script setup>
// Vehicle drawer · "Live" tab: the provider's last fix on a map with the trail of the last hours, and pairing with the
// provider's unit (vehicle.manage). Every position shown was reported by the provider; nothing is estimated.
//   <VehicleLiveTab :vehicle="v" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, MapView, SelectInput } from '@/components';
import { fmtAgo, fmtDate, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { gpsLabel, useGpsStatus, useGpsUnits } from './gps';

const props = defineProps({ vehicle: { type: Object, required: true } });

const auth = useAuth();
const act = useAction();
const { status, configured } = useGpsStatus();
const canManage = computed(() => auth.can('vehicle.manage'));
const hours = ref(12);
const code = computed(() => props.vehicle.code);
const trail = useGet(() => `/transport/vehicles/${encodeURIComponent(code.value)}/trail`, () => ({ hours: hours.value }), { refetchInterval: 30_000, enabled: configured });
const d = computed(() => trail.data.value);
const live = computed(() => d.value?.vehicle || props.vehicle);
const label = computed(() => gpsLabel({ gpsDeviceId: live.value.deviceRef ?? props.vehicle.gpsDeviceId, gpsAt: live.value.at ?? props.vehicle.gpsAt, gpsOnline: live.value.gpsOnline ?? props.vehicle.gpsOnline, speedKph: live.value.speedKph ?? props.vehicle.speedKph }, configured.value));

const markers = computed(() => {
  const v = live.value;
  if (v.lat == null || v.lng == null) return [];
  return [{ id: 'v', kind: 'vehicle', lat: v.lat, lng: v.lng, text: '🚚', title: `${v.code} · ${v.plateAr || ''}`, sub: [label.value.text, v.at ? fmtDate(v.at) : null].filter(Boolean) }];
});
// the recorded track snapped to the streets by the server (pieces break at gaps); the fix-to-fix line only without it
const lines = computed(() => {
  const pts = (d.value?.trail || []).map((p) => [p.lng, p.lat]);
  const route = d.value?.trailRoute?.lines?.length ? d.value.trailRoute.lines : [pts];
  return route.filter((l) => l.length > 1).map((coordinates, i) => ({ id: `trail-${i}`, geometry: { type: 'LineString', coordinates }, color: '#3C79F5', width: 4 }));
});

// ── pairing ──
const pairing = ref(false);
const pick = ref('');
const units = useGpsUnits(computed(() => configured.value && canManage.value && pairing.value));
const unitOpts = computed(() => (units.data.value?.units || []).map((u) => [u.uid || u.name || u.id, `${u.name}${u.uid ? ` · ${u.uid}` : ''}${u.vehicle && u.vehicle !== code.value ? ` — ${t('مقترنة بـ', 'paired to')} ${u.vehicle}` : ''}`]));
watch(pairing, (on) => { if (on) pick.value = props.vehicle.gpsDeviceId || ''; });
async function savePairing(ref) {
  const r = await act.run(() => api.patch(`/transport/vehicles/${encodeURIComponent(code.value)}`, { gpsDeviceId: ref || null }), {
    success: ref ? { ar: 'اقترنت المركبة بالوحدة — تُحدَّث المواقع خلال دقيقة', en: 'Paired — positions update within a minute' } : { ar: 'أُلغي الاقتران', en: 'Unpaired' }, invalidate: ['transport'],
  });
  if (r !== undefined) { pairing.value = false; if (ref) void api.post('/transport/gps/sync').catch(() => {}); }
}
const syncNow = () => act.run(() => api.post('/transport/gps/sync'), { success: (r) => (r.ok ? t(`حُدّثت المواقع — ${r.matched} مركبة مطابقة`, `Positions refreshed — ${r.matched} vehicles matched`) : t('تعذّرت المزامنة', 'Sync failed')), invalidate: ['transport'] });
</script>

<template>
  <div class="col !gap-3">
    <div v-if="!configured" class="rounded-[12px] bg-[#EEF1F6] px-3.5 py-3 text-[10.5px]">
      <div class="font-extrabold text-[#0d5866]">{{ t('التتبع الحي — Integration Pending', 'Live tracking — Integration Pending') }}</div>
      <div class="mt-0.5 text-muted">{{ t('يتطلب ضبط WIALON_TOKEN على الخادم (راجع RUNBOOK). بعدها تظهر هنا مواقع المركبة لحظيًا مع مسار آخر الساعات.', 'Needs WIALON_TOKEN on the server (see RUNBOOK). The vehicle position and its recent trail then appear here.') }}</div>
    </div>

    <template v-else>
      <div class="row wrap !gap-2 text-[10.5px]">
        <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ background: label.color }" />
        <span class="font-extrabold" :style="{ color: label.color }">{{ label.text }}</span>
        <span v-if="live.unit" class="text-faint">· {{ t('الوحدة', 'unit') }}: <bdi dir="ltr">{{ live.unit }}</bdi></span>
        <span class="flex-1" />
        <Btn v-if="canManage" tone="soft" size="sm" :loading="act.pending.value" :label="{ ar: 'تحديث الآن', en: 'Refresh now' }" @click="syncNow" />
        <Btn v-if="canManage" tone="softPurple" size="sm" :label="vehicle.gpsDeviceId ? { ar: 'تغيير الاقتران', en: 'Change pairing' } : { ar: 'اقتران بجهاز', en: 'Pair a unit' }" @click="pairing = !pairing" />
      </div>

      <div v-if="pairing" class="rounded-[12px] border border-line bg-soft p-3">
        <div class="mb-2 text-[10px] font-extrabold text-muted">{{ t('اختر وحدة التتبع من حساب Wialon لهذه المركبة', 'Pick this vehicle\'s tracking unit from the Wialon account') }}</div>
        <div v-if="units.isLoading.value" class="skel h-8" />
        <div v-else-if="units.error.value" class="text-[10px] text-bad">{{ t('تعذّر جلب الوحدات من المزود', 'Could not load the units from the provider') }}</div>
        <template v-else>
          <SelectInput v-model="pick" :options="unitOpts" :placeholder="{ ar: '— اختر وحدة —', en: '— pick a unit —' }" full />
          <div class="row mt-2 !gap-2">
            <Btn tone="primary" size="sm" :disabled="!pick" :loading="act.pending.value" :label="{ ar: 'حفظ الاقتران', en: 'Save pairing' }" @click="savePairing(pick)" />
            <Btn v-if="vehicle.gpsDeviceId" tone="dangerOutline" size="sm" :label="{ ar: 'إلغاء الاقتران', en: 'Unpair' }" @click="savePairing('')" />
            <Btn tone="soft" size="sm" :label="{ ar: 'إغلاق', en: 'Close' }" @click="pairing = false" />
          </div>
        </template>
      </div>

      <template v-if="vehicle.gpsDeviceId">
        <MapView :height="260" :markers="markers" :lines="lines" :fit-key="`${code}|${d?.hours ?? ''}`" :pending-label="{ ar: 'خريطة المركبة', en: 'Vehicle map' }" />
        <div class="row wrap !gap-x-3 !gap-y-1 text-[10px] text-muted">
          <span v-if="live.at">{{ t('آخر موقع', 'Last fix') }}: <span class="num">{{ fmtDate(live.at) }}</span> ({{ fmtAgo(live.at) }})</span>
          <span v-if="live.speedKph != null">{{ t('السرعة', 'Speed') }}: <span class="num">{{ fmtNum(live.speedKph) }}</span> {{ t('كم/س', 'km/h') }}</span>
          <span v-if="live.course != null">{{ t('الاتجاه', 'Heading') }}: <span class="num">{{ live.course }}°</span></span>
          <span class="flex-1" />
          <span class="row !gap-1">{{ t('المسار خلال', 'Trail') }}
            <button v-for="h in [3, 12, 48]" :key="h" type="button" class="num cursor-pointer rounded-md px-1.5 py-0.5 text-[9.5px] font-bold" :class="hours === h ? 'bg-ink text-white' : 'bg-soft'" @click="hours = h">{{ h }} {{ t('س', 'h') }}</button>
          </span>
          <span v-if="d && !d.trail.length" class="basis-full text-faint">{{ t('لا مواقع مسجّلة في هذه المدة', 'No positions recorded in this window') }}</span>
        </div>
      </template>
      <div v-else class="text-[10.5px] text-faint">{{ t('المركبة غير مقترنة بوحدة تتبع بعد.', 'This vehicle is not paired with a tracking unit yet.') }}</div>
      <div v-if="status?.lastSync && !status.lastSync.ok" class="text-[10px] text-bad">{{ t('آخر مزامنة فشلت', 'Last sync failed') }}: {{ status.lastSync.detail }}</div>
    </template>
  </div>
</template>
