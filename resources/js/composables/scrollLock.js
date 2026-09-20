// Freezes the page behind an overlay (drawer, dialog, sheet, search), so on a phone the screen that is open feels
// fixed like an app screen: the list underneath cannot scroll or bounce, and it is exactly where it was on close.
// Locks are counted — a confirm dialog on top of a drawer keeps the page frozen until both are closed.
//   useScrollLock(() => props.open)
import { onBeforeUnmount, watch } from 'vue';
import { isMobile } from './viewport';

let locks = 0;
let savedY = 0;
let applied = false;

function lock() {
  if (locks++ > 0) return;
  if (!isMobile.value) return; // desktop keeps its page scrollbar: hiding it would shift the layout
  applied = true;
  savedY = window.scrollY;
  const b = document.body;
  // position: fixed is the only lock iOS Safari honours; the offset keeps the page visually in place
  b.style.position = 'fixed';
  b.style.top = `-${savedY}px`;
  b.style.insetInline = '0';
  b.style.width = '100%';
  b.style.overflow = 'hidden';
}
function unlock() {
  if (locks === 0 || --locks > 0) return;
  if (!applied) return;
  applied = false;
  const b = document.body;
  b.style.position = ''; b.style.top = ''; b.style.insetInline = ''; b.style.width = ''; b.style.overflow = '';
  window.scrollTo(0, savedY);
}

export function useScrollLock(isOpen) {
  let held = false;
  const set = (open) => {
    if (open && !held) { held = true; lock(); } else if (!open && held) { held = false; unlock(); }
  };
  watch(isOpen, set, { immediate: true });
  onBeforeUnmount(() => set(false));
}
