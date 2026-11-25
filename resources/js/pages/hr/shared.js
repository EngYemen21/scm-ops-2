// «الموظفون والمصروفات» / People & expenses — what the page and its parts share: label maps (with chip colours), small
// formats, the page's query-string state, the lookups of its forms and the file helpers.
//
// API (routes/api/hr.php — camelCase JSON, decimals arrive as strings, dates as `YYYY-MM-DD`):
//   GET /hr/summary · /hr/lookups                          each part only for who may see it
//   GET /hr/employees ?q&status&department&sub&job&manager&source&linked&sort&order&page&pageSize · /hr/employees/{id|code}
//       Employee { id, code, employeeNo, nameAr, nameEn, jobTitle, job?, department?, manager?, workEmail, workPhone, workMobile, workLocation,
//                  startDate, status: active|left|inactive, user?{id,username}, driver?{id,code}, source: 'odoo'|null, locked,
//                  detail: notes, reports[], manages[], ownedFields[],
//                  with hr.private: private{…}, contracts[], files[]      with hr.manage|hr.private: documents[], alerts[] }
//       — without hr.private the keys `private`, `contracts`, `files` DO NOT EXIST in the answer.
//   POST /hr/employees · PATCH /hr/employees/{id} (hr.manage) · GET|PUT /hr/employees/{id}/private (hr.private)
//   GET /hr/departments · /hr/jobs · /hr/org                POST|PATCH departments, jobs (hr.manage)
//   GET /hr/contracts[/{id}] · POST · PATCH · POST …/start|end|cancel|renew          (hr.private)
//   GET /hr/documents[/{id}] · GET /hr/alerts · POST /hr/alerts/refresh               (hr.manage or hr.private)
//   POST /hr/documents · PATCH · POST …/renew|void|files · DELETE …/files/{fileId}    (hr.manage; private papers also hr.private)
//   GET /hr/files/{id}?as=data → { fileName, mime, size, dataUrl }                    (whoever may read what the file hangs on)
//   GET /hr/expense-categories · POST · PATCH (expense.review)
//   GET /hr/claims ?scope=mine|review|all&state&q&employee&from&to&source&sort&order&page · /hr/claims/{id|number}
//       Claim { id, number, title, state, employee?, claimant?, department?, amount, vatAmount, total, employeeDue, companyPaid, lineCount,
//               linesWithoutReceipt, submittedAt, decidedAt, postedAt, paidAt, createdAt, mine, source, locked,
//               detail: lines[{ id, lineNo, date, category, description, amount, vatPct, vatAmount, total, paidBy, receipts[], accountCode*, voucher* }],
//               files[], history[{ at, from, to, by, note, origin }], decisionNote, settlement, paymentRef, can{…} }       (* reviewers only)
//   POST /hr/claims · PATCH · POST …/lines/{lineId}/receipts · DELETE …/receipts/{fileId} · POST …/submit|withdraw|cancel   (the claimant)
//   POST …/approve|refuse|return|reopen (expense.review) · …/post|pay|unpay (+ expense.create) · …/unpost (+ gl.approve)
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';

const NONE = [];

// ---------------------------------------------------------------- labels
/** The colour pairs every status chip of the application uses. */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#d9f4f9' },
  neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};

