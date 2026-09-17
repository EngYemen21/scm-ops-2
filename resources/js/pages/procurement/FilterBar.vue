<script setup>
// Tab filter row: search box (v-model) + whatever the tab adds (status pills, buttons) in the default slot.
import { TextInput } from '@/components';

defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="modelValue" :placeholder="{ ar: 'بحث…', en: 'Search…' }" @update:model-value="emit('update:modelValue', $event)" />
    <slot />
  </div>
</template>
