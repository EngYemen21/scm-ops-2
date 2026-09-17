// Bilingual helpers (Arabic / English, RTL / LTR) and formatting.
// `lang` is a module-level ref, so `t('عربي', 'English')` is reactive in any template or computed.
import { computed, ref, watchEffect } from 'vue';

const LS_KEY = 'scm.lang';
const stored = (() => { try { return localStorage.getItem(LS_KEY); } catch { return null; } })();

/** Current UI language: 'ar' | 'en'. */
export const lang = ref(stored === 'en' ? 'en' : 'ar');
export const dir = computed(() => (lang.value === 'ar' ? 'rtl' : 'ltr'));
export const isAr = computed(() => lang.value === 'ar');

export function setLang(l) {
  lang.value = l === 'en' ? 'en' : 'ar';
  try { localStorage.setItem(LS_KEY, lang.value); } catch { /* ignore */ }
}
export const toggleLang = () => setLang(lang.value === 'ar' ? 'en' : 'ar');
export const getLang = () => lang.value;

watchEffect(() => {
  document.documentElement.lang = lang.value;
  document.documentElement.dir = dir.value;
});

/** `t('عربي', 'English')` */
export const t = (ar, en) => (lang.value === 'ar' ? ar : en);
/** A BiText is a plain string or `{ ar, en }`. */
export const isBi = (v) => typeof v === 'string' || (!!v && typeof v === 'object' && 'ar' in v && 'en' in v);
export const bi = (v, l = lang.value) => (v == null ? '' : typeof v === 'string' ? v : l === 'ar' ? v.ar : v.en);
/** Name of a record that has nameAr / nameEn. */
export const nm = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || '' : '');

/** `const { t, lang, dir, toggle } = useLang()` — same helpers, handy inside components. */
export const useLang = () => ({ lang, dir, isAr, t, bi, nm, setLang, toggle: toggleLang });

// ---------- formatting ----------
/** en-US grouping, 0 fraction digits by default. */
export function fmtNum(n, digits = 0) {
  const v = typeof n === 'string' ? Number(n) : n;
  if (v == null || Number.isNaN(v)) return '—';
  return v.toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}
/** Money: 2 decimals when non-integer, otherwise 0. */
export const fmtMoney = (n) => { const v = Number(n); return Number.isInteger(v) ? fmtNum(v, 0) : fmtNum(v, 2); };
/** Numeric value of API decimals (they arrive as strings). */
export const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };

const p2 = (n) => String(n).padStart(2, '0');
export function toDate(d) {
  if (d == null || d === '') return null;
  if (d instanceof Date) return Number.isNaN(d.getTime()) ? null : d;
  if (typeof d === 'string' && /^\d{4}\/\d{2}\/\d{2}$/.test(d)) { const p = d.split('/'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  const x = new Date(d);
  return Number.isNaN(x.getTime()) ? null : x;
}
/** `YYYY/MM/DD HH:mm` (pass `{ time: false }` for the date only). */
export function fmtDate(d, opts = {}) {
  const x = toDate(d);
  if (!x) return '—';
  const date = `${x.getFullYear()}/${p2(x.getMonth() + 1)}/${p2(x.getDate())}`;
  return opts.time === false ? date : `${date} ${p2(x.getHours())}:${p2(x.getMinutes())}`;
}
export const fmtDateOnly = (d) => fmtDate(d, { time: false });
export const fmtTime = (d) => { const x = toDate(d); return x ? `${p2(x.getHours())}:${p2(x.getMinutes())}` : '—'; };
/** Whole days from today (local midnight) to `date`; negative = past; 9999 when there is no date. */
export function daysTo(d) {
  const x = toDate(d);
  if (!x) return 9999;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const day = new Date(x); day.setHours(0, 0, 0, 0);
  return Math.round((day.getTime() - today.getTime()) / 86400000);
}
/** "الآن", "قبل 5 د", "قبل 3 س" … */
export function fmtAgo(d, l = lang.value) {
  const x = toDate(d); if (!x) return '—';
  const s = Math.max(0, Math.round((Date.now() - x.getTime()) / 1000));
  const ar = l === 'ar';
  if (s < 60) return ar ? 'الآن' : 'now';
  const m = Math.round(s / 60); if (m < 60) return ar ? `قبل ${m} د` : `${m}m ago`;
  const h = Math.round(m / 60); if (h < 24) return ar ? `قبل ${h} س` : `${h}h ago`;
  const dd = Math.round(h / 24); if (dd < 30) return ar ? `قبل ${dd} ي` : `${dd}d ago`;
  return fmtDate(x, { time: false });
}

/** Common labels. `lbl('save')` */
export const L = {
  sar: ['ر.س', 'SAR'], soon: ['قريبًا', 'Soon'], noResults: ['لا نتائج مطابقة.', 'No matching results.'], save: ['حفظ', 'Save'], cancel: ['إلغاء', 'Cancel'],
  confirm: ['تأكيد', 'Confirm'], view: ['عرض', 'View'], edit: ['تعديل', 'Edit'], reject: ['رفض', 'Reject'], clear: ['إزالة الفلتر', 'Clear'], exportExcel: ['تصدير Excel', 'Export Excel'],
  download: ['تنزيل', 'Download'], preview: ['معاينة', 'Preview'], all: ['الكل', 'All'], loading: ['جارٍ التحميل…', 'Loading…'], close: ['إغلاق', 'Close'], search: ['بحث', 'Search'],
  forbidden: ['صلاحية غير كافية', 'Insufficient permission'], allWarehouses: ['كل المستودعات', 'All warehouses'],
};
export const lbl = (k) => (lang.value === 'ar' ? L[k][0] : L[k][1]);
