<script setup>
// Barcode input: LTR, Quicksand, barcode icon. Enter (or the scanner's suffix) emits `submit` with the trimmed code.
// On phones and tablets a camera button opens BarcodeScanner; a camera read behaves exactly like a scanned + Enter.
//   <ScanInput :placeholder="{ ar: 'امسح الموقع', en: 'Scan bin' }" @submit="onScan" />        (uncontrolled, clears itself)
//   <ScanInput v-model="code" :clear-on-submit="false" @submit="…" />                          (controlled)
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { bi } from '../i18n';
import { registerScanTarget } from '../stores/ui';
import Icon from '../layout/Icon.vue';
import Field from './Field.vue';
import ScanButton from './ScanButton.vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { type: String, default: undefined },
  label: { type: [String, Object], default: null },
  fieldClass: { type: [String, Array, Object], default: null },
  autofocus: Boolean,
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: Boolean,
  small: Boolean, disabled: Boolean,
  placeholder: { type: [String, Object], default: null },
  clearOnSubmit: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue', 'submit']);
const id = useId();
const inner = ref('');
const input = ref(null);
const shown = computed(() => props.modelValue ?? inner.value);
onMounted(() => { if (props.autofocus) input.value?.focus(); });

function set(s) { inner.value = s; emit('update:modelValue', s); }
function submit() {
  const code = shown.value.trim();
  if (!code) return;
  emit('submit', code);
  if (props.clearOnSubmit) set('');
}
function onCamera(code) { set(code); submit(); }
// the topbar scan button (worker / driver) delivers its read to the page's scan field
const unregister = registerScanTarget((code) => { if (!props.disabled) onCamera(code); });
onBeforeUnmount(unregister);
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
  <Field :class="fieldClass" :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <div class="scan-wrap has-btn">
      <span class="scan-ico"><Icon name="scan" :size="14" color="#a8a4b8" /></span>
      <input :id="id" ref="input" v-bind="$attrs" type="text" dir="ltr" autocomplete="off" spellcheck="false" class="inp scan" :class="{ sm: small, err: !!error }" :value="shown" :placeholder="placeholder ? bi(placeholder) : 'Scan'" :disabled="disabled"
             @input="set($event.target.value)" @keydown.enter.prevent="submit">
      <ScanButton inside :disabled="disabled" :title="label || placeholder || undefined" @detected="onCamera" />
    </div>
  </Field>
</template>
