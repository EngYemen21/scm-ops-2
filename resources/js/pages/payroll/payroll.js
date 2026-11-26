// «الرواتب والإجازات والحضور» / Payroll, time off, attendance — what the page and its parts share: label maps (with
// chip colours), small formats, the query-string state, the summary and lookups, and the authenticated CSV download.
//
// API (routes/api/payroll.php — camelCase JSON, decimals arrive as strings, days as `YYYY-MM-DD`, moments as ISO UTC):
//   GET  /payroll/summary · /payroll/lookups                                   each caller gets their own part
//   GET  /payroll/leaves ?scope=mine|team|waiting|visible|all&state&type&employee&department&from&to&source&q&sort&order&page
//   GET  /payroll/leaves/calendar ?from&to&department · /payroll/leaves/{id} · /payroll/leave-balances ?employee&year
//   GET  /payroll/leave-entries ?employee&type&year&kind · /payroll/leave-ledger/reconcile
//   POST /payroll/leaves · PATCH /payroll/leaves/{id} · POST …/{submit,withdraw,cancel,approve,refuse}
//   POST /payroll/leave-allocations · /payroll/leave-allocations/yearly
//   GET  /payroll/attendance ?scope=mine|all · /attendance/me · /attendance/daily ?date · /attendance/{id}
//   POST /payroll/attendance/check-in|check-out · POST /attendance · PATCH /attendance/{id} · POST /attendance/{id}/void
//   GET  /payroll/sheets · /sheets/{id} · POST /sheets · …/{recompute,confirm,approve,reopen} · PATCH /sheets/{id}/lines/{line}
//   GET  /payroll/sheet-batches · /sheet-batches/{id} · POST /sheet-batches · …/{generate,submit,close}
//   GET  /payroll/runs · /runs/{id} · /runs/{id}/journal · /runs/{id}/transfer · /runs/{id}/transfer.csv
//   POST /payroll/runs · …/{compute,submit,return,cancel,approve,post,pay,unpay,reverse}
//   GET  /payroll/payslips · /payslips/{id} · /adjustments · POST /adjustments · /adjustments/{id}/cancel
//   GET  /payroll/schedules · holidays · leave-types · policies · components · structures[/{id}] · gosi-rates · parameters · accounts · profiles
//   POST|PATCH the same (PUT accounts, PUT profiles/{employee}, DELETE gosi-rates/{id}, DELETE parameters/{id})
//   GET  /payroll/skills/catalog · /skills/employees[/{id|me}] · POST|DELETE …/skills · POST|PATCH|DELETE …/resume · POST|PATCH /skills/{kind}
// Bank account numbers come ONLY from GET runs/{id}/transfer (and its CSV), fetched on an explicit press, kept in a
// component ref while its drawer is open — never in the URL, the query cache or the console.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { API_BASE, ApiError, refreshAccess, tokens, useGet } from '@/api/client';
import { bi, fmtDate, fmtDateOnly, fmtMoney, fmtTime, getLang, lang, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';

const NONE = [];
/** `map[key]` for a key that comes from the server: only the map's own entries count. */
export const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- tones and labels
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#d9f4f9' },
  neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};

