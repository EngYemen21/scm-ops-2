<script setup>
// «الكشوف» — the statements of every bank (GET /banking/statements): search, bank, status (void and history are asked
// for explicitly), origin, period, sort and paging — all on the server and all in the query string:
//   /banking?tab=statements&bank=BNK-01&status=open&from=2026-10-01&sort=number&order=asc&page=2
// A row opens the statement (`st=<id>`). Each row carries its totals: opening + in + out against the closing balance.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { OWNED_CHIP, STATEMENT_STATUS, fmtDay, money, nameOf, signClass, toCents, useBankingLookups, useBankingNav, useSearchParam } from './banking';

const nav = useBankingNav();
const lk = useBankingLookups();
const { text, type, apply } = useSearchParam(nav);

const STATUS_TABS = [
  { k: '', label: { ar: 'الحية', en: 'Live' } }, { k: 'open', label: { ar: 'المفتوحة', en: 'Open' } }, { k: 'reconciled', label: { ar: 'المقفلة', en: 'Reconciled' } },
  { k: 'history', label: { ar: 'التاريخية', en: 'History' } }, { k: 'void', label: { ar: 'الملغاة', en: 'Void' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } },
];
const ORIGIN = [{ v: 'ops', l: { ar: 'أُنشئت هنا', en: 'Made in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const SORTS = ['number', 'dateFrom', 'dateTo', 'closing', 'status', 'created'];
const status = computed(() => (STATUS_TABS.some((s) => s.k === nav.get('status')) ? nav.get('status') : ''));
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });

const list = useList('/banking/statements', () => ({
  bank: nav.bank.value || undefined, status: status.value === 'all' ? 'open,reconciled,void,history' : status.value || undefined, origin: nav.get('origin') || undefined,
  from: nav.get('from') || undefined, to: nav.get('to') || undefined, q: nav.get('q') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['bank', 'origin', 'from', 'to', 'q', 'status'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ bank: null, origin: null, from: null, to: null, q: null, status: null, page: null });
const empty = computed(() => (filtered.value ? { ar: 'لا كشوف تطابق البحث والفلاتر.', en: 'No statement matches the search and filters.' } : { ar: 'لا كشوف بعد — أضف أول كشف من «+ كشف جديد».', en: 'No statement yet — add the first one with "+ New statement".' }));

const columns = [
  { key: 'number', header: { ar: 'الكشف', en: 'Statement' }, width: 'minmax(170px,1.4fr)', sortable: true },
  { key: 'bank', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(120px,1fr)' },
  { key: 'dateFrom', header: { ar: 'الفترة', en: 'Period' }, width: '150px', sortable: true },
  { key: 'opening', header: { ar: 'الافتتاحي', en: 'Opening' }, width: '96px', kind: 'num' },
  { key: 'moves', header: { ar: 'وارد / صادر', en: 'In / out' }, width: '120px', kind: 'num' },
  { key: 'closing', header: { ar: 'الختامي', en: 'Closing' }, width: '96px', kind: 'num', sortable: true },
  { key: 'difference', header: { ar: 'الفرق', en: 'Difference' }, width: '86px', kind: 'num' },
  { key: 'lines', header: { ar: 'مطابق / سطور', en: 'Matched / lines' }, width: '86px', kind: 'num' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '120px', bare: true, sortable: true },
];
const allLines = (r) => (r.totals ? r.totals.matched + r.totals.unmatched : 0);
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[250px]" :model-value="text" :placeholder="{ ar: 'بحث بالرقم، الاسم، الملاحظات…', en: 'Search number, name, notes…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="status" :tabs="STATUS_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k)" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[220px]" :model-value="nav.bank.value" :options="lk.bankOptions.value" :placeholder="{ ar: 'كل الحسابات', en: 'Every account' }" @update:model-value="(v) => nav.setFilter('bank', v)" />
    <SelectInput small class="w-[140px]" :model-value="nav.get('origin')" :options="ORIGIN" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('origin', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('from')" :title="t('من تاريخ', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('to')" :title="t('إلى تاريخ', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('st') || null" :sort="sort" server-sort :empty-text="empty"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openStatement(r.id)">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-id">{{ row.number }}</div>
          <div class="cell-sub truncate" dir="auto">{{ row.name || '' }}<template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template></div>
        </div>
      </template>
      <template #cell-bank="{ row }">
        <div class="min-w-0"><div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ row.bank ? nameOf(row.bank) : row.sourceJournal?.name || '—' }}</div><div class="cell-sub">{{ row.bank?.code || row.sourceJournal?.code || '' }}</div></div>
      </template>
      <template #cell-dateFrom="{ row }"><span class="cell-date">{{ fmtDay(row.dateFrom) }} — {{ fmtDay(row.dateTo) }}</span></template>
      <template #cell-opening="{ row }"><span class="cell-num">{{ money(row.openingBalance) }}</span></template>
      <template #cell-moves="{ row }"><span class="num ltr text-[10px]"><span class="text-ok">+{{ money(row.totals?.in) }}</span> / <span class="text-bad">{{ money(row.totals?.out) }}</span></span></template>
      <template #cell-closing="{ row }"><span class="cell-num">{{ money(row.closingBalance) }}</span></template>
      <template #cell-difference="{ row }">
        <span v-if="row.totals?.difference == null" class="text-faint">—</span>
        <span v-else-if="toCents(row.totals.difference) === 0" class="text-[10px] font-extrabold text-ok">✓ 0</span>
        <span v-else class="num ltr font-extrabold" :class="signClass(row.totals.difference)">{{ money(row.totals.difference) }}</span>
      </template>
      <template #cell-lines="{ row }"><span class="num" :class="row.totals?.unmatched ? 'text-warn' : 'text-ok'">{{ row.totals?.matched || 0 }} / {{ allLines(row) }}</span></template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="STATEMENT_STATUS" :k="row.status" />
          <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </span>
      </template>
    </DataTable>
  </div>
</template>
