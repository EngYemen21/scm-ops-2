// «البنوك والمطابقة» — what the tabs of the section share: label maps (with chip colours), exact money in hundredths,
// the query-string state of the page and the lookups every form and filter starts from.
// API (routes/api/banking.php): GET /banking/{lookups,accounts,reconciliation,journals,payment-terms,statements,
// statements/{id},lines,lines/{id},profiles,ledger-items,lines/{id}/suggestions,matches,matches/{id},rules,rules/{id}} ·
// bankstatement.manage: POST /banking/statements · PATCH statements/{id} · POST statements/{id}/{void,lines,import} ·
// PATCH|DELETE lines/{id} · POST import/preview · POST|PATCH profiles · POST journals/{id}/{link,unlink,adopt} ·
// bank.reconcile: POST matches · POST matches/{id}/undo · POST statements/{id}/{auto-match,reconcile,reopen} · POST|PATCH rules.
// Money arrives as decimal strings ("1234.50", negative = money out). Figures the server computed are shown as they come;
// the only sum made here (the selection of the reconcile workspace) is made in integer hundredths, never in floats.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { bi, fmtDateOnly, fmtMoney, lang, t } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

/** `map[key]` for a key that comes from the server: only the map's own entries count. */
const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- colours and labels
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#e4f8fb' }, neutral: { fg: '#55506a', bg: '#F1EFF6' },
};
/** A label of a map, or the raw key when the server sends one the client does not know yet. */
export const labelOf = (map, k) => own(map, k) || { ar: k || '—', en: k || '—', ...TONE.neutral };

/** `statement.status` — open → reconciled → open (reopened) · open → void · history never moves. */
export const STATEMENT_STATUS = {
  open: { ar: 'مفتوح', en: 'Open', ...TONE.blue },
  reconciled: { ar: 'مطابَق ومقفل', en: 'Reconciled', ...TONE.ok },
  void: { ar: 'ملغى', en: 'Void', ...TONE.neutral },
  history: { ar: 'سجل تاريخي', en: 'History', ...TONE.violet },
};
export const OWNED_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const OWNED_HELP = {
  ar: 'هذا السجل يُدار من أودو ما دام القسم يتبع أودو: يُقرأ هنا ويُعدَّل هناك. ما يضيفه OPS (الربط، الملاحظات، المطابقة) يبقى هنا.',
  en: 'This record is managed in Odoo while the section follows Odoo: it is read here and changed there. What OPS adds (links, notes, matching) stays here.',
};
export const BANK_KIND = { bank: { ar: 'بنك', en: 'Bank', ...TONE.blue }, cash: { ar: 'صندوق', en: 'Cash box', ...TONE.teal } };

/** `match.status` / `match.kind` / `match.origin`. */
export const MATCH_STATUS = { active: { ar: 'قائمة', en: 'Active', ...TONE.ok }, undone: { ar: 'مفكوكة', en: 'Undone', ...TONE.neutral } };
export const MATCH_KIND = {
  match: { ar: 'مطابقة', en: 'Match', ...TONE.blue },
  contra: { ar: 'مقاصة', en: 'Contra', ...TONE.teal },
  system: { ar: 'من النظام', en: 'System', ...TONE.neutral },
};
export const MATCH_ORIGIN = {
  manual: { ar: 'يدوية', en: 'Manual', ...TONE.neutral }, suggestion: { ar: 'من اقتراح', en: 'From a suggestion', ...TONE.violet },
  auto: { ar: 'تلقائية مؤكدة', en: 'Automatic (certain)', ...TONE.teal }, system: { ar: 'النظام', en: 'System', ...TONE.neutral },
};

