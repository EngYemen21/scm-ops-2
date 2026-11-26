<script setup>
// «المطابقات» — every match confirmed (GET /banking/matches): bank, status (active / undone), kind (match · contra ·
// system), with a write-off only, cleared between two days, search (number, note, reason, who, document), sort and
// paging — all in the query string. A row opens the match (`match=<id>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { MATCH_KIND, MATCH_ORIGIN, MATCH_STATUS, fmtDay, money, nameOf, optionsOf, signClass, useBankingLookups, useBankingNav, useSearchParam } from './banking';

const nav = useBankingNav();
const lk = useBankingLookups();
const { text, type, apply } = useSearchParam(nav);

const STATUS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'active', label: { ar: 'القائمة', en: 'Active' } }, { k: 'undone', label: { ar: 'المفكوكة', en: 'Undone' } }];
const status = computed(() => (STATUS.some((s) => s.k === nav.get('status')) ? nav.get('status') : ''));
const kinds = optionsOf(MATCH_KIND);
const SORTS = ['number', 'date', 'amount', 'created'];
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });

const list = useList('/banking/matches', () => ({
  bank: nav.bank.value || undefined, status: status.value || undefined, kind: MATCH_KIND[nav.get('kind')] ? nav.get('kind') : undefined, writeoff: nav.get('writeoff') === '1' ? '1' : undefined,
  from: nav.get('from') || undefined, to: nav.get('to') || undefined, q: nav.get('q') || undefined, sort: sort.value?.key === 'created' ? undefined : sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['bank', 'status', 'kind', 'writeoff', 'from', 'to', 'q'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ bank: null, status: null, kind: null, writeoff: null, from: null, to: null, q: null, page: null });

const columns = [
  { key: 'number', header: { ar: 'المطابقة', en: 'Match' }, width: 'minmax(130px,1fr)', sortable: true },
  { key: 'bank', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(110px,1fr)' },
  { key: 'date', header: { ar: 'تاريخ التسوية', en: 'Cleared' }, width: '92px', kind: 'date', sortable: true },
  { key: 'sides', header: { ar: 'سطور / قيود', en: 'Lines / entries' }, width: '86px', kind: 'num' },
  { key: 'amount', header: { ar: 'الكشف', en: 'Statement' }, width: '100px', kind: 'num', sortable: true },
  { key: 'ledger', header: { ar: 'الدفاتر', en: 'Books' }, width: '100px', kind: 'num' },
  { key: 'writeoff', header: { ar: 'تسوية', en: 'Write-off' }, width: '90px', kind: 'num' },
  { key: 'by', header: { ar: 'بواسطة', en: 'By' }, width: '120px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '130px', bare: true },
];
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[250px]" :model-value="text" :placeholder="{ ar: 'بحث بالرقم، المستند، الملاحظة، المستخدم…', en: 'Search number, document, note, user…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="status" :tabs="STATUS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k)" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[220px]" :model-value="nav.bank.value" :options="lk.bankOptions.value" :placeholder="{ ar: 'كل الحسابات', en: 'Every account' }" @update:model-value="(v) => nav.setFilter('bank', v)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('kind')" :options="kinds" :placeholder="{ ar: 'كل الأنواع', en: 'Every kind' }" @update:model-value="(v) => nav.setFilter('kind', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('from')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('to')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <label class="row !gap-1.5 text-[10.5px] text-sec"><input type="checkbox" :checked="nav.get('writeoff') === '1'" @change="nav.setFilter('writeoff', $event.target.checked ? '1' : null)"> {{ t('بتسوية فرق فقط', 'With a write-off only') }}</label>
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('match') || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا مطابقات تطابق الفلاتر.', en: 'No match fits the filters.' } : { ar: 'لا مطابقات بعد — تُؤكَّد من «المطابقة».', en: 'No match yet — they are confirmed under “Reconcile”.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openMatch(r.id)">
      <template #cell-number="{ row }">
        <div class="min-w-0"><div class="cell-id">{{ row.number }}</div><div v-if="row.note" class="cell-sub truncate" dir="auto">{{ row.note }}</div></div>
      </template>
      <template #cell-bank="{ row }"><span class="truncate text-[10.5px]" dir="auto">{{ row.bank ? `${row.bank.code} · ${nameOf(row.bank)}` : '—' }}</span></template>
      <template #cell-date="{ row }"><span class="cell-date">{{ fmtDay(row.clearedDate) }}</span></template>
      <template #cell-sides="{ row }"><span class="num">{{ row.statementLinesCount }} / {{ row.ledgerLinesCount }}</span></template>
      <template #cell-amount="{ row }"><span class="num ltr" :class="signClass(row.statementTotal)">{{ money(row.statementTotal) }}</span></template>
      <template #cell-ledger="{ row }"><span class="num ltr" :class="signClass(row.ledgerTotal)">{{ money(row.ledgerTotal) }}</span></template>
      <template #cell-writeoff="{ row }"><span v-if="row.hasWriteOff && row.writeOff" class="num ltr text-violet">{{ money(row.writeOff.amount) }}</span><span v-else class="text-faint">—</span></template>
      <template #cell-by="{ row }"><div class="min-w-0"><div class="truncate text-[10px]" dir="auto">{{ row.createdBy }}</div><div class="cell-sub">{{ fmtDate(row.createdAt) }}</div></div></template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="MATCH_STATUS" :k="row.status" /><Chip small :map="MATCH_KIND" :k="row.kind" /><Chip v-if="row.origin && row.origin !== 'manual'" small :map="MATCH_ORIGIN" :k="row.origin" />
        </span>
      </template>
    </DataTable>
  </div>
</template>
