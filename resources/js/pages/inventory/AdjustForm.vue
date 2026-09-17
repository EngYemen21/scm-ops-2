<script setup>
// POST /inventory/adjust — qtyDelta ± with a mandatory reason (inventory.adjust).
//   <AdjustForm :open="form === 'adjust'" :initial="stockRef" @close="form = null" @done="…" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { batchField, cleanBatch, skuField, useWhOpts } from './stockForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** StockRef: { sku, warehouseCode, binCode, batchNo } */
  initial: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const whOpts = useWhOpts();
const fields = computed(() => [
  skuField(!!props.initial?.sku),
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts.value, required: true },
  { k: 'binCode', label: { ar: 'الموقع Bin', en: 'Bin' }, type: 'scan', required: true, dir: 'ltr' },
  batchField,
  { k: 'qtyDelta', label: { ar: 'الكمية ± (سالب = نقص)', en: 'Qty delta ± (negative = shrink)' }, type: 'num', required: true, step: 1, validate: (v) => (Number(v) === 0 ? t('الفرق لا يمكن أن يكون صفرًا', 'Delta cannot be zero') : null) },
  { k: 'reason', label: { ar: 'سبب التسوية', en: 'Reason' }, type: 'area', required: true, ph: { ar: 'مثال: فرق جرد موضعي / تلف مكتشف …', en: 'e.g. spot-count variance / discovered damage …' } },
]);
const init = computed(() => ({ sku: props.initial?.sku, warehouseCode: props.initial?.warehouseCode, binCode: props.initial?.binCode, batchNo: props.initial?.batchNo || undefined }));
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'تسوية رصيد يدوية', en: 'Manual stock adjustment' }" :sub="{ ar: 'تُسجل كحركة adj في السجل — لا رصيد سالب ولا تجاوز للمحجوز', en: 'Posted as an adj movement — never below reserved / negative' }"
              :fields="fields" :initial="init" :submit-label="{ ar: 'تسجيل التسوية', en: 'Post adjustment' }"
              :submit="(v) => api.postIdempotent('/inventory/adjust', cleanBatch(v))"
              :action="{ success: { ar: 'تم تسجيل التسوية وقيدها في السجل', en: 'Adjustment posted to the ledger' }, invalidate: ['inventory', 'dashboard'] }"
              @close="emit('close')" @done="(res) => emit('done', res)" />
</template>
