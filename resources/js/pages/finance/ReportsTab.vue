<script setup>
// Financial reports: statement picker + date range → a table built from the report's rows, with drill-down links
// (journal numbers, source documents). All figures come from posted journal lines on the server.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SectionCard, SelectInput, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { qs } from '@/pages/procurement/shared';
import { monthStartIso, sourceLink, todayIso, useAccountOptions, useCostCenterOptions, useCustomerOptions, useSupplierOptions } from './shared';

const route = useRoute();
const router = useRouter();
const REPORTS = [
  ['trial-balance', { ar: 'ميزان المراجعة', en: 'Trial balance' }], ['income-statement', { ar: 'قائمة الدخل', en: 'Income statement' }], ['balance-sheet', { ar: 'قائمة المركز المالي', en: 'Balance sheet' }], ['cash-flow', { ar: 'التدفقات النقدية', en: 'Cash flow' }],
  ['ledger', { ar: 'كشف حساب (أستاذ)', en: 'Account ledger' }], ['statement-customer', { ar: 'كشف حساب عميل', en: 'Customer statement' }], ['statement-supplier', { ar: 'كشف حساب مورد', en: 'Supplier statement' }],
  ['aging-customer', { ar: 'أعمار ذمم العملاء', en: 'AR aging' }], ['aging-supplier', { ar: 'أعمار ذمم الموردين', en: 'AP aging' }], ['vat', { ar: 'ضريبة القيمة المضافة', en: 'VAT' }],
  ['inventory-valuation', { ar: 'تقييم المخزون مقابل الأستاذ', en: 'Inventory valuation vs GL' }], ['profitability', { ar: 'الربحية (أصناف / عملاء)', en: 'Profitability' }], ['fleet-costs', { ar: 'تكاليف الأسطول والرحلات', en: 'Fleet & trip costs' }], ['budget', { ar: 'الموازنة مقابل الفعلي', en: 'Budget vs actual' }],
];
const report = ref(qs(route.query.report) || 'trial-balance');
const from = ref(monthStartIso());
const to = ref(todayIso());
const asOf = ref(todayIso());
const account = ref(qs(route.query.account) || '1130');
const partner = ref('');
const by = ref('product');
const period = ref(new Date().toISOString().slice(0, 7));
const costCenter = ref('');
watch(report, (r) => router.replace({ query: { ...route.query, report: r } }));
const { options: accountOpts } = useAccountOptions();
const ccOpts = useCostCenterOptions();
const { options: customerOpts } = useCustomerOptions();
const { options: supplierOpts } = useSupplierOptions();

const path = computed(() => {
  const r = report.value;
  const range = `from=${from.value}&to=${to.value}`;
  if (r === 'ledger') return account.value ? `/finance/reports/ledger/${encodeURIComponent(account.value)}?${range}${costCenter.value ? '&costCenter=' + costCenter.value : ''}` : null;
  if (r === 'statement-customer') return partner.value ? `/finance/reports/statement/customer/${encodeURIComponent(partner.value)}?${range}` : null;
  if (r === 'statement-supplier') return partner.value ? `/finance/reports/statement/supplier/${encodeURIComponent(partner.value)}?${range}` : null;
  if (r === 'aging-customer') return '/finance/reports/aging/customer';
  if (r === 'aging-supplier') return '/finance/reports/aging/supplier';
  if (r === 'balance-sheet') return `/finance/reports/balance-sheet?asOf=${asOf.value}`;
  if (r === 'inventory-valuation') return '/finance/reports/inventory-valuation';
  if (r === 'profitability') return `/finance/reports/profitability?by=${by.value}&${range}`;
  if (r === 'budget') return `/finance/reports/budget?period=${period.value}`;
  if (r === 'income-statement') return `/finance/reports/income-statement?${range}${costCenter.value ? '&costCenter=' + costCenter.value : ''}`;
  return `/finance/reports/${r}?${range}`;
});
const q = useGet(path);
const d = computed(() => q.data.value);
const sar = t('ر.س', 'SAR');
const name = (r) => (lang.value === 'ar' ? r.nameAr : r.nameEn);
const openJournal = (n) => n && router.push(`/jv/${encodeURIComponent(n)}`);
const goSource = (type, number) => { const l = sourceLink(type, number); if (l) router.push(l); };

