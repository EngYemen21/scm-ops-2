// Label maps + helpers of the settings page (users · roles & permissions · business policies · integrations).
//   UserRow = { id, username, email, nameAr, nameEn, initials, active, lastLoginAt, roles: string[], warehouses: string[] }
//   RoleRow = { id, key, nameAr, nameEn, permissions: string[] }
//   SettingRow = { value, group, description, isDefault, updatedAt }        (GET /settings → { [key]: SettingRow })
//   IntegrationRow = { key, nameAr, nameEn, status, detail, detailAr }
//   OutboxRow = { id, type, payload, status: 'pending'|'sent'|'failed', attempts, lastError, createdAt, sentAt }
import { NAV_LABELS, ROLE_LABELS } from '@/shared';

export const OUTBOX_LABELS = {
  pending: { ar: 'بانتظار الإرسال', en: 'Pending', fg: '#b26a16', bg: '#fbf0dd' },
  sent: { ar: 'أُرسل', en: 'Sent', fg: '#1d7a3e', bg: '#e6f9ec' },
  failed: { ar: 'فشل', en: 'Failed', fg: '#b23b3b', bg: '#fdecec' },
};
export const OUTBOX_OPTIONS = [{ v: 'pending', l: OUTBOX_LABELS.pending }, { v: 'sent', l: OUTBOX_LABELS.sent }, { v: 'failed', l: OUTBOX_LABELS.failed }];

export const SETTING_GROUP_LABELS = {
  procurement: { ar: 'مصفوفة الاعتماد والمشتريات', en: 'Approval matrix & procurement' }, receiving: { ar: 'الاستلام', en: 'Receiving' }, inventory: { ar: 'المخزون والصلاحية', en: 'Inventory & expiry' },
  sales: { ar: 'المبيعات', en: 'Sales' }, delivery: { ar: 'التوصيل', en: 'Delivery' }, exceptions: { ar: 'قواعد SLA', en: 'SLA rules' }, fleet: { ar: 'الأسطول', en: 'Fleet' }, loading: { ar: 'التحميل', en: 'Loading' }, general: { ar: 'عام', en: 'General' },
};

/** Permission groups of the matrix: [prefix, label]. */
export const PERM_GROUPS = [
  ['page', { ar: 'الصفحات', en: 'Pages' }], ['pr', { ar: 'طلبات الشراء', en: 'Purchase requests' }], ['rfq', { ar: 'RFQ', en: 'RFQ' }], ['supquote', { ar: 'عروض الموردين', en: 'Supplier quotes' }], ['po', { ar: 'أوامر الشراء', en: 'Purchase orders' }], ['supplier', { ar: 'الموردون', en: 'Suppliers' }],
  ['shipment', { ar: 'الشحنات الواردة', en: 'Inbound' }], ['grn', { ar: 'GRN', en: 'GRN' }], ['putaway', { ar: 'Putaway', en: 'Putaway' }], ['inventory', { ar: 'المخزون', en: 'Inventory' }], ['sales', { ar: 'المبيعات', en: 'Sales' }], ['so', { ar: 'أوامر البيع', en: 'Sales orders' }], ['consol', { ar: 'التجميع', en: 'Consolidation' }], ['customer', { ar: 'العملاء', en: 'Customers' }],
  ['pick', { ar: 'التجهيز', en: 'Picking' }], ['pack', { ar: 'التعبئة', en: 'Packing' }], ['load', { ar: 'التحميل', en: 'Loading' }], ['trip', { ar: 'الرحلات', en: 'Trips' }], ['delivery', { ar: 'التوصيل', en: 'Delivery' }], ['return', { ar: 'المرتجعات', en: 'Returns' }],
  ['vehicle', { ar: 'المركبات', en: 'Vehicles' }], ['driver', { ar: 'السائقون', en: 'Drivers' }], ['maintenance', { ar: 'الصيانة', en: 'Maintenance' }], ['fuel', { ar: 'الوقود', en: 'Fuel' }], ['alert', { ar: 'التنبيهات', en: 'Alerts' }], ['opreq', { ar: 'طلبات تشغيلية', en: 'Ops requests' }],
  ['exception', { ar: 'الاستثناءات', en: 'Exceptions' }], ['product', { ar: 'المنتجات', en: 'Products' }], ['category', { ar: 'الفئات', en: 'Categories' }], ['warehouse', { ar: 'المستودعات', en: 'Warehouses' }], ['user', { ar: 'المستخدمون', en: 'Users' }], ['settings', { ar: 'الإعدادات', en: 'Settings' }], ['audit', { ar: 'التدقيق', en: 'Audit' }], ['report', { ar: 'التقارير', en: 'Reports' }],
];