export const RUN_STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  review: { ar: 'بانتظار الاعتماد', en: 'In review', ...TONE.warn },
  approved: { ar: 'معتمد', en: 'Approved', ...TONE.blue },
  posted: { ar: 'مرحَّل للدفاتر', en: 'Posted', ...TONE.teal },
  paid: { ar: 'مصروف ✓', en: 'Paid ✓', ...TONE.ok },
  reversed: { ar: 'معكوس', en: 'Reversed', ...TONE.bad },
  cancelled: { ar: 'ملغى', en: 'Cancelled', ...TONE.off },
};
export const LEAVE_STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  submitted: { ar: 'بانتظار الاعتماد', en: 'Waiting', ...TONE.warn },
  manager_approved: { ar: 'اعتمده المدير', en: 'Manager approved', ...TONE.violet },
  approved: { ar: 'معتمدة', en: 'Approved', ...TONE.ok },
  refused: { ar: 'مرفوضة', en: 'Refused', ...TONE.bad },
  cancelled: { ar: 'ملغاة', en: 'Cancelled', ...TONE.off },
};
export const SHEET_STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  confirmed: { ar: 'مؤكَّد', en: 'Confirmed', ...TONE.blue },
  approved: { ar: 'معتمد', en: 'Approved', ...TONE.ok },
};
export const BATCH_STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  generated: { ar: 'أُنشئت الكشوف', en: 'Generated', ...TONE.blue },
  submitted: { ar: 'أُكِّدت — بانتظار الاعتماد', en: 'Submitted', ...TONE.warn },
  closed: { ar: 'مغلقة ✓', en: 'Closed ✓', ...TONE.ok },
};
export const ADJ_STATE = {
  pending: { ar: 'بانتظار المسير', en: 'Pending', ...TONE.warn },
  applied: { ar: 'دخلت قسيمة معتمدة', en: 'Applied', ...TONE.ok },
  cancelled: { ar: 'ملغاة', en: 'Cancelled', ...TONE.off },
};
/** An attendance record: `void`, `open` (no check-out yet) or `ok`. */
export const ATT_STATE = {
  ok: { ar: 'مكتمل', en: 'Complete', ...TONE.ok },
  open: { ar: 'بلا انصراف', en: 'Open', ...TONE.warn },
  void: { ar: 'ملغى', en: 'Void', ...TONE.bad },
};
export const attState = (a) => (a?.status === 'void' ? 'void' : a?.open ? 'open' : 'ok');
export const CORRECTED_CHIP = { ar: 'مُصحَّح', en: 'Corrected', ...TONE.violet };
export const IN_SHEET_CHIP = { ar: 'داخل كشف مؤكَّد', en: 'In a confirmed sheet', ...TONE.teal };
export const FROM_ODOO = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const DEFAULT_CHIP = { ar: 'افتراضي', en: 'Default', ...TONE.blue };
export const INACTIVE_CHIP = { ar: 'موقوف', en: 'Inactive', ...TONE.off };
export const CURRENT_CHIP = { ar: 'السارية الآن', en: 'Current', ...TONE.ok };
export const IN_USE_CHIP = { ar: 'حُسبت بها رواتب معتمدة', en: 'Used by approved pay', ...TONE.neutral };

/** The daily board: where each person stands on the day. */
export const DAILY_STATE = {
  present: { ar: 'حاضر', en: 'Present', ...TONE.ok },
  open: { ar: 'داخل الآن', en: 'In now', ...TONE.blue },
  absent: { ar: 'غائب', en: 'Absent', ...TONE.bad },
  expected: { ar: 'متوقَّع', en: 'Expected', ...TONE.neutral },
  weekend: { ar: 'عطلة أسبوعية', en: 'Weekend', ...TONE.off },
  holiday: { ar: 'عطلة رسمية', en: 'Public holiday', ...TONE.teal },
  leave: { ar: 'في إجازة', en: 'On leave', ...TONE.violet },
  off: { ar: 'خارج مدة العقد', en: 'Outside the contract', ...TONE.off },
};
/** A day of an attendance sheet (`line.status`; null = a working day). */
export const LINE_STATUS = {
  work: { ar: 'دوام', en: 'Work', ...TONE.ok },
  absence: { ar: 'غياب', en: 'Absence', ...TONE.bad },
  weekend: { ar: 'عطلة أسبوعية', en: 'Weekend', ...TONE.off },
  holiday: { ar: 'عطلة رسمية', en: 'Holiday', ...TONE.teal },
  leave: { ar: 'إجازة', en: 'Leave', ...TONE.violet },
};
export const lineStatus = (l) => (l?.status && own(LINE_STATUS, l.status) ? l.status : 'work');

