<script setup>
// Dark header grid of the Trip Control Room: vehicle / driver / warehouse / route / dispatch / planned arrival / planned
// return / progress / km. The arrival shown is the PLAN (stops' `eta`: maps or estimate) — never presented as tracking;
// live vehicle tracking is "Integration Pending" until a GPS provider is connected. Extra classes fall through to the root.
import { computed } from 'vue';
import { bi, fmtDate, fmtNum, lang, t } from '@/i18n';
import { useGpsStatus } from './gps';
import { ETA_SOURCE_LABELS, drvName, labelOf, plannedEta, tripProgress, vehName } from './tms';

const { configured: gpsConfigured } = useGpsStatus();

const props = defineProps({ trip: { type: Object, required: true } });

const plan = computed(() => plannedEta(props.trip));
const head = computed(() => {
  const trip = props.trip;
  const prog = tripProgress(trip);
  const p = plan.value;
  return [
    { k: t('المركبة', 'Vehicle'), v: vehName(trip.vehicle) }, { k: t('السائق', 'Driver'), v: drvName(trip.driver, lang.value) },
    { k: t('المستودع', 'Warehouse'), v: trip.warehouse ? `${trip.warehouse.code} · ${trip.warehouse.nameAr}` : '—' }, { k: t('المسار', 'Route'), v: (lang.value === 'en' && trip.routeEn) || trip.routeAr || '—' },
    { k: t('الإرسال', 'Dispatch'), v: trip.dispatchedAt ? fmtDate(trip.dispatchedAt) : trip.dispatch ? fmtDate(trip.dispatch.dispatchedAt) : t('لم تُرسل بعد', 'Not dispatched') },
    { k: t('الوصول المخطط — المحطة التالية', 'Planned arrival — next stop'), v: p.next ? `${p.next}${p.source ? ` (${bi(labelOf(ETA_SOURCE_LABELS, p.source))})` : ''}` : '—', warn: p.late > 0 ? t(`${p.late} خارج النافذة`, `${p.late} outside window`) : null },
    { k: t('العودة المخططة', 'Planned return'), v: trip.plannedEnd || '—' },
    { k: t('التقدم', 'Progress'), v: `${prog.done} / ${prog.total} · ${prog.pct}%` }, { k: t('المسافة', 'Distance'), v: `${fmtNum(trip.km)} ${t('كم', 'km')}` },
  ];
});
</script>

<template>
  <div class="bg-night px-[22px] pb-4 text-white">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(140px,1fr))] gap-2">
      <div v-for="(h, i) in head" :key="i">
        <div class="text-[8.5px] font-extrabold text-[#8b90a5]">{{ h.k }}</div>
        <div class="mt-0.5 text-[10.5px] font-bold leading-[1.5]"><span dir="auto">{{ h.v }}</span><span v-if="h.warn" class="text-[#ff9c9c]"> · {{ h.warn }}</span></div>
      </div>
    </div>
    <div class="mt-2.5 text-[9px] text-[#7FD6E5]">{{ gpsConfigured ? t('موقع المركبة الحي على الخريطة أدناه (يُحدَّث كل 20 ثانية) · أوقات الوصول المعروضة مخططة (خرائط أو تقدير) وليست تتبعًا حيًا', 'Live vehicle position on the map below (refreshed every 20 s) · the arrival times shown are planned (maps or estimate), not live tracking') : t('التتبع الحي للمركبة — Integration Pending (يتطلب مزود GPS / Telematics) · أوقات الوصول المعروضة مخططة (خرائط أو تقدير) · المسار والمسافة على الخريطة أدناه', 'Live vehicle tracking — Integration Pending (needs a GPS / telematics provider) · the arrival times shown are planned (maps or estimate) · route and distance are on the map below') }}</div>
  </div>
</template>
