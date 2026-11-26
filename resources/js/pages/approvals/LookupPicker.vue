<script setup>
// Pick one record by typing part of its name or code: the twenty best matches of a lookup endpoint appear under the
// field while one types; the picked record is shown with a ✕ to change it.
//   <LookupPicker v-model="partner" path="/approvals/partners" :params="{ type: 'supplier' }" :label="{ ar, en }" />
// v-model is the picked item ({ id, code | sku, nameAr, nameEn }) or null.
import { computed, onBeforeUnmount, ref } from 'vue';
import { useList } from '@/api/client';
import { Field, TextInput } from '@/components';
import { nm, t } from '@/i18n';

const props = defineProps({
  modelValue: { type: Object, default: null },
  /** GET `path`?q=… answers { items: [...] }. */
  path: { type: String, required: true },
  params: { type: Object, default: () => ({}) },
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  error: { type: String, default: null },
  hint: { type: [String, Object], default: null },
  full: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
  placeholder: { type: [String, Object], default: () => ({ ar: 'اكتب للبحث…', en: 'Type to search…' }) },
});
const emit = defineEmits(['update:modelValue']);

const text = ref('');
/** What the list is asked for: the text, 250 ms after the last key. */
const asked = ref('');
const focused = ref(false);
let timer = null;
function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(() => { asked.value = String(v ?? '').trim(); }, 250); }
onBeforeUnmount(() => clearTimeout(timer));

// nothing is asked before the field is used; then the first twenty are offered even with nothing typed
const list = useList(() => (!props.modelValue && focused.value ? props.path : null), () => ({ ...props.params, q: asked.value }), { staleTime: 30_000 });
const items = computed(() => list.data.value?.items || []);
const codeOf = (item) => item.code || item.sku || '';
function pick(item) { emit('update:modelValue', item); text.value = ''; asked.value = ''; focused.value = false; }
/** The list stays long enough for a click on one of its rows to land. */
function blur() { setTimeout(() => { focused.value = false; }, 180); }
</script>

<template>
  <Field :label="label" :required="required" :error="error" :hint="hint" :full="full">
    <div v-if="modelValue" class="row rounded-[10px] border border-line bg-soft px-2.5" :class="small ? 'h-[30px]' : 'h-[38px]'">
      <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto">{{ nm(modelValue) }}</span>
      <span v-if="codeOf(modelValue)" class="num ltr flex-none text-[9.5px] text-muted">{{ codeOf(modelValue) }}</span>
      <button type="button" class="x-btn !h-6 !w-6 flex-none !text-[10px]" :aria-label="t('تغيير', 'Change')" :title="t('تغيير', 'Change')" @click="emit('update:modelValue', null)">✕</button>
    </div>
    <div v-else class="relative">
      <TextInput :model-value="text" :small="small" type="search" :class="{ err: !!error }" :placeholder="placeholder" autocomplete="off" @update:model-value="type" @focus="focused = true" @blur="blur" />
      <div v-if="focused" class="absolute inset-x-0 top-full z-20 mt-1 max-h-[230px] overflow-y-auto rounded-xl border border-line bg-white shadow-lg">
        <div v-if="list.isLoading.value" class="px-3 py-2 text-[10px] text-muted">{{ t('جارٍ البحث…', 'Searching…') }}</div>
        <div v-else-if="list.error.value" class="px-3 py-2 text-[10px] text-bad">{{ t('تعذّر البحث — حاول مرة أخرى', 'The search failed — try again') }}</div>
        <div v-else-if="!items.length" class="px-3 py-2 text-[10px] text-muted">{{ t('لا نتائج مطابقة', 'No matches') }}</div>
        <button v-for="item in items" :key="item.id" type="button" class="flex w-full cursor-pointer items-center gap-2 border-0 border-b border-line-2 bg-transparent px-3 py-2 text-start hover:bg-soft" @mousedown.prevent="pick(item)">
          <span class="min-w-0 flex-1 truncate text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto">{{ nm(item) }}</span>
          <span class="num ltr flex-none text-[9.5px] text-muted">{{ codeOf(item) }}</span>
        </button>
      </div>
    </div>
  </Field>
</template>
