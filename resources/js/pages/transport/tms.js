// Shared transport (TMS / fleet) labels and pure helpers — the non-visual half of the reference `tms.tsx`.
// Used by TripsPage, TripPage, FleetPage, TransportTowerPage and DriverPage. Composables live in ./tmsComposables.js,
// every drawer / dialog / form / panel is its own .vue file in this folder.
import { bi } from '@/i18n';

// ───────────────────────────────────────────── label maps (prototype dictionaries not in @/shared)
export const TEMP_LABELS = { dry: { ar: 'جاف', en: 'Dry' }, chill: { ar: 'مبرد +4°', en: 'Chilled +4°' }, reefer: { ar: 'مجمد −18°', en: 'Frozen −18°' } };
export const VEHICLE_KIND_LABELS = { dry: { ar: 'جافة', en: 'Dry box' }, chill: { ar: 'مبردة +4°', en: 'Chiller +4°' }, reefer: { ar: 'مبردة −18°/+4°', en: 'Reefer −18°/+4°' } };
export const SHIFT_LABELS = { am: { ar: 'صباحية 06–14', en: 'AM 06–14' }, pm: { ar: 'مسائية 14–22', en: 'PM 14–22' }, flex: { ar: 'مرنة', en: 'Flex' } };
export const DRIVER_STATE_LABELS = {
  available: { ar: 'متاح', en: 'Available', fg: '#1d7a3e', bg: '#e6f9ec' }, loading: { ar: 'في التحميل', en: 'Loading', fg: '#654e92', bg: '#efeaf8' }, onroute: { ar: 'في رحلة', en: 'On trip', fg: '#3C79F5', bg: '#e8effe' },
  off: { ar: 'خارج الوردية', en: 'Off shift', fg: '#7d7990', bg: '#F1EFF6' }, blocked: { ar: 'موقوف — وثائق', en: 'Blocked — docs', fg: '#b23b3b', bg: '#fdecec' }, inactive: { ar: 'غير نشط', en: 'Inactive', fg: '#a8a4b8', bg: '#F1EFF6' },
};
export const MAINT_KIND_LABELS = {
  preventive: { ar: 'وقائية', en: 'Preventive', fg: '#0d7f93', bg: '#d9f4f9' }, corrective: { ar: 'تصحيحية', en: 'Corrective', fg: '#b26a16', bg: '#fbf0dd' }, emergency: { ar: 'طارئة', en: 'Emergency', fg: '#b23b3b', bg: '#fdecec' },
  tire: { ar: 'إطارات', en: 'Tires', fg: '#55506a', bg: '#F1EFF6' }, oil: { ar: 'زيت', en: 'Oil', fg: '#55506a', bg: '#F1EFF6' }, brake: { ar: 'فرامل', en: 'Brakes', fg: '#55506a', bg: '#F1EFF6' }, engine: { ar: 'محرك', en: 'Engine', fg: '#55506a', bg: '#F1EFF6' },
  elec: { ar: 'كهرباء', en: 'Electrical', fg: '#55506a', bg: '#F1EFF6' }, ac: { ar: 'تكييف / تبريد', en: 'AC / Refrigeration', fg: '#55506a', bg: '#F1EFF6' }, body: { ar: 'هيكل', en: 'Body', fg: '#55506a', bg: '#F1EFF6' },
};
export const MAINT_STATUS_LABELS = { open: { ar: 'مفتوح', en: 'Open', fg: '#b26a16', bg: '#fbf0dd' }, closed: { ar: 'مقفل', en: 'Closed', fg: '#1d7a3e', bg: '#e6f9ec' } };
export const OPREQ_TYPE_LABELS = { fuel: { ar: 'وقود', en: 'Fuel' }, maint: { ar: 'صيانة', en: 'Maintenance' }, tire: { ar: 'إطار', en: 'Tire' }, vehicle: { ar: 'مشكلة مركبة', en: 'Vehicle issue' }, toll: { ar: 'رسوم طريق', en: 'Toll' }, parking: { ar: 'مواقف', en: 'Parking' }, emergency: { ar: 'طارئ', en: 'Emergency' }, other: { ar: 'أخرى', en: 'Other' } };
export const OPREQ_STATE_LABELS = {
  submitted: { ar: 'مُرسل', en: 'Submitted', fg: '#b26a16', bg: '#fbf0dd' }, review: { ar: 'قيد المراجعة', en: 'Under review', fg: '#3C79F5', bg: '#e8effe' }, approved: { ar: 'معتمد', en: 'Approved', fg: '#1d7a3e', bg: '#e6f9ec' },
  rejected: { ar: 'مرفوض', en: 'Rejected', fg: '#b23b3b', bg: '#fdecec' }, processed: { ar: 'تمت المعالجة', en: 'Processed', fg: '#654e92', bg: '#efeaf8' }, closed: { ar: 'مغلق', en: 'Closed', fg: '#7d7990', bg: '#F1EFF6' },
};
export const OPREQ_ACTION_LABELS = { review: { ar: 'بدء المراجعة', en: 'Review' }, approved: { ar: 'اعتماد', en: 'Approve' }, rejected: { ar: 'رفض', en: 'Reject' }, processed: { ar: 'معالجة (صرف / تنفيذ)', en: 'Process' }, closed: { ar: 'إغلاق', en: 'Close' } };
export const ALERT_SEV_LABELS = { c: { ar: 'حرج', en: 'Critical', fg: '#b23b3b', bg: '#fdecec' }, w: { ar: 'تحذير', en: 'Warning', fg: '#b26a16', bg: '#fbf0dd' }, i: { ar: 'معلومة', en: 'Info', fg: '#3C79F5', bg: '#e8effe' }, high: { ar: 'حرج', en: 'Critical', fg: '#b23b3b', bg: '#fdecec' }, med: { ar: 'تحذير', en: 'Warning', fg: '#b26a16', bg: '#fbf0dd' }, low: { ar: 'معلومة', en: 'Info', fg: '#3C79F5', bg: '#e8effe' } };
export const ALERT_STATUS_LABELS = { open: { ar: 'مفتوح', en: 'Open', fg: '#b26a16', bg: '#fbf0dd' }, assigned: { ar: 'مُسند', en: 'Assigned', fg: '#3C79F5', bg: '#e8effe' }, snoozed: { ar: 'مؤجل', en: 'Snoozed', fg: '#7d7990', bg: '#F1EFF6' }, resolved: { ar: 'محلول ✓', en: 'Resolved ✓', fg: '#1d7a3e', bg: '#e6f9ec' } };
export const INCIDENT_TYPE_LABELS = { speed: { ar: 'سرعة زائدة', en: 'Speeding' }, harsh: { ar: 'قيادة عنيفة', en: 'Harsh driving' }, accident: { ar: 'حادث', en: 'Accident' }, complaint: { ar: 'شكوى عميل', en: 'Complaint' }, late: { ar: 'تأخر', en: 'Late' }, noshow: { ar: 'غياب', en: 'No-show' } };
/** GPS / ETA / telematics / maps / file upload are not connected — shown exactly like this everywhere. */
export const PENDING = { ar: 'Integration Pending', en: 'Integration Pending' };
export const ACTIVE_TRIP = ['loading', 'ready', 'dispatched', 'onroute', 'partial', 'completed', 'returning'];
export const ENDED_TRIP = ['closed', 'cancelled', 'failed'];
/** Stop states that count as "done" for trip progress. */
export const CLOSED_STOP = ['delivered', 'partial', 'failed', 'rejected', 'skipped'];
export const STOP_DOT = { delivered: '#1d7a3e', partial: '#b26a16', arrived: '#b26a16', pending: '#a8a4b8', failed: '#b23b3b', rejected: '#b23b3b', skipped: '#7d7990' };

