<script setup>
// One filter a kind declares, drawn by its type: choice (pills up to five options, a list beyond) · daterange
// (from / to) · partner and product (a picker that searches the archive) · text (applied after a pause in typing;
// the API matches it whole — the search box is the one that finds a part of a word).
// Its value lives in the query string under the filter's own key — `from` / `to` for the date range — which is
// also the name of the parameter of GET docs/{kind}.
//   <ArchiveFilter v-for="f in filters" :key="f.key" :filter="f" :name="names[f.key]" />
import { computed } from 'vue';
import { DateInput, Field, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useSearchParam } from '../integration/odoo';
import ArchivePicker from './ArchivePicker.vue';
import { isDay, isObj, labelOf, useArchive } from './archive';

const props = defineProps({
  /** { key, label, type: choice | daterange | partner | product | text, options?: [{ value, label, tone }] } */
  filter: { type: Object, required: true },
  /** Name of the partner / product that is set, when the rows on screen tell it. */
  name: { type: String, default: '' },
});

const { nav, list } = useArchive();
const caption = computed(() => labelOf(props.filter) || props.filter.key);
const value = computed(() => list.values.value[props.filter.key] || '');
const from = computed(() => list.values.value.from || '');
const to = computed(() => list.values.value.to || '');
const set = (v) => nav.setFilter(props.filter.key, v);
/** A day that is not complete yet is not a filter. */
const setDay = (key, v) => nav.setFilter(key, isDay(v) ? v : null);

/** A choice with, where the catalog counts them, how many records are in it: «أمر بيع (1,234)». */
const options = computed(() => (props.filter.options || []).filter(isObj).map((o) => {
  const name = labelOf(o) || String(o.value);
  return { v: String(o.value), l: Number.isFinite(Number(o.count)) && o.count != null ? `${name} (${fmtNum(o.count)})` : name };
}));
const pills = computed(() => options.value.length <= 5);
const tabs = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...options.value.map((o) => ({ k: o.v, label: o.l }))]);

// a text filter is a search box of its own: what is typed reaches the query string after a pause, at once on Enter
const box = props.filter.type === 'text' ? useSearchParam(nav, props.filter.key) : null;
const typed = computed(() => (box ? box.text.value : ''));
</script>

<template>
  <Field v-if="filter.type === 'choice' && pills" :label="caption">
    <Tabs :model-value="value" :tabs="tabs" variant="pill" class="!mb-0" @update:model-value="set" />
  </Field>
  <SelectInput v-else-if="filter.type === 'choice'" small :label="caption" field-class="w-[190px]" :model-value="value" :options="options" :placeholder="{ ar: 'الكل', en: 'All' }" @update:model-value="set" />
  <template v-else-if="filter.type === 'daterange'">
    <DateInput small :label="`${caption} — ${t('من', 'from')}`" :model-value="from" :max="to || null" @update:model-value="(v) => setDay('from', v)" />
    <DateInput small :label="`${caption} — ${t('إلى', 'to')}`" :model-value="to" :min="from || null" @update:model-value="(v) => setDay('to', v)" />
  </template>
  <ArchivePicker v-else-if="filter.type === 'partner' || filter.type === 'product'" :entity="filter.type" :label="caption" :model-value="value" :name="name" @update:model-value="set" />
  <TextInput v-else small type="search" :label="caption" field-class="w-[180px]" :model-value="typed" :placeholder="{ ar: 'القيمة كاملة…', en: 'The whole value…' }" @update:model-value="box.type" @enter="box.apply" />
</template>
