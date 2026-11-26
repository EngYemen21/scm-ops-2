<script setup>
// «الرواتب» / Payroll — payroll.manage / payroll.approve:
//   view=runs         the monthly runs (+ a new one; a row opens the run with its payslips, journal and history)
//   view=payslips     every payslip                view=adjustments   the one-off additions and deductions of a month
import { computed } from 'vue';
import { Tabs } from '@/components';
import AdjustmentList from './AdjustmentList.vue';
import PayslipList from './PayslipList.vue';
import RunList from './RunList.vue';
import { usePayrollNav } from './payroll';

const nav = usePayrollNav();
const views = [
  { k: 'runs', label: { ar: 'المسيرات', en: 'Runs' } },
  { k: 'payslips', label: { ar: 'القسائم', en: 'Payslips' } },
  { k: 'adjustments', label: { ar: 'التسويات', en: 'Adjustments' } },
];
const view = computed(() => (views.some((v) => v.k === nav.get('view')) ? nav.get('view') : 'runs'));
</script>

<template>
  <Tabs :model-value="view" :tabs="views" variant="sm" class="mb-2.5" @update:model-value="(k) => nav.go('payroll', { view: k })" />
  <RunList v-if="view === 'runs'" />
  <PayslipList v-else-if="view === 'payslips'" scope="all" />
  <AdjustmentList v-else-if="view === 'adjustments'" />
</template>
