<script setup>
// Value tile (stock drawer tiles: neutral / amber reserved / green available).
//   <Tile :value="row.onHand" :label="{ ar, en }" tone="amber" />      slot `value` for custom markup
import { computed } from 'vue';
import { bi, fmtNum } from '@/i18n';

const props = defineProps({
  value: { type: [Number, String], default: null },
  label: { type: [String, Object], required: true },
  /** amber | green | red | purple | teal */
  tone: { type: String, default: null },
  color: { type: String, default: null },
});
const TONE_COLORS = { amber: '#b26a16', green: '#1d7a3e', red: '#b23b3b', purple: '#654e92', teal: '#0d7f93' };
const c = computed(() => props.color || TONE_COLORS[props.tone] || '#20242E');
</script>

<template>
  <div class="tile text-center" :class="tone">
    <div class="tile-v !text-center" :style="{ color: c }"><slot name="value">{{ typeof value === 'number' ? fmtNum(value) : value }}</slot></div>
    <div class="tile-l" :style="tone ? { color: c } : null">{{ bi(label) }}</div>
  </div>
</template>
