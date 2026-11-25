<script setup>
// The statements the user may see — his own, or all of them with commission.manage / commission.approve.
// GET /commissions/statements?q=&status=&from=&to=&beneficiary=&source=&sort=&order=&page=&pageSize=25 → the Paging
// envelope + totals { base, commission, adjustments, total } of every matching statement; the three tiles above come
// from GET /commissions/summary with the same period / earner / source. Search, filters, sort and page live in the
// query string under the name of the API parameter (the earner as `who`, a plan's statements as `ofplan`). A row opens
// the statement (`statement=<number>`).
import { computed } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, SelectInput, Tabs, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';
import { API, ODOO_CHIP, PAGE_SIZE, STATEMENT_STATUS, money, periodLabel, personName, recordName, sar, useCommissions, useSearchBox } from './commissions';

const { nav, lookups, seesAll, userOptions, periods } = useCommissions();
const { text, type, apply } = useSearchBox(nav);
const scope = computed(() => ({ from: nav.get('from'), to: nav.get('to'), beneficiary: nav.get('who'), source: nav.get('source') }));
const list = useList(`${API}/statements`, () => ({ ...scope.value, plan: nav.get('ofplan'), q: nav.get('q'), status: nav.get('status'), sort: nav.get('sort'), order: nav.get('order'), page: nav.page.value, pageSize: PAGE_SIZE }));
const summaryQ = useGet(`${API}/summary`, () => scope.value);
const d = computed(() => list.data.value || null);
const totals = computed(() => summaryQ.data.value?.totals || null);

const STATUSES = [
  { k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'draft', label: STATEMENT_STATUS.draft }, { k: 'submitted', label: STATEMENT_STATUS.submitted },
  { k: 'approved', label: { ar: 'بانتظار الصرف', en: 'To be paid' } }, { k: 'paid', label: { ar: 'مصروفة', en: 'Paid' } },
];
const SOURCES = [['ops', { ar: 'كشوف العمليات', en: 'Made in OPS' }], ['odoo', { ar: 'تاريخ أودو', en: 'Odoo’s history' }]];
const SORTS = [['period', { ar: 'الشهر', en: 'Month' }], ['total', { ar: 'المستحق', en: 'Payable' }], ['base', { ar: 'الأساس', en: 'Base' }], ['lines', { ar: 'عدد السطور', en: 'Lines' }], ['number', { ar: 'رقم الكشف', en: 'Number' }]];
const columns = computed(() => [
  { key: 'number', header: { ar: 'الكشف', en: 'Statement' }, width: '138px', sortable: true },
  { key: 'beneficiary', header: { ar: 'المستحق له', en: 'Earner' }, width: 'minmax(140px,1.3fr)', kind: 'name', hidden: !seesAll.value, value: (r) => personName(r.beneficiary) },
  { key: 'period', header: { ar: 'الشهر', en: 'Month' }, width: '92px', sortable: true, value: (r) => periodLabel(r.period) },
  { key: 'plan', header: { ar: 'الخطة', en: 'Plan' }, width: 'minmax(130px,1fr)', class: 'text-[10px] text-muted', value: (r) => (r.plan ? recordName(r.plan) : '—') },
  { key: 'lines', header: { ar: 'سطور', en: 'Lines' }, width: '58px', kind: 'num', sortable: true, value: (r) => String(r.linesCount) },
  { key: 'base', header: { ar: 'الأساس', en: 'Base' }, width: '104px', kind: 'num', sortable: true, value: (r) => money(r.baseTotal) },
  { key: 'commission', header: { ar: 'العمولة', en: 'Commission' }, width: '96px', kind: 'num', value: (r) => money(r.commissionTotal) },
  { key: 'adjustments', header: { ar: 'تسويات', en: 'Adjust.' }, width: '84px', kind: 'num', value: (r) => (Number(r.adjustmentsTotal) === 0 ? '—' : money(r.adjustmentsTotal)) },
  { key: 'total', header: { ar: 'المستحق', en: 'Payable' }, width: '104px', kind: 'num', sortable: true },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px', bare: true },
]);
const shownSort = computed(() => nav.sort.value || { key: 'period', order: 'desc' });
const filtered = computed(() => ['q', 'status', 'from', 'to', 'who', 'source', 'ofplan'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ q: null, status: null, from: null, to: null, who: null, source: null, ofplan: null, sort: null, order: null });
/** An earner kept by name (Odoo's history) is not in the list of users: the filter shows as a pill instead. */
const namedWho = computed(() => (nav.get('who').startsWith('name:') ? nav.get('who').slice(5) : ''));
/** A tile filters the list to its bucket (pending = draft + waiting for approval). */
const bucket = (statuses) => nav.set('status', nav.get('status') === statuses ? null : statuses);
const emptyText = computed(() => (filtered.value
  ? { ar: 'لا كشف يطابق هذا البحث أو هذه التصفية. غيّرها أو أزلها لترى بقية الكشوف.', en: 'No statement matches this search or these filters. Change or clear them to see the others.' }
  : seesAll.value
    ? { ar: 'لا كشوف بعد. فعّل خطة عمولات ثم «احتساب شهر»: يُنشأ كشف لكل مستحق من فواتير ذلك الشهر.', en: 'No statement yet. Activate a commission plan, then “Calculate a month”: a statement is made for every earner from that month’s invoices.' }
    : { ar: 'لا كشوف عمولة باسمك بعد. يظهر كشفك هنا حين يُحتسب شهرك.', en: 'No commission statement in your name yet. Yours appears here once your month is calculated.' }));
