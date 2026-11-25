<script setup>
// The log of order-line changes: who added, changed or removed which product on which order, with the value before
// and after. Ten thousand lines and growing: read a page at a time, newest first, with its filters — all on the
// server and all in the query string:
//   /portal?tab=requests&view=changes&type=modified&field=price&orderRef=S01234&product=SKU-9&from=2026-11-01&to=2026-11-30
// GET /portal/line-changes → { items, total, page, pageSize, pages, totals: { added, modified, removed } } — the
// totals count each kind under the same filters, whatever kind is being looked at.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, DateInput, ErrorBanner, Tabs, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtNum, t } from '@/i18n';
import PartyName from './PartyName.vue';
import PortalFilters from './PortalFilters.vue';
import ProductPick from './ProductPick.vue';
import { CHANGE_FIELD, CHANGE_TYPE, labelOf, moment, usePortalNav, useSearchParam } from './portal';

const nav = usePortalNav();
const { page } = nav;
/** A date box says what it is by its tooltip on a wide screen and by a caption on a phone. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);

const type = computed(() => (CHANGE_TYPE[nav.get('type')] ? nav.get('type') : ''));
const field = computed(() => (CHANGE_FIELD[nav.get('field')] ? nav.get('field') : ''));
const product = computed(() => nav.get('product'));
const request = computed(() => nav.get('request'));
const order = computed(() => (nav.get('order') === 'asc' ? 'asc' : 'desc'));
const { text: orderText, type: orderType, apply: orderApply } = useSearchParam(nav, 'orderRef');

const list = useList('/portal/line-changes', () => ({
  q: nav.get('q'), customer: nav.get('customer'), origin: nav.get('origin'), type: type.value, field: field.value, product: product.value, orderRef: nav.get('orderRef'), request: request.value,
  by: nav.get('by'), from: nav.get('from'), to: nav.get('to'), order: order.value, page: page.value, pageSize: 50,
}));
const totals = computed(() => list.data.value?.totals || null);
const typeTabs = computed(() => [
  { k: '', label: { ar: 'كل التغييرات', en: 'Every change' }, badge: totals.value ? fmtNum(totals.value.added + totals.value.modified + totals.value.removed) : null },
  ...Object.entries(CHANGE_TYPE).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en }, badge: totals.value ? fmtNum(totals.value[k]) : null })),
]);
const FIELD_TABS = [{ k: '', label: { ar: 'أي حقل', en: 'Any field' } }, ...Object.entries(CHANGE_FIELD).map(([k, l]) => ({ k, label: l }))];
const ORDER_TABS = [{ k: 'desc', label: { ar: 'الأحدث أولًا', en: 'Newest first' } }, { k: 'asc', label: { ar: 'الأقدم أولًا', en: 'Oldest first' } }];

function onProduct(p, text) {
  if (p) nav.setFilter('product', p.sku);
  else if (!String(text || '').trim() && product.value) nav.setFilter('product', '');
}

const columns = [
  { key: 'changedAt', header: { ar: 'الوقت', en: 'When' }, width: '124px', kind: 'date', value: (r) => moment(r.changedAt) },
  { key: 'order', header: { ar: 'الطلب', en: 'Order' }, width: '128px' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(150px,1.2fr)' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.5fr)' },
  { key: 'type', header: { ar: 'التغيير', en: 'Change' }, width: '112px', bare: true },
  { key: 'before', header: { ar: 'قبل', en: 'Before' }, width: '82px', kind: 'num' },
  { key: 'after', header: { ar: 'بعد', en: 'After' }, width: '82px', kind: 'num' },
  { key: 'changedBy', header: { ar: 'غيّره', en: 'Changed by' }, width: 'minmax(110px,1fr)' },
];
</script>

<template>
  <PortalFilters origin :search="{ ar: 'بحث برقم الأمر أو المنتج أو من غيّر…', en: 'Search order, product or who changed…' }">
    <TextInput small class="w-[150px]" dir="ltr" :model-value="orderText" :placeholder="{ ar: 'رقم الأمر', en: 'Order number' }" @update:model-value="orderType" @enter="orderApply" />
    <div class="w-[200px]"><ProductPick small :value="product" @pick="onProduct" /></div>
    <DateInput small field-class="w-[140px]" :label="cap('من تاريخ', 'From')" :model-value="nav.get('from')" :max="nav.get('to') || null" :title="t('من تاريخ', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small field-class="w-[140px]" :label="cap('إلى تاريخ', 'To')" :model-value="nav.get('to')" :min="nav.get('from') || null" :title="t('إلى تاريخ', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
  </PortalFilters>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="type" :tabs="typeTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('type', k)" />
    <Tabs :model-value="field" :tabs="FIELD_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('field', k)" />
    <button v-if="request" type="button" class="pill active" @click="nav.setFilter('request', '')">{{ t('طلب واحد ✕', 'One request ✕') }}</button>
    <button v-if="nav.get('by')" type="button" class="pill active" dir="auto" @click="nav.setFilter('by', '')">{{ nav.get('by') }} ✕</button>
    <span class="grow" />
    <Tabs :model-value="order" :tabs="ORDER_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.patch({ order: k === 'asc' ? 'asc' : null, page: null })" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" dense
               :empty-text="{ ar: 'لا تغييرات تطابق البحث والفلاتر.', en: 'No change matches the search and filters.' }" @page="nav.setPage">
      <template #cell-order="{ row }">
        <button v-if="row.request" type="button" class="cell-id cursor-pointer border-0 bg-transparent p-0" :title="t('افتح طلب البوابة', 'Open the portal request')" @click="nav.go('requests', { req: row.request.id })">{{ row.request.number }}</button>
        <button v-else-if="row.order" type="button" class="cell-id cursor-pointer border-0 bg-transparent p-0" :title="t('كل تغييرات هذا الأمر', 'Every change of this order')" @click="nav.setFilter('orderRef', row.order.number || row.order.ref)">{{ row.order.number || `#${row.order.ref}` }}</button>
        <span v-else class="text-faint">—</span>
        <div v-if="row.branch" class="text-[9px] text-muted" dir="auto">{{ row.branch.name }}</div>
      </template>
      <template #cell-customer="{ row }"><PartyName :party="row.customer" /></template>
      <template #cell-product="{ row }">
        <PartyName v-if="row.product" :party="row.product" product />
        <span v-else class="truncate" dir="auto">{{ row.productName || '—' }}</span>
      </template>
      <template #cell-type="{ row }">
        <Chip small :map="CHANGE_TYPE" :k="row.type" />
        <div v-if="row.field || row.fieldLabel" class="mt-0.5 text-[9px] text-muted">{{ row.field ? labelOf(CHANGE_FIELD, row.field) : row.fieldLabel }}</div>
      </template>
      <template #cell-before="{ row }"><span v-if="row.oldValue != null" class="num text-muted line-through">{{ row.oldValue }}</span><span v-else class="text-faint">—</span></template>
      <template #cell-after="{ row }"><span v-if="row.newValue != null" class="num font-extrabold">{{ row.newValue }}</span><span v-else class="text-faint">—</span></template>
      <template #cell-changedBy="{ row }">
        <button v-if="row.changedBy" type="button" class="cursor-pointer truncate border-0 bg-transparent p-0 text-[10.5px] font-bold text-ink" dir="auto" :title="t('كل ما غيّره', 'Everything this person changed')" @click="nav.setFilter('by', row.changedBy)">{{ row.changedBy }}</button>
        <span v-else class="text-faint">—</span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('السجل لا يُعدَّل ولا يُحذف منه: كل سطر هو ما جرى على سطر طلب — في أودو من العميل أو فريق المبيعات، أو هنا عند تصحيح طلب بانتظار القرار.', 'The log is never edited and nothing is removed from it: each line is what happened to an order line — in Odoo by the customer or the sales team, or here when a pending request is corrected.') }}</div>
</template>
