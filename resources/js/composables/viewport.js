// Phone layout switch. One media query for the whole app, so CSS (app.css `@media (max-width: 767px)`) and
// components (`isMobile`) always agree on what "mobile" means. Reactive: rotating or resizing re-evaluates it.
import { ref } from 'vue';

export const MOBILE_QUERY = '(max-width: 767px)';

const mq = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(MOBILE_QUERY) : null;
export const isMobile = ref(!!mq?.matches);
mq?.addEventListener?.('change', (e) => { isMobile.value = e.matches; });

/** A device worth offering the camera scanner on: a phone, or anything driven by touch (tablets, handhelds). */
export const canScanWithCamera = () => typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia
  && (isMobile.value || (typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches));
