<script setup>
// A list of leave requests: GET /payroll/leaves?scope=…&state&type&employee&department&from&to&source&q&sort&order&page
//   mine     the person's own requests            team     the people who report to the caller
//   waiting  what waits for the caller's decision  all      everyone (leave.approve / payroll people)
// Filters, sort and page live in the query string; a row opens the request (`leave=<number>`). In «waiting» each row
// carries approve / refuse, offered only where the row's `can` says the caller decides it.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { useLeaveActs } from './leaveActs';
import { FROM_ODOO, LEAVE_STATE, days, fmtDay, optionsOf, pname, typeColor, usePayrollLookups, usePayrollNav, useSearchParam } from './payroll';

const props = defineProps({
  scope: { type: String, default: 'mine' },
});

const nav = usePayrollNav();
const { page } = nav;
const others = computed(() => props.scope !== 'mine');
const { text, type, apply } = useSearchParam(nav);
const lk = usePayrollLookups();
const { leaveTypeOptions, employeeOptions, departmentOptions } = lk;
const acts = useLeaveActs();

const STATES = Object.keys(LEAVE_STATE);
const state = computed(() => (nav.get('state').split(',').every((s) => STATES.includes(s)) ? nav.get('state') : ''));
const sort = computed(() => nav.sortOf(['number', 'from', 'days', 'state', 'created'], { key: 'from', order: 'desc' }));
const list = useList('/payroll/leaves', () => ({
  scope: props.scope, state: state.value || undefined, type: nav.get('type') || undefined, q: nav.get('q') || undefined,
  employee: others.value ? nav.get('employee') || undefined : undefined, department: others.value ? nav.get('department') || undefined : undefined,
  from: nav.get('from') || undefined, to: nav.get('to') || undefined, source: nav.get('source') || undefined,
  sort: sort.value.key, order: sort.value.order, page: page.value, pageSize: 25,
}));
const filtered = computed(() => ['q', 'state', 'type', 'employee', 'department', 'from', 'to', 'source'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, state: null, type: null, employee: null, department: null, from: null, to: null, source: null });

const columns = computed(() => [
  { key: 'number', header: { ar: 'الطلب', en: 'Request' }, width: '120px', kind: 'id', sortable: true },
  ...(others.value ? [{ key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(150px,1.4fr)' }] : []),
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: 'minmax(120px,1fr)' },
  { key: 'from', header: { ar: 'المدة', en: 'Dates' }, width: '168px', sortable: true },
  { key: 'days', header: { ar: 'الأيام', en: 'Days' }, width: '64px', kind: 'num', sortable: true, value: (r) => days(r.days) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '140px', sortable: true, bare: true },
  ...(props.scope === 'waiting' ? [{ key: 'act', header: { ar: 'القرار', en: 'Decision' }, width: '150px', bare: true }] : []),
]);
const empty = computed(() => (filtered.value ? { ar: 'لا طلبات مطابقة.', en: 'No matching request.' }
  : props.scope === 'waiting' ? { ar: 'لا شيء ينتظر قرارك ✓', en: 'Nothing waits for your decision ✓' }
    : props.scope === 'mine' ? { ar: 'لا طلبات إجازة لك بعد — «+ طلب إجازة» يبدأ أول طلب.', en: 'You have no leave request yet — “+ Leave request” starts one.' }
      : { ar: 'لا طلبات إجازة.', en: 'No leave request.' }));
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[210px]" :model-value="text" :placeholder="{ ar: 'بحث بالرقم أو الموظف…', en: 'Search number or employee…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[160px]" :model-value="state" :options="[...(scope === 'mine' ? [] : [['submitted,manager_approved', { ar: 'كل المنتظرة', en: 'All waiting' }]]), ...optionsOf(LEAVE_STATE)]" :placeholder="{ ar: 'كل الحالات', en: 'Every state' }" @update:model-value="(v) => nav.setFilter('state', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('type')" :options="leaveTypeOptions" :placeholder="{ ar: 'كل الأنواع', en: 'Every type' }" @update:model-value="(v) => nav.setFilter('type', v)" />
    <SelectInput v-if="others" small class="w-[180px]" :model-value="nav.get('employee')" :options="employeeOptions" :placeholder="{ ar: 'كل الموظفين', en: 'Every employee' }" @update:model-value="(v) => nav.setFilter('employee', v)" />
    <SelectInput v-if="others" small class="w-[150px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <DateInput small class="w-[136px]" :model-value="nav.get('from')" :title="t('من تاريخ', 'From')" :aria-label="t('من تاريخ', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[136px]" :model-value="nav.get('to')" :title="t('إلى تاريخ', 'To')" :aria-label="t('إلى تاريخ', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <SelectInput v-if="others" small class="w-[130px]" :model-value="nav.get('source')" :options="[['odoo', FROM_ODOO], ['ops', { ar: 'أُنشئت هنا', en: 'Made here' }]]" :placeholder="{ ar: 'كل المصادر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value || acts.act.error.value" :closable="!list.error.value" @close="acts.act.clearError()" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('leave') || null" :sort="sort" server-sort :empty-text="empty"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.open('leave', r.number)">
      <template #cell-employee="{ row }">
        <div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }}<template v-if="row.department"> · {{ pname(row.department) }}</template></div></div>
      </template>
      <template #cell-type="{ row }">
        <span class="inline-flex min-w-0 items-center gap-1.5"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: typeColor(row.type?.color).fg }" /><span class="truncate text-[11px]" dir="auto">{{ pname(row.type) }}</span></span>
      </template>
      <template #cell-from="{ row }">
        <span class="cell-date">{{ fmtDay(row.dateFrom) }}<template v-if="row.dateTo !== row.dateFrom"> – {{ fmtDay(row.dateTo) }}</template><template v-if="row.halfDay"> · ½</template></span>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="LEAVE_STATE" :k="row.state" />
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template #cell-act="{ row }">
        <span v-if="row.can?.approve || row.can?.refuse" class="inline-flex gap-1" @click.stop>
          <Btn v-if="row.can.approve" size="sm" tone="softGreen" :loading="acts.act.pending.value" :label="row.can.step === 'first' ? { ar: 'اعتماد أول', en: 'First approval' } : { ar: 'اعتماد', en: 'Approve' }" @click="acts.approve(row)" />
          <Btn v-if="row.can.refuse" size="sm" tone="softRed" :label="{ ar: 'رفض', en: 'Refuse' }" @click="acts.refuse(row)" />
        </span>
        <span v-else class="text-[9.5px] text-faint">{{ t('ليس قرارك', 'Not yours') }}</span>
      </template>
      <template v-if="list.data.value?.totals" #footer>
        <span class="me-3">{{ t('مجموع أيام المطابق', 'Days of the matches') }}: <b class="num">{{ days(list.data.value.totals.days) }}</b></span>
      </template>
    </DataTable>
  </div>
</template>
