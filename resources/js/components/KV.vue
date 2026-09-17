<script setup>
// Key / value pair. Inside `<div class="kv-grid">` it stacks (label above value); elsewhere it is one row.
//   <KV :k="{ ar: 'التاريخ', en: 'Date' }" :v="fmtDate(d.date)" ltr />        or slot content for the value
import { bi } from '../i18n';

defineProps({
  k: { type: [String, Object], required: true },
  v: { type: [String, Number], default: null },
  ltr: { type: Boolean, default: false },
});
</script>

<template>
  <div class="kv">
    <div class="kv-k">{{ bi(k) }}</div>
    <div class="kv-v" :class="{ 'ltr text-start': ltr }"><slot>{{ v ?? '—' }}</slot></div>
  </div>
</template>
