<script setup>
// «الإجازات» / Time off — for the managers of a team, the leave officers and the payroll people:
//   view=waiting    what waits for MY decision (a manager: his team's first step; leave.approve: everything)
//   view=team       my team's requests          view=all   everyone's (leave.approve, payroll people)
//   view=calendar   who is off, day by day      view=balances  one person's balances, the ledger, grants and the yearly allowance
import { computed, ref } from 'vue';
import { Btn, Tabs } from '@/components';
import LeaveBalances from './LeaveBalances.vue';
import LeaveCalendar from './LeaveCalendar.vue';
import LeaveForm from './LeaveForm.vue';
import LeaveList from './LeaveList.vue';
import { usePayrollNav, usePayrollPerms } from './payroll';

const props = defineProps({
  summary: { type: Object, default: null },
});

const nav = usePayrollNav();
const perms = usePayrollPerms();
const views = computed(() => [
  { k: 'waiting', label: { ar: 'تنتظر قراري', en: 'Waiting for me' }, badge: props.summary?.leave?.decide?.waiting || null },
  ...(perms.value.managesTeam ? [{ k: 'team', label: { ar: 'فريقي', en: 'My team' } }] : []),
  ...(perms.value.allLeave ? [{ k: 'all', label: { ar: 'كل الطلبات', en: 'All requests' } }] : []),
  { k: 'calendar', label: { ar: 'التقويم', en: 'Calendar' } },
  { k: 'balances', label: { ar: 'الأرصدة والدفتر', en: 'Balances & ledger' } },
]);
const view = computed(() => (views.value.some((v) => v.k === nav.get('view')) ? nav.get('view') : 'waiting'));
const entering = ref(false);
</script>

<template>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="view" :tabs="views" variant="sm" class="!mb-0" @update:model-value="(k) => nav.go('leave', { view: k })" />
    <span class="grow" />
    <Btn v-if="perms.leaveOfficer" size="sm" tone="primary" :label="{ ar: '+ إجازة لموظف', en: '+ Leave for an employee' }" @click="entering = true" />
  </div>

  <LeaveList v-if="view === 'waiting'" key="waiting" scope="waiting" />
  <LeaveList v-else-if="view === 'team'" key="team" scope="team" />
  <LeaveList v-else-if="view === 'all'" key="all" scope="all" />
  <LeaveCalendar v-else-if="view === 'calendar'" />
  <LeaveBalances v-else-if="view === 'balances'" />

  <LeaveForm :open="entering" for-others @close="entering = false" @done="(l) => nav.open('leave', l.number)" />
</template>
