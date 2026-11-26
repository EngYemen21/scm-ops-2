<script setup>
// The figures on top of the page (GET /payroll/summary) — each tile only for who may see it, each a shortcut to the
// list behind it: my waiting requests and today's worked time · what waits for my decision and who is off today ·
// who is in, who forgot to check out, sheets to confirm · runs to approve, adjustments waiting, unmapped accounts.
import { computed } from 'vue';
import { KpiCard, KpiGrid } from '@/components';
import { fmtPeriod, hm } from './payroll';

const props = defineProps({
  summary: { type: Object, default: null },
  perms: { type: Object, required: true },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['go']);

const s = computed(() => props.summary || {});
const me = computed(() => (s.value.me?.linked ? s.value.me : null));
const shown = computed(() => props.loading || !!props.summary);
</script>

<template>
  <KpiGrid v-if="shown" compact class="mb-3">
    <template v-if="loading && !summary">
      <KpiCard :label="{ ar: 'خدمتي', en: 'My corner' }" loading />
      <KpiCard :label="{ ar: 'الإجازات', en: 'Time off' }" loading />
    </template>
    <template v-if="me">
      <KpiCard clickable :value="hm(me.attendance?.workedMinutesToday, false)" :label="{ ar: 'عملتُ اليوم (ساعة)', en: 'Worked today (h)' }" :sub="me.attendance?.in ? { ar: 'داخل الآن', en: 'In now' } : null" color="#1d7a3e" @click="emit('go', 'my', { view: 'attendance' })" />
      <KpiCard clickable :value="s.leave?.mine?.waiting ?? 0" :label="{ ar: 'طلبات إجازتي المنتظرة', en: 'My requests waiting' }" color="#b26a16" @click="emit('go', 'my', { view: 'leave' })" />
      <KpiCard v-if="me.lastPayslip" clickable :value="fmtPeriod(me.lastPayslip.period)" :label="{ ar: 'آخر قسيمة راتب', en: 'Last payslip' }" color="#654e92" @click="emit('go', 'my', { view: 'payslips', slip: me.lastPayslip.number })" />
    </template>
    <template v-if="s.leave?.decide">
      <KpiCard clickable :value="s.leave.decide.waiting" :label="{ ar: 'إجازات تنتظر قراري', en: 'Leave waiting for me' }" :color="s.leave.decide.waiting ? '#b23b3b' : '#20242E'" @click="emit('go', 'leave', { view: 'waiting' })" />
      <KpiCard clickable :value="s.leave.decide.offToday" :label="{ ar: 'في إجازة اليوم', en: 'Off today' }" color="#654e92" @click="emit('go', 'leave', { view: 'calendar' })" />
    </template>
    <template v-if="s.attendance">
      <KpiCard clickable :value="s.attendance.checkedIn" :label="{ ar: 'سجّلوا حضورهم اليوم', en: 'Checked in today' }" :sub="{ ar: `منهم ${s.attendance.stillIn} داخل الآن`, en: `${s.attendance.stillIn} still in` }" color="#1d7a3e" @click="emit('go', 'attendance', { view: 'daily' })" />
      <KpiCard clickable :value="s.attendance.missingCheckOut" :label="{ ar: 'حضور بلا انصراف (أيام سابقة)', en: 'No check-out (earlier days)' }" :color="s.attendance.missingCheckOut ? '#b23b3b' : '#20242E'" @click="emit('go', 'attendance', { view: 'records', status: 'open' })" />
      <KpiCard clickable :value="s.attendance.sheets.draft + s.attendance.sheets.confirmed" :label="{ ar: 'كشوف لم تُعتمد', en: 'Sheets not approved' }" :sub="{ ar: `${s.attendance.sheets.draft} مسودة · ${s.attendance.sheets.confirmed} مؤكَّد`, en: `${s.attendance.sheets.draft} draft · ${s.attendance.sheets.confirmed} confirmed` }" color="#3C79F5" @click="emit('go', 'attendance', { view: 'sheets', state: 'draft,confirmed' })" />
    </template>
    <template v-if="s.payroll">
      <KpiCard clickable :value="s.payroll.runs.review" :label="{ ar: 'مسيرات بانتظار الاعتماد', en: 'Runs in review' }" :sub="{ ar: `${s.payroll.runs.draft} مسودة · ${s.payroll.runs.approved} معتمد لم يُرحَّل`, en: `${s.payroll.runs.draft} draft · ${s.payroll.runs.approved} approved, not posted` }" color="#b26a16" @click="emit('go', 'payroll', { view: 'runs', state: 'review' })" />
      <KpiCard clickable :value="s.payroll.pendingAdjustments" :label="{ ar: 'تسويات تنتظر مسيرًا', en: 'Adjustments pending' }" color="#654e92" @click="emit('go', 'payroll', { view: 'adjustments', state: 'pending' })" />
      <KpiCard v-if="s.payroll.unmappedAccounts" clickable :value="s.payroll.unmappedAccounts" :label="{ ar: 'بنود توجيه بلا حساب', en: 'Posting keys without an account' }" color="#b23b3b" @click="emit('go', 'settings', { set: 'accounts' })" />
    </template>
  </KpiGrid>
</template>
