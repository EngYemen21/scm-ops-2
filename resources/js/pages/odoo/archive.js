// «أرشيف أودو» / "Odoo archive" — everything the company's Odoo holds, consulted inside OPS as business documents
// (docs/odoo/WAVE2.md: §3 is the API contract, §4 this client). What the parts of the page share:
//   · the state of the page, all of it in the query string of /odoo (`useArchiveNav` lists the keys);
//   · the catalog — sections → kinds — with the people's-files rule, and the filters / sort of the list on screen
//     (`provideArchive` once in the page, `useArchive` in every part);
//   · how a value of the API is read and written out: labels, links, amounts, dates, tones;
//   · the download of a file with the session's token (CSV export, archived attachments).
// API, all under /api/odoo-archive (permission page.odoo; a people's-files kind also needs odoo.private):
//   GET catalog · GET docs/{kind} · GET docs/{kind}/{odooId} · GET docs/{kind}/export · GET partners/{odooId} ·
//   GET products/{odooId} · GET search?q=
// The answers are read as app/Integration/Odoo/Archive sends them (a label is `label` + `labelEn`, the wording of a
// state is in its column's `options`, a moment is ISO 8601 in UTC, nothing is null-safe by luck) and, wherever §3
// leaves a shape open, in the other spellings the client was first built on (./fixtures.dev.js holds both).
import { computed, inject, provide, ref, watch } from 'vue';
import { API_BASE, ApiError, buildQuery, refreshAccess, tokens, useGet, useInvalidate } from '@/api/client';
import { fmtDate, fmtDateOnly, getLang, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { useQueryState } from '../dashboard/shared';
import { TONE, fmtDay, plainText, utcDate } from '../integration/odoo';

export const API = '/odoo-archive';
/** Rows of a list page. */
export const PAGE_SIZE = 30;
/** Lines of a document read beyond the 200 it comes with: this many at a time. */
export const LINES_PAGE_SIZE = 50;

const NONE = Object.freeze([]);
/** `map[key]` for a key that comes from the server or the address bar: only the map's own entries count. */
const own = (map, key) => (map != null && key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);
/** A list, whatever arrived (PHP writes an empty map as `[]` and a missing list as null). */
export const arr = (v) => (Array.isArray(v) ? v : NONE);
export const isObj = (v) => !!v && typeof v === 'object' && !Array.isArray(v);

// ---------------------------------------------------------------- labels
const say = (ar, en) => { const v = lang.value === 'ar' ? ar || en : en || ar; return v == null || v === false ? '' : String(v); };
/**
 * A label of the API as text, in the language on screen. The contract says `label`; it is read in every form it
 * takes: `label` + `labelEn` (the archive API — the Arabic under the bare name), `{ ar, en }`, the `labelAr` /
 * `labelEn` pair of older endpoints, or one text for both languages.
 */
export function labelOf(o) {
  if (o == null || o === false || Array.isArray(o)) return '';
  if (typeof o !== 'object') return String(o);
  if ('ar' in o || 'en' in o) return say(o.ar, o.en);
  if (isObj(o.label)) return labelOf(o.label);
  const ar = o.labelAr ?? o.label;
  const en = o.labelEn ?? o.label;
  if ((ar != null && ar !== false) || (en != null && en !== false)) return say(ar, en);
  return typeof o.name === 'string' ? o.name : '';
}
/** What ONE document of a kind is called («أمر بيع» where the kind is «أوامر البيع»): `one` + `oneEn` of a catalog entry. */
export const oneOf = (kind) => (isObj(kind) ? say(kind.one, kind.oneEn) : '');
/** The label an answer carries about itself — never its `name`, which is the record's own (a document, the parent of a line). */
export const saidLabel = (o) => (isObj(o) ? labelOf({ label: o.label, labelAr: o.labelAr, labelEn: o.labelEn }) : '');
const ONE_FORM = { 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ى': 'ي', 'ئ': 'ي', 'ؤ': 'و', 'ة': 'ه' };
/** The spelling the archive's own search works in (its Fold.php): lower case, one form of each Arabic letter, no marks. */
export const foldText = (s) => String(s ?? '').toLowerCase().replace(/[ً-ٰٟـ]/g, '').replace(/[أإآٱىئؤة]/g, (c) => ONE_FORM[c]).replace(/\s+/g, ' ').trim();
/** Everything a kind goes by — its name in both languages, what one document of it is called, its Odoo model —, folded. */
export function namesOfKind(kind) {
  if (!isObj(kind)) return '';
  return foldText([kind.label, kind.labelAr, kind.labelEn, kind.one, kind.oneEn, kind.model].flatMap((x) => (isObj(x) ? [x.ar, x.en] : [x])).filter((x) => typeof x === 'string').join(' '));
}
/** One text for a label and no second language beside it: the server chose the language, so such answers are asked again when it changes. */
const localizedByServer = (catalog) => { const s = arr(catalog?.sections)[0]; return isObj(s) && typeof s.label === 'string' && s.labelAr == null && s.labelEn == null; };

export const PRIVATE_CHIP = { ar: 'ملفات أشخاص', en: 'People’s files', ...TONE.warn };
export const PRIVATE_HELP = { ar: 'رواتب، عقود، إجازات أو أوراق هوية: يظهر فقط لمن يملك صلاحية ملفات الأشخاص', en: 'Pay, contracts, leave or identity papers: listed only for users who may read people’s files' };
export const ARCHIVED_CHIP = { ar: 'مؤرشف في أودو', en: 'Archived in Odoo', ...TONE.warn };
/** A record Odoo no longer holds: the archive keeps its last values. */
export const DELETED_CHIP = { ar: 'محذوف من أودو', en: 'Deleted from Odoo', ...TONE.bad };

/** The tones of the application's chips; the names other systems use for the same colours are read too. */
const TONE_ALIAS = { success: 'ok', green: 'ok', warning: 'warn', amber: 'warn', orange: 'warn', danger: 'bad', error: 'bad', red: 'bad', info: 'blue', azure: 'blue', primary: 'blue', purple: 'violet', muted: 'off', grey: 'off', gray: 'off', default: 'neutral' };
export const toneOf = (name) => own(TONE, name) || own(TONE, own(TONE_ALIAS, name)) || TONE.neutral;

// ---------------------------------------------------------------- reading a value
const ID = /^[1-9]\d{0,14}$/;
const DAY = /^\d{4}-\d{2}-\d{2}$/;
const KIND = /^[a-z0-9_]{1,64}$/;
/** `<model>:<id>` of the document a record hangs on (the `parent` parameter of a list). */
const PARENT = /^[a-z0-9_.]{1,80}:[1-9]\d{0,14}$/;
/** A record id of Odoo: a positive whole number, as a number or as digits. */
export const isId = (v) => (typeof v === 'number' ? Number.isInteger(v) && v > 0 && v < 1e15 : typeof v === 'string' && ID.test(v));
/** The key of a kind, as the routes of the API accept it. */
export const isKindKey = (v) => typeof v === 'string' && KIND.test(v);
/** A calendar day `YYYY-MM-DD` that exists. */
export function isDay(v) {
  if (typeof v !== 'string' || !DAY.test(v)) return false;
  const d = new Date(`${v}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === v;
}
/** Odoo answers `false` for "nothing"; an empty text, list or map is nothing too. (A yes / no value is read before it gets here.) */
export const isBlank = (v) => v == null || v === false || v === '' || (Array.isArray(v) && !v.length) || (isObj(v) && !(v instanceof Date) && !Object.keys(v).length);

/** `{ id, name }` of a link value: the API's `{ id, name }`, or Odoo's own `[id, "label"]` (a pair of numbers is a list of ids, not a link). `id` is null when only a name came. */
export function refOf(v) {
  if (Array.isArray(v)) return v.length === 2 && isId(v[0]) && (typeof v[1] === 'string' || v[1] === false || v[1] == null) ? { id: Number(v[0]), name: v[1] || '' } : null;
  if (!isObj(v)) return null;
  const id = isId(v.id) ? Number(v.id) : isId(v.odooId) ? Number(v.odooId) : null;
  const name = [v.name, v.displayName, v.title, v.number].find((x) => typeof x === 'string' && x) || labelOf(v.label);
  return id != null || name ? { id, name: name || '' } : null;
}
/** A currency as its code: `"SAR"`, `{ name }` or Odoo's `[id, "SAR"]`. */
export const currencyCode = (c) => (typeof c === 'string' ? c : refOf(c)?.name || '');
const numberOf = (v) => (typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN);
/** A figure as Odoo holds it — a number, a decimal that arrived as text, or `{ amount, currency }`: { n, currency }. */
export function figureOf(v) {
  if (isObj(v)) return { n: figureOf(v.amount ?? v.value ?? v.total ?? null).n, currency: currencyCode(v.currency) };
  const n = numberOf(v);
  return { n: Number.isFinite(n) ? n : null, currency: '' };
}
/** Money keeps Odoo's own figure: grouped, two decimals at least, four at most (nothing is rounded to a currency here). */
export const fmtAmount = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
/** A number or a quantity: grouped, no padding; a value that is not a number stays the text it is. */
export function numText(v, type = 'number') {
  const n = numberOf(v);
  if (!Number.isFinite(n)) return typeof v === 'string' ? v : '';
  return type === 'money' ? fmtAmount(n) : n.toLocaleString('en-US', { maximumFractionDigits: type === 'qty' ? 4 : 6 });
}

const MARKUP = /<\/?(?:p|br|div|span|b|i|u|s|strong|em|ul|ol|li|a|table|tbody|thead|tr|td|th|h[1-6]|font|img|blockquote|pre|code|hr|small|sub|sup)\b[^>]*>/i;
/** Text that came from Odoo is shown as text: markup in it is reduced to its words, never rendered. */
export const plain = (s) => (MARKUP.test(s) ? plainText(s) : s);
/** Any value as the text a person reads; '' when there is nothing to read. Never `[object Object]`. */
export function textOf(v) {
  if (v == null || v === false || v === '') return '';
  if (v === true) return t('نعم', 'Yes');
  if (typeof v === 'string') return plain(v);
  if (typeof v === 'number') return Number.isFinite(v) ? String(v) : '';
  if (Array.isArray(v)) {
    const ref = refOf(v);
    return ref ? ref.name || `#${ref.id}` : v.map(textOf).filter(Boolean).join(t('، ', ', '));
  }
  if (typeof v !== 'object') return String(v);
  if ('ar' in v || 'en' in v) return say(v.ar, v.en);
  const ref = refOf(v);
  if (ref) return ref.name || `#${ref.id}`;
  if (typeof v.value === 'string' || typeof v.value === 'number') return String(v.value);
  try { const s = JSON.stringify(v); return s === '{}' ? '' : s; } catch { return ''; }
}

/** An Odoo date (`Y-m-d`) is the same calendar day everywhere; a time is Odoo's UTC, shown in the local day. Text that is no date stays as it is. */
export function dayText(v) {
  if (typeof v === 'string' && DAY.test(v)) return isDay(v) ? fmtDay(v) : v;
  const d = utcDate(v);
  return d ? fmtDateOnly(d) : typeof v === 'string' ? v : '';
}
/** A date and time (Odoo's UTC text, or ISO) as the local `YYYY/MM/DD HH:mm`; a bare day stays a day. */
export function atText(v) {
  if (typeof v === 'string' && DAY.test(v)) return isDay(v) ? fmtDay(v) : v;
  const d = utcDate(v);
  return d ? fmtDate(d) : typeof v === 'string' ? v : '';
}

const MOMENT = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?Z?$/;
/**
 * A value whose type nothing declares — the old and the new value of a change in the history — as a person reads
 * it: yes / no, a number grouped, a day, a moment in local time, else its text. '' when there is none.
 */
export function looseText(v) {
  if (typeof v === 'boolean') return v ? t('نعم', 'Yes') : t('لا', 'No');
  if (typeof v === 'number') return numText(v);
  if (typeof v === 'string' && (DAY.test(v) || MOMENT.test(v))) return atText(v);
  return textOf(v);
}

/** "12 KB" / "3.4 MB"; '' when the size is unknown. */
export function fileSize(bytes) {
  const n = numberOf(bytes);
  if (!Number.isFinite(n) || n < 0) return '';
  if (n < 1024) return `${Math.round(n)} B`;
  if (n < 1024 * 1024) return `${Math.max(1, Math.round(n / 1024)).toLocaleString('en-US')} KB`;
  return `${(n / 1024 / 1024).toLocaleString('en-US', { maximumFractionDigits: 1 })} MB`;
}
const FILE_TYPE = {
  'application/pdf': { ar: 'PDF', en: 'PDF' }, 'text/csv': { ar: 'جدول CSV', en: 'CSV sheet' }, 'text/plain': { ar: 'نص', en: 'Text' }, 'text/html': { ar: 'صفحة HTML', en: 'HTML page' },
  'application/zip': { ar: 'ملف مضغوط', en: 'ZIP archive' }, 'application/xml': { ar: 'XML', en: 'XML' }, 'text/xml': { ar: 'XML', en: 'XML' }, 'application/json': { ar: 'JSON', en: 'JSON' },
  'application/msword': { ar: 'مستند Word', en: 'Word document' }, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': { ar: 'مستند Word', en: 'Word document' },
  'application/vnd.ms-excel': { ar: 'جدول Excel', en: 'Excel sheet' }, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': { ar: 'جدول Excel', en: 'Excel sheet' },
};
/** A mimetype in words; an unknown one is shown as it is. */
export function fileType(mimetype) {
  const m = typeof mimetype === 'string' ? mimetype.toLowerCase().split(';')[0].trim() : '';
  if (!m) return '';
  const known = own(FILE_TYPE, m);
  if (known) return say(known.ar, known.en);
  if (m.startsWith('image/')) return t('صورة', 'Image');
  if (m.startsWith('audio/')) return t('صوت', 'Audio');
  if (m.startsWith('video/')) return t('فيديو', 'Video');
  return m;
}

// ---------------------------------------------------------------- the catalog
export const COLUMN_TYPES = ['text', 'number', 'qty', 'money', 'date', 'datetime', 'state', 'partner', 'product', 'user', 'bool'];
const FILTER_TYPES = ['choice', 'daterange', 'partner', 'product', 'text'];
/** Keys of the query string the page owns: a filter declared under one of these names is not drawn. */
const DRAWER_KEYS = ['doc', 'tab', 'lines', 'lp', 'card'];
const RESERVED = new Set(['kind', 'q', 'from', 'to', 'sort', 'order', 'page', 'pageSize', 'parent', 'deleted', 'active', 'lang', ...DRAWER_KEYS]);

/** The columns the API declares: [{ key, label, type }] — a type the client does not know is read as text. */
export const columnsOf = (columns) => arr(columns).filter((c) => isObj(c) && typeof c.key === 'string' && c.key).map((c) => (COLUMN_TYPES.includes(c.type) ? c : { ...c, type: 'text' }));
/** The filters a kind declares, the ones this page can draw: one date range (the API has one `from` / `to`), choices that have options. */
export function filtersOf(kind) {
  let range = false;
  return arr(kind?.filters).filter((f) => {
    if (!isObj(f) || typeof f.key !== 'string' || !FILTER_TYPES.includes(f.type)) return false;
    if (f.type === 'daterange') { const first = !range; range = true; return first; }
    if (RESERVED.has(f.key)) return false;
    return f.type !== 'choice' || arr(f.options).some(isObj);
  });
}
/** `sorts` of a kind: names of sortable columns, or `{ key, label }` for one that is not a column. */
export const sortsOf = (kind) => arr(kind?.sorts).map((s) => (typeof s === 'string' ? { key: s } : isObj(s) && typeof s.key === 'string' ? s : null)).filter((s) => s && s.key);
/**
 * The order a list comes in when none is asked for, as { key, order }: `defaultSort` + `defaultOrder` (the archive
 * API), `{ key, order }`, `"-date"`, `"date desc"` or `"date:desc"`; null when the direction is not said.
 */
export function defaultSortOf(kind) {
  const d = kind?.defaultSort;
  const dir = (v) => { const s = String(v ?? '').toLowerCase(); return s === 'asc' || s === 'desc' ? s : null; };
  if (isObj(d)) {
    const key = d.key ?? d.sort ?? d.by;
    const order = dir(d.order ?? d.dir ?? d.direction);
    return typeof key === 'string' && order ? { key, order } : null;
  }
  const m = typeof d === 'string' ? /^(-)?([A-Za-z0-9_.]+)(?:[\s:,]+(asc|desc))?$/i.exec(d.trim()) : null;
  if (!m) return null;
  const order = m[1] ? 'desc' : dir(m[3]) || dir(kind.defaultOrder);
  return order ? { key: m[2], order } : null;
}
const SORT_NAME = {
  date: { ar: 'التاريخ', en: 'Date' }, number: { ar: 'الرقم', en: 'Number' }, title: { ar: 'الاسم', en: 'Name' }, name: { ar: 'الاسم', en: 'Name' }, amount: { ar: 'المبلغ', en: 'Amount' },
  state: { ar: 'الحالة', en: 'Status' }, partner: { ar: 'الشريك', en: 'Partner' }, user: { ar: 'المسؤول', en: 'Responsible' }, odooId: { ar: 'رقم السجل في أودو', en: 'Record id in Odoo' },
};
/** What a sort is called: its own label, the column of that key, a well-known name, else its key. */
export const sortLabel = (s, columns) => labelOf(s) || labelOf(arr(columns).find((c) => c.key === s.key)) || labelOf(own(SORT_NAME, s.key)) || s.key;

const WIDTH = { text: 'minmax(130px,1fr)', number: '86px', qty: '86px', money: '128px', date: '96px', datetime: '130px', state: '120px', partner: 'minmax(150px,1.2fr)', product: 'minmax(170px,1.5fr)', user: '126px', bool: '72px' };
/**
 * DataTable columns of the columns the API declares. The first one is the row's title (the card title on a phone);
 * text, partner and product columns share the slack, the rest are fixed; a state chip takes no caption.
 */
export function tableColumns(columns, sortKeys = NONE) {
  const out = columnsOf(columns).map((c, i) => ({
    key: c.key, type: c.type, header: labelOf(c) || c.key, sortable: sortKeys.includes(c.key), bare: c.type === 'state',
    width: i === 0 && c.type === 'text' ? 'minmax(150px,1.3fr)' : WIDTH[c.type],
    // a state column carries the wording of its values: [{ value, label, tone }]
    options: Array.isArray(c.options) ? c.options : null,
  }));
  if (out.length && !out.some((c) => c.width.includes('fr'))) out[0].width = `minmax(${out[0].width},1fr)`;
  return out;
}
/** The value of a row under a column: `cells[key]`, else the row's own property of that name (number, partner, date, state, amount, user …). */
export const cellOf = (row, key) => { const c = own(isObj(row?.cells) ? row.cells : null, key); return c !== undefined ? c : own(row, key); };
/** Name of the partner / product a list is filtered by, when one of the rows on screen carries it. */
export function nameIn(items, columns, entity, id) {
  const keys = [entity, ...columnsOf(columns).filter((c) => c.type === entity).map((c) => c.key)];
  for (const it of arr(items)) for (const k of keys) { const r = refOf(cellOf(it, k)); if (r && r.name && String(r.id) === String(id)) return r.name; }
  return '';
}
/**
 * The totals line of a list: `totals.amount` of every matching document. The contract gives one figure and no
 * currency, so the currency written beside it is the one every document on the page carries (`mixed` when they
 * differ). Read as well: `totals.currency`, and a figure per currency (`{ SAR: 1 }` or `[{ currency, amount }]`).
 */
export function totalsOf(page) {
  const a = isObj(page?.totals) ? page.totals.amount : null;
  if (isBlank(a)) return NONE;
  if (Array.isArray(a)) return a.map(figureOf).filter((f) => f.n != null);
  if (isObj(a) && a.amount == null && a.value == null && a.total == null) return Object.entries(a).map(([currency, v]) => ({ n: figureOf(v).n, currency })).filter((f) => f.n != null);
  const f = figureOf(a);
  if (f.n == null) return NONE;
  const seen = [...new Set(arr(page.items).map((i) => currencyCode(i?.currency)).filter(Boolean))];
  const currency = f.currency || currencyCode(page.totals.currency) || (seen.length === 1 ? seen[0] : '');
  return [{ n: f.n, currency, mixed: !currency && seen.length > 1 }];
}

// ---------------------------------------------------------------- query-string state
const DOC = /^([a-z0-9_]{1,64}):([1-9]\d{0,14})$/;
const CARD = /^(partner|product):([1-9]\d{0,14})$/;
export const DOC_TABS = ['doc', 'history', 'files', 'fields'];
const pruned = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v != null && v !== ''));
const pageOf = (v) => (/^\d{1,7}$/.test(v) ? Math.max(1, Number(v)) : 1);
/** Two places are the same view when they have the same path and the same query, in any order of keys. */
const sameView = (a, b) => {
  const canon = (q) => JSON.stringify(Object.entries(q || {}).filter(([, v]) => v != null).map(([k, v]) => [k, String(v)]).sort((x, y) => (x[0] < y[0] ? -1 : x[0] > y[0] ? 1 : 0)));
  return a.path === b.path && canon(a.query) === canon(b.query);
};