export const EMPLOYEE_STATUS = {
  active: { ar: 'على رأس العمل', en: 'Active', ...TONE.ok },
  left: { ar: 'ترك العمل', en: 'Left', ...TONE.off },
  inactive: { ar: 'مؤرشف', en: 'Archived', ...TONE.neutral },
};
export const CONTRACT_STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  running: { ar: 'سارٍ', en: 'Running', ...TONE.ok },
  expired: { ar: 'منتهٍ', en: 'Ended', ...TONE.off },
  cancelled: { ar: 'ملغى', en: 'Cancelled', ...TONE.bad },
};
export const CONTRACT_TYPE = {
  permanent: { ar: 'غير محدد المدة', en: 'Permanent' }, fixed_term: { ar: 'محدد المدة', en: 'Fixed term' }, temporary: { ar: 'مؤقت', en: 'Temporary' },
  part_time: { ar: 'دوام جزئي', en: 'Part time' }, trainee: { ar: 'تدريب', en: 'Trainee' }, other: { ar: 'آخر', en: 'Other' },
};
export const DOCUMENT_STATUS = {
  active: { ar: 'قائمة', en: 'Current', ...TONE.ok },
  replaced: { ar: 'جُدِّدت', en: 'Renewed', ...TONE.off },
  void: { ar: 'ملغاة', en: 'Void', ...TONE.bad },
};
/** How close an expiry date is: the stages of the alerts (60 / 30 / 7 days before, and after). */
export const STAGE = {
  expired: { ar: 'منتهية', en: 'Expired', fg: '#fff', bg: '#b23b3b' },
  d7: { ar: 'خلال 7 أيام', en: 'Within 7 days', ...TONE.bad },
  d30: { ar: 'خلال 30 يومًا', en: 'Within 30 days', ...TONE.warn },
  d60: { ar: 'خلال 60 يومًا', en: 'Within 60 days', ...TONE.blue },
  valid: { ar: 'سارية', en: 'Valid', ...TONE.ok },
};
export const STAGES = ['expired', 'd7', 'd30', 'd60'];
export const ALERT_CATEGORY = {
  document: { ar: 'وثيقة', en: 'Document' }, identity: { ar: 'هوية / إقامة', en: 'Identity' }, passport: { ar: 'جواز سفر', en: 'Passport' }, contract: { ar: 'عقد عمل', en: 'Contract' },
};
export const CLAIM_STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  submitted: { ar: 'بانتظار المراجعة', en: 'Waiting for review', ...TONE.warn },
  approved: { ar: 'معتمدة — بانتظار الترحيل', en: 'Approved — to be posted', ...TONE.blue },
  posted: { ar: 'مرحَّلة — بانتظار السداد', en: 'Posted — to be paid', ...TONE.teal },
  paid: { ar: 'مسدَّدة ✓', en: 'Settled ✓', ...TONE.ok },
  refused: { ar: 'مرفوضة', en: 'Refused', ...TONE.bad },
  cancelled: { ar: 'ملغاة', en: 'Cancelled', ...TONE.off },
};
export const CLAIM_STATES = Object.keys(CLAIM_STATE);
/** Short names of the states, for the history timeline. */
export const CLAIM_STEP = {
  draft: { ar: 'مسودة', en: 'Draft' }, submitted: { ar: 'أُرسلت للمراجعة', en: 'Submitted' }, approved: { ar: 'اعتُمدت', en: 'Approved' }, posted: { ar: 'رُحِّلت للدفاتر', en: 'Posted to the books' },
  paid: { ar: 'سُدِّدت', en: 'Settled' }, refused: { ar: 'رُفضت', en: 'Refused' }, cancelled: { ar: 'أُلغيت', en: 'Cancelled' },
};
export const PAID_BY = { employee: { ar: 'دفعه الموظف', en: 'Paid by the employee', ...TONE.violet }, company: { ar: 'دفعته الشركة', en: 'Paid by the company', ...TONE.teal } };
export const PAID_BY_OPTIONS = [['employee', { ar: 'الموظف', en: 'Employee' }], ['company', { ar: 'الشركة', en: 'Company' }]];
export const VOUCHER_STATUS = { posted: { ar: 'مرحَّل', en: 'Posted', ...TONE.ok }, void: { ar: 'ملغى', en: 'Void', ...TONE.bad } };
export const GENDER = { male: { ar: 'ذكر', en: 'Male' }, female: { ar: 'أنثى', en: 'Female' } };
export const MARITAL = { single: { ar: 'أعزب / عزباء', en: 'Single' }, married: { ar: 'متزوج/ة', en: 'Married' }, divorced: { ar: 'مطلَّق/ة', en: 'Divorced' }, widowed: { ar: 'أرمل/ة', en: 'Widowed' } };
/** A record that came from Odoo; while the section follows Odoo it is changed there. */
export const FROM_ODOO = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const PRIVATE_CHIP = { ar: 'ملف شخصي', en: 'Personal file', ...TONE.violet };
export const SELECT = { ar: '— اختر —', en: '— select —' };
export const ANY = { ar: 'الكل', en: 'All' };

/** `[value, {ar,en}]` options out of a label map. */
export const optionsOf = (map) => Object.entries(map).map(([v, l]) => [v, { ar: l.ar, en: l.en }]);

