<script setup>
// <SelectInput v-model="status" :options="[['draft', { ar, en }], { v: 'sent', l: 'Sent', disabled: true }]" :placeholder="{ ar: '— اختر —', en: '— select —' }" />
// An option is `[value, label]` or `{ v, l, disabled }`; labels are strings or {ar,en}. `placeholder` adds an empty first option.
import { useId } from 'vue';
import { bi } from '../i18n';
import Field from './Field.vue';
import { optL, optV } from './options';

defineOptions({ inheritAttrs: false });
defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, required: true },
  label: { type: [String, Object], default: null },
  fieldClass: { type: [String, Array, Object], default: null },
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: Boolean,
  small: Boolean, disabled: Boolean,
  placeholder: { type: [String, Object], default: null },
});
const emit = defineEmits(['update:modelValue']);
const id = useId();
</script>

<template>
  <Field :class="fieldClass" :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <select :id="id" v-bind="$attrs" class="inp" :class="{ sm: small, err: !!error }" :value="modelValue ?? ''" :disabled="disabled" @change="emit('update:modelValue', $event.target.value)">
      <option v-if="placeholder != null" value="">{{ bi(placeholder) }}</option>
      <option v-for="o in options" :key="optV(o)" :value="optV(o)" :disabled="!Array.isArray(o) && o.disabled">{{ optL(o) }}</option>
    </select>
  </Field>
</template>