/** `entry.kind` of a ledger entry (BookItems). */
export const ENTRY_KIND = {
  receipt: { ar: 'سند قبض', en: 'Receipt', ...TONE.ok }, payment: { ar: 'سند صرف', en: 'Payment', ...TONE.warn },
  expense: { ar: 'مصروف', en: 'Expense', ...TONE.bad }, journal: { ar: 'قيد يومية', en: 'Journal', ...TONE.blue },
  reversal: { ar: 'قيد عكسي', en: 'Reversal', ...TONE.neutral }, writeoff: { ar: 'قيد تسوية', en: 'Write-off', ...TONE.violet },
};
/** `suggestion.kind` (SuggestionService). */
export const SUGGESTION_KIND = {
  exact: { ar: 'مبلغ مطابق', en: 'Exact amount', ...TONE.ok }, many: { ar: 'عدة قيود', en: 'Several entries', ...TONE.blue },
  grouped: { ar: 'عدة سطور', en: 'Several lines', ...TONE.teal }, writeoff: { ar: 'مع تسوية فرق', en: 'With a write-off', ...TONE.warn },
  rule: { ar: 'بقاعدة تسوية', en: 'By a rule', ...TONE.violet }, opening: { ar: 'الرصيد الافتتاحي', en: 'Opening balance', ...TONE.neutral },
};
/** `rule.nature` — which difference a rule may absorb: bank more than books (in), less (out), or both. */
export const NATURE = {
  in: { ar: 'زيادة في البنك (إيراد)', en: 'Bank more than books (income)', ...TONE.ok },
  out: { ar: 'نقص في البنك (مصروف)', en: 'Bank less than books (cost)', ...TONE.bad },
  both: { ar: 'الاتجاهان', en: 'Both directions', ...TONE.blue },
};
export const RULE_KIND = { writeoff: { ar: 'قاعدة تسوية', en: 'Write-off rule', ...TONE.blue }, matching: { ar: 'نموذج مطابقة', en: 'Matching model', ...TONE.neutral } };
/** The reconciliation statement's verdict. */
export const REC_STATUS = {
  reconciled: { ar: 'مطابَق', en: 'Reconciled', ...TONE.ok }, open_items: { ar: 'بنود مفتوحة', en: 'Open items', ...TONE.warn },
  difference: { ar: 'فرق', en: 'Difference', ...TONE.bad }, no_statement: { ar: 'لا كشف', en: 'No statement', ...TONE.neutral },
};
/** A row of an import report. */
export const IMPORT_ROW = { taken: { ar: 'أُخذ', en: 'Taken', ...TONE.ok }, duplicate: { ar: 'مكرر', en: 'Duplicate', ...TONE.warn }, refused: { ar: 'مرفوض', en: 'Refused', ...TONE.bad } };
export const JOURNAL_KIND = { bank: { ar: 'بنك', en: 'Bank', ...TONE.blue }, cash: { ar: 'نقدية', en: 'Cash', ...TONE.teal } };
export const LINKED_BY = {
  iban: { ar: 'بالآيبان نفسه', en: 'Same IBAN' }, number: { ar: 'برقم الحساب نفسه', en: 'Same account number' }, name: { ar: 'بالاسم نفسه', en: 'Same name' },
  manual: { ar: 'ربط يدوي', en: 'Linked by a person' }, adopted: { ar: 'أُنشئ له حساب', en: 'Bank account created for it' },
};

/** The fields a column of a bank's file may be mapped to (lookups.importFields). */
export const IMPORT_FIELD = {
  date: { ar: 'تاريخ العملية', en: 'Transaction date' }, valueDate: { ar: 'تاريخ القيمة', en: 'Value date' }, description: { ar: 'الوصف', en: 'Description' },
  reference: { ar: 'المرجع', en: 'Reference' }, amount: { ar: 'المبلغ', en: 'Amount' }, debit: { ar: 'مدين (سحب)', en: 'Debit (out)' }, credit: { ar: 'دائن (إيداع)', en: 'Credit (in)' },
  type: { ar: 'نوع الحركة (مدين / دائن)', en: 'Movement type (Dr / Cr)' }, balance: { ar: 'الرصيد بعد الحركة', en: 'Running balance' },
  partnerName: { ar: 'اسم الطرف', en: 'Counterparty name' }, partnerAccount: { ar: 'حساب الطرف', en: 'Counterparty account' },
};
export const AMOUNT_MODE = {
  signed: { ar: 'عمود مبلغ واحد بإشارة (+ وارد / − صادر)', en: 'One signed amount column (+ in / − out)' },
  debit_credit: { ar: 'عمودان: مدين ودائن', en: 'Two columns: debit and credit' },
  amount_type: { ar: 'عمود مبلغ وعمود نوع (مدين / دائن)', en: 'An amount column and a type column (Dr / Cr)' },
};
/** The fields a mapping needs, by amount mode. */
export const requiredFields = (mode) => (mode === 'debit_credit' ? ['date', 'debit', 'credit'] : mode === 'amount_type' ? ['date', 'amount', 'type'] : ['date', 'amount']);
export const DATE_ORDER = { dmy: { ar: 'يوم / شهر / سنة', en: 'Day / month / year' }, mdy: { ar: 'شهر / يوم / سنة', en: 'Month / day / year' }, ymd: { ar: 'سنة / شهر / يوم', en: 'Year / month / day' } };
export const ROW_ORDER = { auto: { ar: 'تلقائي', en: 'Automatic' }, asc: { ar: 'الأقدم أولًا', en: 'Oldest first' }, desc: { ar: 'الأحدث أولًا', en: 'Newest first' } };
export const DELIMITERS = [[',', ', (comma)'], [';', '; (semicolon)'], ['|', '| (pipe)'], ['tab', 'Tab']];
export const ENCODINGS = [['utf-8', 'UTF-8'], ['utf-16', 'UTF-16'], ['windows-1256', 'Windows-1256 (Arabic)'], ['windows-1252', 'Windows-1252']];
export const MAX_FILE_BYTES = 5 * 1024 * 1024;

