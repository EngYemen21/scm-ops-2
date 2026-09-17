<script setup>
import { onMounted, ref, useId } from 'vue';
import { bi } from '../i18n';
import Field from './Field.vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { type: String, default: '' },
  fieldClass: { type: [String, Array, Object], default: null },
  autofocus: Boolean,
  label: { type: [String, Object], default: null },
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: { type: Boolean, default: true },
  disabled: Boolean,
  placeholder: { type: [String, Object], default: null },
  rows: { type: Number, default: 3 },
});
const emit = defineEmits(['update:modelValue']);
const id = useId();
const el = ref(null);
onMounted(() => { if (props.autofocus) el.value?.focus(); });
</script>

<template>
  <Field :class="fieldClass" :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <textarea :id="id" ref="el" v-bind="$attrs" class="inp" :class="{ err: !!error }" :rows="rows" :value="modelValue ?? ''" :placeholder="placeholder ? bi(placeholder) : null" :disabled="disabled" @input="emit('update:modelValue', $event.target.value)" />
  </Field>
</template>
