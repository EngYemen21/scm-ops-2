// Odoo → OPS live mirror — what the views of the «أودو» tab of the integration tower share: label maps (with chip
// colours), plain wording for the reasons the server gives, formats for Odoo's values and times, the tab's
// query-string state and three small composables (search box, model picker, «مزامنة الآن»).
// API (app/Integration/Odoo/OdooMirror.php): GET /integration/odoo/{status,models,models/{model},records,
// records/{model}/{odooId},changes,runs} · POST models/{model} · POST sync.
// Two views read what OPS makes of the copy: GET stock (OdooStockView.php — Odoo's own stock figures) and
// GET master-data · POST master-data/apply (OdooMasterData.php — Odoo's products, customers and suppliers in OPS).
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { bi, fmtAgo, fmtDate, fmtDateOnly, fmtNum, lang, t, toDate } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

const NONE = [];
/** `map[key]` for a key that comes from the server: only the map's own entries count. */
const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- labels
/** The colour pairs every status chip of the application uses (shared/constants.json). */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};

/** `status.connection`: ok → healthy · configured but ODOO_ENABLED off → connected, no runs · not ok → the error text is shown. */
export const CONNECTION_HEALTH = {
  ok: { ar: 'الاتصال سليم', en: 'Healthy', ...TONE.ok },
  off: { ar: 'متصل — المزامنة متوقفة', en: 'Connected — sync is off', ...TONE.warn },
  down: { ar: 'أودو لا يستجيب', en: 'Odoo is not answering', ...TONE.bad },
};
const CONNECTION_ERROR = {
  auth: { ar: 'أودو رفض اسم المستخدم أو المفتاح.', en: 'Odoo refused the login or the key.' },
  transport: { ar: 'تعذّر الوصول إلى خادم أودو: لا رد أو انتهت المهلة.', en: 'The Odoo server could not be reached: no answer or a timeout.' },
  access: { ar: 'مستخدم أودو لا يملك صلاحية القراءة المطلوبة.', en: 'The Odoo user lacks the read access that is needed.' },
  missing: { ar: 'أودو لا يعرف ما طُلب منه (نموذج أو حقل غير موجود).', en: 'Odoo does not know what was asked for (a missing model or field).' },
  odoo: { ar: 'أودو أعاد خطأ أثناء القراءة.', en: 'Odoo raised an error while reading.' },
};
/** `connection.errorKind` in plain words; the server's own text follows it on the screen. */
export const connectionError = (kind) => bi(own(CONNECTION_ERROR, kind) || { ar: 'تعذّر الاتصال بأودو.', en: 'The connection to Odoo failed.' });

/** `odoo_models.status`. */
export const MODEL_STATUS = {
  active: { ar: 'نشط', en: 'Active', ...TONE.ok },
  new: { ar: 'جديد', en: 'New', ...TONE.blue },
  skipped: { ar: 'متخطّى', en: 'Skipped', ...TONE.off },
  paused: { ar: 'موقوف', en: 'Paused', ...TONE.warn },
  error: { ar: 'متعطّل', en: 'Error', ...TONE.bad },
};
export const MODEL_STATUSES = Object.keys(MODEL_STATUS);
/** What each status means, for tooltips and the drawer. */
export const MODEL_STATUS_HELP = {
  active: { ar: 'يُنسخ ويُتابَع ما يتغيّر فيه', en: 'Copied, and its changes are followed' },
  new: { ar: 'مسجَّل وينتظر الفحص في المزامنة القادمة', en: 'Registered — inspected on the next run' },
  skipped: { ar: 'لا يُنسخ', en: 'Not copied' },
  paused: { ar: 'أُوقف مؤقتًا — النسخة باقية ولا تتحدّث', en: 'Paused — its copy stays and is not updated' },
  error: { ar: 'توقّف بعد أخطاء متتالية — يحتاج إعادة محاولة', en: 'Stopped after repeated errors — needs a retry' },
};
/** The two filters of GET models that are not a status: `state=copying|failing`. */
export const MODEL_STATE = {
  copying: { ar: 'قيد النسخة الأولى', en: 'First copy in progress', ...TONE.blue },
  failing: { ar: 'يتعثّر', en: 'Failing', ...TONE.bad },
};
export const CORE_CHIP = { ar: 'أساسي', en: 'Core', ...TONE.violet };
export const CORE_HELP = { ar: 'نموذج أساسي: يُفحص في كل مزامنة؛ بقية النماذج تتناوب على الوقت المتبقي', en: 'Core model: checked on every run; the other models take turns with the time that is left' };
/** `odoo_models.full_state` — the first copy of a model. */
export const FULL_STATE = {
  pending: { ar: 'لم تبدأ بعد', en: 'Not started', ...TONE.neutral },
  running: { ar: 'جارية', en: 'In progress', ...TONE.blue },
  done: { ar: 'اكتملت', en: 'Complete', ...TONE.ok },
};
export const isCopying = (m) => m.status === 'active' && m.fullState !== 'done';