const TB_COLS = [
  { key: 'code', header: { ar: 'الكود', en: 'Code' }, width: '70px', kind: 'id' }, { key: 'name', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(180px,1.6fr)', kind: 'name', value: name },
  { key: 'openingDebit', header: { ar: 'افتتاحي مدين', en: 'Open Dr' }, width: '95px', kind: 'num', value: (r) => (r.openingDebit ? fmtMoney(r.openingDebit) : '') }, { key: 'openingCredit', header: { ar: 'افتتاحي دائن', en: 'Open Cr' }, width: '95px', kind: 'num', value: (r) => (r.openingCredit ? fmtMoney(r.openingCredit) : '') },
  { key: 'debit', header: { ar: 'مدين', en: 'Debit' }, width: '95px', kind: 'num', value: (r) => (r.debit ? fmtMoney(r.debit) : '') }, { key: 'credit', header: { ar: 'دائن', en: 'Credit' }, width: '95px', kind: 'num', value: (r) => (r.credit ? fmtMoney(r.credit) : '') },
  { key: 'closingDebit', header: { ar: 'ختامي مدين', en: 'Close Dr' }, width: '95px', kind: 'num', value: (r) => (r.closingDebit ? fmtMoney(r.closingDebit) : '') }, { key: 'closingCredit', header: { ar: 'ختامي دائن', en: 'Close Cr' }, width: '95px', kind: 'num', value: (r) => (r.closingCredit ? fmtMoney(r.closingCredit) : '') },
];
const LEDGER_COLS = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.date) }, { key: 'journal', header: { ar: 'القيد', en: 'Journal' }, width: '120px' },
  { key: 'description', header: { ar: 'البيان', en: 'Description' }, width: 'minmax(200px,2fr)', kind: 'name', value: (r) => r.description || r.memo }, { key: 'source', header: { ar: 'المستند', en: 'Source' }, width: '150px' },
  { key: 'debit', header: { ar: 'مدين', en: 'Debit' }, width: '95px', kind: 'num', value: (r) => (r.debit ? fmtMoney(r.debit) : '') }, { key: 'credit', header: { ar: 'دائن', en: 'Credit' }, width: '95px', kind: 'num', value: (r) => (r.credit ? fmtMoney(r.credit) : '') },
  { key: 'balance', header: { ar: 'الرصيد', en: 'Balance' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.balance) },
];
const AGING_COLS = [
  { key: 'name', header: { ar: 'الطرف', en: 'Partner' }, width: 'minmax(180px,1.6fr)', kind: 'name', value: name }, { key: 'invoices', header: { ar: 'فواتير', en: 'Invoices' }, width: '70px', kind: 'num' },
  { key: 'current', header: { ar: 'غير مستحقة', en: 'Current' }, width: '95px', kind: 'num', value: (r) => fmtMoney(r.current) }, { key: 'd30', header: '1–30', width: '90px', kind: 'num', value: (r) => fmtMoney(r.d30) }, { key: 'd60', header: '31–60', width: '90px', kind: 'num', value: (r) => fmtMoney(r.d60) },
  { key: 'd90', header: '61–90', width: '90px', kind: 'num', value: (r) => fmtMoney(r.d90) }, { key: 'd90p', header: '90+', width: '90px', kind: 'num', value: (r) => fmtMoney(r.d90p) }, { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.total) },
];
const PROFIT_COLS = [
  { key: 'name', header: { ar: 'الصنف / العميل', en: 'Item / customer' }, width: 'minmax(180px,1.6fr)', kind: 'name', value: name }, { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num' },
  { key: 'revenue', header: { ar: 'الإيراد', en: 'Revenue' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.revenue) }, { key: 'cogs', header: { ar: 'التكلفة', en: 'COGS' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.cogs) },
  { key: 'grossProfit', header: { ar: 'مجمل الربح', en: 'Gross profit' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.grossProfit) }, { key: 'marginPct', header: '%', width: '60px', kind: 'num', value: (r) => (r.marginPct != null ? fmtNum(r.marginPct, 1) : '—') },
];
const FLEET_COLS = [
  { key: 'code', header: { ar: 'المركبة', en: 'Vehicle' }, width: '100px', kind: 'id' }, { key: 'plateAr', header: { ar: 'اللوحة', en: 'Plate' }, width: '110px' },
  { key: 'fuel', header: { ar: 'وقود', en: 'Fuel' }, width: '90px', kind: 'num', value: (r) => fmtMoney(r.fuel) }, { key: 'maintenance', header: { ar: 'صيانة', en: 'Maintenance' }, width: '90px', kind: 'num', value: (r) => fmtMoney(r.maintenance) },
  { key: 'depreciation', header: { ar: 'إهلاك', en: 'Depreciation' }, width: '90px', kind: 'num', value: (r) => fmtMoney(r.depreciation) }, { key: 'other', header: { ar: 'أخرى', en: 'Other' }, width: '90px', kind: 'num', value: (r) => fmtMoney(r.other) }, { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.total) },
];
const BUDGET_COLS = [
  { key: 'code', header: { ar: 'الكود', en: 'Code' }, width: '70px', kind: 'id' }, { key: 'name', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(180px,1.6fr)', kind: 'name', value: name },
  { key: 'budget', header: { ar: 'الموازنة', en: 'Budget' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.budget) }, { key: 'actual', header: { ar: 'الفعلي', en: 'Actual' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.actual) },
  { key: 'variance', header: { ar: 'الانحراف', en: 'Variance' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.variance) }, { key: 'usedPct', header: '%', width: '60px', kind: 'num', value: (r) => (r.usedPct != null ? fmtNum(r.usedPct, 1) : '—') },
];
const VAL_COLS = [
  { key: 'code', header: { ar: 'المستودع', en: 'Warehouse' }, width: '90px', kind: 'id' }, { key: 'name', header: '', width: 'minmax(160px,1.4fr)', kind: 'name', value: name },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '90px', kind: 'num', value: (r) => fmtNum(r.qty) }, { key: 'unvaluedQty', header: { ar: 'غير مقيَّم', en: 'Unvalued' }, width: '90px', kind: 'num', class: 'muted', value: (r) => fmtNum(r.unvaluedQty) }, { key: 'value', header: { ar: 'القيمة', en: 'Value' }, width: '110px', kind: 'num', value: (r) => fmtMoney(r.value) },
];
const SECTION_LABELS = { revenue: { ar: 'الإيرادات', en: 'Revenue' }, cogs: { ar: 'تكلفة المبيعات', en: 'Cost of sales' }, opex: { ar: 'المصروفات التشغيلية', en: 'Operating expenses' }, assets: { ar: 'الأصول', en: 'Assets' }, liabilities: { ar: 'الالتزامات', en: 'Liabilities' }, equity: { ar: 'حقوق الملكية', en: 'Equity' } };
const needsRange = computed(() => ['trial-balance', 'income-statement', 'cash-flow', 'ledger', 'statement-customer', 'statement-supplier', 'vat', 'profitability', 'fleet-costs'].includes(report.value));
</script>

