<script setup>
// Integration status chip. Shows exactly what the API reports: `integration_pending` → "Integration Pending";
// "Connected" only for `connected`. A missing status is treated as pending, never as live.
import { Chip } from '@/components';
import { INTEGRATION_LABELS } from './shared';

defineProps({
  status: { type: String, default: null },
  small: { type: Boolean, default: false },
});
</script>

<template>
  <Chip :map="INTEGRATION_LABELS" :k="status || 'integration_pending'" :small="small" />
</template>