/** `odoo_models.reason` is an English sentence written by the server; the known ones are worded here, any other is shown as it is. */
const MODEL_REASON = {
  'paused by a person': { ar: 'أوقفه مستخدم من هذه الشاشة', en: 'Paused by a person from this screen' },
  'technical model (config odoo.exclude)': { ar: 'نموذج تقني داخلي في أودو — مستبعد من النسخ افتراضيًا', en: 'A technical Odoo model — left out of the copy by default' },
  'private: people\'s files — pay, contracts, leave, bank accounts (config odoo.private)': { ar: 'ملفات أشخاص: رواتب، عقود، إجازات أو حسابات بنكية — لا تُنسخ إلا بقرار صريح', en: 'People’s files: pay, contracts, leave or bank accounts — copied only by an explicit decision' },
  'included by a person': { ar: 'ضمّه مستخدم إلى النسخة من هذه الشاشة', en: 'Taken into the copy by a person from this screen' },
  'no read access for the Odoo user': { ar: 'مستخدم أودو لا يملك صلاحية قراءته', en: 'The Odoo user has no read access to it' },
  'not available in this Odoo': { ar: 'غير متاح في هذا الأودو', en: 'Not available in this Odoo' },
  'no write_date: a report view, not stored records': { ar: 'تقرير محسوب في أودو وليس سجلات محفوظة — لا يُنسخ', en: 'A computed report, not stored records — not copied' },
};
export const modelReason = (reason) => (reason ? bi(own(MODEL_REASON, reason) || reason) : '');

/** `odoo_changes.kind`. */
export const CHANGE_KIND = {
  created: { ar: 'أُنشئ', en: 'Created', ...TONE.ok },
  updated: { ar: 'عُدّل', en: 'Updated', ...TONE.blue },
  archived: { ar: 'أُرشف', en: 'Archived', ...TONE.warn },
  restored: { ar: 'استُعيد', en: 'Restored', ...TONE.violet },
  deleted: { ar: 'حُذف', en: 'Deleted', ...TONE.bad },
};
export const CHANGE_KINDS = Object.keys(CHANGE_KIND);

/** A record of the copy as Odoo has it now. `active` is null when the model has no archive flag. */
export const RECORD_STATE = {
  live: { ar: 'فعّال', en: 'Active', ...TONE.ok },
  archived: { ar: 'مؤرشف', en: 'Archived', ...TONE.warn },
  deleted: { ar: 'محذوف من أودو', en: 'Deleted from Odoo', ...TONE.bad },
};
export const recordState = (r) => (r.deletedAt ? 'deleted' : r.active === false ? 'archived' : r.active === true ? 'live' : null);

/** `odoo_runs.trigger`: who started the run. */
export const RUN_TRIGGER = {
  person: { ar: 'يدوي من هذه الشاشة', en: 'Manual (this screen)', ...TONE.violet },
  manual: { ar: 'يدوي', en: 'Manual', ...TONE.violet },
  heartbeat: { ar: 'تلقائي: دورة التكامل', en: 'Automatic (integration cycle)', ...TONE.neutral },
  command: { ar: 'تلقائي: المجدول', en: 'Automatic (scheduler)', ...TONE.neutral },
};