<template>
  <div class="card mb-3">
    <div class="row wrap p-3 !gap-2">
      <SelectInput v-model="report" small class="w-[260px]" :label="{ ar: 'التقرير', en: 'Report' }" :options="REPORTS" />
      <template v-if="needsRange"><DateInput v-model="from" small class="w-[140px]" :label="{ ar: 'من', en: 'From' }" /><DateInput v-model="to" small class="w-[140px]" :label="{ ar: 'إلى', en: 'To' }" /></template>
      <DateInput v-if="report === 'balance-sheet'" v-model="asOf" small class="w-[140px]" :label="{ ar: 'كما في', en: 'As of' }" />
      <SelectInput v-if="report === 'ledger'" v-model="account" small class="w-[260px]" :label="{ ar: 'الحساب', en: 'Account' }" :options="accountOpts" />
      <SelectInput v-if="report === 'ledger' || report === 'income-statement'" v-model="costCenter" small class="w-[200px]" :label="{ ar: 'مركز التكلفة', en: 'Cost center' }" :options="ccOpts" :placeholder="{ ar: 'الكل', en: 'All' }" />
      <SelectInput v-if="report === 'statement-customer'" v-model="partner" small class="w-[260px]" :label="{ ar: 'العميل', en: 'Customer' }" :options="customerOpts" :placeholder="{ ar: '— اختر —', en: '— select —' }" />
      <SelectInput v-if="report === 'statement-supplier'" v-model="partner" small class="w-[260px]" :label="{ ar: 'المورد', en: 'Supplier' }" :options="supplierOpts" :placeholder="{ ar: '— اختر —', en: '— select —' }" />
      <SelectInput v-if="report === 'profitability'" v-model="by" small class="w-[160px]" :label="{ ar: 'حسب', en: 'By' }" :options="[['product', { ar: 'الأصناف', en: 'Products' }], ['customer', { ar: 'العملاء', en: 'Customers' }]]" />
      <TextInput v-if="report === 'budget'" v-model="period" small class="w-[140px]" :label="{ ar: 'الفترة (YYYY أو YYYY-MM)', en: 'Period' }" dir="ltr" />
      <span class="grow" />
      <Btn size="sm" tone="soft" :label="{ ar: 'تحديث', en: 'Refresh' }" @click="q.refetch()" />
    </div>
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value" class="skel min-h-[160px]" />

  <template v-if="d">
    <!-- trial balance -->
    <SectionCard v-if="report === 'trial-balance'" :title="{ ar: 'ميزان المراجعة', en: 'Trial balance' }" :sub="`${fmtDateOnly(d.from)} → ${fmtDateOnly(d.to)}`" :padded="false">
      <template #actions><Chip :fg="d.balanced ? '#1d7a3e' : '#b23b3b'" :bg="d.balanced ? '#e6f9ec' : '#fdecec'" :label="d.balanced ? { ar: 'متوازن ✓', en: 'Balanced ✓' } : { ar: 'غير متوازن!', en: 'Unbalanced!' }" /></template>
      <DataTable :columns="TB_COLS" :rows="d.rows" :row-key="(r) => r.code" :min-width="900" @row-click="(r) => { report = 'ledger'; account = r.code; }">
        <template #footer><span class="num font-extrabold">{{ t('الإجمالي', 'Totals') }}: {{ fmtMoney(d.totals.debit) }} / {{ fmtMoney(d.totals.credit) }} · {{ t('ختامي', 'closing') }} {{ fmtMoney(d.totals.closingDebit) }} / {{ fmtMoney(d.totals.closingCredit) }}</span></template>
      </DataTable>
    </SectionCard>

    <!-- income statement / balance sheet -->
    <template v-else-if="report === 'income-statement' || report === 'balance-sheet'">
      <div class="grid-2">
        <SectionCard v-for="(rows, sec) in d.sections" :key="sec" :title="SECTION_LABELS[sec]" :count="rows.length">
          <div v-for="r in rows" :key="r.code" class="row cursor-pointer border-t border-[#F7F6FA] py-1.5 text-[10.5px] first:border-t-0" @click="r.code !== '—' && (report = 'ledger', account = r.code)"><span class="num text-faint">{{ r.code }}</span><span class="grow">{{ name(r) }}</span><span class="num font-extrabold">{{ fmtMoney(r.amount) }}</span></div>
          <div class="row mt-2 border-t border-line-2 pt-2 text-[11px] font-extrabold"><span class="grow">{{ t('الإجمالي', 'Total') }}</span><span class="num">{{ fmtMoney(d.totals[sec]) }}</span></div>
        </SectionCard>
      </div>
      <div class="kpi-grid compact mt-3">
        <template v-if="report === 'income-statement'">
          <div class="tile green"><div class="tile-v">{{ fmtMoney(d.totals.grossProfit) }}</div><div class="tile-l">{{ t('مجمل الربح', 'Gross profit') }} {{ d.totals.grossMarginPct != null ? `· ${fmtNum(d.totals.grossMarginPct, 1)}%` : '' }}</div></div>
          <div class="tile" :class="d.totals.netIncome >= 0 ? 'purple' : 'red'"><div class="tile-v">{{ fmtMoney(d.totals.netIncome) }}</div><div class="tile-l">{{ t('صافي الدخل', 'Net income') }}</div></div>
        </template>
        <template v-else>
          <div class="tile purple"><div class="tile-v">{{ fmtMoney(d.totals.assets) }}</div><div class="tile-l">{{ t('إجمالي الأصول', 'Total assets') }}</div></div>
          <div class="tile"><div class="tile-v">{{ fmtMoney(d.totals.liabilitiesAndEquity) }}</div><div class="tile-l">{{ t('الالتزامات + حقوق الملكية', 'Liabilities + equity') }}</div></div>
          <div class="tile" :class="d.balanced ? 'green' : 'red'"><div class="tile-v">{{ d.balanced ? '✓' : '≠' }}</div><div class="tile-l">{{ d.balanced ? t('متوازنة', 'Balanced') : t('غير متوازنة', 'Unbalanced') }}</div></div>
        </template>
      </div>
    </template>

    <!-- cash flow -->
    <SectionCard v-else-if="report === 'cash-flow'" :title="{ ar: 'التدفقات النقدية (الطريقة المباشرة)', en: 'Cash flow (direct)' }" :sub="`${t('افتتاحي', 'opening')} ${fmtMoney(d.opening)} · ${t('ختامي', 'closing')} ${fmtMoney(d.closing)} ${sar}`">
      <div v-for="r in d.rows" :key="r.source || 'manual'" class="row border-t border-[#F7F6FA] py-1.5 text-[10.5px] first:border-t-0"><span class="grow">{{ t(r.label.ar, r.label.en) }}</span><span class="num text-ok">+{{ fmtMoney(r.inflow) }}</span><span class="num text-bad">−{{ fmtMoney(r.outflow) }}</span><span class="num w-[110px] text-end font-extrabold">{{ fmtMoney(r.net) }}</span></div>
      <div class="row mt-2 border-t border-line-2 pt-2 text-[11px] font-extrabold"><span class="grow">{{ t('صافي التغير في النقدية', 'Net change in cash') }}</span><span class="num">{{ fmtMoney(d.net) }}</span></div>
    </SectionCard>

    <!-- ledger / statements -->
    <SectionCard v-else-if="report === 'ledger' || report.startsWith('statement')" :title="report === 'ledger' ? `${d.account.code} · ${name(d.account)}` : `${name(d.partner)} (${d.partner.code})`" :sub="`${t('افتتاحي', 'opening')} ${fmtMoney(d.opening)} → ${t('ختامي', 'closing')} ${fmtMoney(d.closing)} ${sar}`" :padded="false">
      <DataTable :columns="LEDGER_COLS" :rows="d.rows" :row-key="(r, i) => `${r.journal}-${i}`" :min-width="900" :empty-text="{ ar: 'لا حركة في الفترة.', en: 'No movement in the range.' }">
        <template #cell-journal="{ row }"><span class="cell-id cursor-pointer" @click.stop="openJournal(row.journal)">{{ row.journal }}</span></template>
        <template #cell-source="{ row }"><span v-if="row.sourceNumber" class="num cursor-pointer text-[9.5px] text-violet" @click.stop="goSource(row.sourceType, row.sourceNumber)">{{ row.sourceType }} · {{ row.sourceNumber }}</span></template>
      </DataTable>
    </SectionCard>

    <!-- aging -->
    <SectionCard v-else-if="report.startsWith('aging')" :title="report === 'aging-customer' ? { ar: 'أعمار ذمم العملاء', en: 'Receivables aging' } : { ar: 'أعمار ذمم الموردين', en: 'Payables aging' }" :sub="`${t('كما في', 'as of')} ${fmtDateOnly(d.asOf)}`" :padded="false">
      <DataTable :columns="AGING_COLS" :rows="d.rows" :row-key="(r) => r.code" :min-width="900" :empty-text="{ ar: 'لا ذمم مفتوحة.', en: 'Nothing open.' }" @row-click="(r) => { report = report === 'aging-customer' ? 'statement-customer' : 'statement-supplier'; partner = r.code; }">
        <template #footer><span class="num font-extrabold">{{ t('الإجمالي', 'Total') }} {{ fmtMoney(d.totals.total) }} · 90+: {{ fmtMoney(d.totals.d90p) }}</span></template>
      </DataTable>
    </SectionCard>

    <!-- VAT -->
    <SectionCard v-else-if="report === 'vat'" :title="{ ar: 'ضريبة القيمة المضافة', en: 'VAT position' }" :sub="`${fmtDateOnly(d.from)} → ${fmtDateOnly(d.to)}`">
      <div class="kpi-grid compact">
        <div class="tile"><div class="tile-v">{{ fmtMoney(d.outputVat) }}</div><div class="tile-l">{{ t('ضريبة مخرجات (مبيعات)', 'Output VAT') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(d.inputVat) }}</div><div class="tile-l">{{ t('ضريبة مدخلات (مشتريات ومصروفات)', 'Input VAT') }}</div></div>
        <div class="tile" :class="d.netPayable >= 0 ? 'amber' : 'green'"><div class="tile-v">{{ fmtMoney(d.netPayable) }}</div><div class="tile-l">{{ d.netPayable >= 0 ? t('صافي مستحق للهيئة', 'Net payable') : t('رصيد قابل للاسترداد', 'Refundable') }}</div></div>
      </div>
      <div class="banner neutral mt-3">{{ d.note }}</div>
    </SectionCard>

    <!-- inventory valuation -->
    <SectionCard v-else-if="report === 'inventory-valuation'" :title="{ ar: 'تقييم المخزون (متوسط مرجّح) مقابل حساب المخزون', en: 'Inventory valuation (weighted average) vs GL' }" :padded="false">
      <template #actions><Chip :fg="Math.abs(d.difference) < 0.01 ? '#1d7a3e' : '#b23b3b'" :bg="Math.abs(d.difference) < 0.01 ? '#e6f9ec' : '#fdecec'" :label="{ ar: `الدفتر المساعد ${fmtMoney(d.subLedger)} · الأستاذ ${fmtMoney(d.glBalance)} · فرق ${fmtMoney(d.difference)}`, en: `Sub-ledger ${fmtMoney(d.subLedger)} · GL ${fmtMoney(d.glBalance)} · Δ ${fmtMoney(d.difference)}` }" /></template>
      <DataTable :columns="VAL_COLS" :rows="d.rows" :row-key="(r) => r.warehouseId" :min-width="600" />
      <div v-if="d.note" class="banner amber m-3">{{ d.note }}</div>
    </SectionCard>

    <!-- profitability -->
    <SectionCard v-else-if="report === 'profitability'" :title="{ ar: 'الربحية من فواتير العملاء', en: 'Profitability from customer invoices' }" :sub="`${fmtDateOnly(d.from)} → ${fmtDateOnly(d.to)}`" :padded="false">
      <DataTable :columns="PROFIT_COLS" :rows="d.rows" :row-key="(r) => r.code" :min-width="760" :empty-text="{ ar: 'لا مبيعات مفوترة في الفترة.', en: 'No invoiced sales in the range.' }">
        <template #footer><span class="num font-extrabold">{{ t('الإيراد', 'Revenue') }} {{ fmtMoney(d.totals.revenue) }} · {{ t('التكلفة', 'COGS') }} {{ fmtMoney(d.totals.cogs) }} · {{ t('مجمل الربح', 'Gross profit') }} {{ fmtMoney(d.totals.grossProfit) }}</span></template>
      </DataTable>
    </SectionCard>

    <!-- fleet -->
    <template v-else-if="report === 'fleet-costs'">
      <SectionCard :title="{ ar: 'تكلفة المركبات', en: 'Cost per vehicle' }" :sub="`${fmtDateOnly(d.from)} → ${fmtDateOnly(d.to)} · ${t('الإجمالي', 'total')} ${fmtMoney(d.total)} ${sar}`" :padded="false" class="mb-3">
        <DataTable :columns="FLEET_COLS" :rows="d.vehicles" :row-key="(r) => r.code" :min-width="760" :empty-text="{ ar: 'لا تكاليف مرحَّلة على مركبات في الفترة.', en: 'No vehicle costs in the range.' }" />
      </SectionCard>
      <SectionCard :title="{ ar: 'تكلفة الرحلات (ما رُبط بالرحلة فعلًا)', en: 'Cost per trip (facts linked to the trip)' }">
        <div v-if="!d.trips.length" class="empty">{{ t('لا مصروفات مربوطة برحلات — اربط الوقود وسندات المصروف برقم الرحلة', 'No trip-linked costs — link fuel and expense vouchers to the trip number') }}</div>
        <div v-for="r in d.trips" :key="r.trip" class="row border-t border-[#F7F6FA] py-1.5 text-[10.5px] first:border-t-0"><span class="num cursor-pointer text-violet" @click="router.push(`/trip/${encodeURIComponent(r.trip)}`)">{{ r.trip }}</span><span class="grow" /><span class="num text-muted">{{ r.km ? `${fmtNum(r.km)} ${t('كم', 'km')}` : '' }}</span><span class="num font-extrabold">{{ fmtMoney(r.cost) }}</span><span class="num w-[80px] text-end text-faint">{{ r.costPerKm != null ? `${fmtMoney(r.costPerKm)}/${t('كم', 'km')}` : '' }}</span></div>
      </SectionCard>
    </template>

    <!-- budget -->
    <SectionCard v-else-if="report === 'budget'" :title="{ ar: 'الموازنة مقابل الفعلي', en: 'Budget vs actual' }" :sub="`${fmtDateOnly(d.from)} → ${fmtDateOnly(d.to)}`" :padded="false">
      <DataTable :columns="BUDGET_COLS" :rows="d.rows" :row-key="(r) => r.code" :min-width="700" :empty-text="{ ar: 'لا موازنة ولا فعلي في الفترة — تُدخل الموازنة عبر PUT /finance/reports/budget', en: 'No budget or actuals in the period — budgets are entered through PUT /finance/reports/budget' }" />
    </SectionCard>
  </template>
</template>
