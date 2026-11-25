// Route metadata: sidebar pages (`/<key>`, guarded by `page.<key>`) and entity deep links (`/po/:number` …).
import { NAV_LABELS } from '../shared';

export const NAV_KEYS = ['dash', 'tower', 'sales', 'consol', 'products', 'whs', 'inv', 'ledger', 'batches', 'receiving', 'procurement', 'finance', 'picking', 'dispatch', 'trips', 'fleet', 'ttower', 'returns', 'counts', 'reports', 'activity', 'settings', 'wreceive', 'driver', 'approvals', 'crm', 'hr', 'odoo', 'pricing', 'commissions', 'portal', 'preq', 'workspace', 'payroll', 'pos', 'planning', 'credit', 'banking', 'tax', 'insights', 'itower'];

/** Pages guarded by something other than `page.<key>` (the integration tower uses the integration layer's permission). */
const PAGE_PERMISSION = { itower: 'integration.view' };

/** Page component file of each route key, relative to resources/js/pages/. A missing file renders <ComingSoon />. */
export const PAGE_FILES = {
  dash: 'dashboard/DashboardPage.vue', tower: 'dashboard/TowerPage.vue', reports: 'dashboard/ReportsPage.vue', activity: 'dashboard/ActivityPage.vue', settings: 'dashboard/SettingsPage.vue',
  sales: 'sales/SalesPage.vue', consol: 'sales/ConsolPage.vue', so: 'sales/SoPage.vue',
  products: 'master/ProductsPage.vue', whs: 'master/WarehousesPage.vue', product: 'master/ProductPage.vue',
  inv: 'inventory/BalancesPage.vue', ledger: 'inventory/LedgerPage.vue', batches: 'inventory/BatchesPage.vue', counts: 'inventory/CountsPage.vue', trf: 'inventory/TransferPage.vue',
  receiving: 'inbound/ReceivingPage.vue', wreceive: 'inbound/WorkerReceivePage.vue', shipment: 'inbound/ShipmentPage.vue', grn: 'inbound/GrnPage.vue',
  procurement: 'procurement/ProcurementPage.vue', po: 'procurement/PoPage.vue',
  picking: 'fulfillment/PickingPage.vue', dispatch: 'fulfillment/DispatchPage.vue', fo: 'fulfillment/FoPage.vue',
  trips: 'transport/TripsPage.vue', fleet: 'transport/FleetPage.vue', ttower: 'transport/TransportTowerPage.vue', driver: 'transport/DriverPage.vue', trip: 'transport/TripPage.vue',
  returns: 'returns/ReturnsPage.vue', rtn: 'returns/ReturnPage.vue', exc: 'dashboard/ExceptionPage.vue',
  itower: 'integration/IntegrationTowerPage.vue',
  finance: 'finance/FinancePage.vue', jv: 'finance/JournalPage.vue',
  // sections Odoo had and OPS lacked (docs/odoo/COVERAGE.ar.md), and the archive of everything Odoo holds
  approvals: 'approvals/ApprovalsPage.vue', crm: 'crm/CrmPage.vue', hr: 'hr/HrPage.vue', odoo: 'odoo/OdooArchivePage.vue',
  pricing: 'pricing/PricingPage.vue', commissions: 'commissions/CommissionsPage.vue', portal: 'portal/PortalPage.vue', preq: 'preq/ProductRequestsPage.vue', workspace: 'workspace/WorkspacePage.vue', payroll: 'payroll/PayrollPage.vue',
  pos: 'pos/PosPage.vue', planning: 'planning/PlanningPage.vue', credit: 'credit/CreditPage.vue', banking: 'banking/BankingPage.vue', tax: 'tax/TaxPage.vue', insights: 'insights/InsightsPage.vue',
};

export const NAV_ROUTES = NAV_KEYS.map((key) => ({ key, path: `/${key}`, title: NAV_LABELS[key] || { ar: key, en: key }, permission: PAGE_PERMISSION[key] || `page.${key}`, nav: true }));

/** Entity deep links; `param` is the route param name. The API enforces access, so they carry no page permission. */
export const ENTITY_ROUTES = [
  { key: 'po', path: '/po/:number', title: { ar: 'أمر شراء', en: 'Purchase order' }, param: 'number' },
  { key: 'shipment', path: '/shipments/:number', title: { ar: 'شحنة واردة', en: 'Inbound shipment' }, param: 'number' },
  { key: 'grn', path: '/grn/:number', title: { ar: 'إذن استلام GRN', en: 'Goods receipt' }, param: 'number' },
  { key: 'so', path: '/so/:number', title: { ar: 'أمر بيع', en: 'Sales order' }, param: 'number' },
  { key: 'fo', path: '/fo/:number', title: { ar: 'أمر تجهيز', en: 'Fulfillment order' }, param: 'number' },
  { key: 'trip', path: '/trip/:number', title: { ar: 'رحلة', en: 'Trip' }, param: 'number' },
  { key: 'rtn', path: '/rtn/:number', title: { ar: 'مرتجع', en: 'Return' }, param: 'number' },
  { key: 'trf', path: '/trf/:number', title: { ar: 'تحويل', en: 'Transfer' }, param: 'number' },
  { key: 'exc', path: '/exc/:number', title: { ar: 'استثناء', en: 'Exception' }, param: 'number' },
  { key: 'product', path: '/product/:sku', title: { ar: 'منتج', en: 'Product' }, param: 'sku' },
  { key: 'jv', path: '/jv/:number', title: { ar: 'قيد محاسبي', en: 'Journal' }, param: 'number' },
];
export const ALL_ROUTE_META = [...NAV_ROUTES, ...ENTITY_ROUTES];

