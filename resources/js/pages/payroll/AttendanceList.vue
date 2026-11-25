<script setup>
// Attendance records: GET /payroll/attendance?scope=mine|all&employee&department&from&to&status&source&q&sort(date|worked)&order&page
// → rows { id, employee, workDate, checkIn, checkOut, workedMinutes, inMode, outMode, status, open, corrected, inSheet,
// source, can } + totals.workedMinutes. `status`: (default) the standing ones · open · closed · void · corrected · all.
// A row opens the record (`att=<id>`): original vs corrected times and the change log. «+ إدخال يدوي» needs attendance.manage.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import AttendanceForm from './AttendanceForm.vue';
import { ATT_STATE, CORRECTED_CHIP, FROM_ODOO, IN_SHEET_CHIP, attState, fmtClock, fmtDay, hm, pname, usePayrollLookups, usePayrollNav, usePayrollPerms, useSearchParam } from './payroll';

const props = defineProps({
  scope: { type: String, default: 'mine' },
});

const nav = usePayrollNav();
const perms = usePayrollPerms();
const all = computed(() => props.scope === 'all');
const lk = usePayrollLookups(() => all.value);
const { employeeOptions, departmentOptions } = lk;
const { text, type, apply } = useSearchParam(nav);

const STATUS = [['open', { ar: 'بلا انصراف', en: 'No check-out' }], ['closed', { ar: 'مكتملة', en: 'Complete' }], ['corrected', { ar: 'مُصحَّحة', en: 'Corrected' }], ['void', { ar: 'ملغاة', en: 'Void' }], ['all', { ar: 'الكل مع الملغاة', en: 'All, void included' }]];
const status = computed(() => (STATUS.some(([k]) => k === nav.get('status')) ? nav.get('status') : ''));
const sort = computed(() => nav.sortOf(['date', 'worked'], { key: 'date', order: 'desc' }));
const list = useList('/payroll/attendance', () => ({
  scope: props.scope, status: status.value || undefined, from: nav.get('from') || undefined, to: nav.get('to') || undefined,
  ...(all.value ? { q: nav.get('q') || undefined, employee: nav.get('employee') || undefined, department: nav.get('department') || undefined, source: nav.get('source') || undefined } : {}),
  sort: sort.value.key, order: sort.value.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['q', 'status', 'employee', 'department', 'from', 'to', 'source'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, status: null, employee: null, department: null, from: null, to: null, source: null });

const columns = computed(() => [
  ...(all.value ? [{ key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(160px,1.5fr)' }] : []),
  { key: 'date', header: { ar: 'يوم العمل', en: 'Work day' }, width: '100px', kind: 'date', sortable: true, value: (r) => fmtDay(r.workDate) },
  { key: 'checkIn', header: { ar: 'الحضور', en: 'In' }, width: '76px', kind: 'num', value: (r) => fmtClock(r.checkIn) },
  { key: 'checkOut', header: { ar: 'الانصراف', en: 'Out' }, width: '76px', kind: 'num', value: (r) => fmtClock(r.checkOut) },
  { key: 'worked', header: { ar: 'المدة', en: 'Worked' }, width: '70px', kind: 'num', sortable: true, value: (r) => hm(r.workedMinutes) },
  { key: 'mode', header: { ar: 'الطريقة', en: 'How' }, width: '96px', kind: 'muted', value: (r) => modeText(r) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '170px', bare: true },
]);
const MODE = { self: ['ذاتي', 'self'], manual: ['يدوي', 'by hand'], kiosk: ['جهاز', 'kiosk'], import: ['استيراد', 'import'], systray: ['أودو', 'Odoo'], technical: ['تقني', 'technical'] };
const modeText = (r) => [r.inMode, r.outMode].filter(Boolean).map((m) => (MODE[m] ? t(MODE[m][0], MODE[m][1]) : m)).filter((v, i, a) => a.indexOf(v) === i).join(' / ') || '—';
const entering = ref(false);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-if="all" small type="search" class="w-[200px]" :model-value="text" :placeholder="{ ar: 'بحث بالموظف…', en: 'Search employee…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[160px]" :model-value="status" :options="STATUS" :placeholder="{ ar: 'السجلات القائمة', en: 'Standing records' }" @update:model-value="(v) => nav.setFilter('status', v)" />
    <SelectInput v-if="all" small class="w-[180px]" :model-value="nav.get('employee')" :options="employeeOptions" :placeholder="{ ar: 'كل الموظفين', en: 'Every employee' }" @update:model-value="(v) => nav.setFilter('employee', v)" />
    <SelectInput v-if="all" small class="w-[150px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <DateInput small class="w-[136px]" :model-value="nav.get('from')" :title="t('من يوم', 'From')" :aria-label="t('من يوم', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[136px]" :model-value="nav.get('to')" :title="t('إلى يوم', 'To')" :aria-label="t('إلى يوم', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <SelectInput v-if="all" small class="w-[130px]" :model-value="nav.get('source')" :options="[['odoo', FROM_ODOO], ['ops', { ar: 'سُجِّلت هنا', en: 'Made here' }]]" :placeholder="{ ar: 'كل المصادر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
    <span class="grow" />
    <Btn v-if="all && perms.attendanceManage" size="sm" tone="primary" :label="{ ar: '+ إدخال حضور يدوي', en: '+ Enter attendance' }" @click="entering = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('att') || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا سجلات مطابقة.', en: 'No matching record.' } : scope === 'mine' ? { ar: 'لا سجلات حضور لك بعد — «تسجيل الحضور» في بطاقة الساعة.', en: 'No attendance record yet — “Check in” on the clock card.' } : { ar: 'لا سجلات حضور.', en: 'No attendance record.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.open('att', r.id)">
      <template #cell-employee="{ row }">
        <div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }}</div></div>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="ATT_STATE" :k="attState(row)" />
          <Chip v-if="row.corrected" small :label="CORRECTED_CHIP" :fg="CORRECTED_CHIP.fg" :bg="CORRECTED_CHIP.bg" />
          <Chip v-if="row.inSheet" small :label="IN_SHEET_CHIP" :fg="IN_SHEET_CHIP.fg" :bg="IN_SHEET_CHIP.bg" />
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template v-if="list.data.value?.totals" #footer>
        <span class="me-3">{{ t('مجموع مدة المطابق', 'Time worked of the matches') }}: <b class="num">{{ hm(list.data.value.totals.workedMinutes, false) }}</b> {{ t('ساعة', 'h') }}</span>
      </template>
    </DataTable>
  </div>

  <AttendanceForm :open="entering" @close="entering = false" @done="(a) => nav.open('att', a.id)" />
</template>
