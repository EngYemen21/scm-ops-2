// Route metadata: sidebar pages (`/<key>`, guarded by `page.<key>`) and entity deep links (`/po/:number` …).
import { NAV_LABELS } from '../shared';

export const NAV_KEYS = ['dash', 'tower', 'sales', 'consol', 'products', 'whs', 'inv', 'ledger', 'batches', 'receiving', 'procurement', 'picking', 'dispatch', 'trips', 'fleet', 'ttower', 'returns', 'counts', 'reports', 'activity', 'settings', 'wreceive', 'driver'];

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
};

export const NAV_ROUTES = NAV_KEYS.map((key) => ({ key, path: `/${key}`, title: NAV_LABELS[key] || { ar: key, en: key }, permission: `page.${key}`, nav: true }));

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
  };
  if (map[k]) return map[k] + encodeURIComponent(number);
  if (k === 'supplier') return `/procurement?tab=sup&supplier=${encodeURIComponent(number)}`;
  const page = { customer: '/sales', vehicle: '/fleet', driver: '/fleet', bin: '/whs', warehouse: '/whs', batch: '/batches', count: '/counts', pr: '/procurement', rfq: '/procurement', quotation: '/sales', qt: '/sales', oc: '/consol', consolidation: '/consol', maintenance: '/fleet', alert: '/ttower' };
  return page[k] ? `${page[k]}?q=${encodeURIComponent(number)}` : null;
}
