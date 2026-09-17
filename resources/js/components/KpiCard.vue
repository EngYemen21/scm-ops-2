<script setup>
// KPI card. <KpiGrid><KpiCard :value="n" :label="{ ar, en }" :unit="{ ar, en }" color="#b23b3b" @click="…" clickable /></KpiGrid>
import { computed } from 'vue';
import { bi, fmtNum, isBi } from '../i18n';

const props = defineProps({
  value: { type: [Number, String], default: null },
  label: { type: [String, Object], required: true },
  unit: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  color: { type: String, default: '#20242E' },
  clickable: { type: Boolean, default: false },
  active: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  /** Fraction digits when `value` is a number. */
  digits: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
});

const shown = computed(() => (typeof props.value === 'number' ? fmtNum(props.value, props.digits) : props.value == null ? '—' : props.value));
</script>

<template>
  <div class="kpi" :class="{ click: clickable, compact }" :style="active ? { borderColor: '#7FD6E5' } : null" :role="clickable ? 'button' : null">
    <div v-if="loading" class="skel w-[55%]" :class="compact ? 'h-5' : 'h-6'" />
    <div v-else class="kpi-v" :style="{ color }"><slot name="value">{{ shown }}</slot><span v-if="unit" class="kpi-u">{{ bi(unit) }}</span></div>
    <div class="kpi-l">{{ bi(label) }}</div>
    <div v-if="sub || $slots.sub" class="mt-[3px] text-[9px] text-faint"><slot name="sub">{{ isBi(sub) ? bi(sub) : sub }}</slot></div>
  </div>
</template>
