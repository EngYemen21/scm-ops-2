<script setup>
// Batches & expiry (nav `batches`): FEFO-ordered batches with expired / expiring / ok / quarantined KPIs,
// per-warehouse stock, days-left chips and the quarantine action. Deep links: ?q= · ?status=
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, PageHead, SectionCard, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import DaysChip from './DaysChip.vue';
import Hint from './Hint.vue';
import ProductCell from './ProductCell.vue';
import QuarantineForm from './QuarantineForm.vue';
import StockDrawer from './StockDrawer.vue';
import { BATCH_STATUS, batchStatusKey, pillTabs, useDebounced } from './shared';

/**
 * @typedef {{ id: string, batchNo: string, product: { id: string, sku: string, nameAr: string, nameEn: string, storageClass: string, tracksExpiry: boolean },
 *   supplier: { code: string, nameAr: string }|null, mfgDate: string|null, expiry: string|null, daysLeft: number|null, status: 'expired'|'expiring'|'ok',
 *   onHand: number, reserved: number, quarantine: number, anyQuarantine: boolean,
 *   perWarehouse: Record<string, { onHand: number, reserved: number, quarantine: number, bins: string[] }>, createdAt: string }} BatchRow
 */
const PILLS = pillTabs([{ k: '', ar: 'الكل', en: 'All' }, { k: 'expired', ar: 'منتهية', en: 'Expired' }, { k: 'expiring', ar: 'تنتهي قريبًا', en: 'Expiring' }, { k: 'ok', ar: 'سليمة', en: 'OK' }, { k: 'quarantine', ar: 'محجورة', en: 'Quarantined' }]);

const auth = useAuth();
const wh = useWarehouse();
const route = useRoute();
const qs = (k) => (typeof route.query[k] === 'string' ? route.query[k] : '');

const status = ref(qs('status'));
const q = ref(qs('q'));
const dq = useDebounced(q);
const page = ref(1);
/** { key, order } | null — server-side sort */
const sort = ref(null);
/** StockFocus | null */
const focus = ref(null);
/** StockRef | null — quarantine form target */
const qtn = ref(null);
watch([() => wh.wh, status, dq], () => { page.value = 1; });
// A deep link followed while the page is already open re-seeds the filters.
watch(() => route.query, () => { if (qs('q')) q.value = qs('q'); if (qs('status')) status.value = qs('status'); });

const list = useList('/inventory/batches', () => ({ ...wh.whParams, status: status.value, q: dq.value, page: page.value, pageSize: 50, sort: sort.value?.key, order: sort.value?.order }));
// KPI counts: one lightweight query per status (the API has no batch summary endpoint)
const kpiCount = (st) => useList('/inventory/batches', () => ({ ...wh.whParams, status: st, pageSize: 1 }));
const kExpired = kpiCount('expired');
const kExpiring = kpiCount('expiring');
const kOk = kpiCount('ok');
const kQtn = kpiCount('quarantine');
const kAll = kpiCount(undefined);

const cols = computed(() => [
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '110px', kind: 'id', sortable: true },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.5fr)' },
  { key: 'perWarehouse', header: { ar: 'المستودع · الكمية', en: 'Warehouse · qty' }, width: 'minmax(150px,1fr)' },
  { key: 'onHand', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '70px', kind: 'num' },
  { key: 'quarantine', header: { ar: 'محجور', en: 'Quarantined' }, width: '70px', kind: 'num' },
  { key: 'mfgDate', header: { ar: 'الإنتاج', en: 'Mfg' }, width: '92px', kind: 'date', value: (r) => fmtDateOnly(r.mfgDate) },
  { key: 'expiry', header: { ar: 'الانتهاء', en: 'Expiry' }, width: '96px', sortable: true },
  { key: 'daysLeft', header: { ar: 'متبقٍ', en: 'Days left' }, width: '96px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px' },
  { key: 'act', header: '', width: '92px', hidden: !auth.can('inventory.adjust') },
]);

/** `[warehouseCode, { onHand, reserved, quarantine, bins }]` entries that hold stock. */
const stocked = (r) => Object.entries(r.perWarehouse || {}).filter(([, v]) => v.onHand > 0);
function openRow(r) {
  const w = stocked(r)[0];
  focus.value = { sku: r.product.sku, warehouse: w?.[0], batch: r.batchNo };
}
function openQuarantine(r) {
  const [w, v] = stocked(r)[0];
  qtn.value = { sku: r.product.sku, batchNo: r.batchNo, warehouseCode: w, binCode: v.bins[0], quarantine: r.anyQuarantine };
}
const rowStyle = (r) => (r.status === 'expired' || r.anyQuarantine ? { background: '#FFF8F8' } : null);
</script>

