<script setup>
// Inline "label value" pair of the warehouse profile.  <WhMeta :k="{ ar, en }" :v="w.city" />  (slot = custom value)
import { bi } from '@/i18n';

defineProps({
  k: { type: [String, Object], required: true },
  v: { type: [String, Number], default: null },
});
</script>

<template>
  <div class="flex gap-1.5 text-[10.5px]">
    <div class="font-extrabold text-faint">{{ bi(k) }}</div>
    <div class="font-bold"><slot>{{ v ?? '—' }}</slot></div>
  </div>
</template>
