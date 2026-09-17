<script setup>
// SLA countdown chip: green when resolved, red when breached, amber under one hour, teal otherwise.
//   <SlaBadge :sla="e.sla" :sla-hours="e.slaHours" :status="e.status" />      (renders nothing without `sla`)
import { computed } from 'vue';
import { Chip } from '@/components';
import { lang } from '@/i18n';
import { slaLabel } from './shared';

const props = defineProps({
  /** { dueAt, leftMin, breached, labelAr } */
  sla: { type: Object, default: null },
  slaHours: { type: Number, default: null },
  status: { type: String, default: null },
});

const tone = computed(() => {
  if (props.status === 'resolved') return { fg: '#1d7a3e', bg: '#e6f9ec' };
  if (props.sla?.breached) return { fg: '#b23b3b', bg: '#fdecec' };
  if (props.sla && props.sla.leftMin < 60) return { fg: '#b26a16', bg: '#fbf0dd' };
  return { fg: '#0d7f93', bg: '#d9f4f9' };
});
</script>

<template>
  <Chip v-if="sla" small :fg="tone.fg" :bg="tone.bg" :title="sla.dueAt"><span class="num-mixed">{{ slaLabel(sla, slaHours, lang) }}</span></Chip>
</template>