/** Deep link for an entity type + number (search results, notifications, cross-links). Null when unknown. */
export function entityPath(type, number) {
  if (!type || !number) return null;
  const k = String(type).toLowerCase().replace(/[^a-z_]/g, '');
  const map = {
    po: '/po/', purchaseorder: '/po/', purchase_order: '/po/',
    shipment: '/shipments/', inboundshipment: '/shipments/', asn: '/shipments/',
    grn: '/grn/', goodsreceipt: '/grn/', receipt: '/grn/',
    so: '/so/', salesorder: '/so/', sales_order: '/so/', order: '/so/',
    fo: '/fo/', fulfillmentorder: '/fo/', fulfillment_order: '/fo/',
    trip: '/trip/', trp: '/trip/',
    rtn: '/rtn/', return: '/rtn/', returnorder: '/rtn/',
    trf: '/trf/', transfer: '/trf/', warehousetransfer: '/trf/',
    exc: '/exc/', exception: '/exc/', opsexception: '/exc/',
    product: '/product/', sku: '/product/',
    jv: '/jv/', journal: '/jv/',
  };
  if (map[k]) return map[k] + encodeURIComponent(number);
  if (k === 'supplier') return `/procurement?tab=sup&supplier=${encodeURIComponent(number)}`;
  if (k === 'customerinvoice' || k === 'ci') return `/finance?tab=invoices&invoice=${encodeURIComponent(number)}`;
  if (k === 'payment' || k === 'receipt') return `/finance?tab=payments&payment=${encodeURIComponent(number)}`;
  if (k === 'debitnote' || k === 'dn') return `/finance?tab=debit-notes&note=${encodeURIComponent(number)}`;
  if (k === 'expense') return `/finance?tab=expenses&expense=${encodeURIComponent(number)}`;
  if (k === 'fixedasset' || k === 'asset') return `/finance?tab=assets&asset=${encodeURIComponent(number)}`;
  if (k === 'supplierinvoice') return `/procurement?tab=inv&invoice=${encodeURIComponent(number)}`;
  // sections that came with the Odoo work (docs/odoo/WAVE2.md, WAVE3.md): each opens its record in a drawer of its page
  if (k === 'crmlead' || k === 'opp') return `/crm?lead=${encodeURIComponent(number)}`;
  if (k === 'approvalrequest') return `/approvals?request=${encodeURIComponent(number)}`;
  if (k === 'employee') return `/hr?emp=${encodeURIComponent(number)}`;
  if (k === 'expenseclaim') return `/hr?tab=claims&claim=${encodeURIComponent(number)}`;
  if (k === 'productrequest') return `/preq?open=${encodeURIComponent(number)}`;
  if (k === 'commissionstatement') return `/commissions?statement=${encodeURIComponent(number)}`;
  if (k === 'commissionplan') return `/commissions?tab=plans&plan=${encodeURIComponent(number)}`;
  if (k === 'wsticket') return `/workspace?tab=tickets&ticket=${encodeURIComponent(number)}`;
  if (k === 'wstask') return `/workspace?tab=tasks&task=${encodeURIComponent(number)}`;
  if (k === 'wsdocument') return `/workspace?tab=docs&doc=${encodeURIComponent(number)}`;
  if (k === 'creditrequest') return `/credit?req=${encodeURIComponent(number)}`;
  if (k === 'payrollrun') return `/payroll?tab=payroll&run=${encodeURIComponent(number)}`;
  if (k === 'posorder') return `/pos?view=orders&order=${encodeURIComponent(number)}`;
  if (k === 'planningrun') return `/planning?run=${encodeURIComponent(number)}`;
  if (k === 'purchaseplan') return `/planning?tab=plans&plan=${encodeURIComponent(number)}`;
  if (k === 'bankstatement') return `/banking?tab=statements&st=${encodeURIComponent(number)}`;
  const page = { customer: '/sales', vehicle: '/fleet', driver: '/fleet', bin: '/whs', warehouse: '/whs', batch: '/batches', count: '/counts', pr: '/procurement', rfq: '/procurement', quotation: '/sales', qt: '/sales', oc: '/consol', consolidation: '/consol', maintenance: '/fleet', alert: '/ttower' };
  return page[k] ? `${page[k]}?q=${encodeURIComponent(number)}` : null;
}
