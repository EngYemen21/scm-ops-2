// Phone navigation model, derived from the SAME `user.nav` the desktop sidebar uses — so RBAC stays in one place:
// a page the user may not open never becomes a tab, a menu row or a quick action.
//   tabsFor(user)      bottom tab bar: home · up to three role-relevant pages · More
//   moreGroups(user)   every page of the user, grouped by domain, for the More screen
//   quickActions(auth) the + button's sheet
import { NAV_LABELS } from '../../shared';

/** Short tab captions (the sidebar captions are too long for a 5-slot bar). */
const TAB_LABELS = {
  dash: { ar: 'الرئيسية', en: 'Home' }, wreceive: { ar: 'الاستلام', en: 'Receive' }, driver: { ar: 'رحلاتي', en: 'My trips' },
  sales: { ar: 'الطلبات', en: 'Orders' }, receiving: { ar: 'المستودع', en: 'Warehouse' }, picking: { ar: 'التجهيز', en: 'Picking' },
  inv: { ar: 'المخزون', en: 'Stock' }, procurement: { ar: 'المشتريات', en: 'Purchasing' }, trips: { ar: 'الرحلات', en: 'Trips' },
  dispatch: { ar: 'الشحن', en: 'Dispatch' }, counts: { ar: 'الجرد', en: 'Counts' }, reports: { ar: 'التقارير', en: 'Reports' },
  tower: { ar: 'المراقبة', en: 'Tower' }, fleet: { ar: 'الأسطول', en: 'Fleet' }, returns: { ar: 'المرتجعات', en: 'Returns' },
};
/** Which pages deserve a tab, most useful first. The first three the user can open are taken. */
const TAB_PRIORITY = ['sales', 'receiving', 'inv', 'picking', 'procurement', 'trips', 'dispatch', 'counts', 'tower', 'fleet', 'returns', 'reports'];
const TAB_ICONS = { dash: 'home', wreceive: 'home', driver: 'home' };

export function tabsFor(user) {
  const nav = user?.nav || [];
  const home = nav[0] || 'dash';
  const middle = TAB_PRIORITY.filter((k) => k !== home && nav.includes(k)).slice(0, 3);
  // the Orders tab opens the sales-order list, not the quotations tab
  const tab = (k) => ({ key: k, to: k === 'sales' ? '/sales?tab=so' : `/${k}`, label: TAB_LABELS[k] || NAV_LABELS[k] || { ar: k, en: k }, icon: TAB_ICONS[k] || null, nav: k });
  return [{ ...tab(home), icon: 'home', label: home === 'dash' ? TAB_LABELS.dash : tab(home).label }, ...middle.map(tab), { key: 'more', to: '/more', label: { ar: 'المزيد', en: 'More' }, icon: 'dots' }];
}

const GROUPS = [
  { key: 'sales', label: { ar: 'المبيعات', en: 'Sales' }, tint: ['#e8effe', '#3c79f5'], pages: ['sales', 'consol'] },
  { key: 'wh', label: { ar: 'عمليات المستودع', en: 'Warehouse operations' }, tint: ['#d9f4f9', '#0d7f93'], pages: ['receiving', 'wreceive', 'picking', 'counts', 'returns'] },
  { key: 'stock', label: { ar: 'المخزون والبيانات الأساسية', en: 'Stock & master data' }, tint: ['#efeaf8', '#654e92'], pages: ['inv', 'ledger', 'batches', 'products', 'whs'] },
  { key: 'buy', label: { ar: 'المشتريات', en: 'Purchasing' }, tint: ['#fbf0dd', '#b26a16'], pages: ['procurement'] },
  { key: 'tms', label: { ar: 'التوصيل والنقل', en: 'Delivery & transport' }, tint: ['#e8effe', '#3c79f5'], pages: ['dispatch', 'trips', 'fleet', 'ttower', 'driver'] },
  { key: 'mgmt', label: { ar: 'المتابعة والإدارة', en: 'Monitoring & admin' }, tint: ['#f1eff6', '#55506a'], pages: ['dash', 'tower', 'itower', 'reports', 'activity', 'settings'] },
];

export function moreGroups(user) {
  const nav = new Set(user?.nav || []);
  return GROUPS.map((g) => ({ ...g, items: g.pages.filter((k) => nav.has(k)).map((k) => ({ key: k, to: `/${k}`, label: NAV_LABELS[k] || { ar: k, en: k } })) })).filter((g) => g.items.length);
}

/** `page` = the nav key that must be open to the user; the first matching target of an action wins. */
const ACTIONS = [
  { key: 'receive', label: { ar: 'استلام بضاعة', en: 'Receive goods' }, icon: 'download', tint: ['#d9f4f9', '#0d7f93'], targets: [['wreceive', '/wreceive'], ['receiving', '/receiving']] },
  { key: 'transfer', label: { ar: 'تحويل مخزون', en: 'Transfer stock' }, icon: 'swap', tint: ['#e8effe', '#3c79f5'], targets: [['returns', '/returns?tab=trf']] },
  { key: 'pick', label: { ar: 'بدء تجهيز', en: 'Start picking' }, icon: 'check', tint: ['#fbf0dd', '#b26a16'], targets: [['picking', '/picking']] },
  { key: 'pack', label: { ar: 'بدء تعبئة', en: 'Start packing' }, icon: 'box', tint: ['#efeaf8', '#654e92'], targets: [['picking', '/picking?tab=pack']] },
  { key: 'order', label: { ar: 'طلب بيع', en: 'Sales order' }, icon: 'plus', tint: ['#e6f9ec', '#1d7a3e'], targets: [['sales', '/sales?tab=so']] },
  { key: 'product', label: { ar: 'المنتجات', en: 'Products' }, icon: 'box', tint: ['#e6f9ec', '#1d7a3e'], targets: [['products', '/products']] },
  { key: 'count', label: { ar: 'جرد موقع', en: 'Count a bin' }, icon: 'hash', tint: ['#f1eff6', '#55506a'], targets: [['counts', '/counts']] },
  { key: 'trip', label: { ar: 'الرحلات والشحن', en: 'Trips & dispatch' }, icon: 'truck', tint: ['#e8effe', '#3c79f5'], targets: [['dispatch', '/dispatch'], ['trips', '/trips']] },
  { key: 'exception', label: { ar: 'فتح استثناء', en: 'Open exception' }, icon: 'alert', tint: ['#fdecec', '#b23b3b'], targets: [['tower', '/tower?new=1']] },
];

export function quickActions(user) {
  const nav = new Set(user?.nav || []);
  return ACTIONS.map((a) => ({ ...a, to: (a.targets.find(([page]) => nav.has(page)) || [])[1] })).filter((a) => a.to);
}
