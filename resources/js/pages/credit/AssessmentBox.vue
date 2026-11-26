<script setup>
// The assessment and the terms of a request.
// Assessment: the finance recommendation, the legal notes and the top management's notes, with the grant being prepared
// (limit, days, term). Editable when `can.assess` (CEO notes only when `can.ceoNotes`) — POST …/assess with only what
// changed. Terms: the version accepted for this request (frozen on it), the version in force, and «تسجيل القبول»
// (`can.acceptTerms` and the accepted version is not the one in force) — POST …/accept-terms { versionId }.
// «عرض نص الشروط» reads GET /credit/terms (only when asked).
//   <AssessmentBox :request="detail" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, ErrorBanner, NumberInput, TextArea, TextInput } from '@/components';
import { fmtDate, num, t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { fmtDay, money } from './credit';

const props = defineProps({
  request: { type: Object, required: true },
});

const act = useAction({ invalidate: ['credit'] });
const can = computed(() => props.request.can || {});

// ---- assessment
const NOTES = [
  { k: 'financeRecommendation', l: { ar: 'توصية المالية', en: 'Finance recommendation' } },
  { k: 'legalNotes', l: { ar: 'ملاحظات الشؤون القانونية', en: 'Legal notes' } },
  { k: 'ceoNotes', l: { ar: 'ملاحظات الإدارة العليا', en: 'Top management notes' } },
];
const KEYS = ['financeRecommendation', 'legalNotes', 'ceoNotes', 'approvedCreditLimit', 'approvedPaymentDays', 'approvedTerm'];
const fromRequest = (r) => ({
  financeRecommendation: r.financeRecommendation || '', legalNotes: r.legalNotes || '', ceoNotes: r.ceoNotes || '',
  approvedCreditLimit: r.approvedCreditLimit != null ? num(r.approvedCreditLimit) : null, approvedPaymentDays: r.approvedPaymentDays ?? null, approvedTerm: r.approvedTerm || '',
});
const v = reactive(fromRequest(props.request));
let initial = fromRequest(props.request);
const editing = ref(false);
const errors = ref({});
function start() { initial = fromRequest(props.request); Object.assign(v, initial); errors.value = {}; act.clearError(); editing.value = true; }
watch(() => props.request.id, () => { editing.value = false; });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const sent = (k, x) => {
  if (k === 'approvedCreditLimit' || k === 'approvedPaymentDays') return x == null || x === '' ? null : Number(x);
  return String(x ?? '').trim() === '' ? null : String(x).trim();
};
async function save() {
  const errs = {};
  const d = v.approvedPaymentDays;
  if (d != null && d !== '' && (!Number.isInteger(Number(d)) || Number(d) < 1 || Number(d) > 365)) errs.approvedPaymentDays = t('من 1 إلى 365 يومًا', 'From 1 to 365 days');
  if (v.approvedCreditLimit != null && v.approvedCreditLimit !== '' && Number(v.approvedCreditLimit) < 0) errs.approvedCreditLimit = t('لا يكون سالبًا', 'Cannot be negative');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = {};
  for (const k of KEYS) {
    if (k === 'ceoNotes' && !can.value.ceoNotes) continue;
    const now = sent(k, v[k]);
    if (now !== sent(k, initial[k])) body[k] = now;
  }
  if (!Object.keys(body).length) { editing.value = false; return; }
  const r = await act.run(() => api.postIdempotent(`/credit/requests/${props.request.id}/assess`, body), { success: t('حُفظ التقييم', 'Assessment saved') });
  if (r) editing.value = false;
}

// ---- terms
const terms = computed(() => props.request.terms || {});
async function acceptTerms() {
  const inForce = terms.value.inForce;
  if (!inForce) return;
  if (!(await confirm({
    title: { ar: `تسجيل قبول الشروط (الإصدار ${inForce.version}) على الطلب ${props.request.number}؟`, en: `Record acceptance of the terms (version ${inForce.version}) on ${props.request.number}?` },
    sub: { ar: 'يُحفظ نص هذا الإصدار وبصمته على الطلب كما هو', en: 'The text of this version and its fingerprint are frozen on the request' }, tone: 'primary', okLabel: { ar: 'تسجيل القبول', en: 'Record acceptance' },
  }))) return;
  await act.run(() => api.postIdempotent(`/credit/requests/${props.request.id}/accept-terms`, { versionId: inForce.id }), { success: t('سُجّل قبول الشروط', 'Terms acceptance recorded') });
}
const showText = ref(false);
const termsQ = useGet(() => (showText.value ? '/credit/terms' : null));
const termsText = computed(() => {
  const want = terms.value.accepted?.id || terms.value.inForce?.id;
  return (termsQ.data.value?.items || []).find((x) => x.id === want) || null;
});
const short = (h) => (h ? `${String(h).slice(0, 12)}…` : '—');
</script>

<template>
  <div class="col !gap-4">
    <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />

    <section>
      <div class="row mb-1.5">
        <div class="field-l grow">{{ t('التقييم', 'Assessment') }}</div>
        <Btn v-if="can.assess && !editing" size="sm" tone="softPurple" :label="{ ar: 'تحرير التقييم', en: 'Edit the assessment' }" @click="start" />
      </div>
      <template v-if="!editing">
        <div class="col !gap-2">
          <div v-for="n in NOTES" :key="n.k" class="rounded-[12px] bg-soft px-3 py-2">
            <div class="text-[9.5px] font-extrabold text-muted">{{ t(n.l.ar, n.l.en) }}</div>
            <div class="mt-0.5 whitespace-pre-wrap text-[11px] leading-[1.8] [overflow-wrap:anywhere]" :class="request[n.k] ? 'text-ink' : 'text-faint'" dir="auto">{{ request[n.k] || t('لا شيء مسجّل', 'Nothing recorded') }}</div>
          </div>
        </div>
      </template>
      <template v-else>
        <div class="form-grid">
          <NumberInput v-model="v.approvedCreditLimit" :min="0" :label="{ ar: 'الحد المقترح للاعتماد (ر.س)', en: 'Limit proposed for the grant (SAR)' }" :hint="{ ar: `المطلوب ${money(request.requestedCreditLimit, request.currency)}`, en: `Asked ${money(request.requestedCreditLimit, request.currency)}` }" :error="errors.approvedCreditLimit" />
          <NumberInput v-model="v.approvedPaymentDays" :min="1" :max="365" :step="1" :label="{ ar: 'أيام السداد المقترحة', en: 'Proposed payment days' }" :error="errors.approvedPaymentDays" />
          <TextInput v-model="v.approvedTerm" full dir="ltr" :label="{ ar: 'شرط الدفع المقترح', en: 'Proposed payment term' }" :error="errors.approvedTerm" />
        </div>
        <TextArea v-model="v.financeRecommendation" field-class="mt-3" :rows="3" :label="{ ar: 'توصية المالية', en: 'Finance recommendation' }" :error="errors.financeRecommendation" />
        <TextArea v-model="v.legalNotes" field-class="mt-3" :rows="3" :label="{ ar: 'ملاحظات الشؤون القانونية', en: 'Legal notes' }" :error="errors.legalNotes" />
        <TextArea v-if="can.ceoNotes" v-model="v.ceoNotes" field-class="mt-3" :rows="3" :label="{ ar: 'ملاحظات الإدارة العليا', en: 'Top management notes' }" :error="errors.ceoNotes" />
        <div class="row mt-2.5"><span class="grow" />
          <Btn size="sm" tone="soft" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="editing = false" />
          <Btn size="sm" tone="dark" :loading="act.pending.value" :label="{ ar: 'حفظ التقييم', en: 'Save the assessment' }" @click="save" />
        </div>
      </template>
    </section>

    <section>
      <div class="field-l mb-1.5">{{ t('شروط الائتمان', 'Credit terms') }}</div>
      <div class="kv-grid">
        <div class="kv">
          <div class="kv-k">{{ t('الإصدار المقبول على الطلب', 'Version accepted on the request') }}</div>
          <div class="kv-v">
            <template v-if="terms.accepted">
              <span dir="auto">{{ terms.accepted.name }}</span> · <span class="num ltr inline-block">{{ terms.accepted.version }}</span>
              <div class="text-[9px] font-normal text-faint">{{ t('سارٍ من', 'effective') }} <span class="num ltr inline-block">{{ fmtDay(terms.accepted.effectiveDate) }}</span> · {{ t('قُبل', 'accepted') }} <span class="num ltr inline-block">{{ fmtDate(terms.accepted.acceptedAt) }}</span> · <span dir="auto">{{ terms.accepted.acceptedBy || '—' }}</span></div>
              <div class="text-[9px] font-normal text-faint">{{ t('البصمة', 'Fingerprint') }} <span class="num ltr inline-block" :title="terms.accepted.hash">{{ short(terms.accepted.hash) }}</span></div>
            </template>
            <span v-else class="text-faint">{{ t('لم تُقبل شروط بعد', 'No terms accepted yet') }}</span>
          </div>
        </div>
        <div class="kv">
          <div class="kv-k">{{ t('الإصدار الساري الآن', 'Version in force now') }}</div>
          <div class="kv-v">
            <template v-if="terms.inForce"><span dir="auto">{{ terms.inForce.name }}</span> · <span class="num ltr inline-block">{{ terms.inForce.version }}</span>
              <div class="text-[9px] font-normal text-faint">{{ t('سارٍ من', 'effective') }} <span class="num ltr inline-block">{{ fmtDay(terms.inForce.effectiveDate) }}</span></div></template>
            <span v-else class="text-faint">{{ t('لا إصدار منشور', 'No published version') }}</span>
          </div>
        </div>
      </div>
      <div v-if="terms.inForce && !terms.current" class="hint amber">{{ terms.accepted
        ? t('الإصدار المقبول ليس الإصدار الساري الآن.', 'The accepted version is not the one in force now.')
        : t('لم تُسجَّل موافقة العميل على الشروط السارية بعد.', 'The customer’s acceptance of the terms in force is not recorded yet.') }}</div>
      <div v-else-if="terms.current && terms.accepted" class="hint green">{{ t('الشروط المقبولة هي السارية الآن.', 'The accepted terms are the ones in force.') }}</div>
      <div class="row mt-2 !gap-1.5">
        <Btn v-if="can.acceptTerms && !terms.current && terms.inForce" size="sm" tone="primary" :loading="act.pending.value" :label="{ ar: 'تسجيل قبول الشروط السارية', en: 'Record acceptance of the terms in force' }" @click="acceptTerms" />
        <Btn v-if="terms.accepted || terms.inForce" size="sm" tone="soft" :label="showText ? { ar: 'إخفاء النص', en: 'Hide the text' } : { ar: 'عرض نص الشروط', en: 'Show the terms text' }" @click="showText = !showText" />
      </div>
      <template v-if="showText">
        <ErrorBanner :error="termsQ.error.value" :closable="false" />
        <div v-if="termsQ.isLoading.value" class="skel mt-2 h-[120px]" />
        <div v-else-if="termsText" class="mt-2 max-h-[360px] overflow-auto whitespace-pre-wrap rounded-[12px] border border-line-2 bg-soft px-3 py-2.5 text-[11px] leading-[1.9] text-sec [overflow-wrap:anywhere]" dir="auto">{{ t(termsText.termsAr || termsText.termsEn || '—', termsText.termsEn || termsText.termsAr || '—') }}</div>
        <div v-else-if="termsQ.data.value" class="empty !p-3">{{ t('نص هذا الإصدار غير متاح.', 'The text of this version is not available.') }}</div>
      </template>
    </section>
  </div>
</template>
