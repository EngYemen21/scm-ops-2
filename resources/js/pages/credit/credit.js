// «حسابات الائتمان الآجل» — what the views of the section share: the query-string state of the page, the lookups every
// form and filter starts from, small formats and the browser helpers for papers (read a file, open a paper, print the
// agreement). The label maps and chip colours are in ./labels.js.
// API (routes/api/credit.php, everything under /api/credit, page.credit): GET lookups · summary · customers · terms ·
// requests · requests/{id} · requests/{id}/private · requests/{id}/agreement · requests/{id}/documents/{file}?as=data,
// and the moves listed at the top of RequestDrawer.vue / SettingsTab.vue.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { fmtDateOnly, fmtMoney, lang, nm } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

// ---------------------------------------------------------------- names and figures
/** Arabic / English name of a stage, a role, a person (nameAr / nameEn), else the username. */
export const nameOf = (o) => (o ? nm(o) || o.username || o.name || '—' : '—');
/** A label pair the API sends as labelAr / labelEn. */
export const labelOf = (o) => (o ? (lang.value === 'ar' ? o.labelAr || o.labelEn : o.labelEn || o.labelAr) || '' : '');
export const CUR = { SAR: { ar: 'ر.س', en: 'SAR' } };
/** An amount (API decimals are strings) with its currency; «—» when there is none. */
export function money(v, currency = 'SAR') {
  if (v == null || v === '') return '—';
  const c = CUR[currency] ? (lang.value === 'ar' ? CUR[currency].ar : CUR[currency].en) : currency || '';
  return `${fmtMoney(v)} ${c}`.trim();
}
/** A day the API gives as `YYYY-MM-DD`, shown as the same calendar day. */
export const fmtDay = (d) => (d ? fmtDateOnly(String(d).slice(0, 10).replace(/-/g, '/')) : '—');
/** Hours of a stage (null = none). */
export const hours = (h) => (h == null || h === '' ? '—' : `${Number(h)} ${lang.value === 'ar' ? 'س' : 'h'}`);
/** 1 234 567 → "1.2 MB". */
export function fileSize(n) {
  const v = Number(n) || 0;
  if (v >= 1048576) return `${(v / 1048576).toFixed(1)} MB`;
  if (v >= 1024) return `${Math.round(v / 1024)} KB`;
  return `${v} B`;
}
/** Today as `YYYY-MM-DD` (local). */
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

// ---------------------------------------------------------------- query-string state
/** The tabs of the page (`tab=`). */
export const TABS = ['waiting', 'requests', 'mine', 'setup'];
/** The filters of a list (keys of the query string = parameters of GET /credit/requests). */
export const LIST_FILTERS = ['q', 'state', 'stage', 'sla', 'origin', 'apply', 'from', 'to'];
export const SORTS = ['number', 'requestDate', 'companyName', 'requestedCreditLimit', 'approvedCreditLimit', 'slaDeadline', 'submittedAt', 'stageEnteredAt', 'createdAt', 'updatedAt', 'state'];

/**
 * Everything the page shows is in the query string of the page, so every tab, filter and open request is a link:
 *   tab=waiting|requests|mine|setup · q · state · stage · sla · origin · apply · from · to · sort · order · page
 *   req=<id> opens a request (on any tab) · set=stages|terms|methods|policies (the setup tab)
 */
export function useCreditNav() {
  const qs = useQueryState();
  const { get } = qs;
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  const reqOpen = computed(() => get('req') || null);

  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  /** Opens a tab; what belonged to the previous one (filters, page, sort) is dropped, an open request stays open. */
  const go = (tab, params = {}) => qs.replace({
    tab, ...Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '')), ...(get('req') ? { req: get('req') } : {}),
  });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const openReq = (id) => patch({ req: id });
  const closeReq = () => patch({ req: null });
  const hasFilters = computed(() => LIST_FILTERS.some((k) => !!get(k)));
  const clearFilters = () => patch(Object.fromEntries([...LIST_FILTERS, 'page'].map((k) => [k, null])));
  return { get, page, reqOpen, patch, go, setFilter, setPage, openReq, closeReq, hasFilters, clearFilters };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a filter that clears the key refills the box.
 */
export function useSearchParam(nav, key = 'q') {
  let applied = nav.get(key);
  const text = ref(applied);
  let timer = null;
  function apply() {
    clearTimeout(timer);
    const v = String(text.value ?? '').trim();
    if (v === applied) return;
    applied = v;
    nav.setFilter(key, v);
  }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  watch(() => nav.get(key), (v) => { if (v !== applied) { applied = v; text.value = v; } });
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, type, apply };
}

