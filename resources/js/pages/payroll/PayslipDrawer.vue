<script setup>
// One payslip: GET /payroll/payslips/{id|number} → the row + structure, contract, paymentMethod, worked { daysInMonth,
// daysCovered, unpaidDays, absenceDays, overtimeMinutes, lateMinutes, earlyMinutes, absenceMinutes }, explain { period,
// contract, proration, parameters, structure, gosi, sheets, unpaidLeaves, claims, adjustments } and `lines` [{ code,
// nameAr, nameEn, category basic|allowance|gross|deduction|reimbursement|net|company, quantity, rate, amount, total, explain, claim }].
// The lines are grouped (earnings · deductions · reimbursements · the employer's share) and each one says in words how it
// was reached. «طباعة» prints the payslip alone (window.print; the rest of the screen is hidden while printing).
// An employee reads their own payslip once its run is approved; the payroll people read every one.
//   <PayslipDrawer :number="numberOrNull" @close="…" />
import { computed, onBeforeUnmount } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV } from '@/components';
import { bi, t } from '@/i18n';
import { COMPONENT_CATEGORY, PAY_METHOD, RUN_STATE, explainLine, fmtDay, fmtPeriod, hm, money, own, pname, usePayrollNav, warningText } from './payroll';

const props = defineProps({
  number: { type: String, default: null },
});
const emit = defineEmits(['close']);

const nav = usePayrollNav();
const q = useGet(() => (props.number ? `/payroll/payslips/${encodeURIComponent(props.number)}` : null));
const slip = computed(() => (props.number ? q.data.value || null : null));
const lines = computed(() => slip.value?.lines || []);
const GROUPS = [
  { k: 'earn', cats: ['basic', 'allowance'], label: { ar: 'الاستحقاقات', en: 'Earnings' }, fg: '#1d7a3e' },
  { k: 'ded', cats: ['deduction'], label: { ar: 'الاستقطاعات', en: 'Deductions' }, fg: '#b23b3b' },
  { k: 'reim', cats: ['reimbursement'], label: { ar: 'تعويضات المصروفات', en: 'Reimbursements' }, fg: '#0d7f93' },
  { k: 'comp', cats: ['company'], label: { ar: 'حصة المنشأة (لا تُخصم منك)', en: 'Employer share (not taken from you)' }, fg: '#654e92' },
];
const groups = computed(() => GROUPS.map((g) => ({ ...g, lines: lines.value.filter((l) => g.cats.includes(l.category)) })).filter((g) => g.lines.length));
const sum = (ls) => ls.reduce((s, l) => s + Math.round(Number(l.total) * 100), 0) / 100;
const CONTRACT_TYPE = { permanent: ['غير محدد المدة', 'Permanent'], fixed_term: ['محدد المدة', 'Fixed term'], temporary: ['مؤقت', 'Temporary'], part_time: ['دوام جزئي', 'Part time'], trainee: ['تدريب', 'Trainee'], other: ['آخر', 'Other'] };
const contractType = (k) => (CONTRACT_TYPE[k] ? t(CONTRACT_TYPE[k][0], CONTRACT_TYPE[k][1]) : k || '');
const w = computed(() => slip.value?.worked || {});
const ex = computed(() => slip.value?.explain || {});

// printing: the drawer is hidden by the print stylesheet, so a copy of the payslip is printed on its own
function cleanup() { document.body.classList.remove('printing-payslip'); window.removeEventListener('afterprint', cleanup); }
function print() {
  document.body.classList.add('printing-payslip');
  window.addEventListener('afterprint', cleanup);
  window.print();
  setTimeout(cleanup, 1000);
}
onBeforeUnmount(cleanup);
</script>

