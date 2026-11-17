<script setup>
// New quotation drawer: customer, validity, terms, multi-line editor with discount, notes, save as draft or save & send.
// Discount rule (policy `sales.maxDiscountPct`): a draft may carry any discount; above the limit it waits for a holder of
// `discount.approve` before it can be sent — so "save & send" is refused here for a user who cannot approve it himself.
//   <QuotationForm :open="open" :initial-customer="code" @close="…" @done="(quotation) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, Field, SelectInput, TextArea } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import LinesEditor from './LinesEditor.vue';
import { DELIVERY, TERMS, customerOptions, isoDatePlus, linesBody, maxLineDiscount, newLine, useLookups, usePolicy, validateLines } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initialCustomer: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const lookups = useLookups();
const policy = usePolicy();
const act = useAction();

const blank = () => ({ customerCode: props.initialCustomer || '', validUntil: isoDatePlus(14), terms: '', delivery: '', notes: '', action: 'draft' });
const v = reactive(blank());
const lines = ref([newLine()]);
const errors = ref({});

watch(() => [props.open, props.initialCustomer], () => {
  if (!props.open) return;
  Object.assign(v, blank()); lines.value = [newLine()]; errors.value = {}; act.clearError();
}, { immediate: true });
// The customer's own payment terms become the default terms of the quotation.
watch(() => [v.customerCode, lookups.data.value, props.open], () => {
  if (!props.open || !v.customerCode || v.terms) return;
  const c = lookups.data.value?.customers.find((x) => x.code === v.customerCode);
  if (c?.terms) v.terms = c.terms;
});
// Server-side validation details land on their fields (`lines.0.qty` …).
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const customers = computed(() => customerOptions(lookups.data.value));
const ACTIONS = [['draft', { ar: 'حفظ كمسودة', en: 'Save as draft' }], ['sent', { ar: 'حفظ وإرسال للعميل', en: 'Save & send to customer' }]];

// discount above the limit: stamped at once for a holder of discount.approve; otherwise the draft waits for the approval
const maxPct = computed(() => policy.value?.maxDiscountPct ?? null);
const aboveMax = computed(() => maxPct.value != null && maxLineDiscount(lines.value) > Number(maxPct.value));
const mayApprove = computed(() => auth.can('discount.approve'));
const sendBlocked = computed(() => aboveMax.value && !mayApprove.value && v.action === 'sent');
const subText = computed(() => (maxPct.value == null
  ? { ar: 'مرتبط بالعميل والمخزون', en: 'Linked to the customer and stock' }
  : { ar: `مرتبط بالعميل والمخزون — الخصم فوق ${maxPct.value}% يحتاج اعتماد صاحب صلاحية اعتماد الخصم قبل الإرسال`, en: `Linked to the customer and stock — a discount above ${maxPct.value}% needs discount approval before sending` }));

async function submit() {
  const block = sendBlocked.value ? { maxPct: maxPct.value, message: t('لا يُرسل قبل اعتماد الخصم — احفظه كمسودة', 'Cannot be sent before the discount is approved — save it as a draft') } : null;
  const errs = { ...validateLines(lines.value, true, block) };
  if (!v.customerCode) errs.customerCode = t('هذا الحقل إلزامي', 'Required');
  if (!v.validUntil) errs.validUntil = t('هذا الحقل إلزامي', 'Required');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = { customerCode: v.customerCode, validUntil: v.validUntil, terms: v.terms || undefined, delivery: v.delivery || undefined, notes: v.notes || undefined, action: v.action, lines: linesBody(lines.value, true) };
  const r = await act.run(() => api.postIdempotent('/sales/quotations', body), {
    success: (q) => (q.discount?.needsApproval
      ? t(`حُفظ عرض السعر ${q.number} كمسودة — بانتظار اعتماد الخصم قبل إرساله`, `Quotation ${q.number} saved as a draft — waiting for discount approval before it can be sent`)
      : t(`أُنشئ عرض السعر ${q.number}${q.status === 'sent' ? ' وأُرسل للعميل' : ''}`, `Quotation ${q.number} created`)),
    invalidate: ['sales'],
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="680" :z-index="70" :title="{ ar: 'عرض سعر جديد', en: 'New quotation' }" :sub="subText" @close="emit('close')">
    <div class="form-grid">
      <SelectInput v-model="v.customerCode" :label="{ ar: 'العميل', en: 'Customer' }" required :options="customers" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errors.customerCode" />
      <DateInput v-model="v.validUntil" :label="{ ar: 'صالح حتى', en: 'Valid until' }" required :error="errors.validUntil" />
      <SelectInput v-model="v.terms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS" :placeholder="{ ar: '—', en: '—' }" />
      <SelectInput v-model="v.delivery" :label="{ ar: 'شروط التوصيل', en: 'Delivery terms' }" :options="DELIVERY" :placeholder="{ ar: '—', en: '—' }" />
    </div>
    <div class="mt-3.5">
      <div class="field-l mb-1.5">{{ t('الأسطر', 'Lines') }} *</div>
      <LinesEditor v-model:lines="lines" discount :errors="errors" />
    </div>
    <div v-if="aboveMax" class="hint" :class="sendBlocked ? 'amber' : 'purple'">{{
      mayApprove
        ? t(`في العرض خصم فوق الحد ${maxPct}% — سيُسجَّل باعتمادك للخصم (${auth.user?.username || ''}).`, `The quotation carries a discount above the ${maxPct}% limit — it will be recorded as approved by you (${auth.user?.username || ''}).`)
        : sendBlocked
          ? t(`في العرض خصم فوق الحد ${maxPct}% — لا يُرسل للعميل قبل اعتماد الخصم. اختر «حفظ كمسودة» ثم اطلب الاعتماد من صاحب صلاحية اعتماد الخصم.`, `The quotation carries a discount above the ${maxPct}% limit — it cannot be sent before the discount is approved. Choose "Save as draft", then ask a holder of the discount approval.`)
          : t(`في العرض خصم فوق الحد ${maxPct}% — يُحفظ كمسودة ولا يُرسل أو يُعتمد إلا بعد اعتماد الخصم من صاحب الصلاحية.`, `The quotation carries a discount above the ${maxPct}% limit — it is saved as a draft and can be sent or approved only after the discount is approved.`)
    }}</div>
    <div class="form-grid mt-3">
      <TextArea v-model="v.notes" :full="false" :label="{ ar: 'ملاحظات', en: 'Notes' }" />
      <SelectInput v-model="v.action" :label="{ ar: 'الإجراء', en: 'Action' }" :options="ACTIONS" />
      <Field :label="{ ar: 'المرفقات', en: 'Attachments' }"><div class="hint purple !mt-0 !px-3 !py-2">{{ t('رفع الملفات — Integration Pending', 'File upload — Integration Pending') }}</div></Field>
    </div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="v.action === 'sent' ? { ar: 'حفظ وإرسال للعميل', en: 'Save & send' } : { ar: 'حفظ كمسودة', en: 'Save draft' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] text-faint">{{ t(`* حقول إلزامية · يُسجل الإجراء في Audit Trail باسم ${auth.user?.nameAr || ''}`, `* required · recorded in the Audit Trail as ${auth.user?.nameEn || ''}`) }}</div>
    </template>
  </Drawer>
</template>
