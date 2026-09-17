<script setup>
// Record a supplier quotation. POST /procurement/quotations — the attachment name is mandatory (PDF / JPG / PNG);
// the actual file upload is Integration Pending, only the name is recorded.
//   <SupQuoteForm :open :initial="{ supplierCode, rfqNumber, lines }" @close @done="(quotation) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import LinesEditor from './LinesEditor.vue';
import { SELECT_PLACEHOLDER, TERMS_OPTIONS, plusDays, pname, todayIso, useSupplierOptions } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initial: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const { options: supOpts } = useSupplierOptions();
const rfqs = useList('/procurement/rfq', { status: 'open,quoted,compared', pageSize: 100 }, { enabled: () => props.open });
const rfqOptions = computed(() => (rfqs.data.value?.items || []).map((r) => ({ v: r.number, l: `${r.number} · ${r.lines.map((l) => pname(l.product)).join('، ').slice(0, 40)}` })));
const DELIVERY_TERMS = [['DDP', 'DDP'], ['EXW', 'EXW']];

const blank = () => ({ supplierCode: '', supplierRef: '', date: todayIso(), validUntil: plusDays(30), rfqNumber: '', paymentTerms: 'آجل 30 يوم', deliveryTerms: 'DDP', minOrder: 0, leadDays: 5, attachmentName: '', notes: '' });
const f = ref(blank());
const lines = ref([]);
watch(() => props.open, (open) => {
  if (!open) return;
  const init = props.initial || {};
  f.value = { ...blank(), supplierCode: init.supplierCode || '', rfqNumber: init.rfqNumber || '' };
  lines.value = init.lines || [];
  act.clearError();
}, { immediate: true });

/** Lines of an RFQ as quotation drafts (so the quotation covers every line and joins the comparison). */
const rfqLines = (number) => {
  const r = rfqs.data.value?.items.find((x) => x.number === number);
  return r ? r.lines.map((l) => ({ sku: l.product.sku, name: pname(l.product), qty: l.qty, price: l.product.purchasePrice != null ? Number(l.product.purchasePrice) : null })) : null;
};
// Selecting an RFQ pre-fills its lines.
function pickRfq(number) {
  f.value.rfqNumber = number;
  const l = rfqLines(number);
  if (l) lines.value = l;
}
// Opened from an RFQ ("+ record quote"): pre-fill that RFQ's lines as soon as the RFQ list is available.
watch([() => props.open, () => rfqs.data.value], () => {
  if (props.open && f.value.rfqNumber && lines.value.length === 0) { const l = rfqLines(f.value.rfqNumber); if (l) lines.value = l; }
});

const attOk = computed(() => /\.(pdf|jpe?g|png)$/i.test(f.value.attachmentName.trim()));
const validOk = computed(() => !!f.value.validUntil && f.value.validUntil >= todayIso());
const ok = computed(() => !!f.value.supplierCode && f.value.supplierRef.trim().length > 0 && validOk.value && attOk.value && lines.value.length > 0 && lines.value.every((l) => Number(l.price) > 0));

async function submit() {
  const v = f.value;
  const body = { supplierCode: v.supplierCode, rfqNumber: v.rfqNumber || undefined, supplierRef: v.supplierRef, date: v.date || undefined, validUntil: v.validUntil, paymentTerms: v.paymentTerms || undefined, deliveryTerms: v.deliveryTerms || undefined, minOrder: Number(v.minOrder) || 0, leadDays: Number(v.leadDays) || 0, attachmentName: v.attachmentName.trim(), notes: v.notes || undefined, lines: lines.value.map((l) => ({ sku: l.sku, qty: l.qty != null ? Number(l.qty) : undefined, price: Number(l.price), vatPct: 15 })) };
  const r = await act.run(() => api.postIdempotent('/procurement/quotations', body), { success: (q) => t(`سُجل عرض السعر ${q.number}${q.rfq ? ' — أُضيف لمقارنة ' + q.rfq.number : ''}`, `Quotation ${q.number} recorded`), invalidate: ['procurement'] });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :title="{ ar: 'تسجيل عرض سعر مورد', en: 'Record supplier quotation' }" :sub="{ ar: 'المرفق إلزامي (PDF / JPG / PNG) — رفع الملف Integration Pending، يُسجَّل اسمه فقط', en: 'Attachment required (PDF / JPG / PNG) — file upload is Integration Pending; only the name is recorded' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'تسجيل العرض', en: 'Record quotation' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.supplierCode" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supOpts" :placeholder="SELECT_PLACEHOLDER" />
      <TextInput v-model="f.supplierRef" :label="{ ar: 'رقم عرض المورد', en: 'Supplier reference' }" required dir="ltr" />
      <DateInput v-model="f.date" :label="{ ar: 'تاريخ العرض', en: 'Quote date' }" required />
      <DateInput v-model="f.validUntil" :label="{ ar: 'صالح حتى', en: 'Valid until' }" required :error="f.validUntil && !validOk ? t('تاريخ الصلاحية لا يمكن أن يكون في الماضي', 'Validity date cannot be in the past') : null" />
      <SelectInput :model-value="f.rfqNumber" :label="{ ar: 'مرتبط بـ RFQ', en: 'Linked RFQ' }" :options="rfqOptions" :placeholder="{ ar: 'بدون', en: 'None' }" @update:model-value="pickRfq" />
      <NumberInput v-model="f.leadDays" :label="{ ar: 'مدة التوريد (أيام)', en: 'Lead time (days)' }" required :min="0" />
      <SelectInput v-model="f.paymentTerms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS_OPTIONS" />
      <SelectInput v-model="f.deliveryTerms" :label="{ ar: 'شروط التوريد', en: 'Delivery terms' }" :options="DELIVERY_TERMS" />
      <NumberInput v-model="f.minOrder" :label="{ ar: 'الحد الأدنى للطلب', en: 'Minimum order' }" :min="0" />
      <TextInput v-model="f.attachmentName" :label="{ ar: 'المرفق (PDF / JPG / PNG)', en: 'Attachment (PDF / JPG / PNG)' }" required dir="ltr" placeholder="quote-2026-001.pdf"
                 :error="f.attachmentName && !attOk ? t('المرفق يجب أن يكون PDF أو JPG أو PNG', 'Attachment must be PDF, JPG or PNG') : null" :hint="{ ar: 'اكتب اسم الملف بامتداده — الرفع الفعلي Integration Pending', en: 'File name with extension — upload is Integration Pending' }" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" :full="false" />
    </div>
    <div class="mt-3.5"><div class="field-l mb-1.5">{{ t('الأسعار (قبل الضريبة)', 'Prices (before VAT)') }} *</div><LinesEditor v-model="lines" with-price /></div>
  </ActionDrawer>
</template>