/**
 * Everything the page shows is in the query string of /odoo, so every view is a link:
 *   (nothing)                     the home: the sections of the catalog · q=<words> searches every kind
 *   kind=<kind>                   the list of one kind, with q · sort · order · page and the filters the kind declares
 *                                 under the name of their API parameter: state · dim1 · dim2 (choice / text) ·
 *                                 from · to (the date range) · partner · product (Odoo ids) · deleted=1 (what Odoo
 *                                 no longer holds) — and, set by links only, parent=<model>:<id> (what hangs on
 *                                 one document)
 *   doc=<kind>:<odooId>           the document drawer, on any view · tab=history|files|fields is its tab ·
 *                                 lines=<section key> reads every line of one child list, lp=<page> is its page
 *   card=partner:<odooId>         the partner view · card=product:<odooId> the product view — drawn above the
 *                                 document it was opened from; closing it shows that document again
 * Opening something (a kind, a document, a view) adds an entry to the browser history, so «رجوع» walks back through
 * what was opened; a filter, the sort, a page or a tab replaces the current entry.
 */
export function useArchiveNav() {
  const qs = useQueryState();
  const { get, route, router } = qs;

  const kindKey = computed(() => (isKindKey(get('kind')) ? get('kind') : ''));
  /** `kind=` is in the address, whatever it holds: the page is a list (or "no such list"), not the home. */
  const wantsKind = computed(() => qs.has('kind'));
  const page = computed(() => pageOf(get('page')));
  const linesPage = computed(() => pageOf(get('lp')));
  const docOpen = computed(() => { const m = DOC.exec(get('doc')); return m ? { kind: m[1], id: Number(m[2]) } : null; });
  const cardOpen = computed(() => { const m = CARD.exec(get('card')); return m ? { type: m[1], id: Number(m[2]) } : null; });
  const tab = computed(() => (DOC_TABS.includes(get('tab')) ? get('tab') : 'doc'));

  // ---- places (for <RouterLink :to>): each one a full query, so a link can be opened in another tab
  const at = (query) => ({ path: route.path, query: pruned(query) });
  const rest = (keys) => { const q = { ...route.query }; for (const k of keys) delete q[k]; return q; };
  const homeTo = () => at({});
  /** The list of a kind, from its first page, with the given filters only. */
  const kindTo = (kind, filters = {}) => at({ kind, ...filters });
  /** A document: the list behind stays as it is, another open document or view gives way. */
  const docTo = (kind, id) => at({ ...rest(DRAWER_KEYS), doc: `${kind}:${id}` });
  /** The partner / product view, above whatever is open. */
  const cardTo = (type, id) => at({ ...rest(['card']), card: `${type}:${id}` });

  // ---- changes
  /** Changes some keys in place and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const setSort = (s) => patch({ sort: s?.key, order: s?.key ? s.order : null, page: null });
  const setTab = (k) => patch({ tab: k === 'doc' ? null : k });
  /** The list as it opens: no search, no filter, the kind's own order. */
  const clearFilters = () => qs.replace(pruned({ kind: get('kind') }));
  /** Goes to a place (one of the above) as a link would: a new entry of the browser history. */
  const open = (place) => router.push(place);
  const openDoc = (kind, id) => open(docTo(kind, id));
  const openCard = (type, id) => open(cardTo(type, id));
  /**
   * Closes a drawer. When the previous entry of the browser history is exactly where closing leads (the drawer was
   * opened from there), it goes one step back — the close button and «رجوع» agree and the history holds no twin
   * entries; otherwise (a deep link, a tab changed meanwhile) the keys are removed in place.
   */
  let goingBack = false;
  function leave(keys) {
    if (goingBack) return undefined; // a second click (or Escape) while the step back is on its way must not go back twice
    const target = at(rest(keys));
    const back = router.options.history.state?.back;
    if (typeof back !== 'string' || !sameView(router.resolve(back), router.resolve(target))) return router.replace(target);
    goingBack = true;
    const arrived = router.afterEach(() => { goingBack = false; arrived(); });
    setTimeout(() => { goingBack = false; }, 1500); // the browser refused to move: closing works again
    return router.back();
  }
  const closeDoc = () => leave(DRAWER_KEYS);
  const closeCard = () => leave(['card']);

  return { get, kindKey, wantsKind, page, linesPage, docOpen, cardOpen, tab, homeTo, kindTo, docTo, cardTo, patch, setFilter, setPage, setSort, setTab, clearFilters, open, openDoc, openCard, closeDoc, closeCard };
}

