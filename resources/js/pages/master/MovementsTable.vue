<script setup>
// Inventory-ledger movements (server paged). The reference number links to its document when the type is known.
//   <MovementsTable :paged="ledger.data.value" :loading="ledger.isLoading.value" show-product @page="page = $event" />
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { Chip, DataTable } from '@/components';
import { fmtDate, fmtNum } from '@/i18n';
import { entityPath } from '@/router/routes';
import { MOVEMENT_LABELS } from '@/shared';
import SkuPill from './SkuPill.vue';

const props = defineProps({
  /** Server page `{ items: Movement[], total, page, pageSize, pages }`. */
  paged: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  showProduct: { type: Boolean, default: false },
});
const emit = defineEmits(['page']);
const router = useRouter();

const columns = computed(() => [
  { key: 'number', header: { ar: 'الحركة', en: 'Movement' }, width: '96px', kind: 'id' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '112px' },
  { key: 'product', header: { ar: 'الصنف', en: 'Product' }, width: 'minmax(140px,1fr)', hidden: !props.showProduct },
  { key: 'signedQty', header: { ar: 'الكمية', en: 'Qty' }, width: '64px', kind: 'num', align: 'center' },
  { key: 'path', header: { ar: 'من ← إلى', en: 'From → To' }, width: 'minmax(150px,1.2fr)', ltr: true },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '90px', kind: 'muted', ltr: true },
  { key: 'referenceNumber', header: { ar: 'المرجع', en: 'Reference' }, width: '110px' },
  { key: 'username', header: { ar: 'المستخدم', en: 'User' }, width: '90px', kind: 'muted' },
  { key: 'createdAt', header: { ar: 'الوقت', en: 'When' }, width: '110px', kind: 'date', value: (r) => fmtDate(r.createdAt) },
]);
const refPath = (r) => entityPath(r.referenceType, r.referenceNumber);
</script>

<template>
  <DataTable :columns="columns" :paged="paged" :loading="loading" :row-key="(r) => r.id" dense :min-width="showProduct ? 980 : 820" :empty-text="{ ar: 'لا حركات مسجلة', en: 'No movements' }" @page="emit('page', $event)">
    <template #cell-type="{ row }"><Chip :map="MOVEMENT_LABELS" :k="row.type" small /></template>
    <template #cell-product="{ row }"><span><SkuPill :sku="row.product?.sku || ''" /> <span class="text-[10px] font-bold">{{ row.product?.nameAr }}</span></span></template>
    <template #cell-signedQty="{ row }"><span :class="row.signedQty > 0 ? 'text-ok' : row.signedQty < 0 ? 'text-bad' : 'text-sec'">{{ row.signedQty > 0 ? '+' : '' }}{{ fmtNum(row.signedQty) }}</span></template>
    <template #cell-path="{ row }"><span class="num text-[9.5px] text-sec">{{ row.src ? `${row.src.warehouse}/${row.src.bin}` : '—' }} → {{ row.dst ? `${row.dst.warehouse}/${row.dst.bin}` : '—' }}</span></template>
    <template #cell-referenceNumber="{ row }">
      <span v-if="refPath(row)" class="cursor-pointer font-extrabold text-brand-dark" @click.stop="router.push(refPath(row))"><span class="cell-id">{{ row.referenceNumber || '—' }}</span></span>
      <span v-else class="cell-id">{{ row.referenceNumber || '—' }}</span>
    </template>
  </DataTable>
</template>
