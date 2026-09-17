<script setup>
// File input → base64 data URL (delivery photo / receipt). The data URL is sent in the request body as the reference does;
// the server stores it as an attachment reference whose status is "Integration Pending" (object storage is not connected),
// so the note below never claims an upload happened.
//   <FileToBase64 v-model="photo" :label="t('صورة', 'Photo')" capture />
import { onBeforeUnmount, ref } from 'vue';
import { Btn } from '@/components';
import { t } from '@/i18n';

defineProps({
  modelValue: { type: String, default: undefined },
  label: { type: String, default: '' },
  accept: { type: String, default: 'image/*' },
  /** Open the rear camera on phones. */
  capture: { type: Boolean, default: false },
  error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const input = ref(null);
let reader = null;
let alive = true;

function onFile(e) {
  const f = e.target.files?.[0];
  if (!f) { emit('update:modelValue', undefined); return; }
  reader = new FileReader();
  reader.onload = () => { if (alive) emit('update:modelValue', String(reader.result)); };
  reader.readAsDataURL(f);
}
function remove() {
  if (input.value) input.value.value = '';
  emit('update:modelValue', undefined);
}
onBeforeUnmount(() => { alive = false; if (reader && reader.readyState === 1) reader.abort(); });
</script>

<template>
  <div class="field">
    <label class="field-l">{{ label }}</label>
    <input ref="input" type="file" :accept="accept" :capture="capture ? 'environment' : null" class="inp !px-2.5 !py-2" :class="{ err: !!error }" @change="onFile">
    <div v-if="modelValue" class="row mt-1.5">
      <img v-if="modelValue.startsWith('data:image')" :src="modelValue" alt="" class="h-12 rounded-lg border border-line">
      <span class="text-[9.5px] font-extrabold text-ok">{{ t('تم الالتقاط ✓ — الرفع: Integration Pending', 'Captured ✓ — upload: Integration Pending') }}</span>
      <Btn size="sm" tone="ghost" :label="{ ar: 'إزالة', en: 'Remove' }" @click="remove" />
    </div>
    <div v-if="error" class="field-err">{{ error }}</div>
  </div>
</template>
