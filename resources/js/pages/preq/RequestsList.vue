<script setup>
// One list of new-product requests — the same view for «كل الطلبات» and «طلباتي».
// GET /product-requests?scope=&status=&category=&supplier=&requester=&origin=&type=&hasProduct=&from=&to=&q=&sort=&order=&page=
// Search, filters, sort and page live in the query string; a row opens the request (`open=<number>`).
// The picture of a row is fetched only when the row comes on screen.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import PickInput from './PickInput.vue';
import PreqPicture from './PreqPicture.vue';
import {
  API, EXISTING_CHIP, ORIGINS, SOURCE_CHIP, STATUS, STATUS_FILTERS, TYPES, ageText, ageTone, fmtAt, fmtPrice, otherName, personName, productName, refName, useMaster, usePreqNav, useSearchParam, useSection,
} from './preq';

const props = defineProps({
  /** all | mine */
  scope: { type: String, required: true },
  /** GET /product-requests/summary — the counts on the status pills and whether the section holds requests of Odoo. */
  summary: { type: Object, default: null },
});

const nav = usePreqNav();
const { page, sort, filters, filtered, requestOpen } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const section = useSection();
const master = useMaster();

const list = useList(API, () => ({
  scope: props.scope, ...filters.value, requester: props.scope === 'mine' ? '' : filters.value.requester,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));

const hasOdoo = computed(() => (props.summary?.fromOdoo || 0) > 0);
/** The counts of the page head belong to the whole section: they are shown on the pills of «كل الطلبات» only. */
const counts = computed(() => (props.scope === 'all' ? props.summary?.byStatus : null));
const countOf = (k) => {
  const c = counts.value;
  if (!c) return null;
  if (k === 'open') return c.draft + c.submitted + c.in_review + c.returned;
  if (k === 'waiting') return c.submitted + c.in_review;
  return c[k] ?? null;
};
const withCount = (label, n) => (n == null ? label : { ar: `${label.ar} (${fmtNum(n)})`, en: `${label.en} (${fmtNum(n)})` });
const statusTabs = computed(() => [
  { k: '', label: withCount({ ar: 'الكل', en: 'All' }, props.scope === 'all' ? props.summary?.total : null) },
  ...Object.entries(STATUS_FILTERS).map(([k, label]) => ({ k, label: withCount(label, countOf(k)) })),
]);
const originTabs = [{ k: '', label: { ar: 'كل المصادر', en: 'Every origin' } }, ...Object.entries(ORIGINS).map(([k, label]) => ({ k, label }))];
const typeOptions = Object.entries(TYPES).map(([v, l]) => ({ v, l }));
const productOptions = [{ v: '1', l: { ar: 'له صنف في الكتالوج', en: 'Has a catalogue product' } }, { v: '0', l: { ar: 'بلا صنف مرتبط', en: 'No linked product' } }];
const reset = () => nav.go(props.scope);

const columns = computed(() => [
  { key: 'number', header: { ar: 'الطلب', en: 'Request' }, width: 'minmax(260px,2.2fr)', sortable: true },
  { key: 'category', header: { ar: 'التصنيف', en: 'Category' }, width: 'minmax(120px,1fr)', value: (r) => refName(r.category, r.categoryName) || '—' },
  { key: 'requestedBy', header: { ar: 'صاحب الطلب', en: 'Requested by' }, width: '130px', sortable: true, hidden: props.scope === 'mine', value: (r) => personName(r.requestedBy) },
  { key: 'costPrice', header: { ar: 'التكلفة', en: 'Cost' }, width: '104px', kind: 'num', sortable: true, value: (r) => fmtPrice(r.costPrice, r.currency) },
  { key: 'salePrice', header: { ar: 'سعر البيع', en: 'Selling price' }, width: '104px', kind: 'num', sortable: true, value: (r) => fmtPrice(r.salePrice, r.currency) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px', bare: true, sortable: true },
  { key: 'createdAt', header: { ar: 'رُفع', en: 'Raised' }, width: '112px', kind: 'date', sortable: true, value: (r) => fmtAt(r.createdAt) },
]);
const under = (r) => [otherName(r), r.brand, r.barcode].filter(Boolean).join(' · ');
const emptyText = computed(() => {
  if (filtered.value) return { ar: 'لا طلبات مطابقة لهذا البحث.', en: 'No request matches this search.' };
  if (props.scope === 'mine') return { ar: 'لم ترفع طلب صنف بعد — ابدأ من «+ طلب صنف جديد».', en: 'You have not asked for a product yet — start with “+ New product request”.' };
  return { ar: 'لا طلبات أصناف بعد.', en: 'No product requests yet.' };
});
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput scan small type="search" class="w-[250px]" :model-value="qText" :placeholder="{ ar: 'بحث بالاسم، الرقم، الباركود، العلامة، رمز الصنف…', en: 'Search name, number, barcode, brand, product code…' }" @update:model-value="typeQ" @enter="applyQ" />
    <SelectInput small class="w-[170px]" :model-value="filters.category" :options="master.categoryOptions.value" :placeholder="{ ar: 'كل التصنيفات', en: 'Every category' }" @update:model-value="(v) => nav.setFilter('category', v)" />
    <PickInput small field-class="w-[190px]" :model-value="filters.supplier" :options="master.supplierOptions.value" :placeholder="{ ar: 'كل الموردين', en: 'Every supplier' }" @update:model-value="(v) => nav.setFilter('supplier', v)" />
    <SelectInput v-if="scope !== 'mine'" small class="w-[170px]" :model-value="filters.requester" :options="section.requesterOptions.value" :placeholder="{ ar: 'كل أصحاب الطلبات', en: 'Everybody' }" @update:model-value="(v) => nav.setFilter('requester', v)" />
    <SelectInput small class="w-[150px]" :model-value="filters.hasProduct" :options="productOptions" :placeholder="{ ar: 'الصنف: الكل', en: 'Product: any' }" @update:model-value="(v) => nav.setFilter('product', v)" />
    <SelectInput v-if="hasOdoo" small class="w-[150px]" :model-value="filters.type" :options="typeOptions" :placeholder="{ ar: 'النوع: الكل', en: 'Type: any' }" @update:model-value="(v) => nav.setFilter('type', v)" />
    <DateInput small class="w-[136px]" :model-value="filters.from" :max="filters.to || null" :title="t('رُفع من تاريخ', 'Raised from')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[136px]" :model-value="filters.to" :min="filters.from || null" :title="t('رُفع حتى تاريخ', 'Raised until')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="reset" />
  </div>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="filters.status" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k)" />
    <Tabs v-if="hasOdoo" :model-value="filters.origin" :tabs="originTabs" variant="pillPurple" class="!mb-0" @update:model-value="(k) => nav.setFilter('origin', k)" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number" :selected-key="requestOpen" :sort="sort" server-sort :empty-text="emptyText"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.openRequest(r.number)">
      <template #cell-number="{ row }">
        <div class="flex min-w-0 items-center gap-2">
          <PreqPicture :picture="row.picture" class="h-10 w-10 rounded-lg" />
          <div class="min-w-0">
            <div class="flex min-w-0 items-center gap-1.5">
              <span class="cell-id">{{ row.number }}</span>
              <Chip v-if="row.sourceSystem === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
              <Chip v-if="row.requestType === 'existing'" small :label="EXISTING_CHIP" :fg="EXISTING_CHIP.fg" :bg="EXISTING_CHIP.bg" />
            </div>
            <div class="truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto" :title="productName(row)">{{ productName(row) }}</div>
            <div v-if="under(row)" class="truncate text-[9.5px] text-muted [text-align:match-parent]" dir="auto">{{ under(row) }}</div>
          </div>
        </div>
      </template>
      <template #cell-status="{ row }">
        <div class="flex min-w-0 flex-col items-center gap-0.5">
          <Chip small :map="STATUS" :k="row.status" />
          <span v-if="ageText(row)" class="text-[9px] font-bold" :style="{ color: ageTone(row.ageDays).fg }">{{ ageText(row) }}</span>
          <span v-else-if="row.productSku" class="num ltr text-[9.5px] font-bold" :class="row.status === 'approved' ? 'text-ok' : 'text-muted'" :title="row.status === 'approved' ? t('الصنف في الكتالوج', 'The catalogue product') : t('الصنف الموجود في الكتالوج', 'The product the catalogue has')">{{ row.productSku }}</span>
          <span v-if="row.owned && row.sourceState && row.status !== 'approved' && row.status !== 'rejected'" class="max-w-full truncate text-[8.5px] text-violet" :title="bi(row.sourceState)">{{ bi(row.sourceState) }}</span>
        </div>
      </template>
    </DataTable>
  </div>
</template>
