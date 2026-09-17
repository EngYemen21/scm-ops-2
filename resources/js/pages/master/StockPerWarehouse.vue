<script setup>
// Stock of one product per warehouse (on hand / reserved / available / quarantine).
//   <StockPerWarehouse :stock="stock.data.value" :loading="stock.isLoading.value" />     stock = GET /inventory/products/:sku/stock
import { DataTable } from '@/components';
import { fmtNum } from '@/i18n';

defineProps({
  /** @type {import('./_shared').ProductStock} */
  stock: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});

const columns = [
  { key: 'code', header: { ar: 'المستودع', en: 'Warehouse' }, width: 'minmax(120px,1.4fr)' },
  { key: 'onHand', header: { ar: 'فعلي', en: 'On hand' }, width: '70px', kind: 'num', align: 'center' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '70px', kind: 'num', align: 'center' },
  { key: 'available', header: { ar: 'متاح', en: 'Available' }, width: '70px', kind: 'num', align: 'center' },
  { key: 'quarantine', header: { ar: 'محجور', en: 'Quarantine' }, width: '70px', kind: 'num', align: 'center' },
];
</script>

<template>
  <DataTable :columns="columns" :rows="stock?.perWarehouse || []" :loading="loading" :row-key="(r) => r.code" dense :min-width="400" :empty-text="{ ar: 'لا مستودعات نشطة', en: 'No active warehouses' }">
    <template #cell-code="{ row }"><span><span class="cell-id">{{ row.code }}</span> <span class="text-[10px] font-bold text-sec">{{ row.nameAr }}</span></span></template>
    <template #cell-reserved="{ row }"><span :class="{ 'text-warn': row.reserved }">{{ fmtNum(row.reserved) }}</span></template>
    <template #cell-available="{ row }"><span :class="row.available > 0 ? 'text-ok' : 'text-bad'">{{ fmtNum(row.available) }}</span></template>
    <template #cell-quarantine="{ row }"><span :class="row.quarantine ? 'text-bad' : 'text-faint'">{{ fmtNum(row.quarantine) }}</span></template>
  </DataTable>
</template>
