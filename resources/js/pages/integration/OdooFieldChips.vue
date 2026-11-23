<script setup>
// Names of the fields one change touched: the first few as chips, the rest as "+n" with their names in the tooltip.
//   <OdooFieldChips :fields="row.fields" />            <OdooFieldChips :fields="h.fields" :max="0" />   (all of them)
// `fields` is null for a created or a deleted record: the whole record is new / gone, so there is nothing to list.
import { computed } from 'vue';
import { Chip } from '@/components';
import { fmtNum } from '@/i18n';

const props = defineProps({
  /** string[] | null */
  fields: { type: Array, default: null },
  /** How many names are shown before "+n"; 0 shows all of them. */
  max: { type: Number, default: 3 },
});

const all = computed(() => props.fields || []);
const shown = computed(() => (props.max > 0 ? all.value.slice(0, props.max) : all.value));
const rest = computed(() => all.value.slice(shown.value.length));
</script>

<template>
  <span v-if="all.length" class="inline-flex max-w-full flex-wrap items-center gap-1 align-middle">
    <Chip v-for="f in shown" :key="f" small :label="f" class="ltr font-num" />
    <span v-if="rest.length" class="num ltr text-[9px] text-muted" :title="rest.join(' · ')">+{{ fmtNum(rest.length) }}</span>
  </span>
  <span v-else class="text-faint">—</span>
</template>
