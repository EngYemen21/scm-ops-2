// App-wide UI state that is not tied to a page: toast, confirm dialog.
// Plain module state (no Pinia needed) so non-component code such as the API client can use it.
import { ref } from 'vue';
import { bi } from '../i18n';

// ---------- toast ----------
/** Text of the toast currently shown (null = hidden). Rendered by <ToastHost />. */
export const toastMessage = ref(null);
let toastTimer;
export const toast = {
  /** `toast.say('تم الحفظ')` or `toast.say({ ar, en })` */
  say(msg, ms = 2600) {
    toastMessage.value = bi(msg);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastMessage.value = null; }, ms);
  },
};
export const useToast = () => toast;

// ---------- confirm ----------
/** Options of the confirm dialog currently shown (null = hidden). Rendered by <ConfirmHost />. */
export const confirmState = ref(null);
let confirmResolver = null;

/**
 * `if (await confirm({ title: { ar, en }, sub, okLabel, cancelLabel, tone: 'danger' | 'dark' | 'primary' })) …`
 * Also accepts a plain string / {ar,en} as the title.
 */
export function confirm(opts) {
  const o = typeof opts === 'string' || (opts && 'ar' in opts && !('title' in opts)) ? { title: opts } : opts;
  return new Promise((resolve) => { confirmResolver = resolve; confirmState.value = o; });
}
export function resolveConfirm(value) {
  confirmResolver?.(value);
  confirmResolver = null;
  confirmState.value = null;
}
export const useConfirm = () => confirm;

// ---------- page header ----------
/** Title bar overrides set by the current page through <PageHead />. `{ title, sub, hidden }` */
export const pageHeader = ref({});