/** `page.sales` → the page's nav title; any other permission is shown by its key. */
export function permLabel(p, lang) {
  if (p.startsWith('page.')) { const n = NAV_LABELS[p.slice(5)]; return n ? (lang === 'ar' ? n.ar : n.en) : p; }
  return p;
}
export const roleName = (r, lang) => (lang === 'ar' ? r.nameAr || ROLE_LABELS[r.key]?.ar || r.key : r.nameEn || ROLE_LABELS[r.key]?.en || r.key);

/** Permissions grouped for the matrix, filtered by a search text: [{ key, label, perms }] (+ an "other" group). */
export function groupPermissions(permissions, filter, lang) {
  const f = filter.trim().toLowerCase();
  const out = [];
  for (const [prefix, label] of PERM_GROUPS) {
    const perms = permissions.filter((p) => p.split('.')[0] === prefix && (!f || p.includes(f) || permLabel(p, lang).toLowerCase().includes(f)));
    if (perms.length) out.push({ key: prefix, label, perms });
  }
  const known = new Set(PERM_GROUPS.map((g) => g[0]));
  const rest = permissions.filter((p) => !known.has(p.split('.')[0]) && (!f || p.includes(f)));
  if (rest.length) out.push({ key: 'other', label: { ar: 'أخرى', en: 'Other' }, perms: rest });
  return out;
}

/** Editor kind of a setting value. */
export const settingKind = (v) => (typeof v === 'boolean' ? 'bool' : typeof v === 'number' ? 'num' : typeof v === 'string' ? 'str' : 'json');
export const settingText = (v, kind) => (kind === 'json' ? JSON.stringify(v, null, 2) : String(v ?? ''));

// ---------------------------------------------------------------- PO approval tiers (`procurement.approvalTiers`)
export const APPROVAL_TIERS_KEY = 'procurement.approvalTiers';
/** Tier = { max: number | null (no limit), roles: string[] (approval steps, in order) }. */
export const isTierList = (v) => Array.isArray(v) && v.every((x) => x && typeof x === 'object' && !Array.isArray(x) && 'roles' in x);

/**
 * The server picks the first tier whose `max` is null or ≥ the PO total, so: every tier needs at least one role,
 * limits are positive and ascending, and only the last tier may be open-ended. Returns { ar, en } or null.
 */
export function validateTiers(tiers) {
  if (!Array.isArray(tiers) || tiers.length === 0) return { ar: 'أضف شريحة اعتماد واحدة على الأقل', en: 'Add at least one approval tier' };
  let last = 0;
  for (let i = 0; i < tiers.length; i++) {
    const tier = tiers[i];
    const n = i + 1;
    if (!tier || !Array.isArray(tier.roles) || tier.roles.length === 0) return { ar: `الشريحة ${n}: اختر دورًا واحدًا على الأقل`, en: `Tier ${n}: pick at least one role` };
    if (tier.max == null) {
      if (i !== tiers.length - 1) return { ar: `الشريحة ${n}: «بلا حد» مسموح للشريحة الأخيرة فقط`, en: `Tier ${n}: only the last tier may have no limit` };
      continue;
    }
    if (typeof tier.max !== 'number' || !Number.isFinite(tier.max) || tier.max <= 0) return { ar: `الشريحة ${n}: الحد يجب أن يكون رقمًا أكبر من صفر`, en: `Tier ${n}: the limit must be a number above zero` };
    if (tier.max <= last) return { ar: `الشريحة ${n}: الحد يجب أن يكون أكبر من الشريحة السابقة`, en: `Tier ${n}: the limit must be higher than the previous tier` };
    last = tier.max;
  }
  return null;
}
