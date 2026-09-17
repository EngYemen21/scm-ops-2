<script setup>
// Entity number of an activity / audit row; a click opens the entity's page when a deep link is known.
//   <EntityCell :row="r" />     row: { entityType, entityNumber, entityId }
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { entityPath } from '@/router/routes';

const props = defineProps({ row: { type: Object, required: true } });
const router = useRouter();
const path = computed(() => entityPath(props.row.entityType, props.row.entityNumber || props.row.entityId));
function open(ev) {
  if (!path.value) return;
  ev.stopPropagation();
  router.push(path.value);
}
</script>

<template>
  <span class="cell-id" :class="{ 'cursor-pointer': !!path }" :title="row.entityType || null" @click="open">{{ row.entityNumber || row.entityType || '—' }}</span>
</template>
