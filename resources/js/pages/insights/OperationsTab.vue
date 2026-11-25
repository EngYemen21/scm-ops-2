<script setup>
// «التحليلات» — the operations manager's insights (not for brand owners). view=summary|slow|margin|pnl|ageing|invoices.
// What OPS already computes is called, not recomputed, so the figures agree with the existing reports:
//   summary   GET /insights/operations/summary?from&to&days — expiry buckets (the expiry report), slow movers, and with
//             fin.report the P&L totals, customer ageing (OPS and, apart, Odoo's open invoices) and invoice counts.
//   slow      GET /insights/operations/slow-moving?days&warehouse&q&sort&order&page (value at cost with inventory.view_cost)
//   margin    GET /insights/operations/customer-margin?from&to   (the existing profitability report, per customer)   fin.report
//   pnl       GET /insights/operations/pnl?from&to               (the existing income statement)                     fin.report
//   ageing    GET /insights/operations/ageing                    (the existing customer ageing + Odoo's open invoices) fin.report
//   invoices  GET /insights/operations/invoice-counts?from&to    (OPS by state, Odoo's posted history)               fin.report
import { computed, ref } from 'vue';
import { useGet, useList } from '@/api/client';
import { DataTable, DateInput, ErrorBanner, KpiCard, KpiGrid, NumberInput, SectionCard, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import { amount, canOpen, fmtDay, money, monthLabel, nameOf, pct, useInsightsNav, useSearchParam } from './insights';

const props = defineProps({
  me: { type: Object, required: true },
});

const nav = useInsightsNav();
const { page } = nav;
const auth = useAuth();
const wh = useWarehouse();
const finance = computed(() => !!props.me.finance);

const views = computed(() => [
  { k: 'summary', label: { ar: 'الملخص', en: 'Summary' } },
  { k: 'slow', label: { ar: 'بطيء الحركة', en: 'Slow movers' } },
  { k: 'margin', label: { ar: 'هامش العملاء', en: 'Customer margin' }, hidden: !finance.value },
  { k: 'pnl', label: { ar: 'الأرباح والخسائر', en: 'Profit & loss' }, hidden: !finance.value },
  { k: 'ageing', label: { ar: 'أعمار الذمم', en: 'Receivables ageing' }, hidden: !finance.value },
  { k: 'invoices', label: { ar: 'عدد الفواتير', en: 'Invoice counts' }, hidden: !finance.value },
]);
const view = computed(() => (views.value.some((v) => v.k === nav.get('view') && !v.hidden) ? nav.get('view') : 'summary'));
const setView = (k) => nav.go('ops', { view: k === 'summary' ? null : k, from: nav.get('from'), to: nav.get('to'), days: nav.get('days') });
const usesRange = computed(() => ['summary', 'margin', 'pnl', 'invoices'].includes(view.value));
const usesDays = computed(() => ['summary', 'slow'].includes(view.value));
const range = () => ({ from: nav.get('from') || undefined, to: nav.get('to') || undefined });
const days = computed(() => Math.max(1, Math.min(3650, Number(nav.get('days')) || 90)));
const setDays = (v) => nav.setFilter('days', v && v !== 90 ? Math.round(v) : null);

// ---- summary
const summary = useGet(() => (view.value === 'summary' ? '/insights/operations/summary' : null), () => ({ ...range(), days: days.value }));
const s = computed(() => summary.data.value || null);
const BUCKET = {
  expired: { ar: 'منتهية', en: 'Expired', color: '#b23b3b' }, '0-7': { ar: 'خلال 7 أيام', en: 'Within 7 days', color: '#b23b3b' },
  '8-30': { ar: '8–30 يومًا', en: '8–30 days', color: '#b26a16' }, '31-90': { ar: '31–90 يومًا', en: '31–90 days', color: '#8a6d00' }, '90+': { ar: 'أكثر من 90 يومًا', en: 'Over 90 days', color: '#1d7a3e' },
};
const reportsOk = computed(() => canOpen(auth, 'reports'));

// ---- slow movers
const { text, type, apply } = useSearchParam(nav);
const slowSort = nav.sortOf(['daysSinceOut', 'qty', 'value', 'sku'], 'desc');
const slowWh = computed(() => (nav.get('warehouse') === 'all' ? '' : nav.get('warehouse') || (wh.isAll ? '' : wh.wh)));
const whOptions = computed(() => [{ v: 'all', l: { ar: 'كل المستودعات', en: 'All warehouses' } }, ...wh.warehouses.map((w) => ({ v: w.code, l: `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}` }))]);
const slow = useList(() => (view.value === 'slow' ? '/insights/operations/slow-moving' : null), () => ({
  days: days.value, warehouse: slowWh.value || undefined, q: nav.get('q') || undefined, sort: slowSort.value?.key, order: slowSort.value?.order, page: page.value, pageSize: 50,
}));
const slowColumns = computed(() => [
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(200px,1.6fr)', sortable: true },
  { key: 'brand', header: { ar: 'العلامة', en: 'Brand' }, width: 'minmax(90px,0.6fr)' },
  { key: 'category', header: { ar: 'الفئة', en: 'Category' }, width: '96px', kind: 'id' },
  { key: 'qty', header: { ar: 'الرصيد', en: 'On hand' }, width: '80px', kind: 'num', sortable: true },
  { key: 'daysSinceOut', header: { ar: 'أيام بلا صرف', en: 'Days without out' }, width: '104px', kind: 'num', sortable: true },
  { key: 'lastOut', header: { ar: 'آخر صرف', en: 'Last out' }, width: '90px', kind: 'date', value: (r) => (r.lastOut ? fmtDay(r.lastOut) : t('لم يُصرف', 'Never')) },
  { key: 'firstIn', header: { ar: 'أول وارد', en: 'First in' }, width: '90px', kind: 'date', value: (r) => fmtDay(r.firstIn) },
  { key: 'value', header: { ar: 'القيمة بالتكلفة', en: 'Value at cost' }, width: '110px', kind: 'num', sortable: true, hidden: !slow.data.value?.costVisible, value: (r) => amount(r.value) },
]);
const onSlowSort = (x) => nav.setSort({ key: x.key === 'product' ? 'sku' : x.key, order: x.order });
const slowSortShown = computed(() => (slowSort.value?.key === 'sku' ? { key: 'product', order: slowSort.value.order } : slowSort.value));

// ---- customer margin
const margin = useGet(() => (view.value === 'margin' ? '/insights/operations/customer-margin' : null), () => range());
const marginQ = ref('');
const marginRows = computed(() => {
  const needle = marginQ.value.trim().toLowerCase();
  return (margin.data.value?.rows || []).filter((r) => !needle || `${r.code} ${r.nameAr} ${r.nameEn}`.toLowerCase().includes(needle));
});
const marginColumns = [
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(200px,1.6fr)', sortable: true, sortValue: (r) => nameOf(r) },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '80px', kind: 'num', sortable: true },
  { key: 'revenue', header: { ar: 'الإيراد', en: 'Revenue' }, width: '110px', kind: 'num', sortable: true, value: (r) => amount(r.revenue) },
  { key: 'cogs', header: { ar: 'تكلفة المبيعات', en: 'Cost of sales' }, width: '110px', kind: 'num', sortable: true, value: (r) => amount(r.cogs) },
  { key: 'grossProfit', header: { ar: 'مجمل الربح', en: 'Gross profit' }, width: '110px', kind: 'num', sortable: true },
  { key: 'marginPct', header: { ar: 'الهامش', en: 'Margin' }, width: '80px', kind: 'num', sortable: true, value: (r) => pct(r.marginPct) },
];

// ---- P&L
const pnl = useGet(() => (view.value === 'pnl' ? '/insights/operations/pnl' : null), () => range());
const SECTIONS = [
  { k: 'revenue', ar: 'الإيرادات', en: 'Revenue' }, { k: 'cogs', ar: 'تكلفة المبيعات', en: 'Cost of sales' }, { k: 'opex', ar: 'المصروفات التشغيلية', en: 'Operating expenses' },
];

// ---- ageing
const ageing = useGet(() => (view.value === 'ageing' ? '/insights/operations/ageing' : null));
const ageSide = computed(() => (nav.get('side') === 'odoo' ? 'odoo' : 'ops'));
const ageQ = ref('');
const ageRows = computed(() => {
  const side = ageing.data.value?.[ageSide.value];
  const needle = ageQ.value.trim().toLowerCase();
  return (side?.rows || []).filter((r) => !needle || `${r.code || r.customerCode || ''} ${r.partnerRef || ''} ${r.nameAr || r.name || ''} ${r.nameEn || ''}`.toLowerCase().includes(needle));
});
const BUCKETS = [
  { key: 'current', header: { ar: 'غير مستحق', en: 'Current' } }, { key: 'd30', header: { ar: '1–30', en: '1–30' } }, { key: 'd60', header: { ar: '31–60', en: '31–60' } },
  { key: 'd90', header: { ar: '61–90', en: '61–90' } }, { key: 'd90p', header: { ar: '+90', en: '90+' } }, { key: 'total', header: { ar: 'الإجمالي', en: 'Total' } },
];
const ageColumns = computed(() => [
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(190px,1.5fr)', sortable: true, sortValue: (r) => r.nameAr || r.name || '' },
  ...BUCKETS.map((b) => ({ ...b, width: '92px', kind: 'num', sortable: true, value: (r) => amount(r[b.key]) })),
  { key: 'invoices', header: { ar: 'فواتير', en: 'Invoices' }, width: '70px', kind: 'num', sortable: true },
]);
const ageTotals = computed(() => ageing.data.value?.[ageSide.value]?.totals || null);

// ---- invoice counts
const counts = useGet(() => (view.value === 'invoices' ? '/insights/operations/invoice-counts' : null), () => range());
const countColumns = [
  { key: 'month', header: { ar: 'الشهر', en: 'Month' }, width: 'minmax(110px,1fr)', value: (r) => monthLabel(r.month) },
  { key: 'opsInvoices', header: { ar: 'فواتير العمليات', en: 'OPS invoices' }, width: '96px', kind: 'num' },
  { key: 'opsCreditNotes', header: { ar: 'إشعارات العمليات', en: 'OPS credit notes' }, width: '104px', kind: 'num' },
  { key: 'opsCancelled', header: { ar: 'ملغاة', en: 'Cancelled' }, width: '76px', kind: 'num' },
  { key: 'opsAmount', header: { ar: 'مبلغ العمليات', en: 'OPS amount' }, width: '110px', kind: 'num', value: (r) => amount(r.opsAmount) },
  { key: 'odooInvoices', header: { ar: 'فواتير أودو', en: 'Odoo invoices' }, width: '92px', kind: 'num' },
  { key: 'odooCreditNotes', header: { ar: 'إشعارات أودو', en: 'Odoo credit notes' }, width: '100px', kind: 'num' },
  { key: 'odooAmount', header: { ar: 'مبلغ أودو', en: 'Odoo amount' }, width: '110px', kind: 'num', value: (r) => amount(r.odooAmount) },
];
const existing = (ar, en) => t(`من تقرير المالية القائم: ${ar} — الأرقام نفسها التي تعرضها صفحة المالية.`, `From the existing finance report: ${en} — the same figures the finance page shows.`);
</script>

<template>
  <Tabs :model-value="view" :tabs="views" variant="sm" @update:model-value="setView" />

  <div v-if="usesRange || usesDays" class="row wrap mb-2.5">
    <template v-if="usesRange">
      <DateInput small class="w-[150px]" :title="t('من تاريخ', 'From date')" :model-value="nav.get('from')" @update:model-value="(v) => nav.setFilter('from', v)" />
      <DateInput small class="w-[150px]" :title="t('إلى تاريخ', 'To date')" :model-value="nav.get('to')" @update:model-value="(v) => nav.setFilter('to', v)" />
      <span class="text-[9.5px] text-faint">{{ t('فارغ = من أول الشهر إلى اليوم', 'Empty = from the start of the month to today') }}</span>
    </template>
    <template v-if="usesDays">
      <span class="text-[10.5px] font-bold text-sec">{{ t('بطيء الحركة: بلا صرف منذ', 'Slow: no out for') }}</span>
      <NumberInput small center class="w-[80px]" :model-value="days" :min="1" :max="3650" :step="1" @update:model-value="setDays" />
      <span class="text-[10.5px] text-sec">{{ t('يومًا', 'days') }}</span>
    </template>
  </div>

  <!-- summary -->
  <template v-if="view === 'summary'">
    <ErrorBanner :error="summary.error.value" :closable="false" />
    <div v-if="!s && summary.isLoading.value" class="skel h-[260px]" />
    <template v-else-if="s">
      <div class="mb-2 text-[10px] text-muted">{{ t('الفترة', 'Period') }} <b class="num ltr inline-block">{{ fmtDay(s.from) }} → {{ fmtDay(s.to) }}</b></div>
      <SectionCard :title="{ ar: 'الصلاحية — الدفعات ذات الرصيد', en: 'Expiry — lots with stock' }" :sub="bi(s.expiry.definition)">
        <template v-if="reportsOk" #actions><RouterLink class="text-[10px] font-bold text-azure underline" to="/reports?r=expiry">{{ t('فتح تقرير الصلاحية', 'Open the expiry report') }}</RouterLink></template>
        <KpiGrid compact>
          <KpiCard v-for="b in s.expiry.buckets" :key="b.bucket" compact :value="b.lots" :label="BUCKET[b.bucket] || b.bucket" :color="BUCKET[b.bucket]?.color || '#20242E'"
                   :sub="b.value != null ? t(`الكمية ${fmtNum(b.qty)} · ${amount(b.value)} ر.س`, `Qty ${fmtNum(b.qty)} · SAR ${amount(b.value)}`) : t(`الكمية ${fmtNum(b.qty)}`, `Qty ${fmtNum(b.qty)}`)"
                   :unit="{ ar: 'دفعة', en: 'lots' }" />
        </KpiGrid>
      </SectionCard>

      <SectionCard class="mt-3" :title="{ ar: 'بطيء الحركة', en: 'Slow movers' }" :sub="{ ar: `منتجات لها رصيد ولم تخرج من المخزون منذ ${s.slowMoving.days} يومًا.`, en: `Products with stock that did not leave stock for ${s.slowMoving.days} days.` }">
        <KpiGrid compact>
          <KpiCard compact clickable :value="s.slowMoving.products" :label="{ ar: 'منتجات', en: 'Products' }" color="#b26a16" @click="setView('slow')" />
          <KpiCard compact clickable :value="s.slowMoving.qty" :label="{ ar: 'الكمية', en: 'Quantity' }" @click="setView('slow')" />
          <KpiCard v-if="s.slowMoving.value != null" compact clickable :value="money(s.slowMoving.value)" :label="{ ar: 'القيمة بالتكلفة', en: 'Value at cost' }" @click="setView('slow')" />
        </KpiGrid>
      </SectionCard>

      <template v-if="s.finance">
        <SectionCard v-if="s.pnl" class="mt-3" :title="{ ar: 'الأرباح والخسائر', en: 'Profit and loss' }" :sub="existing('قائمة الدخل', 'income statement')">
          <KpiGrid compact>
            <KpiCard compact clickable :value="money(s.pnl.revenue)" :label="{ ar: 'الإيرادات', en: 'Revenue' }" color="#1d7a3e" @click="setView('pnl')" />
            <KpiCard compact clickable :value="money(s.pnl.cogs)" :label="{ ar: 'تكلفة المبيعات', en: 'Cost of sales' }" @click="setView('pnl')" />
            <KpiCard compact clickable :value="money(s.pnl.grossProfit)" :label="{ ar: 'مجمل الربح', en: 'Gross profit' }" :sub="pct(s.pnl.grossMarginPct)" color="#654e92" @click="setView('pnl')" />
            <KpiCard compact clickable :value="money(s.pnl.opex)" :label="{ ar: 'المصروفات التشغيلية', en: 'Operating expenses' }" @click="setView('pnl')" />
            <KpiCard compact clickable :value="money(s.pnl.netIncome)" :label="{ ar: 'صافي الدخل', en: 'Net income' }" :color="s.pnl.netIncome < 0 ? '#b23b3b' : '#1d7a3e'" @click="setView('pnl')" />
          </KpiGrid>
        </SectionCard>
        <div class="grid-2 mt-3">
          <SectionCard v-if="s.ageing" :title="{ ar: 'أعمار ذمم العملاء — العمليات', en: 'Customer ageing — OPS' }" :sub="existing('أعمار الذمم', 'receivables ageing')">
            <KpiGrid compact>
              <KpiCard compact clickable :value="money(s.ageing.total)" :label="{ ar: 'المفتوح', en: 'Open' }" :sub="t(`${s.ageing.customers} عميل`, `${s.ageing.customers} customer(s)`)" @click="nav.go('ops', { view: 'ageing' })" />
              <KpiCard compact clickable :value="money(s.ageing.d90p)" :label="{ ar: 'أكثر من 90 يومًا', en: 'Over 90 days' }" color="#b23b3b" @click="nav.go('ops', { view: 'ageing' })" />
            </KpiGrid>
          </SectionCard>
          <SectionCard v-if="s.odooAgeing" :title="{ ar: 'فواتير مفتوحة في أودو', en: 'Invoices open in Odoo' }" :sub="{ ar: 'من نسخة أودو المحلية، منفصلة عن ذمم العمليات.', en: 'From the local Odoo copy, apart from the OPS receivables.' }">
            <KpiGrid compact>
              <KpiCard compact clickable :value="money(s.odooAgeing.total)" :label="{ ar: 'المفتوح', en: 'Open' }" :sub="t(`${s.odooAgeing.invoices} فاتورة`, `${s.odooAgeing.invoices} invoice(s)`)" color="#654e92" @click="nav.go('ops', { view: 'ageing', side: 'odoo' })" />
              <KpiCard compact clickable :value="money(s.odooAgeing.d90p)" :label="{ ar: 'أكثر من 90 يومًا', en: 'Over 90 days' }" color="#b23b3b" @click="nav.go('ops', { view: 'ageing', side: 'odoo' })" />
            </KpiGrid>
          </SectionCard>
        </div>
        <SectionCard v-if="s.invoices" class="mt-3" :title="{ ar: 'الفواتير في الفترة', en: 'Invoices in the period' }" :sub="{ ar: 'العمليات حسب الحالة، وتاريخ أودو المرحّل.', en: 'OPS by state, and Odoo\'s posted history.' }">
          <KpiGrid compact>
            <KpiCard compact clickable :value="s.invoices.opsInvoices" :label="{ ar: 'فواتير العمليات', en: 'OPS invoices' }" @click="setView('invoices')" />
            <KpiCard compact clickable :value="s.invoices.opsCreditNotes" :label="{ ar: 'إشعارات دائن', en: 'Credit notes' }" @click="setView('invoices')" />
            <KpiCard compact clickable :value="money(s.invoices.opsAmount)" :label="{ ar: 'مبلغ العمليات', en: 'OPS amount' }" @click="setView('invoices')" />
            <KpiCard compact clickable :value="s.invoices.odooInvoices" :label="{ ar: 'فواتير أودو', en: 'Odoo invoices' }" color="#654e92" @click="setView('invoices')" />
            <KpiCard compact :value="s.invoices.opsOpen" :label="{ ar: 'مفتوحة الآن', en: 'Open now' }" />
            <KpiCard compact :value="s.invoices.opsOverdue" :label="{ ar: 'متأخرة الآن', en: 'Overdue now' }" color="#b23b3b" />
          </KpiGrid>
        </SectionCard>
      </template>
      <div v-else class="hint !mt-3">{{ t('الأرقام المالية (الأرباح، الذمم، الفواتير) تحتاج صلاحية تقارير المالية.', 'The finance figures (profit, receivables, invoices) need the finance-reports permission.') }}</div>
    </template>
  </template>

  <!-- slow movers -->
  <template v-else-if="view === 'slow'">
    <div class="row wrap mb-2">
      <TextInput scan small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'بحث بالـ SKU أو الاسم…', en: 'Search SKU or name…' }" @update:model-value="type" @enter="apply" />
      <SelectInput small class="w-[190px]" :model-value="slowWh || 'all'" :options="whOptions" @update:model-value="(v) => nav.setFilter('warehouse', v)" />
    </div>
    <div v-if="slow.data.value?.definition" class="hint teal !mt-0 mb-2"><b>{{ t('التعريف', 'Definition') }}:</b> {{ bi(slow.data.value.definition) }}</div>
    <ErrorBanner :error="slow.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="slowColumns" :paged="slow.data.value" :loading="slow.isFetching.value" :row-key="(r) => r.productId" :sort="slowSortShown" server-sort @sort="onSlowSort" @page="nav.setPage"
                 :empty-text="nav.get('q') ? { ar: 'لا منتج يطابق البحث.', en: 'No product matches the search.' } : { ar: 'لا منتجات بطيئة الحركة بهذا الشرط.', en: 'No slow mover under this condition.' }">
        <template #cell-product="{ row }">
          <div class="min-w-0">
            <RouterLink class="cell-name block truncate hover:underline" :to="`/product/${encodeURIComponent(row.sku)}`" dir="auto">{{ nameOf(row) }}</RouterLink>
            <div class="cell-sub"><span class="num ltr inline-block">{{ row.sku }}</span></div>
          </div>
        </template>
        <template #cell-brand="{ row }"><span class="truncate text-[10px] text-violet" dir="auto">{{ row.brand || '—' }}</span></template>
        <template #cell-qty="{ row }">{{ fmtNum(row.qty) }}</template>
        <template #cell-daysSinceOut="{ row }"><span class="font-bold" :class="row.daysSinceOut == null ? 'text-bad' : 'text-warn'">{{ row.daysSinceOut == null ? t('لم يُصرف', 'Never') : fmtNum(row.daysSinceOut) }}</span></template>
      </DataTable>
    </div>
    <div v-if="slow.data.value" class="mt-2 text-[11px]">
      <span class="font-extrabold text-sec">{{ t('المجموع', 'Total') }}:</span> {{ t('منتجات', 'products') }} <b class="num">{{ fmtNum(slow.data.value.total) }}</b> · {{ t('الكمية', 'qty') }} <b class="num">{{ fmtNum(slow.data.value.totals?.qty) }}</b>
      <template v-if="slow.data.value.totals?.value != null"> · {{ t('القيمة بالتكلفة', 'value at cost') }} <b class="num">{{ money(slow.data.value.totals.value) }}</b></template>
    </div>
  </template>

  <!-- customer margin -->
  <template v-else-if="view === 'margin'">
    <div class="hint purple !mt-0 mb-2">{{ existing('الربحية حسب العميل', 'profitability by customer') }}<template v-if="margin.data.value?.definition"> {{ bi(margin.data.value.definition) }}</template></div>
    <div class="row wrap mb-2"><TextInput v-model="marginQ" small type="search" class="w-[240px]" :placeholder="{ ar: 'بحث بالعميل…', en: 'Search customer…' }" /></div>
    <ErrorBanner :error="margin.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="marginColumns" :rows="marginRows" :page-size="50" :loading="margin.isLoading.value" :row-key="(r, i) => r.code || i" :empty-text="{ ar: 'لا فواتير في الفترة.', en: 'No invoice in the period.' }">
        <template #cell-customer="{ row }">
          <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div><div class="cell-sub"><span class="num ltr inline-block">{{ row.code || '—' }}</span></div></div>
        </template>
        <template #cell-qty="{ row }">{{ fmtNum(row.qty) }}</template>
        <template #cell-grossProfit="{ row }"><b :class="row.grossProfit < 0 ? 'text-bad' : 'text-ok'">{{ amount(row.grossProfit) }}</b></template>
      </DataTable>
    </div>
    <div v-if="margin.data.value?.totals" class="mt-2 text-[11px]">
      <span class="font-extrabold text-sec">{{ t('المجموع', 'Total') }}:</span> {{ t('الإيراد', 'revenue') }} <b class="num">{{ money(margin.data.value.totals.revenue) }}</b> · {{ t('التكلفة', 'cost') }} <b class="num">{{ money(margin.data.value.totals.cogs) }}</b>
      · {{ t('مجمل الربح', 'gross profit') }} <b class="num">{{ money(margin.data.value.totals.grossProfit) }}</b>
    </div>
  </template>

  <!-- P&L -->
  <template v-else-if="view === 'pnl'">
    <div class="hint purple !mt-0 mb-2">{{ existing('قائمة الدخل', 'income statement') }}</div>
    <ErrorBanner :error="pnl.error.value" :closable="false" />
    <div v-if="!pnl.data.value && pnl.isLoading.value" class="skel h-[240px]" />
    <SectionCard v-else-if="pnl.data.value" :title="{ ar: 'قائمة الدخل', en: 'Income statement' }" :sub="`${fmtDay(pnl.data.value.from)} → ${fmtDay(pnl.data.value.to)}`">
      <template v-for="sec in SECTIONS" :key="sec.k">
        <div class="mt-2 text-[11px] font-extrabold text-ink">{{ t(sec.ar, sec.en) }}</div>
        <div v-for="a in pnl.data.value.sections?.[sec.k] || []" :key="a.code" class="row !gap-2 border-b border-line-2 py-1 text-[10.5px]">
          <span class="cell-id min-w-[80px]">{{ a.code }}</span><span class="min-w-0 flex-1 truncate" dir="auto">{{ nameOf(a) }}</span><span class="num">{{ amount(a.amount) }}</span>
        </div>
        <div v-if="!(pnl.data.value.sections?.[sec.k] || []).length" class="py-1 text-[10px] text-faint">—</div>
        <div class="row !gap-2 py-1 text-[11px] font-extrabold"><span class="flex-1">{{ t(`مجموع ${sec.ar}`, `Total ${sec.en.toLowerCase()}`) }}</span><span class="num">{{ money(pnl.data.value.totals?.[sec.k]) }}</span></div>
        <div v-if="sec.k === 'cogs'" class="row !gap-2 rounded-[8px] bg-soft px-2 py-1.5 text-[11.5px] font-extrabold text-violet">
          <span class="flex-1">{{ t('مجمل الربح', 'Gross profit') }} <span class="num text-[10px]">({{ pct(pnl.data.value.totals?.grossMarginPct) }})</span></span><span class="num">{{ money(pnl.data.value.totals?.grossProfit) }}</span>
        </div>
      </template>
      <div class="row mt-2 !gap-2 rounded-[8px] px-2 py-2 text-[12.5px] font-extrabold" :class="pnl.data.value.totals?.netIncome < 0 ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'">
        <span class="flex-1">{{ t('صافي الدخل', 'Net income') }}</span><span class="num">{{ money(pnl.data.value.totals?.netIncome) }}</span>
      </div>
    </SectionCard>
  </template>

  <!-- ageing -->
  <template v-else-if="view === 'ageing'">
    <div class="hint purple !mt-0 mb-2">
      <template v-if="ageSide === 'ops'">{{ existing('أعمار ذمم العملاء', 'customer receivables ageing') }}</template>
      <template v-else>{{ bi(ageing.data.value?.odoo?.definition || { ar: 'فواتير أودو المفتوحة.', en: 'Odoo\'s open invoices.' }) }}</template>
    </div>
    <div class="row wrap mb-2">
      <Tabs :model-value="ageSide" :tabs="[{ k: 'ops', label: { ar: 'ذمم العمليات', en: 'OPS receivables' } }, { k: 'odoo', label: { ar: 'مفتوح في أودو', en: 'Open in Odoo' } }]" variant="pill" class="!mb-0" @update:model-value="(k) => nav.patch({ side: k === 'ops' ? null : k })" />
      <TextInput v-model="ageQ" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالعميل…', en: 'Search customer…' }" />
    </div>
    <ErrorBanner :error="ageing.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="ageColumns" :rows="ageRows" :page-size="50" :loading="ageing.isLoading.value" :row-key="(r, i) => r.code || r.partnerRef || i" :empty-text="{ ar: 'لا ذمم مفتوحة.', en: 'Nothing open.' }">
        <template #cell-customer="{ row }">
          <div class="min-w-0">
            <div class="cell-name truncate" dir="auto">{{ row.name || nameOf(row) }}</div>
            <div class="cell-sub">
              <span class="num ltr inline-block">{{ row.code || row.customerCode || '—' }}</span>
              <template v-if="ageSide === 'odoo'"> · Odoo <span class="num ltr inline-block">#{{ row.partnerRef }}</span><template v-if="!row.customerCode"> · {{ t('غير مربوط بعميل', 'not linked to a customer') }}</template></template>
            </div>
          </div>
        </template>
        <template #cell-total="{ row }"><b>{{ amount(row.total) }}</b></template>
        <template #cell-d90p="{ row }"><span :class="row.d90p > 0 ? 'font-bold text-bad' : ''">{{ amount(row.d90p) }}</span></template>
      </DataTable>
    </div>
    <div v-if="ageTotals" class="mt-2 text-[11px]">
      <span class="font-extrabold text-sec">{{ t('المجموع', 'Total') }}:</span>
      <template v-for="(b, i) in BUCKETS" :key="b.key"><template v-if="i"> ·</template> {{ bi(b.header) }} <b class="num">{{ amount(ageTotals[b.key]) }}</b></template>
      <span class="text-muted"> ({{ t('ر.س', 'SAR') }})</span>
    </div>
  </template>

  <!-- invoice counts -->
  <template v-else-if="view === 'invoices'">
    <div class="hint purple !mt-0 mb-2"><template v-if="counts.data.value?.definition">{{ bi(counts.data.value.definition) }}</template> {{ t('أعداد العمليات من فواتير المالية القائمة؛ أودو من نسخته المحلية.', 'OPS counts come from the existing finance invoices; Odoo from its local copy.') }}</div>
    <ErrorBanner :error="counts.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="countColumns" :rows="counts.data.value?.rows || []" :loading="counts.isLoading.value" :row-key="(r) => r.month" :empty-text="{ ar: 'لا فواتير في الفترة.', en: 'No invoice in the period.' }" />
    </div>
    <div v-if="counts.data.value?.totals" class="mt-2 text-[11px]">
      <span class="font-extrabold text-sec">{{ t('المجموع', 'Total') }}:</span>
      {{ t('فواتير العمليات', 'OPS invoices') }} <b class="num">{{ fmtNum(counts.data.value.totals.opsInvoices) }}</b> · {{ t('إشعارات', 'credit notes') }} <b class="num">{{ fmtNum(counts.data.value.totals.opsCreditNotes) }}</b>
      · {{ t('ملغاة', 'cancelled') }} <b class="num">{{ fmtNum(counts.data.value.totals.opsCancelled) }}</b> · {{ t('المبلغ', 'amount') }} <b class="num">{{ money(counts.data.value.totals.opsAmount) }}</b>
      · {{ t('أودو', 'Odoo') }} <b class="num">{{ fmtNum(counts.data.value.totals.odooInvoices) }}</b> / <b class="num">{{ fmtNum(counts.data.value.totals.odooCreditNotes) }}</b> · <b class="num">{{ money(counts.data.value.totals.odooAmount) }}</b>
      · {{ t('مفتوحة الآن', 'open now') }} <b class="num">{{ fmtNum(counts.data.value.totals.opsOpen) }}</b> · {{ t('متأخرة الآن', 'overdue now') }} <b class="num text-bad">{{ fmtNum(counts.data.value.totals.opsOverdue) }}</b>
    </div>
  </template>
</template>
