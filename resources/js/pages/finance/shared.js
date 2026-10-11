// Finance shared pieces: label maps, option lists, option composables (accounts, cost centers, banks, partners).
//
// API shapes (camelCase, decimals arrive as strings):
//   Account   { id, code, nameAr, nameEn, type: asset|liability|equity|revenue|expense, subtype?, parentId?, isPostable, role?, active, debit?, credit?, balance?, depth? }
//   Journal   { id, number, journalDate, status: draft|posted|reversed, kind: auto|manual|reversal|opening, sourceType?, sourceId?, sourceNumber?, memo,
//               totalDebit, totalCredit, createdBy, approvedBy?, postedAt?, reversalOf?, reversedBy?, period{code,status}, lines[JournalLine] }
//   JournalLine { lineNo, account{code,nameAr,nameEn,type}, debit, credit, description?, costCenter?, partnerType?, partnerId?, partner?{code,nameAr,nameEn}, productId?, vehicleId?, tripId? }
//   Invoice   { id, number, kind: invoice|credit_note, status: issued|partially_paid|paid|cancelled, customer, order?, invoiceDate, dueDate?, subtotal, vatPct, vatAmount, total, paidAmount, cogsTotal, balance, overdue, lines?[] }
//   Payment   { id, number, direction: in|out, partnerType, partner{code,nameAr,nameEn}, paymentDate, amount, allocatedAmount, method, bankAccount, reference?, status: posted|void, journal? }
//   Expense   { id, number, expenseDate, account, amount, vatPct, vatAmount, total, settlement: bank|supplier|accrued, costCenter?, vehicle?, trip?, description, status }
//   Asset     { id, code, nameAr, nameEn, category, vehicle?, acquiredOn, cost, salvageValue, usefulLifeMonths, accumulatedDepreciation, netBookValue, status }
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { lang } from '@/i18n';

export const JOURNAL_STATUS_LABELS = {
  draft: { ar: 'مسودة — بانتظار الاعتماد', en: 'Draft', fg: '#b26a16', bg: '#fbf0dd' }, posted: { ar: 'مرحَّل', en: 'Posted', fg: '#1d7a3e', bg: '#e6f9ec' }, reversed: { ar: 'معكوس', en: 'Reversed', fg: '#7d7990', bg: '#F1EFF6' },
};
export const JOURNAL_KIND_LABELS = {
  auto: { ar: 'آلي', en: 'Automatic', fg: '#0d7f93', bg: '#d9f4f9' }, manual: { ar: 'يدوي', en: 'Manual', fg: '#654e92', bg: '#efeaf8' }, reversal: { ar: 'عكسي', en: 'Reversal', fg: '#b23b3b', bg: '#fdecec' }, opening: { ar: 'افتتاحي', en: 'Opening', fg: '#55506a', bg: '#F1EFF6' },
};
export const CI_LABELS = {
  issued: { ar: 'مستحقة', en: 'Issued', fg: '#b26a16', bg: '#fbf0dd' }, partially_paid: { ar: 'مسدَّدة جزئيًا', en: 'Partially paid', fg: '#3C79F5', bg: '#e8effe' }, paid: { ar: 'مسدَّدة ✓', en: 'Paid ✓', fg: '#1d7a3e', bg: '#e6f9ec' }, cancelled: { ar: 'ملغاة', en: 'Cancelled', fg: '#7d7990', bg: '#F1EFF6' },
};
export const CI_KIND_LABELS = { invoice: { ar: 'فاتورة', en: 'Invoice', fg: '#0d7f93', bg: '#d9f4f9' }, credit_note: { ar: 'إشعار دائن', en: 'Credit note', fg: '#b23b3b', bg: '#fdecec' } };
export const PAYMENT_STATUS_LABELS = { posted: { ar: 'مرحَّل', en: 'Posted', fg: '#1d7a3e', bg: '#e6f9ec' }, void: { ar: 'ملغى', en: 'Void', fg: '#7d7990', bg: '#F1EFF6' } };
export const DIRECTION_LABELS = { in: { ar: 'قبض', en: 'Receipt', fg: '#1d7a3e', bg: '#e6f9ec' }, out: { ar: 'صرف', en: 'Payment', fg: '#b23b3b', bg: '#fdecec' } };
export const METHOD_LABELS = { bank: { ar: 'تحويل بنكي', en: 'Bank transfer' }, cash: { ar: 'نقدًا', en: 'Cash' }, cheque: { ar: 'شيك', en: 'Cheque' }, card: { ar: 'بطاقة', en: 'Card' }, wallet: { ar: 'محفظة', en: 'Wallet' } };
export const METHOD_OPTIONS = Object.entries(METHOD_LABELS).map(([v, l]) => ({ v, l }));
export const PERIOD_LABELS = { open: { ar: 'مفتوحة', en: 'Open', fg: '#1d7a3e', bg: '#e6f9ec' }, closed: { ar: 'مغلقة', en: 'Closed', fg: '#7d7990', bg: '#F1EFF6' } };
export const ACCOUNT_TYPE_LABELS = { asset: { ar: 'أصول', en: 'Assets' }, liability: { ar: 'التزامات', en: 'Liabilities' }, equity: { ar: 'حقوق الملكية', en: 'Equity' }, revenue: { ar: 'إيرادات', en: 'Revenue' }, expense: { ar: 'مصروفات', en: 'Expenses' } };
export const ACCOUNT_TYPE_OPTIONS = Object.entries(ACCOUNT_TYPE_LABELS).map(([v, l]) => ({ v, l }));
export const SETTLEMENT_LABELS = { bank: { ar: 'من بنك / صندوق', en: 'From bank / cash' }, supplier: { ar: 'على حساب مورد', en: 'On a supplier account' }, accrued: { ar: 'مستحق (يُسدَّد لاحقًا)', en: 'Accrued' } };
export const SETTLEMENT_OPTIONS = Object.entries(SETTLEMENT_LABELS).map(([v, l]) => ({ v, l }));
export const ASSET_STATUS_LABELS = { active: { ar: 'قائم', en: 'Active', fg: '#1d7a3e', bg: '#e6f9ec' }, disposed: { ar: 'مستبعد', en: 'Disposed', fg: '#7d7990', bg: '#F1EFF6' } };
/** Posting roles → readable labels (the chart editor shows what a role drives). */
export const ROLE_LABELS = {
  ar: 'ذمم العملاء (تحكم)', ap: 'ذمم الموردين (تحكم)', inventory: 'المخزون', inventory_in_transit: 'مخزون في الطريق (تحويلات)', goods_in_transit: 'بضاعة في الطريق للعملاء', vat_input: 'ضريبة مدخلات', vat_output: 'ضريبة مخرجات',
  grni: 'بضاعة مستلمة غير مفوترة', accrued_expenses: 'مصروفات مستحقة', cash: 'الصندوق', bank: 'البنك الرئيسي', vehicles: 'المركبات (أصل)', accumulated_depreciation: 'مجمع الإهلاك', capital: 'رأس المال', retained_earnings: 'أرباح مبقاة',
  opening_balance_equity: 'أرصدة افتتاحية', revenue: 'مبيعات', sales_returns: 'مردودات مبيعات', cogs: 'تكلفة البضاعة المباعة', ppv: 'فروقات أسعار الشراء', freight_in: 'شحن وارد', inventory_adjustment: 'فروقات الجرد', inventory_loss: 'خسائر المخزون',
  fuel: 'وقود', maintenance: 'صيانة', fleet_other: 'مصاريف أسطول أخرى', depreciation: 'إهلاك', admin_expenses: 'مصاريف إدارية',
};
export const SELECT_PLACEHOLDER = { ar: '— اختر —', en: '— select —' };
export const pname = (p) => (p ? (lang.value === 'ar' ? p.nameAr : p.nameEn) : '—');
export const todayIso = () => new Date().toISOString().slice(0, 10);
export const monthStartIso = () => new Date().toISOString().slice(0, 8) + '01';
/** Deep link of a journal's source document (the report → document drill-down). */
export function sourceLink(type, number) {
  if (!type || !number) return null;
  const m = { GoodsReceipt: `/grn/${number}`, FulfillmentOrder: `/fo/${number}`, ReturnOrder: `/rtn/${number}`, WarehouseTransfer: `/trf/${number}`, InventoryCount: `/counts?q=${number}`, PurchaseOrder: `/po/${number}`,
    SupplierInvoice: `/procurement?tab=inv&invoice=${number}`, CustomerInvoice: `/finance?tab=invoices&invoice=${number}`, Payment: `/finance?tab=payments&payment=${number}`, Expense: `/finance?tab=expenses&expense=${number}`,
    FixedAsset: `/finance?tab=assets&asset=${number}`, FuelRecord: `/fleet?q=${number}`, MaintenanceOrder: `/fleet?q=${number}`, InventoryMovement: `/ledger?q=${number}` };
  return m[type] ? m[type].replace(number, encodeURIComponent(number)) : null;
}

