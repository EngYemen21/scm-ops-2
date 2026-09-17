// Returns & transfers helpers — labels, small formatters and the composables shared by the list tabs, the drawer and
// the full page. Components of the domain live beside this file (ReturnDetail, ReturnDrawer, DecisionModal, ReturnForm,
// TransferForm …). All data comes from /api/returns and /api/inventory/transfers.
import { computed, ref, toValue, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';

// ───────────────────────────────────────────── labels
export const RETURN_TYPE_LABELS = {
  cust: { ar: 'مرتجع عميل', en: 'Customer return', fg: '#654e92', bg: '#efeaf8' }, sup: { ar: 'إرجاع للمورد', en: 'Supplier return', fg: '#0d7f93', bg: '#d9f4f9' },
  del: { ar: 'مرتجع توصيل', en: 'Delivery return', fg: '#3C79F5', bg: '#e8effe' }, dmg: { ar: 'أصناف تالفة', en: 'Damaged items', fg: '#b23b3b', bg: '#fdecec' },
};
export const RETURN_REASON_LABELS = {
  damaged: { ar: 'تالف', en: 'Damaged' }, wrong_product: { ar: 'منتج خاطئ', en: 'Wrong product' }, wrong_qty: { ar: 'كمية خاطئة', en: 'Wrong quantity' }, expired: { ar: 'منتهي الصلاحية', en: 'Expired' },
  cust_reject: { ar: 'رفض العميل', en: 'Customer rejected' }, del_fail: { ar: 'فشل التوصيل', en: 'Delivery failed' }, quality: { ar: 'جودة', en: 'Quality' }, partial: { ar: 'تسليم جزئي', en: 'Partial delivery' }, other: { ar: 'أخرى', en: 'Other' },
};
/** Chip colours + button tone of each final decision. */
export const DECISION_STYLE = {
  restock: { fg: '#1d7a3e', bg: '#e6f9ec', tone: 'success' }, qtn: { fg: '#b26a16', bg: '#fbf0dd', tone: 'softAmber' }, sup: { fg: '#654e92', bg: '#efeaf8', tone: 'purple' }, dmg: { fg: '#b23b3b', bg: '#fdecec', tone: 'danger' }, dispose: { fg: '#fff', bg: '#1E2130', tone: 'dark' },
};
export const TRANSFER_REASON_LABELS = { shortage: { ar: 'نقص بالوجهة', en: 'Shortage' }, rebalance: { ar: 'إعادة توازن', en: 'Rebalance' }, season: { ar: 'موسم', en: 'Season' }, surplus: { ar: 'فائض بالمصدر', en: 'Surplus' }, other: { ar: 'أخرى', en: 'Other' } };
export const RETURN_STEPS = ['pending', 'approved', 'received', 'inspect', 'closed'];

/** Label of a code from a `{ code: { ar, en } }` map, falling back to the code itself. */
export const labelOf = (map, code, fallback = code) => map[code] || { ar: fallback, en: fallback };
export const pname = (p, l = lang.value) => (p ? (l === 'en' && p.nameEn ? p.nameEn : p.nameAr || p.sku) : '—');
export const sourceOf = (r, l = lang.value) => (l === 'en' && r.sourceEn) || r.sourceAr || r.customer?.nameAr || r.supplier?.nameAr || '—';
/** First value of a query-string param (vue-router may hand back an array). */
export const queryOf = (route, k) => { const v = route.query[k]; return (Array.isArray(v) ? v[0] : v) || null; };

/** Warehouse select options of the current user (computed). */
export function useWhOpts() {
  const wh = useWarehouse();
  return computed(() => wh.warehouses.map((w) => ({ v: w.code, l: `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}` })));
}

/** KPI counters of the returns tab: `{ pending, received, inspect, closed }` (computed totals, undefined while loading). */
export function useReturnKpis() {
  const ks = ['pending', 'received', 'inspect', 'closed'];
  const qs = ks.map((s) => useList('/returns', { status: s, pageSize: 1 }, { refetchInterval: 60_000 }));
  return computed(() => Object.fromEntries(ks.map((s, i) => [s, qs[i].data.value?.total])));
}

/** Deep link `?q=RTN-…`: when the search the page opened with yields exactly one record, open it. `initialQ` / `items` are getters or refs. */
export function useAutoOpen(initialQ, items, open) {
  watch(() => toValue(items), (list) => { if (toValue(initialQ) && list && list.length === 1) open(list[0].number); }, { immediate: true });
}

// ───────────────────────────────────────────── return actions (permission-gated state machine)
/**
 * Actions of one return. `ret` is a getter of the return record; `onDone` runs after every successful action.
 * Buttons are shown only in the matching state: approve / reject in `pending`, receive in `approved`, inspect in
 * `received`, decide only in `inspect` (permission `return.decide`; the others need `return.flow`).
 */
export function useReturnActions(ret, onDone) {
  const auth = useAuth();
  const act = useAction();
  const decideOpen = ref(false);
  const flow = computed(() => !!ret() && auth.can('return.flow'));
  const canDecide = computed(() => !!ret() && auth.can('return.decide') && ret().status === 'inspect');

  async function run(path, success, body) {
    const x = await act.run(() => api.postIdempotent(`/returns/${encodeURIComponent(ret().number)}/${path}`, body), { success: (res) => res?.message || success, invalidate: ['returns', 'inventory', 'dashboard'] });
    if (x !== undefined) onDone?.();
    return x;
  }
  const approve = () => run('approve', t('اعتُمد المرتجع — بانتظار الاستلام', 'Approved — awaiting receipt'));
  async function reject() {
    const reason = await ask({ title: { ar: `رفض المرتجع ${ret().number}؟`, en: `Reject ${ret().number}?` }, label: { ar: 'سبب الرفض', en: 'Rejection reason' }, tone: 'danger', okLabel: { ar: 'رفض', en: 'Reject' } });
    if (reason == null) return;
    await run('reject', t('رُفض المرتجع', 'Return rejected'), { reason: reason || undefined });
  }
  async function receive() {
    if (await confirm({ title: { ar: `استلام المرتجع ${ret().number}؟`, en: `Receive ${ret().number}?` }, sub: { ar: 'تُستلم الأصناف في منطقة المرتجعات بانتظار الفحص.', en: 'Items are received into the returns area pending inspection.' }, tone: 'dark' })) await run('receive', t('استُلم المرتجع — بانتظار الفحص', 'Received — awaiting inspection'));
  }
  async function inspect() {
    const findings = await ask({ title: { ar: `بدء فحص ${ret().number}`, en: `Start inspecting ${ret().number}` }, label: { ar: 'نتائج الفحص (اختياري)', en: 'Inspection findings (optional)' }, okLabel: { ar: 'بدء الفحص', en: 'Start inspection' } });
    if (findings == null) return;
    await run('inspect', t('بدأ الفحص — قرار مطلوب', 'Inspection started — decision needed'), { findings: findings || undefined });
  }
  return { act, flow, canDecide, decideOpen, approve, reject, receive, inspect };
}
