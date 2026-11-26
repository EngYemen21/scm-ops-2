<script setup>
// «الطلبات» — sales and refunds of the counter: search, kind, session, register, customer, my orders, dates, owed stock,
// origin; server-side sort and paging — all in the query string:  /pos?view=orders&kind=refund&session=PSS-2026-00012
//   GET /pos/orders?page&pageSize&q&sort(number|total|orderedAt)&order&kind&session&register&customer&cashier=me&from&to&pending=1&source
// A row opens the order (`order=<id>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, num, t } from '@/i18n';
import { HISTORY_CHIP, ORDER_KIND, money, usePosLookups, usePosNav, useSearchParam } from './pos';

const nav = usePosNav();
const lk = usePosLookups();
const { page, orderOpen } = nav;
const { text, type, apply } = useSearchParam(nav);
const session = useSearchParam(nav, 'session');
const customer = useSearchParam(nav, 'customer');

const KINDS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'sale', label: { ar: 'المبيعات', en: 'Sales' } }, { k: 'refund', label: { ar: 'المرتجعات', en: 'Refunds' } }];
const CASHIER = [{ v: 'me', l: { ar: 'طلباتي', en: 'My orders' } }];
const PENDING = [{ v: '1', l: { ar: 'عليها مخزون مستحق', en: 'Stock owed' } }];
const SOURCES = [{ v: 'ops', l: { ar: 'من هنا', en: 'Made here' } }, { v: 'odoo', l: { ar: 'سجل أودو', en: 'Odoo history' } }];
const SORTS = ['number', 'total', 'orderedAt'];
const kind = computed(() => (['sale', 'refund'].includes(nav.get('kind')) ? nav.get('kind') : ''));
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const FILTERS = ['q', 'kind', 'session', 'register', 'customer', 'cashier', 'from', 'to', 'pending', 'source'];
const hasFilters = computed(() => FILTERS.some((k) => !!nav.get(k)));
const clearFilters = () => nav.patch(Object.fromEntries([...FILTERS, 'page'].map((k) => [k, null])));

const list = useList('/pos/orders', () => ({
  ...Object.fromEntries(FILTERS.map((k) => [k, nav.get(k) || undefined])),
  kind: kind.value || undefined, sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));

const columns = [
  { key: 'number', header: { ar: 'الطلب', en: 'Order' }, width: '140px', sortable: true },
  { key: 'orderedAt', header: { ar: 'الوقت', en: 'Time' }, width: '120px', kind: 'date', sortable: true, value: (r) => fmtDate(r.orderedAt) },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(140px,1.3fr)' },
  { key: 'session', header: { ar: 'الجلسة', en: 'Session' }, width: '120px', kind: 'id', value: (r) => r.session?.number || '—' },
  { key: 'cashierName', header: { ar: 'الكاشير', en: 'Cashier' }, width: 'minmax(100px,0.8fr)' },
  { key: 'lines', header: { ar: 'الأسطر', en: 'Lines' }, width: '60px', kind: 'num', value: (r) => String(r._count?.lines ?? r.linesCount ?? '—') },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '112px', kind: 'num', sortable: true },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '130px', bare: true },
];
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[230px]" :model-value="text" scan :placeholder="{ ar: 'بحث برقم الطلب أو الفاتورة…', en: 'Search order or invoice number…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="kind" :tabs="KINDS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('kind', k)" />
  </div>
  <div class="row wrap mb-2.5">
    <TextInput small class="w-[150px]" dir="ltr" :model-value="session.text.value" :placeholder="{ ar: 'رقم الجلسة', en: 'Session no.' }" @update:model-value="session.type" @enter="session.apply" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('register')" :options="lk.registerOptions.value" :placeholder="{ ar: 'كل الصناديق', en: 'Every register' }" @update:model-value="(v) => nav.setFilter('register', v)" />
    <TextInput small class="w-[150px]" dir="ltr" :model-value="customer.text.value" :placeholder="{ ar: 'رمز العميل', en: 'Customer code' }" @update:model-value="customer.type" @enter="customer.apply" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('cashier')" :options="CASHIER" :placeholder="{ ar: 'كل الكاشيرية', en: 'Every cashier' }" @update:model-value="(v) => nav.setFilter('cashier', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('pending')" :options="PENDING" :placeholder="{ ar: 'أي حالة مخزون', en: 'Any stock state' }" @update:model-value="(v) => nav.setFilter('pending', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('from')" :title="t('من تاريخ', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('to')" :title="t('إلى تاريخ', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <SelectInput v-if="lk.follows.value" small class="w-[130px]" :model-value="nav.get('source')" :options="SOURCES" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clearFilters" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="orderOpen" :sort="sort" server-sort
               :empty-text="hasFilters ? { ar: 'لا طلبات تطابق البحث والفلاتر.', en: 'No order matches the search and filters.' } : { ar: 'لا طلبات بعد.', en: 'No order yet.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openOrder(r.id)">
      <template #cell-number="{ row }">
        <div class="min-w-0"><div class="cell-id">{{ row.number }}</div><div v-if="row.invoiceNumber" class="cell-sub num">{{ row.invoiceNumber }}</div></div>
      </template>
      <template #cell-customer="{ row }">
        <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ row.customer?.nameAr || row.customerName || '—' }}</div><div class="cell-sub">{{ row.customer?.code }}</div></div>
      </template>
      <template #cell-total="{ row }">
        <div class="text-center">
          <div class="num font-extrabold" :class="row.kind === 'refund' ? 'text-warn' : 'text-ink'">{{ row.kind === 'refund' ? '−' : '' }}{{ money(row.total) }}</div>
          <div v-if="num(row.refundedTotal) > 0" class="text-[9px] text-warn">{{ t('مُرتجع', 'Refunded') }} <span class="num">{{ money(row.refundedTotal) }}</span></div>
        </div>
      </template>
      <template #cell-kind="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="ORDER_KIND" :k="row.kind" />
          <Chip v-if="row.pendingQty > 0" small :label="{ ar: `مستحق ${row.pendingQty}`, en: `Owed ${row.pendingQty}` }" fg="#b23b3b" bg="#fdecec" />
          <Chip v-if="row.history" small :label="HISTORY_CHIP" :fg="HISTORY_CHIP.fg" :bg="HISTORY_CHIP.bg" />
        </span>
      </template>
    </DataTable>
  </div>
</template>