<template>
  <PageHead :sub="t('FEFO افتراضيًا — الأقرب انتهاءً يُصرف أولًا', 'FEFO by default — earliest expiry ships first')" />
  <KpiGrid compact>
    <KpiCard compact clickable :loading="kAll.isLoading.value" :value="kAll.data.value?.total" :label="{ ar: 'دفعات', en: 'Batches' }" color="#654e92" :active="status === ''" @click="status = ''" />
    <KpiCard compact clickable :loading="kExpired.isLoading.value" :value="kExpired.data.value?.total" :label="{ ar: 'منتهية الصلاحية', en: 'Expired' }" color="#b23b3b" :active="status === 'expired'" @click="status = 'expired'" />
    <KpiCard compact clickable :loading="kExpiring.isLoading.value" :value="kExpiring.data.value?.total" :label="{ ar: 'تنتهي قريبًا (≤ حد الإنذار)', en: 'Expiring soon' }" color="#b26a16" :active="status === 'expiring'" @click="status = 'expiring'" />
    <KpiCard compact clickable :loading="kOk.isLoading.value" :value="kOk.data.value?.total" :label="{ ar: 'سليمة', en: 'OK' }" color="#1d7a3e" :active="status === 'ok'" @click="status = 'ok'" />
    <KpiCard compact clickable :loading="kQtn.isLoading.value" :value="kQtn.data.value?.total" :label="{ ar: 'محجورة', en: 'Quarantined' }" color="#b23b3b" :active="status === 'quarantine'" @click="status = 'quarantine'" />
  </KpiGrid>

  <div class="row wrap mt-3">
    <Tabs v-model="status" :tabs="PILLS" variant="pill" class="!mb-0" />
    <div class="flex-1" />
    <TextInput v-model="q" small type="search" class="min-w-[240px]" :placeholder="{ ar: 'بحث: دفعة / SKU / اسم', en: 'Search: batch / SKU / name' }" />
  </div>

  <SectionCard class="mt-3" :padded="false" :title="{ ar: 'الدفعات مرتبة FEFO — الأقرب انتهاءً أولًا', en: 'Batches in FEFO order — earliest expiry first' }" :count="list.data.value?.total">
    <ErrorBanner :error="list.error.value" :closable="false" class="m-3" />
    <DataTable :columns="cols" :paged="list.data.value" :loading="list.isFetching.value" :min-width="1080" :row-key="(r) => r.id" :row-style="rowStyle" :empty-text="{ ar: 'لا دفعات مطابقة.', en: 'No matching batches.' }"
               server-sort :sort="sort" @sort="sort = $event" @page="page = $event" @row-click="openRow">
      <template #cell-product="{ row }"><ProductCell :p="row.product">{{ row.product.sku }}{{ row.supplier ? ` · ${row.supplier.nameAr}` : '' }}</ProductCell></template>
      <template #cell-perWarehouse="{ row }">
        <div v-if="stocked(row).length" class="row wrap !gap-1">
          <Chip v-for="[w, v] in stocked(row)" :key="w" small fg="#55506a" bg="#F1EFF6" :title="v.bins.join(', ')">{{ w }} ·&nbsp;<b class="num">{{ fmtNum(v.onHand) }}</b><span v-if="v.quarantine" class="text-bad">&nbsp;⚠{{ fmtNum(v.quarantine) }}</span></Chip>
        </div>
        <span v-else class="muted">—</span>
      </template>
      <template #cell-reserved="{ row }"><span class="text-warn">{{ fmtNum(row.reserved) }}</span></template>
      <template #cell-quarantine="{ row }"><span :class="row.quarantine ? 'text-bad' : 'text-faint'">{{ fmtNum(row.quarantine) }}</span></template>
      <template #cell-expiry="{ row }"><span class="num ltr inline-block text-[10px]">{{ fmtDateOnly(row.expiry) }}</span></template>
      <template #cell-daysLeft="{ row }"><DaysChip :days="row.daysLeft" /></template>
      <template #cell-status="{ row }"><Chip small :map="BATCH_STATUS" :k="batchStatusKey(row.daysLeft, row.anyQuarantine)" /></template>
      <template #cell-act="{ row }">
        <Btn v-if="stocked(row).length" :tone="row.anyQuarantine ? 'softGreen' : 'softRed'" size="sm" :label="row.anyQuarantine ? { ar: 'رفع الحجر', en: 'Release' } : { ar: 'حجر', en: 'Quarantine' }" @click.stop="openQuarantine(row)" />
      </template>
    </DataTable>
  </SectionCard>
  <Hint>{{ t('حرجة ≤ 7 أيام تُصرف أولًا · قريبة الانتهاء ≤ حد الإنذار (إعدادات: inventory.expiringSoonDays) · المنتهية تُستبعد تلقائيًا من المتاح ولا يُخصص منها. الحجر علامة على رصيد الموقع (لا حركة كمية) ويُرفع بقرار جودة موثق.', 'Critical ≤ 7 days ships first · expiring ≤ alert threshold (settings: inventory.expiringSoonDays) · expired batches drop out of available automatically and are never allocated. Quarantine is a flag on the bin balance (no qty movement) released by a documented QC decision.') }}</Hint>

  <StockDrawer :focus="focus" @close="focus = null" />
  <QuarantineForm :open="!!qtn" :initial="qtn" @close="qtn = null" />
</template>
