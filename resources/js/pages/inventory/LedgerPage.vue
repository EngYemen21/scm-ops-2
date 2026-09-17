<script setup>
// Inventory ledger (nav `ledger`): append-only movement log — type chips, sku / bin / reference / date / user filters
// (URL-driven so other pages can deep-link `?referenceNumber=` / `?sku=` / `?q=`), source → destination columns,
// transaction drawer and reference trace.
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, PageHead, SectionCard, TextInput } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { toast } from '@/stores/ui';
import { MOVEMENT_LABELS } from '@/shared';
import { useWarehouse } from '@/stores/warehouse';
import Hint from './Hint.vue';
import MovementDrawer from './MovementDrawer.vue';
import ProductCell from './ProductCell.vue';
import RefLink from './RefLink.vue';
import TraceDrawer from './TraceDrawer.vue';
import { fmtSigned, locLabel, signColor, useDebounced } from './shared';

const TEXT_KEYS = ['q', 'sku', 'bin', 'referenceNumber', 'user'];

const wh = useWarehouse();
const route = useRoute();
const router = useRouter();

// ---- the URL query is the source of truth for every filter ----
const get = (k) => (typeof route.query[k] === 'string' ? route.query[k] : '');
/** Patch the query string (`undefined` / '' removes a key). Any filter change returns to page 1 unless `keepPage`. */
function setParams(patch, keepPage = false) {
  const n = { ...route.query };
  Object.entries(patch).forEach(([k, v]) => { if (v) n[k] = v; else delete n[k]; });
  if (!keepPage) delete n.page;
  router.replace({ query: n });
}
// Text inputs are local + debounced, then pushed to the URL; `pushed` remembers what we last wrote so that only
// external URL changes (deep links from other pages) re-seed the inputs — never our own in-flight keystrokes.
const fromUrl = () => Object.fromEntries(TEXT_KEYS.map((k) => [k, get(k)]));
const txt = reactive(fromUrl());
let pushed = { ...txt };
const dtxt = useDebounced(() => ({ ...txt }));
watch(dtxt, (d) => { if (TEXT_KEYS.some((k) => (d[k] || '') !== (pushed[k] || ''))) { pushed = d; setParams(d); } });
watch(() => route.query, () => { const u = fromUrl(); if (TEXT_KEYS.some((k) => u[k] !== (pushed[k] || ''))) { pushed = u; Object.assign(txt, u); } });

const type = computed(() => get('type'));
const from = computed(() => get('from'));
const to = computed(() => get('to'));
const page = computed(() => Number(get('page')) || 1);
watch(() => wh.wh, () => { if (page.value !== 1) setParams({ page: undefined }); });

const list = useList('/inventory/ledger', () => ({ ...wh.whParams, q: get('q'), sku: get('sku'), bin: get('bin'), referenceNumber: get('referenceNumber'), user: get('user'), type: type.value, from: from.value, to: to.value, page: page.value, pageSize: 50 }));

const activeFilters = computed(() => TEXT_KEYS.filter((k) => get(k)).length + (type.value ? 1 : 0) + (from.value ? 1 : 0) + (to.value ? 1 : 0));
function clearFilters() {
  TEXT_KEYS.forEach((k) => { txt[k] = ''; });
  setParams({ q: undefined, sku: undefined, bin: undefined, referenceNumber: undefined, user: undefined, type: undefined, from: undefined, to: undefined });
}

// ---- drawers ----
/** Movement number open in the transaction drawer. */
const sel = ref(null);
/** Document number open in the trace drawer. */
const trace = ref(null);
const traceInput = ref('');
const runTrace = () => {
  const n = traceInput.value.trim();
  if (n) trace.value = n; else toast.say({ ar: 'اكتب رقم المستند أولًا (مثل GRN-2026-0001)', en: 'Enter a document number first (e.g. GRN-2026-0001)' });
};

const cols = [
  { key: 'number', header: { ar: 'الحركة Tx', en: 'Tx ID' }, width: '112px' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '108px' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(160px,1.4fr)' },
  { key: 'src', header: { ar: 'من موقع', en: 'Source' }, width: '105px' },
  { key: 'dst', header: { ar: 'إلى موقع', en: 'Destination' }, width: '105px' },
  { key: 'qty', header: { ar: 'الكمية ±', en: 'Qty ±' }, width: '80px', kind: 'num' },
  { key: 'beforeQty', header: { ar: 'قبل', en: 'Before' }, width: '66px', kind: 'num' },
  { key: 'afterQty', header: { ar: 'بعد', en: 'After' }, width: '66px', kind: 'num', value: (m) => (m.afterQty == null ? '—' : fmtNum(m.afterQty)) },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '88px' },
  { key: 'doc', header: { ar: 'المستند · المستخدم', en: 'Doc · User' }, width: 'minmax(130px,1fr)' },
  { key: 'createdAt', header: { ar: 'التاريخ والوقت', en: 'Timestamp' }, width: '108px', kind: 'date', value: (m) => fmtDate(m.createdAt) },
];
</script>

