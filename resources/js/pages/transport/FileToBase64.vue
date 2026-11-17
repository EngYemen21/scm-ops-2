<script setup>
// File input → base64 data URL (delivery photo / receipt), sent in the request body.
// `max-side` shrinks an image in the browser before it is sent: the longest side is scaled down to it and the picture is
// re-encoded as JPEG at `quality` — a phone photo of several MB becomes a few hundred KB (the POD accepts 2 MB).
//   <FileToBase64 v-model="photo" :label="t('صورة', 'Photo')" capture :max-side="1280" :quality="0.7" />
import { computed, onBeforeUnmount, ref } from 'vue';
import { Btn } from '@/components';
import { fmtNum, t } from '@/i18n';

const props = defineProps({
  modelValue: { type: String, default: undefined },
  label: { type: String, default: '' },
  accept: { type: String, default: 'image/*' },
  /** Open the rear camera on phones. */
  capture: { type: Boolean, default: false },
  error: { type: String, default: null },
  /** Longest side in pixels after compression; 0 = send the file as it is. */
  maxSide: { type: Number, default: 0 },
  /** JPEG quality (0–1) used when compressing. */
  quality: { type: Number, default: 0.7 },
  /** Text shown next to the thumbnail once a file is captured. */
  note: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const busy = ref(false);
let reader = null;
let alive = true;

/** Decoded size of a data URL in KB (what the server will count). */
const sizeKb = computed(() => {
  const b64 = String(props.modelValue || '').split(',')[1] || '';
  return b64 ? Math.max(1, Math.round((b64.length * 3) / 4 / 1024)) : 0;
});

/** Scales the picture down to `maxSide` and re-encodes it as JPEG. Resolves the original data URL when it cannot. */
function compress(dataUrl) {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      try {
        const scale = Math.min(1, props.maxSide / Math.max(img.naturalWidth || 1, img.naturalHeight || 1));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; // JPEG has no transparency
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const out = canvas.toDataURL('image/jpeg', props.quality);
        resolve(out && out.startsWith('data:image/jpeg') ? out : dataUrl);
      } catch { resolve(dataUrl); }
    };
    img.onerror = () => resolve(dataUrl);
    img.src = dataUrl;
  });
}

function onFile(e) {
  const f = e.target.files?.[0];
  if (!f) { emit('update:modelValue', undefined); return; }
  busy.value = true;
  reader = new FileReader();
  reader.onerror = () => { busy.value = false; };
  reader.onload = async () => {
    let url = String(reader.result);
    if (props.maxSide > 0 && String(f.type).startsWith('image/')) url = await compress(url);
    if (!alive) return;
    busy.value = false;
    emit('update:modelValue', url);
  };
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
    <div v-if="busy" class="mt-1.5 text-[9.5px] font-bold text-muted">{{ t('جارٍ تجهيز الصورة…', 'Preparing the picture…') }}</div>
    <div v-else-if="modelValue" class="row mt-1.5">
      <img v-if="modelValue.startsWith('data:image')" :src="modelValue" alt="" class="h-12 rounded-lg border border-line">
      <span class="text-[9.5px] font-extrabold text-ok">{{ note || t('تم الالتقاط ✓', 'Captured ✓') }} <span class="num text-muted">· {{ fmtNum(sizeKb) }} KB</span></span>
      <Btn size="sm" tone="ghost" :label="{ ar: 'إزالة', en: 'Remove' }" @click="remove" />
    </div>
    <div v-if="error" class="field-err">{{ error }}</div>
  </div>
</template>
