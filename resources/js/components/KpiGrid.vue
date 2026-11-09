<script setup>
// Row of KPI tiles that wraps into EVEN rows: ten tiles in a row that fits nine become 5 + 5, never "9 + 1 orphan".
// The grid is measured; the number of rows the tiles need at the minimum tile width is kept and the tiles are spread
// evenly over those rows. One row (or the phone layout, which is two columns by CSS) is left to the stylesheet.
//   <KpiGrid compact :min="140"> … <KpiCard /> … </KpiGrid>          min = minimum tile width in px (default 170 / 128 compact)
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { isMobile } from '../composables/viewport';

const props = defineProps({
  compact: { type: Boolean, default: false },
  /** Minimum tile width in px; overrides the stylesheet default. */
  min: { type: Number, default: null },
});

const el = ref(null);
/** Columns per row when the tiles need more than one row; null = let CSS auto-fit. */
const cols = ref(null);
let resizeObs = null;
let childObs = null;

function balance() {
  const g = el.value;
  if (!g || isMobile.value) { cols.value = null; return; }
  const n = g.children.length;
  const cs = getComputedStyle(g);
  const gap = parseFloat(cs.columnGap) || 0;
  const min = props.min || parseFloat(cs.getPropertyValue('--kpi-min')) || (props.compact ? 128 : 170);
  const perRow = Math.max(1, Math.floor((g.clientWidth + gap) / (min + gap)));
  if (n <= perRow) { cols.value = null; return; }
  cols.value = Math.ceil(n / Math.ceil(n / perRow));
}

onMounted(() => {
  balance();
  if (typeof ResizeObserver !== 'undefined') { resizeObs = new ResizeObserver(balance); resizeObs.observe(el.value); }
  if (typeof MutationObserver !== 'undefined') { childObs = new MutationObserver(balance); childObs.observe(el.value, { childList: true }); }
});
watch(isMobile, balance);
onBeforeUnmount(() => { resizeObs?.disconnect(); childObs?.disconnect(); });
</script>

<template>
  <div ref="el" class="kpi-grid" :class="{ compact }" :style="[min ? { '--kpi-min': `${min}px` } : null, cols ? { gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` } : null]"><slot /></div>
</template>
