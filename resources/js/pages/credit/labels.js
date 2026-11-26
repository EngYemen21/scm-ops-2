// «حسابات الائتمان الآجل» — the label maps of the section with their chip colours: request states, target-time (SLA)
// states, apply status, approval lines, envelopes, papers, notifications and the events of a request's timeline.
// A key the map does not know is shown as the server sent it (never hidden).
import { bi } from '@/i18n';

/** `map[key]` for a key that comes from the server: only the map's own entries count. */
export const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);
/** `{ ar, en, fg, bg }` of a key, or the key itself in neutral colours. */
export const pick = (map, key) => own(map, key) || { ar: key || '—', en: key || '—', ...TONE.neutral };

/** The colour pairs every status chip of the application uses (shared/constants.json). */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#e4f8fb' }, neutral: { fg: '#55506a', bg: '#F1EFF6' },
  dark: { fg: '#ffffff', bg: '#b23b3b' },
};

/** `request.state` (the server sends the words too: stateAr / stateEn; these are the colours and the fallback). */
export const STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  submitted: { ar: 'مُقدَّم', en: 'Submitted', ...TONE.blue },
  in_review: { ar: 'قيد المراجعة', en: 'In review', ...TONE.violet },
  pending_signature: { ar: 'بانتظار التوقيع', en: 'Pending signature', ...TONE.teal },
  ceo_approval: { ar: 'اعتماد الإدارة العليا', en: 'CEO approval', ...TONE.warn },
  approved: { ar: 'معتمد', en: 'Approved', ...TONE.ok },
  rejected: { ar: 'مرفوض', en: 'Rejected', ...TONE.bad },
  changes_requested: { ar: 'مطلوب تعديل', en: 'Changes requested', ...TONE.warn },
  cancelled: { ar: 'ملغى', en: 'Cancelled', ...TONE.neutral },
};
/** The chip of a request card: the server's words, the section's colours. */
export function stateChip(r) {
  const base = pick(STATE, r?.state);
  return { ...base, ar: r?.stateAr || base.ar, en: r?.stateEn || r?.stateAr || base.en };
}
/** The state groups of the list filter, before the single states of the lookups. */
export const STATE_GROUPS = [
  { v: 'open', l: { ar: 'المفتوحة (غير المنتهية)', en: 'Open (not closed)' } },
  { v: 'work', l: { ar: 'قيد العمل في المراحل', en: 'Being worked in the stages' } },
  { v: 'closed', l: { ar: 'المنتهية', en: 'Closed' } },
  { v: 'all', l: { ar: 'كل الحالات', en: 'Every state' } },
];

/** `request.sla` — where the request stands against the target time of its stage. */
export const SLA = {
  none: { ar: 'بلا موعد', en: 'No target', ...TONE.neutral },
  on_track: { ar: 'في الوقت', en: 'On track', ...TONE.ok },
  due_soon: { ar: 'يقترب الموعد', en: 'Due soon', ...TONE.warn },
  late: { ar: 'متأخر', en: 'Late', ...TONE.bad },
  escalated: { ar: 'مُصعَّد', en: 'Escalated', ...TONE.dark },
};
export const slaOptions = () => Object.entries(SLA).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));

/** `request.applyStatus` — whether the granted limit reached the customer. */
export const APPLY = {
  none: { ar: 'لم يُطبَّق', en: 'Not applied', ...TONE.neutral },
  applied: { ar: 'طُبِّق على العميل', en: 'Applied to the customer', ...TONE.ok },
  blocked: { ar: 'تعذّر التطبيق', en: 'Apply blocked', ...TONE.bad },
  restored: { ar: 'أُعيد العميل لما كان', en: 'Customer restored', ...TONE.warn },
};
export const applyOptions = () => Object.entries(APPLY).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));

