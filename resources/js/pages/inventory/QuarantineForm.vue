<script setup>
// POST /inventory/quarantine — flag on/off for one balance row (inventory.adjust). No qty moves; audited.
//   <QuarantineForm :open="!!qtn" :initial="qtn" @close="qtn = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { batchField, cleanBatch, skuField, useWhOpts } from './stockForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** StockRef: { sku, warehouseCode, binCode, batchNo, quarantine (current flag → the form proposes the opposite) } */
  initial: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const whOpts = useWhOpts();
const fields = computed(() => [
  skuField(!!props.initial?.sku),
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts.value, required: true },
  { k: 'binCode', label: { ar: 'الموقع Bin', en: 'Bin' }, type: 'scan', required: true, dir: 'ltr' },
  batchField,
  { k: 'quarantine', label: { ar: 'الإجراء', en: 'Action' }, type: 'select', required: true, opts: [{ v: 'true', l: { ar: 'حجر الرصيد — يخرج من المتاح', en: 'Quarantine — removed from available' } }, { v: 'false', l: { ar: 'رفع الحجر — يعود للمتاح', en: 'Release — back to available' } }] },
  { k: 'reason', label: { ar: 'السبب', en: 'Reason' }, type: 'area', required: true, ph: { ar: 'مثال: اشتباه تلف / انحراف حرارة / قرار جودة …', en: 'e.g. suspected damage / temperature excursion / QC decision …' } },
]);
const init = computed(() => ({ sku: props.initial?.sku, warehouseCode: props.initial?.warehouseCode, binCode: props.initial?.binCode, batchNo: props.initial?.batchNo || undefined, quarantine: props.initial?.quarantine ? 'false' : 'true' }));
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'حجر / رفع حجر', en: 'Quarantine / release' }" :sub="{ ar: 'تغيير علامة على الرصيد — لا حركة كمية، يُسجل في Audit', en: 'Flag change only — no qty movement, audited' }"
              :fields="fields" :initial="init" :submit-label="{ ar: 'تطبيق', en: 'Apply' }"
              :submit="(v) => api.postIdempotent('/inventory/quarantine', { ...cleanBatch(v), quarantine: v.quarantine === 'true' })"
              :action="{ success: (r) => (r?.quarantine ? t('تم حجر الرصيد', 'Stock quarantined') : t('تم رفع الحجر', 'Quarantine released')), invalidate: ['inventory'] }"
              @close="emit('close')" @done="(res) => emit('done', res)" />
</template>
