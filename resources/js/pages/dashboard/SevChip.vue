<script setup>
// Severity pill (c / w / i). Unknown or missing severity renders as "medium".   <SevChip :k="e.severity" small />
import { Chip } from '@/components';
import { SEVERITY } from './shared';

defineProps({
  k: { type: String, default: null },
  small: { type: Boolean, default: false },
});
</script>

<template>
  <Chip :map="SEVERITY" :k="k || 'i'" :small="small" class="flex-none" />
</template>