/**
 * The list on screen: what the query string says, checked against what the kind declares — a value the kind does
 * not know (a state that is not an option, a date that is not a date, a sort that is not sortable) is ignored and
 * never sent. `partner`, `product` and `parent` are honoured for any kind: that is how a partner view, a product
 * view and the related documents of a document link into a list. `params` is the exact query of GET docs/{kind};
 * `exportParams` the same without the page (GET export). Lengths are the ones the API accepts (q 200, a text 120).
 */
function useListState(nav, kind) {
  const { get } = nav;
  const filters = computed(() => filtersOf(kind.value));
  const q = computed(() => get('q').trim().slice(0, 200));
  const values = computed(() => {
    const out = {};
    for (const f of filters.value) {
      const v = get(f.key).trim();
      if (f.type === 'daterange') { for (const k of ['from', 'to']) if (isDay(get(k))) out[k] = get(k); }
      else if (f.type === 'choice') { if (v && f.options.some((o) => isObj(o) && String(o.value) === v)) out[f.key] = v; }
      else if (f.type === 'partner' || f.type === 'product') { if (ID.test(v)) out[f.key] = v; }
      else if (v) out[f.key] = v.slice(0, 120);
    }
    for (const k of ['partner', 'product']) if (!(k in out) && ID.test(get(k))) out[k] = get(k);
    if (PARENT.test(get('parent'))) out.parent = get('parent');
    return out;
  });
  /** `partner` / `product` / `parent` set by a link while the kind declares no such filter: shown as a removable chip. */
  const linked = computed(() => ['partner', 'product', 'parent'].filter((k) => k in values.value && !filters.value.some((f) => f.key === k)).map((k) => ({ key: k, entity: k, id: values.value[k] })));
  /** `deleted=1`: the records Odoo no longer holds, kept by the archive with their last values (instead of the ones it holds now). */
  const deleted = computed(() => get('deleted') === '1');
  const sort = computed(() => (sortsOf(kind.value).some((s) => s.key === get('sort')) ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : 'desc' } : null));
  const exportParams = computed(() => ({ q: q.value, ...values.value, deleted: deleted.value ? 1 : undefined, sort: sort.value?.key, order: sort.value?.order }));
  const params = computed(() => ({ ...exportParams.value, page: nav.page.value, pageSize: PAGE_SIZE }));
  const filtered = computed(() => !!q.value || deleted.value || Object.keys(values.value).length > 0);
  return { filters, q, values, linked, deleted, sort, params, exportParams, filtered };
}

