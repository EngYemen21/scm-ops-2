<script setup>
// Pick a customer, a supplier or a product by typing part of its name or code: the first matches of the master list
// appear under the field while one types; the picked record is shown with a ✕ to change it. The value is the CODE
// people know (customer / supplier code, SKU) — so it can live in the query string and a link reopens the same record.
//   <PickRecord kind="customer" :model-value="code" :label="{ ar, en }" @update:model-value="…" />
// `kind`: customer → GET /customers · supplier → GET /suppliers · product → GET /products (scan-ready).
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useGet, useList } from '@/api/client';
import { Field, TextInput } from '@/components';
import { nm, t } from '@/i18n';

const props = defineProps({
  kind: { type: String, required: true },
  modelValue: { type: String, default: '' },
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  error: { type: String, default: null },
  hint: { type: [String, Object], default: null },
  full: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  /** Offer stopped records as well (a filter may look for them; a form may not). */
  anyState: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'pick']);

const PATH = { customer: '/customers', supplier: '/suppliers', product: '/products' };
const PLACEHOLDER = {
  customer: { ar: 'اسم العميل أو رمزه…', en: 'Customer name or code…' },
  supplier: { ar: 'اسم المورد أو رمزه…', en: 'Supplier name or code…' },
  product: { ar: 'SKU / اسم المنتج / باركود…', en: 'SKU / product name / barcode…' },
};
const codeOf = (item) => (props.kind === 'product' ? item.sku : item.code) || '';

const text = ref('');
/** What the list is asked for: the text, 250 ms after the last key. */
const asked = ref('');
const focused = ref(false);
/** The record picked in this session: its name is known without asking the server. */
const picked = ref(null);
let timer = null;
function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(() => { asked.value = String(v ?? '').trim(); }, 250); }
onBeforeUnmount(() => clearTimeout(timer));

const list = useList(() => (!props.modelValue && focused.value && !props.disabled ? PATH[props.kind] : null),
  () => ({ q: asked.value, pageSize: 8, ...(props.anyState ? {} : { active: 'true' }) }), { staleTime: 30_000 });
const items = computed(() => list.data.value?.items || []);
// a code that came from a link: its record is read once, to show its name
const known = computed(() => (picked.value && codeOf(picked.value).toLowerCase() === String(props.modelValue).toLowerCase() ? picked.value : null));
const one = useGet(() => (props.modelValue && !known.value ? `${PATH[props.kind]}/${encodeURIComponent(props.modelValue)}` : null), undefined, { staleTime: 60_000 });
const record = computed(() => known.value || (props.modelValue ? one.data.value : null) || null);
const unknown = computed(() => !!props.modelValue && !known.value && !!one.error.value);
watch(record, (r) => emit('pick', r));

function pick(item) { picked.value = item; emit('update:modelValue', codeOf(item)); text.value = ''; asked.value = ''; focused.value = false; }
function clear() { picked.value = null; emit('update:modelValue', ''); }
/** The list stays long enough for a click on one of its rows to land. */
function blur() { setTimeout(() => { focused.value = false; }, 180); }
/** A scanned or typed code followed by Enter: taken when exactly one record matches. */
function enter() { if (items.value.length === 1) pick(items.value[0]); }
</script>

<template>
  <Field :label="label" :required="required" :error="error" :hint="hint" :full="full">
    <div v-if="modelValue" class="row rounded-[10px] border bg-soft px-2.5" :class="[small ? 'h-[32px]' : 'h-[38px]', unknown ? 'border-bad' : 'border-line']">
      <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto">
        {{ record ? nm(record) : unknown ? t('غير موجود', 'Not found') : '…' }}
      </span>
      <span class="num ltr flex-none text-[9.5px] text-muted">{{ modelValue }}</span>
      <button v-if="!disabled" type="button" class="x-btn !h-6 !w-6 flex-none !text-[10px]" :aria-label="t('تغيير', 'Change')" :title="t('تغيير', 'Change')" @click="clear">✕</button>
    </div>
    <div v-else class="relative">
      <TextInput :model-value="text" :small="small" :disabled="disabled" :scan="kind === 'product'" type="search" :class="{ err: !!error }" :placeholder="PLACEHOLDER[kind]" autocomplete="off"
                 @update:model-value="type" @enter="enter" @focus="focused = true" @blur="blur" />
      <div v-if="focused" class="absolute inset-x-0 top-full z-20 mt-1 max-h-[240px] min-w-[240px] overflow-y-auto rounded-xl border border-line bg-white shadow-lg">
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
