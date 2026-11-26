<script setup>
// "Was this invoice paid a commission for — to whom, in which statement?" Every calculated line the user may see,
// across statements (his own lines only without commission.manage / commission.approve): the lines of OPS's statements
// and Odoo's whole history of commission lines, in one list.
// GET /commissions/lines?q=&kind=&period=&status=&beneficiary=&from=&to=&sort=&order=&page=&pageSize=50 → the Paging
// envelope + totals. Filters live in the query string (the earner as `who`). A row opens its statement on that invoice.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { API, LINES_PAGE_SIZE, LINE_KIND, STATEMENT_STATUS, commissionText, dayText, money, pct, periodLabel, personName, recordName, useCommissions, useSearchBox } from './commissions';

const { nav, seesAll, userOptions, periods } = useCommissions();
const { text, type, apply } = useSearchBox(nav);
const list = useList(`${API}/lines`, () => ({ q: nav.get('q'), kind: nav.get('kind') || 'invoice,credit_note,reversal', period: nav.get('period'), status: nav.get('status'), beneficiary: nav.get('who'),
  from: nav.get('from'), to: nav.get('to'), sort: nav.get('sort'), order: nav.get('order'), page: nav.page.value, pageSize: LINES_PAGE_SIZE }));
const d = computed(() => list.data.value || null);

const KINDS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'invoice', label: LINE_KIND.invoice }, { k: 'credit_note', label: LINE_KIND.credit_note }, { k: 'reversal', label: LINE_KIND.reversal }];
const STATUSES = Object.entries(STATEMENT_STATUS).map(([v, l]) => [v, { ar: l.ar, en: l.en }]);
const columns = computed(() => [
  { key: 'docNumber', header: { ar: 'المستند', en: 'Document' }, width: '150px', sortable: true },
  { key: 'docDate', header: { ar: 'تاريخه', en: 'Date' }, width: '86px', kind: 'date', sortable: true, value: (l) => dayText(l.docDate) },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(140px,1.3fr)', kind: 'name', value: (l) => recordName(l.customer) },
  { key: 'salesperson', header: { ar: 'مندوب الفاتورة', en: 'Salesperson' }, width: 'minmax(110px,1fr)', class: 'text-[10px]', value: (l) => (l.salesperson ? personName(l.salesperson) : '—') },
  { key: 'base', header: { ar: 'الأساس', en: 'Base' }, width: '96px', kind: 'num', sortable: true, value: (l) => money(l.baseAmount) },
  { key: 'rate', header: { ar: 'النسبة', en: 'Rate' }, width: '62px', kind: 'num', value: (l) => (l.excluded ? '—' : pct(l.rate)) },
  { key: 'commission', header: { ar: 'العمولة', en: 'Commission' }, width: '96px', kind: 'num', sortable: true },
  { key: 'statement', header: { ar: 'في كشف', en: 'In statement' }, width: 'minmax(150px,1.2fr)' },
  { key: 'status', header: { ar: 'حالة الكشف', en: 'Statement' }, width: '140px', bare: true },
]);
const shownSort = computed(() => nav.sort.value || { key: 'docDate', order: 'desc' });
const filtered = computed(() => ['q', 'kind', 'period', 'status', 'who', 'from', 'to'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ q: null, kind: null, period: null, status: null, who: null, from: null, to: null, sort: null, order: null });
const open = (line) => { if (line.statement) nav.openStatement(line.statement.number, line.docNumber ? { lq: line.docNumber } : {}); };
</script>

