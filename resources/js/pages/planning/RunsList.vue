<script setup>
// The forecast runs: search (number / name), status · method · active · origin filters, sort and paging — all on the
// server and all in the query string. GET /planning/runs → { items, total, page, pageSize, pages }. A row opens the run.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { ARCHIVED_CHIP, METHOD, OWNED_CHIP, RUN_STATUS, fmtDay, label, money, options, usePlanningNav, useSearchParam } from './planning';

const nav = usePlanningNav();
const { text, type, apply } = useSearchParam(nav, 'q');

const SORTS = ['createdAt', 'number', 'name', 'computedAt', 'anchorDate', 'itemCount', 'shortageItemCount', 'totalSuggestedValue', 'status'];
const sort = computed(() => nav.sortOf('sort', 'order', SORTS));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const active = computed(() => (['yes', 'no', 'all'].includes(nav.get('active')) ? nav.get('active') : 'yes'));
const list = useList('/planning/runs', () => ({
  q: nav.get('q') || undefined, status: nav.get('status') || undefined, method: nav.get('method') || undefined, active: active.value, origin: nav.get('origin') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.pageOf('page'), pageSize: 25,
}));

const ACTIVE = [{ k: 'yes', label: { ar: 'النشطة', en: 'Active' } }, { k: 'no', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } }];
const ORIGIN = [{ v: 'ops', l: { ar: 'أُنشئت هنا', en: 'Made in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const filtered = computed(() => ['q', 'status', 'method', 'origin'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ q: null, status: null, method: null, origin: null, page: null });
const empty = computed(() => (filtered.value ? { ar: 'لا تشغيلات تطابق البحث والفلاتر.', en: 'No run matches the search and filters.' }
  : { ar: 'لا تشغيلات توقع بعد — ابدأ من «+ تشغيل جديد».', en: 'No forecast run yet — start with "+ New run".' }));

const columns = [
  { key: 'number', header: { ar: 'التشغيل', en: 'Run' }, width: 'minmax(190px,1.6fr)', sortable: true },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '118px', sortable: true, bare: true },
  { key: 'method', header: { ar: 'الطريقة', en: 'Method' }, width: '130px' },
  { key: 'window', header: { ar: 'فترة التوقع', en: 'Forecast window' }, width: '150px', kind: 'date' },
  { key: 'itemCount', header: { ar: 'البنود', en: 'Items' }, width: '64px', kind: 'num', sortable: true },
  { key: 'shortageItemCount', header: { ar: 'بعجز', en: 'Short' }, width: '64px', kind: 'num', sortable: true },
  { key: 'totalSuggestedValue', header: { ar: 'قيمة الشراء المقترح', en: 'Suggested value' }, width: '120px', kind: 'num', sortable: true, value: (r) => money(r.totalSuggestedValue) },
  { key: 'computedAt', header: { ar: 'حُسب', en: 'Computed' }, width: '110px', kind: 'date', sortable: true, value: (r) => (r.computedAt ? fmtDate(r.computedAt) : '—') },
  { key: 'createdAt', header: { ar: 'أُنشئ', en: 'Created' }, width: '96px', kind: 'date', sortable: true, value: (r) => fmtDay(r.createdAt) },
];
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="ph('بحث بالرقم أو الاسم…', 'Search number or name…')" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="active" :tabs="ACTIVE" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('active', k === 'yes' ? null : k)" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[150px]" :model-value="nav.get('status')" :options="options(RUN_STATUS)" :placeholder="ph('كل الحالات', 'Every status')" @update:model-value="(v) => nav.setFilter('status', v)" />
    <SelectInput small class="w-[190px]" :model-value="nav.get('method')" :options="options(METHOD).filter((o) => o.v !== 'none')" :placeholder="ph('كل الطرق', 'Every method')" @update:model-value="(v) => nav.setFilter('method', v)" />
    <SelectInput small class="w-[140px]" :model-value="nav.get('origin')" :options="ORIGIN" :placeholder="ph('أي مصدر', 'Any origin')" @update:model-value="(v) => nav.setFilter('origin', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="ph('إزالة الفلاتر', 'Clear filters')" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('run') || null" :sort="sort" server-sort :empty-text="empty"
               @sort="setSort" @page="(p) => nav.patch({ page: p > 1 ? p : null })" @row-click="(r) => nav.patch({ run: r.id, item: null, rtab: null })">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.name || row.number }}</div>
          <div class="cell-sub"><span class="num ltr inline-block">{{ row.number }}</span><template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template></div>
        </div>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="RUN_STATUS" :k="row.status" />
          <Chip v-if="!row.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
          <Chip v-if="row.owned || row.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </span>
      </template>
      <template #cell-method="{ row }"><span class="truncate text-[10px] font-bold text-sec">{{ label(METHOD, row.method) }}</span></template>
      <template #cell-window="{ row }"><span class="num text-[10px]">{{ fmtDay(row.forecastFrom) }} → {{ fmtDay(row.forecastTo) }}</span></template>
    </DataTable>
  </div>
</template>
