<script setup>
// Payslips: GET /payroll/payslips?scope=mine|all&run&employee&department&period&year&state&source&q&sort(period|number|net|gross|state)&order&page
// → rows { id, number, run, employee, department, period, state, gross, deductions, reimbursements, net, warnings, … }
// + totals { gross, net }. «mine» shows only the person's own, once their run is approved; «all» is the payroll people's.
// A row opens the payslip (`slip=<number>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { FROM_ODOO, RUN_STATE, fmtPeriod, isPeriod, money, optionsOf, pname, usePayrollLookups, usePayrollNav, useSearchParam, warningText, yearOptions } from './payroll';

const props = defineProps({
  scope: { type: String, default: 'mine' },
});

const nav = usePayrollNav();
const all = computed(() => props.scope === 'all');
const { employeeOptions, departmentOptions } = usePayrollLookups(() => all.value);
const { text, type, apply } = useSearchParam(nav);
const STATES = Object.keys(RUN_STATE);
const state = computed(() => (STATES.includes(nav.get('state')) ? nav.get('state') : ''));
const sort = computed(() => nav.sortOf(['period', 'number', 'net', 'gross', 'state'], { key: 'period', order: 'desc' }));
const list = useList('/payroll/payslips', () => ({
  scope: props.scope, year: /^\d{4}$/.test(nav.get('year')) ? nav.get('year') : undefined, period: isPeriod(nav.get('period')) ? nav.get('period') : undefined, q: nav.get('q') || undefined,
  ...(all.value ? { state: state.value || undefined, employee: nav.get('employee') || undefined, department: nav.get('department') || undefined, source: nav.get('source') || undefined } : {}),
  sort: sort.value.key, order: sort.value.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['q', 'state', 'year', 'period', 'employee', 'department', 'source'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, state: null, year: null, period: null, employee: null, department: null, source: null });
const columns = computed(() => [
  { key: 'number', header: { ar: 'القسيمة', en: 'Payslip' }, width: '124px', kind: 'id', sortable: true },
  ...(all.value ? [{ key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(150px,1.5fr)' }] : []),
  { key: 'period', header: { ar: 'الشهر', en: 'Month' }, width: '82px', kind: 'num', sortable: true, value: (r) => fmtPeriod(r.period) },
  { key: 'gross', header: { ar: 'الإجمالي', en: 'Gross' }, width: '100px', kind: 'num', sortable: true, value: (r) => money(r.gross) },
  { key: 'deductions', header: { ar: 'الاستقطاعات', en: 'Deductions' }, width: '96px', kind: 'num', value: (r) => money(r.deductions) },
  { key: 'net', header: { ar: 'الصافي', en: 'Net' }, width: '104px', kind: 'num', sortable: true, value: (r) => money(r.net) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', sortable: true, bare: true },
]);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[190px]" :model-value="text" :placeholder="all ? { ar: 'بحث بالرقم أو الموظف أو الشهر…', en: 'Search number, employee or month…' } : { ar: 'بحث بالرقم أو الشهر…', en: 'Search number or month…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[100px]" :model-value="nav.get('year')" :options="yearOptions()" :placeholder="{ ar: 'كل السنوات', en: 'Every year' }" @update:model-value="(v) => nav.setFilter('year', v)" />
    <TextInput small type="month" class="w-[150px]" :model-value="nav.get('period')" :aria-label="t('الشهر', 'Month')" @update:model-value="(v) => nav.setFilter('period', v)" />
    <template v-if="all">
      <SelectInput small class="w-[150px]" :model-value="state" :options="optionsOf(RUN_STATE)" :placeholder="{ ar: 'كل الحالات', en: 'Every state' }" @update:model-value="(v) => nav.setFilter('state', v)" />
      <SelectInput small class="w-[180px]" :model-value="nav.get('employee')" :options="employeeOptions" :placeholder="{ ar: 'كل الموظفين', en: 'Every employee' }" @update:model-value="(v) => nav.setFilter('employee', v)" />
      <SelectInput small class="w-[150px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
      <SelectInput small class="w-[130px]" :model-value="nav.get('source')" :options="[['odoo', FROM_ODOO], ['ops', { ar: 'حُسبت هنا', en: 'Made here' }]]" :placeholder="{ ar: 'كل المصادر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    </template>
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('slip') || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا قسائم مطابقة.', en: 'No matching payslip.' } : all ? { ar: 'لا قسائم بعد.', en: 'No payslip yet.' } : { ar: 'لا قسائم لك بعد — تظهر قسيمتك هنا بعد اعتماد مسير شهرها.', en: 'No payslip yet — yours shows here once the run of its month is approved.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.open('slip', r.number)">
      <template #cell-employee="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }}<template v-if="row.run?.number"> · {{ row.run.number }}</template></div></div></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="RUN_STATE" :k="row.state" />
          <span v-if="row.warnings?.length" class="text-[9px] font-bold text-warn" :title="row.warnings.map(warningText).join(' · ')">⚠ {{ row.warnings.length }}</span>
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template v-if="list.data.value?.totals" #footer>
        <span class="me-3">{{ t('مجموع المطابق', 'Totals of the matches') }}: {{ t('الإجمالي', 'gross') }} <b class="num">{{ money(list.data.value.totals.gross) }}</b> · {{ t('الصافي', 'net') }} <b class="num">{{ money(list.data.value.totals.net) }}</b></span>
      </template>
    </DataTable>
  </div>
</template>