// ---------------------------------------------------------------- formats
/** Name of a record that has nameAr / nameEn, in the language on screen. */
export const pname = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || '—' : '—');
/** A calendar day (`YYYY-MM-DD`, no time, no zone) as the same day wherever the browser is. */
export const fmtDay = (v) => (v ? fmtDateOnly(String(v).slice(0, 10).replace(/-/g, '/')) : '—');
export const fmtAt = (v) => (v ? fmtDate(v) : '—');
/** An amount with two decimals. */
export const money = (v) => fmtNum(Number(v) || 0, 2);
/** Today as `YYYY-MM-DD` in the browser's own calendar. */
export function todayIso() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
export const fileSize = (n) => (n == null ? '' : n >= 1024 * 1024 ? `${fmtNum(n / 1024 / 1024, 1)} MB` : `${fmtNum(Math.max(1, Math.round(n / 1024)))} KB`);
/** "expires in 12 days" / "expired 3 days ago" for a number of days to a date. */
export function daysText(days) {
  if (days == null) return '—';
  const n = Math.abs(days);
  if (days < 0) return t(`منذ ${n} يومًا`, `${n} day(s) ago`);
  return days === 0 ? t('اليوم', 'today') : t(`بعد ${n} يومًا`, `in ${n} day(s)`);
}
/** The employee (or, without an employee record, the login) a claim belongs to. */
export const claimOwner = (c) => (c?.employee ? pname(c.employee) : c?.claimant ? pname(c.claimant) : '—');
export const claimOwnerCode = (c) => c?.employee?.code || c?.claimant?.username || '';

// ---------------------------------------------------------------- claim lines
/** Two decimals the way the server rounds (a product such as 2093.90 × 15 % is 314.085 to it, not 314.08499999999998). */
const r2 = (x) => Math.round(parseFloat((Number(x) * 100).toPrecision(15))) / 100;
/**
 * The figures of a claim line the way the server computes them (ClaimsService::figures — the voucher's own formula):
 * amount before VAT, VAT = round(amount × %), total = amount + VAT; when the typed amount is the total of the receipt,
 * the amount before VAT is the one whose VAT brings the total back. For the preview only: the server's figures are the ones kept.
 */
export function lineFigures(amount, pct, includesVat) {
  const rate = Number(pct) || 0;
  const vatOf = (net) => r2((net * rate) / 100);
  let net = r2(Number(amount) || 0);
  if (includesVat && rate > 0) {
    const gross = net;
    net = r2(gross / (1 + rate / 100));
    for (const shift of [0, 0.01, -0.01]) { const tryNet = r2(net + shift); if (Math.abs(tryNet + vatOf(tryNet) - gross) < 0.005) { net = tryNet; break; } }
  }
  const vat = vatOf(net);
  return { amount: net, vat, total: r2(net + vat) };
}
let lineKey = 0;
/** A blank line of the claim form. */
export const newClaimLine = () => ({ key: `n${++lineKey}`, id: null, date: todayIso(), category: '', description: '', amount: null, vatPct: null, amountIncludesVat: true, paidBy: 'employee', file: null, receipts: [] });

// ---------------------------------------------------------------- files
export const FILE_MAX_BYTES = 5 * 1024 * 1024;
export const FILE_ACCEPT = 'application/pdf,image/png,image/jpeg,image/webp';
/** A picture larger than this is scaled down in the browser before it is sent. */
const IMAGE_SHRINK_ABOVE = 400 * 1024;
const IMAGE_MAX_SIDE = 1800;
/** Decoded size of a data URL in bytes (what the server will count). */
const dataUrlBytes = (url) => Math.floor((String(url).split(',')[1] || '').length * 0.75);
/**
 * Scales a picture down to at most 1800 px on its longest side and re-encodes it as JPEG: a phone photo of a receipt
 * (several MB) becomes a few hundred KB and stays readable. Resolves null when the browser cannot do it.
 */
function shrinkImage(dataUrl) {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      try {
        const scale = Math.min(1, IMAGE_MAX_SIDE / Math.max(img.naturalWidth || 1, img.naturalHeight || 1));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; // JPEG has no transparency
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const out = canvas.toDataURL('image/jpeg', 0.8);
        resolve(out && out.startsWith('data:image/jpeg') ? out : null);
      } catch { resolve(null); }
    };
    img.onerror = () => resolve(null);
    img.src = dataUrl;
  });
}
/**
 * A picked file as the API takes it: `{ fileName, mime, base64 }` (base64 is a data URL; `size` is what the server
 * will count). A large picture is scaled down first, so the 5 MB limit is judged on what is really sent; a PDF goes
 * as it is. Rejects with a message in the language on screen.
 */
export function readFile(file) {
  return new Promise((resolve, reject) => {
    if (!file) { reject(new Error(t('لم يُختر ملف', 'No file was picked'))); return; }
    const isImage = /^image\/(png|jpeg|webp|gif)$/.test(file.type || '');
    if (!isImage && file.type !== 'application/pdf') { reject(new Error(t('يُقبل ملف PDF أو صورة فقط', 'Only a PDF or a picture is accepted'))); return; }
    const tooLarge = () => reject(new Error(t('حجم الملف يتجاوز 5 ميجابايت', 'The file exceeds 5 MB')));
    if (!isImage && file.size > FILE_MAX_BYTES) { tooLarge(); return; }
    const reader = new FileReader();
    reader.onerror = () => reject(new Error(t('تعذّرت قراءة الملف', 'The file could not be read')));
    reader.onload = async () => {
      let url = String(reader.result);
      let mime = file.type;
      if (isImage && file.type !== 'image/gif' && file.size > IMAGE_SHRINK_ABOVE) {
        const smaller = await shrinkImage(url);
        if (smaller && dataUrlBytes(smaller) < file.size) { url = smaller; mime = 'image/jpeg'; }
      }
      const size = url === String(reader.result) ? file.size : dataUrlBytes(url);
      if (size > FILE_MAX_BYTES) { tooLarge(); return; }
      resolve({ fileName: file.name, mime, base64: url, size });
    };
    reader.readAsDataURL(file);
  });
}

