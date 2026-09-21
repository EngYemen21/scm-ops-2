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
  const asking = !!confirmState.value?.input;
  // `ask()` resolves to the typed text, or null when cancelled; `confirm()` to a boolean.
  confirmResolver?.(asking ? (value === false ? null : String(value ?? '').trim()) : value);
  confirmResolver = null;
  confirmState.value = null;
}
export const useConfirm = () => confirm;

/**
 * The same dialog with a text field — the replacement for `window.prompt` (never use the native one).
 * `const reason = await ask({ title: { ar, en }, label, required: true, tone: 'danger', okLabel }); if (reason === null) return;`
 * Resolves to the trimmed text ('' when left empty and not required) or null when cancelled.
 */
export function ask(opts) {
  const { label = null, placeholder = null, required = false, value = '', ...rest } = opts;
  return confirm({ tone: 'dark', ...rest, input: { label, placeholder, required, value } });
}

// ---------- page header ----------
/** Title bar overrides set by the current page through <PageHead />. `{ title, sub, hidden }` */
export const pageHeader = ref({});

// ---------- change-password dialog ----------
/** Opened from the desktop user menu and from the phone's More screen; rendered once by AppShell. */
export const passwordDialogOpen = ref(false);

// ---------- printable labels ----------
/** What <LabelHost /> shows (null = closed): `{ items: Label[], caption? }` or `{ custom: true }`.
 *  Label = `{ type: 'code128' | 'qr', text, title?, sub?, caption? }` — `text` is exactly what a scanner will read. */
export const labelState = ref(null);
/** One label. CODE128 for shipping / shelf labels, QR for runs and customers. */
export function showLabel(spec) { labelState.value = { items: [{ type: 'code128', ...spec }] }; }
/** Many labels in one print job (a rack, a zone, every batch of a receipt). */
export function showLabels(specs, caption = null) { labelState.value = { items: specs.map((s) => ({ type: 'code128', ...s })), caption }; }
/** Free-text label: the user types what the code holds. */
export function showCustomLabel() { labelState.value = { custom: true }; }
/** Absolute link into this application — what a QR label carries, so the phone scanner opens the document directly. */
export const appLink = (path) => `${window.location.origin}${path.startsWith('/') ? path : `/${path}`}`;

// ---------- page scan target ----------
// The scan field of the page on screen (the first mounted ScanInput). The topbar scan button of the task-focused
// roles hands a camera read to it, so "scan" is one tap from anywhere in the page.
const scanTargets = [];
export function registerScanTarget(handler) { scanTargets.push(handler); return () => { const i = scanTargets.indexOf(handler); if (i >= 0) scanTargets.splice(i, 1); }; }
/** Returns false when the page has no scan field to take the code. */
export function deliverScan(code) { const h = scanTargets[0]; if (!h) return false; h(code); return true; }
