<script setup>
// Pick a product of the catalogue by typing part of its name, its code or its barcode (GET /products?q= — the master
// list every signed-in user may read). The picked product is shown with a ✕ to change it.
//   <ProductPick v-model="product" :label="{ ar, en }" />          v-model is the product ({ id, sku, nameAr, nameEn }) or null
import { computed, onBeforeUnmount, ref } from 'vue';
import { useList } from '@/api/client';
import { Field } from '@/components';
import { bi, nm, t } from '@/i18n';

const props = defineProps({
  modelValue: { type: Object, default: null },
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  error: { type: String, default: null },
  hint: { type: [String, Object], default: null },
  full: { type: Boolean, default: false },
  placeholder: { type: [String, Object], default: () => ({ ar: 'ابحث بالاسم أو الرمز أو الباركود…', en: 'Search by name, code or barcode…' }) },
});
const emit = defineEmits(['update:modelValue']);

const text = ref('');
/** What the list is asked for: the text, 250 ms after the last key. */
const asked = ref('');
const focused = ref(false);
let timer = null;
let blurTimer = null;
function type(e) { text.value = e.target.value; clearTimeout(timer); timer = setTimeout(() => { asked.value = text.value.trim(); }, 250); }
onBeforeUnmount(() => { clearTimeout(timer); clearTimeout(blurTimer); });

// nothing is asked before the field is used, and nothing for less than two characters
const list = useList(() => (!props.modelValue && focused.value && asked.value.length >= 2 ? '/products' : null), () => ({ q: asked.value, pageSize: 8 }), { staleTime: 30_000 });
const items = computed(() => list.data.value?.items || []);
function pick(p) { emit('update:modelValue', { id: p.id, sku: p.sku, nameAr: p.nameAr, nameEn: p.nameEn, active: p.active }); text.value = ''; asked.value = ''; focused.value = false; }
function blur() { clearTimeout(blurTimer); blurTimer = setTimeout(() => { focused.value = false; }, 180); }
</script>

<template>
  <Field :label="label" :required="required" :error="error" :hint="hint" :full="full">
    <div v-if="modelValue" class="row h-[38px] rounded-[10px] border border-line bg-soft px-2.5">
      <span class="num ltr flex-none text-[10.5px] font-bold text-violet">{{ modelValue.sku }}</span>
      <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto">{{ nm(modelValue) }}</span>
      <button type="button" class="x-btn !h-6 !w-6 flex-none !text-[10px]" :aria-label="t('تغيير', 'Change')" :title="t('تغيير', 'Change')" @click="emit('update:modelValue', null)">✕</button>
    </div>
    <div v-else class="relative">
      <input :value="text" type="search" class="inp" :class="{ err: !!error }" :placeholder="bi(placeholder)" autocomplete="off" @input="type" @focus="focused = true" @blur="blur">
      <div v-if="focused && asked.length >= 2" class="absolute inset-x-0 top-full z-20 mt-1 max-h-[240px] overflow-y-auto rounded-xl border border-line bg-white shadow-lg">
        <div v-if="list.isLoading.value" class="px-3 py-2 text-[10px] text-muted">{{ t('جارٍ البحث…', 'Searching…') }}</div>
        <div v-else-if="list.error.value" class="px-3 py-2 text-[10px] text-bad">{{ t('تعذّر البحث — حاول مرة أخرى', 'The search failed — try again') }}</div>
        <div v-else-if="!items.length" class="px-3 py-2 text-[10px] text-muted">{{ t('لا صنف مطابق في الكتالوج', 'No matching product in the catalogue') }}</div>
        <button v-for="p in items" :key="p.id" type="button" class="flex w-full cursor-pointer items-center gap-2 border-0 border-b border-line-2 bg-transparent px-3 py-2 text-start hover:bg-soft" @mousedown.prevent="pick(p)">
          <span class="num ltr flex-none text-[10px] font-bold text-violet">{{ p.sku }}</span>
          <span class="min-w-0 flex-1 truncate text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto">{{ nm(p) }}</span>
          <span v-if="p.active === false" class="flex-none text-[9px] text-bad">{{ t('موقوف', 'inactive') }}</span>
        </button>
      </div>
    </div>
  </Field>
</template>