/** Options of a label map for SelectInput / PillChoice. */
export const optionsOf = (map, keys = Object.keys(map)) => keys.map((k) => ({ v: k, l: { ar: labelOf(map, k).ar, en: labelOf(map, k).en } }));

// ---------------------------------------------------------------- money in hundredths
/** "1234.5" / "-0.75" / 12 → integer hundredths (exact: the string is split, never multiplied as a float). */
export function toCents(v) {
  if (v == null || v === '') return 0;
  const m = String(v).trim().replace(/,/g, '').match(/^([+-])?(\d*)(?:\.(\d*))?$/);
  if (!m) return 0;
  const whole = Number(m[2] || '0');
  const frac = Number(((m[3] || '') + '00').slice(0, 2));
  const c = whole * 100 + frac;
  return m[1] === '-' ? -c : c;
}
/** Integer hundredths → "1234.50" (the API's own form). */
export function centsStr(c) {
  const n = Math.trunc(Number(c) || 0);
  const a = Math.abs(n);
  return `${n < 0 ? '-' : ''}${Math.floor(a / 100)}.${String(a % 100).padStart(2, '0')}`;
}
export const sumCents = (values) => values.reduce((s, v) => s + toCents(v), 0);
/** A typed amount the API accepts: at most two decimals, optional sign. */
export const isMoney = (v) => /^[+-]?\d+(\.\d{1,2})?$/.test(String(v ?? '').trim());
/** An amount to show; «—» for nothing. */
export const money = (v) => (v == null || v === '' ? '—' : fmtMoney(v));
/** Colour class of a signed amount: money in green, money out red. */
export const signClass = (v) => (toCents(v) > 0 ? 'text-ok' : toCents(v) < 0 ? 'text-bad' : 'text-sec');

// ---------------------------------------------------------------- days and names
/** A day the API gives as `YYYY-MM-DD`, shown as the same calendar day. */
export const fmtDay = (d) => (d ? fmtDateOnly(String(d).slice(0, 10).replace(/-/g, '/')) : '—');
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
export const nameOf = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || o.code || '—' : '—');
/** "BNK-01 · البنك الأهلي" */
export const bankLabel = (b) => (b ? `${b.code} · ${nameOf(b)}` : '—');
export const reasonOf = (r) => (r ? t(r.ar || r.en || '', r.en || r.ar || '') : '');

/** One reason of a suggestion (SuggestionService::why and friends), in the language of the screen. */
export function suggestionReason(r) {
  switch (r?.key) {
    case 'amount': return t('المبلغ نفسه', 'Same amount');
    case 'reference': return t(`المرجع ${r.value} مذكور في السطر`, `Reference ${r.value} is in the line`);
    case 'party': return r.value || r.valueEn ? t(`الطرف «${r.value || r.valueEn}» مذكور في السطر`, `Party “${r.valueEn || r.value}” is named in the line`) : t('الطرف مذكور في السطر', 'The party is named in the line');
    case 'date': return r.days === 0 ? t('التاريخ نفسه', 'Same day') : t(`فرق ${r.days} يوم${r.outside ? ' — خارج النافذة، وُجد بالمرجع' : ''}`, `${r.days} day(s) apart${r.outside ? ' — outside the window, found by its reference' : ''}`);
    case 'sum': return t(`مجموع ${r.value} يساوي المبلغ`, `${r.value} items add up to the amount`);
    case 'sameParty': return t(`كلها للطرف نفسه${r.value ? `: ${r.value}` : ''}`, `All of one party${r.valueEn || r.value ? `: ${r.valueEn || r.value}` : ''}`);
    case 'rule': return t(`قاعدة «${r.value}» تسوّي فرق ${fmtMoney(r.difference)}`, `Rule “${r.valueEn || r.value}” writes off ${fmtMoney(r.difference)}`);
    case 'keyword': return t(`الكلمة «${r.value}» في نص السطر`, `The word “${r.value}” is in the line`);
    case 'unique': return t('المبلغ الوحيد في الجهتين ضمن النافذة', 'The only such amount on both sides within the window');
    case 'allBefore': return t('كل القيود غير المطابقة قبل بداية الكشف', 'Every unmatched entry booked before the statement starts');
    default: return r?.key || '';
  }
}

