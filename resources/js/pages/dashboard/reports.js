// Helpers of the reports page: group labels, cell formatting by column type, CSV download.
//   ReportMeta = { name, titleAr, titleEn, group, snapshot?, defaultDays }
//   ReportPage = { columns: [{ key, labelAr, labelEn, type }], rows, totals?, total, page, pageSize, pages, generatedAt }
//   column type: 'text' | 'number' | 'date' | 'datetime' | 'money' | 'pct' | 'status' | 'bool'
import { API_BASE, ApiError, buildQuery, refreshAccess, tokens } from '@/api/client';
import { fmtDate, fmtDateOnly, fmtMoney, fmtNum, getLang } from '@/i18n';

export const REPORT_GROUP_LABELS = {
  inventory: { ar: 'المخزون', en: 'Inventory' }, inbound: { ar: 'الاستلام', en: 'Inbound' }, procurement: { ar: 'المشتريات', en: 'Procurement' }, fulfillment: { ar: 'التجهيز', en: 'Fulfillment' },
  delivery: { ar: 'التوصيل', en: 'Delivery' }, fleet: { ar: 'الأسطول', en: 'Fleet' }, returns: { ar: 'المرتجعات', en: 'Returns' }, platform: { ar: 'المنصة', en: 'Platform' },
};

export const isNumType = (type) => type === 'number' || type === 'money' || type === 'pct';
export const isoDay = (d) => d.toISOString().slice(0, 10);

/** Cell text by column type (`status` cells are drawn as chips by the page, this is their text). */
export function fmtCell(v, type) {
  if (v == null || v === '') return '—';
  switch (type) {
    case 'number': return fmtNum(Number(v), Number.isInteger(Number(v)) ? 0 : 2);
    case 'money': return fmtMoney(v);
    case 'pct': return `${fmtNum(Number(v), 1)}%`;
    case 'date': return fmtDateOnly(v);
    case 'datetime': return fmtDate(v);
    case 'bool': return v ? '✓' : '—';
    default: return typeof v === 'object' ? JSON.stringify(v) : String(v);
  }
}

/** DataTable column width / kind for a report column. */
export function columnOf(c) {
  return {
    key: c.key, header: { ar: c.labelAr, en: c.labelEn }, sortable: true, type: c.type,
    width: isNumType(c.type) ? '110px' : c.type === 'date' ? '110px' : c.type === 'datetime' ? '140px' : c.type === 'status' ? '120px' : 'minmax(120px,1fr)',
    kind: isNumType(c.type) ? 'num' : c.type === 'date' || c.type === 'datetime' ? 'date' : undefined,
    value: (r) => fmtCell(r[c.key], c.type),
  };
}

/**
 * GET /reports/:name?format=csv with the bearer token (needs `report.export` on the server) and save it as a file.
 * Resolves the file name; throws ApiError on a server error. An expired token is refreshed once.
 */
export async function downloadReportCsv(name, params) {
  const url = `${API_BASE}/reports/${encodeURIComponent(name)}${buildQuery({ ...params, page: undefined, pageSize: undefined, format: 'csv' })}`;
  const call = () => fetch(url, { headers: { Authorization: `Bearer ${tokens.access || ''}`, 'Accept-Language': getLang() }, credentials: 'same-origin' })
    .catch(() => { throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر التصدير', messageEn: 'Export failed' }); });
  let res = await call();
  if (res.status === 401 && (await refreshAccess())) res = await call();
  if (!res.ok) { const p = await res.json().catch(() => ({})); throw new ApiError({ status: res.status, ...p }); }
  const blob = await res.blob();
  const cd = res.headers.get('content-disposition') || '';
  const file = /filename="?([^";]+)"?/.exec(cd)?.[1] || `${name}-${isoDay(new Date())}.csv`;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = file;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  return file;
}
