<script setup>
// Relative time ("قبل 5 د" / "5m ago") with the exact timestamp as tooltip.   <Ago :at="row.createdAt" />
import { fmtAgo, lang } from '@/i18n';

defineProps({ at: { type: String, default: null } });
</script>

<template>
  <span class="num flex-none text-[9px] text-faint" :title="at || null">{{ fmtAgo(at, lang) }}</span>
</template>