/** Trip Control Room tabs. `trip` only feeds the POD badge. */
export const TRIP_TABS = (trip) => [
  { k: 'stops', label: { ar: 'المحطات والخريطة', en: 'Stops & map' } }, { k: 'assign', label: { ar: 'الإسناد الذكي', en: 'Assignment' } }, { k: 'load', label: { ar: 'الحمولة', en: 'Capacity' } },
  { k: 'time', label: { ar: 'سجل الأحداث', en: 'Timeline' } }, { k: 'cost', label: { ar: 'التكلفة', en: 'Cost' } }, { k: 'pods', label: { ar: 'إثباتات التسليم POD', en: 'PODs' }, badge: trip?.podsCount ?? trip?._count?.pods },
];

// ───────────────────────────────────────────── pure helpers
export const tripName = (trip) => trip?.number || '—';
export const vehName = (v) => (v ? `${v.code}${v.plateAr ? ` · ${v.plateAr}` : ''}` : '—');
export const drvName = (d, lang = 'ar') => (d ? (lang === 'en' && d.nameEn ? d.nameEn : d.nameAr) : '—');
/** `{ ar, en }` of a key from a label map, falling back to the raw key. */
export const labelOf = (map, k, fallback) => map?.[k] || { ar: fallback ?? k ?? '—', en: fallback ?? k ?? '—' };
/** Localised text of a record with `<base>Ar` / `<base>En` fields (English only when present). */
export const loc = (o, base, lang) => (o ? (lang === 'en' && o[`${base}En`]) || o[`${base}Ar`] : null);

