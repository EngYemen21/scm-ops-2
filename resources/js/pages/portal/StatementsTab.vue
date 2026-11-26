<script setup>
// Customer statements: Odoo's history and the statements issued here from the OPS receivables, in one list — search,
// filters (customer · branch · origin · state · the days a period touches), sort and paging, all on the server and all
// in the query string:   /portal?tab=statements&customer=CUS-1008&from=2026-10-01&to=2026-10-31&stmt=<id>
// GET /portal/statements → { items, total, page, pageSize, pages }. A row opens the statement (`stmt=<id>`).
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, Tabs } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import PartyName from './PartyName.vue';
import PortalFilters from './PortalFilters.vue';
import StatementDrawer from './StatementDrawer.vue';
import StatementIssue from './StatementIssue.vue';
import { OWNED_CHIP, STATEMENT_STATUS, day, money, usePortalNav } from './portal';

const auth = useAuth();
const nav = usePortalNav();
const { page } = nav;
const canManage = computed(() => auth.can('portal.manage'));

const STATUS_TABS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.entries(STATEMENT_STATUS).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en } }))];
const status = computed(() => (STATEMENT_STATUS[nav.get('status')] ? nav.get('status') : ''));
const branchId = computed(() => nav.get('branchId'));
const sort = computed(() => nav.sortOf(['dateTo', 'dateFrom', 'number', 'closingBalance', 'issuedAt', 'linesCount']));
const opened = computed(() => nav.get('stmt') || null);

const list = useList('/portal/statements', () => ({
  q: nav.get('q'), customer: nav.get('customer'), origin: nav.get('origin'), status: status.value, branch: branchId.value, from: nav.get('from'), to: nav.get('to'),
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const filtered = computed(() => !!(nav.get('q') || nav.get('customer') || nav.get('origin') || status.value || branchId.value || nav.get('from') || nav.get('to')));
const empty = computed(() => (filtered.value
  ? { ar: 'لا كشوف تطابق البحث والفلاتر.', en: 'No statement matches the search and filters.' }
  : { ar: 'لا كشوف حساب بعد — أصدر أول كشف من «+ كشف حساب».', en: 'No statement yet — issue the first one with "+ Statement".' }));
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);

const columns = [
  { key: 'number', header: { ar: 'الكشف', en: 'Statement' }, width: '132px', sortable: true },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(170px,1.5fr)' },
  { key: 'period', header: { ar: 'الفترة', en: 'Period' }, width: '168px' },
  { key: 'openingBalance', header: { ar: 'رصيد افتتاحي', en: 'Opening' }, width: '104px', kind: 'num', value: (r) => money(r.openingBalance) },
  { key: 'totalDebit', header: { ar: 'مدين', en: 'Debit' }, width: '100px', kind: 'num', value: (r) => money(r.totalDebit) },
  { key: 'totalCredit', header: { ar: 'دائن', en: 'Credit' }, width: '100px', kind: 'num', value: (r) => money(r.totalCredit) },
  { key: 'closingBalance', header: { ar: 'رصيد ختامي', en: 'Closing' }, width: '108px', kind: 'num', sortable: true },
  { key: 'linesCount', header: { ar: 'الحركات', en: 'Lines' }, width: '70px', kind: 'num', sortable: true, value: (r) => fmtNum(r.linesCount) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '112px', bare: true },
];

const issuing = ref(false);
function onIssued(s) { issuing.value = false; list.refetch(); if (s?.id) nav.patch({ stmt: s.id, lpage: null, lkind: null, lbranch: null }); }
</script>

<template>
  <PortalFilters origin :search="{ ar: 'بحث برقم الكشف أو العميل…', en: 'Search statement or customer…' }">
    <DateInput small field-class="w-[140px]" :label="cap('فترة من', 'Period from')" :model-value="nav.get('from')" :max="nav.get('to') || null" :title="t('كشوف تمس فترتها هذه الأيام — من', 'Statements whose period touches these days — from')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small field-class="w-[140px]" :label="cap('إلى', 'To')" :model-value="nav.get('to')" :min="nav.get('from') || null" :title="t('إلى', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <Tabs :model-value="status" :tabs="STATUS_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k)" />
    <button v-if="branchId" type="button" class="pill active" @click="nav.setFilter('branchId', '')">{{ t('فرع واحد ✕', 'One branch ✕') }}</button>
    <span class="grow" />
    <Btn v-if="canManage" size="sm" tone="primary" :label="{ ar: '+ كشف حساب', en: '+ Statement' }" @click="issuing = true" />
  </PortalFilters>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="opened" :sort="sort" server-sort :empty-text="empty"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ stmt: r.id, lpage: null, lkind: null, lbranch: null })">
      <template #cell-number="{ row }"><div><div class="cell-id">{{ row.number }}</div><div v-if="row.branch" class="text-[9px] font-bold text-violet" dir="auto">{{ t('فرع', 'Branch') }} {{ row.branch.name }}</div></div></template>
      <template #cell-customer="{ row }"><PartyName :party="row.customer" /></template>
      <template #cell-period="{ row }"><span class="num ltr text-[10.5px]">{{ day(row.dateFrom) }} – {{ day(row.dateTo) }}</span></template>
      <template #cell-closingBalance="{ row }"><span class="num font-extrabold" :class="Number(row.closingBalance) < 0 ? 'text-ok' : ''">{{ money(row.closingBalance) }}</span> <span class="text-[9px] text-faint">{{ row.currency }}</span></template>
      <template #cell-status="{ row }">
        <div class="flex flex-wrap justify-center gap-1">
          <Chip small :map="STATEMENT_STATUS" :k="row.status" />
          <Chip v-if="row.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </div>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الكشوف القادمة من أودو تُحفظ كما أصدرها أودو بأرقامه. الكشف الصادر هنا يُحسب من ذمم نظام العمليات — الفواتير والإشعارات الدائنة وسندات القبض وإلغاءاتها — ويُحفظ نسخة ثابتة بما سُلّم للعميل؛ ما لا يصح منه يُلغى بسبب ويصدر غيره.', 'Statements that came from Odoo are kept as Odoo issued them, with its figures. A statement issued here is worked out from the OPS receivables — invoices, credit notes, receipts and their cancellations — and kept as a fixed copy of what the customer was given; one that should not stand is voided with a reason and another is issued.') }}</div>

  <StatementDrawer :id="opened" @close="nav.patch({ stmt: null, lpage: null, lkind: null, lbranch: null })" @changed="list.refetch()" />
  <StatementIssue :open="issuing" :customer="nav.get('customer')" @close="issuing = false" @done="onIssued" />
</template>
