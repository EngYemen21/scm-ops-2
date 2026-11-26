<script setup>
// One expense claim: GET /hr/claims/{number} — its lines with their receipts, its totals, its story, and the buttons
// of what can be done to it NOW by the person looking (`claim.can`, computed by the server from the state, the
// permissions and whose claim it is; the server checks again on every call):
//   the claimant   edit · submit · withdraw · cancel · attach a receipt
//   a reviewer     approve · refuse (reason) · return for correction (reason) · take an approval back
//   finance        post to the books (one expense voucher per line) · pay · take a payment back · reverse the posting
// A claim that came from Odoo is history: shown with Odoo's state and figures, with no buttons while the section follows Odoo.
//   <ClaimDrawer :number="nav.get('claim') || null" @close />
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Timeline } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import ClaimForm from './ClaimForm.vue';
import ClaimPayForm from './ClaimPayForm.vue';
import ClaimPostForm from './ClaimPostForm.vue';
import HrFilePick from './HrFilePick.vue';
import HrFiles from './HrFiles.vue';
import { CLAIM_STATE, CLAIM_STEP, FROM_ODOO, PAID_BY, TONE, VOUCHER_STATUS, claimOwner, claimOwnerCode, fmtAt, fmtDay, money, pname, useHrNav } from './shared';

const props = defineProps({ number: { type: String, default: null } });
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = useHrNav();
const detail = useGet(() => (props.number ? `/hr/claims/${encodeURIComponent(props.number)}` : null));
const c = computed(() => (detail.data.value && (detail.data.value.number === props.number || detail.data.value.id === props.number) ? detail.data.value : null));
const act = useAction({ invalidate: ['hr', 'finance'] });
const editing = ref(false);
const posting = ref(false);
const paying = ref(false);
const reviewer = computed(() => auth.can('expense.review'));
const anyAction = computed(() => !!c.value && Object.entries(c.value.can).some(([k, v]) => v && k !== 'selfReview' && k !== 'addReceipt'));

const STEP_COLOR = { draft: TONE.neutral.fg, submitted: TONE.warn.fg, approved: TONE.blue.fg, posted: TONE.teal.fg, paid: TONE.ok.fg, refused: TONE.bad.fg, cancelled: TONE.off.fg };
const history = computed(() => (c.value?.history || []).map((h) => ({ at: h.at, by: h.by || (h.origin === 'odoo' ? t('أودو', 'Odoo') : null), note: h.note, label: CLAIM_STEP[h.to] || h.to, color: STEP_COLOR[h.to] })));

const call = (action, body, success) => act.run(() => api.postIdempotent(`/hr/claims/${c.value.id}/${action}`, body), { success });
/** Asks for the reason an act needs (at least 5 characters — the server refuses less) and then does it. */
async function withReason(action, title, okLabel, success, tone = 'dark') {
  const reason = await ask({ title, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, tone, okLabel });
  if (reason === null) return;
  await call(action, { reason }, success);
}
async function submit() {
  const n = c.value.linesWithoutReceipt;
  if (!(await confirm({ title: { ar: `إرسال ${c.value.number} للمراجعة؟`, en: `Submit ${c.value.number} for review?` },
    sub: n ? { ar: `${n} سطرًا بلا إيصال — يمكنك إرفاقه الآن أو بعد الإرسال، وقد يعيدها المراجع بسببه.`, en: `${n} line(s) have no receipt — attach it now or after submitting; the reviewer may return the claim for it.` } : { ar: 'لن تستطيع تعديل السطور بعد الإرسال إلا إذا سحبتها أو أُعيدت إليك.', en: 'You will not be able to change the lines after submitting unless you withdraw it or it is returned to you.' }, tone: 'dark' }))) return;
  await call('submit', undefined, t('أُرسلت للمراجعة', 'Submitted for review'));
}
const withdraw = () => call('withdraw', undefined, t('سُحبت المطالبة — عادت مسودة', 'Withdrawn — it is a draft again'));
async function cancel() {
  const reason = await ask({ title: { ar: `إلغاء المطالبة ${c.value.number}`, en: `Cancel claim ${c.value.number}` }, sub: { ar: 'تبقى في السجل ملغاة؛ لا يُحذف شيء.', en: 'It stays on record as cancelled; nothing is deleted.' }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'إلغاء المطالبة', en: 'Cancel the claim' } });
  if (reason === null) return;
  await call('cancel', reason ? { reason } : undefined, t('أُلغيت المطالبة', 'Claim cancelled'));
}
async function approve() {
  const note = await ask({ title: { ar: `اعتماد ${c.value.number} — ${money(c.value.total)} ر.س`, en: `Approve ${c.value.number} — SAR ${money(c.value.total)}` }, label: { ar: 'ملاحظة (اختياري)', en: 'Note (optional)' }, okLabel: { ar: 'اعتماد', en: 'Approve' } });
  if (note === null) return;
  await call('approve', note ? { note } : undefined, t('اعتُمدت المطالبة', 'Claim approved'));
}
const refuse = () => withReason('refuse', { ar: `رفض المطالبة ${c.value.number}`, en: `Refuse claim ${c.value.number}` }, { ar: 'رفض', en: 'Refuse' }, t('رُفضت المطالبة', 'Claim refused'), 'danger');
const sendBack = () => withReason('return', { ar: `إعادة ${c.value.number} لصاحبها للتصحيح`, en: `Return ${c.value.number} for correction` }, { ar: 'إعادة للتصحيح', en: 'Return' }, t('أُعيدت لصاحبها', 'Returned to its owner'));
const reopen = () => withReason('reopen', { ar: `التراجع عن اعتماد ${c.value.number}`, en: `Take back the approval of ${c.value.number}` }, { ar: 'تراجع عن الاعتماد', en: 'Take back' }, t('عادت للمراجعة', 'Back to review'));
const unpay = () => withReason('unpay', { ar: `التراجع عن سداد ${c.value.number}`, en: `Take back the payment of ${c.value.number}` }, { ar: 'تراجع عن السداد', en: 'Take back' }, t('عادت بانتظار السداد', 'Waiting for payment again'));
const unpost = () => withReason('unpost', { ar: `عكس ترحيل ${c.value.number} — تُلغى سنداتها وتُعكس قيودها`, en: `Reverse the posting of ${c.value.number} — its vouchers are voided` }, { ar: 'عكس الترحيل', en: 'Reverse' }, t('عُكس الترحيل — المطالبة معتمدة بانتظار ترحيل جديد', 'Posting reversed — approved, to be posted again'), 'danger');

