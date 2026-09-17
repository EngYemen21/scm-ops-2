<script setup>
// <TextInput v-model="name" :label="{ ar, en }" required :error="errors.name" small dir="ltr" mono @enter="save" />
import { useId } from 'vue';
import { bi } from '../i18n';
import Field from './Field.vue';

defineOptions({ inheritAttrs: false });
defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: [String, Object], default: null },
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: Boolean,
  small: Boolean, disabled: Boolean, mono: Boolean,
  placeholder: { type: [String, Object], default: null },
  type: { type: String, default: 'text' },
  dir: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue', 'enter']);
const id = useId();
</script>

<template>
  <Field :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <input :id="id" v-bind="$attrs" :type="type" class="inp" :class="{ sm: small, err: !!error, num: mono }" :value="modelValue ?? ''" :placeholder="placeholder ? bi(placeholder) : null" :dir="dir" :disabled="disabled"
           @input="emit('update:modelValue', $event.target.value)" @keydown.enter.prevent="emit('enter')">
  </Field>
</template>
