<script setup>
// Customer reorder patterns (cadence) and the expected orders they give.
//   GET /planning/runs/{id}/patterns?state&band&warehouse&q&sort&order   keys p*
//   GET /planning/runs/{id}/events?actual&pattern&q                      keys e*   (pview=events)
// A pattern row lists its expected orders; the actual state of each comes from «تحديث الطلبات الفعلية».
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { ACTUAL, BAND, PATTERN_STATE, fmtDay, options, qty, usePlanningLookups, usePlanningNav, useSearchParam } from './planning';

const props = defineProps({ run: { type: Object, required: true } });

const nav = usePlanningNav();
const lk = usePlanningLookups();
const view = computed(() => (nav.get('pview') === 'events' ? 'events' : 'patterns'));
const VIEWS = [{ k: 'patterns', label: { ar: 'الأنماط', en: 'Patterns' } }, { k: 'events', label: { ar: 'الطلبات المتوقعة', en: 'Expected orders' } }];

// ---- patterns
const ps = useSearchParam(nav, 'pq', 'ppage');
const SORTS = ['nextExpected', 'confidence', 'forecast', 'orders', 'overdue', 'sku'];
const sort = computed(() => nav.sortOf('psort', 'porder', SORTS));
const patterns = useList(() => (view.value === 'patterns' ? `/planning/runs/${props.run.id}/patterns` : null), () => ({
  q: nav.get('pq') || undefined, state: nav.get('pstate') || undefined, band: nav.get('pband') || undefined, warehouse: nav.get('pwh') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.pageOf('ppage'), pageSize: 50,
}));
const PKEYS = ['pq', 'pstate', 'pband', 'pwh'];
const pFiltered = computed(() => PKEYS.some((k) => !!nav.get(k)));
const pColumns = [
  { key: 'sku', header: { ar: 'العميل · المنتج', en: 'Customer · product' }, width: 'minmax(180px,1.6fr)', sortable: true },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '96px', bare: true },
  { key: 'orders', header: { ar: 'الطلبات', en: 'Orders' }, width: '60px', kind: 'num', sortable: true },
  { key: 'avgInterval', header: { ar: 'كل (يوم)', en: 'Every (d)' }, width: '64px', kind: 'num', value: (r) => (r.avgInterval == null ? '—' : fmtNum(r.avgInterval, 1)) },
  { key: 'avgQty', header: { ar: 'متوسط الكمية', en: 'Avg qty' }, width: '70px', kind: 'num', value: (r) => qty(r.avgQty) },
  { key: 'lastOrder', header: { ar: 'آخر طلب', en: 'Last order' }, width: '84px', kind: 'date', value: (r) => fmtDay(r.lastOrder) },
  { key: 'nextExpected', header: { ar: 'المتوقع التالي', en: 'Next expected' }, width: '96px', kind: 'date', sortable: true },
  { key: 'forecast', header: { ar: 'التوقع', en: 'Forecast' }, width: '66px', kind: 'num', sortable: true, value: (r) => qty(r.forecast) },
  { key: 'confidence', header: { ar: 'الثقة', en: 'Confidence' }, width: '96px', sortable: true, bare: true },
];