export const ENTRY_KIND = {
  allocation: { ar: 'منح', en: 'Granted', ...TONE.ok },
  taken: { ar: 'مأخوذ بإجازة', en: 'Taken', ...TONE.bad },
  restored: { ar: 'أُعيد بإلغاء', en: 'Restored', ...TONE.blue },
  adjustment: { ar: 'تسوية', en: 'Adjustment', ...TONE.warn },
};
export const APPROVAL = {
  none: { ar: 'بلا اعتماد', en: 'No approval' }, manager: { ar: 'المدير المباشر', en: 'Direct manager' },
  hr: { ar: 'الموارد البشرية', en: 'HR' }, both: { ar: 'المدير ثم الموارد البشرية', en: 'Manager, then HR' },
};
export const PAY_METHOD = { bank: { ar: 'تحويل بنكي', en: 'Bank transfer', ...TONE.blue }, cash: { ar: 'نقدًا', en: 'Cash', ...TONE.warn } };
export const ADJ_KIND = { addition: { ar: 'إضافة', en: 'Addition', ...TONE.ok }, deduction: { ar: 'خصم', en: 'Deduction', ...TONE.bad } };
export const COMPONENT_CATEGORY = {
  basic: { ar: 'أساسي', en: 'Basic', ...TONE.blue }, allowance: { ar: 'بدل / إضافة', en: 'Allowance', ...TONE.ok }, deduction: { ar: 'خصم', en: 'Deduction', ...TONE.bad },
  company: { ar: 'حصة المنشأة', en: 'Employer share', ...TONE.violet }, reimbursement: { ar: 'تعويض مصروف', en: 'Reimbursement', ...TONE.teal },
  gross: { ar: 'الإجمالي', en: 'Gross', ...TONE.neutral }, net: { ar: 'الصافي', en: 'Net', ...TONE.neutral },
};
export const COMPONENT_CALC = {
  contract: { ar: 'من العقد', en: 'From the contract' }, overtime: { ar: 'العمل الإضافي', en: 'Overtime' }, late: { ar: 'خصم التأخير', en: 'Lateness' },
  early: { ar: 'خصم الخروج المبكر', en: 'Early leave' }, absence: { ar: 'خصم الغياب', en: 'Absence' }, unpaid_leave: { ar: 'إجازة غير مدفوعة', en: 'Unpaid leave' },
  gosi_employee: { ar: 'تأمينات — حصة الموظف', en: 'GOSI — employee' }, gosi_employer: { ar: 'تأمينات — حصة المنشأة', en: 'GOSI — employer' },
  claims: { ar: 'مطالبات المصروفات', en: 'Expense claims' }, input: { ar: 'إدخال يدوي (تسوية)', en: 'Manual input (adjustment)' },
};
/** The posting map keys (RunPosting::KEYS) — the server sends their names too; this is for the components form. */
export const ACCOUNT_KEYS = ['salaries', 'allowances', 'overtime', 'gosi_expense', 'payable', 'gosi_payable', 'deductions', 'reimbursements'];
export const ACCOUNT_SIDE = { expense: { ar: 'مصروف', en: 'Expense', ...TONE.warn }, liability: { ar: 'التزام', en: 'Liability', ...TONE.blue } };
export const HOLIDAY_SCOPE = { company: { ar: 'الشركة كلها', en: 'Whole company' }, department: { ar: 'أقسام محددة', en: 'Some departments' }, employee: { ar: 'موظفون محددون', en: 'Some employees' } };
export const DAY_TYPE = { workday: { ar: 'أيام العمل', en: 'Working days' }, weekend: { ar: 'العطلة الأسبوعية', en: 'Weekend' }, holiday: { ar: 'العطل الرسمية', en: 'Public holidays' } };
export const RULE_TYPE = { rate: { ar: 'نسبة من الوقت', en: 'Rate of the time' }, fix: { ar: 'مدة ثابتة', en: 'Fixed time' } };
/** 0 = Monday … 6 = Sunday (the server's weekday numbering). */
export const WEEKDAYS = [
  { ar: 'الاثنين', en: 'Monday' }, { ar: 'الثلاثاء', en: 'Tuesday' }, { ar: 'الأربعاء', en: 'Wednesday' }, { ar: 'الخميس', en: 'Thursday' },
  { ar: 'الجمعة', en: 'Friday' }, { ar: 'السبت', en: 'Saturday' }, { ar: 'الأحد', en: 'Sunday' },
];
export const weekdayName = (i) => bi(WEEKDAYS[Number(i)] || { ar: String(i), en: String(i) });

