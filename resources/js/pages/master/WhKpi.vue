<script setup>
// Small soft KPI box of the warehouse profile.  <WhKpi :v="fmtNum(12)" :l="{ ar, en }" color="#1d7a3e" />
import { bi } from '@/i18n';

defineProps({
  v: { type: [String, Number], default: null },
  l: { type: [String, Object], required: true },
  color: { type: String, default: '#654e92' },
});
</script>

<template>
  <div class="rounded-xl border border-line-2 bg-soft px-3 py-2.5">
    <div class="num text-[16px] font-bold" :style="{ color }">{{ v ?? '—' }}</div>
    <div class="mt-0.5 text-[8.5px] font-extrabold text-faint">{{ bi(l) }}</div>
  </div>
</template>
