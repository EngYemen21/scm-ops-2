<script setup>
// Flag chips for a balance row (falls back to the computed status chip).
import { Chip } from '@/components';
import { BALANCE_STATUS, FLAG_LABELS } from './shared';

defineProps({
  /** { flags: string[], status: string } */
  row: { type: Object, required: true },
});
</script>

<template>
  <div class="row wrap !gap-1">
    <template v-if="row.flags?.length"><Chip v-for="f in row.flags" :key="f" small :map="FLAG_LABELS" :k="f" /></template>
    <Chip v-else small :map="BALANCE_STATUS" :k="row.status" />
  </div>
</template>