// ---- expected orders
const es = useSearchParam(nav, 'eq', 'epage');
const events = useList(() => (view.value === 'events' ? `/planning/runs/${props.run.id}/events` : null), () => ({
  q: nav.get('eq') || undefined, actual: nav.get('eactual') || undefined, pattern: nav.get('epattern') || undefined, page: nav.pageOf('epage'), pageSize: 50,
}));
const eColumns = [
  { key: 'expectedDate', header: { ar: 'متوقع في', en: 'Expected' }, width: '92px', kind: 'date', value: (r) => fmtDay(r.expectedDate) },
  { key: 'customerName', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(140px,1.4fr)' },
  { key: 'sku', header: { ar: 'المنتج', en: 'Product' }, width: '110px', kind: 'id' },
  { key: 'periodLabel', header: { ar: 'الفترة', en: 'Period' }, width: '84px' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '64px', kind: 'num', value: (r) => qty(r.qty) },
  { key: 'band', header: { ar: 'الثقة', en: 'Confidence' }, width: '84px', bare: true },
  { key: 'actual', header: { ar: 'الفعلي', en: 'Actual' }, width: '150px', bare: true },
];
const openPattern = (p) => nav.patch({ pview: 'events', epattern: p.id, epage: null, eq: null });
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <Tabs :model-value="view" :tabs="VIEWS" variant="pill" @update:model-value="(k) => nav.patch({ pview: k === 'patterns' ? null : k })" />

  <template v-if="view === 'patterns'">
    <div class="row wrap mb-2.5">
      <TextInput small type="search" class="w-[220px]" :model-value="ps.text.value" :placeholder="ph('بحث SKU أو عميل…', 'Search SKU or customer…')" @update:model-value="ps.type" @enter="ps.apply" />
      <SelectInput small class="w-[140px]" :model-value="nav.get('pstate')" :options="options(PATTERN_STATE)" :placeholder="ph('أي حالة', 'Any state')" @update:model-value="(v) => nav.setFilter('pstate', v, 'ppage')" />
      <SelectInput small class="w-[120px]" :model-value="nav.get('pband')" :options="options(BAND)" :placeholder="ph('أي ثقة', 'Any confidence')" @update:model-value="(v) => nav.setFilter('pband', v, 'ppage')" />
      <SelectInput small class="w-[150px]" :model-value="nav.get('pwh')" :options="lk.warehouseOptions.value" :placeholder="ph('كل المستودعات', 'All warehouses')" @update:model-value="(v) => nav.setFilter('pwh', v, 'ppage')" />
      <Btn v-if="pFiltered" size="sm" tone="ghost" :label="ph('إزالة الفلاتر', 'Clear filters')" @click="nav.patch(Object.fromEntries([...PKEYS, 'ppage'].map((k) => [k, null])))" />
    </div>
    <ErrorBanner :error="patterns.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="pColumns" :paged="patterns.data.value" :loading="patterns.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort compact
                 :empty-text="pFiltered ? { ar: 'لا أنماط تطابق الفلاتر.', en: 'No pattern matches the filters.' } : { ar: 'لا أنماط عملاء في هذا التشغيل (طريقة الإيقاع فقط).', en: 'No customer pattern in this run (cadence method only).' }"
                 @sort="(s) => nav.patch({ psort: s.key, porder: s.order, ppage: null })" @page="(p) => nav.patch({ ppage: p > 1 ? p : null })" @row-click="openPattern">
        <template #cell-sku="{ row }">
          <div class="min-w-0">
            <div class="cell-name truncate" dir="auto">{{ row.customerName || t('بلا عميل', 'No customer') }}</div>
            <div class="cell-sub"><span class="num ltr inline-block">{{ row.sku }}</span><template v-if="row.seasonal"> · {{ t('موسمي', 'seasonal') }}</template><template v-if="row.estimate"> · {{ t('مُقدّر بالمتوسط', 'estimated') }}</template></div>
          </div>
        </template>
        <template #cell-state="{ row }">
          <span class="inline-flex flex-col items-center gap-0.5"><Chip small :map="PATTERN_STATE" :k="row.state" /><span v-if="row.overdueDays > 0" class="num text-[9px] text-warn">+{{ row.overdueDays }} {{ t('يوم', 'd') }}</span></span>
        </template>
        <template #cell-nextExpected="{ row }"><span class="num">{{ fmtDay(row.nextExpected) }}</span><span v-if="row.expectedOrders" class="num text-[9px] text-muted"> ×{{ row.expectedOrders }}</span></template>
        <template #cell-confidence="{ row }"><span class="inline-flex items-center gap-1"><Chip small :map="BAND" :k="row.band || 'none'" /><span class="num text-[9.5px]">{{ row.confidence == null ? '' : Math.round(row.confidence) }}</span></span></template>
      </DataTable>
    </div>
  </template>

  <template v-else>
    <div class="row wrap mb-2.5">
      <TextInput small type="search" class="w-[220px]" :model-value="es.text.value" :placeholder="ph('بحث SKU أو عميل…', 'Search SKU or customer…')" @update:model-value="es.type" @enter="es.apply" />
      <SelectInput small class="w-[140px]" :model-value="nav.get('eactual')" :options="options(ACTUAL)" :placeholder="ph('أي نتيجة', 'Any outcome')" @update:model-value="(v) => nav.setFilter('eactual', v, 'epage')" />
      <Btn v-if="nav.get('epattern')" size="sm" tone="softPurple" :label="ph('كل الأنماط ✕', 'Every pattern ✕')" @click="nav.patch({ epattern: null, epage: null })" />
    </div>
    <ErrorBanner :error="events.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="eColumns" :paged="events.data.value" :loading="events.isFetching.value" :row-key="(r) => r.id" compact
                 :empty-text="{ ar: 'لا طلبات متوقعة.', en: 'No expected order.' }" @page="(p) => nav.patch({ epage: p > 1 ? p : null })">
        <template #cell-customerName="{ row }"><span class="truncate text-[10.5px] font-bold" dir="auto">{{ row.customerName || '—' }}</span><span v-if="row.catchUp" class="ms-1 text-[9px] text-warn">{{ t('متأخر عن موعده', 'catch-up') }}</span></template>
        <template #cell-band="{ row }"><Chip small :map="BAND" :k="row.band || 'none'" /></template>
        <template #cell-actual="{ row }">
          <span class="inline-flex flex-col items-center gap-0.5">
            <Chip small :map="ACTUAL" :k="row.actual || 'pending'" />
            <span v-if="row.actual === 'ordered'" class="num text-[9px] text-muted">{{ fmtDay(row.actualDate) }} · {{ qty(row.actualQty) }}<template v-if="row.deviation != null"> ({{ row.deviation > 0 ? '+' : '' }}{{ qty(row.deviation) }})</template></span>
          </span>
        </template>
      </DataTable>
    </div>
  </template>
</template>
