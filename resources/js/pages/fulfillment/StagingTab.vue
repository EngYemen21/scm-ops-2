<script setup>
// Pick & Pack › staging tab: packages awaiting loading and the balances of the staging bins (STG-OUT / STG-IN) with
// their age. Data: /api/inventory/staging (refreshed every 30 s), scoped by the warehouse selector.
//   StagingItem  { id, sku, nameAr, nameEn?, warehouse, bin, direction: 'in'|'out', batch?, expiry?, onHand, reserved, ageHours?,
//                  reference?: { number?, type?, movement?, movementType?, by?, at? } }
//   StagingEntry { id, direction, warehouse, bin, referenceType, referenceNumber?, qty, status, createdAt }
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtAgo, fmtDate, fmtDateOnly, fmtNum, nm, t } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import { useWarehouse } from '@/stores/warehouse';
import StagingRef from './StagingRef.vue';

const wh = useWarehouse();
const dir = ref('out');
const staging = useGet('/inventory/staging', () => ({ ...wh.whParams }), { refetchInterval: 30_000 });
const items = computed(() => (staging.data.value?.items || []).filter((x) => !dir.value || x.direction === dir.value));
const entries = computed(() => (staging.data.value?.entries || []).filter((x) => !dir.value || x.direction === dir.value));

const DIRS = [{ k: 'out', label: { ar: 'Staging الصادر STG-OUT', en: 'Outbound STG-OUT' } }, { k: 'in', label: { ar: 'Staging الوارد STG-IN', en: 'Inbound STG-IN' } }, { k: '', label: { ar: 'الكل', en: 'All' } }];
const ageColor = (h) => ((h ?? 0) > 24 ? '#b23b3b' : (h ?? 0) > 8 ? '#b26a16' : '#1d7a3e');

const entryCols = [
  { key: 'ref', header: { ar: 'المرجع', en: 'Reference' }, width: '130px' },
  { key: 'bin', header: { ar: 'الموقع', en: 'Bin' }, width: '90px' },
  { key: 'wh', header: { ar: 'المستودع', en: 'WH' }, width: '70px' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num', value: (r) => fmtNum(r.qty) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '110px' },
  { key: 'at', header: { ar: 'منذ', en: 'Since' }, width: '120px' },
];
const itemCols = [
  { key: 'bin', header: { ar: 'الموقع', en: 'Bin' }, width: '90px' },
  { key: 'wh', header: { ar: 'المستودع', en: 'WH' }, width: '70px' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(180px,1.5fr)' },
  { key: 'batch', header: { ar: 'الدفعة · الصلاحية', en: 'Batch · expiry' }, width: '130px' },
  { key: 'onHand', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num', value: (r) => fmtNum(r.onHand) },
  { key: 'ref', header: { ar: 'المرجع', en: 'Reference' }, width: '120px' },
  { key: 'mv', header: { ar: 'آخر حركة', en: 'Last movement' }, width: '150px' },
  { key: 'age', header: { ar: 'العمر', en: 'Age' }, width: '80px' },
];
</script>

<template>
  <div>
    <Tabs v-model="dir" :tabs="DIRS" variant="pill" />
    <ErrorBanner :error="staging.error.value" :closable="false" />
    <div class="card">
      <div class="px-[18px] pb-[9px] pt-[13px] text-[13px] font-extrabold">{{ t('طرود بانتظار التحميل', 'Packages awaiting loading') }} <span class="num muted text-[10px]">({{ entries.length }})</span></div>
      <DataTable :columns="entryCols" :rows="entries" :loading="staging.isLoading.value" :row-key="(r) => r.id" :min-width="640" :empty-text="{ ar: 'لا طرود في Staging', en: 'Nothing staged' }">
        <template #cell-ref="{ row }"><StagingRef :type="row.referenceType" :number="row.referenceNumber" /></template>
        <template #cell-bin="{ row }"><span class="num text-violet">{{ row.bin }}</span></template>
        <template #cell-wh="{ row }"><span class="sec text-[10px] font-bold">{{ row.warehouse }}</span></template>
        <template #cell-status="{ row }"><Chip :label="row.status === 'waiting' ? { ar: 'بانتظار التحميل', en: 'Awaiting load' } : row.status" fg="#b26a16" bg="#fbf0dd" small /></template>
        <template #cell-at="{ row }"><span class="cell-date">{{ fmtDate(row.createdAt) }} · {{ fmtAgo(row.createdAt) }}</span></template>
      </DataTable>
    </div>
    <div class="card mt-3.5">
      <div class="px-[18px] pb-[9px] pt-[13px] text-[13px] font-extrabold">{{ t('أرصدة مواقع Staging', 'Staging bin balances') }} <span class="num muted text-[10px]">({{ items.length }})</span></div>
      <DataTable :columns="itemCols" :rows="items" :loading="staging.isLoading.value" :row-key="(r) => r.id" :min-width="900" :empty-text="{ ar: 'لا أرصدة في مواقع Staging', en: 'No stock in staging bins' }">
        <template #cell-bin="{ row }"><span class="num" :class="row.direction === 'out' ? 'text-violet' : 'text-ok'">{{ row.bin }}</span></template>
        <template #cell-wh="{ row }"><span class="sec text-[10px] font-bold">{{ row.warehouse }}</span></template>
        <template #cell-product="{ row }"><div><div class="cell-name">{{ nm(row) }}</div><div class="cell-sub">{{ row.sku }}</div></div></template>
        <template #cell-batch="{ row }"><span class="num text-[9.5px] text-brand-dark">{{ row.batch || '—' }}{{ row.expiry ? ` · ${fmtDateOnly(row.expiry)}` : '' }}</span></template>
        <template #cell-ref="{ row }"><StagingRef :type="row.reference?.type" :number="row.reference?.number" /></template>
        <template #cell-mv="{ row }">
          <div v-if="row.reference" class="row !gap-1.5"><Chip :map="MOVEMENT_LABELS" :k="row.reference.movementType || ''" small /><span class="cell-sub">{{ row.reference.by || '' }}</span></div>
          <template v-else>—</template>
        </template>
        <template #cell-age="{ row }"><span class="num text-[10px]" :style="{ color: ageColor(row.ageHours) }">{{ row.ageHours != null ? t(`${row.ageHours} س`, `${row.ageHours}h`) : '—' }}</span></template>
      </DataTable>
    </div>
    <div class="hint">{{ t('STG-OUT يحتفظ بالكميات المعبأة حتى تحميلها على الشاحنة (التحميل والتوصيل) — الأعمار فوق 24 ساعة تُظهر تأخرًا في الشحن.', 'STG-OUT holds packed stock until it is loaded onto the truck (Load & Dispatch) — ages above 24h indicate a shipping delay.') }}</div>
  </div>
</template>
