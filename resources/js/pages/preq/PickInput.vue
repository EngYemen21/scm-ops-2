<script setup>
// Pick one of many options by typing part of its name or code — for the lists a plain select is too long for (six
// hundred suppliers, four hundred customers). The options are already in hand (the master lookups): they are filtered
// here, the fifty best matches are offered, and the picked one is shown with a ✕ to change it.
//   <PickInput v-model="supplierId" :options="supplierOptions" :label="{ ar, en }" />
// An option is { v, l, sub? }: its value, its name and (optional) its code. v-model is the value ('' = nothing picked).
import { computed, onBeforeUnmount, ref } from 'vue';
import { Field } from '@/components';
import { bi, t } from '@/i18n';

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  error: { type: String, default: null },
  hint: { type: [String, Object], default: null },
  full: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  fieldClass: { type: [String, Array, Object], default: null },
  placeholder: { type: [String, Object], default: () => ({ ar: 'اكتب للبحث…', en: 'Type to search…' }) },
  /** What is shown when the picked value is not among the options (a record that was archived since). */
  missingLabel: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const LIMIT = 50;
const text = ref('');
const focused = ref(false);
let blurTimer = null;

const picked = computed(() => (props.modelValue ? props.options.find((o) => o.v === props.modelValue) || { v: props.modelValue, l: props.missingLabel || props.modelValue, sub: '' } : null));
const fold = (s) => String(s ?? '').toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي');
const matches = computed(() => {
  const words = fold(text.value).split(/\s+/).filter(Boolean);
  if (!words.length) return props.options.slice(0, LIMIT);
  const out = [];
  for (const o of props.options) {
    const hay = fold(`${o.l} ${o.sub || ''}`);
    if (words.every((w) => hay.includes(w))) { out.push(o); if (out.length >= LIMIT) break; }
  }
  return out;
});
const more = computed(() => props.options.length > matches.value.length && matches.value.length >= LIMIT);

function pick(o) { emit('update:modelValue', o.v); text.value = ''; focused.value = false; }
function clear() { emit('update:modelValue', ''); }
/** The list stays long enough for a click on one of its rows to land. */
function blur() { clearTimeout(blurTimer); blurTimer = setTimeout(() => { focused.value = false; }, 180); }
function onEnter() { if (matches.value.length === 1) pick(matches.value[0]); }
onBeforeUnmount(() => clearTimeout(blurTimer));
</script>

<template>
  <Field :class="fieldClass" :label="label" :required="required" :error="error" :hint="hint" :full="full">
    <div v-if="picked" class="row rounded-[10px] border border-line bg-soft px-2.5" :class="small ? 'h-[30px]' : 'h-[38px]'">
      <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto" :title="picked.l">{{ picked.l }}</span>
      <span v-if="picked.sub" class="num ltr flex-none text-[9.5px] text-muted">{{ picked.sub }}</span>
      <button v-if="!disabled" type="button" class="x-btn !h-6 !w-6 flex-none !text-[10px]" :aria-label="t('تغيير', 'Change')" :title="t('تغيير', 'Change')" @click="clear">✕</button>
    </div>
    <div v-else class="relative">
      <input v-model="text" type="search" class="inp" :class="{ sm: small, err: !!error }" :placeholder="bi(placeholder)" :disabled="disabled" autocomplete="off"
             @focus="focused = true" @blur="blur" @keydown.enter.prevent="onEnter">
      <div v-if="focused && !disabled" class="absolute inset-x-0 top-full z-20 mt-1 max-h-[230px] overflow-y-auto rounded-xl border border-line bg-white shadow-lg">
        <div v-if="!matches.length" class="px-3 py-2 text-[10px] text-muted">{{ t('لا نتائج مطابقة', 'No matches') }}</div>
        <button v-for="o in matches" :key="o.v" type="button" class="flex w-full cursor-pointer items-center gap-2 border-0 border-b border-line-2 bg-transparent px-3 py-2 text-start hover:bg-soft" @mousedown.prevent="pick(o)">
          <span class="min-w-0 flex-1 truncate text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto">{{ o.l }}</span>
          <span v-if="o.sub" class="num ltr flex-none text-[9.5px] text-muted">{{ o.sub }}</span>
        </button>
        <div v-if="more" class="px-3 py-1.5 text-[9.5px] text-faint">{{ t('اكتب أكثر لتضييق النتائج…', 'Type more to narrow the list…') }}</div>
      </div>
    </div>
  </Field>
</template>
