<script setup>
// Drawer wrapper: loads a sales order by number and renders <SoDetail>.
//   <SoDrawer :number="openSo" @close="openSo = null" />
import { useGet } from '@/api/client';
import { Drawer, ErrorBanner } from '@/components';
import SoDetail from './SoDetail.vue';

const props = defineProps({
  /** Sales-order number; null = closed. */
  number: { type: String, default: null },
});
const emit = defineEmits(['close']);

const det = useGet(() => (props.number ? `/sales/orders/${encodeURIComponent(props.number)}` : null));
</script>

<template>
  <Drawer :open="!!number" :width="760" :sub="{ ar: 'أمر بيع — الأسطر والحجز والتخصيص والتتبع', en: 'Sales order — lines, reservation, allocation, traceability' }" @close="emit('close')">
    <template #title><span class="num-mixed">{{ number }}</span></template>
    <ErrorBanner :error="det.error.value" :closable="false" />
    <div v-if="det.isLoading.value && !det.data.value" class="skel h-40" />
    <SoDetail v-if="det.data.value" :so="det.data.value" @changed="det.refetch()" />
  </Drawer>
</template>
