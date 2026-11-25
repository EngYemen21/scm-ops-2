<script setup>
// «الإعدادات» / Settings — what working time and pay are made of. The officers (payroll people, leave.approve,
// attendance.manage) read the working-time sections; the pay sections are the payroll people's. Every change needs
// payroll.manage; nothing is deleted — an entry is switched off (a dated rate nobody was paid under may be taken out).
// Rows marked «من أودو» came from Odoo while the section follows it: what Odoo owns is changed there.
//   /payroll?tab=settings&set=schedules|holidays|leaveTypes|policies|components|structures|gosi|parameters|accounts|profiles
import { computed } from 'vue';
import { Tabs } from '@/components';
import { t } from '@/i18n';
import AccountsSet from './AccountsSet.vue';
import ComponentsSet from './ComponentsSet.vue';
import GosiSet from './GosiSet.vue';
import HolidaysSet from './HolidaysSet.vue';
import LeaveTypesSet from './LeaveTypesSet.vue';
import ParametersSet from './ParametersSet.vue';
import PoliciesSet from './PoliciesSet.vue';
import ProfilesSet from './ProfilesSet.vue';
import SchedulesSet from './SchedulesSet.vue';
import StructuresSet from './StructuresSet.vue';
import { usePayrollNav, usePayrollPerms } from './payroll';

const nav = usePayrollNav();
const perms = usePayrollPerms();
const sets = computed(() => [
  { k: 'schedules', label: { ar: 'جداول الدوام', en: 'Work schedules' } },
  { k: 'holidays', label: { ar: 'العطل الرسمية', en: 'Public holidays' } },
  { k: 'leaveTypes', label: { ar: 'أنواع الإجازات', en: 'Leave types' } },
  { k: 'policies', label: { ar: 'سياسات الحضور', en: 'Attendance policies' } },
  ...(perms.value.pay ? [
    { k: 'components', label: { ar: 'بنود الراتب', en: 'Pay components' } },
    { k: 'structures', label: { ar: 'هياكل الرواتب', en: 'Pay structures' } },
    { k: 'gosi', label: { ar: 'نسب التأمينات', en: 'GOSI rates' } },
    { k: 'parameters', label: { ar: 'معاملات الحساب', en: 'Parameters' } },
    { k: 'accounts', label: { ar: 'التوجيه المحاسبي', en: 'Posting map' } },
    { k: 'profiles', label: { ar: 'ملفات الرواتب', en: 'Pay profiles' } },
  ] : []),
]);
const set = computed(() => (sets.value.some((s) => s.k === nav.get('set')) ? nav.get('set') : 'schedules'));
const canEdit = computed(() => perms.value.payrollManage);
</script>

<template>
  <Tabs :model-value="set" :tabs="sets" variant="pill" class="mb-2.5" @update:model-value="(k) => nav.go('settings', { set: k })" />
  <div v-if="!canEdit" class="hint !mt-0 mb-2.5">{{ t('للاطلاع فقط: تعديل الإعدادات لمسؤول الرواتب (payroll.manage).', 'Read only: changing the settings belongs to the payroll officer (payroll.manage).') }}</div>
  <SchedulesSet v-if="set === 'schedules'" :can-edit="canEdit" />
  <HolidaysSet v-else-if="set === 'holidays'" :can-edit="canEdit" />
  <LeaveTypesSet v-else-if="set === 'leaveTypes'" :can-edit="canEdit" />
  <PoliciesSet v-else-if="set === 'policies'" :can-edit="canEdit" />
  <ComponentsSet v-else-if="set === 'components'" :can-edit="canEdit" />
  <StructuresSet v-else-if="set === 'structures'" :can-edit="canEdit" />
  <GosiSet v-else-if="set === 'gosi'" :can-edit="canEdit" />
  <ParametersSet v-else-if="set === 'parameters'" :can-edit="canEdit" />
  <AccountsSet v-else-if="set === 'accounts'" :can-edit="canEdit" />
  <ProfilesSet v-else-if="set === 'profiles'" :can-edit="canEdit" />
</template>