/** Odoo field types, in words. */
const FIELD_TYPE = {
  char: { ar: 'نص قصير', en: 'Short text' }, text: { ar: 'نص', en: 'Text' }, html: { ar: 'نص منسّق', en: 'Formatted text' },
  integer: { ar: 'عدد صحيح', en: 'Whole number' }, float: { ar: 'رقم عشري', en: 'Decimal number' }, monetary: { ar: 'مبلغ', en: 'Amount' },
  boolean: { ar: 'نعم / لا', en: 'Yes / no' }, date: { ar: 'تاريخ', en: 'Date' }, datetime: { ar: 'تاريخ ووقت', en: 'Date and time' },
  selection: { ar: 'اختيار من قائمة', en: 'Choice from a list' }, many2one: { ar: 'ارتباط بسجل', en: 'Link to a record' },
  many2many: { ar: 'ارتباط بعدة سجلات', en: 'Links to several records' }, one2many: { ar: 'قائمة فرعية', en: 'Child list' },
  reference: { ar: 'مرجع إلى سجل', en: 'Reference to a record' }, many2one_reference: { ar: 'رقم سجل مرتبط', en: 'Id of a linked record' },
  binary: { ar: 'ملف أو صورة', en: 'File or image' }, json: { ar: 'بيانات مركّبة', en: 'Structured data' },
  properties: { ar: 'خصائص إضافية', en: 'Extra properties' }, properties_definition: { ar: 'تعريف الخصائص', en: 'Properties definition' },
};
export const fieldType = (type) => (type ? bi(own(FIELD_TYPE, type) || type) : '—');

/** Why a field is left out of the copy (`skippedFields`: credential · a skipped type such as binary · one2many · "Odoo cannot return it"). */
const FIELD_REASON = {
  credential: { ar: 'بيانات سرّية (كلمة مرور، مفتاح، رمز دخول) — لا تُنسخ أبدًا', en: 'A credential (password, key, access token) — never copied' },
  personal: { ar: 'من الملف الشخصي للموظف (هوية، عائلة، عنوان، حساب بنكي، راتب) — لا يُنسخ', en: 'Part of an employee’s private file (identity, family, address, bank account, pay) — not copied' },
  binary: { ar: 'ملف أو صورة — المحتوى لا يُنسخ', en: 'A file or an image — its content is not copied' },
  one2many: { ar: 'قائمة فرعية — تُنسخ من نموذجها نفسه فلا يضيع شيء', en: 'A child list — copied from its own model, so nothing is lost' },
  'Odoo cannot return it': { ar: 'أودو لا يستطيع إرجاعه', en: 'Odoo cannot return it' },
};
export function fieldReason(reason) {
  if (!reason) return '—';
  if (own(FIELD_REASON, reason)) return bi(FIELD_REASON[reason]);
  return own(FIELD_TYPE, reason) ? t(`نوع لا يُنسخ: ${FIELD_TYPE[reason].ar}`, `A type that is not copied: ${FIELD_TYPE[reason].en}`) : reason;
}

// ---------------------------------------------------------------- times and numbers
/**
 * A time as a Date. The API spells times three ways: ISO with a zone (model columns), a bare `Y-m-d H:i:s[.v]` from
 * SQL aggregates (`status.changes.lastAt`, `status.freshness.coreOldestSyncAt`) and Odoo's own `Y-m-d H:i:s`
 * (`writeDate`, datetime values). The bare ones are UTC — the server's clock and Odoo's both are — so they are read
 * as UTC, never as the browser's local time.
 */
export function utcDate(v) {
  if (v instanceof Date) return toDate(v);
  if (typeof v !== 'string' || !v) return null; // Odoo's `false`, a missing time
  const m = v.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)$/);
  return toDate(m ? `${m[1]}T${m[2]}Z` : v);
}
/** Local date and time (`YYYY/MM/DD HH:mm`), «—» when there is none. */
export const fmtAt = (v) => fmtDate(utcDate(v));
/** "قبل 5 د" / "5m ago". */
export const agoAt = (v) => fmtAgo(utcDate(v), lang.value);
/** An Odoo date (`Y-m-d`, no time and no zone) as the same calendar day. */
export const fmtDay = (v) => fmtDateOnly(typeof v === 'string' ? v.replace(/-/g, '/') : v);
/** Milliseconds as seconds: "4.2 ث". */
export const fmtSecs = (ms) => (ms == null ? '—' : `${(ms / 1000).toFixed(ms < 10000 ? 1 : 0)} ${t('ث', 's')}`);
/** `freshness.coreLagSeconds` as a number, its unit and a colour: up to 5 minutes is fresh, up to 30 is late, more is stale. */
export function lagParts(sec) {
  if (sec == null) return { n: null, unit: null, color: undefined };
  const color = sec <= 300 ? TONE.ok.fg : sec <= 1800 ? TONE.warn.fg : TONE.bad.fg;
  if (sec < 120) return { n: sec, unit: { ar: 'ثانية', en: 'sec' }, color };
  if (sec < 7200) return { n: Math.round(sec / 60), unit: { ar: 'دقيقة', en: 'min' }, color };
  if (sec < 172800) return { n: Math.round(sec / 3600), unit: { ar: 'ساعة', en: 'h' }, color };
  return { n: Math.round(sec / 86400), unit: { ar: 'يوم', en: 'days' }, color };
}

