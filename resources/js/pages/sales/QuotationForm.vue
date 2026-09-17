<script setup>
// New quotation drawer: customer, validity, terms, multi-line editor with discount, notes, save as draft or save & send.
//   <QuotationForm :open="open" :initial-customer="code" @close="…" @done="(quotation) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, Field, SelectInput, TextArea } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import LinesEditor from './LinesEditor.vue';
import { DELIVERY, TERMS, customerOptions, isoDatePlus, linesBody, newLine, useLookups, validateLines } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initialCustomer: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const lookups = useLookups();
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

async function submit() {
  const errs = { ...validateLines(lines.value, true) };
  if (!v.customerCode) errs.customerCode = t('هذا الحقل إلزامي', 'Required');
  if (!v.validUntil) errs.validUntil = t('هذا الحقل إلزامي', 'Required');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = { customerCode: v.customerCode, validUntil: v.validUntil, terms: v.terms || undefined, delivery: v.delivery || undefined, notes: v.notes || undefined, action: v.action, lines: linesBody(lines.value, true) };
  const r = await act.run(() => api.postIdempotent('/sales/quotations', body), {
    success: (q) => t(`أُنشئ عرض السعر ${q.number}${v.action === 'sent' ? ' وأُرسل للعميل' : ''}`, `Quotation ${q.number} created`),
    invalidate: ['sales'],
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="680" :z-index="70" :title="{ ar: 'عرض سعر جديد', en: 'New quotation' }"
          :sub="{ ar: 'مرتبط بالعميل والمخزون — الخصم فوق 30% يحتاج اعتماد مدير المبيعات', en: 'Linked to the customer and stock — discount above 30% needs approval' }" @close="emit('close')">
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
