<script setup>
// Centered modal. `locked` hides ✕ and ignores overlay / Escape (forced password change).
//   <Modal :open="open" :title="{ ar, en }" :width="440" @close="open = false"> body <template #footer>…</template></Modal>
import { onBeforeUnmount, watch } from 'vue';
import { bi, dir, isBi } from '../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  width: { type: Number, default: 560 },
  locked: { type: Boolean, default: false },
  zIndex: { type: Number, default: 80 },
});
const emit = defineEmits(['close']);

const onKey = (e) => { if (e.key === 'Escape' && !props.locked) emit('close'); };
watch(() => props.open, (open) => {
  if (open) window.addEventListener('keydown', onKey); else window.removeEventListener('keydown', onKey);
}, { immediate: true });
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
  <div v-if="open" class="modal-wrap" :style="{ zIndex }" @mousedown.self="!locked && emit('close')">
    <div class="modal" :dir="dir" :style="{ width: `min(${width}px, 94vw)` }" role="dialog" aria-modal="true">
      <div v-if="title || sub || $slots.title" class="drawer-head">
        <div class="drawer-title">
          <slot name="title">{{ isBi(title) ? bi(title) : title }}</slot>
          <div v-if="sub || $slots.sub" class="drawer-sub"><slot name="sub">{{ isBi(sub) ? bi(sub) : sub }}</slot></div>
        </div>
        <button v-if="!locked" type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
      </div>
      <div class="modal-body"><slot /></div>
      <div v-if="$slots.footer" class="drawer-foot"><slot name="footer" /></div>
    </div>
  </div>
</template>