/** The figures of one run (a row of odoo_runs, or `status.lastRun`) as small tiles: [{ k, v, l, bad }]. */
export function runTiles(r) {
  if (!r) return NONE;
  return [
    { k: 'ms', v: fmtSecs(r.ms), l: { ar: 'المدة', en: 'Duration' } },
    { k: 'calls', v: fmtNum(r.calls), l: { ar: 'نداءات إلى أودو', en: 'Calls to Odoo' } },
    { k: 'pulled', v: fmtNum(r.pulled), l: { ar: 'سجلات مقروءة', en: 'Records read' } },
    { k: 'created', v: fmtNum(r.created), l: { ar: 'جديدة', en: 'Created' } },
    { k: 'updated', v: fmtNum(r.updated), l: { ar: 'معدّلة', en: 'Updated' } },
    { k: 'deleted', v: fmtNum(r.deleted), l: { ar: 'محذوفة', en: 'Deleted' } },
    { k: 'errors', v: fmtNum(r.errors), l: { ar: 'أخطاء', en: 'Errors' }, bad: r.errors > 0 },
  ];
}
/**
 * `detail.models` of a run is keyed by model, plus steps that are not a model: today `→ OPS master data` (what the run
 * applied to the OPS products, customers and suppliers: { changes, counts: { entity: { action: n } } } or { error }).
 */
const RUN_STEP = { '→ OPS master data': { ar: 'تحديث بيانات النظام من أودو (أصناف، عملاء، موردون)', en: 'Update of the OPS master data from Odoo (products, customers, suppliers)' } };
export const runStepTitle = (name) => bi(own(RUN_STEP, name) || name);
/** The OPS master data the Odoo copy feeds (OdooMasterData.php), in the order the screen shows it. */
export const MASTER_ENTITY = { products: { ar: 'الأصناف', en: 'Products' }, customers: { ar: 'العملاء', en: 'Customers' }, suppliers: { ar: 'الموردون', en: 'Suppliers' }, categories: { ar: 'الفئات', en: 'Categories' } };
/** What happened (or would happen) to a record of Odoo; `tone` colours the ones a person should look at. */
export const MASTER_ACTION = {
  created: { ar: 'أُنشئ', en: 'created' }, linked: { ar: 'رُبط بموجود', en: 'linked to an existing one' }, updated: { ar: 'حُدّث', en: 'updated' }, unchanged: { ar: 'بلا تغيير', en: 'unchanged' },
  skippedArchived: { ar: 'تُرك لأنه مؤرشف', en: 'left out: archived' }, skippedNoCode: { ar: 'تُرك لأنه بلا رمز', en: 'left out: no code' }, skippedService: { ar: 'تُرك لأنه خدمة', en: 'left out: a service' },
  duplicateCode: { ar: 'رمز مكرر في أودو', en: 'duplicate code in Odoo', tone: 'warn' }, badCode: { ar: 'رمز لا يصلح رمزًا لصنف', en: 'a reference that is not a usable code', tone: 'warn' }, barcodeTaken: { ar: 'باركود مستخدم لصنف آخر', en: 'barcode used by another product', tone: 'warn' },
  refused: { ar: 'رفضته قاعدة في النظام', en: 'refused by an OPS rule', tone: 'warn' }, needsReview: { ar: 'يحتاج مراجعة', en: 'needs a review', tone: 'warn' },
  failed: { ar: 'تعذّر تطبيقه', en: 'could not be applied', tone: 'bad' },
};
/** The actions that write to OPS: their sum is what an apply does. */
export const MASTER_WRITES = ['created', 'linked', 'updated'];
/** The counts of such a step as lines: [{ entity, parts: 'أُنشئ 3 · حُدّث 2' }] — entities where nothing happened are left out. */
export function runStepLines(counts) {
  return Object.entries(counts || {}).map(([entity, acts]) => ({
    entity: bi(own(MASTER_ENTITY, entity) || entity),
    parts: Object.entries(acts || {}).filter(([, n]) => n).map(([what, n]) => `${bi(own(MASTER_ACTION, what) || what)} ${fmtNum(n)}`).join(' · '),
  })).filter((x) => x.parts);
}
/** Where a run failed: the models it could not read and the steps that broke (`detail.models[name].error`). */
export const runFailedModels = (r) => Object.entries(r?.detail?.models || {}).filter(([, x]) => x?.error).map(([name]) => runStepTitle(name));

