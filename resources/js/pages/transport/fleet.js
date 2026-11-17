// Fleet helpers added with the 2026-11 operational hardening: labels of the new states, the trip cost sheet's sources,
// and the small pure helpers the edit / correction forms share. Composables that load data live next to their forms.
import { fmtMoney, fmtNum } from '@/i18n';

/** Maintenance order states, `cancelled` included (the older MAINT_STATUS_LABELS in ./tms.js predates it). */
export const MAINT_STATE_LABELS = {
  open: { ar: 'مفتوح', en: 'Open', fg: '#b26a16', bg: '#fbf0dd' }, closed: { ar: 'مقفل', en: 'Closed', fg: '#1d7a3e', bg: '#e6f9ec' }, cancelled: { ar: 'ملغى', en: 'Cancelled', fg: '#7d7990', bg: '#F1EFF6' },
};
export const MAINT_STATUS_PILLS = [['open', { ar: 'مفتوحة', en: 'Open' }], ['closed', { ar: 'مقفلة', en: 'Closed' }], ['cancelled', { ar: 'ملغاة', en: 'Cancelled' }], ['', { ar: 'الكل', en: 'All' }]];
export const FUEL_STATUS_PILLS = [['active', { ar: 'السارية', en: 'Live' }], ['voided', { ar: 'الملغاة', en: 'Voided' }], ['', { ar: 'الكل', en: 'All' }]];

// ───────────────────────────────────────────── trip cost sheet (trip.cost.basis)
/** Where a figure of the cost sheet comes from. */
export const COST_SOURCE_LABELS = {
  actual: { ar: 'فعلي', en: 'Actual', fg: '#1d7a3e', bg: '#e6f9ec' }, estimated: { ar: 'تقديري', en: 'Estimated', fg: '#b26a16', bg: '#fbf0dd' },
  allocated: { ar: 'موزَّع بالكيلومتر', en: 'Allocated per km', fg: '#3C79F5', bg: '#e8effe' }, not_configured: { ar: 'غير مُعدّ', en: 'Not configured', fg: '#7d7990', bg: '#F1EFF6' },
};
export const COST_ROWS = [
  ['fuel', { ar: 'وقود', en: 'Fuel' }], ['driver', { ar: 'وقت السائق', en: 'Driver time' }], ['ot', { ar: 'وقت إضافي', en: 'Overtime' }], ['maint', { ar: 'صيانة', en: 'Maintenance' }],
  ['dep', { ar: 'إهلاك المركبة', en: 'Depreciation' }], ['tolls', { ar: 'رسوم طرق ومصاريف أسطول', en: 'Tolls & fleet costs' }], ['parking', { ar: 'مواقف', en: 'Parking' }],
  ['third', { ar: 'نقل طرف ثالث', en: 'Third-party' }], ['other', { ar: 'سندات أخرى', en: 'Other vouchers' }],
];
/** Why a component is 0 (`basis.detail.reason`), per component where the remedy differs. */
const REASONS = {
  no_vehicle: { ar: 'الرحلة بلا مركبة', en: 'No vehicle on the trip' },
  no_driver: { ar: 'الرحلة بلا سائق', en: 'No driver on the trip' },
  no_km: { ar: 'مسافة الرحلة غير مسجلة (كم = 0)', en: 'The trip has no distance (km = 0)' },
  no_times: { ar: 'لا يوجد وقت انطلاق وعودة فعليان بعد', en: 'No actual start and end time yet' },
  no_history: { ar: 'لا وقود مسجل على الرحلة، وللمركبة أقل من تعبئتين لتقدير استهلاكها', en: 'No fuel on the trip and the vehicle has fewer than two fills to estimate from' },
  no_account_role: { ar: 'لا حساب مستقل لها في الدليل', en: 'No separate account in the chart' },
};
const NO_RATE = {
  driver: { ar: 'لم تُحدَّد تكلفة ساعة السائق (بطاقة السائق أو سياسة fleet.driverHourlyCost)', en: 'No hourly cost (driver card or policy fleet.driverHourlyCost)' },
  ot: { ar: 'لم تُحدَّد تكلفة ساعة السائق', en: 'No hourly cost for the driver' },
  maint: { ar: 'سجل صيانة المركبة أقل من 500 كم، وسياسة fleet.maintCostPerKm = 0', en: 'Under 500 km of vehicle history and policy fleet.maintCostPerKm = 0' },
  dep: { ar: 'لا تكلفة شراء وعمر بالكيلومتر على بطاقة المركبة، وسياسة fleet.depreciationPerKm = 0', en: 'No purchase cost / life km on the vehicle card and policy fleet.depreciationPerKm = 0' },
};
const INCLUDED = { tolls: { ar: 'تظهر ضمن «رسوم طرق ومصاريف أسطول»', en: 'Shown under "Tolls & fleet costs"' }, other: { ar: 'تظهر ضمن «سندات أخرى»', en: 'Shown under "Other vouchers"' } };