// ---------------------------------------------------------------- the page's shared context
const ARCHIVE = Symbol('odoo-archive');
const ENTITY_MODEL = { partner: 'res.partner', product: 'product.product' };

/**
 * Called once by the page. Reads the catalog (GET catalog — again every 30 s while it says the index is not ready)
 * and gives every part, through `useArchive()`: the query-string state, the sections and kinds the user may see,
 * the list on screen, and the two rules that need the catalog — how a state is worded and where a link leads.
 */
export function provideArchive() {
  const auth = useAuth();
  const nav = useArchiveNav();

  const building = ref(false);
  const query = useGet(`${API}/catalog`, null, { staleTime: 5 * 60_000, refetchInterval: computed(() => (building.value ? 30_000 : false)) });
  const data = computed(() => (isObj(query.data.value) ? query.data.value : null));
  watch(() => data.value?.ready, (ready) => { building.value = ready === false; }, { immediate: true });
  // labels that arrive as one text were written in the language of the request
  const invalidate = useInvalidate();
  watch(lang, () => { if (localizedByServer(data.value)) invalidate([API.slice(1)]); });

  const canPrivate = computed(() => auth.can('odoo.private'));
  const canExport = computed(() => auth.can('report.export'));
  const known = (k) => isObj(k) && isKindKey(k.key);
  /**
   * People's files are listed only with odoo.private: the server leaves them out, and a catalog that carries them
   * anyway is filtered here. A section stays on screen while it has a kind to show, or says (`locked`) that it holds
   * kinds this user may not read.
   */
  const sections = computed(() => arr(data.value?.sections).filter(isObj)
    .map((s) => {
      const kinds = arr(s.kinds).filter((k) => known(k) && (!k.private || canPrivate.value));
      return { ...s, kinds, locked: !canPrivate.value && (s.locked === true || kinds.length < arr(s.kinds).filter(known).length) };
    })
    .filter((s) => s.kinds.length || s.locked));
  const byKey = computed(() => { const m = new Map(); for (const s of sections.value) for (const k of s.kinds) if (!m.has(k.key)) m.set(k.key, { ...k, section: s }); return m; });
  const hidden = computed(() => new Set(canPrivate.value ? NONE : arr(data.value?.sections).flatMap((s) => arr(s?.kinds)).filter((k) => known(k) && k.private).map((k) => k.key)));
  /** The catalog entry of a kind the user may see (with its `section`); null for any other key. */
  const kindOf = (key) => (typeof key === 'string' ? byKey.value.get(key) || null : null);
  /** A people's-files kind this user may not read. */
  const isHidden = (key) => hidden.value.has(key);
  /** The catalog carried kinds this user may not read (the page left them out): its own totals count them, so they are not shown. */
  const hidesKinds = computed(() => hidden.value.size > 0);
  /** The kinds that hold the records of an Odoo model, the fullest first (where a partner or a product is looked up). */
  const kindsOfModel = (model) => (model ? [...byKey.value.values()].filter((k) => k.model === model).sort((a, b) => (Number(b.count) || 0) - (Number(a.count) || 0)) : NONE);
  /**
   * The kinds a partner / product picker searches: the one the catalog names (`lookups: { partner, product }`), else
   * the kinds that hold the records of that Odoo model.
   */
  const lookupKinds = (entity) => {
    const named = [own(isObj(data.value?.lookups) ? data.value.lookups : null, entity)].flat().map(kindOf).filter(Boolean);
    return named.length ? named : kindsOfModel(own(ENTITY_MODEL, entity));
  };
  /** Name of the query parameter that filters a kind by a partner / a product: the key of its filter of that type, else the API's own name. */
  const entityKey = (kindKey, entity) => filtersOf(kindOf(kindKey)).find((f) => f.type === entity)?.key || entity;
  /** 'partner' | 'product' when the records of a kind are partners (res.partner) or products (product.product), else null. */
  const entityOf = (kindKey) => { const model = kindOf(kindKey)?.model; return model ? Object.keys(ENTITY_MODEL).find((e) => ENTITY_MODEL[e] === model) || null : null; };
  /**
   * Where a record of a kind opens (a place for <RouterLink>): a partner or a product opens its view — what Odoo
   * holds about it across kinds —, any other record its document drawer. (The view links to the record's own drawer.)
   */
  const placeOf = (kindKey, id) => { const entity = entityOf(kindKey); return entity ? nav.cardTo(entity, id) : nav.docTo(kindKey, id); };

  /**
   * The states a kind names, as [{ value, label, tone }]: the `options` of its column of that key (where the archive
   * API puts them), its `states`, else the options of its choice filter of that key.
   */
  function statesOf(kind, col) {
    const column = arr(kind?.columns).find((c) => isObj(c) && c.key === col && Array.isArray(c.options));
    if (column) return column.options;
    const declared = col === 'state' ? kind?.states : null;
    if (Array.isArray(declared)) return declared;
    if (isObj(declared)) return Object.entries(declared).map(([value, o]) => (isObj(o) ? { value, ...o } : { value, label: o }));
    return arr(arr(kind?.filters).find((f) => isObj(f) && f.key === col && f.type === 'choice')?.options);
  }
  /**
   * A state as { label, tone }: `{ value, label, tone }` as the detail gives it, or a bare value worded by the
   * `options` at hand (the column of a section), else by the catalog — shown as it is when nothing names it.
   */
  function stateOf(kindKey, value, col = 'state', options = null) {
    if (isObj(value)) return { label: labelOf(value) || textOf(value.value), tone: toneOf(value.tone) };
    const o = [...arr(options), ...statesOf(kindOf(kindKey), col)].find((x) => isObj(x) && String(x.value) === String(value));
    return o ? { label: labelOf(o) || String(value), tone: toneOf(o.tone) } : { label: textOf(value), tone: TONE.neutral };
  }
  /**
   * Where a value leads (a place for <RouterLink>), null when it is plain text: a partner or a product opens its
   * view; `link: { kind, id }` opens that record when the kind is in the user's catalog.
   */
  function targetOf(type, value, link = null) {
    const id = isObj(link) && isId(link.id) ? link.id : refOf(value)?.id;
    if (type === 'partner' || type === 'product') return isId(id) ? nav.cardTo(type, id) : null;
    if (!isObj(link) || !isId(link.id) || typeof link.kind !== 'string') return null;
    if (kindOf(link.kind)) return placeOf(link.kind, link.id);
    return link.kind === 'partner' || link.kind === 'product' ? nav.cardTo(link.kind, link.id) : null;
  }

  const kind = computed(() => (nav.kindKey.value ? kindOf(nav.kindKey.value) : null));
  const list = useListState(nav, kind);

  const ctx = { nav, query, data, sections, kind, list, canPrivate, canExport, kindOf, isHidden, hidesKinds, kindsOfModel, lookupKinds, entityKey, entityOf, placeOf, stateOf, targetOf };
  provide(ARCHIVE, ctx);
  return ctx;
}

