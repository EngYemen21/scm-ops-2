<script setup>
// The VAT returns: search, filters (status · year · frequency), sort and paging — on the server and in the query string:
//   /tax?tab=returns&status=filed&year=2026&sort=netDue&order=desc&page=2         /tax?tab=returns&ret=<id> opens one
// GET /tax/returns → { items, total, page, pageSize, pages, suggest }. «+ إقرار» (vatreturn.manage) opens the form
// prefilled with `suggest`, the period the next return would be; `new=1` in the query string opens it too.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ReturnForm from './ReturnForm.vue';
import { ANY, FREQUENCY, fmtDay, labelOf, money, optionsOf, signClass, useSearchParam, useTaxLookups, useTaxNav } from './tax';

const nav = useTaxNav();
const auth = useAuth();
const lk = useTaxLookups();
const search = useSearchParam(nav);
const SORTS = ['period', 'netDue', 'status', 'filedOn'];
const sort = nav.sortOf(SORTS);
const list = useList('/tax/returns', () => ({
  q: nav.get('q') || undefined, status: nav.get('status') || undefined, year: nav.get('year') || undefined, frequency: nav.get('frequency') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 24,
}));
const suggest = computed(() => list.data.value?.suggest || null);

const statusTabs = computed(() => [{ k: '', label: { ar: 'الكل (عدا الملغاة)', en: 'All (not cancelled)' } }, ...Object.entries(lk.returnStates.value).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en } }))]);
const thisYear = new Date().getFullYear();
const years = Array.from({ length: 8 }, (_, i) => String(thisYear + 1 - i)).map((y) => ({ v: y, l: y }));
const hasFilters = computed(() => ['q', 'status', 'year', 'frequency'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ q: null, status: null, year: null, frequency: null, page: null });

const creating = computed(() => nav.get('new') === '1');
const openForm = () => nav.patch({ new: 1 });
const closeForm = () => nav.patch({ new: null });
function onCreated(r) { nav.patch({ new: null, ret: r?.id || null }); }

const columns = [
  { key: 'periodCode', header: { ar: 'الفترة', en: 'Period' }, width: 'minmax(120px,1fr)', sortable: true },
  { key: 'frequency', header: { ar: 'الدورية', en: 'Frequency' }, width: '84px', value: (r) => labelOf(FREQUENCY, r.frequency) },
  { key: 'outputVat', header: { ar: 'ضريبة المخرجات', en: 'Output VAT' }, width: '112px', kind: 'num' },
  { key: 'inputVat', header: { ar: 'ضريبة المدخلات', en: 'Input VAT' }, width: '112px', kind: 'num' },
  { key: 'netDue', header: { ar: 'صافي المستحق', en: 'Net due' }, width: '120px', kind: 'num', sortable: true },
  { key: 'unexplained', header: { ar: 'غير مفسَّر', en: 'Unexplained' }, width: '96px', kind: 'num' },
  { key: 'filedOn', header: { ar: 'التقديم', en: 'Filed' }, width: '112px', sortable: true },
  { key: 'computedAt', header: { ar: 'آخر احتساب', en: 'Computed' }, width: '110px', kind: 'date', value: (r) => (r.computedAt ? fmtDate(r.computedAt) : '—') },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '104px', sortable: true, bare: true },
];
// the list sorts `period` for the column of the period code
const tableSort = computed(() => (sort.value?.key === 'period' ? { key: 'periodCode', order: sort.value.order } : sort.value));
const onSort = (s) => nav.setSort({ key: s.key === 'periodCode' ? 'period' : s.key, order: s.order });
</script>

<template>
  <div class="row wrap mb-2.5 items-end gap-2">
    <TextInput :model-value="search.text.value" small field-class="w-full sm:w-[240px]" :placeholder="{ ar: 'بحث بالفترة أو المرجع أو الملاحظات…', en: 'Search period, reference, notes…' }" @update:model-value="search.type" @enter="search.apply" />
    <SelectInput :model-value="nav.get('year')" small :options="years" :placeholder="{ ar: '— كل السنوات —', en: '— all years —' }" @update:model-value="(v) => nav.setFilter('year', v)" />
    <SelectInput :model-value="nav.get('frequency')" small :options="optionsOf(FREQUENCY)" :placeholder="ANY" @update:model-value="(v) => nav.setFilter('frequency', v)" />
    <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'مسح الفلاتر', en: 'Clear filters' }" @click="clear" />
    <div class="grow" />
    <Btn v-if="auth.can('vatreturn.manage')" tone="primary" :label="{ ar: '+ إقرار جديد', en: '+ New return' }" @click="openForm" />
  </div>
  <Tabs :model-value="nav.get('status')" :tabs="statusTabs" variant="pill" class="mb-2.5" @update:model-value="(v) => nav.setFilter('status', v)" />
  <div v-if="suggest && !suggest.exists" class="hint teal !mt-0 mb-2.5">
    {{ t('الفترة المقترحة للإقرار التالي', 'Suggested next period') }}: <b class="num">{{ suggest.code }}</b> (<span class="num">{{ fmtDay(suggest.from) }} – {{ fmtDay(suggest.to) }}</span>) — {{ t('لا إقرار لها بعد', 'no return for it yet') }}
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('ret') || null" :sort="tableSort" server-sort
               :empty-text="hasFilters ? { ar: 'لا إقرارات تطابق البحث والفلاتر.', en: 'No return matches the search and filters.' } : { ar: 'لا إقرارات بعد — أنشئ أول إقرار من «+ إقرار جديد».', en: 'No return yet — make the first one with “+ New return”.' }"
               @sort="onSort" @page="nav.setPage" @row-click="(r) => nav.patch({ ret: r.id })">
      <template #cell-periodCode="{ row }">
        <div class="min-w-0">
          <div class="cell-name num">{{ row.periodCode }}</div>
          <div class="cell-sub">{{ fmtDay(row.periodFrom) }} – {{ fmtDay(row.periodTo) }}</div>
        </div>
      </template>
      <template #cell-outputVat="{ row }"><span class="num">{{ money(row.outputVat) }}</span></template>
      <template #cell-inputVat="{ row }"><span class="num">{{ money(row.inputVat) }}</span></template>
      <template #cell-netDue="{ row }"><b class="num" :class="signClass(row.netDue)">{{ money(row.netDue) }}</b></template>
      <template #cell-unexplained="{ row }"><span class="num" :class="Number(row.unexplained) ? 'font-extrabold text-bad' : 'text-faint'">{{ money(row.unexplained) }}</span></template>
      <template #cell-filedOn="{ row }">
        <div v-if="row.filedOn" class="min-w-0 text-center">
          <div class="num text-[10.5px]">{{ fmtDay(row.filedOn) }}</div>
          <div class="cell-sub truncate">{{ row.filedReference }}</div>
        </div>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-status="{ row }"><Chip small :map="lk.returnStates.value" :k="row.status" /></template>
    </DataTable>
  </div>

  <ReturnForm :open="creating" :suggest="suggest" @close="closeForm" @done="onCreated" />
</template>
