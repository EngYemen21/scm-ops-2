// «العمولات» / Sales commissions — what the parts of the page share:
//   · the state of the page, all of it in the query string of /commissions (`useNav` lists the keys);
//   · the lookups (who may do what, people, categories, plans) — `provideCommissions` once in the page, `useCommissions` in every part;
//   · labels and the way a value is written out: money, a month, a person, a rule, how a line reached its figure.
// API, all under /api/commissions (routes/api/commissions.php):
//   page.commissions     lookups · summary · statements · statements/{id} · statements/{id}/lines · lines   (own statements only)
//   commission.manage    plans (write) · assignments · unassigned · calculate · adjustments · exclude / include · submit
//   commission.approve   approve · payout · void-payout · payout-options        (return: either of the two)
// Shapes (camelCase; money arrives as a decimal string, a day as YYYY-MM-DD, a moment as ISO-8601):
//   Person     { key, id?, username?, nameAr, nameEn, external }          external = a name kept from Odoo, no OPS user
//   Statement  { id, number, reference?, period: 'YYYY-MM', dateFrom, dateTo, status: draft|submitted|approved|paid, earnerType,
//                beneficiary: Person, plan?: { id, code, nameAr, nameEn }, linesCount, baseTotal, commissionTotal, adjustmentsTotal,
//                total, currency, sourceSystem?, externalRef?, owned, mine,
//                detail only: terms, adjustments: Line[], breakdown[], history[], actions: { <action>: { allowed, code?, reasonAr?, reasonEn? } },
//                payout?: { date, reference, bankAccount, account, expense?: { id, number } }, returned?: { at, by, reason }, processedAt? }
//   Line       { id, kind: invoice|credit_note|reversal|adjustment, docNumber, docDate, earnedOn, sourceType, sourceId, customer?, salesperson?: Person,
//                salespersonVia, invoiceTotal, baseAmount, rate, commission, ruleLabel?, explain?, excluded, excludeReason?, reason?, voided?, reversesId? }
//   Plan       { id, code, nameAr, nameEn, earnerType: salesperson|manager, beneficiary?: Person, everyone, members: Person[], earnOn: invoiced|collected,
//                base: net|gross|margin, netOfReturns, validFrom, validTo?, status: draft|active|archived, notes?, rules: Rule[], statements, locked, owned }
//   Rule       { id, sequence, scope: all|category|customer, scopeIds[], scopeNames[], rateType: flat|tiered, rate?, tierMode?: whole|progressive, tiers?: [{ upTo, rate }], label? }
import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { fmtDate, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';

export const API = '/commissions';
export const PAGE_SIZE = 25;
export const LINES_PAGE_SIZE = 50;

// ---------------------------------------------------------------- labels
const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, neutral: { fg: '#55506a', bg: '#F1EFF6' }, teal: { fg: '#0d7f93', bg: '#d9f4f9' },
};
export const STATEMENT_STATUS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  submitted: { ar: 'بانتظار الاعتماد', en: 'Awaiting approval', ...TONE.warn },
  approved: { ar: 'معتمد — بانتظار الصرف', en: 'Approved — to be paid', ...TONE.blue },
  paid: { ar: 'مصروف ✓', en: 'Paid ✓', ...TONE.ok },
};
export const STATUS_COLOR = { draft: '#7d7990', submitted: '#b26a16', approved: '#3C79F5', paid: '#1d7a3e' };
export const PLAN_STATUS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral }, active: { ar: 'فعّالة', en: 'Active', ...TONE.ok }, archived: { ar: 'مؤرشفة', en: 'Archived', fg: '#7d7990', bg: '#F1EFF6' },
};
export const EARNER = {
  salesperson: { ar: 'مندوب — على مبيعاته', en: 'Salesperson — own sales' }, manager: { ar: 'مدير — على مبيعات فريقه', en: 'Manager — team sales' },
};
export const EARN_ON = {
  invoiced: { ar: 'عند صدور الفاتورة', en: 'When the invoice is issued' }, collected: { ar: 'عند تحصيل الفاتورة كاملة', en: 'When the invoice is fully collected' },
};
export const BASE = {
  net: { ar: 'الصافي قبل الضريبة', en: 'Net before tax' }, gross: { ar: 'الإجمالي شامل الضريبة', en: 'Total with tax' }, margin: { ar: 'هامش الربح', en: 'Profit margin' },
};
export const LINE_KIND = {
  invoice: { ar: 'فاتورة', en: 'Invoice', ...TONE.teal }, credit_note: { ar: 'إشعار دائن', en: 'Credit note', ...TONE.bad },
  reversal: { ar: 'عكس', en: 'Reversal', ...TONE.warn }, adjustment: { ar: 'تسوية', en: 'Adjustment', ...TONE.violet },
};
export const SCOPE = { all: { ar: 'كل المبيعات', en: 'All sales' }, category: { ar: 'فئات منتجات', en: 'Product categories' }, customer: { ar: 'عملاء محددون', en: 'Named customers' } };
export const TIER_MODE = {
  whole: { ar: 'الشريحة المحقَّقة تُطبَّق على كامل المبلغ', en: 'The tier reached pays on the whole amount' },
  progressive: { ar: 'كل جزء من المبلغ بنسبة شريحته', en: 'Each slice at the rate of its own tier' },
};
export const VIA = {
  rep: { ar: 'مندوب الطلب في منصة المبيعات', en: 'Representative named on the order' }, customer: { ar: 'مندوب العميل', en: 'The customer’s salesperson' },
  creator: { ar: 'مُدخل الطلب', en: 'Entered the order' }, odoo: { ar: 'مندوب الفاتورة في أودو', en: 'Invoice salesperson in Odoo' },
};
export const REVERSAL_REASON = {
  cancelled: { ar: 'أُلغيت الفاتورة بعد إقفال الكشف', en: 'The invoice was cancelled after the statement was closed' },
  unpaid: { ar: 'لم تعد الفاتورة مسدَّدة بالكامل (أُلغي سند القبض)', en: 'The invoice is no longer fully paid (its receipt was voided)' },
  invoice_reversed: { ar: 'عُكست عمولة الفاتورة التي يخصها الإشعار', en: 'The commission of its invoice was taken back' },
  missing: { ar: 'المستند لم يعد موجودًا', en: 'The document no longer exists' },
};
export const ASSIGN_KIND = { customer: { ar: 'عميل ← مندوب', en: 'Customer → salesperson', ...TONE.blue }, rep: { ar: 'اسم مندوب المنصة ← مستخدم', en: 'Platform rep name → user', ...TONE.violet } };
export const OUTCOME = {
  created: { ar: 'كشف جديد', en: 'New statement', ...TONE.ok }, updated: { ar: 'حُدّث', en: 'Updated', ...TONE.blue },
  unchanged: { ar: 'بلا تغيير', en: 'Unchanged', ...TONE.neutral }, frozen: { ar: 'مقفل — لم يُمسّ', en: 'Closed — untouched', ...TONE.warn },
  removed: { ar: 'سُحب — لا مستحقات', en: 'Withdrawn — nothing earned', ...TONE.bad },
};
export const ODOO_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const SELECT_PLACEHOLDER = { ar: '— اختر —', en: '— select —' };

