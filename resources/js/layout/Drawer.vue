<script setup>
// Side drawer on the inline-end side (left in RTL, right in LTR). Escape and the overlay close it.
//   <Drawer :open="open" :title="{ ar, en }" :width="520" @close="open = false">
//     body…  <template #headExtra>…</template>  <template #footer>…</template>
//   </Drawer>
// Typical widths: 430 inventory · 520 forms / fleet · 760 trip control room.
// On a phone it is a full app screen (app.css): fixed app bar with a back arrow, scrolling body, fixed footer,
// and the page behind it is frozen.
import { onBeforeUnmount, watch } from 'vue';
import { isMobile } from '../composables/viewport';
import { useScrollLock } from '../composables/scrollLock';
import { bi, dir, isBi, t } from '../i18n';
import Icon from './Icon.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  width: { type: Number, default: 430 },
  /** Grey body background (trip control room). */
  dark: { type: Boolean, default: false },
  /** Dark header (#1E2130). */
  darkHead: { type: Boolean, default: false },
  zIndex: { type: Number, default: 60 },
  bodyClass: { type: [String, Array, Object], default: null },
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
  <template v-if="open">
    <div class="overlay" :style="{ zIndex }" @click="emit('close')" />
    <div class="drawer" :class="{ 'dark-bg': dark }" :dir="dir" :style="{ zIndex: zIndex + 1, insetInlineEnd: 0, width: `min(${width}px, 94vw)` }" role="dialog" aria-modal="true">
      <div v-if="title || sub || $slots.title || $slots.headExtra" class="drawer-head" :style="darkHead ? { background: '#1E2130', color: '#fff', borderBottom: 'none' } : null">
        <button v-if="isMobile" type="button" class="x-btn m-back" :class="{ dark: darkHead }" :aria-label="t('رجوع', 'Back')" @click="emit('close')"><Icon :name="dir === 'rtl' ? 'chevronRight' : 'chevronLeft'" :size="18" /></button>
        <div class="drawer-title">
          <slot name="title">{{ isBi(title) ? bi(title) : title }}</slot>
          <div v-if="sub || $slots.sub" class="drawer-sub" :style="darkHead ? { color: '#8b90a5' } : null"><slot name="sub">{{ isBi(sub) ? bi(sub) : sub }}</slot></div>
        </div>
        <slot name="headExtra" />
        <button v-if="!isMobile" type="button" class="x-btn" :class="{ dark: darkHead }" aria-label="close" @click="emit('close')">✕</button>
      </div>
      <div class="drawer-body" :class="bodyClass"><slot /></div>
      <div v-if="$slots.footer" class="drawer-foot"><slot name="footer" /></div>
    </div>
  </template>
</template>
