<script setup>
// Shipment status history (timeline of status changes).
import { computed } from 'vue';
import { Chip, Timeline } from '@/components';
import { SHIPMENT_LABELS } from '@/shared';

const props = defineProps({ shipment: { type: Object, required: true } });

// The label of each entry is the status chip (slot `chip`), so the text label stays empty.
const items = computed(() => (props.shipment.history || []).map((h) => ({ at: h.at, by: h.username, note: h.note, label: '', status: h.toStatus, color: SHIPMENT_LABELS[h.toStatus]?.fg || '#1BC4DB' })));
</script>

<template>
  <Timeline :items="items" :empty-text="{ ar: 'لا سجل', en: 'No history' }">
    <template #chip="{ item }"><span class="-ms-2 inline-flex"><Chip :map="SHIPMENT_LABELS" :k="item.status" small /></span></template>
  </Timeline>
</template>