// ---------------------------------------------------------------- query-string state
const TABS = ['directory', 'org', 'due', 'contracts', 'claims'];
const pruned = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v != null && v !== ''));

/**
 * Everything the page shows lives in the query string of /hr, so every view, filter and open drawer is a link:
 *   tab=directory|org|due|contracts|claims · page · q
 *   directory: status · department · source · sort · order          org: dept=<id>
 *   due: view=alerts|register · stage · category · kind · due · dstatus
 *   contracts: state · ending=60                                     claims: scope=mine|review|all · state · from · to · source
 *   emp=<code> opens the employee card (etab=work|personal|contracts|documents) · claim=<number> the claim · contract=<number> the contract
 */
export function useHrNav() {
  const route = useRoute();
  const router = useRouter();
  const get = (k) => { const v = route.query[k]; return (Array.isArray(v) ? v[0] : v) || ''; };
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  /** Changes some keys and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return router.replace({ path: route.path, query: next });
  }
  /** Opens a tab with its own filters; what belonged to the previous tab (filters, page) is dropped, an open drawer stays. */
  const go = (tab, params = {}) => router.replace({ path: route.path, query: pruned({ tab, ...params, emp: get('emp'), etab: get('etab'), claim: get('claim'), contract: get('contract') }) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const openEmployee = (code, etab = null) => patch({ emp: code, etab, claim: null, contract: null });
  const openClaim = (number) => patch({ claim: number, emp: null, etab: null, contract: null });
  const openContract = (number) => patch({ contract: number, claim: null });
  return { route, get, page, patch, go, setFilter, setPage, openEmployee, openClaim, openContract, TABS };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a filter that clears the key refills the box.
 *   const { text, type, apply } = useSearchParam(nav);   <TextInput :model-value="text" @update:model-value="type" @enter="apply" />
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
 * What the forms pick from — ONE request shared by every form through the query cache (5 minutes), asked only while
 * `enabled` is true (an open drawer, a tab that shows a selector). Each list is there only for who may use it.
 */
export function useHrLookups(enabled = () => true) {
  const q = useGet('/hr/lookups', undefined, { enabled, staleTime: 300_000 });
  const d = computed(() => q.data.value || {});
  const list = (key) => computed(() => d.value[key] || NONE);
  const named = (key) => computed(() => (d.value[key] || NONE).map((x) => [x.id, pname(x)]));
  return {
    query: q, data: d,
    departments: list('departments'), jobs: list('jobs'), employees: list('employees'), categories: list('categories'), users: list('users'), drivers: list('drivers'),
    accounts: list('accounts'), banks: list('banks'), costCenters: list('costCenters'), documentKinds: list('documentKinds'),
    departmentOptions: named('departments'), jobOptions: named('jobs'),
    employeeOptions: computed(() => (d.value.employees || NONE).map((e) => [e.id, `${pname(e)} · ${e.code}`])),
    categoryOptions: computed(() => (d.value.categories || NONE).map((c) => [c.id, pname(c)])),
    accountOptions: computed(() => (d.value.accounts || NONE).map((a) => [a.code, `${a.code} · ${pname(a)}`])),
    bankOptions: computed(() => (d.value.banks || NONE).map((b) => [b.code, `${pname(b)} · ${b.code}`])),
    costCenterOptions: computed(() => (d.value.costCenters || NONE).map((c) => [c.code, `${c.code} · ${pname(c)}`])),
    kindOptions: computed(() => (d.value.documentKinds || NONE).map((k) => [k.key, { ar: k.ar, en: k.en }])),
  };
}

/** Only the keys of `now` that differ from `before` — what a PATCH sends ('' counts as empty and travels as null). */
export function changedOnly(before, now) {
  const out = {};
  for (const [k, v] of Object.entries(now)) {
    const a = before?.[k] ?? null;
    const b = v === '' || v === undefined ? null : v;
    if (String(a ?? '') !== String(b ?? '')) out[k] = b;
  }
  return out;
}
