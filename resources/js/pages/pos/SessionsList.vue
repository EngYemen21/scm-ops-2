<script setup>
// «الجلسات» — every session of every register: search (number, cashier), state, register, cashier, dates, origin;
// server-side sort and paging — all in the query string:  /pos?view=sessions&state=closed&cashier=me&sort=difference
//   GET /pos/sessions?page&pageSize&q&sort(number|openedAt|closedAt|difference)&order&state&register&cashier&from&to&source
// A row opens the session (`session=<id>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, num, t } from '@/i18n';
import { HISTORY_CHIP, SESSION_STATE, money, nameOf, usePosLookups, usePosNav, useSearchParam } from './pos';

const nav = usePosNav();
const lk = usePosLookups();
const { page, sessionOpen } = nav;
const { text, type, apply } = useSearchParam(nav);

const STATES = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'open', label: { ar: 'المفتوحة', en: 'Open' } }, { k: 'closed', label: { ar: 'المغلقة', en: 'Closed' } }];
const CASHIER = [{ v: 'me', l: { ar: 'جلساتي', en: 'My sessions' } }];
const SOURCES = [{ v: 'ops', l: { ar: 'من هنا', en: 'Made here' } }, { v: 'odoo', l: { ar: 'سجل أودو', en: 'Odoo history' } }];
const SORTS = ['number', 'openedAt', 'closedAt', 'difference'];
const state = computed(() => (['open', 'closed'].includes(nav.get('state')) ? nav.get('state') : ''));
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const FILTERS = ['q', 'state', 'register', 'cashier', 'from', 'to', 'source'];
const hasFilters = computed(() => FILTERS.some((k) => !!nav.get(k)));
const clearFilters = () => nav.patch(Object.fromEntries([...FILTERS, 'page'].map((k) => [k, null])));

const list = useList('/pos/sessions', () => ({
  q: nav.get('q') || undefined, state: state.value || undefined, register: nav.get('register') || undefined, cashier: nav.get('cashier') || undefined,
  from: nav.get('from') || undefined, to: nav.get('to') || undefined, source: nav.get('source') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));

const columns = [
  { key: 'number', header: { ar: 'الجلسة', en: 'Session' }, width: '130px', sortable: true },
  { key: 'register', header: { ar: 'الصندوق', en: 'Register' }, width: 'minmax(140px,1.2fr)' },
  { key: 'cashierName', header: { ar: 'الكاشير', en: 'Cashier' }, width: 'minmax(110px,1fr)' },
  { key: 'openedAt', header: { ar: 'فُتحت', en: 'Opened' }, width: '120px', kind: 'date', sortable: true, value: (r) => fmtDate(r.openedAt) },
  { key: 'closedAt', header: { ar: 'أُغلقت', en: 'Closed' }, width: '120px', kind: 'date', sortable: true, value: (r) => (r.closedAt ? fmtDate(r.closedAt) : '—') },
  { key: 'sales', header: { ar: 'المبيعات', en: 'Sales' }, width: '64px', kind: 'num', value: (r) => String(r.summary?.sales ?? 0) },
  { key: 'netTotal', header: { ar: 'الصافي', en: 'Net' }, width: '110px', kind: 'num', value: (r) => money(r.summary?.netTotal ?? 0) },
  { key: 'difference', header: { ar: 'فرق النقد', en: 'Cash diff.' }, width: '100px', kind: 'num', sortable: true },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '120px', bare: true },
];
const diffClass = (v) => (num(v) === 0 ? 'text-ok' : num(v) > 0 ? 'text-warn' : 'text-bad');
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="{ ar: 'بحث برقم الجلسة أو الكاشير…', en: 'Search session number or cashier…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="state" :tabs="STATES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k)" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[170px]" :model-value="nav.get('register')" :options="lk.registerOptions.value" :placeholder="{ ar: 'كل الصناديق', en: 'Every register' }" @update:model-value="(v) => nav.setFilter('register', v)" />
    <SelectInput small class="w-[140px]" :model-value="nav.get('cashier')" :options="CASHIER" :placeholder="{ ar: 'كل الكاشيرية', en: 'Every cashier' }" @update:model-value="(v) => nav.setFilter('cashier', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('from')" :title="t('من تاريخ', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('to')" :title="t('إلى تاريخ', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <SelectInput v-if="lk.follows.value" small class="w-[130px]" :model-value="nav.get('source')" :options="SOURCES" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clearFilters" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="sessionOpen" :sort="sort" server-sort
               :empty-text="hasFilters ? { ar: 'لا جلسات تطابق البحث والفلاتر.', en: 'No session matches the search and filters.' } : { ar: 'لا جلسات بعد — تُفتح أول جلسة من تبويب «البيع».', en: 'No session yet — the first one is opened in the Sell tab.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openSession(r.id)">
      <template #cell-number="{ row }"><span class="cell-id">{{ row.number }}</span></template>
      <template #cell-register="{ row }">
        <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ nameOf(row.register) }}</div><div class="cell-sub">{{ row.register?.code }}</div></div>
      </template>
      <template #cell-difference="{ row }">
        <span v-if="row.state === 'closed'" class="font-bold" :class="diffClass(row.difference)">{{ money(row.difference) }}</span>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="SESSION_STATE" :k="row.state" />
          <Chip v-if="row.sourceSystem" small :label="HISTORY_CHIP" :fg="HISTORY_CHIP.fg" :bg="HISTORY_CHIP.bg" />
        </span>
      </template>
    </DataTable>
  </div>
</template>