// ---------------------------------------------------------------- writing values out
const MONTHS_AR = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const isPeriod = (v) => typeof v === 'string' && /^\d{4}-(0[1-9]|1[0-2])$/.test(v);
/** `2026-11` → «نوفمبر 2026» / "Nov 2026". */
export function periodLabel(period) {
  if (!isPeriod(period)) return period || '—';
  const [y, m] = period.split('-');
  return `${(lang.value === 'ar' ? MONTHS_AR : MONTHS_EN)[Number(m) - 1]} ${y}`;
}
/** The current month as `YYYY-MM` (the browser's clock; the server refuses a month that has not started). */
export const thisPeriod = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; };
/** The last `count` months, latest first, as select options — plus `extra` periods (months that hold statements, a deep link). */
export function periodOptions(count = 24, extra = []) {
  const out = new Set();
  const d = new Date();
  d.setDate(1);
  for (let i = 0; i < count; i++) { out.add(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`); d.setMonth(d.getMonth() - 1); }
  for (const p of extra) if (isPeriod(p)) out.add(p);
  return [...out].sort().reverse().map((p) => [p, periodLabel(p)]);
}
/** Money with two decimals (`digits` 4 shows a line's unrounded commission). */
export const money = (v, digits = 2) => (v == null || v === '' || Number.isNaN(Number(v)) ? '—' : fmtNum(Number(v), digits));
/** A line's commission: two decimals, or four when the figure has more (Odoo's unrounded shares). */
export function commissionText(v) {
  const n = Number(v);
  if (v == null || Number.isNaN(n)) return '—';
  return Math.abs(n * 100 - Math.round(n * 100)) > 0.0001 ? fmtNum(n, 4) : fmtNum(n, 2);
}
export const pct = (v) => (v == null || v === '' ? '—' : `${Number(Number(v).toFixed(4))}%`);
export const sar = () => t('ر.س', 'SAR');
export const dayText = (v) => (v ? fmtDate(String(v).replace(/-/g, '/'), { time: false }) : '—');
export const personName = (p) => (p ? (lang.value === 'ar' ? p.nameAr || p.nameEn : p.nameEn || p.nameAr) || '—' : '—');
export const recordName = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || o.code || '—' : '—');
const say = (map, key) => { const l = map[key]; return l ? (lang.value === 'ar' ? l.ar : l.en) : key || '—'; };
export const label = say;

/** The rate of a rule in words: `7%`, or its tiers. */
export function rateText(rule) {
  if (!rule) return '—';
  if (rule.rateType !== 'tiered') return pct(rule.rate);
  const tiers = rule.tiers || [];
  return tiers.map((tier, i) => (tier.upTo == null
    ? `${i === 0 ? '' : t('ما فوق ذلك', 'above that')} ${pct(tier.rate)}`.trim()
    : `${t('حتى', 'up to')} ${money(tier.upTo, 0)}: ${pct(tier.rate)}`)).join(' · ');
}
/** What a rule covers, in words. */
export function scopeText(rule) {
  if (!rule || rule.scope === 'all') return say(SCOPE, 'all');
  const names = (rule.scopeNames || []).map(recordName);
  const shown = names.length ? names.join(lang.value === 'ar' ? '، ' : ', ') : `${(rule.scopeIds || []).length}`;
  return `${rule.scope === 'category' ? t('فئات', 'Categories') : t('عملاء', 'Customers')}: ${shown}`;
}
/** The terms of a plan (or the terms a statement kept) as one sentence. */
export function termsText(x) {
  if (!x) return '';
  const rules = x.rules || [];
  const rate = rules.length === 1 ? rateText(rules[0]) : t(`${rules.length} قواعد`, `${rules.length} rules`);
  const parts = [`${rate} ${t('من', 'of')} ${say(BASE, x.base)}`, say(EARN_ON, x.earnOn)];
  if (x.netOfReturns) parts.push(t('صافية من المرتجعات', 'net of returns'));
  return parts.join(' · ');
}
/** How a line reached its figure: rows of `{ k, v }` a person can redo the sum from. */
export function explainRows(line) {
  const e = line.explain || {};
  const rows = [];
  const push = (ar, en, v) => rows.push({ k: t(ar, en), v });
  if (line.kind === 'adjustment') { push('السبب', 'Reason', line.reason || '—'); return rows; }
  if (e.reversal) {
    push('سبب العكس', 'Why it was taken back', say(REVERSAL_REASON, e.reversal));
    if (e.of) push('الكشف الذي حمل السطر الأصلي', 'Statement of the original line', e.of);
  }
  if (e.net != null) push('الصافي قبل الضريبة', 'Net before tax', money(e.net));
  if (e.base === 'gross' && e.tax != null) push('الضريبة', 'Tax', money(e.tax));
  if (e.base === 'margin' && e.cogs != null) push('تكلفة البضاعة', 'Cost of goods', money(e.cogs));
  if (e.share != null) push('حصة القاعدة من الفاتورة', 'Share of the invoice under this rule', pct(Number(e.share) * 100));
  push('الأساس', 'Base', e.base ? `${money(line.baseAmount)} (${say(BASE, e.base)})` : money(line.baseAmount));
  if (e.tier) {
    push('الشريحة', 'Tier', e.tier.mode === 'whole'
      ? t(`أساس الشهر ${money(e.tier.total)} يقع في شريحة ${money(e.tier.from, 0)} – ${e.tier.upTo == null ? '∞' : money(e.tier.upTo, 0)}`, `the month’s base ${money(e.tier.total)} falls in the tier ${money(e.tier.from, 0)} – ${e.tier.upTo == null ? '∞' : money(e.tier.upTo, 0)}`)
      : t(`هذا السطر يغطي من ${money(e.tier.from)} إلى ${money(e.tier.to)} من أساس الشهر`, `this line covers ${money(e.tier.from)} to ${money(e.tier.to)} of the month’s base`));
  }
  push('النسبة', 'Rate', line.excluded ? t('مستبعدة — لا عمولة', 'excluded — no commission') : pct(line.rate));
  push('العمولة', 'Commission', commissionText(line.commission));
  if (e.earnOn) push('تُستحق', 'Earned', `${say(EARN_ON, e.earnOn)}${line.earnedOn ? ` — ${dayText(line.earnedOn)}` : ''}`);
  if (e.late) push('ملاحظة', 'Note', t('وصلت بعد إقفال كشف شهرها فدخلت هذا الكشف', 'arrived after its month was closed, so it is in this statement'));
  if (line.salespersonVia) push('مندوب الفاتورة عُرف من', 'Salesperson known from', say(VIA, line.salespersonVia));
  if (line.excluded && line.excludeReason) push('سبب الاستبعاد', 'Why excluded', line.excludeReason);
  return rows;
}
/** Where the document of a line can be opened: an OPS invoice in finance; an Odoo invoice has no page of its own here. */
export function documentLink(line) {
  if (line.sourceType === 'CustomerInvoice' && line.docNumber) return `/finance?tab=invoices&invoice=${encodeURIComponent(line.docNumber)}`;
  return null;
}

// ---------------------------------------------------------------- the state of the page lives in the query string
/**
 *   tab=statements|lines|summary|plans|assignments      the tab (switching drops the filters of the tab that is left)
 *   q · status · from · to · who · source · ofplan · kind · type · period · sort · order · page      filters, sort and page of the tab on screen
 *   statement=<number>   the statement drawer, from any tab · view=lines|adjustments|history · lq · lkind · lpage   its lines
 *   plan=<code>          the plan drawer
 */
export function useNav() {
  const route = useRoute();
  const router = useRouter();
  const raw = (k) => { const v = route.query[k]; return Array.isArray(v) ? v[0] : v; };
  const get = (k) => raw(k) || '';
  const replace = (query) => router.replace({ path: route.path, query });
  /** Sets (or, with an empty value, removes) keys; a change of anything but the page goes back to page 1. */
  function patch(values, keepPage = false) {
    const next = { ...route.query };
    for (const [k, v] of Object.entries(values)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    if (!keepPage && !('page' in values)) delete next.page;
    return replace(next);
  }
  const set = (k, v) => patch({ [k]: v });
  const page = computed(() => { const n = Number(raw('page')); return Number.isInteger(n) && n > 0 ? n : 1; });
  const sort = computed(() => (get('sort') ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : 'desc' } : null));
  const setSort = (s) => patch({ sort: s ? s.key : null, order: s ? s.order : null });
  const setPage = (p) => patch({ page: p > 1 ? p : null }, true);
  /** Another tab: its own filters start clean (`more` = the filters the link carries into it). */
  const setTab = (k, more = {}) => replace({ ...(k && k !== 'statements' ? { tab: k } : {}), ...more });
  const openStatement = (number, more = {}) => patch({ statement: number, view: null, lq: null, lkind: null, lpage: null, ...more }, true);
  const closeStatement = () => patch({ statement: null, view: null, lq: null, lkind: null, lpage: null }, true);
  const openPlan = (code) => patch({ plan: code }, true);
  const closePlan = () => patch({ plan: null }, true);
  return { route, get, raw, patch, set, page, sort, setSort, setPage, setTab, openStatement, closeStatement, openPlan, closePlan };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a cleared filter refills the box.
 *   const { text, type, apply } = useSearchBox(nav);        <TextInput :model-value="text" @update:model-value="type" @enter="apply" />
 */
export function useSearchBox(nav, key = 'q') {
  let applied = nav.get(key);
  const text = ref(applied);
  let timer = null;
  function apply() {
    clearTimeout(timer);
    const v = String(text.value ?? '').trim();
    if (v === applied) return;
    applied = v;
    nav.patch({ [key]: v, ...(key === 'lq' ? { lpage: null } : {}) }, key === 'lq');
  }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  watch(() => nav.get(key), (v) => { if (v !== applied) { applied = v; text.value = v; } });
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, type, apply };
}

// ---------------------------------------------------------------- what every part of the page shares
const KEY = Symbol('commissions');
export function provideCommissions() {
  const auth = useAuth();
  const nav = useNav();
  const lookupsQ = useGet(`${API}/lookups`, undefined, { staleTime: 60_000 });
  const lookups = computed(() => lookupsQ.data.value || { following: false, currentPeriod: thisPeriod(), scope: 'mine', can: { manage: false, approve: false }, users: [], categories: [], plans: [], periods: [] });
  const can = computed(() => ({ manage: auth.can('commission.manage'), approve: auth.can('commission.approve') }));
  /** Plans, calculation and approval see every statement; anybody else sees his own. */
  const seesAll = computed(() => can.value.manage || can.value.approve);
  const userOptions = computed(() => lookups.value.users.map((u) => ({ v: u.id, l: `${personName(u)} · ${u.username}` })));
  const periods = computed(() => periodOptions(24, lookups.value.periods));
  const ctx = { nav, lookupsQ, lookups, can, seesAll, userOptions, periods, me: computed(() => auth.user) };
  provide(KEY, ctx);
  return ctx;
}
export const useCommissions = () => inject(KEY);
