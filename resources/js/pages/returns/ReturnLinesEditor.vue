<script setup>
// Lines editor of the new-return form: rows of { sku, qty, batchNo } (always shows at least one empty row).
import { computed } from 'vue';
import { Btn, NumberInput, TextInput } from '@/components';
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
const GRID = 'grid grid-cols-[minmax(120px,1.4fr)_80px_110px_32px] gap-1.5';
</script>

<template>
  <div>
    <div class="field-l">{{ t('الأصناف', 'Lines') }} <span class="text-bad">*</span></div>
    <div :class="GRID" class="px-0.5 pb-1 text-[9px] font-extrabold text-faint"><div>SKU</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('الدفعة', 'Batch') }}</div><div /></div>
    <div v-for="(r, i) in rows" :key="i" :class="GRID" class="mb-1.5 items-center">
      <TextInput :model-value="r.sku" small dir="ltr" placeholder="SKU" mono @update:model-value="set(i, { sku: $event })" />
      <NumberInput :model-value="r.qty" small :min="1" @update:model-value="set(i, { qty: $event })" />
      <TextInput :model-value="r.batchNo" small dir="ltr" :placeholder="{ ar: 'اختياري', en: 'optional' }" @update:model-value="set(i, { batchNo: $event })" />
      <button type="button" class="x-btn" aria-label="remove" @click="remove(i)">✕</button>
    </div>
    <Btn size="sm" tone="soft" :label="{ ar: '+ سطر', en: '+ Line' }" @click="add" />
    <div v-if="error" class="field-err mt-1">{{ error }}</div>
  </div>
</template>
