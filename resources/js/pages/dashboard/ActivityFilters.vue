<script setup>
// Filter bar of the activity / audit tables: text + date inputs over one draft object, applied with Search / Enter.
//   <ActivityFilters :values="draft" :fields="[{ k: 'q', label: { ar, en }, ph, width }, { k: 'from', label, type: 'date' }]" @change="(k, v) => (draft[k] = v)" @apply="…" @clear="…" />
// `values` is the page's draft; it only reaches the URL (and the query) on apply.
import { Btn, DateInput, TextInput } from '@/components';

defineProps({
  values: { type: Object, required: true },
  /** [{ k, label: { ar, en }, type?: 'date', ph?: string | { ar, en }, width?: number }] */
  fields: { type: Array, required: true },
});
const emit = defineEmits(['change', 'apply', 'clear']);
</script>

<template>
  <div class="row wrap px-[18px] pb-3">
    <template v-for="f in fields" :key="f.k">
      <DateInput v-if="f.type === 'date'" small :label="f.label" :model-value="values[f.k]" :style="{ width: `${f.width || 140}px` }" @update:model-value="(v) => emit('change', f.k, v)" />
      <TextInput v-else small :label="f.label" :model-value="values[f.k]" :placeholder="f.ph" :style="{ width: `${f.width || 150}px` }" @update:model-value="(v) => emit('change', f.k, v)" @enter="emit('apply')" />
    </template>
    <div class="row self-end !gap-1.5">
      <Btn tone="dark" size="sm" :label="{ ar: 'بحث', en: 'Search' }" @click="emit('apply')" />
      <Btn tone="ghost" size="sm" :label="{ ar: 'إزالة الفلتر', en: 'Clear' }" @click="emit('clear')" />
    </div>
  </div>
</template>
