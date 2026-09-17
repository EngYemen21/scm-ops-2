<script setup>
// Status history: rows with a coloured dot, label, "by · when", optional note.
//   <Timeline :items="[{ at, label: { ar, en }, by: 'sales', note, color: '#1d7a3e' }]" />   slot `#chip="{ item }"` for a chip beside the label
import { bi, fmtDate, isBi } from '../i18n';

defineProps({
  items: { type: Array, required: true },
  emptyText: { type: [String, Object], default: null },
});
</script>

<template>
  <div v-if="!items.length" class="empty !p-3.5">{{ emptyText ? bi(emptyText) : '—' }}</div>
  <div v-else class="timeline">
    <div v-for="(it, i) in items" :key="i" class="tl-item">
      <div class="tl-dot" :style="{ background: it.color || '#1BC4DB' }" />
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-[10.5px] font-extrabold">{{ isBi(it.label) ? bi(it.label) : it.label }}</span>
          <slot name="chip" :item="it" />
        </div>
        <div class="cell-date mt-0.5" style="direction: inherit"><span v-if="it.by" class="font-sans">{{ it.by }} · </span><span class="ltr">{{ fmtDate(it.at) }}</span></div>
        <div v-if="it.note" class="mt-[3px] text-[10px] leading-[1.7] text-sec">{{ isBi(it.note) ? bi(it.note) : it.note }}</div>
      </div>
    </div>
  </div>
</template>
