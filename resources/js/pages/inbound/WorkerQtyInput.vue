<script setup>
// Large quantity box of the worker receiving screen (digits only; v-model is a number or null) with its caption below.
import { parseQty } from './shared';

defineProps({
  modelValue: { type: Number, default: null },
  label: { type: String, required: true },
  /** Text colour of the number and the caption. */
  color: { type: String, required: true },
  border: { type: String, required: true },
  width: { type: Number, default: 62 },
});
const emit = defineEmits(['update:modelValue']);
// Keep the box in sync with the sanitised value (letters never show).
function onInput(e) { const n = parseQty(e.target.value); e.target.value = n ?? ''; emit('update:modelValue', n); }
</script>

<template>
  <div class="text-center">
    <input :value="modelValue ?? ''" dir="ltr" inputmode="numeric" class="num h-10 rounded-[10px] border-[1.5px] border-solid bg-white text-center text-[15px] outline-none" :style="{ width: width + 'px', borderColor: border, color }" @input="onInput">
    <div class="mt-0.5 text-[8px] font-extrabold" :style="{ color }">{{ label }}</div>
  </div>
</template>