/** Why a contract was left out of a run, or an employee out of a batch (`skipped[].reason`). */
export const SKIP_REASON = {
  ALREADY_PAID: { ar: 'هذا الشهر مدفوع لهذا العقد في مسير آخر', en: 'This month is already paid to the contract by another run' },
  OTHER_CURRENCY: { ar: 'عملة العقد غير عملة المسير', en: 'The contract is in another currency than the run' },
  PAYROLL_NOT_IN_PERIOD: { ar: 'العقد غير سارٍ في هذا الشهر', en: 'The contract is not in force this month' },
  PAYROLL_GOSI_RATE_MISSING: { ar: 'لا نسب تأمينات سارية لنظام هيكل راتبه — أضِفها في الإعدادات', en: 'No social-insurance rate is in force for the scheme of the pay structure — add it in the settings' },
  NO_CONTRACT: { ar: 'لا عقد سارٍ في المدة', en: 'No contract in force in the period' },
  HAS_SHEET: { ar: 'له كشف حضور بالفعل في المدة', en: 'Already has a sheet in the period' },
};
export const skipText = (code) => bi(own(SKIP_REASON, code) || { ar: `سبب: ${code}`, en: `Reason: ${code}` });
/** What a run or a payslip warns about (`warnings[].code` / `warnings[]`). */
export const WARNING = {
  SHEET_NOT_APPROVED: { ar: 'كشف حضور الشهر لم يُعتمد بعد — أرقامه ليست في القسيمة', en: "The month's attendance sheet is not approved — its figures are not in the payslip" },
  NO_BANK_ACCOUNT: { ar: 'يُصرف بالتحويل ولا حساب بنكي في ملفه', en: 'Paid by transfer but has no bank account on file' },
  DEDUCTION_CAPPED: { ar: 'خُصم ما يتّسع له الأجر فقط (لا يتجاوز الخصم ما استُحق)', en: 'Deductions were capped at what was earned' },
  NEGATIVE_NET: { ar: 'الصافي سالب', en: 'Negative net' },
};
export const warningText = (code) => bi(own(WARNING, code) || { ar: code, en: code });

/** Plain words for a parameter's choice values. */
export const CHOICE_WORDS = { gross: { ar: 'الإجمالي (الأساسي والبدلات)', en: 'Gross (basic and allowances)' }, basic: { ar: 'الأساسي فقط', en: 'Basic only' } };
export const choiceText = (v) => bi(own(CHOICE_WORDS, v) || { ar: String(v ?? '—'), en: String(v ?? '—') });

/** Twelve colours of the leave types (`type.color` 0..11). */
const PALETTE = [
  TONE.neutral, { fg: '#b23b3b', bg: '#fdecec' }, { fg: '#b26a16', bg: '#fbf0dd' }, { fg: '#8a6d00', bg: '#fbf6d0' }, { fg: '#3C79F5', bg: '#e8effe' }, { fg: '#8a3d6b', bg: '#f8e6f0' },
  { fg: '#b05a3a', bg: '#fbe9e1' }, { fg: '#0d7f93', bg: '#e4f8fb' }, { fg: '#3b4a9c', bg: '#e6e9fb' }, { fg: '#a13a5a', bg: '#fbe4ea' }, { fg: '#1d7a3e', bg: '#e6f9ec' }, { fg: '#654e92', bg: '#efeaf8' },
];
export const typeColor = (i) => PALETTE[Math.abs(Number(i) || 0) % PALETTE.length] || TONE.neutral;
export const PALETTE_SIZE = PALETTE.length;

