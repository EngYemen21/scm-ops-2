// Order-consolidation helpers shared by ConsolPage and BatchDetail.
//   OcOrder { number, status, kg, cbm, dueDate?, priority?, customer: { code, nameAr, nameEn?, zone? }, lines?: [{ qty }], fos?: [{ number, status }] }
//   Oc      { number, status, rule?, createdAt, createdBy?, warehouse: { code }, orders: OcOrder[], trips?: [{ number, status }], history? }
import { num } from '@/i18n';

export const OC_STEPS = ['open', 'consolidating', 'readypick', 'picking', 'packed', 'readydisp', 'dispatched', 'done'];
/** Pool grouping modes; `rule` is stored on the batch as its consolidation rule. */
export const GROUPS = [
  { k: 'zone', ar: 'المنطقة', en: 'Zone', rule: 'المنطقة + تاريخ التسليم' },
  { k: 'cust', ar: 'العميل', en: 'Customer', rule: 'العميل' },
  { k: 'due', ar: 'تاريخ التسليم', en: 'Delivery date', rule: 'تاريخ التسليم' },
  { k: 'wh', ar: 'المستودع', en: 'Warehouse', rule: 'المستودع' },
];
/** Units of an order (sum of its line quantities). */
export const itemsOf = (o) => (o.lines || []).reduce((s, l) => s + num(l.qty), 0);
/** Distinct customer zones of a set of orders. */
export const zonesOf = (rows) => Array.from(new Set(rows.map((r) => r.customer.zone).filter(Boolean)));
/** Number of distinct customers in a set of orders. */
export const custsOf = (rows) => new Set(rows.map((r) => r.customer.code || r.customer.nameAr)).size;
export const sumOf = (rows, field) => rows.reduce((s, o) => s + num(o[field]), 0);
