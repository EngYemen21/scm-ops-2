<script setup>
// Button. <Btn tone="primary" size="sm" :loading="act.pending.value" :label="{ ar, en }" @click="…" />  (or slot content)
// tones: dark primary success purple blue danger dangerOutline outline soft softPurple softGreen softBlue softRed softAmber ghost
import { bi } from '../i18n';

defineProps({
  tone: { type: String, default: 'dark' },
  size: { type: String, default: 'md' },
  block: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  label: { type: [String, Object], default: null },
  type: { type: String, default: 'button' },
});
</script>

<template>
  <button :type="type" class="btn" :class="[tone, size === 'sm' ? 'sm' : size === 'lg' ? 'lg' : '', { block }]" :disabled="disabled || loading">
    <span v-if="loading" class="spinner" />
    <slot v-else name="icon" />
    <slot>{{ bi(label) }}</slot>
  </button>
</template>