// ---------------------------------------------------------------- formats
/** Name of a record with nameAr / nameEn in the language on screen. */
export const pname = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || '—' : '—');
/** A calendar day `YYYY-MM-DD` shown as the same day wherever the browser is. */
export const fmtDay = (v) => (v ? fmtDateOnly(String(v).slice(0, 10).replace(/-/g, '/')) : '—');
export const fmtAt = (v) => (v ? fmtDate(v) : '—');
export const fmtClock = (v) => (v ? fmtTime(v) : '—');
/** Money as the API sends it (a decimal string). */
export const money = (v) => (v == null || v === '' ? '—' : fmtMoney(num(v)));
/** Minutes as hours:minutes (`7:05`); `—` for nothing. */
export function hm(minutes, dash = true) {
  const m = Math.round(Number(minutes) || 0);
  if (!m && dash) return '—';
  const sign = m < 0 ? '-' : '';
  const a = Math.abs(m);
  return `${sign}${Math.floor(a / 60)}:${String(a % 60).padStart(2, '0')}`;
}
export const days = (n) => (n == null ? '—' : String(Number(Number(n).toFixed(2))));
const p2 = (n) => String(n).padStart(2, '0');
/** Today / this month in the browser's own calendar. */
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`; };
export const monthIso = (d = new Date()) => `${d.getFullYear()}-${p2(d.getMonth() + 1)}`;
export const isPeriod = (v) => /^\d{4}-(0[1-9]|1[0-2])$/.test(String(v || ''));
/** `2026-11` → `11/2026`. */
export const fmtPeriod = (p) => (isPeriod(p) ? `${p.slice(5, 7)}/${p.slice(0, 4)}` : p || '—');
/** A moment (ISO) as the value of a `datetime-local` input, in the browser's clock. */
export function toLocalInput(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}T${p2(d.getHours())}:${p2(d.getMinutes())}`;
}
/** The value of a `datetime-local` input (the browser's clock) as an ISO moment the server reads without guessing a zone. */
export function fromLocalInput(v) {
  if (!v) return null;
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}
/** Years a filter offers: the current one, two before and one after. */
export function yearOptions(around = new Date().getFullYear()) {
  return [around + 1, around, around - 1, around - 2].map((y) => [String(y), String(y)]);
}
/** `[value, {ar,en}]` options of a label map. */
export const optionsOf = (map) => Object.entries(map).map(([v, l]) => [v, { ar: l.ar, en: l.en }]);
export const YES_NO = [['1', { ar: 'نعم', en: 'Yes' }], ['0', { ar: 'لا', en: 'No' }]];
export const yesNo = (b) => (b ? t('نعم', 'Yes') : t('لا', 'No'));
export const bool = (v) => v === true || v === '1' || v === 1;
export const SELECT = { ar: '— اختر —', en: '— select —' };
export const ANY = { ar: 'الكل', en: 'All' };

/**
 * One line of a payslip in plain words (`line.explain.rule`), or null for the totals.
 */
export function explainLine(l) {
  const e = l?.explain || {};
  const ar = lang.value === 'ar';
  const base = e.base === 'basic' ? t('الأساسي', 'the basic pay') : t('الإجمالي', 'the gross pay');
  switch (e.rule) {
    case 'contract':
      return Number(e.daysCovered) === Number(e.daysInMonth)
        ? t(`المبلغ الشهري في العقد ${money(e.monthly)} — الشهر كاملًا`, `Monthly amount of the contract ${money(e.monthly)} — the whole month`)
        : t(`المبلغ الشهري ${money(e.monthly)} × ${e.daysCovered} يومًا من ${e.daysInMonth} (أيام العقد في الشهر)`, `Monthly ${money(e.monthly)} × ${e.daysCovered} of ${e.daysInMonth} days (days of the contract in the month)`);
    case 'unpaid_leave':
      return t(`${days(e.days)} يوم إجازة غير مدفوعة × أجر اليوم (${base} ${money(e.baseAmount)} ÷ ${e.monthDays})`, `${days(e.days)} day(s) of unpaid leave × the daily pay (${base} ${money(e.baseAmount)} ÷ ${e.monthDays})`)
        + (e.leaves?.length ? ` · ${e.leaves.join('، ')}` : '') + (e.capped ? t(' — خُصم ما يتّسع له الأجر', ' — capped at what was earned') : '');
    case 'overtime': case 'late': case 'early': case 'absence': {
      const what = { overtime: ['وقت إضافي', 'overtime'], late: ['تأخير', 'lateness'], early: ['خروج مبكر', 'early leave'], absence: ['غياب', 'absence'] }[e.rule];
      const hours = Number(e.minutesPerDay) ? Number((Number(e.minutesPerDay) / 60).toFixed(2)) : '—';
      const s = ar
        ? `${hm(e.minutes, false)} ساعة ${what[0]}${e.rule === 'absence' && e.days ? ` (${e.days} يوم)` : ''} × أجر الساعة (${base} ${money(e.baseAmount)} ÷ ${e.monthDays} يومًا ÷ ${hours} ساعة)`
        : `${hm(e.minutes, false)} h of ${what[1]}${e.rule === 'absence' && e.days ? ` (${e.days} day(s))` : ''} × the hourly pay (${base} ${money(e.baseAmount)} ÷ ${e.monthDays} days ÷ ${hours} h)`;
      return s + (e.sheets?.length ? ` · ${t('كشوف', 'sheets')} ${e.sheets.join('، ')}` : '') + (e.capped ? t(' — خُصم ما يتّسع له الأجر', ' — capped at what was earned') : '');
    }
    case 'adjustment':
      return t('تسوية أُدخلت لهذا الشهر (إضافة أو خصم بسببها)', 'An adjustment entered for this month (an addition or a deduction with its reason)');
    case 'gosi_employee': case 'gosi_employer': {
      const pct = e.rule === 'gosi_employee' ? e.employeePct : e.employerPct;
      return t(`${pct}% من وعاء التأمينات ${money(e.base)} (نظام ${e.scheme}، النسبة سارية من ${fmtDay(e.effectiveFrom)})`, `${pct}% of the social-insurance base ${money(e.base)} (scheme ${e.scheme}, rate in force from ${fmtDay(e.effectiveFrom)})`)
        + (num(e.base) !== num(e.contractBase) ? t(` — وعاء العقد ${money(e.contractBase)} بعد الحدّين الأدنى والأعلى والتناسب`, ` — contract base ${money(e.contractBase)} after the floor, the ceiling and proration`) : '');
    }
    case 'claims':
      return t(`تعويض مطالبة المصروفات ${e.claim || ''}`, `Reimbursement of expense claim ${e.claim || ''}`);
    default:
      return null;
  }
}

