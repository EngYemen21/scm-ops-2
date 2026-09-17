<script setup>
// Native date (`YYYY-MM-DD`) or, with `time`, datetime-local input. LTR + Quicksand.
import { computed, useId } from 'vue';
import Field from './Field.vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: [String, Object], default: null },
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: Boolean,
  small: Boolean, disabled: Boolean, time: Boolean,
  min: { type: String, default: null }, max: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);
const id = useId();
const shown = computed(() => (props.modelValue ? (props.time ? props.modelValue.slice(0, 16) : props.modelValue.slice(0, 10)) : ''));
</script>

<template>
  <Field :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <input :id="id" v-bind="$attrs" :type="time ? 'datetime-local' : 'date'" dir="ltr" class="inp num" :class="{ sm: small, err: !!error }" :value="shown" :min="min" :max="max" :disabled="disabled" @input="emit('update:modelValue', $event.target.value)">
  </Field>
</template>
