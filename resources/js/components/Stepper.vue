<script setup>
// Horizontal progress stepper (order / trip). <Stepper :steps="[{ label: { ar, en } }]" :current="2" :failed="false" />
import { bi } from '../i18n';

const props = defineProps({
  steps: { type: Array, required: true },
  current: { type: Number, required: true },
  failed: { type: Boolean, default: false },
  maxWidth: { type: Number, default: 820 },
});
const isFailed = (i) => props.failed && i === props.current;
const isDone = (i) => i <= props.current && !isFailed(i);
</script>

<template>
  <div class="stepper" :style="{ maxWidth: maxWidth + 'px' }">
    <div v-for="(s, i) in steps" :key="i" class="step" :class="{ failed: isFailed(i), done: isDone(i) }">
      <div class="step-dot">{{ isFailed(i) ? '✕' : isDone(i) ? '✓' : i + 1 }}</div>
      <div class="step-l">{{ bi(s.label) }}</div>
    </div>
  </div>
</template>
