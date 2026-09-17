<script setup>
// New trip form (POST /transport/trips). The load (kg / m³ / pallets) is derived by the server from the chosen packed orders;
// vehicle and driver are optional here (smart recommendation later in the trip room). Only assignable vehicles / drivers are offered.
//   <NewTripForm :open="form === 'trip'" @close="form = null" @done="(trip) => openTrip(trip.number)" />
import { computed } from 'vue';
import { api, useList } from '@/api/client';
import { FormDrawer } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import FoPicker from './FoPicker.vue';
import { TEMP_LABELS, VEHICLE_KIND_LABELS, labelOf, optsOf, todayIso } from './tms';
import { useWarehouseOptions } from './tmsComposables';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'done']);

const wh = useWarehouse();
const whOpts = useWarehouseOptions();
const enabled = () => props.open;
const vehicles = useList('/transport/vehicles', { pageSize: 100 }, { enabled });
const drivers = useList('/transport/drivers', { pageSize: 100 }, { enabled });
const routes = useList('/transport/routes', { pageSize: 100 }, { enabled });
const routeItems = computed(() => routes.data.value?.items || []);

const fields = computed(() => [
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', required: true, opts: whOpts.value, def: wh.current?.code },
  { k: 'date', label: { ar: 'تاريخ الانطلاق', en: 'Departure date' }, type: 'date', required: true, def: todayIso() },
  { k: 'plannedStart', label: { ar: 'وقت الانطلاق المخطط', en: 'Planned start' }, required: true, def: '08:00', dir: 'ltr' },
  { k: 'routeCode', label: { ar: 'مسار محفوظ (اختياري)', en: 'Saved route (optional)' }, type: 'select', opts: routeItems.value.map((r) => [r.code, `${r.name} · ${r.zones || ''}`]) },
  { k: 'routeAr', label: { ar: 'اسم المسار / المنطقة', en: 'Route name / area' }, required: true },
  { k: 'tempNeed', label: { ar: 'متطلب الحرارة', en: 'Temperature need' }, type: 'select', def: 'dry', opts: optsOf(TEMP_LABELS) },
  { k: 'km', label: { ar: 'المسافة المتوقعة كم', en: 'Expected km' }, type: 'num', min: 0 },
  { k: 'foNumbers', label: { ar: 'الطلبات المجهزة', en: 'Packed orders' }, full: true, required: true, def: [], validate: (v) => (Array.isArray(v) && v.length ? null : t('اختر طلبًا واحدًا على الأقل', 'Select at least one order')) },
  { k: 'vehicleCode', label: { ar: 'المركبة (اختياري — أو اقتراح ذكي لاحقًا)', en: 'Vehicle (optional — smart suggestion later)' }, type: 'select', ph: { ar: '— اقتراح ذكي —', en: '— smart suggestion —' },
    opts: (vehicles.data.value?.items || []).filter((v) => v.canAssign?.ok !== false).map((v) => [v.code, `${v.code} · ${v.plateAr} · ${bi(labelOf(VEHICLE_KIND_LABELS, v.kind))} · ${fmtNum(v.maxKg)} ${t('كجم', 'kg')}`]) },
  { k: 'driverCode', label: { ar: 'السائق (اختياري)', en: 'Driver (optional)' }, type: 'select', ph: { ar: '— لاحقًا —', en: '— later —' }, opts: (drivers.data.value?.items || []).filter((d) => d.canAssign?.ok !== false).map((d) => [d.code, `${d.nameAr} · ${d.code}`]) },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area' },
]);

function submit(values) {
  const body = { ...values };
  if (body.routeCode) { const r = routeItems.value.find((x) => x.code === body.routeCode); if (r && !body.routeAr) body.routeAr = r.name; }
  return api.postIdempotent('/transport/trips', body);
}
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'رحلة جديدة', en: 'New trip' }" :sub="{ ar: 'تُشتق الحمولة من الطلبات المجهزة', en: 'Load is derived from the packed orders' }" :fields="fields" :submit="submit"
              :action="{ success: (r) => r?.message || t('أُنشئت الرحلة', 'Trip created'), invalidate: ['transport', 'fulfillment'] }" @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-foNumbers="{ value, set, values, error }">
      <FoPicker :model-value="value || []" :warehouse="values.warehouseCode" :error="error" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
