<script setup>
// Acknowledge / resolve buttons of one exception. Hidden without `exception.manage` or once resolved.
// The buttons only ask for the note modal:  <ExceptionActions :row="e" @act="(mode) => exc.open(mode, e.number)" />
import { Btn } from '@/components';
import { useAuth } from '@/stores/auth';

defineProps({
  /** { number, status } */
  row: { type: Object, required: true },
  size: { type: String, default: 'sm' },
});
const emit = defineEmits(['act']);
const auth = useAuth();
</script>

<template>
  <span v-if="auth.can('exception.manage') && row.status !== 'resolved'" class="row flex-none !gap-1.5" @click.stop>
    <Btn v-if="row.status === 'open'" tone="dark" :size="size" :label="{ ar: 'استلام', en: 'Ack' }" @click="emit('act', 'ack')" />
    <Btn :tone="row.status === 'open' ? 'softGreen' : 'success'" :size="size" :label="{ ar: 'إغلاق', en: 'Resolve' }" @click="emit('act', 'resolve')" />
  </span>
</template>
