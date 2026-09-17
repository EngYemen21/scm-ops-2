<script setup>
// Balance rows per bin / batch. With `movable`, rows that hold stock get a "Move" link that emits `move` with the row.
//   <StockRows :rows="stock.rows" :loading="…" :movable="auth.can('inventory.move')" @move="(row) => …" />
import { computed } from 'vue';
import { Chip, DataTable } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { BALANCE_STATUS_LABELS } from './_shared';

const props = defineProps({
  /** @type {import('./_shared').StockRow[]} */
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  movable: { type: Boolean, default: false },
});
const emit = defineEmits(['move']);

const columns = computed(() => [
  { key: 'warehouse', header: { ar: 'المستودع', en: 'WH' }, width: '60px', kind: 'id' },
  { key: 'bin', header: { ar: 'الموقع Bin', en: 'Bin' }, width: '110px' },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '110px' },
  { key: 'onHand', header: { ar: 'فعلي', en: 'On hand' }, width: '64px', kind: 'num', align: 'center' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '64px', kind: 'num', align: 'center' },
  { key: 'available', header: { ar: 'متاح', en: 'Avail.' }, width: '64px', kind: 'num', align: 'center' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '96px' },
  { key: 'act', header: '', width: '54px', align: 'center', hidden: !props.movable },
]);
const expiryClass = (r) => (r.daysToExpiry != null && r.daysToExpiry < 0 ? '!text-bad' : r.daysToExpiry != null && r.daysToExpiry <= 30 ? '!text-warn' : '');
</script>

<template>
  <DataTable :columns="columns" :rows="rows" :loading="loading" :row-key="(r) => r.id" dense :min-width="620" :empty-text="{ ar: 'لا رصيد لهذا الصنف في أي موقع', en: 'No stock in any bin' }">
    <template #cell-bin="{ row }"><span><span class="cell-id">{{ row.bin }}</span><div class="cell-sub">{{ row.zone }} · {{ row.zoneType }}</div></span></template>
    <template #cell-batch="{ row }">
      <span><span class="num text-[10px]">{{ row.batch || '—' }}</span><div v-if="row.expiry" class="cell-sub" :class="expiryClass(row)">{{ fmtDateOnly(row.expiry) }}</div></span>
    </template>
    <template #cell-reserved="{ row }"><span :class="row.reserved ? 'text-warn' : 'text-faint'">{{ fmtNum(row.reserved) }}</span></template>
    <template #cell-available="{ row }"><span :class="row.available > 0 ? 'text-ok' : 'text-faint'">{{ fmtNum(row.available) }}</span></template>
    <template #cell-status="{ row }"><Chip :map="BALANCE_STATUS_LABELS" :k="row.quarantine ? 'quarantine' : row.blocked ? 'blocked' : row.status" small /></template>
    <template #cell-act="{ row }"><span v-if="row.onHand > 0" class="cursor-pointer text-[8.5px] font-extrabold text-brand-dark" @click.stop="emit('move', row)">{{ t('نقل', 'Move') }}</span><span v-else /></template>
  </DataTable>
</template>
