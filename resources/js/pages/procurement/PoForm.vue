<script setup>
// New purchase order (multi-line). POST /procurement/po — the approval chain is derived from the total by the server.
//   <PoForm :open :initial="{ supplierCode, warehouseCode, lines, reference, notes }" @close @done="(po) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextArea, TextInput, optV } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import LinesEditor from './LinesEditor.vue';
import ScoreOverrideField from './ScoreOverrideField.vue';
import { SELECT_PLACEHOLDER, TERMS_OPTIONS, linesValid, plusDays, pname, useSupplierOptions, useWarehouseOptions } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initial: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const whOpts = useWarehouseOptions();
const { options: supOpts, suppliers } = useSupplierOptions();

const blank = () => ({ supplierCode: '', warehouseCode: '', dueDate: plusDays(7), paymentTerms: '', reference: '', notes: '', discount: 0, shippingCost: 0, vatPct: 15, overrideSupplierScore: false, overrideReason: '', overrideDocuments: false, documentsReason: '' });
const f = ref(blank());
const lines = ref([]);
watch(() => props.open, (open) => {
  if (!open) return;
  const init = props.initial || {};
  f.value = { ...blank(), supplierCode: init.supplierCode || '', warehouseCode: init.warehouseCode || (whOpts.value[0] ? optV(whOpts.value[0]) : '') || '', reference: init.reference || '', notes: init.notes || '' };
  lines.value = init.lines || [];
  act.clearError();
}, { immediate: true });

const sup = computed(() => suppliers.value.find((s) => s.code === f.value.supplierCode));
const lowScore = computed(() => !!sup.value && sup.value.score < 65);
const supplierError = computed(() => (lowScore.value && !f.value.overrideSupplierScore ? t(`تقييم المورد ${fmtNum(sup.value.score)} دون الحد الأدنى (65) — يحتاج استثناء`, `Supplier score ${fmtNum(sup.value.score)} is below 65 — override required`) : null));
const goods = computed(() => lines.value.reduce((a, l) => a + (Number(l.qty) || 0) * (Number(l.price) || 0), 0));
/** (goods − discount + shipping) × (1 + VAT) — the approval tier still follows the goods value. */
const gross = computed(() => (goods.value - (Number(f.value.discount) || 0) + (Number(f.value.shippingCost) || 0)) * (1 + (Number(f.value.vatPct) || 0) / 100));
const chargesError = computed(() => ((Number(f.value.discount) || 0) > goods.value ? t('الخصم يتجاوز قيمة الأصناف', 'Discount exceeds the goods value') : null));
/** The server refuses a supplier whose mandatory document expired; the override (po.approve + reason) is offered right there. */
const docsBlocked = computed(() => act.error.value?.code === 'SUPPLIER_DOCS_EXPIRED' || f.value.overrideDocuments);
const ok = computed(() => !!f.value.supplierCode && !!f.value.warehouseCode && !!f.value.dueDate && linesValid(lines.value, true) && !chargesError.value
  && (!f.value.overrideSupplierScore || f.value.overrideReason.trim().length > 2) && (!f.value.overrideDocuments || f.value.documentsReason.trim().length > 2));

