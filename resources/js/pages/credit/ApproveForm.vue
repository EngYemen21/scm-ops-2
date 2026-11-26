<script setup>
// The approval of the current stage: an optional note and — at the last stage (`can.final`) or when the stage needs the
// grant (`requirements.grant.required`) — the granted limit, payment days and term (they start from what was granted
// so far, else from what was asked). POST /credit/requests/{id}/approve { note?, approvedCreditLimit?, approvedPaymentDays?,
// approvedTerm? } answers the detail. A decision is never edited afterwards.
//   <ApproveForm :request="detailOrNull" @close="…" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, TextArea, TextInput } from '@/components';
import { num, t } from '@/i18n';
import { money } from './credit';

const props = defineProps({
  /** The request detail; null closes the dialog. */
  request: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['credit'] });
const v = reactive({ note: '', approvedCreditLimit: null, approvedPaymentDays: null, approvedTerm: '' });
const errors = ref({});
const grant = computed(() => !!props.request && (!!props.request.can?.final || !!props.request.requirements?.grant?.required));

watch(() => props.request?.id, () => {
  const r = props.request;
  if (!r) return;
  v.note = '';
  v.approvedCreditLimit = r.approvedCreditLimit != null ? num(r.approvedCreditLimit) : r.requestedCreditLimit != null ? num(r.requestedCreditLimit) : null;
  v.approvedPaymentDays = r.approvedPaymentDays ?? r.requestedPaymentDays ?? null;
  v.approvedTerm = r.approvedTerm || '';
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

async function submit() {
  const errs = {};
  if (grant.value) {
    if (v.approvedCreditLimit == null || v.approvedCreditLimit === '' || Number(v.approvedCreditLimit) < 0) errs.approvedCreditLimit = t('أدخل الحد المعتمد', 'Enter the granted limit');
    const d = Number(v.approvedPaymentDays);
    if (!Number.isInteger(d) || d < 1 || d > 365) errs.approvedPaymentDays = t('من 1 إلى 365 يومًا', 'From 1 to 365 days');
  }
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = {};
  if (v.note.trim()) body.note = v.note.trim();
  if (grant.value) {
    body.approvedCreditLimit = Number(v.approvedCreditLimit);
    body.approvedPaymentDays = Number(v.approvedPaymentDays);
    if (v.approvedTerm.trim()) body.approvedTerm = v.approvedTerm.trim();
  }
  const r = await act.run(() => api.postIdempotent(`/credit/requests/${props.request.id}/approve`, body), { success: t('سُجّلت الموافقة', 'Approval recorded') });
  if (r) emit('close');
}
</script>

<template>
  <Modal :open="!!request" :width="520" :title="request?.can?.final ? { ar: 'الاعتماد النهائي للطلب', en: 'Final approval of the request' } : { ar: 'الموافقة على المرحلة', en: 'Approve the stage' }"
         :sub="request ? `${request.number} · ${request.stage ? (request.stage.nameAr || request.stage.nameEn) : ''}` : null" @close="emit('close')">
    <div v-if="grant" class="form-grid">
      <NumberInput v-model="v.approvedCreditLimit" required :min="0" :label="{ ar: 'الحد الائتماني المعتمد (ر.س)', en: 'Granted credit limit (SAR)' }" :hint="request ? { ar: `المطلوب ${money(request.requestedCreditLimit, request.currency)}`, en: `Asked ${money(request.requestedCreditLimit, request.currency)}` } : null" :error="errors.approvedCreditLimit" />
      <NumberInput v-model="v.approvedPaymentDays" required :min="1" :max="365" :step="1" :label="{ ar: 'أيام السداد المعتمدة', en: 'Granted payment days' }" :hint="request ? { ar: `المطلوب ${request.requestedPaymentDays || '—'} يوم`, en: `Asked ${request.requestedPaymentDays || '—'} days` } : null" :error="errors.approvedPaymentDays" />
      <TextInput v-model="v.approvedTerm" full dir="ltr" :label="{ ar: 'شرط الدفع المعتمد', en: 'Granted payment term' }" :hint="{ ar: 'يُترك فارغًا ليُشتق من أيام السداد', en: 'Leave empty to derive it from the payment days' }" :error="errors.approvedTerm" />
    </div>
    <TextArea v-model="v.note" :field-class="grant ? 'mt-3' : ''" :rows="3" :label="{ ar: 'ملاحظة القرار (اختيارية)', en: 'Decision note (optional)' }" :error="errors.note" />
    <div class="hint">{{ t('القرار يُسجَّل باسمك في سجل الاعتمادات ولا يُعدَّل بعد ذلك.', 'The decision is recorded in your name in the approval audit and is never edited afterwards.') }}</div>
    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="success" class="!h-11 flex-1" :loading="act.pending.value" :label="{ ar: 'تسجيل الموافقة', en: 'Record the approval' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
