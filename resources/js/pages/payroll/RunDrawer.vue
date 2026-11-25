<script setup>
// One payroll run: GET /payroll/runs/{id|number} → the row (figures, can) + skipped [{ contract, employee, reason, payslip? }],
// warnings [{ code, count }], approvalNote, closeReason, paymentRef/Account/Date, accountingDate, journals, payslips, history.
//   draft ──submit──▶ review ──approve──▶ approved ──post──▶ posted ──pay──▶ paid       (return → draft, cancel, unpay, reverse)
// The action bar follows `can`: compute · submit · return { reason } · cancel { reason? } · approve { note? } (not by the
// person who submitted it: `can.selfApproval` says why) · post { date? } · pay { bankAccount, reference, date? } ·
// unpay { reason } · reverse { reason } · the transfer listing (its own drawer).
// The «القيد» pane reads GET /payroll/runs/{id}/journal: the lines posting would write, `missing` posting keys, `balanced`.
//   <RunDrawer :number="numberOrNull" @close="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, Tabs, Timeline } from '@/components';
import { t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import RunBooksForm from './RunBooksForm.vue';
import RunJournal from './RunJournal.vue';
import TransferDrawer from './TransferDrawer.vue';
import { FROM_ODOO, RUN_STATE, fmtAt, fmtDay, fmtPeriod, money, own, pname, skipText, usePayrollNav, warningText } from './payroll';

const props = defineProps({
  number: { type: String, default: null },
});
const emit = defineEmits(['close']);

const nav = usePayrollNav();
const q = useGet(() => (props.number ? `/payroll/runs/${encodeURIComponent(props.number)}` : null));
const run = computed(() => (props.number ? q.data.value || null : null));
const can = computed(() => run.value?.can || {});
const act = useAction({ invalidate: ['payroll'] });
const pane = ref('payslips');
const books = ref(null); // 'post' | 'pay'
const transfer = ref(false);
watch(() => props.number, () => { pane.value = 'payslips'; books.value = null; transfer.value = false; act.clearError(); });

const panes = computed(() => [
  { k: 'payslips', label: { ar: 'القسائم', en: 'Payslips' }, badge: run.value?.slipCount || null },
  { k: 'journal', label: { ar: 'القيد', en: 'Journal' } },
  { k: 'history', label: { ar: 'السجل', en: 'History' } },
]);
const url = (what) => `/payroll/runs/${encodeURIComponent(run.value.id)}/${what}`;

async function compute() { await act.run(() => api.postIdempotent(url('compute')), { success: t('أُعيد حساب المسير', 'Run computed again') }); }
async function submit() {
  if (!(await confirm({ title: { ar: `إرسال المسير ${run.value.number} للاعتماد؟`, en: `Submit ${run.value.number} for approval?` }, sub: { ar: 'يُحسب مرة أخيرة ثم يعتمده شخص آخر بصلاحية اعتماد الرواتب', en: 'It is computed one last time, then someone else with payroll.approve approves it' }, tone: 'primary' }))) return;
  await act.run(() => api.postIdempotent(url('submit')), { success: t('أُرسل المسير للاعتماد', 'Run submitted') });
}
async function sendBack() {
  const reason = await ask({ title: { ar: `إعادة المسير ${run.value.number} للتصحيح`, en: `Return ${run.value.number}` }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, okLabel: { ar: 'إعادة', en: 'Return' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('return'), { reason }), { success: t('أُعيد المسير مسودة', 'Run returned to draft') });
}
async function cancel() {
  const reason = await ask({ title: { ar: `إلغاء المسير ${run.value.number}؟`, en: `Cancel ${run.value.number}?` }, sub: { ar: 'تتحرر عقوده لهذا الشهر لمسير آخر', en: 'Its contracts’ month is freed for another run' }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إلغاء المسير', en: 'Cancel the run' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('cancel'), reason ? { reason } : undefined), { success: t('أُلغي المسير', 'Run cancelled') });
}
async function approve() {
  const note = await ask({ title: { ar: `اعتماد المسير ${run.value.number}`, en: `Approve ${run.value.number}` }, sub: { ar: `${run.value.slipCount} قسيمة · الصافي ${money(run.value.net)} — بعد الاعتماد لا يتغيّر`, en: `${run.value.slipCount} payslips · net ${money(run.value.net)} — it does not change after approval` }, label: { ar: 'ملاحظة (اختيارية)', en: 'Note (optional)' }, okLabel: { ar: 'اعتماد', en: 'Approve' }, tone: 'primary' });
  if (note === null) return;
  await act.run(() => api.postIdempotent(url('approve'), note ? { note } : undefined), { success: t('اعتُمد المسير — القسائم متاحة لأصحابها', 'Run approved — the payslips are available to their owners') });
}
async function unpay() {
  const reason = await ask({ title: { ar: `التراجع عن صرف ${run.value.number}`, en: `Take back the payment of ${run.value.number}` }, sub: { ar: 'يُعكس قيد الصرف وتعود المطالبات بانتظار السداد', en: 'The payment journal is reversed and the claims wait for payment again' }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, okLabel: { ar: 'تراجع', en: 'Take back' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('unpay'), { reason }), { success: t('تُرُوجِع عن الصرف', 'Payment taken back') });
}
async function reverse() {
  const reason = await ask({ title: { ar: `عكس المسير ${run.value.number}`, en: `Reverse ${run.value.number}` }, sub: { ar: 'كأن المسير لم يكن: يُعكس قيده، وتتحرر عقود الشهر، وتعود تسوياته منتظرة', en: 'As if it never happened: its journal is reversed, the month is freed, its adjustments wait again' }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, okLabel: { ar: 'عكس المسير', en: 'Reverse' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('reverse'), { reason }), { success: t('عُكس المسير', 'Run reversed') });
}

const slipColumns = [
  { key: 'number', header: { ar: 'القسيمة', en: 'Payslip' }, width: '120px', kind: 'id' },
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(140px,1.5fr)' },
  { key: 'gross', header: { ar: 'الإجمالي', en: 'Gross' }, width: '96px', kind: 'num', value: (r) => money(r.gross) },
  { key: 'deductions', header: { ar: 'الاستقطاع', en: 'Deductions' }, width: '92px', kind: 'num', value: (r) => money(r.deductions) },
  { key: 'net', header: { ar: 'الصافي', en: 'Net' }, width: '100px', kind: 'num', value: (r) => money(r.net) },
  { key: 'warn', header: { ar: 'تنبيه', en: 'Warning' }, width: '60px', bare: true },
];
const history = computed(() => (run.value?.history || []).map((h) => ({ at: h.at, by: h.by, note: h.note, label: own(RUN_STATE, h.to) || { ar: h.to, en: h.to }, color: (own(RUN_STATE, h.to) || {}).fg })));
const anyAction = computed(() => ['compute', 'submit', 'sendBack', 'cancel', 'approve', 'selfApproval', 'post', 'pay', 'unpay', 'reverse', 'transfer'].some((k) => can.value[k]));
</script>

<template>
  <Drawer :open="!!number" :width="820" @close="emit('close')">
    <template #title><span dir="auto">{{ run ? run.name || run.number : number }}</span></template>
    <template #sub><span v-if="run"><span class="num ltr inline-block">{{ run.number }}</span> · {{ fmtPeriod(run.period) }}<template v-if="run.department"> · <span dir="auto">{{ pname(run.department) }}</span></template><template v-if="run.externalRef"> · <span class="num ltr inline-block">Odoo #{{ run.externalRef }}</span></template></span></template>
    <template v-if="run" #headExtra><Chip :map="RUN_STATE" :k="run.state" /></template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[260px]" />
    <template v-if="run">
      <div v-if="run.source === 'odoo'" class="hint purple !mt-0 mb-3">{{ t('مسير منقول من أودو: تاريخ يُعرض بأرقامه كما هي، ودفاتره هناك.', 'A run brought from Odoo: history shown with its own figures; its books are there.') }}</div>
      <div class="grid grid-cols-6 gap-2 max-[767px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ run.slipCount }}</div><div class="tile-l">{{ t('قسائم', 'Payslips') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(run.gross) }}</div><div class="tile-l">{{ t('الإجمالي', 'Gross') }}</div></div>
        <div class="tile red"><div class="tile-v">{{ money(run.deductions) }}</div><div class="tile-l">{{ t('الاستقطاعات', 'Deductions') }}</div></div>
        <div class="tile teal"><div class="tile-v">{{ money(run.reimbursements) }}</div><div class="tile-l">{{ t('تعويضات المصروفات', 'Reimbursements') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ money(run.net) }}</div><div class="tile-l">{{ t('الصافي', 'Net') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ money(run.employerCost) }}</div><div class="tile-l">{{ t('حصة المنشأة', 'Employer share') }}</div></div>
      </div>

      <div v-if="run.warnings?.length" class="hint amber">
        <b>{{ t('تنبيهات', 'Warnings') }}:</b>
        <ul class="mt-1 list-disc ps-5"><li v-for="w in run.warnings" :key="w.code">{{ warningText(w.code) }} — <span class="num">{{ w.count }}</span> {{ t('قسيمة', 'payslip(s)') }}</li></ul>
      </div>
      <div v-if="run.skipped?.length" class="hint">
        <b>{{ t(`عقود لم تدخل المسير (${run.skipped.length})`, `Contracts left out (${run.skipped.length})`) }}:</b>
        <ul class="mt-1 list-disc ps-5">
          <li v-for="(s, i) in run.skipped" :key="i"><span class="num ltr inline-block">{{ s.employee }} · {{ s.contract }}</span> — {{ skipText(s.reason) }}<template v-if="s.payslip"> (<button type="button" class="cell-id" @click="nav.open('slip', s.payslip)">{{ s.payslip }}</button>)</template></li>
        </ul>
      </div>
      <div v-if="can.selfApproval" class="hint amber">{{ t('أنت من أرسل هذا المسير للاعتماد: فصل المسؤوليات يمنعك من اعتماده — يعتمده شخص آخر يحمل صلاحية اعتماد الرواتب.', 'You submitted this run: segregation of duties keeps you from approving it — another holder of payroll.approve does.') }}</div>
      <div v-if="run.closeReason" class="hint amber"><b>{{ run.state === 'reversed' ? t('سبب العكس', 'Why reversed') : t('سبب الإلغاء', 'Why cancelled') }}:</b> <span dir="auto">{{ run.closeReason }}</span><template v-if="run.reversalJournalNumber"> · {{ t('قيد عكسي', 'reversal journal') }} <span class="num ltr inline-block">{{ run.reversalJournalNumber }}</span></template></div>

      <div class="kv-grid mt-3">
        <KV :k="{ ar: 'حُسب', en: 'Computed' }" :v="fmtAt(run.computedAt)" ltr />
        <KV v-if="run.submittedBy" :k="{ ar: 'أرسله', en: 'Submitted by' }"><span dir="auto">{{ run.submittedBy }}</span> · <span class="num ltr">{{ fmtAt(run.submittedAt) }}</span></KV>
        <KV v-if="run.approvedBy" :k="{ ar: 'اعتمده', en: 'Approved by' }"><span dir="auto">{{ run.approvedBy }}</span> · <span class="num ltr">{{ fmtAt(run.approvedAt) }}</span><template v-if="run.approvalNote"> — <span dir="auto">{{ run.approvalNote }}</span></template></KV>
        <KV v-if="run.journalNumber" :k="{ ar: 'قيد الترحيل', en: 'Posting journal' }"><span class="num ltr">{{ run.journalNumber }}</span> · <span class="num ltr">{{ fmtDay(run.accountingDate) }}</span></KV>
        <KV v-if="run.paymentRef" :k="{ ar: 'الصرف', en: 'Payment' }"><span class="num ltr">{{ run.paymentRef }}</span> · <span class="num ltr">{{ run.paymentAccount }}</span> · <span class="num ltr">{{ fmtDay(run.paymentDate) }}</span><template v-if="run.paymentJournalNumber"> · <span class="num ltr">{{ run.paymentJournalNumber }}</span></template></KV>
        <KV :k="{ ar: 'أنشأه', en: 'Made by' }"><span dir="auto">{{ run.createdBy || '—' }}</span> · <span class="num ltr">{{ fmtAt(run.createdAt) }}</span></KV>
        <KV v-if="run.source === 'odoo'" :k="{ ar: 'المصدر', en: 'Origin' }"><Chip small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" /></KV>
      </div>

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-2.5 mt-4" />
      <div v-if="pane === 'payslips'" class="card">
        <DataTable :columns="slipColumns" :rows="run.payslips" :page-size="25" :row-key="(r) => r.number" :selected-key="nav.get('slip') || null"
                   :empty-text="{ ar: 'لا قسائم في المسير — لا عقد سارٍ في هذا الشهر ضمن نطاقه.', en: 'No payslip — no contract is in force this month within its scope.' }" @row-click="(r) => nav.open('slip', r.number)">
          <template #cell-employee="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }}<template v-if="row.daysCovered !== row.daysInMonth"> · {{ row.daysCovered }}/{{ row.daysInMonth }}</template></div></div></template>
          <template #cell-warn="{ row }"><span v-if="row.warnings?.length" class="text-[11px] font-bold text-warn" :title="row.warnings.map(warningText).join(' · ')">⚠ {{ row.warnings.length }}</span><span v-else class="text-faint">—</span></template>
        </DataTable>
      </div>
      <RunJournal v-else-if="pane === 'journal'" :run="run" />
      <Timeline v-else :items="history" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }" />
    </template>

    <template v-if="run && anyAction" #footer>
      <div class="row wrap">
        <Btn v-if="can.approve" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
        <Btn v-if="can.selfApproval" tone="success" disabled :label="{ ar: 'اعتماد', en: 'Approve' }" :title="t('لا تعتمد مسيرًا أرسلته', 'You cannot approve a run you submitted')" />
        <Btn v-if="can.submit" tone="primary" :loading="act.pending.value" :label="{ ar: 'إرسال للاعتماد', en: 'Submit for approval' }" @click="submit" />
        <Btn v-if="can.compute" tone="soft" :loading="act.pending.value" :label="{ ar: 'إعادة الحساب', en: 'Compute again' }" @click="compute" />
        <Btn v-if="can.post" tone="primary" :label="{ ar: 'ترحيل للدفاتر', en: 'Post to the books' }" @click="books = 'post'" />
        <Btn v-if="can.pay" tone="success" :label="{ ar: 'تسجيل الصرف', en: 'Record payment' }" @click="books = 'pay'" />
        <Btn v-if="can.transfer" tone="softBlue" :label="{ ar: 'ملف التحويل البنكي', en: 'Bank transfer listing' }" @click="transfer = true" />
        <span class="grow" />
        <Btn v-if="can.sendBack" tone="softAmber" :label="{ ar: 'إعادة للتصحيح', en: 'Return' }" @click="sendBack" />
        <Btn v-if="can.unpay" tone="softAmber" :label="{ ar: 'التراجع عن الصرف', en: 'Take back the payment' }" @click="unpay" />
        <Btn v-if="can.reverse" tone="dangerOutline" :label="{ ar: 'عكس المسير', en: 'Reverse' }" @click="reverse" />
        <Btn v-if="can.cancel" tone="ghost" :label="{ ar: 'إلغاء المسير', en: 'Cancel run' }" @click="cancel" />
      </div>
    </template>
  </Drawer>

  <RunBooksForm :run="books ? run : null" :mode="books || 'post'" @close="books = null" />
  <TransferDrawer :run="transfer ? run : null" @close="transfer = false" />
</template>
