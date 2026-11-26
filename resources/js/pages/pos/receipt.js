// Printing a receipt: GET /pos/orders/{id}/receipt?format=80mm|a4 answers text/html (the receipt as the server lays
// it out, with the ZATCA QR). The API client returns the body as text for a non-JSON answer and adds the bearer token,
// so the page is fetched with it and printed from a hidden iframe (srcdoc) — no new window, no popup blocker.
import { ref } from 'vue';
import { api, errorMessage } from '@/api/client';
import { toast } from '@/stores/ui';

let frame = null;

/** For print buttons: `const pr = usePrint(); pr.print(id, 'a4')`, `pr.printing.value` = the format being printed. */
export function usePrint() {
  const printing = ref(null);
  async function print(orderId, format) {
    if (!orderId || printing.value) return;
    printing.value = format;
    try { await printReceipt(orderId, format); } catch (e) { toast.say({ ar: `تعذرت طباعة الإيصال: ${errorMessage(e, 'ar')}`, en: `Could not print the receipt: ${errorMessage(e, 'en')}` }, 3600); } finally { printing.value = null; }
  }
  return { printing, print };
}

/** Fetches the receipt and opens the print dialog for it. Rejects with the ApiError when the server refuses. */
export async function printReceipt(orderId, format = '80mm') {
  const html = await api.get(`/pos/orders/${encodeURIComponent(orderId)}/receipt`, { format });
  if (typeof html !== 'string' || !html) throw new Error('empty receipt');
  if (frame) { frame.remove(); frame = null; }
  const f = document.createElement('iframe');
  f.setAttribute('aria-hidden', 'true');
  f.setAttribute('tabindex', '-1');
  f.style.cssText = 'position:fixed;inset-inline-end:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
  frame = f;
  await new Promise((resolve) => {
    f.onload = () => resolve();
    f.srcdoc = html;
    document.body.appendChild(f);
  });
  // let the QR image / fonts settle before the dialog takes its snapshot
  await new Promise((resolve) => setTimeout(resolve, 250));
  try {
    f.contentWindow.focus();
    f.contentWindow.print();
  } finally {
    // the dialog is modal in most browsers; keep the frame a moment for those where it is not
    setTimeout(() => { if (frame === f) { f.remove(); frame = null; } }, 60_000);
  }
  return true;
}
