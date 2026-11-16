<script setup>
// Return status chip; a closed return also shows its decision ("مكتمل — إعادة للمخزون", "مكتمل — قرارات متعددة").
import { computed } from 'vue';
import { Chip } from '@/components';
import { bi } from '@/i18n';
import { RETURN_LABELS } from '@/shared';
import { decisionLabel } from './shared';

const props = defineProps({
  r: { type: Object, required: true },
  small: { type: Boolean, default: false },
});
const base = computed(() => RETURN_LABELS[props.r.status]);
const closedWithDecision = computed(() => props.r.status === 'closed' && !!props.r.decision && !!base.value);
</script>

<template>
  <Chip v-if="closedWithDecision" :small="small" :fg="base.fg" :bg="base.bg" :label="`${bi(base)} — ${bi(decisionLabel(r.decision))}`" />
  <Chip v-else :small="small" :map="RETURN_LABELS" :k="r.status" />
</template>