<template>
  <div class="row wrap mb-2.5 !items-end">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'رقم الفاتورة أو اسم العميل…', en: 'Invoice number or customer name…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="nav.get('kind')" :tabs="KINDS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.set('kind', k)" />
    <SelectInput small field-class="w-[136px]" :label="{ ar: 'شهر الكشف', en: 'Statement month' }" :model-value="nav.get('period')" :options="periods" :placeholder="{ ar: 'كل الشهور', en: 'Any month' }" @update:model-value="(v) => nav.set('period', v)" />
    <SelectInput small field-class="w-[170px]" :label="{ ar: 'حالة الكشف', en: 'Statement status' }" :model-value="nav.get('status')" :options="STATUSES" :placeholder="{ ar: 'كل الحالات', en: 'Any status' }" @update:model-value="(v) => nav.set('status', v)" />
    <SelectInput v-if="seesAll" small field-class="w-[190px]" :label="{ ar: 'المستحق له', en: 'Earner' }" :model-value="nav.get('who')" :options="userOptions" :placeholder="{ ar: 'كل المستحقين', en: 'Every earner' }" @update:model-value="(v) => nav.set('who', v)" />
    <DateInput small field-class="w-[140px]" :label="{ ar: 'فواتير من', en: 'Invoices from' }" :model-value="nav.get('from')" @update:model-value="(v) => nav.set('from', v)" />
    <DateInput small field-class="w-[140px]" :label="{ ar: 'إلى', en: 'To' }" :model-value="nav.get('to')" @update:model-value="(v) => nav.set('to', v)" />
    <Btn v-if="filtered || nav.sort.value" tone="ghost" size="sm" :label="{ ar: 'إزالة التصفية', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div v-if="d || !list.error.value" class="card">
    <DataTable :columns="columns" :paged="d" :loading="list.isFetching.value" :row-key="(l) => l.id" :sort="shownSort" server-sort
               :empty-text="filtered ? { ar: 'لا سطر عمولة يطابق هذا البحث. فاتورة لا تظهر هنا لم تُحتسب لها عمولة في أي كشف.', en: 'No commission line matches. An invoice that is not listed here was not paid a commission in any statement.' } : { ar: 'لا سطور عمولة بعد.', en: 'No commission line yet.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="open">
      <template #cell-docNumber="{ row }">
        <div class="min-w-0"><div class="cell-id truncate" :class="{ 'line-through opacity-60': row.voided }">{{ row.docNumber || '—' }}</div><Chip :map="LINE_KIND" :k="row.kind" small /></div>
      </template>
      <template #cell-commission="{ row }">
        <span class="num ltr font-bold" :class="Number(row.commission) < 0 ? 'text-bad' : ''">{{ commissionText(row.commission) }}</span>
        <Chip v-if="row.excluded" small class="ms-1" fg="#b26a16" bg="#fbf0dd" :label="{ ar: 'مستبعدة', en: 'Excluded' }" />
      </template>
      <template #cell-statement="{ row }">
        <div v-if="row.statement" class="min-w-0">
          <div class="truncate text-[10.5px] font-bold" dir="auto">{{ personName(row.statement.beneficiary) }}</div>
          <div class="num ltr truncate text-[9.5px] text-muted">{{ row.statement.number }} · {{ periodLabel(row.statement.period) }}</div>
        </div>
      </template>
      <template #cell-status="{ row }"><Chip v-if="row.statement" :map="STATEMENT_STATUS" :k="row.statement.status" small /></template>
      <template v-if="d && d.total > 0" #footer>
        <span class="flex flex-wrap items-baseline gap-x-3 font-bold text-sec">
          <span>{{ t('لكل السطور المطابقة — الأساس', 'Every matching line — base') }} <span class="num ltr text-ink">{{ money(d.totals.base) }}</span></span>
          <span>{{ t('العمولة', 'commission') }} <span class="num ltr text-[11.5px] text-ink">{{ commissionText(d.totals.commission) }}</span></span>
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('سطر لكل فاتورة وقاعدة، وسطر سالب لكل إشعار دائن أو عكس. الفاتورة تُحتسب مرة واحدة لكل مستحق: إن وجدتها هنا فقد دخلت كشفًا، واضغط السطر لتفتحه.', 'One line per invoice and rule, a negative one per credit note or reversal. An invoice is counted once per earner: if it is listed here it is in a statement — click the line to open it.') }}</div>
</template>