/** What a run found, in one line (the answer of POST sync or a row of the run log). */
export function runLine(r) {
  if (!r.pulled && !r.created && !r.updated && !r.deleted && !r.errors) return t('لا جديد في أودو منذ آخر فحص.', 'Nothing new in Odoo since the last check.');
  return t(
    `قُرئ من أودو ${fmtNum(r.pulled)} سجل — جديد ${fmtNum(r.created)} · معدّل ${fmtNum(r.updated)} · محذوف ${fmtNum(r.deleted)}${r.errors ? ` · أخطاء ${fmtNum(r.errors)}` : ''}`,
    `${fmtNum(r.pulled)} records read from Odoo — ${fmtNum(r.created)} new · ${fmtNum(r.updated)} changed · ${fmtNum(r.deleted)} deleted${r.errors ? ` · ${fmtNum(r.errors)} errors` : ''}`,
  );
}
/** What is still to be done after a run (`pending` of POST sync); '' when nothing is. */
export function pendingLine(p) {
  if (!p) return '';
  const parts = [];
  if (p.toInspect) parts.push(t(`نماذج بانتظار الفحص: ${fmtNum(p.toInspect)}`, `models waiting to be inspected: ${fmtNum(p.toInspect)}`));
  if (p.firstCopy) parts.push(t(`نماذج لم تكتمل نسختها الأولى: ${fmtNum(p.firstCopy)}`, `models whose first copy is not complete: ${fmtNum(p.firstCopy)}`));
  if (p.windows) parts.push(t(`نماذج لم تكتمل قراءة تغييراتها: ${fmtNum(p.windows)}`, `models whose changes were not all read: ${fmtNum(p.windows)}`));
  return parts.join(' · ');
}

// ---------------------------------------------------------------- values of a record
const DATETIME = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;
/**
 * How one value of an Odoo record is shown: empty · bool · ref · ids · num · datetime · date · html · text · json.
 * Odoo answers `false` for "empty" in every type but boolean, `[id, "label"]` for a many2one and a list of ids for a
 * many2many / one2many. `type` is the field's Odoo type when the model's field list knows it; without it the shape
 * of the value decides. An html value that holds nothing but empty tags is empty too.
 */
export function valueKind(v, type = null) {
  if (v == null || v === '') return 'empty';
  if (typeof v === 'boolean') return v || type === 'boolean' ? 'bool' : 'empty';
  if (Array.isArray(v)) {
    if (!v.length) return 'empty';
    if (v.length === 2 && Number.isInteger(v[0]) && typeof v[1] === 'string' && type !== 'many2many' && type !== 'one2many') return 'ref';
    return v.every((x) => Number.isInteger(x)) ? 'ids' : 'json';
  }
  if (typeof v === 'object') return Object.keys(v).length ? 'json' : 'empty';
  if (typeof v === 'number') return 'num';
  const s = String(v);
  if ((type === 'datetime' || !type) && DATETIME.test(s) && utcDate(s)) return 'datetime';
  if ((type === 'date' || !type) && DATE.test(s)) return 'date';
  if (type === 'html') return plainText(s) ? 'html' : 'empty';
  return 'text';
}
/** A number as Odoo holds it: ids and counters plain, quantities and amounts grouped with up to six decimals. */
export const fmtValueNum = (v, type = null) => (type === 'integer' || type === 'many2one_reference' ? String(v) : v.toLocaleString('en-US', { maximumFractionDigits: 6 }));
/** The text of an Odoo html value. It comes from another system, so it is never rendered as markup. */
export function plainText(html) {
  const s = String(html ?? '');
  if (!/[<&]/.test(s)) return s.trim();
  const lines = s.replace(/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/gi, '$&\n');
  try {
    return (new DOMParser().parseFromString(lines, 'text/html').body.textContent || '').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
  } catch {
    return lines.replace(/<[^>]*>/g, ' ').replace(/[ \t]{2,}/g, ' ').trim();
  }
}
/** Lower-case text of any value, for the filter box of the record drawer. */
export const valueText = (v) => (typeof v === 'string' ? v : JSON.stringify(v ?? '')).toLowerCase();

// ---------------------------------------------------------------- query-string state
const ODOO_VIEWS = ['overview', 'stock', 'master', 'models', 'changes', 'records', 'runs'];
const MODEL_NAME = /^[a-z0-9_.]+$/;
/** A technical Odoo model name (the pattern of the API's routes). */
export const isModelName = (name) => MODEL_NAME.test(String(name ?? ''));
const pruned = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v != null && v !== ''));

