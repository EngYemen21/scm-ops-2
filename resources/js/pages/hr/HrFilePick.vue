<script setup>
// Picks one file (a PDF or a picture, at most 5 MB — the limits of the API) and hands it over as the API takes it:
// `{ fileName, mime, base64 }`. On a phone the picker offers the camera for a receipt.
//   <HrFilePick v-model="file" :label="{ ar: 'صورة الوثيقة', en: 'Scan' }" />        v-model is the payload or null
import { ref } from 'vue';
import { Btn } from '@/components';
import { bi, t } from '@/i18n';
import { FILE_ACCEPT, fileSize, readFile } from './shared';

defineProps({
  modelValue: { type: Object, default: null },
  label: { type: [String, Object], default: null },
  hint: { type: [String, Object], default: null },
  /** A slim one-line picker for table rows and line editors. */
  small: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const busy = ref(false);
const problem = ref(null);
async function onPick(e) {
  const f = e.target.files?.[0];
  problem.value = null;
  if (!f) return;
  busy.value = true;
  try { emit('update:modelValue', await readFile(f)); } catch (err) { problem.value = err.message; emit('update:modelValue', null); } finally { busy.value = false; if (input.value) input.value.value = ''; }
}
const open = () => input.value?.click();
const clear = () => { problem.value = null; emit('update:modelValue', null); };
</script>

<template>
  <div class="field" :class="{ full: !small }">
    <label v-if="label" class="field-l">{{ bi(label) }}</label>
    <input ref="input" type="file" class="hidden" :accept="FILE_ACCEPT" @change="onPick">
    <div class="row wrap !gap-1.5">
      <Btn :size="small ? 'sm' : 'md'" tone="softPurple" :loading="busy" :label="modelValue ? { ar: 'تغيير الملف', en: 'Change the file' } : { ar: '📎 إرفاق ملف', en: '📎 Attach a file' }" @click="open" />
      <template v-if="modelValue">
        <span class="max-w-[200px] truncate text-[10px] font-extrabold text-ok" dir="auto">{{ modelValue.fileName }}</span>
        <span class="num text-[9.5px] text-muted">{{ fileSize(modelValue.size) }}</span>
        <button type="button" class="x-btn !h-6 !w-6 !text-[10px]" :aria-label="t('إزالة', 'Remove')" @click="clear">✕</button>
      </template>
    </div>
    <div v-if="problem" class="field-err">{{ problem }}</div>
    <div v-else-if="hint" class="field-hint">{{ bi(hint) }}</div>
  </div>
</template>