/** The context the page provided: `const { nav, kindOf, list } = useArchive();` */
export function useArchive() {
  const ctx = inject(ARCHIVE, null);
  if (!ctx) throw new Error('useArchive() is for the parts of OdooArchivePage.vue');
  return ctx;
}

// ---------------------------------------------------------------- the partner and the product views
// §3 describes these two answers in words only. What is read here is what the archive API sends (the names after
// "or" are other spellings the client was built on before the API existed, read too):
//   partner: { odooId, kind, name, isCustomer, isSupplier, contacts, summary (or profile): [{ label, value, type, link? }],
//              receivable, payable: { amount, currency, documents (or count) } | number,
//              groups (or documents): [{ kind, label, total (or count), items: [doc], query: { partner: id } }] }
//   product: { odooId, kind, name, code, summary, groups, stock: { asOf, qty, reserved, available,
//              warehouses: [{ warehouseId, warehouse, qty, reserved, available, lots, nearestExpiry }] } (or the rows alone) }
//   the documents may also arrive as `counts` + `latest` kept apart, as lists or as maps keyed by kind.
/** The labelled values of a view: [{ label, value, type, link? }]. */
export const profileOf = (d) => arr([d?.summary, d?.profile].find(Array.isArray)).filter(isObj);
const asGroups = (x, part) => (Array.isArray(x) ? x.filter(isObj)
  : isObj(x) ? Object.entries(x).map(([kind, v]) => (Array.isArray(v) ? { kind, items: v } : isObj(v) ? { kind, ...v } : { kind, [part]: v }))
    : NONE);
