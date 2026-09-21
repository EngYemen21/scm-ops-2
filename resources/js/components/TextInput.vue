<script setup>
// <TextInput v-model="name" :label="{ ar, en }" required :error="errors.name" small dir="ltr" mono @enter="save" />
// `scan` adds the camera button: what is read replaces the value and fires `enter`, exactly like a handheld scanner.
import { onMounted, ref, useId } from 'vue';
import { bi } from '../i18n';
import Field from './Field.vue';
import ScanButton from './ScanButton.vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  /** Class for the field wrapper (width, flex-grow …); a plain `class` lands on the <input>. */
  fieldClass: { type: [String, Array, Object], default: null },
  autofocus: Boolean,
  label: { type: [String, Object], default: null },
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: Boolean,
  small: Boolean, disabled: Boolean, mono: Boolean,
  /** Offer camera scanning for this field (SKU, barcode, bin, batch, document number). */
  scan: Boolean,
  placeholder: { type: [String, Object], default: null },
  type: { type: String, default: 'text' },
  dir: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue', 'enter']);
const id = useId();
const el = ref(null);
onMounted(() => { if (props.autofocus) el.value?.focus(); });
function onScan(code) { emit('update:modelValue', code); emit('enter'); }
</script>

<template>
  <Field :class="fieldClass" :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <div v-if="scan" class="scan-wrap has-btn">
      <input :id="id" ref="el" v-bind="$attrs" :type="type" class="inp" :class="{ sm: small, err: !!error, num: mono }" :value="modelValue ?? ''" :placeholder="placeholder ? bi(placeholder) : null" :dir="dir" :disabled="disabled"
             @input="emit('update:modelValue', $event.target.value)" @keydown.enter.prevent="emit('enter')">
      <ScanButton inside :disabled="disabled" :title="label || placeholder || undefined" @detected="onScan" />
    </div>
    <input v-else :id="id" ref="el" v-bind="$attrs" :type="type" class="inp" :class="{ sm: small, err: !!error, num: mono }" :value="modelValue ?? ''" :placeholder="placeholder ? bi(placeholder) : null" :dir="dir" :disabled="disabled"
           @input="emit('update:modelValue', $event.target.value)" @keydown.enter.prevent="emit('enter')">
  </Field>
</template>
