<script setup>
// Phone bottom sheet: dimmed backdrop, rounded top, grabber, optional title + close. Escape and the backdrop close it.
//   <BottomSheet :open="open" :title="{ ar, en }" @close="open = false"> … </BottomSheet>
import { onBeforeUnmount, watch } from 'vue';
import { useScrollLock } from '../../composables/scrollLock';
import { bi, dir, isBi } from '../../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
});
const emit = defineEmits(['close']);

const onKey = (e) => { if (e.key === 'Escape') emit('close'); };
watch(() => props.open, (open) => {
  if (open) window.addEventListener('keydown', onKey); else window.removeEventListener('keydown', onKey);
}, { immediate: true });
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
useScrollLock(() => props.open);
</script>

<template>
  <Teleport to="body">
    <Transition name="m-sheet">
      <div v-if="open" class="m-sheet-wrap" :dir="dir" @click.self="emit('close')">
        <div class="m-sheet" role="dialog" aria-modal="true">
          <div class="m-sheet-grab" />
          <div v-if="title" class="m-sheet-head">
            <div class="m-sheet-title">{{ isBi(title) ? bi(title) : title }}</div>
            <button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
          </div>
          <div class="m-sheet-body"><slot /></div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
