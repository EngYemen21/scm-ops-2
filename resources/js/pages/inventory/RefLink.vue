<script setup>
// Reference link when the document type maps to a deep link (`entityPath`), else plain text.
//   <RefLink :type="m.referenceType" :number="m.referenceNumber" />
import { computed } from 'vue';
import { entityPath } from '@/router/routes';

const props = defineProps({
  type: { type: String, default: null },
  number: { type: String, default: null },
});
const path = computed(() => entityPath(props.type, props.number));
</script>

<template>
  <span v-if="!number" class="muted">—</span>
  <RouterLink v-else-if="path" :to="path" class="font-extrabold !text-brand-dark no-underline" @click.stop>{{ number }}</RouterLink>
  <span v-else class="font-extrabold text-brand-dark">{{ number }}</span>
</template>
