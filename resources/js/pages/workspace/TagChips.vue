<script setup>
// The tags of a task or a document, each in the colour people picked for it.   <TagChips :tags="task.tags" />
import { Chip } from '@/components';
import { arr, tagStyle } from './workspace';

defineProps({
  /** [{ id, name, color, group? }] */
  tags: { type: Array, default: () => [] },
});
</script>

<template>
  <span v-if="arr(tags).length" class="inline-flex flex-wrap items-center gap-1 align-middle">
    <Chip v-for="tag in arr(tags)" :key="tag.id" small :label="String(tag.name ?? '')" :fg="tagStyle(tag.color).fg" :bg="tagStyle(tag.color).bg" :title="tag.group || null" />
  </span>
</template>
