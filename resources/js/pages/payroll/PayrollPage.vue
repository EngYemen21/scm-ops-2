<script setup>
// «الرواتب والإجازات والحضور» / Payroll, time off, attendance. Everything on screen lives in the query string (the
// keys are listed at `usePayrollNav` in ./payroll.js), so every tab, filter and open record is a link:
//   /payroll                                   my corner           /payroll?tab=leave&view=waiting      what waits for me
//   /payroll?tab=payroll&run=PYR-2026-00012    one run             /payroll?slip=PSL-2026-00031         one payslip, on any tab
// The tabs follow GET /payroll/summary → `permissions`: everyone has their own corner; managers of a team and leave
// officers decide time off; timekeepers keep attendance; the payroll people run pay, skills and the configuration.
// The server enforces every one of them; this page only hides what would be refused.
import { computed } from 'vue';
import { ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import AttendanceDrawer from './AttendanceDrawer.vue';
import AttendanceTab from './AttendanceTab.vue';
import BatchDrawer from './BatchDrawer.vue';
import LeaveDrawer from './LeaveDrawer.vue';
import LeaveTab from './LeaveTab.vue';
import MyTab from './MyTab.vue';
import PayrollTab from './PayrollTab.vue';
import PayslipDrawer from './PayslipDrawer.vue';
import RunDrawer from './RunDrawer.vue';
import SettingsTab from './SettingsTab.vue';
import SheetDrawer from './SheetDrawer.vue';
import SkillsDrawer from './SkillsDrawer.vue';
import SkillsTab from './SkillsTab.vue';
import SummaryStrip from './SummaryStrip.vue';
import { usePayrollNav, usePayrollPerms, usePayrollSummary } from './payroll';

const nav = usePayrollNav();
const summary = usePayrollSummary();
const s = computed(() => summary.data.value || null);
const perms = usePayrollPerms();

const tabs = computed(() => {
  const p = perms.value;
  return [
    { k: 'my', label: { ar: 'خدمتي', en: 'My corner' } },
    ...(p.managesTeam || p.allLeave ? [{ k: 'leave', label: { ar: 'الإجازات', en: 'Time off' }, badge: s.value?.leave?.decide?.waiting || null }] : []),
    ...(p.allAttendance ? [{ k: 'attendance', label: { ar: 'الحضور', en: 'Attendance' }, badge: s.value?.attendance?.missingCheckOut || null }] : []),
    ...(p.pay ? [{ k: 'payroll', label: { ar: 'الرواتب', en: 'Payroll' }, badge: s.value?.payroll?.runs?.review || null },
      { k: 'skills', label: { ar: 'المهارات', en: 'Skills' } }] : []),
    ...(p.officer ? [{ k: 'settings', label: { ar: 'الإعدادات', en: 'Settings' } }] : []),
  ];
});
const tab = computed(() => (tabs.value.some((x) => x.k === nav.get('tab')) ? nav.get('tab') : 'my'));
</script>

<template>
  <PageHead :sub="t('خدمتي: الحضور والإجازات والقسائم · قرارات الإجازات · الحضور وكشوفه · مسيرات الرواتب حتى القيد والصرف · المهارات · الإعدادات', 'My corner: clock, time off, payslips · leave decisions · attendance and its sheets · pay runs down to the books and the payment · skills · settings')" />
  <ErrorBanner :error="summary.error.value" :closable="false" />

  <SummaryStrip :summary="s" :perms="perms" :loading="summary.isLoading.value" @go="(k, params) => nav.go(k, params)" />

  <div v-if="s?.following" class="banner teal">
    <div>{{ t('هذا القسم يتبع أودو حاليًا: السجلات القادمة من أودو (عليها شارة «من أودو») تُعرض هنا وتُعدَّل هناك، وما يُنشأ هنا يعمل كاملًا. ما يضيفه نظام العمليات على سجلات أودو (الرصيد السنوي، فترات السماح، الافتراضي، الملاحظات) يبقى قابلًا للتعديل.', 'This section follows Odoo for now: records that came from Odoo (marked “From Odoo”) are shown here and changed there, and what is created here works fully. What OPS adds on Odoo’s records (the yearly allowance, grace times, the default, notes) stays editable.') }}</div>
  </div>

  <Tabs v-if="tabs.length > 1" :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" />
  <div class="mt-1">
    <MyTab v-if="tab === 'my'" :summary="s" :loading="summary.isLoading.value" />
    <LeaveTab v-else-if="tab === 'leave'" :summary="s" />
    <AttendanceTab v-else-if="tab === 'attendance'" :summary="s" />
    <PayrollTab v-else-if="tab === 'payroll'" />
    <SkillsTab v-else-if="tab === 'skills'" />
    <SettingsTab v-else-if="tab === 'settings'" />
  </div>

  <!-- the records open from any tab and from a link; the later ones stack above the earlier ones -->
  <LeaveDrawer :number="nav.get('leave') || null" @close="nav.close('leave')" />
  <AttendanceDrawer :id="nav.get('att') || null" @close="nav.close('att')" />
  <BatchDrawer :number="nav.get('batch') || null" @close="nav.close('batch')" />
  <SheetDrawer :number="nav.get('sheet') || null" @close="nav.close('sheet')" />
  <RunDrawer :number="nav.get('run') || null" @close="nav.close('run')" />
  <PayslipDrawer :number="nav.get('slip') || null" @close="nav.close('slip')" />
  <SkillsDrawer :employee="nav.get('emp') || null" @close="nav.close('emp')" />
</template>
