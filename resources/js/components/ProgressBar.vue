<script setup>
// Capacity / progress bar. `auto` colours it green <70, amber <90, red ≥90.
import { computed } from 'vue';
import { bi, fmtNum } from '../i18n';

const props = defineProps({
  /** 0–100 (clamped). */
  pct: { type: Number, default: 0 },
  color: { type: String, default: null },
  height: { type: Number, default: 8 },
  label: { type: [String, Object], default: null },
  showPct: { type: Boolean, default: false },
  auto: { type: Boolean, default: false },
});
const p = computed(() => Math.max(0, Math.min(100, Number.isFinite(props.pct) ? props.pct : 0)));
const fill = computed(() => props.color || (props.auto ? (p.value >= 90 ? '#b23b3b' : p.value >= 70 ? '#b26a16' : '#1d7a3e') : null));
</script>

<template>
  <div>
    <div v-if="label || showPct" class="mb-1 flex justify-between text-[9.5px] font-extrabold text-muted">
      <span>{{ label ? bi(label) : '' }}</span><span v-if="showPct" class="num" :style="{ color: fill || '#20242E' }">{{ fmtNum(p) }}%</span>
    </div>
    <div class="progress" :style="{ height: height + 'px' }"><div :style="{ width: p + '%', background: fill }" /></div>
  </div>
</template>
