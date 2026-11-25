<script setup>
// The stock items of a run (one per product and warehouse): search, filters (status · warehouse · buy only · fallback ·
// reorder rule · criticality · price source), sort, paging — keys i* of the query string.
// GET /planning/runs/{id}/items?kind=stock → { items, total, page, pageSize, pages }. A row opens the item (`item=<id>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { CRITICALITY, ITEM_STATUS, ORDERPOINT, PRICE_SOURCE, fmtDay, money, nameOf, options, qty, usePlanningLookups, usePlanningNav, useSearchParam } from './planning';

const props = defineProps({ run: { type: Object, required: true } });

const nav = usePlanningNav();
const lk = usePlanningLookups();
const { text, type, apply } = useSearchParam(nav, 'iq', 'ipage');
const SORTS = ['sku', 'forecast', 'shortage', 'surplus', 'suggestedQty', 'suggestedValue', 'orderBy', 'firstShortage', 'coverage', 'onHand', 'confidence', 'warehouse'];
const sort = computed(() => nav.sortOf('isort', 'iorder', SORTS));
const setSort = (s) => nav.patch({ isort: s.key, iorder: s.order, ipage: null });
const set = (k, v) => nav.setFilter(k, v, 'ipage');
const list = useList(() => `/planning/runs/${props.run.id}/items`, () => ({
  kind: 'stock', q: nav.get('iq') || undefined, status: nav.get('istatus') || undefined, warehouse: nav.get('iwh') || undefined, buy: nav.get('ibuy') ? '1' : undefined,
  fallback: nav.get('ifb') || undefined, orderpoint: nav.get('iop') || undefined, criticality: nav.get('icrit') || undefined, priceSource: nav.get('iprice') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.pageOf('ipage'), pageSize: 50,
}));

const KEYS = ['iq', 'istatus', 'iwh', 'ibuy', 'ifb', 'iop', 'icrit', 'iprice'];
const filtered = computed(() => KEYS.some((k) => !!nav.get(k)));
const clear = () => nav.patch(Object.fromEntries([...KEYS, 'ipage'].map((k) => [k, null])));
const FALLBACK = [{ v: 'true', l: { ar: 'بطريقة بديلة', en: 'On a fallback' } }, { v: 'false', l: { ar: 'بالطريقة المطلوبة', en: 'On the method asked' } }];
const empty = computed(() => (props.run.status !== 'computed' && props.run.status !== 'frozen'
  ? { ar: 'لم يُحسب التشغيل بعد — لا بنود.', en: 'The run is not computed yet — no items.' }
  : filtered.value ? { ar: 'لا بنود تطابق البحث والفلاتر.', en: 'No item matches the search and filters.' } : { ar: 'لا بنود في هذا التشغيل.', en: 'No item in this run.' }));

const columns = [
  { key: 'sku', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.6fr)', sortable: true },
  { key: 'warehouse', header: { ar: 'المستودع', en: 'Whs' }, width: '64px', sortable: true, value: (r) => r.warehouseCode || '—' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '84px', bare: true },
  { key: 'onHand', header: { ar: 'المتاح', en: 'On hand' }, width: '64px', kind: 'num', sortable: true, value: (r) => qty(r.onHand) },
  { key: 'forecast', header: { ar: 'التوقع', en: 'Forecast' }, width: '66px', kind: 'num', sortable: true, value: (r) => qty(r.forecast) },
  { key: 'shortage', header: { ar: 'العجز', en: 'Short' }, width: '62px', kind: 'num', sortable: true, value: (r) => qty(r.shortage) },
  { key: 'firstShortage', header: { ar: 'أول عجز', en: 'First short' }, width: '84px', kind: 'date', sortable: true, value: (r) => fmtDay(r.firstShortage) },
  { key: 'suggestedQty', header: { ar: 'الشراء المقترح', en: 'Suggested' }, width: '74px', kind: 'num', sortable: true },
  { key: 'orderBy', header: { ar: 'اطلب قبل', en: 'Order by' }, width: '84px', kind: 'date', sortable: true, value: (r) => fmtDay(r.orderBy) },
  { key: 'suggestedValue', header: { ar: 'القيمة', en: 'Value' }, width: '92px', kind: 'num', sortable: true, value: (r) => (r.suggestedValue ? money(r.suggestedValue) : '—') },
  { key: 'vendor', header: { ar: 'المورد', en: 'Vendor' }, width: 'minmax(100px,1fr)', value: (r) => r.vendor?.name || '—' },
  { key: 'confidence', header: { ar: 'الثقة', en: 'Conf.' }, width: '56px', kind: 'num', sortable: true, value: (r) => (r.confidence == null ? '—' : `${Math.round(Number(r.confidence))}`) },
];
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="ph('بحث SKU، اسم، مورد…', 'Search SKU, name, vendor…')" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[120px]" :model-value="nav.get('istatus')" :options="options(ITEM_STATUS)" :placeholder="ph('كل الحالات', 'Any status')" @update:model-value="(v) => set('istatus', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('iwh')" :options="lk.warehouseOptions.value" :placeholder="ph('كل المستودعات', 'All warehouses')" @update:model-value="(v) => set('iwh', v)" />
    <button type="button" class="pill" :class="{ active: !!nav.get('ibuy') }" @click="set('ibuy', nav.get('ibuy') ? null : '1')">{{ t('للشراء فقط', 'To buy only') }}</button>
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[140px]" :model-value="nav.get('ifb')" :options="FALLBACK" :placeholder="ph('أي طريقة', 'Any method')" @update:model-value="(v) => set('ifb', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('iop')" :options="options(ORDERPOINT)" :placeholder="ph('أي حد طلب', 'Any reorder rule')" @update:model-value="(v) => set('iop', v)" />
    <SelectInput small class="w-[120px]" :model-value="nav.get('icrit')" :options="options(CRITICALITY)" :placeholder="ph('أي أهمية', 'Any criticality')" @update:model-value="(v) => set('icrit', v)" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('iprice')" :options="options(PRICE_SOURCE)" :placeholder="ph('أي مصدر سعر', 'Any price source')" @update:model-value="(v) => set('iprice', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="ph('إزالة الفلاتر', 'Clear filters')" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('item') || null" :sort="sort" server-sort compact :empty-text="empty"
               @sort="setSort" @page="(p) => nav.patch({ ipage: p > 1 ? p : null })" @row-click="(r) => nav.patch({ item: r.id })">
      <template #cell-sku="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
          <div class="cell-sub"><span class="num ltr inline-block">{{ row.sku }}</span><template v-if="row.criticality && row.criticality !== 'normal'"> · <Chip small :map="CRITICALITY" :k="row.criticality" /></template></div>
        </div>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="ITEM_STATUS" :k="row.status" />
          <span v-if="row.fallback" class="text-[9px] font-extrabold text-warn" :title="t('طريقة بديلة', 'Fallback method')">↺</span>
        </span>
      </template>
      <template #cell-suggestedQty="{ row }"><span class="num" :class="row.suggestedQty > 0 ? 'font-extrabold text-ok' : 'text-faint'">{{ qty(row.suggestedQty) }}</span></template>
    </DataTable>
  </div>
</template>
