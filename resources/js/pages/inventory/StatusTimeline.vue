<script setup>
// Status history whose label IS the status chip (counts, transfers). Same markup as the shared <Timeline />,
// which can only put a chip beside a text label.
//   <StatusTimeline :history="count.history" :map="COUNT_LABELS" :empty-text="{ ar, en }" />
import { Chip } from '@/components';
import { bi, fmtDate } from '@/i18n';

defineProps({
  /** [{ id, toStatus, username, note, at }] */
  history: { type: Array, default: () => [] },
  /** Label map of the statuses (colours the dot and the chip). */
  map: { type: Object, required: true },
  emptyText: { type: [String, Object], default: null },
});
</script>

<template>
  <div v-if="!history.length" class="empty !p-3.5">{{ emptyText ? bi(emptyText) : '—' }}</div>
  <div v-else class="timeline">
    <div v-for="(h, i) in history" :key="h.id || i" class="tl-item">
      <div class="tl-dot" :style="{ background: map[h.toStatus]?.fg || '#1BC4DB' }" />
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2"><Chip small :map="map" :k="h.toStatus" /></div>
        <div class="cell-date mt-0.5" style="direction: inherit"><span v-if="h.username" class="font-sans">{{ h.username }} · </span><span class="ltr">{{ fmtDate(h.at) }}</span></div>
        <div v-if="h.note" class="mt-[3px] text-[10px] leading-[1.7] text-sec">{{ h.note }}</div>
      </div>
    </div>
  </div>
</template>
