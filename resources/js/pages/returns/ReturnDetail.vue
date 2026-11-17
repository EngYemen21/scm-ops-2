<script setup>
// Return detail body (used by the drawer and the /rtn/:number page): chips + state-machine buttons (each with its
// disabled-with-reason state), stepper, meta, tabs (lines · receipt / inspection / decisions / documents · movements ·
// history) and the receive / inspect / decision modals.
// Two paths: goods that arrive (approve → receive → inspect → decide) and goods taken from stock (approve → ship).
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Btn, Chip, DataTable, EmptyState, ErrorBanner, KV, SectionCard, Stepper, Tabs, Timeline } from '@/components';
import { bi, fmtDate, fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { MOVEMENT_LABELS, RETURN_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import DebitNoteModal from './DebitNoteModal.vue';
import DecisionModal from './DecisionModal.vue';
import InspectModal from './InspectModal.vue';
import ReceiveModal from './ReceiveModal.vue';
import ReturnStatusChip from './ReturnStatusChip.vue';
import { DECISION_STYLE, RETURN_CONDITION_LABELS, RETURN_REASON_LABELS, RETURN_TYPE_LABELS, decisionLabel, decisionWhere, labelOf, pname, sourceOf, stepsOf, useReturnActions } from './shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['done']);

const router = useRouter();
const auth = useAuth();
const tab = ref('lines');
const { act, flow, fromStock, canDecide, canCancel, cancelBlock, blocked, canIssueNote, canCancelNote, decideOpen, receiveOpen, inspectOpen, noteOpen, approve, reject, cancel, receive, inspect, ship, issueNote, cancelNote } = useReturnActions(() => props.ret, () => emit('done'));

const r = computed(() => props.ret);
const stepKeys = computed(() => stepsOf(r.value));
const steps = computed(() => stepKeys.value.map((s) => ({ label: RETURN_LABELS[s] })));
const stopped = computed(() => ['rejected', 'cancelled'].includes(r.value.status));
/** A rejected / cancelled return stops at the step it never completed. */
const stepIdx = computed(() => (stopped.value ? (r.value.status === 'cancelled' && r.value.approvedAt ? 2 : 1) : Math.max(0, stepKeys.value.indexOf(r.value.status))));
const totalQty = computed(() => (r.value.lines || []).reduce((a, l) => a + (l.qty || 0), 0));
const wasReceived = computed(() => (r.value.lines || []).some((l) => l.receivedQty != null));
const receivedQty = computed(() => (r.value.lines || []).reduce((a, l) => a + (l.receivedQty || 0), 0));
const partyLabel = computed(() => (r.value.type === 'sup' ? { ar: 'المورد', en: 'Supplier' } : { ar: 'العميل', en: 'Customer' }));
const party = computed(() => { const p = r.value.type === 'sup' ? r.value.supplier : r.value.customer; return p ? `${p.code} · ${p.nameAr}` : '—'; });
const reasonText = computed(() => (lang.value === 'en' && r.value.reasonEn) || r.value.reasonAr || bi(labelOf(RETURN_REASON_LABELS, r.value.reasonCode, '—')));
const closedText = computed(() => (r.value.closedAt ? `${fmtDate(r.value.closedAt)}${r.value.decision ? ` · ${bi(decisionLabel(r.value.decision))}` : ''}${r.value.decidedBy ? ` · ${r.value.decidedBy}` : ''}` : '—'));
const credit = computed(() => r.value.flow?.credit);
const activeNotes = computed(() => (r.value.debitNotes || []).filter((n) => n.status !== 'cancelled'));

/** The step the return is waiting for and the permission it needs — shown to whoever cannot do it, so nobody faces a dead end. */
const nextStep = computed(() => {
  if (blocked.value) return null;
  const s = r.value.status;
  const step = s === 'pending' ? { ar: 'الاعتماد أو الرفض', en: 'Approval or rejection', perm: 'return.flow' }
    : s === 'approved' ? (fromStock.value ? { ar: r.value.type === 'sup' ? 'الشحن إلى المورد' : 'النقل إلى منطقة التالف', en: r.value.type === 'sup' ? 'Shipping to the supplier' : 'Moving to the damaged area', perm: 'return.flow' } : { ar: 'استلام البضاعة في المستودع', en: 'Receiving the goods', perm: 'return.flow' })
      : s === 'received' ? { ar: 'الفحص', en: 'Inspection', perm: 'return.flow' }
        : s === 'inspect' ? { ar: 'القرار النهائي لكل سطر', en: 'The decision per line', perm: 'return.decide' } : null;
  return step && !auth.can(step.perm) ? step : null;
});

const tabs = computed(() => [
  { k: 'lines', label: { ar: 'الأصناف', en: 'Lines' }, badge: r.value.lines?.length },
  { k: 'flow', label: fromStock.value ? { ar: 'الحجز والشحن والمستندات', en: 'Hold · shipping · documents' } : { ar: 'الاستلام والفحص والقرار', en: 'Receipt · inspection · decision' } },
  { k: 'moves', label: { ar: 'حركات المخزون', en: 'Movements' }, badge: r.value.movements?.length },
  { k: 'history', label: { ar: 'السجل', en: 'History' } },
]);
const lineCols = computed(() => {
  const c = (key, ar, en, width, kind, value) => ({ key, header: { ar, en }, width, kind, value });
  const head = [{ key: 'lineNo', header: '#', width: '36px', kind: 'num' }, { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.4fr)' }, c('qty', 'الكمية', 'Qty', '64px', 'num', (l) => fmtNum(l.qty))];
  const batch = [c('batch', 'الدفعة', 'Batch', '104px', 'id', (l) => l.batchNo || l.batch?.batchNo || '—'), c('expiry', 'الصلاحية', 'Expiry', '86px', 'date', (l) => (l.expiryDate || l.batch?.expiryDate ? fmtDateOnly(l.expiryDate || l.batch?.expiryDate) : '—'))];
  const decision = { key: 'decision', header: { ar: 'القرار', en: 'Decision' }, width: '118px' };
  if (fromStock.value) {
    return [...head, c('fromBin', 'من موقع', 'From bin', '96px', 'id', (l) => l.fromBin?.code || '—'), ...batch,
      ...(r.value.type === 'sup' ? [c('unitPrice', 'سعر الوحدة', 'Unit price', '86px', 'num', (l) => (l.unitPrice != null ? fmtMoney(l.unitPrice) : t('سعر الشراء', 'PO price')))] : []), decision];
  }
  return [...head, c('receivedQty', 'المستلم', 'Received', '66px', 'num', (l) => (l.receivedQty != null ? fmtNum(l.receivedQty) : '—')), ...batch,
    c('inspectedQty', 'مفحوص', 'Inspected', '66px', 'num', (l) => l.inspectedQty ?? '—'), { key: 'condition', header: { ar: 'الحالة', en: 'Condition' }, width: '104px' },
    c('bin', 'الموقع', 'Bin', '90px', 'id', (l) => l.bin?.code || '—'), decision];
});
const moveCols = [
  { key: 'number', header: { ar: 'الحركة', en: 'Movement' }, width: '128px', kind: 'id' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '110px', kind: 'muted', value: (m) => bi(labelOf(MOVEMENT_LABELS, m.type)) },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '64px', kind: 'num', value: (m) => fmtNum(m.qty) },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '104px', kind: 'id', value: (m) => m.batchNo || '—' },
  { key: 'bins', header: { ar: 'من → إلى', en: 'From → to' }, width: '170px' },
  { key: 'createdAt', header: { ar: 'الوقت', en: 'Time' }, width: '118px', kind: 'date', value: (m) => fmtDate(m.createdAt) },
];
// History entries: the label is the status chip (slot `chip`).
const history = computed(() => (r.value.history || []).map((h) => ({ at: h.at, label: '', status: h.toStatus, by: h.username, note: h.note })));
const noteChip = (n) => (n.status === 'cancelled' ? { fg: '#7d7990', bg: '#f1eff6', label: { ar: 'ملغى', en: 'Cancelled' } } : { fg: '#1d7a3e', bg: '#e6f9ec', label: { ar: 'صادر', en: 'Issued' } });
</script>

<template>
  <div class="col !gap-3">
    <div class="row wrap">
      <Chip :map="RETURN_TYPE_LABELS" :k="r.type" /><ReturnStatusChip :r="r" />
      <Chip v-if="fromStock" small fg="#0d7f93" bg="#d9f4f9" :label="{ ar: 'من المخزون', en: 'From stock' }" />
      <Chip v-if="r.reasonCode" small fg="#55506a" bg="#F1EFF6" :label="`${t('كود السبب', 'Reason code')}: ${bi(labelOf(RETURN_REASON_LABELS, r.reasonCode))}`" />
      <div class="grow" />
      <!-- state machine: each button only in its state; a refused action is shown disabled with its reason below -->
      <template v-if="flow && r.status === 'pending'">
        <Btn tone="dark" size="sm" :loading="act.pending.value" :disabled="!!blocked" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
        <Btn tone="dangerOutline" size="sm" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject" />
      </template>
      <template v-if="flow && r.status === 'approved'">
        <Btn v-if="fromStock" :tone="r.type === 'sup' ? 'purple' : 'danger'" size="sm" :loading="act.pending.value" :label="r.type === 'sup' ? { ar: 'شحن للمورد', en: 'Ship to supplier' } : { ar: 'نقل للتالف', en: 'Move to damaged' }" @click="ship" />
        <Btn v-else tone="dark" size="sm" :loading="act.pending.value" :disabled="!!blocked" :label="{ ar: 'استلام', en: 'Receive' }" @click="receive" />
      </template>
      <Btn v-if="flow && r.status === 'received'" tone="purple" size="sm" :loading="act.pending.value" :label="{ ar: 'بدء الفحص', en: 'Inspect' }" @click="inspect" />
      <Btn v-if="flow && r.status === 'inspect'" tone="soft" size="sm" :label="{ ar: 'تعديل الفحص', en: 'Edit inspection' }" @click="inspect" />
      <Btn v-if="canDecide" tone="primary" size="sm" :label="{ ar: 'القرار النهائي', en: 'Decision' }" @click="decideOpen = true" />
      <Btn v-if="canIssueNote" tone="purple" size="sm" :loading="act.pending.value" :label="{ ar: 'إصدار إشعار مدين', en: 'Issue debit note' }" @click="issueNote" />
      <Btn v-if="canCancel" tone="softRed" size="sm" :loading="act.pending.value" :disabled="!!cancelBlock" :title="cancelBlock ? bi(cancelBlock) : null" :label="{ ar: 'إلغاء المرتجع', en: 'Cancel return' }" @click="cancel" />
    </div>
    <div v-if="canCancel && cancelBlock" class="text-end text-[10px] font-bold text-warn">{{ t('الإلغاء غير متاح الآن', 'Cancel is not available now') }}: {{ bi(cancelBlock) }}</div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="blocked" class="hint amber !mt-0">{{ bi(blocked) }}</div>
    <div v-if="nextStep" class="hint !mt-0">{{ t(`الخطوة التالية: ${nextStep.ar} — ليست ضمن صلاحياتك (${nextStep.perm}).`, `Next step: ${nextStep.en} — not within your permissions (${nextStep.perm}).`) }}</div>
    <Stepper :steps="steps" :current="stepIdx" :failed="stopped" />

    <div class="kv-grid">
      <KV :k="{ ar: 'المصدر', en: 'Source' }" :v="sourceOf(r)" />
      <KV :k="{ ar: 'المرجع', en: 'Reference' }" :v="r.reference || '—'" />
      <KV :k="partyLabel" :v="party" />
      <KV :k="{ ar: 'المستودع', en: 'Warehouse' }" :v="r.warehouse ? `${r.warehouse.code} · ${r.warehouse.nameAr}` : '—'" />
      <KV v-if="r.type === 'sup'" :k="{ ar: 'أمر الشراء / سند الاستلام', en: 'PO / GRN' }">
        <span>
          <span v-if="r.po?.number" class="cell-id cursor-pointer" @click="router.push(`/po/${r.po.number}`)">{{ r.po.number }}</span><template v-else>—</template>
          <template v-if="r.grn?.number"> · <span class="cell-id">{{ r.grn.number }}</span></template>
        </span>
      </KV>
      <KV v-else :k="{ ar: 'أمر التجهيز / الرحلة', en: 'FO / trip' }">
        <span>
          <span v-if="r.fo?.number" class="cell-id cursor-pointer" @click="router.push(`/fo/${r.fo.number}`)">{{ r.fo.number }}</span><template v-else>—</template>
          <template v-if="r.trip?.number"> · <span class="cell-id cursor-pointer" @click="router.push(`/trip/${r.trip.number}`)">{{ r.trip.number }}</span></template>
        </span>
      </KV>
      <KV :k="{ ar: 'السبب', en: 'Reason' }" :v="reasonText" />
      <KV :k="{ ar: 'الأسطر / الكمية', en: 'Lines / qty' }">
        <span class="num">{{ r.lines?.length || 0 }} / {{ fmtNum(totalQty) }}</span>
        <span v-if="wasReceived" class="ms-1 text-[10px]" :class="receivedQty < totalQty ? 'text-warn' : 'text-ok'">· {{ t('استُلم', 'received') }} <span class="num">{{ fmtNum(receivedQty) }}</span></span>
      </KV>
      <KV :k="{ ar: 'أُنشئ', en: 'Created' }" :v="`${fmtDate(r.createdAt)}${r.createdBy ? ` · ${r.createdBy}` : ''}`" />
      <KV :k="{ ar: 'اعتماد', en: 'Approved' }" :v="r.approvedAt ? fmtDate(r.approvedAt) : '—'" />
      <template v-if="fromStock">
        <KV :k="{ ar: 'الشحن', en: 'Shipped' }" :v="r.shippedAt ? fmtDate(r.shippedAt) : '—'" />
        <KV :k="{ ar: 'الناقل', en: 'Carrier' }" :v="r.carrier || '—'" />
      </template>
      <template v-else>
        <KV :k="{ ar: 'استلام', en: 'Received' }" :v="r.receivedAt ? fmtDate(r.receivedAt) : '—'" />
        <KV :k="{ ar: 'فحص', en: 'Inspected' }" :v="r.inspectedAt ? fmtDate(r.inspectedAt) : '—'" />
      </template>
      <KV :k="{ ar: 'إقفال', en: 'Closed' }" :v="closedText" />
      <KV v-if="r.status === 'cancelled'" :k="{ ar: 'الإلغاء', en: 'Cancelled' }" :v="`${r.cancelledAt ? fmtDate(r.cancelledAt) : ''}${r.cancelledBy ? ` · ${r.cancelledBy}` : ''}${r.cancelReason ? ` — ${r.cancelReason}` : ''}`" />
      <KV :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="r.notes || '—'" />
      <KV :k="{ ar: 'المرفقات', en: 'Attachments' }" :v="r.attachments?.length ? `${r.attachments.length} · Integration Pending` : '—'" />
    </div>

    <!-- the financial side, said plainly -->
    <div v-if="r.creditNote" class="hint teal !mt-0">{{ t('إشعار دائن للعميل', 'Customer credit note') }} <span class="num font-extrabold">{{ r.creditNote.number }}</span> · <span class="num">{{ fmtMoney(r.creditNote.total) }}</span> {{ t('ر.س', 'SAR') }}<template v-if="r.creditNote.invoice"> · {{ t('على الفاتورة', 'on invoice') }} <span class="num">{{ r.creditNote.invoice }}</span></template></div>
    <div v-else-if="credit === 'expected' && ['pending', 'approved'].includes(r.status)" class="hint teal !mt-0">{{ t('مرتبط بتسليم مفوتر: عند الاستلام تُعكس تكلفة المبيعات ويصدر إشعار دائن للعميل بالكمية المستلمة.', 'Linked to an invoiced delivery: on receipt the cost of sales is reversed and a credit note is issued for the received quantity.') }}</div>
    <div v-else-if="credit === 'expected'" class="hint amber !mt-0">{{ t('استُلم المرتجع ولم يصدر إشعار دائن (لا فاتورة سارية تغطي الكمية) — راجِع المالية.', 'Received without a credit note (no active invoice covers the quantity) — check with finance.') }}</div>
    <div v-else-if="credit === 'none' && !stopped" class="hint amber !mt-0">{{ t('لا مرجع تسليم (أمر تجهيز أو فاتورة) على هذا المرتجع: يُستلم بمتوسط التكلفة ولن يصدر إشعار دائن للعميل.', 'No delivery reference (fulfilment order or invoice) on this return: it is received at average cost and no customer credit note will be issued.') }}</div>
    <div v-for="n in activeNotes" :key="n.number" class="hint teal !mt-0">{{ t('إشعار مدين على المورد', 'Supplier debit note') }} <span class="num font-extrabold">{{ n.number }}</span> · <span class="num">{{ fmtMoney(n.total) }}</span> {{ t('ر.س شامل الضريبة', 'SAR incl. VAT') }}<template v-if="n.supplier"> · {{ n.supplier.nameAr }}</template></div>

    <Tabs v-model="tab" variant="sm" :tabs="tabs" />
    <SectionCard v-if="tab === 'lines'" small :padded="false">
      <DataTable :columns="lineCols" :rows="r.lines || []" :row-key="(l) => l.id || l.lineNo" dense :empty-text="{ ar: 'لا أسطر', en: 'No lines' }">
        <template #cell-product="{ row }"><div><div class="text-[11px] font-extrabold">{{ pname(row.product) }}</div><div class="cell-sub num">{{ row.product?.sku }}</div></div></template>
        <template #cell-condition="{ row }">
          <div v-if="row.condition"><Chip small fg="#55506a" bg="#F1EFF6" :label="labelOf(RETURN_CONDITION_LABELS, row.condition)" /><div v-if="row.inspectNote" class="cell-sub">{{ row.inspectNote }}</div></div>
          <template v-else>—</template>
        </template>
        <template #cell-decision="{ row }">
          <Chip v-if="row.decision" small :fg="DECISION_STYLE[row.decision]?.fg" :bg="DECISION_STYLE[row.decision]?.bg" :label="decisionLabel(row.decision)" />
          <Chip v-else-if="row.receivedQty === 0" small fg="#7d7990" bg="#f1eff6" :label="{ ar: 'لم يُستلم', en: 'Not received' }" />
          <template v-else>—</template>
        </template>
      </DataTable>
    </SectionCard>

    <div v-if="tab === 'flow'" class="col !gap-2.5">
      <template v-if="fromStock">
        <SectionCard small :title="{ ar: 'الحجز في المواقع', en: 'Held in the bins' }">
          <template v-if="(r.holds || []).length">
            <div v-for="(h, i) in r.holds" :key="i" class="row wrap border-b border-[#F7F6FA] py-1.5 text-[10.5px]">
              <span class="cell-id">{{ h.bin }}</span><span v-if="h.batchNo" class="num muted">{{ h.batchNo }}</span><span class="num font-extrabold">{{ fmtNum(h.qty) }}</span>
              <span class="muted">{{ t('محجوزة — لا تُباع ولا تُنقل', 'held — cannot be sold or moved') }}</span>
            </div>
          </template>
          <EmptyState v-else :text="r.status === 'pending' ? { ar: 'تُحجز الكمية في مواقعها عند الاعتماد', en: 'The quantity is held in its bins at approval' } : { ar: 'لا كمية محجوزة الآن', en: 'Nothing is held now' }" />
        </SectionCard>
        <SectionCard small :title="{ ar: 'الشحن', en: 'Shipping' }">
          <div v-if="r.shippedAt" class="text-[10.5px] leading-[1.9]">
            <span class="num">{{ fmtDate(r.shippedAt) }}</span> · {{ r.decidedBy || '—' }}<template v-if="r.carrier"> · {{ t('الناقل', 'Carrier') }}: {{ r.carrier }}</template>
            <div v-if="r.shipNote" class="muted">{{ r.shipNote }}</div>
          </div>
          <EmptyState v-else :text="{ ar: 'لم يُشحن بعد', en: 'Not shipped yet' }" />
        </SectionCard>
      </template>
      <template v-else>
        <SectionCard small :title="{ ar: 'الاستلام', en: 'Receiving' }">
          <div v-if="r.receiving" class="text-[10.5px] leading-[1.9]">
            <span class="num">{{ fmtDate(r.receiving.receivedAt) }}</span> · {{ r.receiving.receivedBy || '—' }} · {{ t('الموقع', 'Bin') }}: <span class="num">{{ r.receiving.binCode || '—' }}</span>
            <template v-if="wasReceived"> · {{ t('استُلم', 'received') }} <span class="num">{{ fmtNum(receivedQty) }}</span> {{ t('من', 'of') }} <span class="num">{{ fmtNum(totalQty) }}</span></template>
            <div v-if="r.receiving.notes" class="muted">{{ r.receiving.notes }}</div>
          </div>
          <EmptyState v-else :text="{ ar: 'لم يُستلم بعد', en: 'Not received yet' }" />
        </SectionCard>
        <SectionCard small :title="{ ar: 'الفحص', en: 'Inspection' }">
          <div v-if="r.inspection" class="text-[10.5px] leading-[1.9]">
            <span class="num">{{ fmtDate(r.inspection.startedAt) }}</span> · {{ r.inspection.inspector || '—' }}
            <div>{{ r.inspection.findings || t('بدون نتائج عامة مسجلة', 'No overall findings recorded') }}</div>
            <div v-for="l in (r.lines || []).filter((x) => x.condition)" :key="l.id" class="muted">
              #<span class="num">{{ l.lineNo }}</span> {{ pname(l.product) }} — {{ bi(labelOf(RETURN_CONDITION_LABELS, l.condition)) }} · <span class="num">{{ l.inspectedQty ?? '—' }}</span><template v-if="l.inspectNote"> · {{ l.inspectNote }}</template>
            </div>
          </div>
          <EmptyState v-else :text="{ ar: 'لم يبدأ الفحص', en: 'Inspection not started' }" />
        </SectionCard>
      </template>
      <SectionCard small :title="{ ar: 'القرارات', en: 'Decisions' }">
        <template v-if="(r.decisions || []).length">
          <div v-for="d in r.decisions" :key="d.id" class="row wrap border-b border-[#F7F6FA] py-1.5 text-[10.5px]">
            <Chip small :fg="DECISION_STYLE[d.decision]?.fg" :bg="DECISION_STYLE[d.decision]?.bg" :label="decisionLabel(d.decision)" />
            <span class="num font-extrabold">{{ fmtNum(d.qty) }}</span>
            <span v-if="d.lineNo" class="muted">#<span class="num">{{ d.lineNo }}</span> <span class="num">{{ d.sku }}</span></span>
            <span v-if="decisionWhere(d)" class="num ltr">{{ decisionWhere(d) }}</span>
            <span class="muted">{{ d.decidedBy || '' }}</span><span class="num muted ltr">{{ fmtDate(d.at) }}</span>
            <span v-if="d.note">· {{ d.note }}</span><span v-if="d.movementId" class="muted">· {{ t('حركة مسجلة', 'movement recorded') }}</span>
          </div>
        </template>
        <EmptyState v-else :text="{ ar: 'لا قرار بعد', en: 'No decision yet' }" />
      </SectionCard>
      <SectionCard v-if="(r.debitNotes || []).length || r.creditNote" small :title="{ ar: 'المستندات المالية', en: 'Financial documents' }">
        <div v-if="r.creditNote" class="row wrap border-b border-[#F7F6FA] py-1.5 text-[10.5px]">
          <b>{{ t('إشعار دائن', 'Credit note') }}</b><span class="cell-id">{{ r.creditNote.number }}</span><span class="num font-extrabold">{{ fmtMoney(r.creditNote.total) }}</span>
          <span v-if="r.creditNote.invoice" class="muted">{{ t('على الفاتورة', 'on invoice') }} <span class="num">{{ r.creditNote.invoice }}</span></span>
        </div>
        <div v-for="n in r.debitNotes || []" :key="n.number" class="border-b border-[#F7F6FA] py-1.5 text-[10.5px]">
          <div class="row wrap">
            <b>{{ t('إشعار مدين', 'Debit note') }}</b><span class="cell-id">{{ n.number }}</span><Chip small :fg="noteChip(n).fg" :bg="noteChip(n).bg" :label="noteChip(n).label" />
            <span v-if="n.supplier" class="muted">{{ n.supplier.code }} · {{ n.supplier.nameAr }}</span>
            <div class="grow" />
            <span>{{ t('قبل الضريبة', 'Net') }} <span class="num">{{ fmtMoney(n.subtotal) }}</span> · {{ t('ضريبة', 'VAT') }} <span class="num">{{ fmtNum(n.vatPct) }}%</span> <span class="num">{{ fmtMoney(n.vatAmount) }}</span> · <b>{{ t('الإجمالي', 'Total') }} <span class="num">{{ fmtMoney(n.total) }}</span></b></span>
            <Btn v-if="canCancelNote && n.status !== 'cancelled'" tone="softRed" size="sm" :loading="act.pending.value" :label="{ ar: 'إلغاء الإشعار', en: 'Cancel note' }" @click="cancelNote(n)" />
          </div>
          <div v-for="(l, i) in n.lines || []" :key="i" class="muted ps-2">
            <span class="num">{{ l.sku }}</span> × <span class="num">{{ fmtNum(l.qty) }}</span> @ <span class="num">{{ fmtMoney(l.price) }}</span>
            <span v-if="auth.can('inventory.view_cost')"> · {{ t('تكلفة الخروج', 'cost out') }} <span class="num">{{ fmtMoney(l.cost) }}</span></span>
          </div>
        </div>
      </SectionCard>
    </div>

    <SectionCard v-if="tab === 'moves'" small :padded="false">
      <DataTable :columns="moveCols" :rows="r.movements || []" :row-key="(m) => m.number" dense :empty-text="fromStock ? { ar: 'لا حركات مخزون بعد — تُنشأ عند الشحن', en: 'No movements yet — created at shipping' } : { ar: 'لا حركات مخزون بعد — تُنشأ عند الاستلام والقرار', en: 'No movements yet — created at receipt and decision' }">
        <template #cell-bins="{ row }"><span class="num ltr inline-block" dir="ltr" :title="row.note || null">{{ row.srcBin?.code || '—' }} → {{ row.dstBin?.code || '—' }}</span></template>
      </DataTable>
    </SectionCard>
    <SectionCard v-if="tab === 'history'" small>
      <Timeline :items="history" :empty-text="{ ar: 'لا سجل', en: 'No history' }">
        <template #chip="{ item }"><span class="-ms-2 inline-flex"><Chip small :map="RETURN_LABELS" :k="item.status" /></span></template>
      </Timeline>
    </SectionCard>

    <DecisionModal v-if="decideOpen" :ret="r" @close="decideOpen = false" @done="emit('done')" />
    <ReceiveModal v-if="receiveOpen" :ret="r" @close="receiveOpen = false" @done="emit('done')" />
    <InspectModal v-if="inspectOpen" :ret="r" @close="inspectOpen = false" @done="emit('done')" />
    <DebitNoteModal v-if="noteOpen" :ret="r" @close="noteOpen = false" @done="emit('done')" />
  </div>
</template>
