<script setup>
// One credit request: where it stands (path of stages, who it waits for, what it still needs), what may be done with it
// now and everything about it. GET /credit/requests/{id} → the card + detail + `can` — a button is shown only for what
// `can` allows (the server checks every move again):
//   submit → POST …/submit · approve → ApproveForm (…/approve) · request changes → …/request-changes { reason }
//   reject → …/reject { reason } · skip → …/skip { reason } · refresh approvers → …/refresh-approvers { reason? }
//   withdraw → …/withdraw { reason? } · cancel → …/cancel { reason } · reopen → …/reopen · apply → …/apply
//   restore the customer → …/restore-customer { reason }
// The panes: details (RequestDetails) · assessment and terms (AssessmentBox) · papers (DocumentsBox) · signature
// (SignatureBox) · approvals and notifications (ApprovalsBox) · timeline and comments (RequestTimeline).
//   <RequestDrawer :request-id="idOrNull" @close="…" @edit="(detail) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, Tabs } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import ApprovalsBox from './ApprovalsBox.vue';
import ApproveForm from './ApproveForm.vue';
import AssessmentBox from './AssessmentBox.vue';
import DocumentsBox from './DocumentsBox.vue';
import RequestDetails from './RequestDetails.vue';
import RequestTimeline from './RequestTimeline.vue';
import SignatureBox from './SignatureBox.vue';
import { money, nameOf } from './credit';
import { APPLY, ORIGIN, OWNED_HELP, SIGNATURE, SLA, decideHelp, pick, stateChip } from './labels';

const props = defineProps({
  /** The request's id; null closes the drawer. */
  requestId: { type: String, default: null },
});
const emit = defineEmits(['close', 'edit']);

const isOpen = computed(() => !!props.requestId);
const q = useGet(() => (props.requestId ? `/credit/requests/${encodeURIComponent(props.requestId)}` : null));
const r = computed(() => (isOpen.value ? q.data.value || null : null));
const can = computed(() => r.value?.can || {});
const act = useAction({ invalidate: ['credit'] });

const pane = ref('details');
const approving = ref(false);
// another request (or none): its own first pane, nothing left open from the previous one
watch(() => props.requestId, () => { pane.value = 'details'; approving.value = false; act.clearError(); });

const missingDocs = computed(() => r.value?.requirements?.documents?.missing?.length || 0);
const panes = computed(() => [
  { k: 'details', label: { ar: 'البيانات', en: 'Details' } },
  { k: 'review', label: { ar: 'التقييم والشروط', en: 'Assessment & terms' } },
  { k: 'documents', label: { ar: 'الوثائق', en: 'Papers' }, badge: missingDocs.value || null },
  { k: 'signature', label: { ar: 'التوقيع', en: 'Signature' } },
  { k: 'approvals', label: { ar: 'الاعتمادات', en: 'Approvals' }, badge: (r.value?.approvals || []).filter((a) => a.state === 'pending' || a.state === 'escalated').length || null },
  { k: 'timeline', label: { ar: 'السجل والتعليقات', en: 'Timeline' } },
]);

const url = (suffix = '') => `/credit/requests/${r.value.id}${suffix}`;
const chip = computed(() => stateChip(r.value));
const slotLabel = (slot) => { const d = (r.value?.documents || []).find((x) => x.slot === slot); return d ? t(d.labelAr, d.labelEn || d.labelAr) : slot; };

// ---- where it stands
const steps = computed(() => r.value?.steps || []);
const waiting = computed(() => {
  const w = r.value?.waitingFor;
  if (!w) return '';
  if (w.requester) return t('بانتظار مقدّم الطلب: إكماله وإرساله', 'Waiting for the requester to complete and send it');
  const names = [...(w.people || []).map(nameOf), ...(w.roles || []).map((x) => `${nameOf(x)} (${t('دور', 'role')})`)];
  if (names.length) return `${t('بانتظار', 'Waiting for')}: ${names.join('، ')}`;
  if (w.anyone) return t('بانتظار أي معتمد لهذه المرحلة', 'Waiting for any approver of this stage');
  return '';
});
const req = computed(() => r.value?.requirements || null);