// ---------------------------------------------------------------- query-string state
const RECORDS = ['leave', 'att', 'sheet', 'batch', 'run', 'slip', 'emp'];
const pruned = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v != null && v !== ''));

/**
 * Everything the page shows lives in the query string of /payroll, so every view, filter and open record is a link:
 *   tab=my|leave|attendance|payroll|skills|settings · view (the part of the tab) · set (the settings section)
 *   filters: q · state · type · employee · department · from · to · source · status · year · period · kind · has · skill · date · month · sort · order · page
 *   records: leave=<number> · att=<id> · sheet=<number> · batch=<number> · run=<number> · slip=<number> · emp=<employee id>
 * Bank account numbers are never put here.
 */
export function usePayrollNav() {
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
  const records = () => Object.fromEntries(RECORDS.map((k) => [k, get(k)]));
  /** Opens a tab (and a view of it) with its own filters; what belonged to the previous view is dropped, open records stay. */
  const go = (tab, params = {}) => router.replace({ path: route.path, query: pruned({ ...records(), tab, ...params }) });
  /** Another view of the same tab. */
  const view = (v, params = {}) => go(get('tab') || null, { ...(get('set') && !params.set ? { set: get('set') } : {}), view: v, ...params });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setFilters = (changes) => patch({ ...changes, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const open = (key, value) => patch({ [key]: value });
  const close = (key) => patch({ [key]: null });
  /** `{ key, order }` of the list's sort when the key is one the API sorts by; else the default. */
  const sortOf = (allowed, def = null) => (allowed.includes(get('sort')) ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : 'desc' } : def);
  const setSort = (s) => patch({ sort: s.key, order: s.order, page: null });
  return { route, get, page, patch, go, view, setFilter, setFilters, setPage, open, close, sortOf, setSort };
}

/**
 * A search box bound to a key of the query string: typed text reaches the URL 350 ms after the last key, at once on
 * Enter; a deep link or a cleared filter refills the box.
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

// ---------------------------------------------------------------- summary, permissions, lookups
/** GET /payroll/summary — shared by the page and its tabs through the query cache. */
export function usePayrollSummary() {
  return useGet('/payroll/summary', undefined, { refetchInterval: 120_000 });
}

/**
 * What the caller may do here: the summary's `permissions` once it is in, the login's permissions meanwhile (the
 * server enforces every one of them; this only hides what would be refused).
 */
export function usePayrollPerms() {
  const auth = useAuth();
  const summary = usePayrollSummary();
  return computed(() => {
    const p = summary.data.value?.permissions;
    const from = (key, perm) => (p ? !!p[key] : auth.can(perm));
    const out = {
      payrollManage: from('payrollManage', 'payroll.manage'), payrollApprove: from('payrollApprove', 'payroll.approve'), leaveRequest: from('leaveRequest', 'leave.request'),
      leaveApprove: from('leaveApprove', 'leave.approve'), attendanceManage: from('attendanceManage', 'attendance.manage'), managesTeam: !!p?.managesTeam,
      glPost: auth.can('gl.post'),
    };
    out.pay = out.payrollManage || out.payrollApprove;
    out.allLeave = out.leaveApprove || out.pay;
    out.allAttendance = out.attendanceManage || out.pay;
    out.officer = out.pay || out.leaveApprove || out.attendanceManage;
    out.leaveOfficer = out.leaveApprove || out.payrollManage;
    return out;
  });
}

/**
 * GET /payroll/lookups — one request shared by every form (5 minutes). Each list is there only for who may use it:
 * employees (everyone for an officer, the team for a manager, oneself otherwise), departments, leave types, the year;
 * schedules and policies for officers; structures, input components and bank accounts for the payroll people.
 */
export function usePayrollLookups(enabled = () => true) {
  const q = useGet('/payroll/lookups', undefined, { enabled, staleTime: 300_000 });
  const d = computed(() => q.data.value || {});
  const list = (key) => computed(() => d.value[key] || NONE);
  return {
    query: q, data: d,
    employees: list('employees'), departments: list('departments'), leaveTypes: list('leaveTypes'), schedules: list('schedules'), policies: list('policies'),
    structures: list('structures'), inputComponents: list('inputComponents'), bankAccounts: list('bankAccounts'),
    year: computed(() => d.value.year || new Date().getFullYear()),
    employeeOptions: computed(() => (d.value.employees || NONE).map((e) => [e.id, `${pname(e)} · ${e.code}`])),
    departmentOptions: computed(() => (d.value.departments || NONE).map((x) => [x.id, pname(x)])),
    leaveTypeOptions: computed(() => (d.value.leaveTypes || NONE).map((x) => [x.id, pname(x)])),
    scheduleOptions: computed(() => (d.value.schedules || NONE).map((x) => [x.id, pname(x)])),
    policyOptions: computed(() => (d.value.policies || NONE).map((x) => [x.id, pname(x)])),
    structureOptions: computed(() => (d.value.structures || NONE).map((x) => [x.id, `${pname(x)}${x.code ? ` · ${x.code}` : ''}`])),
    componentOptions: computed(() => (d.value.inputComponents || NONE).map((x) => [x.id, `${pname(x)} · ${x.code} (${bi(own(COMPONENT_CATEGORY, x.category) || { ar: x.category, en: x.category })})`])),
    bankOptions: computed(() => (d.value.bankAccounts || NONE).map((b) => [b.code, `${pname(b)} · ${b.code}`])),
  };
}

// ---------------------------------------------------------------- files
/**
 * GET a file of the API with the bearer token (a bare link cannot carry it) and hand it to the browser as a download.
 * Resolves the file name; throws ApiError on a refusal. An expired token is refreshed once. The bytes are never read.
 */
export async function downloadFile(path, fallbackName) {
  const call = () => fetch(API_BASE + path, { headers: { Accept: 'text/csv, application/json', 'Accept-Language': getLang(), ...(tokens.access ? { Authorization: `Bearer ${tokens.access}` } : {}) }, credentials: 'same-origin', cache: 'no-store' });
  let res;
  try {
    res = await call();
    if (res.status === 401 && (await refreshAccess())) res = await call();
  } catch {
    throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر الاتصال بالخادم', messageEn: 'Cannot reach the server' });
  }
  if (!res.ok) {
    const p = await res.json().catch(() => ({}));
    throw new ApiError({ status: res.status, category: p.category, code: p.code, message: p.message, messageEn: p.messageEn, details: p.details, requestId: p.requestId });
  }
  const blob = await res.blob();
  const cd = res.headers.get('content-disposition') || '';
  const name = /filename="?([^";]+)"?/.exec(cd)?.[1] || fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
  return name;
}
