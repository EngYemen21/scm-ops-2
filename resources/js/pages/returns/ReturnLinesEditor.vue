<script setup>
// Lines editor of the new-return form (goods that arrive): rows of { sku, qty, batchNo } (always shows at least one empty row).
// The batch is optional here — it must be a batch that exists for the product (the server refuses an unknown one); when it is
// left empty a tracked product gets its batch at the decision.
import { computed } from 'vue';
import { Btn, NumberInput, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const blank = () => ({ sku: '', qty: null, batchNo: '' });
const rows = computed(() => (props.modelValue.length ? props.modelValue : [blank()]));
const set = (i, patch) => emit('update:modelValue', rows.value.map((r, j) => (j === i ? { ...r, ...patch } : r)));
const remove = (i) => emit('update:modelValue', rows.value.filter((_, j) => j !== i));
const add = () => emit('update:modelValue', [...rows.value, blank()]);
const GRID = 'grid grid-cols-[minmax(120px,1.4fr)_80px_150px_32px] gap-1.5';
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
</script>

<template>
  <div>
    <div class="field-l">{{ t('الأصناف', 'Lines') }} <span class="text-bad">*</span></div>
    <div :class="GRID" class="le-head px-0.5 pb-1 text-[9px] font-extrabold text-faint"><div>SKU</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('الدفعة', 'Batch') }}</div><div /></div>
    <div v-for="(r, i) in rows" :key="i" :class="GRID" class="le-row mb-1.5 items-center">
      <TextInput scan :label="cap('الصنف SKU', 'SKU')" :model-value="r.sku" small dir="ltr" placeholder="SKU" mono @update:model-value="set(i, { sku: $event })" />
      <NumberInput :label="cap('الكمية', 'Qty')" :model-value="r.qty" small :min="1" @update:model-value="set(i, { qty: $event })" />
      <TextInput scan :label="cap('الدفعة', 'Batch')" :model-value="r.batchNo" small dir="ltr" :placeholder="{ ar: 'دفعة مسجلة — اختياري', en: 'existing batch — optional' }" @update:model-value="set(i, { batchNo: $event })" />
      <button type="button" class="x-btn" aria-label="remove" @click="remove(i)">✕</button>
    </div>
    <Btn size="sm" tone="soft" :label="{ ar: '+ سطر', en: '+ Line' }" @click="add" />
    <div class="mt-1 text-[9px] text-faint">{{ t('الدفعة يجب أن تكون مسجلة للصنف؛ اتركها فارغة إن لم تُعرف — تُحدَّد عند القرار للأصناف المتتبَّعة بالصلاحية.', 'The batch must exist for the item; leave it empty when unknown — a tracked item gets it at the decision.') }}</div>
    <div v-if="error" class="field-err mt-1">{{ error }}</div>
  </div>
</template>
