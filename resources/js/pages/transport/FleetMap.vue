<script setup>
// Control-tower / fleet map: warehouses, the open trips' stops, and every position that really exists (a vehicle fix
// from a GPS provider, or the last fix a driver's phone attached to a proof of delivery). Clicking a stop opens its trip.
//   <FleetMap :height="240" :warehouse="wh?.code" @trip="(number) => (trip = number)" />
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { MapView } from '@/components';
import { fmtAgo, fmtNum, lang, t } from '@/i18n';
import { STOP_LABELS, TRIP_LABELS } from '@/shared';
import { STOP_DOT } from './tms';

const props = defineProps({
  height: { type: Number, default: 240 },
  warehouse: { type: String, default: null },
});
const emit = defineEmits(['trip', 'vehicle']);

const q = useGet('/transport/map', () => (props.warehouse ? { warehouse: props.warehouse } : null), { refetchInterval: 20_000 });
const d = computed(() => q.data.value);
const label = (map, k) => (map[k] ? (lang.value === 'en' ? map[k].en : map[k].ar) : k);

const markers = computed(() => {
  const x = d.value;
  if (!x) return [];
  const out = x.warehouses.map((w) => ({ id: `w-${w.code}`, kind: 'warehouse', lat: w.lat, lng: w.lng, text: w.code, title: { ar: `مستودع ${w.nameAr}`, en: `${w.nameEn} warehouse` }, sub: w.city }));
  for (const trip of x.trips) {
    for (const s of trip.stops) {
      out.push({
        id: s.id, kind: 'stop', lat: s.lat, lng: s.lng, text: String(s.seq), color: STOP_DOT[s.status], trip: trip.number,
        title: (lang.value === 'en' && s.customerEn) || s.customerAr, sub: [`${trip.number} · ${label(TRIP_LABELS, trip.status)}`, label(STOP_LABELS, s.status), trip.driverAr].filter(Boolean),
      });
    }
    if (trip.lastFix) out.push({ id: `f-${trip.number}`, kind: 'fix', lat: trip.lastFix.lat, lng: trip.lastFix.lng, trip: trip.number, title: `${trip.number}${trip.vehicle ? ` · ${trip.vehicle}` : ''}`, sub: [t('آخر موقع سجّله جوال السائق عند التسليم', 'Last fix from the driver phone at delivery'), fmtAgo(trip.lastFix.at)] });
  }
  for (const v of x.vehicles) out.push({ id: `v-${v.code}`, kind: v.gpsOnline ? 'vehicle' : 'vehicle off', lat: v.lat, lng: v.lng, text: '🚚', vehicle: v.code, title: `${v.code}${v.plateAr ? ` · ${v.plateAr}` : ''}`, sub: [v.gpsOnline ? `${t('متصلة', 'Online')} · ${fmtNum(v.speedKph ?? 0)} ${t('كم/س', 'km/h')}` : t('غير متصلة', 'Offline'), v.at && `${t('آخر موقع', 'Last fix')} ${fmtAgo(v.at)}`].filter(Boolean) });
  return out;
});
const located = computed(() => markers.value.filter((m) => m.kind === 'stop' && m.lat != null).length);
</script>

<template>
  <div>
    <MapView :height="height" :markers="markers" :fit-key="`${warehouse || 'all'}|${d ? d.trips.length : 0}`" :pending-label="{ ar: 'خريطة الأسطول والرحلات', en: 'Fleet & trips map' }" @marker="(m) => (m.vehicle ? emit('vehicle', m.vehicle) : m.trip && emit('trip', m.trip))" />
    <div v-if="d" class="row wrap mt-2 !gap-x-3 !gap-y-1 text-[9.5px] text-muted">
      <span class="row !gap-1"><i class="map-dot warehouse legend" />{{ t('مستودع', 'Warehouse') }}</span>
      <span class="row !gap-1"><i class="map-dot stop legend" />{{ t(`محطات الرحلات المفتوحة (${located})`, `Open-trip stops (${located})`) }}</span>
      <span class="row !gap-1"><i class="map-dot fix legend" />{{ t('آخر موقع من جوال السائق', 'Last driver-phone fix') }}</span>
      <span v-if="d.gps.status === 'connected'" class="row !gap-1"><i class="map-dot vehicle legend" />{{ t(`مركبات حية (${d.vehicles.length})`, `Live vehicles (${d.vehicles.length})`) }}</span>
      <span v-if="d.gps.status === 'connected' && d.gps.lastSync" class="text-faint">· {{ t('آخر مزامنة', 'last sync') }} {{ fmtAgo(d.gps.lastSync) }}</span>
      <span v-else-if="d.gps.status === 'error'" class="basis-full text-bad">{{ t('تعذّرت مزامنة مواقع المركبات', 'Vehicle sync failed') }}: {{ d.gps.detail }}</span>
      <span v-if="d.gps.status !== 'connected' && d.gps.status !== 'error'" class="basis-full text-faint">{{ lang === 'en' ? d.gps.noteEn : d.gps.noteAr }}</span>
      <span v-if="d.gps.unmatched?.length" class="basis-full text-warn">{{ t('مركبات مقترنة بلا وحدة مطابقة عند المزود', 'Paired vehicles with no matching unit at the provider') }}: {{ d.gps.unmatched.join('، ') }}</span>
    </div>
  </div>
</template>