/** Lists a product answer may name instead of grouping them by kind. */
const NAMED_LISTS = { sales: { ar: 'آخر المبيعات', en: 'Latest sales' }, purchases: { ar: 'آخر المشتريات', en: 'Latest purchases' }, moves: { ar: 'آخر حركات المخزون', en: 'Latest stock moves' } };
/**
 * The documents of a partner / product per kind, in the order the API gives them:
 * [{ key, kind, label, count, items, query }] — `query` is what filters the list of that kind by the same partner / product.
 */
export function groupsOf(d) {
  const out = new Map();
  const take = (g, i) => {
    const kind = typeof g.kind === 'string' && g.kind ? g.kind : null;
    const key = kind || `#${i}`;
    const prev = out.get(key) || { key, kind, label: null, count: null, items: NONE, query: null };
    const items = [g.items, g.latest, g.documents].find(Array.isArray);
    out.set(key, { ...prev, label: labelOf(g) ? g : prev.label, count: g.total ?? g.count ?? prev.count, items: items ? items.filter(isObj) : prev.items, query: isObj(g.query) ? g.query : prev.query });
  };
  for (const source of [d?.groups, d?.documents, d?.kinds, d?.related]) asGroups(source, 'count').forEach(take);
  asGroups(d?.counts, 'count').forEach(take);
  asGroups(d?.latest, 'items').forEach(take);
  for (const [name, label] of Object.entries(NAMED_LISTS)) if (Array.isArray(d?.[name])) take({ label, items: d[name] }, name);
  return [...out.values()].filter((g) => g.items.length || Number(g.count) > 0);
}
/** Open receivable / payable of a partner: { n, currency, count } or null when the answer does not carry it. */
export function openBalance(d, which) {
  const v = d?.[which] ?? d?.open?.[which] ?? d?.balances?.[which];
  if (isBlank(v)) return null;
  const f = figureOf(v);
  if (f.n == null) return null;
  const count = isObj(v) ? numberOf(v.documents ?? v.count) : NaN;
  return { n: f.n, currency: f.currency || currencyCode(d?.currency) || currencyCode(d?.open?.currency), count: Number.isFinite(count) ? count : null };
}
/**
 * Odoo's stock of a product per warehouse: [{ key, warehouse, qty, reserved, available, lots, nearestExpiry }] — a
 * figure the answer does not carry is null.
 */
