<script setup>
// Label + control + error / hint wrapper used by every input component.
import { bi } from '../i18n';

defineProps({
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  error: { type: String, default: null },
  hint: { type: [String, Object], default: null },
  full: { type: Boolean, default: false },
  for: { type: String, default: null },
});
</script>

<template>
  <div class="field" :class="{ full }">
    <label v-if="label" class="field-l" :for="$props.for">{{ bi(label) }}<span v-if="required" class="text-bad"> *</span></label>
    <slot />
    <div v-if="error" class="field-err">{{ error }}</div>
    <div v-else-if="hint" class="field-hint">{{ bi(hint) }}</div>
  </div>
</template>
