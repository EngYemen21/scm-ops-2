<script setup>
// Numeric input (LTR, Quicksand). v-model is a number or null. Clamps to min/max on blur; ↑/↓ use `step`.
import { computed, onMounted, ref, useId } from 'vue';
import { bi } from '../i18n';
import Field from './Field.vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  fieldClass: { type: [String, Array, Object], default: null },
  autofocus: Boolean,
  label: { type: [String, Object], default: null },
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: Boolean,
  small: Boolean, disabled: Boolean, center: Boolean,
  placeholder: { type: [String, Object], default: null },
  min: { type: Number, default: null }, max: { type: Number, default: null }, step: { type: Number, default: null },
});
const emit = defineEmits(['update:modelValue', 'enter']);
const id = useId();
const raw = ref(null);
const el = ref(null);
onMounted(() => { if (props.autofocus) el.value?.focus(); });
const shown = computed(() => raw.value ?? (props.modelValue == null || props.modelValue === '' ? '' : String(props.modelValue)));

function onInput(e) {
  const s = e.target.value.replace(/[^\d.\-]/g, '');
  raw.value = s;
  const n = s === '' || s === '-' || s === '.' ? null : Number(s);
  emit('update:modelValue', n == null || Number.isNaN(n) ? null : n);
}
function onBlur() {
  raw.value = null;
  const v = props.modelValue;
  if (typeof v === 'number') {
    if (props.min != null && v < props.min) emit('update:modelValue', props.min);
    else if (props.max != null && v > props.max) emit('update:modelValue', props.max);
  }
}
function onArrow(sign) {
  if (!props.step) return;
  raw.value = null;
  emit('update:modelValue', (Number(props.modelValue) || 0) + sign * props.step);
}
</script>

<template>
  <Field :class="fieldClass" :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <input :id="id" ref="el" v-bind="$attrs" type="text" inputmode="decimal" dir="ltr" class="inp num" :class="{ sm: small, err: !!error, 'text-center': center }" :value="shown" :placeholder="placeholder ? bi(placeholder) : null" :disabled="disabled"
           @input="onInput" @blur="onBlur" @keydown.enter.prevent="emit('enter')" @keydown.up.prevent="onArrow(1)" @keydown.down.prevent="onArrow(-1)">
  </Field>
</template>