/** @returns {{ done: number, total: number, pct: number }} */
export function tripProgress(trip) {
  const stops = trip?.stops || [];
  const total = stops.length || trip?._count?.stops || 0;
  const done = stops.length ? stops.filter((s) => CLOSED_STOP.includes(s.status)).length : trip?.progress?.done || 0;
  return { done, total, pct: total ? Math.round((done / total) * 100) : 0 };
}
export const delayLabel = (min, lang) => (!min ? (lang === 'ar' ? 'في الموعد' : 'On time') : lang === 'ar' ? `متأخرة ${min} د` : `+${min} min`);
/** Document-expiry warning. @returns {{ text: string, color: string }} */
export function docDaysLabel(days, lang) {
  if (days == null) return { text: '—', color: '#a8a4b8' };
  if (days < 0) return { text: lang === 'ar' ? 'منتهية' : 'Expired', color: '#b23b3b' };
  if (days <= 30) return { text: lang === 'ar' ? `${days} يومًا` : `${days} d`, color: '#b26a16' };
  return { text: lang === 'ar' ? 'سارية' : 'Valid', color: '#1d7a3e' };
}
/** ETA is never fabricated: a real string from the API, otherwise "Integration Pending". */
export const etaText = (trip) => (typeof trip?.eta === 'string' && trip.eta ? trip.eta : bi(PENDING));
/** Why a vehicle / driver cannot be assigned (unfit vehicle, busy driver, license expiry …) in the UI language. */
export const whyNot = (canAssign, lang) => (lang === 'en' && canAssign?.whyEn ? canAssign.whyEn : canAssign?.why || '');
/** Safety score colour (red < 70, amber < 85, green otherwise). */
export const safetyColor = (n) => (n < 70 ? '#b23b3b' : n < 85 ? '#b26a16' : '#1d7a3e');
/** Which drawer an alert / request entity opens: 'vehicle' | 'driver' | 'trip' | null. */
export function entityKind(entityType) {
  const ty = String(entityType || '').toLowerCase();
  return ty.includes('vehicle') ? 'vehicle' : ty.includes('driver') ? 'driver' : ty.includes('trip') ? 'trip' : null;
}
export const todayIso = () => new Date().toISOString().slice(0, 10);
export const YES_NO = [['yes', { ar: 'نعم', en: 'Yes' }], ['no', { ar: 'لا', en: 'No' }]];
/** Label map → select options (`[value, {ar,en}]`). */
export const optsOf = (map) => Object.entries(map).map(([v, l]) => [v, { ar: l.ar, en: l.en }]);