/** One readable line for a component's basis, e.g. "8 h × 30 SAR" — built only from what the server stored. @returns {{ar: string, en: string}} */
export function costBasisText(key, basis) {
  const d = basis?.detail || {};
  const src = basis?.source;
  if (!src) return { ar: '', en: '' };
  if (src === 'not_configured') {
    if (d.reason === 'no_rate') return NO_RATE[key] || NO_RATE.driver;
    if (d.reason === 'no_account_role') { const inc = INCLUDED[d.includedIn]; return { ar: `${REASONS.no_account_role.ar}${inc ? ` — ${inc.ar}` : ''}`, en: `${REASONS.no_account_role.en}${inc ? ` — ${inc.en}` : ''}` }; }
    return REASONS[d.reason] || { ar: '', en: '' };
  }
  const km = fmtNum(d.km, 0);
  const rate = fmtNum(d.ratePerKm, 3);
  if (key === 'fuel') {
    return src === 'actual'
      ? { ar: `${fmtNum(d.records)} سجل وقود على الرحلة · ${fmtNum(d.liters, 1)} لتر`, en: `${fmtNum(d.records)} fuel record(s) on the trip · ${fmtNum(d.liters, 1)} L` }
      : { ar: `${km} كم ÷ ${fmtNum(d.kmPerL, 1)} كم/ل × ${fmtNum(d.pricePerL, 2)} ر.س/ل (${d.kmPerLSource === 'records' ? 'من تعبئات المركبة' : 'متوسط بطاقة المركبة'})`, en: `${km} km ÷ ${fmtNum(d.kmPerL, 1)} km/L × ${fmtNum(d.pricePerL, 2)} SAR/L (${d.kmPerLSource === 'records' ? 'from the vehicle\'s fills' : 'vehicle card average'})` };
  }
  if (key === 'driver') {
    const from = d.rateSource === 'driver' ? { ar: 'بطاقة السائق', en: 'driver card' } : { ar: 'سياسة الأسطول', en: 'fleet policy' };
    return { ar: `${fmtNum(d.hours, 2)} س × ${fmtMoney(d.rate)} ر.س (${from.ar}) — مدة الرحلة ${fmtNum(d.tripHours, 2)} س`, en: `${fmtNum(d.hours, 2)} h × ${fmtMoney(d.rate)} SAR (${from.en}) — trip took ${fmtNum(d.tripHours, 2)} h` };
  }
  if (key === 'ot') {
    return Number(d.hours) > 0
      ? { ar: `${fmtNum(d.hours, 2)} س بعد ${fmtNum(d.afterHours, 1)} س × ${fmtMoney(d.rate)} × ${fmtNum(d.multiplier, 2)}`, en: `${fmtNum(d.hours, 2)} h beyond ${fmtNum(d.afterHours, 1)} h × ${fmtMoney(d.rate)} × ${fmtNum(d.multiplier, 2)}` }
      : { ar: `لا وقت إضافي — الرحلة ضمن ${fmtNum(d.afterHours, 1)} س`, en: `No overtime — within ${fmtNum(d.afterHours, 1)} h` };
  }
  if (key === 'maint') {
    const direct = Number(d.direct) > 0 ? { ar: `سندات صيانة على الرحلة ${fmtMoney(d.direct)}`, en: `maintenance vouchers on the trip ${fmtMoney(d.direct)}` } : null;
    const share = src === 'allocated'
      ? d.rateSource === 'history'
        ? { ar: `${km} كم × ${rate} (صيانة المركبة المقفلة ${fmtMoney(d.historyCost)} ر.س ÷ ${fmtNum(d.historyKm)} كم خلال ${fmtNum(d.historyDays)} يومًا)`, en: `${km} km × ${rate} (closed maintenance ${fmtMoney(d.historyCost)} SAR ÷ ${fmtNum(d.historyKm)} km in ${fmtNum(d.historyDays)} days)` }
        : { ar: `${km} كم × ${rate} (سياسة fleet.maintCostPerKm)`, en: `${km} km × ${rate} (policy fleet.maintCostPerKm)` }
      : null;
    const parts = [direct, share].filter(Boolean);
    return { ar: parts.map((p) => p.ar).join(' + '), en: parts.map((p) => p.en).join(' + ') };
  }
  if (key === 'dep') {
    return d.rateSource === 'vehicle'
      ? { ar: `${km} كم × ${rate} = (${fmtMoney(d.purchaseCost)} − ${fmtMoney(d.salvageValue)}) ÷ ${fmtNum(d.lifeKm)} كم`, en: `${km} km × ${rate} = (${fmtMoney(d.purchaseCost)} − ${fmtMoney(d.salvageValue)}) ÷ ${fmtNum(d.lifeKm)} km` }
      : { ar: `${km} كم × ${rate} (سياسة fleet.depreciationPerKm)`, en: `${km} km × ${rate} (policy fleet.depreciationPerKm)` };
  }
  return { ar: `${fmtNum(d.vouchers)} سند مصروف مرحَّل على الرحلة`, en: `${fmtNum(d.vouchers)} posted expense voucher(s) on the trip` };
}

// ───────────────────────────────────────────── edit / correction forms
/** `YYYY-MM-DD` of an API date (documents are stored at UTC midnight) — what a DateInput expects. */
export const isoDay = (d) => (d ? String(d).slice(0, 10) : '');
/** Number of an API decimal for a NumberInput (null stays empty). */
export const numOrEmpty = (v) => (v == null || v === '' || Number.isNaN(Number(v)) ? undefined : Number(v));

/**
 * Body of a PATCH from a FormDrawer submit: only the keys whose value differs from `initial`. FormDrawer drops empty
 * values, so a field the user emptied arrives missing — it is sent as null when it is listed in `nullable`, otherwise
 * left alone (a mandatory field cannot be emptied).
 */
export function patchBody(values, initial, keys, nullable = []) {
  const body = {};
  for (const k of keys) {
    const was = initial?.[k] ?? '';
    const now = values[k] ?? '';
    if (String(now) === String(was)) continue;
    if (now === '') { if (nullable.includes(k)) body[k] = null; continue; }
    body[k] = now;
  }
  return body;
}

/** Driver document rows: what a lapse does (`effect` from the API: blocks | warns). */
export const DOC_EFFECT_LABELS = { blocks: { ar: 'انتهاؤها يوقف الإسناد', en: 'Lapse stops assignment' }, warns: { ar: 'تنبيه فقط', en: 'Warning only' } };
