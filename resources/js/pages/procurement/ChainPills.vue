<script setup>
// Approval chain as pills, one per tier: ✓ approved (green) · rejected (red) · current pending step (amber) · later steps (grey).
import { computed } from 'vue';
import { Chip } from '@/components';
import { fmtDateOnly, lang } from '@/i18n';

const props = defineProps({
  /** Approval[] — see shared.js */
  approvals: { type: Array, default: () => [] },
});

const pills = computed(() => {
  let currentSeen = false;
  return (props.approvals || []).map((a) => {
    const done = a.decision === 'approved';
    const isCurrent = !done && !currentSeen && a.decision === 'pending';
    if (isCurrent) currentSeen = true;
    const style = done ? { fg: '#1d7a3e', bg: '#e6f9ec' } : a.decision === 'rejected' ? { fg: '#b23b3b', bg: '#fdecec' } : isCurrent ? { fg: '#b26a16', bg: '#fbf0dd' } : { fg: '#a8a4b8', bg: '#F1EFF6' };
    return { key: a.id || a.step, ...style, label: `${done ? '✓ ' : ''}${lang.value === 'ar' ? a.labelAr : a.labelEn}`, title: a.approver ? `${a.approver} · ${fmtDateOnly(a.decidedAt)}` : null };
  });
});
</script>

<template>
  <span v-if="!pills.length" class="muted text-[9px]">—</span>
  <div v-else class="row wrap !gap-1">
    <Chip v-for="p in pills" :key="p.key" small :fg="p.fg" :bg="p.bg" :label="p.label" :title="p.title" />
  </div>
</template>