<template>
  <PageHead :sub="t('سجل append-only — كل حركة موثقة بالمستند والمستخدم والوقت · لا حذف ولا تعديل', 'Append-only log — every movement carries its document, user and time · no delete, no edit')">
    <div class="row !gap-1.5">
      <TextInput v-model="traceInput" small dir="ltr" mono class="min-w-[230px]" :placeholder="{ ar: 'تتبع مستند: GRN-… / SO-… / TRF-…', en: 'Trace: GRN-… / SO-… / TRF-…' }" @enter="runTrace" />
      <Btn tone="dark" size="sm" :label="{ ar: 'تتبع', en: 'Trace' }" @click="runTrace" />
    </div>
  </PageHead>

  <div class="pill-bar">
    <button type="button" class="pill" :class="{ active: !type }" @click="setParams({ type: undefined })">{{ t('كل الأنواع', 'All types') }}</button>
    <button v-for="(l, k) in MOVEMENT_LABELS" :key="k" type="button" class="pill" :class="{ active: type === k }" :style="type === k ? null : { color: l.fg }" @click="setParams({ type: type === k ? undefined : k })">{{ t(l.ar, l.en) }}</button>
  </div>
  <div class="row wrap items-end">
    <div class="min-w-[250px] flex-1"><TextInput v-model="txt.q" small type="search" :placeholder="{ ar: 'بحث: رقم حركة / مستند / SKU / دفعة / ملاحظة', en: 'Search: tx / doc / SKU / batch / note' }" /></div>
    <TextInput v-model="txt.sku" small dir="ltr" mono class="!w-[120px]" placeholder="SKU" />
    <TextInput v-model="txt.bin" small dir="ltr" mono class="!w-[110px]" :placeholder="{ ar: 'موقع Bin', en: 'Bin' }" />
    <TextInput v-model="txt.referenceNumber" small dir="ltr" mono class="!w-[140px]" :placeholder="{ ar: 'رقم المستند', en: 'Reference no.' }" />
    <TextInput v-model="txt.user" small class="!w-[110px]" :placeholder="{ ar: 'المستخدم', en: 'User' }" />
    <DateInput small :model-value="from" :label="{ ar: 'من', en: 'From' }" @update:model-value="setParams({ from: $event })" />
    <DateInput small :model-value="to" :label="{ ar: 'إلى', en: 'To' }" @update:model-value="setParams({ to: $event })" />
    <Btn v-if="activeFilters > 0" tone="ghost" size="sm" :label="{ ar: `إزالة الفلاتر (${activeFilters})`, en: `Clear filters (${activeFilters})` }" @click="clearFilters" />
  </div>

  <SectionCard class="mt-3" :padded="false" :title="{ ar: 'حركات المخزون — الأحدث أولًا', en: 'Inventory movements — newest first' }">
    <template #actions><span class="text-[10px] text-faint">append-only · {{ t('لا حذف ولا تعديل', 'no delete, no edit') }} · <b class="num text-ink">{{ fmtNum(list.data.value?.total ?? 0) }}</b></span></template>
    <ErrorBanner :error="list.error.value" :closable="false" class="m-3" />
    <DataTable :columns="cols" :paged="list.data.value" :loading="list.isFetching.value" :min-width="1180" :row-key="(m) => m.id" :selected-key="sel" :empty-text="{ ar: 'لا حركات مطابقة.', en: 'No matching movements.' }"
               @page="(p) => setParams({ page: String(p) }, true)" @row-click="(m) => (sel = m.number)">
      <template #cell-number="{ row }"><span class="num ltr inline-block text-[9.5px] text-sec">{{ row.number }}</span></template>
      <template #cell-type="{ row }"><Chip small :map="MOVEMENT_LABELS" :k="row.type" /></template>
      <template #cell-product="{ row }"><ProductCell :p="row.product" /></template>
      <template #cell-src="{ row }"><span class="num ltr inline-block text-[9.5px]" :class="row.src ? 'text-brand-dark' : 'text-faint'">{{ locLabel(row.src) }}</span></template>
      <template #cell-dst="{ row }"><span class="num ltr inline-block text-[9.5px]" :class="row.dst ? 'text-brand-dark' : 'text-faint'">{{ locLabel(row.dst) }}</span></template>
      <template #cell-qty="{ row }"><span class="text-[12px]" :style="{ color: signColor(row.signedQty) }">{{ fmtSigned(row.signedQty) }}</span></template>
      <template #cell-beforeQty="{ row }"><span class="font-normal text-faint">{{ row.beforeQty == null ? '—' : fmtNum(row.beforeQty) }}</span></template>
      <template #cell-batchNo="{ row }"><span class="cell-id !text-[9px]">{{ row.batchNo || '—' }}</span></template>
      <template #cell-doc="{ row }">
        <div class="min-w-0">
          <div class="text-[9.5px]" @click.stop><RefLink :type="row.referenceType" :number="row.referenceNumber" /></div>
          <div class="mt-px text-[8px] text-faint">{{ row.username || '—' }}{{ row.note ? ` · ${row.note}` : '' }}</div>
        </div>
      </template>
    </DataTable>
  </SectionCard>
  <Hint>{{ t('الكمية ± تعكس أثر الحركة على الموقع: + دخول (استلام/تخزين/تحويل وارد/مرتجع)، − خروج (صرف/تحميل/عبور/إعدام)، ونقل بين موقعين يظهر بالمصدر والوجهة. «قبل/بعد» رصيد الموقع المتأثر. اضغط الصف لعرض المعاملة كاملة، أو اكتب رقم مستند في «تتبع» لعرض كل حركاته من الاستلام حتى التسليم.', 'Qty ± is the effect on the bin: + inbound (receipt / putaway / transfer in / return), − outbound (pick / load / transit / scrap); bin moves show source and destination. Before / after = the affected bin balance. Click a row for the full transaction, or enter a document number in Trace to follow it end-to-end.') }}</Hint>

  <!-- The transaction drawer comes last so it opens above the trace drawer when a traced movement is clicked. -->
  <TraceDrawer :reference="trace" @close="trace = null" @open-movement="(n) => (sel = n)" />
  <MovementDrawer :number="sel" traceable @close="sel = null" @trace="(r) => { sel = null; trace = r; }" />
</template>
