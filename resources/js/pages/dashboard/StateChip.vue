<script setup>
// Exception status chip (open / ack / resolved).   <StateChip :k="e.status" small />
import { Chip } from '@/components';
import { EXCEPTION_STATE_LABELS } from '@/shared';

defineProps({
  k: { type: String, default: null },
  small: { type: Boolean, default: false },
});
</script>

<template>
  <Chip :map="EXCEPTION_STATE_LABELS" :k="k" :small="small" />
</template>
