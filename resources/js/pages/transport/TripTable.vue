<script setup>
// Trips table (the reference `tripColumns`): number · date · warehouse · vehicle · driver · route · stops · orders · load ·
// plan / actual · ETA (Integration Pending unless the API sends one) · progress · delay · status.
//   <TripTable :paged="list.data.value" :loading="…" :selected-key="openTrip" @page="page = $event" @open="(n) => …" />
//   <TripTable :rows="delayedRows" … />                                   (client-side rows, no pager)
import { DataTable, Chip } from '@/components';
import { fmtDateOnly, fmtNum, fmtTime, lang } from '@/i18n';
import { TRIP_LABELS } from '@/shared';
import { delayLabel, drvName, etaText, tripProgress } from './tms';

defineProps({
  paged: { type: Object, default: null },
  rows: { type: Array, default: null },
  loading: { type: Boolean, default: false },
  selectedKey: { type: String, default: null },
  emptyText: { type: [String, Object], default: null },
});
const emit = defineEmits(['page', 'open']);

const stopsText = (r) => { const p = tripProgress(r); return `${p.done} / ${p.total}`; };
const columns = [
  { key: 'number', header: { ar: 'الرحلة', en: 'Trip' }, kind: 'id', width: '100px', sortable: true },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, kind: 'date', width: '74px', value: (r) => fmtDateOnly(r.date), sortable: true },
  { key: 'warehouse', header: { ar: 'المستودع', en: 'WH' }, width: '62px', kind: 'muted', value: (r) => r.warehouse?.code || '—' },
  { key: 'vehicle', header: { ar: 'المركبة', en: 'Vehicle' }, width: '56px' },
  { key: 'driver', header: { ar: 'السائق', en: 'Driver' }, width: '88px' },
  { key: 'route', header: { ar: 'المسار', en: 'Route' }, width: 'minmax(116px,1fr)', kind: 'muted', value: (r) => (lang.value === 'en' && r.routeEn) || r.routeAr || '—' },
  { key: 'stops', header: { ar: 'المحطات', en: 'Stops' }, kind: 'num', width: '54px', value: stopsText },
  { key: 'orders', header: { ar: 'الطلبات', en: 'Orders' }, kind: 'num', width: '48px', value: (r) => fmtNum(r._count?.orders ?? r.orders?.length) },
  { key: 'kg', header: { ar: 'كجم', en: 'kg' }, kind: 'num', width: '54px', value: (r) => fmtNum(r.kg) },
  { key: 'cbm', header: { ar: 'م³', en: 'm³' }, kind: 'num', width: '42px', value: (r) => fmtNum(r.cbm, 1) },
  { key: 'km', header: { ar: 'كم', en: 'km' }, kind: 'num', width: '42px', value: (r) => fmtNum(r.km) },
  { key: 'plan', header: { ar: 'المخطط / الفعلي', en: 'Plan / actual' }, kind: 'date', width: '88px', value: (r) => `${r.plannedStart || '—'} / ${r.actualStart ? fmtTime(r.actualStart) : '—'}` },
  { key: 'eta', header: 'ETA', width: '72px' },
  { key: 'prog', header: { ar: 'التقدم', en: 'Progress' }, width: '64px' },
  { key: 'delayMin', header: { ar: 'التأخر', en: 'Delay' }, width: '70px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '100px' },
];
</script>

<template>
  <DataTable :columns="columns" :paged="paged" :rows="rows" :loading="loading" compact :row-key="(r) => r.number" :selected-key="selectedKey" :empty-text="emptyText"
             @page="emit('page', $event)" @row-click="(r) => emit('open', r.number)">
    <template #cell-vehicle="{ row }"><span class="num font-bold text-violet">{{ row.vehicle?.code || '—' }}</span></template>
    <template #cell-driver="{ row }"><b class="text-[10px]">{{ drvName(row.driver, lang) }}</b></template>
    <template #cell-eta="{ row }"><span class="num text-[9px] font-bold text-brand-dark">{{ etaText(row) }}</span></template>
    <template #cell-prog="{ row }">
      <div class="row !gap-1.5"><div class="progress !h-1.5 flex-1"><div :style="{ width: tripProgress(row).pct + '%', background: '#1BC4DB' }" /></div><span class="num text-[9px] font-bold">{{ tripProgress(row).pct }}%</span></div>
    </template>
    <template #cell-delayMin="{ row }"><span class="whitespace-nowrap text-[9.5px] font-extrabold" :class="row.delayMin ? 'text-warn' : 'text-ok'">{{ delayLabel(row.delayMin, lang) }}</span></template>
    <template #cell-status="{ row }"><Chip :map="TRIP_LABELS" :k="row.status" /></template>
  </DataTable>
</template>
