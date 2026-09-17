<script setup>
// Compact movements table used by the transaction drawer (same-transaction siblings) and the trace drawer
// (`full` adds the timestamp and user columns). `clickable` makes rows emit `open` with the movement number.
import { computed } from 'vue';
import { Chip, DataTable } from '@/components';
import { fmtDate } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import { fmtSigned, locLabel, pname, signColor } from './shared';

const props = defineProps({
  /** Movement[] */
  rows: { type: Array, default: () => [] },
  full: { type: Boolean, default: false },
  clickable: { type: Boolean, default: false },
  minWidth: { type: Number, default: 520 },
  emptyText: { type: [String, Object], default: null },
});
const emit = defineEmits(['open']);

const SIBLING_COLS = [
  { key: 'number', header: { ar: 'الحركة', en: 'Tx' }, width: '110px', kind: 'id' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '95px' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: '1fr' },
  { key: 'route', header: { ar: 'من ← إلى', en: 'From → To' }, width: '150px' },
  { key: 'qty', header: { ar: 'الكمية ±', en: 'Qty ±' }, width: '70px', kind: 'num' },
];
const columns = computed(() => (props.full
  ? [{ key: 'createdAt', header: { ar: 'الوقت', en: 'When' }, width: '105px', kind: 'date', value: (m) => fmtDate(m.createdAt) }, ...SIBLING_COLS, { key: 'username', header: { ar: 'المستخدم', en: 'User' }, width: '80px', kind: 'muted' }]
  : SIBLING_COLS));
const onRowClick = computed(() => (props.clickable ? (m) => emit('open', m.number) : null));
</script>

<template>
  <DataTable :columns="columns" :rows="rows" dense :min-width="minWidth" :row-key="(r) => r.id" :on-row-click="onRowClick" :empty-text="emptyText">
    <template #cell-type="{ row }"><Chip small :map="MOVEMENT_LABELS" :k="row.type" /></template>
    <template #cell-product="{ row }"><span class="text-[10.5px] font-extrabold">{{ pname(row.product) }}</span></template>
    <template #cell-route="{ row }"><span class="num ltr inline-block text-[9.5px] font-bold text-brand-dark">{{ locLabel(row.src) }} → {{ locLabel(row.dst) }}</span></template>
    <template #cell-qty="{ row }"><span :style="{ color: signColor(row.signedQty) }">{{ fmtSigned(row.signedQty) }}</span></template>
  </DataTable>
</template>
