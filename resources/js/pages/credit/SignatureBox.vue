<script setup>
// The signature of the credit agreement. The provider is what the API reports (`signatureProvider`): no electronic
// provider is connected unless it says so — the working one is «manual»: print the agreement, have it signed, upload
// the signed scan. Nothing here claims a message was sent by a provider that is not configured.
//   print       GET /credit/requests/{id}/agreement → { html } printed from a hidden frame
//   issue       POST …/envelopes { signerName?, signerEmail?, signerMobile? } (`can.issueSignature`; empty = the first signatory)
//   per envelope (its own `can`): delivered · declined { reason } · void { reason } · signed { fileName, base64 } · refresh
//   <SignatureBox :request="detail" />
import { computed, reactive, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, Modal, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { ask, confirm, toast } from '@/stores/ui';
import { blankWindow, fileProblem, labelOf, printHtml, readAsDataUrl, showDataUrl } from './credit';
import { ENVELOPE, SIGNATURE, pick } from './labels';

const props = defineProps({
  request: { type: Object, required: true },
});

const act = useAction({ invalidate: ['credit'] });
const reader = useAction({ invalidate: false, success: false });
const can = computed(() => props.request.can || {});
const provider = computed(() => props.request.signatureProvider || null);
const sig = computed(() => props.request.requirements?.signature || null);
const envelopes = computed(() => props.request.envelopes || []);
const base = () => `/credit/requests/${props.request.id}`;

// ---- the agreement
async function printAgreement() {
  const res = await reader.run(() => api.get(`${base()}/agreement`));
  if (res?.html) printHtml(res.html);
}

// ---- issue for signature
const issuing = ref(false);
const signer = reactive({ signerName: '', signerEmail: '', signerMobile: '' });
function startIssue() {
  const first = (props.request.signatories || [])[0];
  Object.assign(signer, { signerName: first?.name || '', signerEmail: '', signerMobile: first?.mobile || '' });
  act.clearError();
  issuing.value = true;
}
async function issue() {
  const body = Object.fromEntries(Object.entries(signer).map(([k, x]) => [k, String(x || '').trim()]).filter(([, x]) => x));
  const r = await act.run(() => api.postIdempotent(`${base()}/envelopes`, Object.keys(body).length ? body : undefined), { success: t('صدرت الاتفاقية للتوقيع', 'Agreement issued for signature') });
  if (r) issuing.value = false;
}

// ---- the moves of an envelope
const env = (e, suffix) => `${base()}/envelopes/${e.id}/${suffix}`;
async function delivered(e) {
  if (!(await confirm({ title: { ar: 'تسجيل وصول الاتفاقية إلى الموقّع؟', en: 'Record that the signer received the agreement?' }, tone: 'dark', okLabel: { ar: 'تسجيل', en: 'Record' } }))) return;
  await act.run(() => api.postIdempotent(env(e, 'delivered')), { success: t('سُجّل الوصول', 'Delivery recorded') });
}
async function withReason(e, suffix, title, success) {
  const reason = await ask({ title, label: { ar: 'السبب', en: 'Reason' }, required: true, tone: 'danger', okLabel: { ar: 'تأكيد', en: 'Confirm' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(env(e, suffix), { reason }), { success });
}
const declined = (e) => withReason(e, 'declined', { ar: 'تسجيل رفض الموقّع للتوقيع', en: 'Record that the signer declined' }, t('سُجّل الرفض', 'Decline recorded'));
const voidIt = (e) => withReason(e, 'void', { ar: 'إبطال هذه الاتفاقية المرسلة', en: 'Void this envelope' }, t('أُبطلت', 'Voided'));
const refresh = (e) => act.run(() => api.postIdempotent(env(e, 'refresh')), { success: t('حُدّثت حالتها من المزوّد', 'Status refreshed from the provider') });

// ---- upload the signed copy
const input = ref(null);
const target = ref(null);
function chooseSigned(e) { target.value = e; if (input.value) { input.value.value = ''; input.value.click(); } }
async function onFile(ev) {
  const file = ev.target.files?.[0];
  const e = target.value;
  if (!file || !e) return;
  const problem = fileProblem(file);
  if (problem) { toast.say(problem, 3200); return; }
  let base64;
  try { base64 = await readAsDataUrl(file); } catch { toast.say({ ar: 'تعذّرت قراءة الملف', en: 'The file could not be read' }, 3200); return; }
  await act.run(() => api.postIdempotent(env(e, 'signed'), { fileName: file.name, base64 }), { success: t('رُفعت النسخة الموقّعة', 'Signed copy uploaded') });
}
async function openSigned(f) {
  const win = blankWindow();
  const res = await reader.run(() => api.get(`${base()}/documents/${f.id}`, { as: 'data' }));
  if (!res?.dataUrl) { if (win) win.close(); return; }
  showDataUrl(res, win, !win);
}
</script>

<template>
  <div class="col !gap-3">
    <ErrorBanner :error="act.error.value || reader.error.value" @close="act.clearError(); reader.clearError()" />

    <div class="rounded-[12px] bg-soft px-3 py-2.5 text-[10.5px] leading-[1.8] text-sec">
      <div><b class="text-ink">{{ t('مزوّد التوقيع', 'Signature provider') }}:</b> {{ provider ? labelOf(provider) : '—' }}
        <Chip v-if="provider" small class="ms-1" :label="provider.remote && provider.configured ? { ar: 'متصل', en: 'Connected' } : provider.remote ? { ar: 'غير مُعدّ', en: 'Not configured' } : { ar: 'رفع يدوي', en: 'Manual upload' }"
              :fg="provider.remote && provider.configured ? '#1d7a3e' : '#55506a'" :bg="provider.remote && provider.configured ? '#e6f9ec' : '#F1EFF6'" /></div>
      <div v-if="provider && !provider.remote" class="mt-1">{{ t('لا توقيع إلكتروني متصل: اطبع الاتفاقية، وقّعها مع العميل، ثم ارفع النسخة الموقّعة على الاتفاقية الصادرة.', 'No electronic signature is connected: print the agreement, have it signed with the customer, then upload the signed copy on the issued envelope.') }}</div>
      <div v-if="sig" class="mt-1">{{ t('حالة التوقيع', 'Signature status') }}: <Chip small :map="SIGNATURE" :k="sig.status || 'none'" />
        <span v-if="sig.required" class="ms-1 text-[9.5px]">{{ sig.ok ? t('مكتمل', 'complete') : t('مطلوب في هذه المرحلة', 'required at this stage') }}</span>
        <span v-if="sig.stale" class="ms-1 text-[9.5px] font-bold text-warn">{{ t('الاتفاقية تغيّرت بعد توقيعها', 'The agreement changed after it was signed') }}</span></div>
    </div>

    <div class="row wrap !gap-1.5">
      <Btn size="sm" tone="soft" :loading="reader.pending.value" :label="{ ar: 'طباعة الاتفاقية', en: 'Print the agreement' }" @click="printAgreement" />
      <Btn v-if="can.issueSignature" size="sm" tone="primary" :label="{ ar: 'إصدار للتوقيع', en: 'Issue for signature' }" @click="startIssue" />
    </div>

    <input ref="input" type="file" class="hidden" accept="application/pdf,image/*" @change="onFile">
    <div v-if="!envelopes.length" class="empty !p-4">{{ t('لم تصدر الاتفاقية للتوقيع بعد.', 'The agreement has not been issued for signature yet.') }}</div>
    <div class="col !gap-1.5">
      <div v-for="e in envelopes" :key="e.id" class="rounded-[12px] border border-line-2 px-3 py-2" :class="{ 'opacity-60': ['voided', 'expired', 'declined', 'failed'].includes(e.status) }">
        <div class="flex flex-wrap items-center gap-2">
          <Chip small :label="pick(ENVELOPE, e.status)" :fg="pick(ENVELOPE, e.status).fg" :bg="pick(ENVELOPE, e.status).bg" />
          <span class="min-w-0 flex-1 text-[11px] font-extrabold text-ink" dir="auto">{{ e.signerName || t('موقّع غير مسمّى', 'Unnamed signer') }}</span>
          <span class="num ltr text-[10px] text-muted">{{ [e.signerMobile, e.signerEmail].filter(Boolean).join(' · ') }}</span>
          <span v-if="e.source === 'odoo'" class="text-[9px] font-bold text-violet">{{ t('من أودو', 'from Odoo') }}</span>
        </div>
        <div class="mt-1 flex flex-wrap gap-x-3 text-[9.5px] text-muted">
          <span>{{ e.provider }}</span>
          <span v-if="e.sentAt">{{ t('أُرسلت', 'sent') }} <span class="num ltr inline-block">{{ fmtDate(e.sentAt) }}</span></span>
          <span v-if="e.deliveredAt">{{ t('وصلت', 'delivered') }} <span class="num ltr inline-block">{{ fmtDate(e.deliveredAt) }}</span></span>
          <span v-if="e.signedAt" class="text-ok">{{ t('وُقّعت', 'signed') }} <span class="num ltr inline-block">{{ fmtDate(e.signedAt) }}</span></span>
          <span v-if="e.expiresAt && !e.signedAt && !e.closedAt">{{ t('تنتهي', 'expires') }} <span class="num ltr inline-block">{{ fmtDate(e.expiresAt) }}</span></span>
          <span v-if="e.closedAt">{{ t('أُغلقت', 'closed') }} <span class="num ltr inline-block">{{ fmtDate(e.closedAt) }}</span></span>
          <span dir="auto">{{ t('أصدرها', 'issued by') }} {{ e.createdBy || '—' }}</span>
          <span v-if="e.externalEnvelopeId" class="num ltr">#{{ e.externalEnvelopeId }}</span>
        </div>
        <div v-if="e.errorMessage" class="mt-1 text-[10px] text-bad" dir="auto">{{ e.errorMessage }}</div>
        <div v-if="e.stale" class="mt-1 text-[10px] font-bold text-warn">{{ t('وُقّعت على نسخة سابقة من الاتفاقية — يلزم توقيع جديد.', 'Signed on an earlier version of the agreement — a new signature is needed.') }}</div>
        <div v-if="e.signedFile" class="row mt-1.5 !gap-1.5 text-[10px]">
          <span class="min-w-0 flex-1 truncate text-sec" dir="auto">✍ {{ e.signedFile.name || t('النسخة الموقّعة', 'Signed copy') }}</span>
          <Btn v-if="e.signedFile.canOpen && e.signedFile.available" size="sm" tone="soft" :label="{ ar: 'فتح الموقّعة', en: 'Open the signed copy' }" @click="openSigned(e.signedFile)" />
        </div>
        <div v-if="e.can && Object.values(e.can).some(Boolean)" class="row wrap mt-1.5 !gap-1.5">
          <Btn v-if="e.can.signed" size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'رفع النسخة الموقّعة', en: 'Upload the signed copy' }" @click="chooseSigned(e)" />
          <Btn v-if="e.can.delivered" size="sm" tone="soft" :loading="act.pending.value" :label="{ ar: 'وصلت للموقّع', en: 'Delivered' }" @click="delivered(e)" />
          <Btn v-if="e.can.refresh" size="sm" tone="soft" :loading="act.pending.value" :label="{ ar: 'تحديث الحالة', en: 'Refresh status' }" @click="refresh(e)" />
          <Btn v-if="e.can.declined" size="sm" tone="ghost" :label="{ ar: 'رفض الموقّع', en: 'Declined' }" @click="declined(e)" />
          <Btn v-if="e.can.void" size="sm" tone="ghost" :label="{ ar: 'إبطال', en: 'Void' }" @click="voidIt(e)" />
        </div>
      </div>
    </div>

    <Modal :open="issuing" :width="480" :title="{ ar: 'إصدار الاتفاقية للتوقيع', en: 'Issue the agreement for signature' }" :sub="request.number" @close="issuing = false">
      <div class="form-grid">
        <TextInput v-model="signer.signerName" full :label="{ ar: 'اسم الموقّع', en: 'Signer name' }" :hint="{ ar: 'فارغ = أول مفوّض بالتوقيع في الطلب', en: 'Empty = the first authorised signatory of the request' }" />
        <TextInput v-model="signer.signerMobile" dir="ltr" :label="{ ar: 'جوال الموقّع', en: 'Signer mobile' }" />
        <TextInput v-model="signer.signerEmail" dir="ltr" type="email" :label="{ ar: 'بريد الموقّع', en: 'Signer e-mail' }" />
      </div>
      <div v-if="provider && !provider.remote" class="hint">{{ t('المزوّد يدوي: لن يُرسل شيء آليًا — تُطبع الاتفاقية وتُرفع نسختها الموقّعة هنا.', 'The provider is manual: nothing is sent automatically — the agreement is printed and its signed copy is uploaded here.') }}</div>
      <template #footer>
        <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
        <div class="flex gap-2">
          <Btn tone="primary" class="!h-11 flex-1" :loading="act.pending.value" :label="{ ar: 'إصدار', en: 'Issue' }" @click="issue" />
          <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="issuing = false" />
        </div>
      </template>
    </Modal>
  </div>
</template>