export function stockOf(d) {
  const rows = [d?.stock?.warehouses, d?.stock, d?.warehouses, d?.stock?.rows].find(Array.isArray) || NONE;
  const fig = (v) => figureOf(v).n;
  return rows.filter(isObj).map((r, i) => {
    const qty = fig(r.qty ?? r.onHand ?? r.quantity);
    const reserved = fig(r.reserved);
    return {
      key: r.warehouseId ?? r.id ?? i, warehouse: textOf(r.warehouse ?? r.name), qty, reserved, available: fig(r.available) ?? (qty != null && reserved != null ? qty - reserved : null),
      lots: fig(r.lots), nearestExpiry: typeof r.nearestExpiry === 'string' && r.nearestExpiry ? r.nearestExpiry : null,
    };
  });
}

// ---------------------------------------------------------------- downloads
/** Name of the file a download answers with (`Content-Disposition`, plain or RFC 5987 for Arabic names). */
function fileNameOf(res) {
  const cd = res.headers.get('content-disposition') || '';
  const star = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(cd);
  if (star) { try { return decodeURIComponent(star[1].trim()); } catch { /* the plain name below */ } }
  return /filename\s*=\s*"?([^";]+)"?/i.exec(cd)?.[1]?.trim() || '';
}
/**
 * A file of this API (a path under /api) saved through a link, the way the reports page saves its CSV: asked for
 * with the session's bearer token — a plain address could not carry it — and handed to the browser as a download.
 * An expired token is refreshed once. Resolves the file name; throws ApiError (403 without the permission, …).
 */
