<script setup>
// Purchases vs target per month, drawn as inline SVG (no chart library): a bar per month for the net purchases and a
// dashed line with dots for the target. A month's bar emits `month` ('YYYY-MM') so the dashboard opens its lines.
//   <TrendChart :trend="dashboard.trend" @month="(m) => …" />      trend: [{ month, purchases, invoiced, returns, target }]
import { computed } from 'vue';
import { fmtMoney, num, t } from '@/i18n';
import { monthLabel } from './insights';

const props = defineProps({
  trend: { type: Array, default: () => [] },
});
const emit = defineEmits(['month']);

const H = 190;
const TOP = 14;
const BOTTOM = 26;
const LEFT = 52;
const STEP = 46;
const width = computed(() => LEFT + Math.max(1, props.trend.length) * STEP + 10);
const values = computed(() => props.trend.flatMap((p) => [num(p.purchases), num(p.target)]));
const maxV = computed(() => Math.max(1, ...values.value));
const minV = computed(() => Math.min(0, ...values.value));
const y = (v) => TOP + (maxV.value - v) / (maxV.value - minV.value) * (H - TOP - BOTTOM);
const zero = computed(() => y(0));
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => minV.value + (maxV.value - minV.value) * f));
const short = (v) => {
  const a = Math.abs(v);
  if (a >= 1e6) return `${(v / 1e6).toFixed(1)}M`;
  if (a >= 1e3) return `${(v / 1e3).toFixed(a >= 1e4 ? 0 : 1)}K`;
  return String(Math.round(v));
};
const bars = computed(() => props.trend.map((p, i) => {
  const v = num(p.purchases);
  const x = LEFT + i * STEP + 9;
  const top = Math.min(y(v), zero.value);
  const h = Math.max(1, Math.abs(y(v) - zero.value));
  const tv = num(p.target);
  return { month: p.month, x, top, h, w: STEP - 18, v, target: tv, ty: y(tv), cx: x + (STEP - 18) / 2, met: tv > 0 && v >= tv };
}));
const targetLine = computed(() => bars.value.filter((b) => b.target > 0).map((b) => `${b.cx},${b.ty}`).join(' '));
const hasTarget = computed(() => bars.value.some((b) => b.target > 0));
const tip = (b) => `${monthLabel(b.month)}\n${t('المشتريات', 'Purchases')}: ${fmtMoney(b.v)}\n${t('الهدف', 'Target')}: ${b.target > 0 ? fmtMoney(b.target) : '—'}`;
</script>

<template>
  <div v-if="!trend.length" class="empty">{{ t('لا أشهر في الفترة.', 'No month in the period.') }}</div>
  <div v-else dir="ltr">
    <div class="overflow-x-auto">
      <svg :viewBox="`0 0 ${width} ${H}`" :style="{ minWidth: `${Math.min(width, 640)}px` }" class="block h-auto w-full" role="img" :aria-label="t('المشتريات مقابل الهدف', 'Purchases vs target')">
        <g v-for="(v, i) in ticks" :key="i">
          <line :x1="LEFT - 4" :x2="width - 6" :y1="y(v)" :y2="y(v)" stroke="#EEEBF4" stroke-width="1" />
          <text :x="LEFT - 8" :y="y(v) + 3" text-anchor="end" font-size="9" fill="#a8a4b8" font-family="Quicksand, sans-serif">{{ short(v) }}</text>
        </g>
        <line :x1="LEFT - 4" :x2="width - 6" :y1="zero" :y2="zero" stroke="#c9c4d6" stroke-width="1" />
        <g v-for="b in bars" :key="b.month" class="cursor-pointer" @click="emit('month', b.month)">
          <title>{{ tip(b) }}</title>
          <rect :x="b.x - 6" :y="TOP" :width="b.w + 12" :height="H - TOP - BOTTOM" fill="transparent" />
          <rect :x="b.x" :y="b.top" :width="b.w" :height="b.h" rx="3" :fill="b.v < 0 ? '#b23b3b' : b.met ? '#1d7a3e' : '#654e92'" opacity="0.85" />
          <text :x="b.cx" :y="H - 9" text-anchor="middle" font-size="9" fill="#7d7990" font-family="Quicksand, sans-serif">{{ monthLabel(b.month, true) }}</text>
        </g>
        <polyline v-if="targetLine" :points="targetLine" fill="none" stroke="#b26a16" stroke-width="1.6" stroke-dasharray="4 3" pointer-events="none" />
        <circle v-for="b in bars.filter((x) => x.target > 0)" :key="`t-${b.month}`" :cx="b.cx" :cy="b.ty" r="2.8" fill="#b26a16" pointer-events="none" />
      </svg>
    </div>
    <div class="row wrap mt-1 justify-center !gap-4 text-[9.5px] text-sec" :dir="t('rtl', 'ltr')">
      <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:#654e92" />{{ t('صافي المشتريات', 'Net purchases') }}</span>
      <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:#1d7a3e" />{{ t('بلغ الهدف', 'Target met') }}</span>
      <span v-if="hasTarget" class="inline-flex items-center gap-1"><span class="inline-block h-0.5 w-4" style="background:#b26a16" />{{ t('الهدف', 'Target') }}</span>
      <span v-else class="text-faint">{{ t('لا أهداف شهرية مسجلة للفروع', 'No monthly target set for the franchisees') }}</span>
    </div>
  </div>
</template>
