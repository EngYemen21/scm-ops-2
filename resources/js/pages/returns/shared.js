// Returns & transfers helpers — labels, small formatters and the composables shared by the list tabs, the drawer and
// the full page. Components of the domain live beside this file (ReturnDetail, ReturnDrawer, DecisionModal, ReturnForm,
// TransferForm …). All data comes from /api/returns and /api/inventory/transfers.
import { computed, ref, toValue, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { bi, lang, t } from '@/i18n';
import { RETURN_DECISION_LABELS } from '@/shared';
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
/** Chip colours + button tone of each final decision (`mixed` = a return whose lines were decided differently). */
export const DECISION_STYLE = {
  restock: { fg: '#1d7a3e', bg: '#e6f9ec', tone: 'success' }, qtn: { fg: '#b26a16', bg: '#fbf0dd', tone: 'softAmber' }, sup: { fg: '#654e92', bg: '#efeaf8', tone: 'purple' }, dmg: { fg: '#b23b3b', bg: '#fdecec', tone: 'danger' }, dispose: { fg: '#fff', bg: '#1E2130', tone: 'dark' },
  mixed: { fg: '#3C79F5', bg: '#e8effe', tone: 'primary' },
};
export const TRANSFER_REASON_LABELS = { shortage: { ar: 'نقص بالوجهة', en: 'Shortage' }, rebalance: { ar: 'إعادة توازن', en: 'Rebalance' }, season: { ar: 'موسم', en: 'Season' }, surplus: { ar: 'فائض بالمصدر', en: 'Surplus' }, other: { ar: 'أخرى', en: 'Other' } };
/** Steps of a return whose goods arrive (customer / delivery). */
export const RETURN_STEPS = ['pending', 'approved', 'received', 'inspect', 'closed'];
/** Steps of a return taken from stock (to a supplier / damaged goods): no receipt, no inspection — approved, then shipped. */
export const RETURN_STOCK_STEPS = ['pending', 'approved', 'shipped', 'closed'];
/** What the inspector found on a line. */
export const RETURN_CONDITION_LABELS = {
  good: { ar: 'سليم', en: 'Good' }, damaged: { ar: 'تالف', en: 'Damaged' }, expired: { ar: 'منتهي الصلاحية', en: 'Expired' }, defective: { ar: 'معيب', en: 'Defective' }, opened: { ar: 'مفتوح', en: 'Opened' },
};
/** Where each decision sends the goods — shown under the decision pills so nobody has to guess. */
export const DECISION_EFFECT = {
  restock: { ar: 'يعود إلى موقع تخزين ويدخل المتاح', en: 'Goes back to a storage bin and becomes available' },
  qtn: { ar: 'إلى منطقة الحجر QTN-01 — يبقى في الرصيد ولا يدخل المتاح', en: 'To quarantine QTN-01 — on hand, not available' },
  dmg: { ar: 'إلى منطقة التالف DMG-01 — يبقى في الرصيد ولا يدخل المتاح', en: 'To the damaged area DMG-01 — on hand, not available' },
  dispose: { ar: 'يخرج من المخزون نهائيًا (إعدام موثق)', en: 'Leaves stock for good (documented scrap)' },
  sup: { ar: 'يخرج من المخزون إلى المورد ويصدر به إشعار مدين', en: 'Leaves stock to the supplier with a debit note' },
};
export const MIXED_DECISION = { ar: 'قرارات متعددة', en: 'Mixed decisions' };

/** Label of a code from a `{ code: { ar, en } }` map, falling back to the code itself. */
export const labelOf = (map, code, fallback = code) => map[code] || { ar: fallback, en: fallback };
/** `{ ar, en }` of a decision code, `mixed` included. */
export const decisionLabel = (k) => (k === 'mixed' ? MIXED_DECISION : labelOf(RETURN_DECISION_LABELS, k));
export const pname = (p, l = lang.value) => (p ? (l === 'en' && p.nameEn ? p.nameEn : p.nameAr || p.sku) : '—');
export const sourceOf = (r, l = lang.value) => (l === 'en' && r.sourceEn) || r.sourceAr || r.customer?.nameAr || r.supplier?.nameAr || '—';
/** First value of a query-string param (vue-router may hand back an array). */
export const queryOf = (route, k) => { const v = route.query[k]; return (Array.isArray(v) ? v[0] : v) || null; };
/** A return taken from stock (purchase return / damaged goods from their bin). */
export const isFromStock = (r) => r?.sourceMode === 'stock';
/** Steps a return goes through — depends on whether its goods arrive or are taken from stock. */
export const stepsOf = (r) => (isFromStock(r) ? RETURN_STOCK_STEPS : RETURN_STEPS);
/** Quantity that actually arrived on a line (null until the return is received). */
export const receivedOf = (l) => (l?.receivedQty ?? null);

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
 * Actions of one return. `ret` is a getter of the return record (GET /returns/:number, with its `flow` block);
 * `onDone` runs after every successful action. Buttons are shown only in the matching state:
 *   goods that arrive:  approve / reject (pending) → receive (approved) → inspect (received) → decide (inspect)
 *   taken from stock:   approve / reject (pending) → ship (approved)
 *   cancel in pending / approved — `cancelBlock` carries the reason when the server would refuse it.
 * `receive`, `inspect`, `decide` and `issueNote` open their modal (`receiveOpen`, `inspectOpen`, `decideOpen`, `noteOpen`);
 * the others ask and post. Permissions: `return.decide` for the decision, `gl.approve` to cancel a debit note,
 * `return.flow` for everything else.
 */
export function useReturnActions(ret, onDone) {
  const auth = useAuth();
  const act = useAction();
  const decideOpen = ref(false);
  const receiveOpen = ref(false);
  const inspectOpen = ref(false);
  const noteOpen = ref(false);
  const flow = computed(() => !!ret() && auth.can('return.flow'));
  const fromStock = computed(() => isFromStock(ret()));
  const canDecide = computed(() => !!ret() && auth.can('return.decide') && ret().status === 'inspect');
  const canCancel = computed(() => flow.value && ['pending', 'approved'].includes(ret().status));
  /** Why cancelling is refused right now ({ ar, en }), or null. */
  const cancelBlock = computed(() => { const c = ret()?.flow?.cancel; return c && !c.allowed && c.reasonAr ? { ar: c.reasonAr, en: c.reasonEn || c.reasonAr } : null; });
  /** Why the return cannot move forward at all ({ ar, en }) — a supplier / damaged return created without a source bin. */
  const blocked = computed(() => { const b = ret()?.flow?.blocked; return b ? { ar: b.reasonAr, en: b.reasonEn || b.reasonAr } : null; });
  /** Goods already sent to a supplier with no standing debit note (its note was cancelled): a corrected one can be issued. */
  const canIssueNote = computed(() => {
    const r = ret();
    if (!r || !flow.value || !['shipped', 'closed'].includes(r.status)) return false;
    const sent = (r.decisions || []).some((d) => d.decision === 'sup' && d.movementId);
    return sent && !(r.debitNotes || []).some((n) => n.status !== 'cancelled');
  });

  async function run(path, success, body) {
    const x = await act.run(() => api.postIdempotent(`/returns/${encodeURIComponent(ret().number)}/${path}`, body), { success: (res) => res?.message || success, invalidate: ['returns', 'inventory', 'dashboard', 'finance'] });
    if (x !== undefined) onDone?.();
    return x;
  }
  async function approve() {
    if (fromStock.value && !(await confirm({ title: { ar: `اعتماد ${ret().number}؟`, en: `Approve ${ret().number}?` }, sub: { ar: 'تُحجز كمية كل سطر في موقعها فلا تُباع ولا تُنقل حتى الشحن أو الإلغاء.', en: 'Each line is held in its bin — it cannot be sold or moved until shipped or cancelled.' }, tone: 'dark' }))) return;
    await run('approve', fromStock.value ? t('اعتُمد المرتجع — الكمية محجوزة بانتظار الشحن', 'Approved — stock held for shipping') : t('اعتُمد المرتجع — بانتظار الاستلام', 'Approved — awaiting receipt'));
  }
  async function reject() {
    const reason = await ask({ title: { ar: `رفض المرتجع ${ret().number}؟`, en: `Reject ${ret().number}?` }, label: { ar: 'سبب الرفض', en: 'Rejection reason' }, tone: 'danger', okLabel: { ar: 'رفض', en: 'Reject' } });
    if (reason == null) return;
    await run('reject', t('رُفض المرتجع', 'Return rejected'), { reason: reason || undefined });
  }
  async function cancel() {
    const reason = await ask({
      title: { ar: `إلغاء المرتجع ${ret().number}؟`, en: `Cancel ${ret().number}?` },
      sub: fromStock.value && ret().status === 'approved' ? { ar: 'تعود الكمية المحجوزة إلى المتاح.', en: 'The held quantity goes back to available.' } : { ar: 'لا يُستلم بعدها ولا يُعاد فتحه.', en: 'It can no longer be received or reopened.' },
      label: { ar: 'سبب الإلغاء', en: 'Cancellation reason' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء المرتجع', en: 'Cancel return' },
    });
    if (reason == null) return;
    await run('cancel', t('أُلغي المرتجع', 'Return cancelled'), { reason });
  }
  async function ship() {
    const r = ret();
    if (r.type === 'sup') {
      const carrier = await ask({
        title: { ar: `شحن ${r.number} إلى المورد؟`, en: `Ship ${r.number} to the supplier?` },
        sub: { ar: 'تخرج الكمية المحجوزة من مواقعها ويصدر إشعار مدين على المورد بسعر الشراء والضريبة. لا يُتراجع عنه.', en: 'The held quantity leaves its bins and a debit note is issued at the purchase price + VAT. This cannot be undone.' },
        label: { ar: 'الناقل / المستلم (اختياري)', en: 'Carrier / receiver (optional)' }, okLabel: { ar: 'شحن وإصدار الإشعار', en: 'Ship and issue the note' },
      });
      if (carrier == null) return;
      await run('ship', t('شُحن للمورد وصدر إشعار المدين', 'Shipped — debit note issued'), { carrier: carrier || undefined });
    } else if (await confirm({ title: { ar: `نقل أصناف ${r.number} إلى منطقة التالف؟`, en: `Move ${r.number} to the damaged area?` }, sub: { ar: 'تنتقل الكمية المحجوزة من مواقعها إلى DMG-01: تبقى في الرصيد ولا تدخل المتاح.', en: 'The held quantity moves from its bins to DMG-01: on hand, not available.' }, tone: 'danger', okLabel: { ar: 'نقل للتالف', en: 'Move to damaged' } })) {
      await run('ship', t('نُقلت الأصناف إلى منطقة التالف', 'Moved to the damaged area'));
    }
  }
  /** Finance cancels a standing debit note (its journal is reversed; the goods stay returned) — `gl.approve`. */
  const canCancelNote = computed(() => !!ret() && auth.can('gl.approve'));
  async function cancelNote(note) {
    const reason = await ask({
      title: { ar: `إلغاء إشعار المدين ${note.number}؟`, en: `Cancel debit note ${note.number}?` },
      sub: { ar: 'يُعكس قيد الإشعار (فرق السعر والضريبة) والبضاعة تبقى مُرجعة للمورد. يمكن بعدها إصدار إشعار مصحح من هذا المرتجع.', en: 'The journal of the note (price difference + VAT) is reversed; the goods stay returned. A corrected note can then be issued from this return.' },
      label: { ar: 'سبب الإلغاء (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء الإشعار', en: 'Cancel the note' },
    });
    if (reason == null) return;
    const x = await act.run(() => api.postIdempotent(`/finance/debit-notes/${encodeURIComponent(note.number)}/cancel`, { reason }), { success: t('أُلغي إشعار المدين وعُكس قيده', 'Debit note cancelled — its journal was reversed'), invalidate: ['returns', 'finance', 'dashboard'] });
    if (x !== undefined) onDone?.();
  }
  const receive = () => { receiveOpen.value = true; };
  const inspect = () => { inspectOpen.value = true; };
  const issueNote = () => { noteOpen.value = true; };
  return { act, flow, fromStock, canDecide, canCancel, cancelBlock, blocked, canIssueNote, canCancelNote, decideOpen, receiveOpen, inspectOpen, noteOpen, approve, reject, cancel, receive, inspect, ship, issueNote, cancelNote };
}

/** One-line text of a decision row: quantity · where it went. */
export const decisionWhere = (d) => [d.binCode, d.batchNo, d.supplier ? `${d.supplier.code} · ${bi({ ar: d.supplier.nameAr, en: d.supplier.nameEn || d.supplier.nameAr })}` : null].filter(Boolean).join(' · ');
