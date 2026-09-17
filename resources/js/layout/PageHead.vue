<script setup>
// Declarative page title bar. Mount it once inside a page:
//   <PageHead :sub="t('عرض سعر ← أمر بيع', 'Quote → order')"><Btn … /><Btn … /></PageHead>
// - `title` / `sub` override the default nav title (plain text or {ar,en});
// - rich title or subtitle: <template #title>…</template> / <template #sub>…</template>;
// - the default slot holds the command buttons;
// - `hidden` removes the shell title bar when the page draws its own.
// Slots are teleported into the shell's title bar (AppShell.vue).
import { onBeforeUnmount, useSlots, watchEffect } from 'vue';
import { pageHeader } from '../stores/ui';

const props = defineProps({
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  hidden: { type: Boolean, default: false },
});
const slots = useSlots();

watchEffect(() => { pageHeader.value = { title: props.title, sub: props.sub, hidden: props.hidden, titleSlot: !!slots.title, subSlot: !!slots.sub }; });
onBeforeUnmount(() => { pageHeader.value = {}; });
</script>

<template>
  <template v-if="!hidden">
    <Teleport v-if="$slots.title" defer to="#page-title-slot"><slot name="title" /></Teleport>
    <Teleport v-if="$slots.sub" defer to="#page-sub-slot"><slot name="sub" /></Teleport>
    <Teleport defer to="#page-actions"><slot /></Teleport>
  </template>
</template>
