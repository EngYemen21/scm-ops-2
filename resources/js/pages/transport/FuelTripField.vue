<script setup>
// Trip select of the fuel form: the chosen vehicle's trips of the last 14 days (GET /transport/trips?vehicle=…).
// Optional — a fill linked to a trip is that trip's ACTUAL fuel cost; without a link the trip's fuel is estimated from
// the vehicle's own consumption. The server refuses a trip that does not run on the vehicle (FUEL_TRIP_VEHICLE).
//   <FuelTripField :vehicle-code="values.vehicleCode" :model-value="value" :current="record?.tripNumber" @update:model-value="set" />
import { computed, watch } from 'vue';
import { useList } from '@/api/client';
import { SelectInput } from '@/components';
import { fmtDateOnly } from '@/i18n';

const props = defineProps({
  vehicleCode: { type: String, default: '' },
  modelValue: { type: String, default: '' },
  /** The trip the record is linked to now: stays selectable even when it is older than the window. */
  current: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const DAYS = 14;
const trips = useList('/transport/trips', () => ({ vehicle: props.vehicleCode, pageSize: 50, sort: 'date', order: 'desc' }), { enabled: () => !!props.vehicleCode });
const options = computed(() => {
  const since = Date.now() - DAYS * 86400000;
  const rows = (props.vehicleCode ? trips.data.value?.items || [] : []).filter((x) => x.status !== 'cancelled' && (!x.date || new Date(x.date).getTime() >= since));
  const opts = rows.map((x) => [x.number, `${x.number} · ${fmtDateOnly(x.date)}${x.routeAr ? ` · ${x.routeAr}` : ''}`]);
  if (props.current && !opts.some(([v]) => v === props.current)) opts.unshift([props.current, props.current]);
  return opts;
});
const hint = computed(() => {
  if (!props.vehicleCode) return { ar: 'اختر المركبة أولًا', en: 'Pick the vehicle first' };
  if (trips.isLoading.value) return { ar: 'تُحمَّل رحلات المركبة…', en: 'Loading the vehicle\'s trips…' };
  if (trips.error.value) return { ar: 'تعذّر تحميل رحلات المركبة — يمكن الحفظ بدون رحلة', en: 'Could not load the trips — you can save without one' };
  return options.value.length
    ? { ar: 'تُحمَّل التعبئة على تكلفة الرحلة المختارة (وقود فعلي)', en: 'The fill is charged to the chosen trip (actual fuel)' }
    : { ar: `لا رحلات لهذه المركبة خلال آخر ${DAYS} يومًا`, en: `No trips of this vehicle in the last ${DAYS} days` };
});
// another vehicle → its trips: a trip picked for the previous vehicle no longer applies
watch(() => props.vehicleCode, (now, was) => { if (was && now !== was && props.modelValue) emit('update:modelValue', ''); });
</script>

<template>
  <SelectInput :model-value="modelValue || ''" :options="options" :label="{ ar: 'الرحلة (اختياري)', en: 'Trip (optional)' }" :placeholder="{ ar: '— بدون رحلة —', en: '— no trip —' }"
               :hint="hint" :disabled="!vehicleCode" @update:model-value="emit('update:modelValue', $event)" />
</template>
