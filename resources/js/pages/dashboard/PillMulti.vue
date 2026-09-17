<script setup>
// Multi-select pills (roles / warehouses).  <PillMulti :model-value="value" :options="[{ v, l }]" purple @update:model-value="set" />
// A non-array value counts as "nothing selected".
import { computed } from 'vue';
import { optL, optV } from '@/components';

const props = defineProps({
  modelValue: { type: Array, default: null },
  /** Options as in SelectInput: `[value, label]` or `{ v, l }`. */
  options: { type: Array, required: true },
  purple: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const sel = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));
const toggle = (v) => emit('update:modelValue', sel.value.includes(v) ? sel.value.filter((x) => x !== v) : [...sel.value, v]);
</script>

<template>
  <div class="row wrap !gap-1.5">
    <button v-for="o in options" :key="optV(o)" type="button" class="pill" :class="{ purple, active: sel.includes(optV(o)), '!h-[26px] !px-2.5 !text-[9px]': small }" @click="toggle(optV(o))">{{ optL(o) }}</button>
  </div>
</template>
