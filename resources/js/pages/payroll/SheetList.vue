<script setup>
// Attendance sheets: GET /payroll/sheets?scope=all&state&employee&department&period&batch&source&q&sort(from|number|late|overtime|absence|state)&order&page
// → rows { id, number, employee, department, batch, dateFrom, dateTo, period, state, totals {…}, payslip, source, can }
// + totals { lateMinutes, earlyMinutes, overtimeMinutes, absenceDays }. A row opens the sheet (`sheet=<number>`).
// «+ كشف» (attendance.manage): POST /payroll/sheets { employee, dateFrom, dateTo } — one to 62 days.
import { computed, ref } from 'vue';
import { api, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { FROM_ODOO, SHEET_STATE, fmtDay, fmtPeriod, hm, isPeriod, optionsOf, pname, todayIso, usePayrollLookups, usePayrollNav, usePayrollPerms, useSearchParam } from './payroll';

const nav = usePayrollNav();
const perms = usePayrollPerms();
const { employeeOptions, departmentOptions } = usePayrollLookups();
const { text, type, apply } = useSearchParam(nav);

const STATES = Object.keys(SHEET_STATE);
const state = computed(() => (nav.get('state') && nav.get('state').split(',').every((s) => STATES.includes(s)) ? nav.get('state') : ''));
const sort = computed(() => nav.sortOf(['from', 'number', 'late', 'overtime', 'absence', 'state'], { key: 'from', order: 'desc' }));
const list = useList('/payroll/sheets', () => ({
  scope: 'all', state: state.value || undefined, employee: nav.get('employee') || undefined, department: nav.get('department') || undefined,
  period: isPeriod(nav.get('period')) ? nav.get('period') : undefined, source: nav.get('source') || undefined, q: nav.get('q') || undefined,
  sort: sort.value.key, order: sort.value.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['q', 'state', 'employee', 'department', 'period', 'source'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, state: null, employee: null, department: null, period: null, source: null });
const columns = [
  { key: 'number', header: { ar: 'الكشف', en: 'Sheet' }, width: '116px', kind: 'id', sortable: true },
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(150px,1.5fr)' },
  { key: 'from', header: { ar: 'المدة', en: 'Dates' }, width: '170px', sortable: true },
  { key: 'late', header: { ar: 'تأخير', en: 'Late' }, width: '62px', kind: 'num', sortable: true, value: (r) => hm(r.totals?.lateMinutes) },
  { key: 'early', header: { ar: 'مبكر', en: 'Early' }, width: '62px', kind: 'num', value: (r) => hm(r.totals?.earlyMinutes) },
  { key: 'overtime', header: { ar: 'إضافي', en: 'Overtime' }, width: '66px', kind: 'num', sortable: true, value: (r) => hm(r.totals?.overtimeMinutes) },
  { key: 'absence', header: { ar: 'غياب (يوم)', en: 'Absent (d)' }, width: '72px', kind: 'num', sortable: true, value: (r) => r.totals?.absenceCount || '—' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', sortable: true, bare: true },
];

const creating = ref(false);
const fields = computed(() => [
  { k: 'employee', label: { ar: 'الموظف', en: 'Employee' }, type: 'select', required: true, full: true, opts: employeeOptions.value },
  { k: 'dateFrom', label: { ar: 'من', en: 'From' }, type: 'date', required: true },
  { k: 'dateTo', label: { ar: 'إلى', en: 'To' }, type: 'date', required: true, validate: (v, all) => (all.dateFrom && v < all.dateFrom ? t('النهاية لا تسبق البداية', 'The end cannot be before the start') : null) },
]);
const firstOfMonth = () => `${todayIso().slice(0, 8)}01`;
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[190px]" :model-value="text" :placeholder="{ ar: 'بحث بالرقم أو الموظف…', en: 'Search number or employee…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[150px]" :model-value="state" :options="[['draft,confirmed', { ar: 'لم تُعتمد', en: 'Not approved' }], ...optionsOf(SHEET_STATE)]" :placeholder="{ ar: 'كل الحالات', en: 'Every state' }" @update:model-value="(v) => nav.setFilter('state', v)" />
    <SelectInput small class="w-[180px]" :model-value="nav.get('employee')" :options="employeeOptions" :placeholder="{ ar: 'كل الموظفين', en: 'Every employee' }" @update:model-value="(v) => nav.setFilter('employee', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <TextInput small type="month" class="w-[150px]" :model-value="nav.get('period')" :aria-label="t('الشهر', 'Month')" @update:model-value="(v) => nav.setFilter('period', v)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('source')" :options="[['odoo', FROM_ODOO], ['ops', { ar: 'أُنشئت هنا', en: 'Made here' }]]" :placeholder="{ ar: 'كل المصادر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
    <span class="grow" />
    <Btn v-if="perms.attendanceManage" size="sm" tone="primary" :label="{ ar: '+ كشف حضور', en: '+ Sheet' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('sheet') || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا كشوف مطابقة.', en: 'No matching sheet.' } : { ar: 'لا كشوف حضور بعد — أنشئ كشفًا لموظف أو دفعة لقسم.', en: 'No sheet yet — make one for an employee, or a batch for a department.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.open('sheet', r.number)">
      <template #cell-employee="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }}<template v-if="row.batch"> · {{ row.batch.number }}</template></div></div></template>
      <template #cell-from="{ row }"><span class="cell-date">{{ fmtDay(row.dateFrom) }} – {{ fmtDay(row.dateTo) }}<span class="text-faint"> · {{ fmtPeriod(row.period) }}</span></span></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="SHEET_STATE" :k="row.state" />
          <span v-if="row.payslip" class="cell-sub !mt-0" :title="t('دخلت أرقامه هذه القسيمة', 'Its figures went into this payslip')">{{ row.payslip.number }}</span>
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template v-if="list.data.value?.totals" #footer>
        <span class="me-3">{{ t('مجموع المطابق', 'Totals of the matches') }}: {{ t('تأخير', 'late') }} <b class="num">{{ hm(list.data.value.totals.lateMinutes, false) }}</b> · {{ t('مبكر', 'early') }} <b class="num">{{ hm(list.data.value.totals.earlyMinutes, false) }}</b> · {{ t('إضافي', 'overtime') }} <b class="num">{{ hm(list.data.value.totals.overtimeMinutes, false) }}</b> · {{ t('غياب', 'absence') }} <b class="num">{{ list.data.value.totals.absenceDays }}</b> {{ t('يوم', 'd') }}</span>
      </template>
    </DataTable>
  </div>

  <FormDrawer :open="creating" :title="{ ar: 'كشف حضور لموظف', en: 'Attendance sheet for an employee' }" :sub="{ ar: 'من سجلات الحضور وجدول الدوام، عبر سياسة الحضور — يوم إلى 62 يومًا', en: 'From the attendance records and the schedule, through the attendance policy — one to 62 days' }"
              :fields="fields" :initial="{ dateFrom: firstOfMonth(), dateTo: todayIso() }" :submit="(v) => api.postIdempotent('/payroll/sheets', v)" :submit-label="{ ar: 'إنشاء الكشف', en: 'Make the sheet' }"
              :action="{ success: (s) => t(`أُنشئ الكشف ${s.number}`, `Sheet ${s.number} made`), invalidate: ['payroll'] }" @close="creating = false" @done="(s) => nav.open('sheet', s.number)" />
</template>