// ---- the moves
const who = computed(() => r.value?.number || '');
async function submit() {
  if (!(await confirm({ title: { ar: `إرسال الطلب ${who.value} للمراجعة؟`, en: `Send ${who.value} for review?` }, sub: { ar: 'بعد الإرسال لا تُعدَّل بياناته إلا إذا أُعيد إليك للتعديل', en: 'Once sent its values change only if it is returned to you for changes' }, tone: 'primary', okLabel: { ar: 'إرسال', en: 'Send' } }))) return;
  await act.run(() => api.postIdempotent(url('/submit')), { success: t('أُرسل الطلب للمراجعة', 'Request sent for review') });
}
async function withReason(path, { title, label, required = true, tone = 'dark', okLabel, success, optionalKey = 'reason' }) {
  const reason = await ask({ title, label, required, tone, okLabel });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url(path), reason ? { [optionalKey]: reason } : undefined), { success });
}
const requestChanges = () => withReason('/request-changes', { title: { ar: `إعادة ${who.value} لمقدّمه للتعديل`, en: `Return ${who.value} for changes` }, label: { ar: 'ما المطلوب تعديله؟', en: 'What must be changed?' }, okLabel: { ar: 'طلب تعديل', en: 'Request changes' }, success: t('أُعيد الطلب للتعديل', 'Returned for changes') });
const reject = () => withReason('/reject', { title: { ar: `رفض الطلب ${who.value}`, en: `Reject ${who.value}` }, label: { ar: 'سبب الرفض', en: 'Reason for the rejection' }, tone: 'danger', okLabel: { ar: 'رفض', en: 'Reject' }, success: t('رُفض الطلب', 'Request rejected') });
const skip = () => withReason('/skip', { title: { ar: `تجاوز المرحلة الحالية للطلب ${who.value}`, en: `Skip the current stage of ${who.value}` }, label: { ar: 'سبب التجاوز (يُسجَّل في السجل)', en: 'Reason (kept on record)' }, okLabel: { ar: 'تجاوز', en: 'Skip' }, success: t('تُجووزت المرحلة', 'Stage skipped') });
const refreshApprovers = () => withReason('/refresh-approvers', { title: { ar: 'تحديث معتمدي المرحلة الحالية', en: 'Refresh the approvers of the current stage' }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, required: false, okLabel: { ar: 'تحديث', en: 'Refresh' }, success: t('حُدّث المعتمدون', 'Approvers refreshed') });
const withdraw = () => withReason('/withdraw', { title: { ar: `سحب الطلب ${who.value} إلى المسودة`, en: `Withdraw ${who.value} to draft` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, required: false, okLabel: { ar: 'سحب', en: 'Withdraw' }, success: t('سُحب الطلب', 'Request withdrawn') });
const cancel = () => withReason('/cancel', {
  title: { ar: `إلغاء الطلب ${who.value}`, en: `Cancel ${who.value}` }, label: r.value?.state === 'draft' ? { ar: 'السبب (اختياري)', en: 'Reason (optional)' } : { ar: 'سبب الإلغاء', en: 'Reason for cancelling' },
  required: r.value?.state !== 'draft', tone: 'danger', okLabel: { ar: 'إلغاء الطلب', en: 'Cancel the request' }, success: t('أُلغي الطلب', 'Request cancelled'),
});
const restore = () => withReason('/restore-customer', { title: { ar: 'إعادة حد العميل وشروطه إلى ما كانت عليه قبل هذا الطلب', en: 'Restore the customer’s limit and terms to what they were before this request' }, label: { ar: 'السبب', en: 'Reason' }, tone: 'danger', okLabel: { ar: 'إعادة', en: 'Restore' }, success: t('أُعيد العميل لما كان', 'Customer restored') });
async function reopen() {
  if (!(await confirm({ title: { ar: `إعادة فتح الطلب ${who.value} كمسودة؟`, en: `Reopen ${who.value} as a draft?` }, tone: 'dark', okLabel: { ar: 'إعادة فتح', en: 'Reopen' } }))) return;
  await act.run(() => api.postIdempotent(url('/reopen')), { success: t('أُعيد فتح الطلب', 'Request reopened') });
}
async function applyNow() {
  const g = r.value;
  if (!(await confirm({
    title: { ar: `تطبيق الحد المعتمد على العميل ${g.customer?.code || ''}؟`, en: `Apply the granted limit to customer ${g.customer?.code || ''}?` },
    sub: { ar: `الحد ${money(g.approvedCreditLimit, g.currency)} · ${g.approvedPaymentDays || '—'} يوم — تُحفظ القيم السابقة في السجل`, en: `Limit ${money(g.approvedCreditLimit, g.currency)} · ${g.approvedPaymentDays || '—'} days — the previous values are kept on record` },
    tone: 'primary', okLabel: { ar: 'تطبيق', en: 'Apply' },
  }))) return;
  await act.run(() => api.postIdempotent(url('/apply')), { success: t('طُبّق الحد على العميل', 'Limit applied to the customer') });
}
const applied = computed(() => r.value?.applied || null);
const showApplyBox = computed(() => applied.value && (applied.value.status !== 'none' || r.value.state === 'approved'));
// shown inside LTR spans: "before → after"
const fromTo = (c) => (c && typeof c === 'object' && ('from' in c || 'to' in c) ? `${c.from ?? '—'} → ${c.to ?? '—'}` : c ?? '—');
const fromToMoney = (c) => (c && typeof c === 'object' ? `${money(c.from)} → ${money(c.to)}` : '—');
const anyAction = computed(() => ['edit', 'submit', 'decide', 'skip', 'refreshApprovers', 'withdraw', 'cancel', 'reopen', 'apply', 'restore'].some((k) => can.value[k]));
</script>

<template>
  <Drawer :open="isOpen" :width="760" @close="emit('close')">
    <template #title><span dir="auto">{{ r ? r.companyName || r.partnerName || r.number : t('طلب ائتمان', 'Credit request') }}</span></template>
    <template #sub>
      <span v-if="r"><span class="num ltr inline-block">{{ r.number }}</span><template v-if="r.customer"> · <span class="num ltr inline-block">{{ r.customer.code }}</span> <span dir="auto">{{ nameOf(r.customer) }}</span></template><template v-if="r.round > 1"> · {{ t('جولة', 'round') }} <span class="num">{{ r.round }}</span></template></span>
    </template>
    <template v-if="r" #headExtra>
      <span class="inline-flex flex-wrap items-center gap-1">
        <Chip :label="chip" :fg="chip.fg" :bg="chip.bg" />
        <Chip v-if="r.origin === 'odoo'" :label="ORIGIN.odoo" :fg="ORIGIN.odoo.fg" :bg="ORIGIN.odoo.bg" />
      </span>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[240px]" />
    <template v-if="r">
      <div v-if="r.ownedBySource" class="hint purple !mt-0 mb-3">{{ bi(OWNED_HELP) }}</div>

      <!-- the path of stages -->
      <div v-if="steps.length" class="row wrap !gap-1.5">
        <span v-for="s in steps" :key="s.id" class="pill !cursor-default" :class="{ active: s.status === 'current' }"
              :style="s.status === 'done' ? { color: '#1d7a3e', borderColor: '#BFE8CC', background: '#e6f9ec' } : s.status === 'upcoming' ? { opacity: 0.65 } : null"
              :title="[s.requireDocuments ? t('تتطلب الوثائق', 'needs the papers') : '', s.requireSignature ? t('تتطلب التوقيع', 'needs the signature') : '', s.slaHours ? `${t('المدة المستهدفة', 'target')} ${s.slaHours} ${t('س', 'h')}` : ''].filter(Boolean).join(' · ') || null">
          <span v-if="s.status === 'done'">✓ </span><span dir="auto">{{ nameOf(s) }}</span><span v-if="s.requireSignature" class="text-[9px]"> ✍</span>
        </span>
      </div>
      <div v-if="waiting" class="mt-2 text-[10.5px] font-bold text-sec" dir="auto">{{ waiting }}</div>
      <div v-if="r.sla && r.sla !== 'none'" class="row mt-1.5 !gap-1.5 text-[10px] text-muted">
        <Chip small :map="SLA" :k="r.sla" />
        <span v-if="r.slaDeadline">{{ t('موعد المرحلة', 'Stage due') }} <span class="num ltr inline-block">{{ fmtDate(r.slaDeadline) }}</span></span>
        <span v-if="r.stageEnteredAt">· {{ t('دخلها', 'entered') }} <span class="num ltr inline-block">{{ fmtDate(r.stageEnteredAt) }}</span></span>
      </div>

      <!-- what it still needs -->
      <div v-if="req" class="row wrap mt-2.5 !gap-1.5">
        <Chip v-if="req.documents?.required" small :label="req.documents.complete ? { ar: 'الوثائق مكتملة', en: 'Papers complete' } : { ar: `ينقص ${req.documents.missing.length} من الوثائق`, en: `${req.documents.missing.length} paper(s) missing` }"
              :fg="req.documents.complete ? '#1d7a3e' : '#b26a16'" :bg="req.documents.complete ? '#e6f9ec' : '#fbf0dd'" />
        <Chip v-if="req.signature?.required" small :label="req.signature.ok ? { ar: 'التوقيع مكتمل', en: 'Signature done' } : req.signature.stale ? { ar: 'التوقيع على نسخة سابقة', en: 'Signed an earlier version' } : { ar: `التوقيع: ${pick(SIGNATURE, req.signature.status).ar}`, en: `Signature: ${pick(SIGNATURE, req.signature.status).en}` }"
              :fg="req.signature.ok ? '#1d7a3e' : '#b26a16'" :bg="req.signature.ok ? '#e6f9ec' : '#fbf0dd'" />
        <Chip v-if="req.grant?.required" small :label="req.grant.ok ? { ar: 'الحد المعتمد محدد', en: 'Grant set' } : { ar: 'يلزم تحديد الحد المعتمد', en: 'The grant must be set' }"
              :fg="req.grant.ok ? '#1d7a3e' : '#b26a16'" :bg="req.grant.ok ? '#e6f9ec' : '#fbf0dd'" />
      </div>
      <div v-if="req?.documents?.missing?.length" class="mt-1.5 text-[9.5px] text-warn" dir="auto">{{ t('الناقص', 'Missing') }}: {{ req.documents.missing.map(slotLabel).join('، ') }}</div>
      <div v-if="r.missingToSend?.length" class="hint amber">
        <b>{{ t('قبل الإرسال يلزم:', 'Before sending:') }}</b>
        <ul class="mt-1 list-disc ps-4"><li v-for="m in r.missingToSend" :key="m.path" dir="auto">{{ t(m.labelAr, m.labelEn || m.labelAr) }}</li></ul>
      </div>
      <div v-if="!can.decide && can.decideCode && steps.some((s) => s.status === 'current')" class="hint !mt-2">{{ decideHelp(can.decideCode) }}</div>

      <!-- how it ended -->
      <div v-if="r.state === 'rejected' && r.rejectionReason" class="hint amber"><b>{{ t('سبب الرفض', 'Rejected because') }}:</b> <span dir="auto">{{ r.rejectionReason }}</span><span class="text-[9px]"> — {{ r.rejectedBy || '—' }} · <span class="num ltr inline-block">{{ fmtDate(r.rejectedAt) }}</span></span></div>
      <div v-if="r.state === 'changes_requested' && r.changesRequestedNote" class="hint amber"><b>{{ t('المطلوب تعديله', 'Changes asked') }}:</b> <span dir="auto">{{ r.changesRequestedNote }}</span></div>
      <div v-if="r.state === 'cancelled'" class="hint"><b>{{ t('أُلغي', 'Cancelled') }}</b> — {{ r.cancelledBy || '—' }} · <span class="num ltr inline-block">{{ fmtDate(r.cancelledAt) }}</span><template v-if="r.cancelReason"> — <span dir="auto">{{ r.cancelReason }}</span></template></div>

      <!-- did the grant reach the customer -->
      <div v-if="showApplyBox" class="banner mt-2.5" :class="applied.status === 'applied' ? 'green' : applied.status === 'blocked' ? 'red' : applied.status === 'restored' ? 'amber' : 'neutral'">
        <div class="min-w-0 flex-1">
          <div>{{ bi(pick(APPLY, applied.status)) }}<template v-if="applied.at"> · <span class="num ltr inline-block">{{ fmtDate(applied.at) }}</span> · {{ applied.by || '—' }}</template></div>
          <div v-if="applied.status === 'applied' && applied.changes" class="mt-1 text-[10px] font-bold">
            <div v-if="applied.changes.creditLimit">{{ t('الحد الائتماني', 'Credit limit') }}: <span class="num ltr inline-block">{{ fromToMoney(applied.changes.creditLimit) }}</span></div>
            <div v-if="applied.changes.terms">{{ t('شروط الدفع', 'Payment terms') }}: <span class="num ltr inline-block">{{ fromTo(applied.changes.terms) }}</span></div>
            <div v-if="applied.changes.paymentDays != null">{{ t('أيام السداد', 'Payment days') }}: <span class="num ltr inline-block">{{ fromTo(applied.changes.paymentDays) }}</span></div>
            <div v-if="applied.changes.customerCode">{{ t('العميل', 'Customer') }}: <span class="num ltr inline-block">{{ applied.changes.customerCode }}</span></div>
          </div>
          <div v-if="applied.status === 'blocked' && applied.error" class="mt-1 text-[10px]">
            <span dir="auto">{{ t(applied.error.messageAr, applied.error.messageEn || applied.error.messageAr) }}</span>
            <span class="num ltr ms-1 inline-block opacity-70">{{ applied.error.code }}</span>
            <span v-if="applied.error.at" class="text-[9px]"> · <span class="num ltr inline-block">{{ fmtDate(applied.error.at) }}</span> · {{ applied.error.by || '—' }}</span>
          </div>
          <div v-if="applied.restoredAt" class="mt-1 text-[10px]">{{ t('أُعيد العميل', 'Customer restored') }} · <span class="num ltr inline-block">{{ fmtDate(applied.restoredAt) }}</span> · {{ applied.restoredBy || '—' }}</div>
          <div v-if="applied.status === 'none' && r.state === 'approved'" class="mt-1 text-[10px]">{{ t('الحد المعتمد لم يصل إلى العميل بعد.', 'The granted limit has not reached the customer yet.') }}</div>
        </div>
        <Btn v-if="can.apply" size="sm" tone="primary" :loading="act.pending.value" :label="applied.status === 'blocked' ? { ar: 'تطبيق مرة أخرى', en: 'Apply again' } : { ar: 'تطبيق على العميل', en: 'Apply to the customer' }" @click="applyNow" />
      </div>

      <div class="mt-3 grid grid-cols-3 gap-2 max-[520px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ money(r.requestedCreditLimit, r.currency) }}</div><div class="tile-l">{{ t('الحد المطلوب', 'Asked limit') }} · <span class="num">{{ r.requestedPaymentDays || '—' }}</span> {{ t('يوم', 'days') }}</div></div>
        <div class="tile" :class="r.approvedCreditLimit != null ? 'green' : ''"><div class="tile-v">{{ money(r.approvedCreditLimit, r.currency) }}</div><div class="tile-l">{{ t('الحد المعتمد', 'Granted limit') }} · <span class="num">{{ r.approvedPaymentDays || '—' }}</span> {{ t('يوم', 'days') }}<template v-if="r.approvedTerm"> · <span class="num ltr inline-block">{{ r.approvedTerm }}</span></template></div></div>
        <div class="tile purple"><div class="tile-v">{{ r.customerCredit ? money(r.customerCredit.creditLimit) : '—' }}</div><div class="tile-l">{{ r.customerCredit ? t('الحد الحالي للعميل', 'Customer’s current limit') : t('حد العميل غير معروض لك', 'Customer’s limit not shown to you') }}</div></div>
      </div>

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-2.5 mt-4" />
      <RequestDetails v-if="pane === 'details'" :request="r" />
      <AssessmentBox v-else-if="pane === 'review'" :request="r" />
      <DocumentsBox v-else-if="pane === 'documents'" :request="r" />
      <SignatureBox v-else-if="pane === 'signature'" :request="r" />
      <ApprovalsBox v-else-if="pane === 'approvals'" :request="r" />
      <RequestTimeline v-else :request="r" />
    </template>

    <template v-if="r && anyAction" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="can.submit" tone="primary" :loading="act.pending.value" :label="{ ar: 'إرسال للمراجعة', en: 'Send for review' }" @click="submit" />
        <Btn v-if="can.decide" tone="success" :label="can.final ? { ar: 'اعتماد نهائي', en: 'Final approval' } : { ar: 'موافقة', en: 'Approve' }" @click="approving = true" />
        <Btn v-if="can.decide" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'طلب تعديل', en: 'Request changes' }" @click="requestChanges" />
        <Btn v-if="can.decide" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject" />
        <Btn v-if="can.apply" tone="primary" :loading="act.pending.value" :label="{ ar: 'تطبيق على العميل', en: 'Apply to the customer' }" @click="applyNow" />
        <Btn v-if="can.reopen" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'إعادة فتح', en: 'Reopen' }" @click="reopen" />
        <Btn v-if="can.skip" tone="soft" :loading="act.pending.value" :label="{ ar: 'تجاوز المرحلة', en: 'Skip the stage' }" @click="skip" />
        <Btn v-if="can.refreshApprovers" tone="soft" :loading="act.pending.value" :label="{ ar: 'تحديث المعتمدين', en: 'Refresh approvers' }" @click="refreshApprovers" />
        <Btn v-if="can.withdraw" tone="soft" :loading="act.pending.value" :label="{ ar: 'سحب', en: 'Withdraw' }" @click="withdraw" />
        <Btn v-if="can.restore" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إعادة العميل لما كان', en: 'Restore the customer' }" @click="restore" />
        <Btn v-if="can.cancel" tone="ghost" :loading="act.pending.value" :label="{ ar: 'إلغاء الطلب', en: 'Cancel the request' }" @click="cancel" />
        <span class="grow" />
        <Btn v-if="can.edit" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', r)" />
      </div>
    </template>
  </Drawer>

  <ApproveForm :request="approving ? r : null" @close="approving = false" />
</template>
