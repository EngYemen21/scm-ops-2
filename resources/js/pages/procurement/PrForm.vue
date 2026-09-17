<script setup>
// Purchase requisition (multi-line). POST /procurement/pr, then (unless saved as a draft) POST /procurement/pr/:number/submit.
//   <PrForm :open :initial="{ warehouseCode, lines, justification, priority }" @close @done="(pr) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, optV } from '@/components';
import { t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import LinesEditor from './LinesEditor.vue';
import { SELECT_PLACEHOLDER, linesValid, plusDays, useWarehouseOptions } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initial: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const whOpts = useWarehouseOptions();

const PRIORITIES = [['normal', { ar: 'عادية', en: 'Normal' }], ['urgent', { ar: 'عاجلة — نافد/حرج', en: 'Urgent — out of stock' }], ['low', { ar: 'منخفضة', en: 'Low' }]];
const COST_CENTERS = [['ops', { ar: 'العمليات', en: 'Operations' }], ['sales', { ar: 'المبيعات', en: 'Sales' }], ['fr', { ar: 'الأسطول', en: 'Fleet' }]];
const ACTIONS = [['submit', { ar: 'إرسال للمراجعة', en: 'Submit' }], ['draft', { ar: 'حفظ كمسودة', en: 'Save as draft' }]];

const blank = () => ({ warehouseCode: '', needDate: plusDays(7), priority: 'normal', justification: '', costCenter: 'ops', submit: true });
const f = ref(blank());
const lines = ref([]);
watch(() => props.open, (open) => {
  if (!open) return;
  const init = props.initial || {};
  f.value = { ...blank(), warehouseCode: init.warehouseCode || (whOpts.value[0] ? optV(whOpts.value[0]) : '') || '', priority: init.priority || 'normal', justification: init.justification || '' };
  lines.value = init.lines || [];
  act.clearError();
}, { immediate: true });

const ok = computed(() => !!f.value.warehouseCode && f.value.justification.trim().length > 0 && linesValid(lines.value, false));

async function submit() {
  const v = f.value;
  const body = { warehouseCode: v.warehouseCode, needDate: v.needDate || undefined, priority: v.priority, justification: v.justification, costCenter: v.costCenter || undefined, lines: lines.value.map((l) => ({ sku: l.sku, qty: Number(l.qty), price: l.price != null ? Number(l.price) : undefined })) };
  const r = await act.run(async () => {
    const pr = await api.postIdempotent('/procurement/pr', body);
    return v.submit ? api.post(`/procurement/pr/${pr.number}/submit`) : pr;
  }, { success: (pr) => t(`أُنشئ طلب الشراء ${pr.number}${v.submit ? ' — بانتظار مراجعة المشتريات' : ' (مسودة)'}`, `PR ${pr.number} created${v.submit ? ' — pending review' : ' (draft)'}`), invalidate: ['procurement'] });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :title="{ ar: 'طلب شراء داخلي Requisition', en: 'Purchase requisition' }" :sub="{ ar: 'يُراجَع من المشتريات ثم يُحوَّل إلى RFQ أو أمر شراء', en: 'Reviewed by procurement, then converted to RFQ or PO' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="f.submit ? { ar: 'إرسال للمراجعة', en: 'Submit for review' } : { ar: 'حفظ كمسودة', en: 'Save draft' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.warehouseCode" :label="{ ar: 'المستودع الطالب', en: 'Requesting warehouse' }" required :options="whOpts" :placeholder="SELECT_PLACEHOLDER" />
      <DateInput v-model="f.needDate" :label="{ ar: 'تاريخ الحاجة', en: 'Need-by date' }" required />
      <SelectInput v-model="f.priority" :label="{ ar: 'الأولوية', en: 'Priority' }" :options="PRIORITIES" />
      <SelectInput v-model="f.costCenter" :label="{ ar: 'مركز التكلفة', en: 'Cost center' }" :options="COST_CENTERS" />
      <TextArea v-model="f.justification" :label="{ ar: 'المبرر', en: 'Justification' }" required :rows="2" :full="false" />
      <SelectInput :model-value="f.submit ? 'submit' : 'draft'" :label="{ ar: 'الإجراء', en: 'Action' }" :options="ACTIONS" @update:model-value="(v) => (f.submit = v === 'submit')" />
    </div>
    <div class="mt-3.5"><div class="field-l mb-1.5">{{ t('البنود', 'Lines') }} *</div><LinesEditor v-model="lines" /></div>
  </ActionDrawer>
</template>