// ---------------------------------------------------------------- lookups
/**
 * GET /credit/lookups, shared by every view (one request, fresh for half a minute) and shaped for the pickers:
 *   const lk = useCreditLookups();  lk.can.value.manage · lk.stageOptions.value · lk.settings.value.calendar …
 */
export function useCreditLookups() {
  const q = useGet('/credit/lookups', undefined, { staleTime: 30_000 });
  const data = computed(() => q.data.value || null);
  const list = (key) => computed(() => data.value?.[key] || []);
  const states = list('states');
  const stages = list('stages');
  const path = list('path');
  const paymentMethods = list('paymentMethods');
  const roles = list('roles');
  const slots = list('slots');
  const named = (rows) => rows.map((x) => ({ v: String(x.id), l: { ar: x.nameAr, en: x.nameEn || x.nameAr } }));
  return {
    query: q, data, states, stages, path, paymentMethods, roles, slots,
    loaded: computed(() => !!data.value),
    can: computed(() => data.value?.can || {}),
    followsOdoo: computed(() => !!data.value?.followsOdoo),
    settings: computed(() => data.value?.settings || {}),
    channels: computed(() => data.value?.channels || {}),
    signatureProvider: computed(() => data.value?.signatureProvider || null),
    termsInForce: computed(() => data.value?.termsInForce || null),
    stageOptions: computed(() => named(stages.value.filter((s) => s.active))),
    stateOptions: computed(() => states.value.map((s) => ({ v: s.value, l: { ar: s.labelAr, en: s.labelEn || s.labelAr } }))),
    roleOptions: computed(() => roles.value.map((r) => ({ v: r.key, l: { ar: r.nameAr || r.key, en: r.nameEn || r.nameAr || r.key } }))),
    methodOptions: computed(() => paymentMethods.value.filter((m) => m.active)),
  };
}

// ---------------------------------------------------------------- papers in the browser
export const MAX_FILE = 5 * 1024 * 1024;
/** A paper the API takes: a PDF or an image of at most 5 MB. Returns an error text pair, or null when it is fine. */
export function fileProblem(file) {
  if (!file) return { ar: 'لم يُختر ملف', en: 'No file chosen' };
  const okType = file.type === 'application/pdf' || String(file.type).startsWith('image/');
  if (!okType) return { ar: 'يُقبل ملف PDF أو صورة فقط', en: 'Only a PDF or an image is accepted' };
  if (file.size > MAX_FILE) return { ar: 'حجم الملف أكبر من 5 ميغابايت', en: 'The file is larger than 5 MB' };
  return null;
}
/** The file as a data URL (what POST …/documents and …/signed take as `base64`). */
export function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const fr = new FileReader();
    fr.onload = () => resolve(String(fr.result));
    fr.onerror = () => reject(fr.error || new Error('read failed'));
    fr.readAsDataURL(file);
  });
}
function dataUrlToBlob(dataUrl) {
  const [head, body] = String(dataUrl).split(',');
  const mime = (head.match(/data:([^;]+)/) || [])[1] || 'application/octet-stream';
  const bin = atob(body || '');
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Blob([bytes], { type: mime });
}
/**
 * Opens a paper the API sent as `{ fileName, dataUrl }` (a link could not carry the bearer token). `win` is a window
 * opened in the click itself (so no popup blocker stops it); without one the paper is downloaded instead.
 */
export function showDataUrl(file, win, download = false) {
  const url = URL.createObjectURL(dataUrlToBlob(file.dataUrl));
  if (win && !download) {
    win.location.href = url;
  } else {
    const a = document.createElement('a');
    a.href = url; a.download = file.fileName || 'document'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
  }
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
/** A blank window opened inside a click, to receive a paper once it has been read. */
export const blankWindow = () => { try { return window.open('', '_blank'); } catch { return null; } };
/** Prints a whole HTML page (the agreement) from a hidden frame, without leaving the screen. */
export function printHtml(html) {
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;inset-inline-start:-10000px;top:0;width:900px;height:1200px;border:0;';
  frame.srcdoc = html;
  frame.onload = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); } finally { setTimeout(() => frame.remove(), 60_000); }
  };
  document.body.appendChild(frame);
}