export const ORIGIN = { odoo: { ar: 'من أودو', en: 'From Odoo', ...TONE.violet }, ops: { ar: 'أُنشئ هنا', en: 'Made in OPS', ...TONE.neutral } };
export const originOptions = () => [{ v: 'ops', l: { ar: 'أُنشئ هنا', en: 'Made in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
export const OWNED_HELP = {
  ar: 'هذا الطلب يُدار في أودو ما دام القسم يتبعه: بياناته وقراراته تُعدَّل هناك. هنا يُقرأ ويُعلَّق عليه.',
  en: 'This request is managed in Odoo while the section follows it: its values and decisions are changed there. Here it is read and commented on.',
};

/** `approval.state` — a line of the approval audit. */
export const LINE = {
  pending: { ar: 'بانتظار القرار', en: 'Pending', ...TONE.blue },
  approved: { ar: 'موافقة', en: 'Approved', ...TONE.ok },
  rejected: { ar: 'رفض', en: 'Rejected', ...TONE.bad },
  changes_requested: { ar: 'طلب تعديل', en: 'Changes requested', ...TONE.warn },
  skipped: { ar: 'تُجووزت', en: 'Skipped', ...TONE.neutral },
  escalated: { ar: 'مُصعَّدة', en: 'Escalated', ...TONE.dark },
};

/** `envelope.status` — an agreement sent for signature. */
export const ENVELOPE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  sent: { ar: 'أُرسلت', en: 'Sent', ...TONE.blue },
  delivered: { ar: 'وصلت للموقّع', en: 'Delivered', ...TONE.teal },
  signed: { ar: 'موقّعة', en: 'Signed', ...TONE.ok },
  declined: { ar: 'رُفض التوقيع', en: 'Declined', ...TONE.bad },
  expired: { ar: 'انتهت مهلتها', en: 'Expired', ...TONE.warn },
  failed: { ar: 'فشلت', en: 'Failed', ...TONE.bad },
  voided: { ar: 'أُبطلت', en: 'Voided', ...TONE.neutral },
};
/** `requirements.signature.status` / `request.signatureStatus`: the envelope states plus the summary words. */
export const SIGNATURE = {
  ...ENVELOPE,
  none: { ar: 'لا توقيع بعد', en: 'Not signed yet', ...TONE.neutral },
  ok: { ar: 'موقّع', en: 'Signed', ...TONE.ok },
  stale: { ar: 'وُقّع على نسخة سابقة', en: 'Signed an earlier version', ...TONE.warn },
};

/** `file.status` — a paper of a slot. */
export const FILE = {
  current: { ar: 'الحالي', en: 'Current', ...TONE.ok },
  replaced: { ar: 'استُبدل', en: 'Replaced', ...TONE.neutral },
  removed: { ar: 'أُزيل', en: 'Removed', ...TONE.neutral },
};

/** `notification.channel` and `.status`. */
export const CHANNEL = {
  in_app: { ar: 'داخل النظام', en: 'In app', ...TONE.blue },
  email: { ar: 'البريد الإلكتروني', en: 'E-mail', ...TONE.teal },
  whatsapp: { ar: 'واتساب', en: 'WhatsApp', ...TONE.ok },
};
export const NOTIFY = {
  pending: { ar: 'بالانتظار', en: 'Pending', ...TONE.blue },
  sent: { ar: 'أُرسل', en: 'Sent', ...TONE.ok },
  failed: { ar: 'فشل', en: 'Failed', ...TONE.bad },
  skipped: { ar: 'لم يُرسل', en: 'Skipped', ...TONE.neutral },
};
export const SKIPPED_HELP = { ar: 'لم يُرسل: لا مزوّد مُعدّ لهذه القناة بعد', en: 'Not sent: no provider is configured for this channel yet' };

/** `terms.status` — a version of the credit terms. */
export const TERMS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  published: { ar: 'منشورة', en: 'Published', ...TONE.ok },
  retired: { ar: 'متقاعدة', en: 'Retired', ...TONE.warn },
};

/** `approvalMode` of a stage. */
export const MODE = { any_user: { ar: 'يكفي معتمد واحد', en: 'Any one approver' }, all_users: { ar: 'كل المعتمدين', en: 'Every approver' } };

/** A line of the timeline (`event.kind`). */
export const EVENT = {
  created: { ar: 'أُنشئ الطلب', en: 'Created', ...TONE.blue },
  edited: { ar: 'عُدّلت بياناته', en: 'Edited', ...TONE.neutral },
  submitted: { ar: 'أُرسل للمراجعة', en: 'Submitted', ...TONE.blue },
  stage: { ar: 'انتقل إلى مرحلة', en: 'Moved to a stage', ...TONE.violet },
  approved: { ar: 'موافقة', en: 'Approved', ...TONE.ok },
  rejected: { ar: 'رُفض', en: 'Rejected', ...TONE.bad },
  changes_requested: { ar: 'طُلب تعديله', en: 'Changes requested', ...TONE.warn },
  skipped: { ar: 'تُجووزت المرحلة', en: 'Stage skipped', ...TONE.neutral },
  approvers_refreshed: { ar: 'حُدّث المعتمدون', en: 'Approvers refreshed', ...TONE.neutral },
  withdrawn: { ar: 'سُحب', en: 'Withdrawn', ...TONE.warn },
  cancelled: { ar: 'أُلغي', en: 'Cancelled', ...TONE.neutral },
  reopened: { ar: 'أُعيد فتحه', en: 'Reopened', ...TONE.warn },
  assessed: { ar: 'سُجّل تقييم', en: 'Assessed', ...TONE.teal },
  comment: { ar: 'تعليق', en: 'Comment', ...TONE.teal },
  document_added: { ar: 'أُضيفت وثيقة', en: 'Paper added', ...TONE.blue },
  document_removed: { ar: 'أُزيلت وثيقة', en: 'Paper removed', ...TONE.neutral },
  terms_accepted: { ar: 'قُبلت الشروط', en: 'Terms accepted', ...TONE.ok },
  envelope: { ar: 'التوقيع', en: 'Signature', ...TONE.teal },
  reminder: { ar: 'تذكير', en: 'Reminder', ...TONE.warn },
  escalated: { ar: 'صُعِّد', en: 'Escalated', ...TONE.bad },
  applied: { ar: 'طُبّق على العميل', en: 'Applied to the customer', ...TONE.ok },
  apply_blocked: { ar: 'تعذّر التطبيق', en: 'Apply blocked', ...TONE.bad },
  customer_restored: { ar: 'أُعيد العميل لما كان', en: 'Customer restored', ...TONE.warn },
};
export const eventLabel = (k) => pick(EVENT, k);

/** Why the decision buttons are not offered (`can.decideCode`). */
export const DECIDE_CODE = {
  CREDIT_SELF_APPROVAL: { ar: 'لا يقرّر أحد في طلب أدخله بنفسه — القرار لغيرك.', en: 'Nobody decides a request he entered himself — the decision is someone else’s.' },
  CREDIT_DECIDE_PERMISSION: { ar: 'قرار المرحلة يحتاج صلاحية مراجعة الائتمان أو اعتماده.', en: 'A stage decision needs the credit review or approve permission.' },
  CREDIT_ALREADY_DECIDED: { ar: 'سجّلت موافقتك على هذه المرحلة — المرحلة بانتظار بقية المعتمدين.', en: 'You have already approved this stage — it waits for the other approvers.' },
};
export const decideHelp = (code) => (code ? bi(own(DECIDE_CODE, code) || { ar: `لا يمكنك القرار في هذه المرحلة (${code})`, en: `You cannot decide at this stage (${code})` }) : '');

/** Sunday … Saturday = 0 … 6 (the calendar policy). */
export const WEEKDAYS = [
  { ar: 'الأحد', en: 'Sun' }, { ar: 'الاثنين', en: 'Mon' }, { ar: 'الثلاثاء', en: 'Tue' }, { ar: 'الأربعاء', en: 'Wed' },
  { ar: 'الخميس', en: 'Thu' }, { ar: 'الجمعة', en: 'Fri' }, { ar: 'السبت', en: 'Sat' },
];
