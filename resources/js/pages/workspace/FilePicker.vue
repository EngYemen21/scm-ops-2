<script setup>
// Picks the files of ONE request (a new ticket, a reply, a comment, a document or a version of it) and reads them the
// way the API takes them: `{ fileName, mime, base64 }`. The rules of GET lookups (`files.maxBytes` in total,
// `maxFiles`, `extensions`) are applied here, before anything is sent, and a file that breaks one is refused with the
// reason. The server checks again — and reads the real type from the bytes.
//   <FilePicker v-model="files" :lookups="lookups" @busy="reading = $event" />        single: one file (a document)
import { computed, ref } from 'vue';
import { Btn } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { arr, fileProblem, fileRules, fileSize, readUpload } from './workspace';

const props = defineProps({
  /** [{ fileName, mime, base64, size }] */
  modelValue: { type: Array, default: () => [] },
  /** The answer of GET lookups (its `files` rules). */
  lookups: { type: Object, default: null },
  single: { type: Boolean, default: false },
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'busy']);

const input = ref(null);
const reading = ref(false);
/** Why the files of the last pick were refused. */
const problems = ref([]);
const rules = computed(() => fileRules(props.lookups));
const chosen = computed(() => arr(props.modelValue));
const totalBytes = computed(() => chosen.value.reduce((n, f) => n + (Number(f.size) || 0), 0));
const accept = computed(() => arr(rules.value.extensions).map((e) => `.${e}`).join(',') || null);
const rulesLine = computed(() => {
  const types = arr(rules.value.extensions).join('، ');
  const cap = props.single ? t(`حتى ${fileSize(rules.value.maxBytes)}`, `up to ${fileSize(rules.value.maxBytes)}`)
    : t(`حتى ${fmtNum(rules.value.maxFiles)} ملفات، مجموعها ${fileSize(rules.value.maxBytes)}`, `up to ${fmtNum(rules.value.maxFiles)} files, ${fileSize(rules.value.maxBytes)} in total`);
  return types ? `${cap} · ${types}` : cap;
});

const browse = () => input.value?.click();
async function onPick(e) {
  const picked = Array.from(e.target.files || []);
  e.target.value = ''; // the same file can be picked again after it was taken off
  if (!picked.length) return;
  const kept = props.single ? [] : [...chosen.value];
  const used = { bytes: kept.reduce((n, f) => n + (Number(f.size) || 0), 0), count: kept.length };
  const refused = [];
  reading.value = true; emit('busy', true);
  try {
    for (const file of props.single ? picked.slice(0, 1) : picked) {
      if (kept.some((f) => f.fileName === file.name && f.size === file.size)) continue; // picked twice
      const problem = fileProblem(file, props.single ? { ...rules.value, maxFiles: 1 } : rules.value, used);
      if (problem) { refused.push(problem); continue; }
      try {
        kept.push(await readUpload(file));
        used.bytes += file.size; used.count += 1;
      } catch (err) { refused.push({ ar: err.message, en: err.message }); }
    }
  } finally { reading.value = false; emit('busy', false); }
  problems.value = refused;
  emit('update:modelValue', kept);
}
function takeOff(i) {
  problems.value = [];
  emit('update:modelValue', chosen.value.filter((_, at) => at !== i));
}
</script>

<template>
  <div class="field full">
    <label v-if="label" class="field-l">{{ bi(label) }}<span v-if="required" class="text-bad"> *</span></label>
    <input ref="input" type="file" class="hidden" :multiple="!single" :accept="accept" :disabled="disabled" @change="onPick">
    <div class="row wrap">
      <Btn tone="outline" size="sm" :loading="reading" :disabled="disabled" :label="single ? (chosen.length ? { ar: 'اختيار ملف آخر', en: 'Pick another file' } : { ar: 'اختيار ملف', en: 'Pick a file' }) : { ar: '+ إرفاق ملفات', en: '+ Attach files' }" @click="browse" />
      <span class="field-hint">{{ rulesLine }}</span>
    </div>
    <div v-if="chosen.length" class="mt-1 overflow-hidden rounded-xl border border-line-2">
      <div v-for="(f, i) in chosen" :key="`${f.fileName}:${i}`" class="flex items-center gap-2 border-t border-line-2 px-3 py-1.5 first:border-t-0">
        <span class="min-w-0 flex-1 break-words text-[10.5px] font-bold [text-align:match-parent]" dir="auto">{{ f.fileName }}</span>
        <span class="num ltr flex-none text-[9.5px] text-muted">{{ fileSize(f.size) }}</span>
        <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('إزالة الملف', 'Take the file off')" :disabled="disabled" @click="takeOff(i)">✕</button>
      </div>
      <div v-if="!single && chosen.length > 1" class="border-t border-line-2 bg-soft px-3 py-1 text-[9.5px] font-bold text-muted">{{ t('المجموع', 'Total') }}: <span class="num ltr inline-block">{{ fileSize(totalBytes) }}</span></div>
    </div>
    <div v-if="problems.length" class="banner amber !mb-0 mt-1.5" role="alert">
      <ul class="!m-0 !ps-4"><li v-for="(p, i) in problems" :key="i">{{ bi(p) }}</li></ul>
    </div>
  </div>
</template>
