<script setup>
// «قوائم العملاء» — the customer price lists: figures, search, filters (state · origin), sort and paging on the server,
// all in the query string:   /pricing?tab=lists&state=all&source=odoo&q=فرنشايز&sort=rulesCount&order=desc
// GET /pricing/lists → { items, total, page, pageSize, pages }. A row opens the list (`list=<code>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { ARCHIVED_CHIP, DEFAULT_CHIP, OWNED_CHIP, PERIOD, TONE, periodText, usePricingLookups, usePricingNav, useSearchParam } from './pricing';

const props = defineProps({
  /** GET /pricing/summary (the page asks for it once). */
  summary: { type: Object, default: null },
});

const nav = usePricingNav();
const { page, listOpen } = nav;
const { follows } = usePricingLookups();
const { text, type, apply } = useSearchParam(nav);

const STATES = [{ k: 'active', label: { ar: 'الفعّالة', en: 'Active' } }, { k: 'archived', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } }];
const ORIGIN = [{ v: 'ops', l: { ar: 'أُنشئت هنا', en: 'Made in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const SORTS = ['name', 'code', 'rulesCount', 'customersCount', 'updatedAt'];
const state = computed(() => (STATES.some((s) => s.k === nav.get('state')) ? nav.get('state') : 'active'));
const sort = computed(() => nav.sortOf(SORTS));
const list = useList('/pricing/lists', () => ({
  q: nav.get('q'), state: state.value, source: nav.get('source') || undefined, sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const s = computed(() => props.summary);
const filtered = computed(() => !!nav.get('q') || !!nav.get('source') || state.value !== 'active');

const columns = [
  { key: 'name', header: { ar: 'القائمة', en: 'List' }, width: 'minmax(220px,2fr)', sortable: true },
  { key: 'currency', header: { ar: 'العملة', en: 'Currency' }, width: '70px', kind: 'id' },
  { key: 'rulesCount', header: { ar: 'القواعد', en: 'Rules' }, width: '76px', kind: 'num', sortable: true, value: (r) => fmtNum(r.activeRulesCount) },
  { key: 'customersCount', header: { ar: 'العملاء', en: 'Customers' }, width: '76px', kind: 'num', sortable: true, value: (r) => fmtNum(r.customersCount) },
  { key: 'validity', header: { ar: 'الصلاحية', en: 'Validity' }, width: 'minmax(150px,1fr)' },
  { key: 'flags', header: { ar: 'الحالة', en: 'Status' }, width: '170px', bare: true },
];
</script>

<template>
  <KpiGrid compact class="!mb-3">
    <KpiCard compact :value="s?.lists?.active" :label="{ ar: 'قوائم فعّالة', en: 'Active lists' }" :loading="!s" :sub="s?.lists?.fromOdoo ? t(`${fmtNum(s.lists.fromOdoo)} من أودو`, `${fmtNum(s.lists.fromOdoo)} from Odoo`) : null" />
    <KpiCard compact :value="s?.rules?.active" :label="{ ar: 'قواعد تسعير', en: 'Pricing rules' }" color="#654e92" :loading="!s"
             :sub="s?.rules?.pending ? t(`${fmtNum(s.rules.pending)} بانتظار ربط المنتج`, `${fmtNum(s.rules.pending)} wait for their product`) : null" />
    <KpiCard compact clickable :value="s?.customers?.assigned" :label="{ ar: 'عملاء لهم قائمة خاصة', en: 'Customers with a list of their own' }" color="#1d7a3e" :loading="!s" @click="nav.go('customers', { assigned: 'yes' })" />
    <KpiCard compact clickable :value="s?.customers?.onDefault" :label="{ ar: 'عملاء على القائمة الافتراضية', en: 'Customers on the default list' }" :color="TONE.blue.fg" :loading="!s"
             :sub="s?.defaultList ? s.defaultList.name : s ? t('لا قائمة افتراضية: السعر المعلن للمنتج', 'no default list: the product list price') : null" @click="nav.go('customers', { assigned: 'no' })" />
    <KpiCard compact clickable :value="s?.listPrices?.missing" :label="{ ar: 'منتجات بلا سعر معلن', en: 'Products without a list price' }" :color="s?.listPrices?.missing ? TONE.warn.fg : TONE.ok.fg" :loading="!s" @click="nav.go('base', { has: 'no' })" />
  </KpiGrid>

  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'بحث باسم القائمة أو رمزها…', en: 'Search by list name or code…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="state" :tabs="STATES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k === 'active' ? null : k)" />
    <SelectInput v-if="follows" small class="w-[140px]" :model-value="nav.get('source')" :options="ORIGIN" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.code" :selected-key="listOpen" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا قوائم تطابق البحث والفلاتر.', en: 'No list matches the search and filters.' } : { ar: 'لا قوائم أسعار بعد — أضف أول قائمة من «+ قائمة أسعار».', en: 'No price list yet — add the first one with “+ Price list”.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.openList(r.code)">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.name }}</div>
          <div class="cell-sub">{{ row.code }}<template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template></div>
        </div>
      </template>
      <template #cell-validity="{ row }">
        <span class="text-[10px] text-sec">{{ periodText(row.validFrom, row.validTo) }}</span>
        <Chip v-if="row.period !== 'valid'" small class="ms-1" :map="PERIOD" :k="row.period" />
      </template>
      <template #cell-flags="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip v-if="row.isDefault" small :label="DEFAULT_CHIP" :fg="DEFAULT_CHIP.fg" :bg="DEFAULT_CHIP.bg" />
          <Chip v-if="!row.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
          <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
          <span v-if="!row.isDefault && row.active && !row.owned" class="text-faint">—</span>
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">
    {{ t('لكل عميل قائمة واحدة على الأكثر؛ من لا قائمة له يُسعَّر بالقائمة الافتراضية، وما لا قاعدة له في القائمة يُسعَّر بالسعر المعلن للمنتج. أي قاعدة تحسم: الأخص (منتج ثم فئة ثم الكل)، ثم الأعلى حدًّا أدنى للكمية، ثم الأحدث.',
      'A customer has one list at most; a customer without one is priced with the default list, and a product no rule of the list covers is priced at its list price. Which rule decides: the most specific (product, then category, then everything), then the highest minimum quantity, then the newest.') }}
  </div>
</template>
