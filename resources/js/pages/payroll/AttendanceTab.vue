<script setup>
// «الحضور» / Attendance — for the timekeepers (attendance.manage) and the payroll people:
//   view=daily     the daily board            view=records   everyone's records (manual entry, correction, void)
//   view=sheets    the period sheets          view=batches   the batches of sheets of a department
import { computed } from 'vue';
import { Tabs } from '@/components';
import AttendanceList from './AttendanceList.vue';
import BatchList from './BatchList.vue';
import DailyBoard from './DailyBoard.vue';
import SheetList from './SheetList.vue';
import { usePayrollNav } from './payroll';

const props = defineProps({
  summary: { type: Object, default: null },
});

const nav = usePayrollNav();
const views = computed(() => [
  { k: 'daily', label: { ar: 'لوحة اليوم', en: 'Daily board' } },
  { k: 'records', label: { ar: 'سجلات الحضور', en: 'Records' }, badge: props.summary?.attendance?.missingCheckOut || null },
  { k: 'sheets', label: { ar: 'كشوف الحضور', en: 'Sheets' }, badge: props.summary?.attendance ? props.summary.attendance.sheets.draft + props.summary.attendance.sheets.confirmed || null : null },
  { k: 'batches', label: { ar: 'الدفعات', en: 'Batches' } },
]);
const view = computed(() => (views.value.some((v) => v.k === nav.get('view')) ? nav.get('view') : 'daily'));
</script>

<template>
  <Tabs :model-value="view" :tabs="views" variant="sm" class="mb-2.5" @update:model-value="(k) => nav.go('attendance', { view: k })" />
  <DailyBoard v-if="view === 'daily'" />
  <AttendanceList v-else-if="view === 'records'" scope="all" />
  <SheetList v-else-if="view === 'sheets'" />
  <BatchList v-else-if="view === 'batches'" />
</template>
