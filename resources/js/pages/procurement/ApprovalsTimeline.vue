<script setup>
// Approval chain as a timeline (PO / PR): document creation, then one row per tier with its decision chip, approver and note.
import { computed } from 'vue';
import { Chip, Timeline } from '@/components';
import { lang, t } from '@/i18n';
import { DECISION_LABELS } from './shared';

const props = defineProps({
  /** Approval[] — see shared.js */
  approvals: { type: Array, default: () => [] },
  createdBy: { type: String, default: null },
  createdAt: { type: String, default: null },
});

const COLORS = { approved: '#1d7a3e', rejected: '#b23b3b', cancelled: '#a8a4b8' };
const items = computed(() => [
  ...(props.createdAt ? [{ at: props.createdAt, label: t('إنشاء المستند', 'Created'), by: props.createdBy || undefined, color: '#1BC4DB' }] : []),
  ...(props.approvals || []).map((a) => ({
    at: a.decidedAt, by: a.approver || undefined, note: a.note || undefined, decision: a.decision,
    label: `${t('الخطوة', 'Step')} ${a.step} — ${lang.value === 'ar' ? a.labelAr : a.labelEn}`,
    color: COLORS[a.decision] || '#b26a16',
  })),
]);
</script>

<template>
  <Timeline :items="items" :empty-text="{ ar: 'لا سلسلة اعتماد', en: 'No approval chain' }">
    <template #chip="{ item }"><Chip v-if="item.decision" small :map="DECISION_LABELS" :k="item.decision" /></template>
  </Timeline>
</template>
