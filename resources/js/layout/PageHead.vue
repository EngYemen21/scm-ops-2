<script setup>
// Declarative page title bar. Mount it once inside a page:
//   <PageHead :sub="t('عرض سعر ← أمر بيع', 'Quote → order')"><Btn … /><Btn … /></PageHead>
// `title` overrides the default nav title; the default slot holds the command buttons (teleported into the shell);
// `hidden` removes the shell title bar when the page draws its own.
import { onBeforeUnmount, watchEffect } from 'vue';
import { pageHeader } from '../stores/ui';

const props = defineProps({
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  hidden: { type: Boolean, default: false },
});

watchEffect(() => { pageHeader.value = { title: props.title, sub: props.sub, hidden: props.hidden }; });
onBeforeUnmount(() => { pageHeader.value = {}; });
</script>

<template>
  <Teleport v-if="!hidden" defer to="#page-actions"><slot /></Teleport>
</template>
