<script setup>
// Tabs / filter pills.  <Tabs v-model="tab" :tabs="[{ k: 'qt', label: { ar, en }, badge: 3 }]" variant="md" />
// variant: 'md' page tabs · 'sm' sub-tabs · 'pill' filter pills · 'pillPurple' warehouse / group pills
import { computed } from 'vue';
import { bi } from '../i18n';

const props = defineProps({
  tabs: { type: Array, required: true },
  modelValue: { type: String, default: null },
  variant: { type: String, default: 'md' },
});
const emit = defineEmits(['update:modelValue']);

const base = computed(() => (props.variant === 'pill' ? 'pill' : props.variant === 'pillPurple' ? 'pill purple' : props.variant === 'sm' ? 'tab sm' : 'tab'));
</script>

<template>
  <div :class="variant.startsWith('pill') ? 'pill-bar' : 'tab-bar'" role="tablist">
    <button v-for="tb in tabs.filter((x) => !x.hidden)" :key="tb.k" type="button" role="tab" :aria-selected="tb.k === modelValue" :class="[base, { active: tb.k === modelValue }]" :disabled="tb.disabled" @click="emit('update:modelValue', tb.k)">
      {{ bi(tb.label) }}
      <span v-if="tb.badge != null && tb.badge !== 0" class="badge" :class="{ soft: tb.k !== modelValue }">{{ tb.badge }}</span>
    </button>
  </div>
</template>
