<script setup>
// The e-invoice history that came from Odoo (read-only: the record of what Odoo did) — GET /tax/history with filters
// status · moveType · from · to, search, sort (date · number · amount · status · chain · partner), paging and `summary`.
// A row opens the record (`hist=<id>`, HistoryDrawer.vue).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { HISTORY_STATUS, MOVE_TYPE, fmtDay, labelOf, optionsOf, sar, useSearchParam, useTaxNav } from './tax';

const nav = useTaxNav();
const search = useSearchParam(nav);
const SORTS = ['date', 'number', 'amount', 'status', 'chain', 'partner'];
const sort = nav.sortOf(SORTS);
const FILTERS = ['status', 'moveType', 'from', 'to', 'customer'];
const list = useList('/tax/history', () => ({
  q: nav.get('q') || undefined, ...Object.fromEntries(FILTERS.map((k) => [k, nav.get(k) || undefined])),
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const summary = computed(() => list.data.value?.summary || null);
const hasFilters = computed(() => ['q', ...FILTERS].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ ...Object.fromEntries(['q', ...FILTERS].map((k) => [k, null])), page: null });
const statusTabs = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' }, badge: summary.value?.total || null },
  ...Object.entries(HISTORY_STATUS).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en }, badge: summary.value?.byStatus?.[k] || null }))]);

const columns = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', sortable: true, value: (r) => fmtDay(r.invoiceDate) },
  { key: 'number', header: { ar: 'المستند', en: 'Document' }, width: 'minmax(130px,1fr)', sortable: true },
  { key: 'partner', header: { ar: 'الطرف', en: 'Partner' }, width: 'minmax(150px,1.3fr)', sortable: true },
  { key: 'amount', header: { ar: 'الإجمالي', en: 'Total' }, width: '104px', kind: 'num', sortable: true },
  { key: 'chain', header: { ar: 'السلسلة', en: 'Chain' }, width: '72px', kind: 'num', sortable: true, value: (r) => (r.chainIndex == null ? '—' : String(r.chainIndex)) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '128px', sortable: true, bare: true },
];
</script>

<template>
  <div class="hint purple !mt-0 mb-2.5">
    {{ t('سجل ما فعله أودو في الفوترة الإلكترونية لفواتيره — للقراءة فقط.', 'What Odoo did in e-invoicing for its invoices — read-only.') }}
    <template v-if="summary?.first"> {{ t('من', 'From') }} <span class="num">{{ fmtDay(summary.first) }}</span> {{ t('إلى', 'to') }} <span class="num">{{ fmtDay(summary.last) }}</span> · <span class="num">{{ fmtNum(summary.total) }}</span> {{ t('مستند', 'documents') }}</template>
  </div>
  <div class="row wrap mb-2 items-end gap-2">
    <TextInput :model-value="search.text.value" small field-class="w-full sm:w-[230px]" :placeholder="{ ar: 'بحث بالرقم أو الطرف أو UUID أو الملف…', en: 'Search number, partner, UUID, file…' }" @update:model-value="search.type" @enter="search.apply" />
    <SelectInput :model-value="nav.get('moveType')" small :options="optionsOf(MOVE_TYPE)" :placeholder="{ ar: '— كل الأنواع —', en: '— all types —' }" @update:model-value="(v) => nav.setFilter('moveType', v)" />
    <DateInput :model-value="nav.get('from')" small :label="{ ar: 'من', en: 'From' }" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput :model-value="nav.get('to')" small :label="{ ar: 'إلى', en: 'To' }" :min="nav.get('from') || null" @update:model-value="(v) => nav.setFilter('to', v)" />
    <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'مسح الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <Tabs :model-value="nav.get('status')" :tabs="statusTabs" variant="pill" class="mb-2.5" @update:model-value="(v) => nav.setFilter('status', v)" />
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('hist') || null" :sort="sort" server-sort
               :empty-text="hasFilters ? { ar: 'لا سجلات تطابق البحث والفلاتر.', en: 'No record matches the search and filters.' } : { ar: 'لا سجل من أودو.', en: 'No history from Odoo.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ hist: r.id })">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-name num truncate">{{ row.moveNumber || row.moveRef || '—' }}</div>
          <div class="truncate text-[9px] text-muted">{{ labelOf(MOVE_TYPE, row.moveType) }}<template v-if="row.fileUrl"> · {{ t('الملف محفوظ', 'file held') }}</template></div>
        </div>
      </template>
      <template #cell-partner="{ row }"><span class="truncate text-[10.5px]" dir="auto">{{ row.partnerName || '—' }}</span></template>
      <template #cell-amount="{ row }">
        <div class="text-center"><div class="num">{{ sar(row.amountTotal) }}</div><div v-if="row.amountTax != null" class="cell-sub">{{ t('ض', 'VAT') }} {{ sar(row.amountTax) }}</div></div>
      </template>
      <template #cell-status="{ row }">
        <div class="text-center">
          <Chip small :map="HISTORY_STATUS" :k="row.status" />
          <div v-if="row.error" class="mt-0.5 truncate text-[8.5px] text-bad" :title="row.error">{{ row.error }}</div>
        </div>
      </template>
    </DataTable>
  </div>
</template>
