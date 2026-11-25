<script setup>
// The partner / product filter of a list: type a few letters, pick one of the matches. The matches are read from
// the archive itself — GET /odoo-archive/docs/{kind}?q=&pageSize=6 for the kinds of the user's catalog that hold
// partners (res.partner) or products (product.product), up to three of them. What is picked reaches the query
// string as the record's Odoo id; once set, the filter shows the name and a click removes it.
//   <ArchivePicker entity="partner" :label="…" :model-value="id" :name="knownName" @update:model-value="…" />
import { computed, onBeforeUnmount, ref } from 'vue';
import { useList } from '@/api/client';
import { Field } from '@/components';
import { t } from '@/i18n';
import { API, arr, isId, labelOf, textOf, useArchive } from './archive';

const props = defineProps({
  /** 'partner' | 'product' */
  entity: { type: String, required: true },
  /** The Odoo id that is set, as text; '' when the filter is off. */
  modelValue: { type: String, default: '' },
  label: { type: String, default: null },
  /** Name of the record that is set, when the caller knows it (the rows on screen carry it). */
  name: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const { lookupKinds } = useArchive();
const sources = computed(() => lookupKinds(props.entity).slice(0, 3));

const typed = ref('');
/** What is searched: the words 300 ms after the last key. */
const words = ref('');
const open = ref(false);
/** The record picked here: its name is known even when no row on screen carries it. */
const picked = ref(null);
let timer = null;
function type(v) {
  typed.value = v; open.value = true;
  clearTimeout(timer);
  timer = setTimeout(() => { words.value = v.trim(); }, 300);
}
onBeforeUnmount(() => clearTimeout(timer));

const searching = computed(() => open.value && words.value.length >= 2);
const source = (i) => useList(() => (searching.value && sources.value[i] ? `${API}/docs/${encodeURIComponent(sources.value[i].key)}` : null), () => ({ q: words.value, pageSize: 6 }));
const found = [source(0), source(1), source(2)];
const matches = computed(() => {
  const seen = new Set();
  const out = [];
  found.forEach((f, i) => {
    if (!searching.value || !sources.value[i]) return;
    for (const it of arr(f.data.value?.items)) {
      if (!it || !isId(it.odooId) || seen.has(it.odooId)) continue;
      seen.add(it.odooId);
      const title = textOf(it.title) || textOf(it.number) || `#${it.odooId}`;
      out.push({ id: String(it.odooId), title, sub: [textOf(it.number) !== title ? textOf(it.number) : '', sources.value.length > 1 ? labelOf(sources.value[i]) : ''].filter(Boolean).join(' · ') });
    }
  });
  return out;
});
const busy = computed(() => found.some((f) => f.isFetching.value));
const failed = computed(() => found.some((f) => f.error.value));
const shownName = computed(() => props.name || (picked.value && picked.value.id === props.modelValue ? picked.value.title : '') || `#${props.modelValue}`);

function pick(m) {
  picked.value = m;
  open.value = false; typed.value = ''; words.value = '';
  emit('update:modelValue', m.id);
}
const clear = () => emit('update:modelValue', '');
const hint = computed(() => (props.entity === 'product' ? t('اكتب اسم الصنف أو رمزه…', 'Type a product name or code…') : t('اكتب اسم الشريك…', 'Type a partner name…')));
</script>

<template>
  <Field v-if="modelValue || sources.length" :label="label" class="relative w-[210px]" :class="{ 'z-[31]': open }">
    <button v-if="modelValue" type="button" class="pill active max-w-full" :title="t('إزالة هذه التصفية', 'Remove this filter')" @click="clear">
      <span class="min-w-0 truncate" dir="auto">{{ shownName }}</span><span aria-hidden="true">✕</span>
    </button>
    <template v-else>
      <input class="inp sm" type="search" autocomplete="off" :value="typed" :placeholder="hint" :aria-label="label || hint"
             @input="type($event.target.value)" @focus="open = true" @keydown.esc="open = false" @keydown.enter.prevent="matches[0] && pick(matches[0])">
      <template v-if="searching">
        <div class="fixed inset-0 z-[30]" @click="open = false" />
        <div class="popover start-0 top-full z-[31] mt-1 max-h-[280px] w-[min(340px,92vw)] !overflow-y-auto">
          <button v-for="m in matches" :key="m.id" type="button" class="menu-item" @click="pick(m)">
            <span class="min-w-0 flex-1">
              <span class="block truncate [text-align:match-parent]" dir="auto">{{ m.title }}</span>
              <span v-if="m.sub" class="block truncate text-[9px] font-normal text-faint [text-align:match-parent]" dir="auto">{{ m.sub }}</span>
            </span>
          </button>
          <div v-if="!matches.length" class="p-3 text-center text-[10.5px] text-faint">
            {{ busy ? t('جارٍ البحث…', 'Searching…') : failed ? t('تعذّر البحث الآن.', 'The search failed just now.') : t('لا نتائج مطابقة. جرّب جزءًا آخر من الاسم.', 'No match. Try another part of the name.') }}
          </div>
        </div>
      </template>
    </template>
  </Field>
</template>
