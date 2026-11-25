<script setup>
// A searchable multi-picker of products, product categories or customers for a run's scope. v-model = an array of ids.
//   products   GET /products?q=&pageSize=12&active=true      (the list ProductPicker uses)
//   customers  GET /customers?q=&pageSize=12
//   categories GET /categories?flat=true (all of them, filtered here)
// Ids already chosen that the page has never seen (editing a run) are named through GET /products/{id} · /customers/{id}.
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { api, useGet, useList } from '@/api/client';
import { Field, TextInput } from '@/components';
import { t } from '@/i18n';
import { nameOf } from './planning';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  kind: { type: String, default: 'product' },
  label: { type: [String, Object], default: null },
  error: { type: String, default: null },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const typed = ref('');
const term = ref('');
let timer = null;
function onType(v) { typed.value = v; clearTimeout(timer); timer = setTimeout(() => { term.value = String(v || '').trim(); }, 300); }
onBeforeUnmount(() => clearTimeout(timer));

const searching = computed(() => term.value.length >= 1);
const listPath = computed(() => (props.kind === 'product' ? '/products' : props.kind === 'customer' ? '/customers' : null));
const list = useList(listPath, () => ({ q: term.value, pageSize: 12, ...(props.kind === 'product' ? { active: 'true' } : {}) }), { enabled: searching });
const cats = useGet(() => (props.kind === 'category' ? '/categories' : null), { flat: 'true' }, { staleTime: 60_000 });
const hits = computed(() => {
  if (!searching.value) return [];
  if (props.kind === 'category') {
    const s = term.value.toLowerCase();
    const all = Array.isArray(cats.data.value) ? cats.data.value : cats.data.value?.items || [];
    return all.filter((c) => [c.code, c.nameAr, c.nameEn].some((x) => String(x || '').toLowerCase().includes(s))).slice(0, 15);
  }
  return list.data.value?.items || [];
});
const fetching = computed(() => (props.kind === 'category' ? cats.isFetching.value : list.isFetching.value));

/** id → { code, name } of what was seen (picked, searched, fetched). */
const known = reactive({});
const codeOf = (r) => r.sku || r.code || '';
const remember = (r) => { known[String(r.id)] = { code: codeOf(r), name: nameOf(r) }; };
watch(hits, (h) => h.forEach(remember));
watch(() => cats.data.value, (d) => { const all = Array.isArray(d) ? d : d?.items || []; all.forEach(remember); }, { immediate: true });
// name the ids that came with the run
const asked = new Set();
watch(() => props.modelValue, (ids) => {
  if (props.kind === 'category') return;
  const base = props.kind === 'product' ? '/products/' : '/customers/';
  (ids || []).filter((id) => !known[String(id)] && !asked.has(String(id))).slice(0, 40).forEach((id) => {
    asked.add(String(id));
    api.get(base + encodeURIComponent(id)).then((r) => { if (r && r.id != null) known[String(id)] = { code: codeOf(r), name: nameOf(r) }; }).catch(() => { /* shown by id */ });
  });
}, { immediate: true });

const chosen = computed(() => (props.modelValue || []).map(String));
function toggle(r) {
  remember(r);
  const id = String(r.id);
  emit('update:modelValue', chosen.value.includes(id) ? chosen.value.filter((x) => x !== id) : [...chosen.value, id]);
}
const drop = (id) => emit('update:modelValue', chosen.value.filter((x) => x !== id));
const placeholder = computed(() => ({
  product: { ar: 'ابحث بالاسم أو SKU…', en: 'Search name or SKU…' }, category: { ar: 'ابحث باسم الفئة…', en: 'Search a category…' }, customer: { ar: 'ابحث باسم العميل أو رمزه…', en: 'Search customer name or code…' },
}[props.kind]));
</script>

<template>
  <Field full :label="label" :error="error">
    <div v-if="chosen.length" class="row wrap mb-1.5 !gap-1">
      <span v-for="id in chosen" :key="id" class="pill active !cursor-default">
        <span class="num ltr inline-block">{{ known[id]?.code || '' }}</span>
        <span dir="auto">{{ known[id]?.name || (known[id] ? '' : `#${id}`) }}</span>
        <button v-if="!disabled" type="button" class="ms-1 font-extrabold" :aria-label="t('إزالة', 'Remove')" @click="drop(id)">×</button>
      </span>
    </div>
    <div v-if="!disabled" class="relative">
      <TextInput small type="search" :model-value="typed" :placeholder="placeholder" @update:model-value="onType" />
      <div v-if="typed.trim()" class="absolute inset-x-0 top-full z-[5] max-h-[240px] overflow-y-auto rounded-xl border border-line bg-white shadow-[0_12px_30px_rgba(30,33,48,.12)]">
        <div v-if="fetching && hits.length === 0" class="empty !p-3">{{ t('جارٍ البحث…', 'Searching…') }}</div>
        <div v-else-if="hits.length === 0" class="empty !p-3">{{ t('لا نتائج مطابقة.', 'No matching results.') }}</div>
        <template v-else>
          <div v-for="r in hits" :key="r.id" class="gs-row" @mousedown.prevent="toggle(r)">
            <span class="w-[16px] text-ok font-extrabold">{{ chosen.includes(String(r.id)) ? '✓' : '' }}</span>
            <span class="num min-w-[80px] text-[10px] text-violet">{{ codeOf(r) }}</span>
            <span class="ellipsis flex-1 text-[11px] font-extrabold" dir="auto">{{ nameOf(r) }}</span>
          </div>
        </template>
      </div>
    </div>
  </Field>
</template>