const addReceipt = (line, file) => file && act.run(() => api.postIdempotent(`/hr/claims/${c.value.id}/lines/${line.id}/receipts`, { fileName: file.fileName, mime: file.mime, base64: file.base64 }), { success: t('أُرفق الإيصال', 'Receipt attached') });
async function removeReceipt(file) {
  if (!(await confirm({ title: { ar: `إزالة الإيصال «${file.name}»؟`, en: `Remove the receipt “${file.name}”?` }, tone: 'danger' }))) return;
  await act.run(() => api.del(`/hr/claims/${c.value.id}/receipts/${file.id}`), { success: t('أُزيل الإيصال', 'Receipt removed') });
}
</script>

<template>
  <Drawer :open="!!number" :title="c ? c.number : number" :sub="c ? c.title : null" :width="660" @close="emit('close')">
    <ErrorBanner :error="detail.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="!c && !detail.error.value" class="skel h-[200px]" />
    <template v-if="c">
      <div class="row wrap mb-3">
        <Chip :map="CLAIM_STATE" :k="c.state" />
        <Chip v-if="c.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        <span class="grow" />
        <Btn v-if="c.employee && auth.can('page.hr')" size="sm" tone="ghost" :label="{ ar: 'بطاقة الموظف', en: 'Employee card' }" @click="nav.openEmployee(c.employee.code)" />
      </div>

      <div v-if="c.locked" class="banner teal"><div>{{ t('هذه المطالبة مصدرها أودو والقسم يتبع أودو: تُعرض بحالتها وأرقامها كما في أودو، ولا تُعدَّل ولا تُرحَّل هنا.', 'This claim comes from Odoo and the section follows Odoo: it is shown with Odoo’s state and figures, and is neither changed nor posted here.') }}</div></div>
      <div v-else-if="c.postedIn === 'odoo'" class="banner neutral"><div>{{ t('رُحِّلت هذه المطالبة في دفاتر أودو — لا قيد لها في دفاتر هذا النظام، ولا تُرحَّل ثانية.', 'This claim was posted in Odoo’s books — it has no entry in these books and is not posted again.') }}</div></div>
      <div v-if="c.state === 'draft' && c.decisionNote" class="banner amber"><div>{{ t(`أعادها ${c.decidedBy} للتصحيح:`, `Returned by ${c.decidedBy} for correction:`) }} {{ c.decisionNote }}</div></div>
      <div v-if="c.state === 'refused'" class="banner red"><div>{{ t(`رفضها ${c.decidedBy || '—'}:`, `Refused by ${c.decidedBy || '—'}:`) }} {{ c.decisionNote || '—' }}</div></div>
      <div v-if="c.can.selfReview" class="banner neutral"><div>{{ t('هذه مطالبة تخصّك أو أدخلتها أنت: يقرّرها مراجع آخر (فصل المسؤوليات).', 'This claim is yours or was entered by you: another reviewer decides it (segregation of duties).') }}</div></div>
      <div v-if="reviewer && c.state === 'approved' && !c.can.post && !c.locked" class="banner neutral"><div>{{ t('معتمدة — ترحيلها إلى الدفاتر وسدادها من عمل المالية (صلاحية سندات المصروف).', 'Approved — posting it to the books and paying it is finance’s work (the expense-voucher permission).') }}</div></div>

      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'صاحب المصروف', en: 'Whose expenses' }"><span dir="auto">{{ claimOwner(c) }}</span> <span class="num ltr text-[9.5px] text-muted">{{ claimOwnerCode(c) }}</span></KV>
        <KV :k="{ ar: 'القسم', en: 'Department' }" :v="c.department ? pname(c.department) : '—'" />
        <KV :k="{ ar: 'أُنشئت', en: 'Created' }" :v="`${fmtDay(c.createdAt)}${c.createdBy ? ` · ${c.createdBy}` : ''}`" />
        <KV v-if="c.paymentRef" :k="{ ar: 'مرجع السداد', en: 'Payment reference' }" :v="c.paymentRef" ltr />
      </div>
      <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div class="rounded-[12px] bg-soft px-3 py-2"><div class="text-[9px] font-extrabold text-faint">{{ t('قبل الضريبة', 'Before VAT') }}</div><div class="num text-[13px] font-bold">{{ money(c.amount) }}</div></div>
        <div class="rounded-[12px] bg-soft px-3 py-2"><div class="text-[9px] font-extrabold text-faint">{{ t('الضريبة', 'VAT') }}</div><div class="num text-[13px] font-bold">{{ money(c.vatAmount) }}</div></div>
        <div class="rounded-[12px] bg-soft px-3 py-2"><div class="text-[9px] font-extrabold text-faint">{{ t('الإجمالي', 'Total') }} · {{ c.currency }}</div><div class="num text-[13px] font-bold">{{ money(c.total) }}</div></div>
        <div class="rounded-[12px] bg-violet-soft px-3 py-2"><div class="text-[9px] font-extrabold text-violet">{{ t('مستحق للموظف', 'Due to the employee') }}</div><div class="num text-[13px] font-bold text-violet">{{ money(c.employeeDue) }}</div></div>
      </div>

      <div class="card-title mb-1.5">{{ t('السطور', 'Lines') }}<span class="card-count num ms-2">{{ c.lines.length }}</span></div>
      <div class="col mb-4 !gap-2">
        <div v-for="l in c.lines" :key="l.id" class="rounded-[14px] border border-line bg-white p-3">
          <div class="row wrap">
            <span class="cell-date">{{ fmtDay(l.date) }}</span>
            <span class="text-[11px] font-extrabold">{{ l.category ? pname(l.category) : t('بلا فئة', 'No category') }}</span>
            <Chip small :map="PAID_BY" :k="l.paidBy" />
            <span class="grow" />
            <span class="num text-[12.5px] font-bold">{{ money(l.total) }}</span>
          </div>
          <div class="mt-1 text-[11px] leading-[1.7] text-sec" dir="auto">{{ l.description }}</div>
          <div class="mt-1 text-[9.5px] text-muted">{{ t('قبل الضريبة', 'Before VAT') }} <span class="num">{{ money(l.amount) }}</span> · {{ t('ضريبة', 'VAT') }} <span class="num">{{ l.vatPct }}%</span> = <span class="num">{{ money(l.vatAmount) }}</span></div>
          <div class="row wrap mt-1.5">
            <HrFiles :files="l.receipts" :removable="c.can.edit" :empty="c.source === 'odoo' ? null : t('بلا إيصال', 'No receipt')" @remove="removeReceipt" />
            <HrFilePick v-if="c.can.addReceipt" small :model-value="null" @update:model-value="(f) => addReceipt(l, f)" />
          </div>
          <!-- what the line is charged to and its voucher: the reviewers' part (the answer carries it only for them) -->
          <div v-if="'accountCode' in l" class="row wrap mt-1.5 border-t border-line pt-1.5 text-[9.5px] text-muted">
            <span>{{ t('الحساب', 'Account') }}: <span class="num ltr">{{ l.accountCode || '—' }}</span></span>
            <span v-if="l.sourceAccount">· {{ t('في أودو', 'in Odoo') }}: <span dir="auto">{{ l.sourceAccount }}</span></span>
            <template v-if="l.voucher">
              <span>· {{ t('السند', 'Voucher') }}</span>
              <RouterLink v-if="auth.can('gl.view')" class="cell-id" :to="`/finance?tab=expenses&expense=${encodeURIComponent(l.voucher.number)}`">{{ l.voucher.number }}</RouterLink>
              <span v-else class="cell-id">{{ l.voucher.number }}</span>
              <Chip v-if="l.voucher.status" small :map="VOUCHER_STATUS" :k="l.voucher.status" />
              <RouterLink v-if="l.voucher.journal && auth.can('gl.view')" class="cell-id" :to="`/jv/${encodeURIComponent(l.voucher.journal)}`">{{ l.voucher.journal }}</RouterLink>
            </template>
          </div>
        </div>
      </div>

      <template v-if="c.files.length">
        <div class="card-title mb-1">{{ t('مرفقات التقرير في أودو', 'Files attached to the report in Odoo') }}</div>
        <div class="mb-4"><HrFiles :files="c.files" /></div>
      </template>
      <div v-if="c.notes" class="hint !mt-0 mb-4 whitespace-pre-line" dir="auto">{{ c.notes }}</div>
      <!-- honest about the books: there is no automated settlement of an accrual yet -->
      <div v-if="reviewer && c.state === 'paid' && c.settlement === 'accrued'" class="hint amber !mt-0 mb-4">
        {{ t(`سُجِّل السداد بمرجع ${c.paymentRef}. المبلغ ${money(c.employeeDue)} مقيَّد في الدفاتر مصروفًا مستحقًا عند الترحيل؛ إقفاله (مدين: مصروفات مستحقة / دائن: البنك أو الصندوق) يتم بقيد من قسم المالية — لا يوجد بعد مسار سداد آلي للمصروفات المستحقة.`, `The payment is recorded with reference ${c.paymentRef}. The amount ${money(c.employeeDue)} was accrued in the books at posting; clearing it (debit accrued expenses / credit the bank or cash box) is a journal made in the finance section — there is no automated settlement of accrued expenses yet.`) }}
      </div>
      <div v-if="c.settlement === 'bank'" class="hint green !mt-0 mb-4">{{ t('صُرف التعويض عند الترحيل من الصندوق / البنك: السندات نفسها هي السداد.', 'The reimbursement was paid at posting from the cash box / bank: the vouchers themselves are the payment.') }}</div>
      <div v-if="c.settlement === 'none'" class="hint green !mt-0 mb-4">{{ t('كل السطور دفعتها الشركة: لا مبلغ مستحق للموظف، وأُقفلت المطالبة عند الترحيل.', 'Every line was paid by the company: nothing is owed to the employee, and the claim was settled at posting.') }}</div>

      <div class="card-title mb-1">{{ t('مسار المطالبة', 'The story of the claim') }}</div>
      <Timeline :items="history" />
    </template>

    <template v-if="anyAction" #footer>
      <div class="flex flex-wrap gap-2">
        <Btn v-if="c.can.submit" tone="primary" :loading="act.pending.value" :label="{ ar: 'إرسال للمراجعة', en: 'Submit for review' }" @click="submit" />
        <Btn v-if="c.can.edit" tone="outline" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = true" />
        <Btn v-if="c.can.withdraw" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'سحب المطالبة', en: 'Withdraw' }" @click="withdraw" />
        <Btn v-if="c.can.approve" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
        <Btn v-if="c.can.sendBack" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'إعادة للتصحيح', en: 'Return for correction' }" @click="sendBack" />
        <Btn v-if="c.can.refuse" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Refuse' }" @click="refuse" />
        <Btn v-if="c.can.post" tone="primary" :label="{ ar: 'ترحيل إلى الدفاتر', en: 'Post to the books' }" @click="posting = true" />
        <Btn v-if="c.can.pay" tone="success" :label="{ ar: 'تسجيل السداد', en: 'Record the payment' }" @click="paying = true" />
        <Btn v-if="c.can.reopen" tone="soft" :loading="act.pending.value" :label="{ ar: 'تراجع عن الاعتماد', en: 'Take back the approval' }" @click="reopen" />
        <Btn v-if="c.can.unpay" tone="soft" :loading="act.pending.value" :label="{ ar: 'تراجع عن السداد', en: 'Take back the payment' }" @click="unpay" />
        <Btn v-if="c.can.unpost" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'عكس الترحيل', en: 'Reverse the posting' }" @click="unpost" />
        <Btn v-if="c.can.cancel" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء المطالبة', en: 'Cancel the claim' }" @click="cancel" />
      </div>
    </template>
  </Drawer>

  <ClaimForm :open="editing" :claim="c" @close="editing = false" />
  <ClaimPostForm :open="posting" :claim="c" @close="posting = false" />
  <ClaimPayForm :open="paying" :claim="c" @close="paying = false" />
</template>
