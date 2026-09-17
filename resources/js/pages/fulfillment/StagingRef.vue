<script setup>
// Reference document of a staging row: a link for fulfillment orders and goods receipts, plain id otherwise.
//   <StagingRef :type="row.referenceType" :number="row.referenceNumber" />
import { computed } from 'vue';

const props = defineProps({
  type: { type: String, default: null },
  number: { type: String, default: null },
});
const path = computed(() => {
  if (!props.number) return null;
  if (props.type === 'FulfillmentOrder') return `/fo/${encodeURIComponent(props.number)}`;
  if (props.type === 'Grn' || props.type === 'GoodsReceipt') return `/grn/${encodeURIComponent(props.number)}`;
  return null;
});
</script>

<template>
  <template v-if="!number">—</template>
  <RouterLink v-else-if="path" :to="path" class="cell-id">{{ number }}</RouterLink>
  <span v-else class="cell-id">{{ number }}</span>
</template>