<template>
  <Drawer :open="!!number" :width="640" :z-index="65" @close="emit('close')">
    <template #title><span dir="auto">{{ slip ? `${t('قسيمة راتب', 'Payslip')} ${fmtPeriod(slip.period)} — ${pname(slip.employee)}` : number }}</span></template>
    <template #sub><span v-if="slip"><span class="num ltr inline-block">{{ slip.number }}</span><template v-if="slip.run?.number"> · <button type="button" class="cell-id" @click="nav.open('run', slip.run.number)">{{ slip.run.number }}</button></template></span></template>
    <template v-if="slip" #headExtra><Chip :map="RUN_STATE" :k="slip.state" /></template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-[260px]" />
    <template v-if="slip">
      <div class="grid grid-cols-4 gap-2 max-[520px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ money(slip.gross) }}</div><div class="tile-l">{{ t('الإجمالي', 'Gross') }}</div></div>
        <div class="tile red"><div class="tile-v">{{ money(slip.deductions) }}</div><div class="tile-l">{{ t('الاستقطاعات', 'Deductions') }}</div></div>
        <div class="tile teal"><div class="tile-v">{{ money(slip.reimbursements) }}</div><div class="tile-l">{{ t('التعويضات', 'Reimbursements') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ money(slip.net) }}</div><div class="tile-l">{{ t('الصافي', 'Net pay') }} · {{ slip.currency }}</div></div>
      </div>
      <div v-if="slip.warnings?.length" class="hint amber"><div v-for="c in slip.warnings" :key="c">⚠ {{ warningText(c) }}</div></div>

      <div v-for="g in groups" :key="g.k" class="mt-3.5">
        <div class="mb-1 flex items-center justify-between border-b border-line-2 pb-1">
          <b class="text-[12px]" :style="{ color: g.fg }">{{ bi(g.label) }}</b>
          <b class="num text-[12px]">{{ money(Math.abs(sum(g.lines))) }}</b>
        </div>
        <div v-for="l in g.lines" :key="l.id" class="flex items-start gap-3 py-1.5">
          <div class="min-w-0 flex-1">
            <div class="text-[11.5px] font-bold" dir="auto">{{ pname(l) }} <span class="num text-[9px] font-normal text-faint">{{ l.code }}</span></div>
            <div v-if="explainLine(l)" class="text-[10px] leading-[1.7] text-muted" dir="auto">{{ explainLine(l) }}</div>
          </div>
          <div class="num ltr shrink-0 text-[12px] font-bold" :class="Number(l.total) < 0 ? 'text-bad' : ''">{{ money(l.total) }}</div>
        </div>
      </div>
      <div class="mt-3 flex items-center justify-between rounded-[12px] bg-soft px-3 py-2.5">
        <b class="text-[13px]">{{ t('صافي الراتب', 'Net pay') }}</b><b class="num text-[16px] text-ok">{{ money(slip.net) }} {{ slip.currency }}</b>
      </div>

      <div class="card-title mb-1 mt-4">{{ t('الأيام والوقت', 'Days and time') }}</div>
      <div class="kv-grid">
        <KV :k="{ ar: 'أيام العقد في الشهر', en: 'Contract days in the month' }" :v="`${w.daysCovered ?? '—'} / ${w.daysInMonth ?? '—'}`" ltr />
        <KV :k="{ ar: 'إجازة غير مدفوعة (يوم)', en: 'Unpaid leave (days)' }" :v="w.unpaidDays || 0" ltr />
        <KV :k="{ ar: 'غياب (يوم)', en: 'Absence (days)' }" :v="w.absenceDays || 0" ltr />
        <KV :k="{ ar: 'إضافي (ساعة)', en: 'Overtime (h)' }" :v="hm(w.overtimeMinutes)" ltr />
        <KV :k="{ ar: 'تأخير (ساعة)', en: 'Late (h)' }" :v="hm(w.lateMinutes)" ltr />
        <KV :k="{ ar: 'خروج مبكر (ساعة)', en: 'Early leave (h)' }" :v="hm(w.earlyMinutes)" ltr />
      </div>

      <div class="card-title mb-1 mt-4">{{ t('كيف حُسبت', 'How it was computed') }}</div>
      <div class="kv-grid">
        <KV :k="{ ar: 'العقد', en: 'Contract' }"><span class="num ltr">{{ slip.contract?.number || ex.contract?.number || '—' }}</span><span v-if="slip.contract" class="text-[9.5px] text-muted"> · {{ contractType(slip.contract.type) }} · <span class="num ltr inline-block">{{ fmtDay(slip.contract.startDate) }} – {{ slip.contract.endDate ? fmtDay(slip.contract.endDate) : t('مفتوح', 'open') }}</span></span></KV>
        <KV :k="{ ar: 'المدة المحسوبة', en: 'Days paid' }"><span class="num ltr">{{ fmtDay(ex.contract?.from || slip.dateFrom) }} – {{ fmtDay(ex.contract?.to || slip.dateTo) }}</span></KV>
        <KV :k="{ ar: 'التناسب', en: 'Proration' }" :v="ex.proration?.rule === 'whole month' ? t('الشهر كاملًا', 'The whole month') : t('المبلغ الشهري × أيام العقد ÷ أيام الشهر', 'Monthly × contract days ÷ days of the month')" />
        <KV :k="{ ar: 'هيكل الراتب', en: 'Pay structure' }"><span dir="auto">{{ slip.structure ? pname(slip.structure) : t('الافتراضي', 'Default') }}</span></KV>
        <KV :k="{ ar: 'أيام الشهر لأجر اليوم', en: 'Month days for the daily rate' }" :v="ex.parameters?.monthDays ?? '—'" ltr />
        <KV :k="{ ar: 'ساعات اليوم في جدوله', en: 'Hours of his working day' }" :v="hm(ex.parameters?.minutesPerDay)" ltr />
        <KV v-if="ex.gosi" :k="{ ar: 'التأمينات الاجتماعية', en: 'Social insurance' }"><span class="num ltr">{{ ex.gosi.scheme }} · {{ ex.gosi.employeePct }}% / {{ ex.gosi.employerPct }}% · {{ t('الوعاء', 'base') }} {{ money(ex.gosi.base) }}</span></KV>
        <KV :k="{ ar: 'طريقة الصرف', en: 'Paid by' }" :v="bi(own(PAY_METHOD, slip.paymentMethod) || { ar: slip.paymentMethod, en: slip.paymentMethod })" />
        <KV v-if="ex.sheets?.length" :k="{ ar: 'كشوف الحضور', en: 'Attendance sheets' }"><span class="num ltr">{{ ex.sheets.join(', ') }}</span></KV>
        <KV v-if="ex.unpaidLeaves?.length" :k="{ ar: 'إجازات غير مدفوعة', en: 'Unpaid leaves' }"><span class="num ltr">{{ ex.unpaidLeaves.join(', ') }}</span></KV>
        <KV v-if="ex.claims?.length" :k="{ ar: 'مطالبات معوَّضة', en: 'Claims reimbursed' }"><span class="num ltr">{{ ex.claims.join(', ') }}</span></KV>
        <KV v-if="ex.adjustments" :k="{ ar: 'تسويات', en: 'Adjustments' }" :v="ex.adjustments" ltr />
      </div>
      <div v-if="slip.source === 'odoo'" class="hint purple">{{ t('قسيمة منقولة من أودو بأرقامها كما هي.', 'A payslip brought from Odoo with its own figures.') }}</div>

      <!-- the printed copy (shown only while printing) -->
      <Teleport to="body">
        <div class="payslip-print" :dir="t('rtl', 'ltr')">
          <h2>{{ t('قسيمة راتب', 'Payslip') }} {{ fmtPeriod(slip.period) }} — {{ slip.number }}</h2>
          <p>{{ pname(slip.employee) }} · {{ slip.employee?.code }}<template v-if="slip.department"> · {{ pname(slip.department) }}</template> · {{ bi(own(RUN_STATE, slip.state) || { ar: slip.state, en: slip.state }) }}</p>
          <table>
            <thead><tr><th>{{ t('البند', 'Item') }}</th><th>{{ t('الفئة', 'Kind') }}</th><th>{{ t('كيف حُسب', 'How') }}</th><th>{{ t('المبلغ', 'Amount') }}</th></tr></thead>
            <tbody>
              <tr v-for="l in lines" :key="l.id" :class="{ sum: l.category === 'gross' || l.category === 'net' }">
                <td>{{ pname(l) }}</td><td>{{ bi(own(COMPONENT_CATEGORY, l.category) || { ar: l.category, en: l.category }) }}</td><td>{{ explainLine(l) || '' }}</td><td class="amt">{{ money(l.total) }}</td>
              </tr>
            </tbody>
          </table>
          <p>{{ t('أيام العقد', 'Contract days') }} {{ w.daysCovered }}/{{ w.daysInMonth }} · {{ t('غياب', 'absence') }} {{ w.absenceDays || 0 }} · {{ t('إضافي', 'overtime') }} {{ hm(w.overtimeMinutes, false) }} · {{ t('تأخير', 'late') }} {{ hm(w.lateMinutes, false) }}<template v-if="slip.contract"> · {{ t('العقد', 'contract') }} {{ slip.contract.number }}</template></p>
          <p class="net">{{ t('صافي الراتب', 'Net pay') }}: {{ money(slip.net) }} {{ slip.currency }}</p>
        </div>
      </Teleport>
    </template>

    <template v-if="slip" #footer>
      <div class="row wrap"><Btn tone="dark" :label="{ ar: 'طباعة القسيمة', en: 'Print the payslip' }" @click="print" /><span class="grow" /><Btn tone="soft" :label="{ ar: 'إغلاق', en: 'Close' }" @click="emit('close')" /></div>
    </template>
  </Drawer>
</template>

<style>
.payslip-print { display: none; }
@media print {
  body.printing-payslip > *:not(.payslip-print) { display: none !important; }
  body.printing-payslip .payslip-print { display: block !important; padding: 8mm; font-size: 11px; color: #000; }
  .payslip-print h2 { font-size: 15px; font-weight: 800; margin: 0 0 4px; }
  .payslip-print table { width: 100%; border-collapse: collapse; margin: 8px 0; }
  .payslip-print th, .payslip-print td { border: 1px solid #bbb; padding: 4px 6px; text-align: start; vertical-align: top; }
  .payslip-print td.amt { direction: ltr; text-align: end; white-space: nowrap; font-weight: 700; }
  .payslip-print tr.sum td { background: #f1f1f1; font-weight: 800; }
  .payslip-print .net { font-size: 14px; font-weight: 800; }
}
</style>
