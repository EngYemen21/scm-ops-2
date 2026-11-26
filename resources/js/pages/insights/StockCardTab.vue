<script setup>
// «كرت الصنف» — the stock card of one product (not for brand owners): an opening balance, each movement as IN or OUT
// with the running balance after it, the closing balance and the document behind each line.
// GET /insights/stock-card?sku&warehouse&from&to&internal&page&pageSize → { items, total, …, product, warehouse, from, to,
// opening, in, out, closing, onHandNow, definition }. Export: GET /insights/stock-card/export (same filters, CSV, needs
// report.export — the button shows with `me.export`). Print: the browser's print with a print-only header.
// Query string: sku · warehouse (code, or `all`) · from · to · internal=1 · page.
import { computed, ref, watch } from 'vue';
import { useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, KpiCard, KpiGrid, SelectInput, TextInput } from '@/components';
import { bi, fmtDate, fmtNum, lang, t } from '@/i18n';
import { entityPath } from '@/router/routes';
import { MOVEMENT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import { canOpen, downloadFile, fmtDay, monthStartIso, nameOf, printPage, todayIso, useInsightsNav } from './insights';

const props = defineProps({
  me: { type: Object, required: true },
});

const nav = useInsightsNav();
const { page } = nav;
const auth = useAuth();
const wh = useWarehouse();

// ---- filters
const skuText = ref(nav.get('sku'));
watch(() => nav.get('sku'), (v) => { skuText.value = v; });
const applySku = () => nav.setFilter('sku', String(skuText.value || '').trim());
const whSel = computed(() => { const v = nav.get('warehouse'); if (v === 'all') return ''; return v || (wh.isAll ? '' : wh.wh); });
const whOptions = computed(() => [{ v: 'all', l: { ar: 'كل المستودعات', en: 'All warehouses' } }, ...wh.warehouses.map((w) => ({ v: w.code, l: `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}` }))]);
const from = computed(() => nav.get('from') || monthStartIso());
const to = computed(() => nav.get('to') || todayIso());
const internal = computed(() => nav.get('internal') === '1');
const params = computed(() => ({ sku: nav.get('sku'), warehouse: whSel.value || undefined, from: from.value, to: to.value, internal: internal.value ? 1 : 0 }));

const card = useList(() => (nav.get('sku') ? '/insights/stock-card' : null), () => ({ ...params.value, page: page.value, pageSize: 100 }));
const d = computed(() => card.data.value || null);
const isFirst = computed(() => (d.value?.page || 1) === 1);
const isLast = computed(() => (d.value?.page || 1) >= (d.value?.pages || 1));
/** The page of movements with the opening line before the first and the closing line after the last. */
const paged = computed(() => {
  if (!d.value) return null;
  const items = [...d.value.items];
  if (isFirst.value) items.unshift({ id: '__opening', synthetic: 'opening', at: d.value.from, balance: d.value.opening });
  if (isLast.value) items.push({ id: '__closing', synthetic: 'closing', at: d.value.to, in: d.value.in, out: d.value.out, balance: d.value.closing });
  return { ...d.value, items };
});
const endsToday = computed(() => to.value >= todayIso());
const mismatch = computed(() => d.value && endsToday.value && d.value.closing !== d.value.onHandNow);

const columns = [
  { key: 'at', header: { ar: 'التاريخ', en: 'Date' }, width: '120px', kind: 'date' },
  { key: 'number', header: { ar: 'الحركة', en: 'Movement' }, width: '124px' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '110px', bare: true },
  { key: 'reference', header: { ar: 'المستند', en: 'Document' }, width: '124px' },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Lot' }, width: '90px', kind: 'id' },
  { key: 'route', header: { ar: 'من ← إلى', en: 'From → to' }, width: 'minmax(140px,1fr)' },
  { key: 'in', header: { ar: 'وارد', en: 'In' }, width: '68px', kind: 'num' },
  { key: 'out', header: { ar: 'صادر', en: 'Out' }, width: '68px', kind: 'num' },
  { key: 'balance', header: { ar: 'الرصيد', en: 'Balance' }, width: '78px', kind: 'num' },
  { key: 'user', header: { ar: 'المستخدم', en: 'User' }, width: '90px', kind: 'muted' },
  { key: 'note', header: { ar: 'ملاحظة', en: 'Note' }, width: 'minmax(100px,0.8fr)', kind: 'muted' },
];
const ledgerOk = computed(() => canOpen(auth, 'ledger'));
const moveLink = (r) => (ledgerOk.value && r.number ? `/ledger?q=${encodeURIComponent(r.number)}` : null);
function refLink(r) {
  const doc = r.reference;
  if (!doc?.number) return null;
  return entityPath(doc.type, doc.number) || (ledgerOk.value ? `/ledger?referenceNumber=${encodeURIComponent(doc.number)}` : null);
}
const place = (p) => (p ? `${p.warehouse || '—'}/${p.bin || '—'}` : '—');
const rowStyle = (r) => (r.synthetic ? { background: '#F7F5FB', fontWeight: 800 } : null);

// ---- export / print
const act = useAction();
const exportCsv = () => act.run(() => downloadFile('/insights/stock-card/export', params.value, `stock-card-${nav.get('sku')}.csv`), {
  success: (f) => t(`نُزّل ${f}`, `Downloaded ${f}`), invalidate: false,
});
const hasFilters = computed(() => ['warehouse', 'from', 'to', 'internal'].some((k) => !!nav.get(k)));
</script>

<template>
  <div class="row wrap mb-2 no-print">
    <TextInput v-model="skuText" scan small mono class="w-[200px]" :placeholder="{ ar: 'SKU المنتج', en: 'Product SKU' }" @enter="applySku" />
    <Btn size="sm" tone="dark" :label="{ ar: 'عرض الكرت', en: 'Show card' }" :disabled="!String(skuText || '').trim()" @click="applySku" />
    <SelectInput small class="w-[190px]" :model-value="whSel || 'all'" :options="whOptions" @update:model-value="(v) => nav.setFilter('warehouse', v)" />
    <DateInput small class="w-[140px]" :title="t('من تاريخ', 'From date')" :model-value="from" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[140px]" :title="t('إلى تاريخ', 'To date')" :model-value="to" @update:model-value="(v) => nav.setFilter('to', v)" />
    <label class="inline-flex cursor-pointer items-center gap-1.5 text-[10.5px] font-bold text-sec">
      <input type="checkbox" class="h-4 w-4" :checked="internal" @change="nav.setFilter('internal', $event.target.checked ? '1' : null)">{{ t('إظهار النقل الداخلي', 'Show internal moves') }}
    </label>
    <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="nav.patch({ warehouse: null, from: null, to: null, internal: null, page: null })" />
    <div class="grow" />
    <template v-if="d">
      <Btn v-if="me.export" size="sm" tone="soft" :loading="act.pending.value" :label="{ ar: 'تصدير CSV', en: 'Export CSV' }" @click="exportCsv" />
      <Btn size="sm" tone="soft" :label="{ ar: 'طباعة', en: 'Print' }" @click="printPage" />
    </template>
  </div>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />

  <div v-if="!nav.get('sku')" class="empty dashed">{{ t('اكتب SKU المنتج أو امسح باركوده لعرض كرت الصنف: الرصيد الافتتاحي، كل حركة وارد أو صادر، الرصيد بعدها، والرصيد الختامي.', 'Type the product SKU or scan its barcode to see its stock card: the opening balance, every movement in or out, the balance after it, and the closing balance.') }}</div>
  <template v-else>
    <ErrorBanner :error="card.error.value" :closable="false" />
    <div v-if="!d && card.isLoading.value" class="skel h-[240px]" />
    <template v-else-if="d">
      <div class="mb-2">
        <div class="text-[14px] font-extrabold text-ink" dir="auto">{{ nameOf(d.product) }} <span class="num ltr inline-block text-[11px] text-muted">{{ d.product.sku }}</span></div>
        <div class="text-[10px] text-muted">
          {{ d.warehouse ? `${t('المستودع', 'Warehouse')} ${d.warehouse.code} · ${nameOf(d.warehouse)}` : t('كل المستودعات', 'All warehouses') }}
          · {{ t('الفترة', 'Period') }} <span class="num ltr inline-block">{{ fmtDay(d.from) }} → {{ fmtDay(d.to) }}</span>
          <template v-if="internal"> · {{ t('يشمل النقل الداخلي', 'internal moves included') }}</template>
        </div>
      </div>
      <KpiGrid compact>
        <KpiCard compact :value="d.opening" :label="{ ar: 'الرصيد الافتتاحي', en: 'Opening' }" />
        <KpiCard compact :value="d.in" :label="{ ar: 'الوارد', en: 'In' }" color="#1d7a3e" />
        <KpiCard compact :value="d.out" :label="{ ar: 'الصادر', en: 'Out' }" color="#b23b3b" />
        <KpiCard compact :value="d.closing" :label="{ ar: 'الرصيد الختامي', en: 'Closing' }" color="#654e92" />
        <KpiCard compact :value="d.onHandNow" :label="{ ar: 'الرصيد الآن', en: 'On hand now' }" color="#0d7f93" />
      </KpiGrid>
      <div class="hint teal !mt-2 mb-2"><b>{{ t('التعريف', 'Definition') }}:</b> {{ bi(d.definition) }}</div>
      <div v-if="mismatch" class="banner amber">{{ t('الرصيد الختامي لا يساوي الرصيد الحالي في الأرصدة — راجع مطابقة المخزون (scm:reconcile).', 'The closing balance differs from the on-hand balance — check the inventory reconciliation (scm:reconcile).') }}</div>
      <div class="card">
        <DataTable :columns="columns" :paged="paged" :loading="card.isFetching.value" :row-key="(r) => r.id" :row-style="rowStyle" @page="nav.setPage"
                   :empty-text="{ ar: 'لا حركات في الفترة.', en: 'No movement in the period.' }">
          <template #cell-at="{ row }"><span class="cell-date">{{ row.synthetic ? fmtDay(row.at) : fmtDate(row.at) }}</span></template>
          <template #cell-number="{ row }">
            <span v-if="row.synthetic" class="text-[10.5px] font-extrabold text-violet">{{ row.synthetic === 'opening' ? t('رصيد افتتاحي', 'Opening balance') : t('رصيد ختامي', 'Closing balance') }}</span>
            <RouterLink v-else-if="moveLink(row)" class="cell-id text-azure underline" :to="moveLink(row)">{{ row.number }}</RouterLink>
            <span v-else class="cell-id">{{ row.number }}</span>
          </template>
          <template #cell-type="{ row }"><Chip v-if="!row.synthetic" small :map="MOVEMENT_LABELS" :k="row.type" /></template>
          <template #cell-reference="{ row }">
            <template v-if="!row.synthetic && row.reference">
              <RouterLink v-if="refLink(row)" class="cell-id text-azure underline" :to="refLink(row)">{{ row.reference.number }}</RouterLink>
              <span v-else class="cell-id">{{ row.reference.number || '—' }}</span>
            </template>
            <span v-else-if="!row.synthetic" class="text-faint">—</span>
          </template>
          <template #cell-batchNo="{ row }">{{ row.synthetic ? '' : row.batchNo || '—' }}</template>
          <template #cell-route="{ row }"><span v-if="!row.synthetic" class="num ltr truncate text-[10px] text-sec">{{ place(row.src) }} → {{ place(row.dst) }}</span></template>
          <template #cell-in="{ row }"><span v-if="row.in" class="font-bold text-ok">{{ fmtNum(row.in) }}</span><span v-else-if="!row.synthetic" class="text-faint">0</span></template>
          <template #cell-out="{ row }"><span v-if="row.out" class="font-bold text-bad">{{ fmtNum(row.out) }}</span><span v-else-if="!row.synthetic" class="text-faint">0</span></template>
          <template #cell-balance="{ row }"><b>{{ fmtNum(row.balance) }}</b></template>
          <template #cell-user="{ row }">{{ row.synthetic ? '' : row.user || '—' }}</template>
          <template #cell-note="{ row }"><span class="truncate" dir="auto">{{ row.synthetic ? '' : row.note || '' }}</span></template>
        </DataTable>
      </div>
      <div class="mt-1.5 text-[9px] text-faint">{{ t('الطباعة تطبع الصفحة المعروضة (100 حركة)؛ للكرت كاملًا صدّر الملف.', 'Print prints the page on screen (100 movements); export the file for the whole card.') }}</div>
    </template>
  </template>
</template>
