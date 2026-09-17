<script setup>
// "n يوم" days-left chip coloured by urgency (null → "—").
import { computed } from 'vue';
import { Chip } from '@/components';
import { fmtNum, t } from '@/i18n';
import { daysColor } from './shared';

const props = defineProps({
  days: { type: Number, default: null },
  small: { type: Boolean, default: true },
});
const label = computed(() => (props.days == null ? '' : props.days < 0 ? t(`منتهٍ منذ ${fmtNum(-props.days)} ي`, `${fmtNum(-props.days)}d ago`) : t(`${fmtNum(props.days)} يوم`, `${fmtNum(props.days)} days`)));
const bg = computed(() => (props.days < 0 || props.days <= 7 ? '#fdecec' : props.days <= 30 ? '#fbf0dd' : '#e6f9ec'));
</script>

<template>
  <span v-if="days == null" class="muted">—</span>
  <Chip v-else :small="small" :label="label" :fg="daysColor(days)" :bg="bg" />
</template>