export async function downloadFromApi(path, params, fallbackName) {
  const url = `${API_BASE}${path}${buildQuery(params)}`;
  const call = () => fetch(url, { headers: { Authorization: `Bearer ${tokens.access || ''}`, 'Accept-Language': getLang() }, credentials: 'same-origin' })
    .catch(() => { throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر التنزيل: لا اتصال بالخادم', messageEn: 'Download failed: the server cannot be reached' }); });
  let res = await call();
  if (res.status === 401 && (await refreshAccess())) res = await call();
  if (!res.ok) { const p = await res.json().catch(() => ({})); throw new ApiError({ status: res.status, ...(isObj(p) ? p : {}) }); }
  const blob = await res.blob();
  const file = fileNameOf(res) || fallbackName;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = file;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  return file;
}
/** The path under /api of a stored file (`/api/odoo-backup/files/12` → `/odoo-backup/files/12`); null for any other address. */
export const apiPathOf = (url) => (typeof url === 'string' && /^\/api\/[^/]/.test(url) ? url.slice(4) : null);
/** An address outside this API (object storage): opened as it is, without the session's token. */
export const outsideUrl = (url) => (typeof url === 'string' && /^https?:\/\/[^\s]+$/i.test(url) ? url : null);
export const isoToday = () => new Date().toISOString().slice(0, 10);
