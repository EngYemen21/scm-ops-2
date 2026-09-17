<script setup>
// New purchase order (multi-line). POST /procurement/po — the approval chain is derived from the total by the server.
//   <PoForm :open :initial="{ supplierCode, warehouseCode, lines, reference, notes }" @close @done="(po) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, TextInput, optV } from '@/components';
import { fmtNum, t } from '@/i18n';
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

const blank = () => ({ supplierCode: '', warehouseCode: '', dueDate: plusDays(7), paymentTerms: '', reference: '', notes: '', overrideSupplierScore: false, overrideReason: '' });
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
const ok = computed(() => !!f.value.supplierCode && !!f.value.warehouseCode && !!f.value.dueDate && linesValid(lines.value, true) && (!f.value.overrideSupplierScore || f.value.overrideReason.trim().length > 2));

async function submit() {
  const v = f.value;
  const body = { supplierCode: v.supplierCode, warehouseCode: v.warehouseCode, dueDate: v.dueDate, paymentTerms: v.paymentTerms || undefined, reference: v.reference || undefined, notes: v.notes || undefined, lines: lines.value.map((l) => ({ sku: l.sku, qty: Number(l.qty), price: Number(l.price) })), overrideSupplierScore: v.overrideSupplierScore || undefined, overrideReason: v.overrideReason || undefined };
  const r = await act.run(() => api.postIdempotent('/procurement/po', body), { success: (po) => t(`أُنشئ أمر الشراء ${po.number} — بانتظار الاعتماد`, `PO ${po.number} created — pending approval`), invalidate: ['procurement'] });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :title="{ ar: 'أمر شراء جديد', en: 'New purchase order' }" :sub="{ ar: 'سلسلة الاعتماد تُحدَّد تلقائيًا حسب القيمة (< 5k / 5–25k / > 25k)', en: 'Approval chain is derived from the total (< 5k / 5–25k / > 25k)' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'إنشاء وإرسال للاعتماد', en: 'Create & submit for approval' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.supplierCode" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supOpts" :placeholder="SELECT_PLACEHOLDER" :error="supplierError" />
      <SelectInput v-model="f.warehouseCode" :label="{ ar: 'مستودع التوريد', en: 'Delivery warehouse' }" required :options="whOpts" :placeholder="SELECT_PLACEHOLDER" />
      <DateInput v-model="f.dueDate" :label="{ ar: 'تاريخ التوريد المطلوب', en: 'Required delivery date' }" required />
      <SelectInput v-model="f.paymentTerms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS_OPTIONS" :placeholder="{ ar: 'حسب المورد', en: 'Supplier default' }" />
      <TextInput v-model="f.reference" :label="{ ar: 'مرجع (PR / RFQ)', en: 'Reference (PR / RFQ)' }" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات للمورد', en: 'Notes to supplier' }" :rows="2" :full="false" />
      <ScoreOverrideField v-if="lowScore" v-model:checked="f.overrideSupplierScore" v-model:reason="f.overrideReason" :label="t('استثناء تقييم المورد (يتطلب صلاحية اعتماد أوامر الشراء)', 'Override supplier score (requires po.approve)')" :reason-label="{ ar: 'سبب الاستثناء', en: 'Override reason' }" />
    </div>
    <div class="mt-3.5"><div class="field-l mb-1.5">{{ t('البنود', 'Lines') }} *</div><LinesEditor v-model="lines" with-price /></div>
    <div v-if="sup" class="hint !mt-3">{{ pname(sup) }} · Score <b class="num">{{ fmtNum(sup.score) }}</b> · OTIF <b class="num">{{ fmtNum(sup.otif) }}%</b> · {{ t('مهلة', 'Lead') }} <b class="num">{{ sup.leadDays }}</b> {{ t('يوم', 'days') }}</div>
  </ActionDrawer>
</template>
