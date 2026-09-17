<script setup>
// Honest map placeholder — a grid background and an "Integration Pending" note. No fake positions, no fake routes:
// the telematics / GPS / maps provider is not connected.
import { bi, t } from '@/i18n';

defineProps({
  height: { type: Number, default: 190 },
  label: { type: [String, Object], default: null },
});

// Two crossed 1px gradients = the 36px map grid (kept as a style: the value has top-level commas).
const GRID = {
  backgroundImage: 'linear-gradient(rgba(30,33,48,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(30,33,48,.06) 1px,transparent 1px)',
  backgroundSize: '36px 36px',
};
</script>

<template>
  <div dir="ltr" class="relative flex items-center justify-center overflow-hidden rounded-[14px] bg-[#EEF1F6]" :style="[GRID, { height: height + 'px' }]">
    <div dir="auto" class="rounded-xl border border-dashed border-[#A8E4EF] bg-white px-4 py-2.5 text-center">
      <div class="text-[11px] font-extrabold text-[#0d5866]">{{ label ? bi(label) : t('الخريطة الحية — مواقع المركبات', 'Live map — vehicle positions') }}</div>
      <div class="mt-[3px] text-[9.5px] text-muted">{{ t('Integration Pending — يتطلب ربط مزود Telematics / GPS', 'Integration Pending — requires a telematics / GPS provider') }}</div>
    </div>
  </div>
</template>
