<script setup>
// Dark header grid of the Trip Control Room: vehicle / driver / warehouse / route / dispatch / ETA / progress / km.
// ETA and live tracking are "Integration Pending" — never fabricated. Extra classes (padding) fall through to the root.
import { computed } from 'vue';
import { fmtDate, fmtNum, lang, t } from '@/i18n';
import { drvName, etaText, tripProgress, vehName } from './tms';

const props = defineProps({ trip: { type: Object, required: true } });

const head = computed(() => {
  const trip = props.trip;
  const prog = tripProgress(trip);
  return [
    { k: t('المركبة', 'Vehicle'), v: vehName(trip.vehicle) }, { k: t('السائق', 'Driver'), v: drvName(trip.driver, lang.value) },
    { k: t('المستودع', 'Warehouse'), v: trip.warehouse ? `${trip.warehouse.code} · ${trip.warehouse.nameAr}` : '—' }, { k: t('المسار', 'Route'), v: (lang.value === 'en' && trip.routeEn) || trip.routeAr || '—' },
    { k: t('الإرسال', 'Dispatch'), v: trip.dispatchedAt ? fmtDate(trip.dispatchedAt) : trip.dispatch ? fmtDate(trip.dispatch.dispatchedAt) : t('لم تُرسل بعد', 'Not dispatched') }, { k: 'ETA', v: etaText(trip) },
    { k: t('التقدم', 'Progress'), v: `${prog.done} / ${prog.total} · ${prog.pct}%` }, { k: t('المسافة', 'Distance'), v: `${fmtNum(trip.km)} ${t('كم', 'km')}` },
  ];
});
</script>

<template>
  <div class="bg-night px-[22px] pb-4 text-white">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(140px,1fr))] gap-2">
      <div v-for="(h, i) in head" :key="i">
        <div class="text-[8.5px] font-extrabold text-[#8b90a5]">{{ h.k }}</div>
        <div class="mt-0.5 text-[10.5px] font-bold leading-[1.5]">{{ h.v }}</div>
      </div>
    </div>
    <div class="mt-2.5 text-[9px] text-[#7FD6E5]">{{ t('التتبع الحي للمركبة و ETA اللحظي — Integration Pending (يتطلب مزود GPS / Telematics) · المسار والمسافة على الخريطة أدناه', 'Live vehicle tracking & live ETA — Integration Pending (needs a GPS / telematics provider) · route and distance are on the map below') }}</div>
  </div>
</template>
