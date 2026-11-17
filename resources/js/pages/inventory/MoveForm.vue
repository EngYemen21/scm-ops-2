<script setup>
// POST /inventory/move — bin-to-bin inside one warehouse (inventory.move).
//   <MoveForm :open="form === 'move'" :initial="stockRef" @close="form = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { MOVE_REASONS, dictOptions } from './shared';
import { batchField, cleanBatch, skuField, useWhOpts } from './stockForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** StockRef: { sku, warehouseCode, binCode (→ fromBin), batchNo } */
  initial: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const whOpts = useWhOpts();
const sameBin = (a, b) => String(a || '').trim().toUpperCase() === String(b || '').trim().toUpperCase();
/**
 * Bins whose stock belongs to a document in progress (the server refuses them as a source: MOVE_FROM_STAGING) → the
 * step of that document that takes the stock out.
 */
const DOCUMENT_BINS = {
  PACK: { ar: 'رصيد PACK يخص طلب تجهيز جرت تعبئته — استخدم «فك التعبئة» من شاشة التعبئة', en: 'PACK stock belongs to a packed fulfilment order — use “unpack” on the packing screen' },
  'STG-OUT': { ar: 'رصيد STG-OUT يخص طلب تجهيز جرى التقاطه — استخدم «إرجاع للرف» من شاشة الالتقاط', en: 'STG-OUT stock belongs to a picked fulfilment order — use “put back” on the picking screen' },
  'STG-IN': { ar: 'رصيد STG-IN استلام بانتظار التخزين — نفّذ مهمة التخزين (Putaway) من شاشة الاستلام', en: 'STG-IN stock is a receipt awaiting putaway — complete its putaway task on the receiving screen' },
  'RET-01': { ar: 'رصيد RET-01 مرتجع بانتظار القرار — سجّل قرار المرتجع من شاشة المرتجعات', en: 'RET-01 stock is a return awaiting its decision — record the decision on the returns screen' },
};
const documentBin = (v) => { const why = DOCUMENT_BINS[String(v || '').trim().toUpperCase()]; return why ? t(why.ar, why.en) : null; };
const fields = computed(() => [
  skuField(!!props.initial?.sku),
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts.value, required: true },
  { k: 'fromBin', label: { ar: 'من موقع', en: 'From bin' }, type: 'scan', required: true, dir: 'ltr', validate: documentBin },
  { k: 'toBin', label: { ar: 'إلى موقع', en: 'To bin' }, type: 'scan', required: true, dir: 'ltr', validate: (v, vals) => (sameBin(v, vals.fromBin) ? t('موقع المصدر والوجهة متطابقان', 'Source and destination are the same') : null) },
  batchField,
  { k: 'qty', label: { ar: 'الكمية', en: 'Qty' }, type: 'num', required: true, min: 1, step: 1, validate: (v) => (Number(v) <= 0 ? t('الكمية يجب أن تكون موجبة', 'Qty must be positive') : null) },
  { k: 'reason', label: { ar: 'سبب النقل', en: 'Reason' }, type: 'select', required: true, def: 'slot', opts: dictOptions(MOVE_REASONS), hint: { ar: 'حجر/تالف: الوجهة يجب أن تكون في منطقة الحجر/التالف', en: 'Quarantine/damage: destination must be in that zone' } },
]);
const init = computed(() => ({ sku: props.initial?.sku, warehouseCode: props.initial?.warehouseCode, fromBin: props.initial?.binCode, batchNo: props.initial?.batchNo || undefined }));
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'نقل بين المواقع', en: 'Bin-to-bin move' }" :sub="{ ar: 'المحجوز لا يُنقل · قواعد الموقع (مبرد/مجمد) تُطبّق على الوجهة · لا نقل من PACK / STG-OUT / STG-IN / RET-01', en: 'Reserved stock stays · destination must pass location rules · no moves out of PACK / STG-OUT / STG-IN / RET-01' }"
              :fields="fields" :initial="init" :submit-label="{ ar: 'تنفيذ النقل', en: 'Move' }"
              :submit="(v) => api.postIdempotent('/inventory/move', cleanBatch(v))"
              :action="{ success: { ar: 'تم النقل وتسجيل الحركة', en: 'Moved and recorded' }, invalidate: ['inventory', 'warehouses', 'bins'] }"
              @close="emit('close')" @done="(res) => emit('done', res)" />
</template>