// ---------------------------------------------------------------- option composables
/**
 * ONE request (`/finance/lookups`) shared by every form and report selector through vue-query's cache (5 minutes),
 * fetched only while `enabled` is true (a drawer that is open, a tab that shows selectors) — the finance page itself
 * fires two requests on open (board, reconciliation), not eight. Serverless cold starts made the old fan-out slow.
 */
export function useFinanceLookups(enabled = () => true) {
  const q = useGet('/finance/lookups', undefined, { enabled, staleTime: 300_000 });
  return { query: q, data: computed(() => q.data.value || { accounts: [], costCenters: [], banks: [], customers: [], suppliers: [] }) };
}
/** Postable accounts as select options `code · name`, plus the raw list. */
export function useAccountOptions(filter = null, enabled = () => true) {
  const { data, query } = useFinanceLookups(enabled);
  const accounts = computed(() => data.value.accounts.filter((a) => !filter || filter(a)));
  const options = computed(() => accounts.value.map((a) => ({ v: a.code, l: `${a.code} · ${pname(a)}` })));
  return { options, accounts, query };
}
export function useCostCenterOptions(enabled = () => true) {
  const { data } = useFinanceLookups(enabled);
  return computed(() => data.value.costCenters.map((c) => ({ v: c.code, l: `${c.code} · ${pname(c)}` })));
}
export function useBankOptions(enabled = () => true) {
  const { data } = useFinanceLookups(enabled);
  const banks = computed(() => data.value.banks);
  return { options: computed(() => banks.value.map((b) => ({ v: b.code, l: `${pname(b)}${b.iban ? ' · ' + b.iban.slice(-4) : ''}` }))), banks };
}
export function useCustomerOptions(enabled = () => true) {
  const { data } = useFinanceLookups(enabled);
  const customers = computed(() => data.value.customers);
  return { options: computed(() => customers.value.map((c) => ({ v: c.code, l: `${pname(c)} · ${c.code}` }))), customers };
}
export function useSupplierOptions(enabled = () => true) {
  const { data } = useFinanceLookups(enabled);
  const suppliers = computed(() => data.value.suppliers);
  return { options: computed(() => suppliers.value.map((s) => ({ v: s.code, l: `${pname(s)} · ${s.code}` }))), suppliers };
}
