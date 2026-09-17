<script setup>
// Stacked status bar with legend (e.g. available vs reserved vs quarantined).
//   <StackedBar :segments="[{ value: 120, color: '#1d7a3e', label: { ar, en } }]" />
import { computed } from 'vue';
import { bi, fmtNum } from '../i18n';

const props = defineProps({
  segments: { type: Array, required: true },
  height: { type: Number, default: 10 },
  legend: { type: Boolean, default: true },
});
const total = computed(() => props.segments.reduce((s, x) => s + Math.max(0, x.value), 0) || 1);
</script>

<template>
  <div>
    <div class="statbar" :style="{ height: height + 'px' }"><div v-for="(s, i) in segments" :key="i" :style="{ width: (Math.max(0, s.value) / total) * 100 + '%', background: s.color }" /></div>
    <div v-if="legend" class="statbar-legend">
      <span v-for="(s, i) in segments" :key="i" class="inline-flex items-center gap-[5px]"><span class="h-2 w-2 rounded-full" :style="{ background: s.color }" />{{ s.label ? bi(s.label) : '' }} <span class="num">{{ fmtNum(s.value) }}</span></span>
    </div>
  </div>
</template>
