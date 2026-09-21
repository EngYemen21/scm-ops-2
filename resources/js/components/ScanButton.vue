<script setup>
// The one "scan with the camera" button of the application: it opens BarcodeScanner and emits what was read.
//   <ScanButton @detected="(code, format) => …" />              standalone (search boxes, toolbars)
//   <ScanButton inside @detected="…" />                          placed inside an input (TextInput `scan`, ScanInput)
// Rendered only where a camera can work at all: a secure address (https / localhost) with camera support — phone,
// tablet, handheld or a desktop with a webcam. USB / Bluetooth scanners need no button: they type into the field.
import { ref } from 'vue';
import { canScanWithCamera } from '../composables/viewport';
import { t } from '../i18n';
import BarcodeScanner from './BarcodeScanner.vue';

defineProps({
  inside: { type: Boolean, default: false },
  title: { type: [String, Object], default: undefined },
  dark: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['detected']);
const open = ref(false);
const offered = canScanWithCamera();
function onDetected(code, format) { open.value = false; emit('detected', code, format); }
</script>

<template>
  <template v-if="offered">
    <button type="button" class="scan-btn" :class="{ inside, dark }" :disabled="disabled" :title="t('مسح بالكاميرا', 'Scan with the camera')" :aria-label="t('مسح بالكاميرا', 'Scan with the camera')" @click.stop="open = true">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M7 12h10" /></svg>
    </button>
    <BarcodeScanner :open="open" :title="title" @detected="onDetected" @close="open = false" />
  </template>
</template>