async function submit() {
  const v = f.value;
  const body = { supplierCode: v.supplierCode, warehouseCode: v.warehouseCode, dueDate: v.dueDate, paymentTerms: v.paymentTerms || undefined, reference: v.reference || undefined, notes: v.notes || undefined, lines: lines.value.map((l) => ({ sku: l.sku, qty: Number(l.qty), price: Number(l.price) })), discount: Number(v.discount) || 0, shippingCost: Number(v.shippingCost) || 0, vatPct: Number(v.vatPct) || 0,
    overrideSupplierScore: v.overrideSupplierScore || undefined, overrideReason: v.overrideReason || undefined, overrideDocuments: v.overrideDocuments || undefined, documentsReason: v.documentsReason || undefined };
  const r = await act.run(() => api.postIdempotent('/procurement/po', body), { success: (po) => t(`أُنشئ أمر الشراء ${po.number} — بانتظار الاعتماد`, `PO ${po.number} created — pending approval`), invalidate: ['procurement'] });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :title="{ ar: 'أمر شراء جديد', en: 'New purchase order' }" :sub="{ ar: 'سلسلة الاعتماد تُحدَّد تلقائيًا من قيمة الأصناف حسب شرائح الإعدادات — ومنشئ الأمر لا يعتمده', en: 'Approval chain is derived from the goods value per the configured tiers — the creator never approves' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'إنشاء وإرسال للاعتماد', en: 'Create & submit for approval' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.supplierCode" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supOpts" :placeholder="SELECT_PLACEHOLDER" :error="supplierError" />
      <SelectInput v-model="f.warehouseCode" :label="{ ar: 'مستودع التوريد', en: 'Delivery warehouse' }" required :options="whOpts" :placeholder="SELECT_PLACEHOLDER" />
      <DateInput v-model="f.dueDate" :label="{ ar: 'تاريخ التوريد المطلوب', en: 'Required delivery date' }" required />
      <SelectInput v-model="f.paymentTerms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS_OPTIONS" :placeholder="{ ar: 'حسب المورد', en: 'Supplier default' }" />
      <TextInput v-model="f.reference" :label="{ ar: 'مرجع (PR / RFQ)', en: 'Reference (PR / RFQ)' }" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات للمورد', en: 'Notes to supplier' }" :rows="2" :full="false" />
      <NumberInput v-model="f.discount" :label="{ ar: 'خصم على الأمر ر.س', en: 'Order discount SAR' }" :min="0" :error="chargesError" />
      <NumberInput v-model="f.shippingCost" :label="{ ar: 'تكلفة الشحن ر.س', en: 'Shipping SAR' }" :min="0" />
      <NumberInput v-model="f.vatPct" :label="{ ar: 'ضريبة القيمة المضافة %', en: 'VAT %' }" :min="0" :max="100" />
      <div class="field"><div class="field-l">{{ t('الإجمالي شامل الضريبة', 'Gross total incl. VAT') }}</div><div class="num ltr text-start text-[13px] font-extrabold">{{ fmtMoney(gross) }} <span class="font-sans text-[9px] text-faint">{{ t('ر.س', 'SAR') }}</span></div></div>
      <ScoreOverrideField v-if="lowScore" v-model:checked="f.overrideSupplierScore" v-model:reason="f.overrideReason" :label="t('استثناء تقييم المورد (يتطلب صلاحية اعتماد أوامر الشراء)', 'Override supplier score (requires po.approve)')" :reason-label="{ ar: 'سبب الاستثناء', en: 'Override reason' }" />
      <ScoreOverrideField v-if="docsBlocked" v-model:checked="f.overrideDocuments" v-model:reason="f.documentsReason" :label="t('مستند إلزامي للمورد منتهٍ — استثناء موثق (يتطلب صلاحية اعتماد أوامر الشراء)', 'A mandatory supplier document expired — documented override (requires po.approve)')" :reason-label="{ ar: 'سبب الاستثناء (مثال: السجل قيد التجديد)', en: 'Override reason (e.g. CR renewal in progress)' }" />
    </div>
    <div class="mt-3.5"><div class="field-l mb-1.5">{{ t('البنود', 'Lines') }} *</div><LinesEditor v-model="lines" with-price /></div>
    <div v-if="sup" class="hint !mt-3">{{ pname(sup) }} · Score <b class="num">{{ fmtNum(sup.score) }}</b> · OTIF <b class="num">{{ fmtNum(sup.otif) }}%</b> · {{ t('مهلة', 'Lead') }} <b class="num">{{ sup.leadDays }}</b> {{ t('يوم', 'days') }}</div>
  </ActionDrawer>
</template>
