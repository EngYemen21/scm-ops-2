<script setup>
// Several tags chosen by id — the tags of a task or of a document. Each tag is a pill in its own colour; the tags of
// documents stand under the heading they belong to (`group`). A tag that was put away is offered only where it is
// already chosen.
//   <TagPicker v-model="f.tagIds" :tags="lookups.tags" :label="{ ar: 'الوسوم', en: 'Tags' }" />
import { computed } from 'vue';
import { bi, t } from '@/i18n';
import { arr, tagStyle } from './workspace';

const props = defineProps({
  /** The chosen tag ids. */
  modelValue: { type: Array, default: () => [] },
  /** [{ id, name, color, group?, active? }] */
  tags: { type: Array, default: () => [] },
  label: { type: [String, Object], default: null },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const picked = computed(() => new Set(arr(props.modelValue).map(String)));
/** [{ heading, tags }] — one block per heading, tags without one first. */
const groups = computed(() => {
  const by = new Map();
  for (const tag of arr(props.tags)) {
    if (tag.active === false && !picked.value.has(String(tag.id))) continue;
    const heading = tag.group || '';
    if (!by.has(heading)) by.set(heading, []);
    by.get(heading).push(tag);
  }
  return [...by.entries()].sort(([a], [b]) => (a === '' ? -1 : b === '' ? 1 : a.localeCompare(b))).map(([heading, tags]) => ({ heading, tags }));
});
function toggle(tag) {
  if (props.disabled) return;
  const id = String(tag.id);
  emit('update:modelValue', picked.value.has(id) ? arr(props.modelValue).map(String).filter((x) => x !== id) : [...arr(props.modelValue).map(String), id]);
}
const look = (tag) => (picked.value.has(String(tag.id)) ? { color: tagStyle(tag.color).fg, background: tagStyle(tag.color).bg, borderColor: tagStyle(tag.color).fg } : null);
</script>

<template>
  <div class="field full">
    <div v-if="label" class="field-l">{{ bi(label) }}</div>
    <div v-if="!groups.length" class="text-[10.5px] text-faint">{{ t('لا وسوم معرَّفة بعد.', 'No tags defined yet.') }}</div>
    <div v-for="g in groups" :key="g.heading" class="flex flex-wrap items-center gap-1.5">
      <span v-if="g.heading" class="text-[9.5px] font-extrabold text-muted" dir="auto">{{ g.heading }}:</span>
      <button v-for="tag in g.tags" :key="tag.id" type="button" class="pill !h-7 !px-2.5" :style="look(tag)" :aria-pressed="picked.has(String(tag.id))" :disabled="disabled" @click="toggle(tag)">
        <span v-if="picked.has(String(tag.id))">✓</span><span dir="auto">{{ tag.name }}</span>
      </button>
    </div>
  </div>
</template>
