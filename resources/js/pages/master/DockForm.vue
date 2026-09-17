<script setup>
// Dock appointment: (warehouse, dock, date, slot) is unique — the server rejects double bookings.
// Docks come from the lookups' `dockList` of the chosen warehouse (fallback D1…Dn), slots from `dockSlots`.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { serverMsg, today, useLookups } from './_shared';
import { DOCK_TYPE_LABELS, opts, whOpts } from './_whForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {import('./_whForms').Warehouse[]} */
  warehouses: { type: Array, default: () => [] },
  warehouseCode: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const lookups = useLookups();
const DEFAULT_SLOTS = [{ key: '06', label: '06:00 – 08:00' }, { key: '08', label: '08:00 – 10:00' }, { key: '10', label: '10:00 – 12:00' }, { key: '13', label: '13:00 – 15:00' }, { key: '15', label: '15:00 – 17:00' }];

const initial = computed(() => ({ warehouseCode: props.warehouseCode || '', date: today() }));
/** Docks of the warehouse currently chosen in the form. */
function dockOpts(values) {
  const w = lookups.data.value?.warehouses.find((x) => x.code === values.warehouseCode);
  const list = w?.dockList?.length ? w.dockList : Array.from({ length: Math.max(1, props.warehouses.find((x) => x.code === values.warehouseCode)?.docks || 4) }, (_, i) => `D${i + 1}`);
  return list.map((d) => ({ v: d, l: d }));
}
const fields = computed(() => [
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts(props.warehouses), required: true },
  { k: 'dock', label: { ar: 'الرصيف', en: 'Dock' }, type: 'select', opts: dockOpts, required: true },
  { k: 'type', label: { ar: 'النوع', en: 'Type' }, type: 'select', opts: opts(DOCK_TYPE_LABELS), def: 'in' },
  { k: 'reference', label: { ar: 'المرجع (PO / FO / RTN)', en: 'Reference (PO / FO / RTN)' }, required: true, dir: 'ltr', ph: 'PO-2026-0001' },
  { k: 'date', label: { ar: 'التاريخ', en: 'Date' }, type: 'date', required: true },
  { k: 'slot', label: { ar: 'الفترة', en: 'Slot' }, type: 'select', required: true, opts: (lookups.data.value?.dockSlots || DEFAULT_SLOTS).map((s) => ({ v: s.key, l: s.label })) },
  { k: 'carrier', label: { ar: 'الناقل / اللوحة', en: 'Carrier / plate' }, full: true },
]);

function submit(v) {
  const { warehouseCode: wc, ...body } = v;
  return api.postIdempotent(`/warehouses/${encodeURIComponent(String(wc))}/docks`, body);
}
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'حجز رصيف / موعد استلام', en: 'Dock appointment' }" :sub="{ ar: 'لا يُسمح بحجز الرصيف نفسه مرتين في الفترة ذاتها', en: 'No double booking of the same dock and slot' }"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: (d) => serverMsg(d, { ar: 'حُجز الرصيف', en: 'Dock booked' }), invalidate: ['warehouses'] }"
              @close="emit('close')" @done="emit('done', $event)" />
</template>
