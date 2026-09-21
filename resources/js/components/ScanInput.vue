<script setup>
// Barcode input: LTR, Quicksand, barcode icon. Enter (or the scanner's suffix) emits `submit` with the trimmed code.
// On phones and tablets a camera button opens BarcodeScanner; a camera read behaves exactly like a scanned + Enter.
//   <ScanInput :placeholder="{ ar: 'امسح الموقع', en: 'Scan bin' }" @submit="onScan" />        (uncontrolled, clears itself)
//   <ScanInput v-model="code" :clear-on-submit="false" @submit="…" />                          (controlled)
import { computed, onMounted, ref, useId } from 'vue';
import { canScanWithCamera } from '../composables/viewport';
import { bi, t } from '../i18n';
import Icon from '../layout/Icon.vue';
import BarcodeScanner from './BarcodeScanner.vue';
import Field from './Field.vue';

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
const camera = ref(false);
const cameraOffered = computed(() => !props.disabled && canScanWithCamera());
function onCamera(code) { camera.value = false; set(code); submit(); }
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
  <Field :class="fieldClass" :label="label" :required="required" :error="error" :hint="hint" :full="full" :for="id">
    <div class="scan-wrap">
      <span class="scan-ico"><Icon name="scan" :size="14" color="#a8a4b8" /></span>
      <input :id="id" ref="input" v-bind="$attrs" type="text" dir="ltr" autocomplete="off" spellcheck="false" class="inp scan" :class="{ sm: small, err: !!error }" :value="shown" :placeholder="placeholder ? bi(placeholder) : 'Scan'" :disabled="disabled"
             @input="set($event.target.value)" @keydown.enter.prevent="submit">
      <button v-if="cameraOffered" type="button" class="scan-cam" :aria-label="t('مسح بالكاميرا', 'Scan with the camera')" @click="camera = true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M7 12h10" /></svg>
      </button>
    </div>
    <BarcodeScanner :open="camera" :title="label || placeholder || undefined" @detected="onCamera" @close="camera = false" />
  </Field>
</template>