/**
 * Everything the tab shows is in the query string of /itower, so every view is a link:
 *   tab=odoo · view=overview|stock|master|models|changes|records|runs · page
 *   models:  status · state · q · sort · order        changes: model · kind · q        records: model · q · deleted=1 · sort · order
 *   stock:   warehouse · expiry=expired|soon · q · sort · order
 *   m=<model> opens the model drawer · rec=<model>:<odooId> opens the record drawer — on any view, one at a time
 */
export function useOdooNav() {
  const qs = useQueryState();
  const { get } = qs;
  const view = computed(() => (ODOO_VIEWS.includes(get('view')) ? get('view') : 'overview'));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  /** Model named by a key of the query string ('' when it is absent or not a model name). */
  const modelParam = (key = 'model') => (MODEL_NAME.test(get(key)) ? get(key) : '');
  const modelOpen = computed(() => modelParam('m') || null);
  const recordOpen = computed(() => { const m = /^([a-z0-9_.]+):(\d{1,15})$/.exec(get('rec')); return m ? { model: m[1], id: Number(m[2]) } : null; });

  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  /** Opens a view with its own filters; what belonged to the previous view (filters, page, open drawer) is dropped. */
  const go = (to, params = {}) => qs.replace({ tab: 'odoo', ...(to && to !== 'overview' ? { view: to } : {}), ...pruned(params) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const openModel = (model) => patch({ m: model, rec: null });
  const openRecord = (model, odooId) => patch({ rec: `${model}:${odooId}`, m: null });
  const closeDrawer = () => patch({ m: null, rec: null });
  return { get, view, page, modelParam, modelOpen, recordOpen, patch, go, setFilter, setPage, openModel, openRecord, closeDrawer };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a filter that clears the key refills the box.
 *   const { text, type, apply } = useSearchParam(nav);
 *   <TextInput :model-value="text" @update:model-value="type" @enter="apply" />
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

// ---------------------------------------------------------------- composables
const byCoreThenName = (a, b) => (a.core !== b.core ? (a.core ? -1 : 1) : a.core && a.priority !== b.priority ? a.priority - b.priority : a.model.localeCompare(b.model));

/**
 * The models that hold records, for the pickers of the changes and records views (core models first, then by name),
 * and Odoo's own label of each model. One request: the 500 models with the most records.
 */
export function useOdooModels() {
  const list = useList('/integration/odoo/models', { hasRecords: 1, sort: 'mirrored_count', order: 'desc', pageSize: 500 }, { staleTime: 60_000 });
  const rows = computed(() => list.data.value?.items || NONE);
  const withRecords = computed(() => rows.value.filter((m) => m.mirroredCount > 0).sort(byCoreThenName));
  const labels = computed(() => Object.fromEntries(rows.value.map((m) => [m.model, m.label])));
  /** Select options; `current` (a deep link) is listed even when it holds no record. */
  const options = (current = '') => {
    const out = withRecords.value.map((m) => [m.model, `${m.model}${m.label ? ` · ${m.label}` : ''} (${fmtNum(m.mirroredCount)})`]);
    if (current && !withRecords.value.some((m) => m.model === current)) out.unshift([current, current]);
    return out;
  };
  return { list, withRecords, labels, options, loaded: computed(() => !!list.data.value) };
}

/**
 * «مزامنة الآن»: one short run now — POST /integration/odoo/sync — for every model or for one. Keeps what the run
 * found in `result` ({ ms, calls, models, pulled, created, updated, deleted, errors, error, pending }); a refusal
 * (422 ODOO_DISABLED, 409 ODOO_BUSY) lands in `error` with the server's message.
 */
export function useOdooSync() {
  const act = useAction({ invalidate: ['integration/odoo'] });
  const result = ref(null);
  async function run(model = null) {
    result.value = null;
    const r = await act.run(() => api.postIdempotent('/integration/odoo/sync', model ? { model } : {}), {
      success: (d) => (d?.error ? t('انتهت المزامنة بخطأ', 'The run ended with an error') : t('اكتملت المزامنة ✓', 'Sync finished ✓')),
    });
    if (r) result.value = r;
    return r;
  }
  return { run, result, pending: act.pending, error: act.error, clearError: act.clearError, clear: () => { result.value = null; } };
}
