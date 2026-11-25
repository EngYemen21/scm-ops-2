<script setup>
// The list of leads: search, filters (state · stage · salesperson · team · priority · tag · next to-do · origin), sort
// and paging — all on the server and all in the query string:
//   /crm?view=list&state=won&owner=me&sort=expectedRevenue&order=desc&page=2
// GET /crm/leads → { items, total, page, pageSize, pages }. A row opens the lead (`lead=<number>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner } from '@/components';
import { fmtDateOnly, t } from '@/i18n';
import CrmFilters from './CrmFilters.vue';
import { ARCHIVED_CHIP, KIND_CHIP, LEAD_STATES, LEAD_STATUS, OWNED_CHIP, activityTypeLabel, dueLabel, leadWho, money, nameOf, pct, stars, useCrmNav } from './crm';

const nav = useCrmNav();
const { page, leadOpen } = nav;

const SORTS = ['name', 'expectedRevenue', 'probability', 'priority', 'nextActivity', 'createdAt'];
const state = computed(() => (LEAD_STATES.some((s) => s.k === nav.get('state')) ? nav.get('state') : 'open'));
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const list = useList('/crm/leads', () => ({
  ...nav.leadParams(), state: state.value, stage: nav.get('stage') || undefined, sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));

const empty = computed(() => (nav.hasLeadFilters.value
  ? { ar: 'لا فرص تطابق البحث والفلاتر.', en: 'No opportunity matches the search and filters.' }
  : state.value === 'open' ? { ar: 'لا فرص مفتوحة بعد — أضف أول فرصة من «+ فرصة».', en: 'No open opportunity yet — add the first one with "+ Opportunity".' }
    : { ar: 'لا فرص في هذه القائمة.', en: 'No opportunity in this list.' }));

const columns = [
  { key: 'name', header: { ar: 'الفرصة', en: 'Opportunity' }, width: 'minmax(200px,1.6fr)', sortable: true },
  { key: 'who', header: { ar: 'العميل المحتمل', en: 'Prospect' }, width: 'minmax(140px,1.1fr)' },
  { key: 'stage', header: { ar: 'المرحلة', en: 'Stage' }, width: '112px' },
  { key: 'owner', header: { ar: 'المندوب', en: 'Salesperson' }, width: '110px' },
  { key: 'expectedRevenue', header: { ar: 'الإيراد المتوقع', en: 'Expected revenue' }, width: '104px', kind: 'num', sortable: true },
  { key: 'probability', header: { ar: 'الاحتمال', en: 'Chance' }, width: '72px', kind: 'num', sortable: true },
  { key: 'priority', header: { ar: 'الأولوية', en: 'Priority' }, width: '70px', sortable: true },
  { key: 'nextActivity', header: { ar: 'النشاط القادم', en: 'Next to-do' }, width: '124px', sortable: true },
  { key: 'createdAt', header: { ar: 'أُنشئت', en: 'Created' }, width: '88px', kind: 'date', sortable: true, value: (r) => fmtDateOnly(r.createdAt) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '96px', bare: true },
];
</script>

<template>
  <CrmFilters list />
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="leadOpen" :sort="sort" server-sort :empty-text="empty"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openLead(r.number)">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.name }}</div>
          <div class="cell-sub">{{ row.number }}<template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template></div>
        </div>
      </template>
      <template #cell-who="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ leadWho(row) || '—' }}</div>
          <div v-if="row.phone || row.mobile || row.city" class="truncate text-[9px] text-muted"><span class="num ltr">{{ row.mobile || row.phone }}</span><template v-if="row.city"> · {{ row.city }}</template></div>
        </div>
      </template>
      <template #cell-stage="{ row }"><span class="text-[10px] font-bold" :class="row.stage?.isWon ? 'text-ok' : 'text-violet'" dir="auto">{{ row.stage ? nameOf(row.stage) : '—' }}</span></template>
      <template #cell-owner="{ row }"><span class="truncate text-[10px]" :class="row.owner ? 'text-sec' : 'text-faint'" dir="auto">{{ row.owner ? nameOf(row.owner) : t('بلا مندوب', 'Unassigned') }}</span></template>
      <template #cell-expectedRevenue="{ row }">{{ money(row.expectedRevenue) }}</template>
      <template #cell-probability="{ row }">{{ pct(row.probability) }}</template>
      <template #cell-priority="{ row }"><span class="text-[11px] text-warn" :title="t('الأولوية', 'Priority')">{{ stars(row.priority) || '—' }}</span></template>
      <template #cell-nextActivity="{ row }">
        <span v-if="row.nextActivity" class="text-[9.5px]" :class="row.nextActivity.overdue ? 'font-extrabold text-bad' : row.nextActivity.today ? 'font-extrabold text-warn' : 'text-sec'" :title="row.nextActivity.summary || ''">
          {{ activityTypeLabel(row.nextActivity.type) }} · <span class="num">{{ dueLabel(row.nextActivity) }}</span>
        </span>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="LEAD_STATUS" :k="row.status" />
          <Chip v-if="row.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
          <Chip v-if="row.kind === 'lead'" small :label="KIND_CHIP" :fg="KIND_CHIP.fg" :bg="KIND_CHIP.bg" />
          <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </span>
      </template>
    </DataTable>
  </div>
</template>
