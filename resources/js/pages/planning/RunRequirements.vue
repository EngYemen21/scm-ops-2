<script setup>
// The purchase requirements of a run — what to order, when (order by), for when (need date) and why — with their totals.
// GET /planning/runs/{id}/requirements → { items, total, page, pageSize, pages, totals { qty, value } }. Keys r* of the query
// string. A row opens its item.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { CRITICALITY, PRICE_SOURCE, fmtDay, label, money, nameOf, options, qty, usePlanningLookups, usePlanningNav, useSearchParam } from './planning';

const props = defineProps({ run: { type: Object, required: true } });

const nav = usePlanningNav();
const lk = usePlanningLookups();
const { text, type, apply } = useSearchParam(nav, 'rq', 'rpage');
const SORTS = ['orderBy', 'needDate', 'qty', 'value', 'sku', 'shortage'];
const sort = computed(() => nav.sortOf('rsort', 'rorder', SORTS));
const setSort = (s) => nav.patch({ rsort: s.key, rorder: s.order, rpage: null });
const set = (k, v) => nav.setFilter(k, v, 'rpage');
const list = useList(() => `/planning/runs/${props.run.id}/requirements`, () => ({
  q: nav.get('rq') || undefined, warehouse: nav.get('rwh') || undefined, criticality: nav.get('rcrit') || undefined, priceSource: nav.get('rprice') || undefined,
  late: nav.get('rlate') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.pageOf('rpage'), pageSize: 50,
}));
const totals = computed(() => list.data.value?.totals || null);

const KEYS = ['rq', 'rwh', 'rcrit', 'rprice', 'rlate'];
const filtered = computed(() => KEYS.some((k) => !!nav.get(k)));
const clear = () => nav.patch(Object.fromEntries([...KEYS, 'rpage'].map((k) => [k, null])));
const LATE = [{ v: 'true', l: { ar: 'متأخرة (موعد الطلب مضى)', en: 'Late (order day passed)' } }, { v: 'false', l: { ar: 'في موعدها', en: 'On time' } }];

const columns = [
  { key: 'sku', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.5fr)', sortable: true },
  { key: 'warehouseCode', header: { ar: 'المستودع', en: 'Whs' }, width: '64px' },
  { key: 'periodLabel', header: { ar: 'الفترة', en: 'Period' }, width: '84px' },
  { key: 'orderBy', header: { ar: 'اطلب قبل', en: 'Order by' }, width: '96px', kind: 'date', sortable: true },
  { key: 'needDate', header: { ar: 'مطلوب في', en: 'Need date' }, width: '84px', kind: 'date', sortable: true, value: (r) => fmtDay(r.needDate) },
  { key: 'shortage', header: { ar: 'نفاد', en: 'Stock-out' }, width: '64px', kind: 'num', sortable: true, value: (r) => qty(r.stockout) },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '66px', kind: 'num', sortable: true, value: (r) => qty(r.qty) },
  { key: 'priceUnit', header: { ar: 'السعر', en: 'Price' }, width: '76px', kind: 'num', value: (r) => (r.priceUnit == null ? '—' : qty(r.priceUnit)) },
  { key: 'value', header: { ar: 'القيمة', en: 'Value' }, width: '96px', kind: 'num', sortable: true, value: (r) => money(r.value) },
  { key: 'vendor', header: { ar: 'المورد', en: 'Vendor' }, width: 'minmax(100px,1fr)', value: (r) => r.vendor?.name || '—' },
];
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="ph('بحث SKU، اسم، مورد…', 'Search SKU, name, vendor…')" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('rwh')" :options="lk.warehouseOptions.value" :placeholder="ph('كل المستودعات', 'All warehouses')" @update:model-value="(v) => set('rwh', v)" />
    <SelectInput small class="w-[120px]" :model-value="nav.get('rcrit')" :options="options(CRITICALITY)" :placeholder="ph('أي أهمية', 'Any criticality')" @update:model-value="(v) => set('rcrit', v)" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('rprice')" :options="options(PRICE_SOURCE)" :placeholder="ph('أي مصدر سعر', 'Any price source')" @update:model-value="(v) => set('rprice', v)" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('rlate')" :options="LATE" :placeholder="ph('متأخرة أو لا', 'Late or not')" @update:model-value="(v) => set('rlate', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="ph('إزالة الفلاتر', 'Clear filters')" @click="clear" />
  </div>
  <div v-if="totals" class="row wrap mb-2 !gap-2">
    <div class="tile"><div class="tile-v">{{ qty(totals.qty) }}</div><div class="tile-l">{{ t('إجمالي الكمية (حسب الفلتر)', 'Total qty (filtered)') }}</div></div>
    <div class="tile green"><div class="tile-v">{{ money(totals.value) }}</div><div class="tile-l">{{ t('إجمالي القيمة (حسب الفلتر)', 'Total value (filtered)') }}</div></div>
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort compact
               :empty-text="filtered ? { ar: 'لا احتياجات تطابق البحث والفلاتر.', en: 'No requirement matches the search and filters.' } : { ar: 'لا احتياجات شراء في هذا التشغيل.', en: 'No purchase requirement in this run.' }"
               @sort="setSort" @page="(p) => nav.patch({ rpage: p > 1 ? p : null })" @row-click="(r) => nav.patch({ item: r.itemId })">
      <template #cell-sku="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
          <div class="cell-sub"><span class="num ltr inline-block">{{ row.sku }}</span><template v-if="row.criticality && row.criticality !== 'normal'"> · <Chip small :map="CRITICALITY" :k="row.criticality" /></template><template v-if="row.priceSource"> · {{ label(PRICE_SOURCE, row.priceSource) }}</template></div>
        </div>
      </template>
      <template #cell-orderBy="{ row }"><span class="num" :class="row.late ? 'font-extrabold text-bad' : ''">{{ fmtDay(row.orderBy) }}<template v-if="row.late"> · {{ t('متأخر', 'late') }}</template></span></template>
    </DataTable>
  </div>
</template>
