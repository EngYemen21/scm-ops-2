<script setup>
// Small stat tile: big LTR value over a label.  <Tile :v="fmtNum(n)" :l="{ ar, en }" tone="amber" />
// tone: amber | green | red | purple | teal (default neutral). Slot content replaces the value.
import { bi } from '@/i18n';

defineProps({
  v: { type: [String, Number], default: null },
  l: { type: [String, Object], required: true },
  tone: { type: String, default: null },
});
</script>

<template>
  <div class="tile" :class="tone">
    <div class="tile-v"><slot>{{ v ?? '—' }}</slot></div>
    <div class="tile-l">{{ bi(l) }}</div>
  </div>
</template>