</script>

<template>
  <KpiGrid compact class="!mb-3">
    <KpiCard compact clickable :value="totals ? money(totals.pending.amount) : null" :unit="sar()" :label="{ ar: 'قيد الإعداد والاعتماد', en: 'Pending' }" :sub="totals ? t(`${totals.pending.count} كشف — مسودة أو بانتظار الاعتماد`, `${totals.pending.count} statements — draft or awaiting approval`) : null"
             color="#b26a16" :active="nav.get('status') === 'draft,submitted'" :loading="summaryQ.isLoading.value" @click="bucket('draft,submitted')" />
    <KpiCard compact clickable :value="totals ? money(totals.approved.amount) : null" :unit="sar()" :label="{ ar: 'معتمد بانتظار الصرف', en: 'Approved, to be paid' }" :sub="totals ? t(`${totals.approved.count} كشف`, `${totals.approved.count} statements`) : null"
             color="#3C79F5" :active="nav.get('status') === 'approved'" :loading="summaryQ.isLoading.value" @click="bucket('approved')" />
    <KpiCard compact clickable :value="totals ? money(totals.paid.amount) : null" :unit="sar()" :label="{ ar: 'مصروف', en: 'Paid' }" :sub="totals ? t(`${totals.paid.count} كشف`, `${totals.paid.count} statements`) : null"
             color="#1d7a3e" :active="nav.get('status') === 'paid'" :loading="summaryQ.isLoading.value" @click="bucket('paid')" />
  </KpiGrid>

  <div class="row wrap mb-2.5 !items-end">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="seesAll ? { ar: 'بحث برقم الكشف أو المستحق أو مرجع الحوالة…', en: 'Search number, earner or transfer reference…' } : { ar: 'بحث برقم الكشف…', en: 'Search by number…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="['', 'draft', 'submitted', 'approved', 'paid'].includes(nav.get('status')) ? nav.get('status') : null" :tabs="STATUSES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.set('status', k)" />
    <SelectInput small field-class="w-[136px]" :label="{ ar: 'من شهر', en: 'From month' }" :model-value="nav.get('from')" :options="periods" :placeholder="{ ar: 'البداية', en: 'Start' }" @update:model-value="(v) => nav.set('from', v)" />
    <SelectInput small field-class="w-[136px]" :label="{ ar: 'إلى شهر', en: 'To month' }" :model-value="nav.get('to')" :options="periods" :placeholder="{ ar: 'الآن', en: 'Now' }" @update:model-value="(v) => nav.set('to', v)" />
    <button v-if="namedWho" type="button" class="pill active max-w-full" :title="t('إزالة هذه التصفية', 'Remove this filter')" @click="nav.set('who', null)"><span class="min-w-0 truncate" dir="auto">{{ t('المستحق له', 'Earner') }}: {{ namedWho }}</span><span aria-hidden="true">✕</span></button>
    <button v-if="nav.get('ofplan')" type="button" class="pill active max-w-full" :title="t('إزالة هذه التصفية', 'Remove this filter')" @click="nav.set('ofplan', null)"><span class="num ltr">{{ t('الخطة', 'Plan') }}: {{ nav.get('ofplan') }}</span><span aria-hidden="true">✕</span></button>
    <SelectInput v-if="seesAll" small field-class="w-[190px]" :label="{ ar: 'المستحق له', en: 'Earner' }" :model-value="namedWho ? '' : nav.get('who')" :options="userOptions" :placeholder="{ ar: 'كل المستحقين', en: 'Every earner' }" @update:model-value="(v) => nav.set('who', v)" />
    <SelectInput v-if="seesAll" small field-class="w-[140px]" :label="{ ar: 'المصدر', en: 'Source' }" :model-value="nav.get('source')" :options="SOURCES" :placeholder="{ ar: 'الكل', en: 'All' }" @update:model-value="(v) => nav.set('source', v)" />
    <SelectInput v-if="isMobile" small field-class="w-[150px]" :label="{ ar: 'الترتيب', en: 'Sort by' }" :model-value="shownSort.key" :options="SORTS" @update:model-value="(k) => nav.setSort({ key: k, order: shownSort.order })" />
    <button v-if="isMobile" type="button" class="pill" @click="nav.setSort({ key: shownSort.key, order: shownSort.order === 'asc' ? 'desc' : 'asc' })">{{ shownSort.order === 'asc' ? t('تصاعدي', 'Ascending') : t('تنازلي', 'Descending') }}</button>
    <Btn v-if="filtered || nav.sort.value" tone="ghost" size="sm" :label="{ ar: 'إزالة التصفية', en: 'Clear filters' }" @click="clear" />
  </div>

  <ErrorBanner :error="list.error.value" :closable="false" />
  <ErrorBanner :error="summaryQ.error.value" :closable="false" />
  <div v-if="d || !list.error.value" class="card">
    <DataTable :columns="columns" :paged="d" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('statement') || null" :sort="shownSort" server-sort :empty-text="emptyText"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.openStatement(r.number)">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-id truncate">{{ row.number }}</div>
          <div v-if="row.sourceSystem === 'odoo'" class="flex min-w-0 items-center gap-1"><Chip small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" /><span class="num ltr truncate text-[9px] text-muted">{{ row.reference }}</span></div>
        </div>
      </template>
      <template #cell-total="{ row }"><span class="num ltr font-bold" :class="Number(row.total) < 0 ? 'text-bad' : ''">{{ money(row.total) }}</span></template>
      <template #cell-status="{ row }"><Chip :map="STATEMENT_STATUS" :k="row.status" /></template>
      <template v-if="d && d.total > 0" #footer>
        <span class="flex flex-wrap items-baseline gap-x-3 font-bold text-sec">
          <span>{{ t('لكل الكشوف المطابقة — الأساس', 'Every matching statement — base') }} <span class="num ltr text-ink">{{ money(d.totals.base) }}</span></span>
          <span>{{ t('المستحق', 'payable') }} <span class="num ltr text-[11.5px] text-ink">{{ money(d.totals.total) }}</span> <span class="text-[9px] font-normal text-muted">{{ sar() }}</span></span>
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">
    <template v-if="seesAll">{{ t('الكشف لمستحق واحد وشهر واحد. المسودة يُعاد احتسابها متى شئت من فواتير المالية؛ بعد التقديم تُقفل، وما يتغير لاحقًا (فاتورة أُلغيت، تحصيل أُلغي، إشعار دائن) يظهر سطرًا في كشف الشهر المفتوح.', 'A statement is one earner and one month. A draft is recalculated at will from the finance invoices; once submitted it is closed, and what changes later (a cancelled invoice, a voided receipt, a credit note) shows as a line in the open month’s statement.') }}</template>
    <template v-else>{{ t('ترى هنا كشوف عمولتك فقط. المسودة قيد المراجعة وأرقامها قد تتغير؛ «معتمد» يعني أنه بانتظار الصرف.', 'You see your own statements only. A draft is under review and its figures may change; “approved” means it waits for its payout.') }}</template>
    <template v-if="lookups.following"> {{ t('الكشوف الموسومة «من أودو» هي تاريخ العمولات كما احتُسب هناك.', 'Statements marked “From Odoo” are the history of commissions as calculated there.') }}</template>
  </div>
</template>
