<script setup>
// The purchase plans: search (number / name), status filter, sort and paging — keys pl_* of the query string.
// GET /planning/plans → { items: [planHead + totals], total, page, pageSize, pages }. A row opens the plan (`plan=<id>`).
// A plan is made from an allocated budget scenario of a run (run → «سيناريوهات الميزانية» → «اصنع خطة شراء»).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, ProgressBar, Tabs, TextInput } from '@/components';
import { num, t } from '@/i18n';
import { OWNED_CHIP, PLAN_STATUS, fmtDay, money, usePlanningNav, useSearchParam } from './planning';

const nav = usePlanningNav();
const { text, type, apply } = useSearchParam(nav, 'pl_q', 'pl_page');
const SORTS = ['createdAt', 'number', 'dateFrom', 'status'];
const sort = computed(() => nav.sortOf('pl_sort', 'pl_order', SORTS));
const list = useList('/planning/plans', () => ({
  q: nav.get('pl_q') || undefined, status: nav.get('pl_status') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.pageOf('pl_page'), pageSize: 25,
}));
const STATES = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.entries(PLAN_STATUS).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en } }))]);
const filtered = computed(() => !!nav.get('pl_q') || !!nav.get('pl_status'));

const columns = [
  { key: 'number', header: { ar: 'الخطة', en: 'Plan' }, width: 'minmax(180px,1.5fr)', sortable: true },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '130px', sortable: true, bare: true },
  { key: 'dateFrom', header: { ar: 'تواريخ الطلب', en: 'Order dates' }, width: '160px', kind: 'date', sortable: true, value: (r) => `${fmtDay(r.dateFrom)} → ${fmtDay(r.dateTo)}` },
  { key: 'lines', header: { ar: 'البنود', en: 'Lines' }, width: '60px', kind: 'num', value: (r) => (r.totals ? String(r.totals.lines) : '—') },
  { key: 'planned', header: { ar: 'المخطط', en: 'Planned' }, width: '104px', kind: 'num', value: (r) => money(r.totals?.planned) },
  { key: 'committed', header: { ar: 'الملتزم به', en: 'Committed' }, width: '104px', kind: 'num', value: (r) => money(r.totals?.committed) },
  { key: 'progress', header: { ar: 'الاستلام', en: 'Received' }, width: '110px', bare: true },
  { key: 'late', header: { ar: 'متأخر', en: 'Late' }, width: '78px', bare: true },
  { key: 'createdAt', header: { ar: 'أُنشئت', en: 'Created' }, width: '90px', kind: 'date', sortable: true, value: (r) => fmtDay(r.createdAt) },
];
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="ph('بحث بالرقم أو الاسم…', 'Search number or name…')" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="nav.get('pl_status')" :tabs="STATES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('pl_status', k, 'pl_page')" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="ph('إزالة الفلاتر', 'Clear filters')" @click="nav.patch({ pl_q: null, pl_status: null, pl_page: null })" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('plan') || null" :sort="sort" server-sort
               :empty-text="filtered ? ph('لا خطط تطابق البحث والفلتر.', 'No plan matches the search and filter.') : ph('لا خطط شراء بعد — تُصنع من سيناريو ميزانية موزّع في تشغيل توقع.', 'No purchase plan yet — one is made from an allocated budget scenario of a forecast run.')"
               @sort="(s) => nav.patch({ pl_sort: s.key, pl_order: s.order, pl_page: null })" @page="(p) => nav.patch({ pl_page: p > 1 ? p : null })" @row-click="(r) => nav.patch({ plan: r.id })">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.name || row.number }}</div>
          <div class="cell-sub"><span class="num ltr inline-block">{{ row.number }}</span><template v-if="row.planner"> · <span dir="auto">{{ row.planner }}</span></template></div>
        </div>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="PLAN_STATUS" :k="row.status" />
          <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </span>
      </template>
      <template #cell-progress="{ row }">
        <span class="inline-flex w-full flex-col gap-0.5"><ProgressBar :pct="num(row.totals?.progressPct)" :height="5" /><span class="num text-[9px] text-muted">{{ num(row.totals?.progressPct).toFixed(0) }}%</span></span>
      </template>
      <template #cell-late="{ row }">
        <span v-if="row.totals && (row.totals.lateOrder || row.totals.lateReceipt)" class="text-[9.5px] font-extrabold text-bad">
          <template v-if="row.totals.lateOrder">{{ t('طلب', 'order') }} {{ row.totals.lateOrder }}</template><template v-if="row.totals.lateOrder && row.totals.lateReceipt"> · </template><template v-if="row.totals.lateReceipt">{{ t('استلام', 'receipt') }} {{ row.totals.lateReceipt }}</template>
        </span>
        <span v-else class="text-faint">—</span>
      </template>
    </DataTable>
  </div>
</template>
