<script setup>
// «خدمتي» / My corner — what belongs to the signed-in person, through the employee record HR linked to their login:
// the clock (check in / out), the leave balances of a year, the leave requests (+ a new one), the attendance records,
// the payslips (once their run is approved) and the skills and résumé. A login HR has not linked yet gets the honest
// notice and no list (each would only answer NO_EMPLOYEE_RECORD).
//   /payroll?tab=my&view=leave|attendance|payslips|skills
import { computed, ref } from 'vue';
import { Btn, SectionCard, Tabs } from '@/components';
import { t } from '@/i18n';
import AttendanceList from './AttendanceList.vue';
import BalanceCards from './BalanceCards.vue';
import ClockCard from './ClockCard.vue';
import LeaveForm from './LeaveForm.vue';
import LeaveList from './LeaveList.vue';
import PayslipList from './PayslipList.vue';
import SkillsPanel from './SkillsPanel.vue';
import { pname, usePayrollNav, usePayrollPerms } from './payroll';

const props = defineProps({
  summary: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});

const nav = usePayrollNav();
const perms = usePayrollPerms();
const linked = computed(() => !!props.summary?.me?.linked);
const unlinked = computed(() => !!props.summary && !linked.value);

const views = computed(() => [
  { k: 'leave', label: { ar: 'إجازاتي', en: 'My time off' }, badge: props.summary?.leave?.mine?.waiting || null },
  { k: 'attendance', label: { ar: 'حضوري', en: 'My attendance' } },
  { k: 'payslips', label: { ar: 'قسائم راتبي', en: 'My payslips' }, badge: props.summary?.me?.payslips || null },
  { k: 'skills', label: { ar: 'مهاراتي وسيرتي', en: 'My skills & résumé' } },
]);
const view = computed(() => (views.value.some((v) => v.k === nav.get('view')) ? nav.get('view') : 'leave'));
const asking = ref(false);
</script>

<template>
  <div v-if="loading && !summary" class="skel h-[160px]" />

  <div v-else-if="unlinked" class="banner amber">
    <div>
      <b>{{ t('حسابك غير مربوط بسجل موظف بعد.', 'Your login is not linked to an employee record yet.') }}</b>
      {{ t('الحضور والإجازات والقسائم والمهارات تُقرأ من سجلك الوظيفي: اطلب من الموارد البشرية ربط دخولك بسجلك في «الموظفون والمصروفات». بعد الربط تظهر هنا مباشرة.', 'Attendance, time off, payslips and skills are read from your employee record: ask HR to link your login to your record in “People & expenses”. Once linked, they show here at once.') }}
    </div>
  </div>

  <template v-else>
    <div v-if="summary?.me?.employee" class="mb-2 text-[11px] text-muted">
      {{ t('سجلّك:', 'Your record:') }} <b class="text-ink">{{ pname(summary.me.employee) }}</b> · <span class="num ltr">{{ summary.me.employee.code }}</span>
    </div>
    <div class="grid-eq mb-3">
      <ClockCard />
      <SectionCard :title="{ ar: 'أرصدة إجازاتي', en: 'My leave balances' }">
        <BalanceCards :year="summary?.me?.year || null" compact />
      </SectionCard>
    </div>

    <div class="row wrap mb-2.5">
      <Tabs :model-value="view" :tabs="views" variant="sm" class="!mb-0" @update:model-value="(k) => nav.go('my', { view: k })" />
      <span class="grow" />
      <Btn v-if="view === 'leave' && perms.leaveRequest" size="sm" tone="primary" :label="{ ar: '+ طلب إجازة', en: '+ Leave request' }" @click="asking = true" />
    </div>

    <LeaveList v-if="view === 'leave'" scope="mine" />
    <AttendanceList v-else-if="view === 'attendance'" scope="mine" />
    <PayslipList v-else-if="view === 'payslips'" scope="mine" />
    <SkillsPanel v-else-if="view === 'skills'" employee="me" />

    <LeaveForm :open="asking" @close="asking = false" @done="(l) => nav.open('leave', l.number)" />
  </template>
</template>