/** A File → `data:…;base64,…` (what the import endpoints take). */
export function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(reader.error || new Error('read failed'));
    reader.readAsDataURL(file);
  });
}

// ---------------------------------------------------------------- query-string state
export const TABS = ['accounts', 'statements', 'reconcile', 'matches', 'recon', 'rules', 'journals', 'terms'];

/**
 * Everything the page shows is in the query string of /banking, so every tab, filter and open drawer is a link:
 *   tab=accounts|statements|reconcile|matches|recon|rules|journals|terms · bank=<code> (kept across tabs)
 *   statements: status · origin · from · to · q · sort · order · page
 *   reconcile:  stmt=<statement id> · ls/lq/lp (lines: sign, search, page) · ek/es/ef/et/ea/eq/ep (entries: kind, sign, from, to, amount, search, page)
 *   matches:    status · kind · writeoff · from · to · q · sort · order · page
 *   recon:      date        rules: active · origin · kind · nature · q · page        journals: linked · kind · active · q · page
 *   st=<statement id> opens a statement · match=<id> opens a match · line=<line id> opens the proposals of a line — on any tab
 */
export function useBankingNav() {
  const qs = useQueryState();
  const { get } = qs;
  const tab = computed(() => (TABS.includes(get('tab')) ? get('tab') : 'accounts'));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  const bank = computed(() => get('bank') || '');

  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  /** Opens a tab; filters of the previous one are dropped, the bank and the open drawers stay. */
  const go = (to, params = {}) => {
    const keep = Object.fromEntries(['bank', 'st', 'match', 'line'].map((k) => [k, get(k)]).filter(([, v]) => v));
    return qs.replace({ ...keep, ...(to && to !== 'accounts' ? { tab: to } : {}), ...Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '')) });
  };
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  return {
    get, tab, page, bank, patch, go, setFilter, setPage,
    openStatement: (id) => patch({ st: id }), closeStatement: () => patch({ st: null }),
    openMatch: (id) => patch({ match: id }), closeMatch: () => patch({ match: null }),
    openLine: (id) => patch({ line: id }), closeLine: () => patch({ line: null }),
  };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL 350 ms after the last key, at once on
 * Enter; a deep link or a filter that clears the key refills the box. `pageKey` is the page key the search resets.
 */
export function useSearchParam(nav, key = 'q', pageKey = 'page') {
  let applied = nav.get(key);
  const text = ref(applied);
  let timer = null;
  function apply() {
    clearTimeout(timer);
    const v = String(text.value ?? '').trim();
    if (v === applied) return;
    applied = v;
    nav.patch({ [key]: v, [pageKey]: null });
  }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  watch(() => nav.get(key), (v) => { if (v !== applied) { applied = v; text.value = v; } });
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, type, apply };
}

// ---------------------------------------------------------------- lookups
/** GET /banking/lookups, shared by every tab (one request, kept fresh for half a minute) and shaped for the pickers. */
export function useBankingLookups() {
  const q = useGet('/banking/lookups', undefined, { staleTime: 30_000 });
  const data = computed(() => q.data.value || null);
  const list = (key) => computed(() => data.value?.[key] || []);
  const banks = list('banks');
  const rules = list('rules');
  const accounts = list('accounts');
  const findBank = (key) => (key ? banks.value.find((b) => b.code === key || b.id === key) || null : null);
  return {
    query: q, data, banks, rules, accounts, findBank,
    profiles: list('profiles'), importFields: computed(() => data.value?.importFields || Object.keys(IMPORT_FIELD)),
    amountModes: computed(() => data.value?.amountModes || Object.keys(AMOUNT_MODE)),
    /** The section follows Odoo: what came from Odoo is read-only here. */
    follows: computed(() => !!data.value?.follows),
    seesNumbers: computed(() => !!data.value?.seesNumbers),
    window: computed(() => data.value?.window || { default: 7, max: 60 }),
    can: computed(() => data.value?.can || {}),
    bankOptions: computed(() => banks.value.map((b) => ({ v: b.code, l: `${b.code} · ${nameOf(b)}${b.active ? '' : ` (${bi({ ar: 'موقوف', en: 'inactive' })})`}` }))),
    accountOptions: computed(() => accounts.value.map((a) => ({ v: a.code, l: `${a.code} · ${nameOf(a)}` }))),
  };
}
