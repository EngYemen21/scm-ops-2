<script setup>
// The daily board: everyone at work on one date — what the day planned, when each came and left, late, early leave and
// overtime as they stand (the actual minutes; the policy is applied on the period sheet).
//   GET /payroll/attendance/daily?date&department&state&q&sort(late|worked|overtime)&order&page&pageSize
//   → { items: [{ employee, kind, state, plannedIn, plannedOut, actualIn, actualOut, workedMinutes, lateMinutes,
//        earlyMinutes, overtimeMinutes, records, leave, holiday }], totals: { present, absent, late, onLeave, open, off }, date }
// A row opens that person's records of the day.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { DAILY_STATE, fmtDay, hm, pname, todayIso, usePayrollLookups, usePayrollNav, useSearchParam } from './payroll';

const nav = usePayrollNav();
const { departmentOptions } = usePayrollLookups();
const { text, type, apply } = useSearchParam(nav);
const date = computed(() => (/^\d{4}-\d{2}-\d{2}$/.test(nav.get('date')) ? nav.get('date') : todayIso()));
const STATES = ['present', 'open', 'absent', 'late', 'leave', 'holiday', 'weekend', 'off', 'expected'];
const state = computed(() => (STATES.includes(nav.get('state')) ? nav.get('state') : ''));
const sort = computed(() => nav.sortOf(['late', 'worked', 'overtime'], null));
const list = useList('/payroll/attendance/daily', () => ({
  date: date.value, department: nav.get('department') || undefined, state: state.value || undefined, q: nav.get('q') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 50,
}));
const totals = computed(() => list.data.value?.totals || null);
const pills = computed(() => [
  { k: '', label: { ar: 'الكل', en: 'All' } },
  { k: 'present', label: { ar: 'حاضرون', en: 'Present' }, badge: totals.value?.present ?? null },
  { k: 'open', label: { ar: 'داخل الآن', en: 'In now' }, badge: totals.value?.open ?? null },
  { k: 'absent', label: { ar: 'غائبون', en: 'Absent' }, badge: totals.value?.absent ?? null },
  { k: 'late', label: { ar: 'متأخرون', en: 'Late' }, badge: totals.value?.late ?? null },
  { k: 'leave', label: { ar: 'في إجازة', en: 'On leave' }, badge: totals.value?.onLeave ?? null },
]);
const columns = [
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(160px,1.6fr)' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '120px', bare: true },
  { key: 'planned', header: { ar: 'المخطط', en: 'Planned' }, width: '104px', kind: 'num', value: (r) => (r.plannedIn ? `${r.plannedIn}–${r.plannedOut}` : '—') },
  { key: 'actual', header: { ar: 'الفعلي', en: 'Actual' }, width: '104px', kind: 'num', value: (r) => (r.actualIn ? `${r.actualIn}–${r.actualOut || '…'}` : '—') },
  { key: 'worked', header: { ar: 'المدة', en: 'Worked' }, width: '66px', kind: 'num', sortable: true, value: (r) => hm(r.workedMinutes) },
  { key: 'late', header: { ar: 'تأخير', en: 'Late' }, width: '62px', kind: 'num', sortable: true },
  { key: 'early', header: { ar: 'خروج مبكر', en: 'Early' }, width: '70px', kind: 'num', value: (r) => hm(r.earlyMinutes) },
  { key: 'overtime', header: { ar: 'إضافي', en: 'Overtime' }, width: '66px', kind: 'num', sortable: true, value: (r) => hm(r.overtimeMinutes) },
  { key: 'note', header: { ar: 'ملاحظة', en: 'Note' }, width: 'minmax(110px,1fr)' },
];
const openRecords = (r) => nav.go('attendance', { view: 'records', employee: r.employee?.id, from: date.value, to: date.value, status: 'all' });
</script>

<template>
  <div class="row wrap mb-2.5">
    <DateInput small class="w-[150px]" :model-value="date" :aria-label="t('اليوم', 'Day')" @update:model-value="(v) => nav.setFilter('date', v)" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <TextInput small type="search" class="w-[190px]" :model-value="text" :placeholder="{ ar: 'بحث بالموظف…', en: 'Search employee…' }" @update:model-value="type" @enter="apply" />
  </div>
  <div class="mb-2.5 grid grid-cols-6 gap-2 max-[767px]:grid-cols-3">
    <div class="tile green"><div class="tile-v">{{ totals?.present ?? '—' }}</div><div class="tile-l">{{ t('حاضرون', 'Present') }}</div></div>
    <div class="tile"><div class="tile-v">{{ totals?.open ?? '—' }}</div><div class="tile-l">{{ t('داخل الآن', 'In now') }}</div></div>
    <div class="tile red"><div class="tile-v">{{ totals?.absent ?? '—' }}</div><div class="tile-l">{{ t('غائبون', 'Absent') }}</div></div>
    <div class="tile amber"><div class="tile-v">{{ totals?.late ?? '—' }}</div><div class="tile-l">{{ t('متأخرون', 'Late') }}</div></div>
    <div class="tile purple"><div class="tile-v">{{ totals?.onLeave ?? '—' }}</div><div class="tile-l">{{ t('في إجازة', 'On leave') }}</div></div>
    <div class="tile teal"><div class="tile-v">{{ totals?.off ?? '—' }}</div><div class="tile-l">{{ t('عطلة / خارج العقد', 'Off') }}</div></div>
  </div>
  <Tabs :model-value="state" :tabs="pills" variant="pill" class="mb-2" @update:model-value="(k) => nav.setFilter('state', k)" />
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.employee?.id" :sort="sort" server-sort
               :empty-text="{ ar: `لا أحد يطابق هذا الاختيار في ${fmtDay(date)}.`, en: `Nobody matches this choice on ${fmtDay(date)}.` }" @sort="nav.setSort" @page="nav.setPage" @row-click="openRecords">
      <template #cell-employee="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }}<template v-if="row.records > 1"> · {{ row.records }} {{ t('سجلات', 'records') }}</template></div></div></template>
      <template #cell-state="{ row }"><Chip small :map="DAILY_STATE" :k="row.state" /></template>
      <template #cell-late="{ row }"><span class="num" :class="row.lateMinutes ? 'font-bold text-warn' : ''">{{ hm(row.lateMinutes) }}</span></template>
      <template #cell-note="{ row }"><span class="truncate text-[10.5px] text-muted" dir="auto">{{ row.leave ? pname(row.leave) : row.holiday ? pname(row.holiday) : '' }}</span></template>
    </DataTable>
  </div>
</template>
