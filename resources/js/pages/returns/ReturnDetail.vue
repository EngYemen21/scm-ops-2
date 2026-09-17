<script setup>
// Return detail body (used by the drawer and the /rtn/:number page): chips + state-machine buttons, stepper, meta,
// tabs (lines · receipt / inspection / decisions · movements · history) and the decision modal.
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Btn, Chip, DataTable, EmptyState, ErrorBanner, KV, SectionCard, Stepper, Tabs, Timeline } from '@/components';
import { bi, fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { RETURN_DECISION_LABELS, RETURN_LABELS } from '@/shared';
import DecisionModal from './DecisionModal.vue';
import ReturnStatusChip from './ReturnStatusChip.vue';
import { DECISION_STYLE, RETURN_REASON_LABELS, RETURN_STEPS, RETURN_TYPE_LABELS, labelOf, pname, sourceOf, useReturnActions } from './shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['done']);

const router = useRouter();
const tab = ref('lines');
const { act, flow, canDecide, decideOpen, approve, reject, receive, inspect } = useReturnActions(() => props.ret, () => emit('done'));

const r = computed(() => props.ret);
const stepIdx = computed(() => (r.value.status === 'rejected' ? 1 : Math.max(0, RETURN_STEPS.indexOf(r.value.status))));
const steps = RETURN_STEPS.map((s) => ({ label: RETURN_LABELS[s] }));
const totalQty = computed(() => (r.value.lines || []).reduce((a, l) => a + (l.qty || 0), 0));
const partyLabel = computed(() => (r.value.type === 'sup' ? { ar: 'المورد', en: 'Supplier' } : { ar: 'العميل', en: 'Customer' }));
const party = computed(() => { const p = r.value.type === 'sup' ? r.value.supplier : r.value.customer; return p ? `${p.code} · ${p.nameAr}` : '—'; });
const reasonText = computed(() => (lang.value === 'en' && r.value.reasonEn) || r.value.reasonAr || bi(labelOf(RETURN_REASON_LABELS, r.value.reasonCode, '—')));
const closedText = computed(() => (r.value.closedAt ? `${fmtDate(r.value.closedAt)}${r.value.decision ? ` · ${bi(labelOf(RETURN_DECISION_LABELS, r.value.decision))}` : ''}${r.value.decidedBy ? ` · ${r.value.decidedBy}` : ''}` : '—'));

const tabs = computed(() => [
  { k: 'lines', label: { ar: 'الأصناف', en: 'Lines' }, badge: r.value.lines?.length },
  { k: 'flow', label: { ar: 'الاستلام والفحص والقرار', en: 'Receipt · inspection · decision' } },
  { k: 'moves', label: { ar: 'حركات المخزون', en: 'Movements' }, badge: r.value.movements?.length },
  { k: 'history', label: { ar: 'السجل', en: 'History' } },
]);
const lineCols = [
  { key: 'lineNo', header: '#', width: '36px', kind: 'num' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.4fr)' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num', value: (l) => fmtNum(l.qty) },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '110px', kind: 'id', value: (l) => l.batchNo || l.batch?.batchNo || '—' },
  { key: 'expiry', header: { ar: 'الصلاحية', en: 'Expiry' }, width: '90px', kind: 'date', value: (l) => (l.expiryDate || l.batch?.expiryDate ? fmtDateOnly(l.expiryDate || l.batch?.expiryDate) : '—') },
  { key: 'bin', header: { ar: 'الموقع', en: 'Bin' }, width: '90px', kind: 'id', value: (l) => l.bin?.code || '—' },
  { key: 'inspectedQty', header: { ar: 'مفحوص', en: 'Inspected' }, width: '75px', kind: 'num', value: (l) => l.inspectedQty ?? '—' },
  { key: 'condition', header: { ar: 'الحالة', en: 'Condition' }, width: '90px', kind: 'muted', value: (l) => l.condition || '—' },
  { key: 'decision', header: { ar: 'القرار', en: 'Decision' }, width: '120px' },
];
const moveCols = [
  { key: 'number', header: { ar: 'الحركة', en: 'Movement' }, width: '130px', kind: 'id' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '90px', kind: 'muted' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num', value: (m) => fmtNum(m.qty) },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '110px', kind: 'id', value: (m) => m.batchNo || '—' },
  { key: 'bins', header: { ar: 'من ← إلى', en: 'From → to' }, width: '160px', kind: 'id', value: (m) => `${m.srcBin?.code || '—'} ← ${m.dstBin?.code || '—'}` },
  { key: 'createdAt', header: { ar: 'الوقت', en: 'Time' }, width: '120px', kind: 'date', value: (m) => fmtDate(m.createdAt) },
];
// History entries: the label is the status chip (slot `chip`).
const history = computed(() => (r.value.history || []).map((h) => ({ at: h.at, label: '', status: h.toStatus, by: h.username, note: h.note })));
</script>

<template>
  <div class="col !gap-3">
    <div class="row wrap">
      <Chip :map="RETURN_TYPE_LABELS" :k="r.type" /><ReturnStatusChip :r="r" />
      <Chip v-if="r.reasonCode" small fg="#55506a" bg="#F1EFF6" :label="`${t('كود السبب', 'Reason code')}: ${bi(labelOf(RETURN_REASON_LABELS, r.reasonCode))}`" />
      <div class="grow" />
      <!-- state machine: each button only in its state -->
      <template v-if="flow && r.status === 'pending'">
        <Btn tone="dark" size="sm" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
        <Btn tone="dangerOutline" size="sm" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject" />
      </template>
      <Btn v-if="flow && r.status === 'approved'" tone="dark" size="sm" :loading="act.pending.value" :label="{ ar: 'استلام', en: 'Receive' }" @click="receive" />
      <Btn v-if="flow && r.status === 'received'" tone="purple" size="sm" :loading="act.pending.value" :label="{ ar: 'بدء الفحص', en: 'Inspect' }" @click="inspect" />
      <Btn v-if="canDecide" tone="primary" size="sm" :label="{ ar: 'القرار النهائي', en: 'Decision' }" @click="decideOpen = true" />
    </div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <Stepper :steps="steps" :current="stepIdx" :failed="r.status === 'rejected'" />

    <div class="kv-grid">
      <KV :k="{ ar: 'المصدر', en: 'Source' }" :v="sourceOf(r)" />
      <KV :k="{ ar: 'المرجع', en: 'Reference' }" :v="r.reference || '—'" />
      <KV :k="partyLabel" :v="party" />
      <KV :k="{ ar: 'المستودع', en: 'Warehouse' }" :v="r.warehouse ? `${r.warehouse.code} · ${r.warehouse.nameAr}` : '—'" />
      <KV :k="{ ar: 'أمر التجهيز / الرحلة', en: 'FO / trip' }">
        <span>
          <span v-if="r.fo?.number" class="cell-id cursor-pointer" @click="router.push(`/fo/${r.fo.number}`)">{{ r.fo.number }}</span><template v-else>—</template>
          <template v-if="r.trip?.number"> · <span class="cell-id cursor-pointer" @click="router.push(`/trip/${r.trip.number}`)">{{ r.trip.number }}</span></template>
        </span>
      </KV>
      <KV :k="{ ar: 'السبب', en: 'Reason' }" :v="reasonText" />
      <KV :k="{ ar: 'الأسطر / الكمية', en: 'Lines / qty' }"><span class="num">{{ r.lines?.length || 0 }} / {{ fmtNum(totalQty) }}</span></KV>
      <KV :k="{ ar: 'أُنشئ', en: 'Created' }" :v="`${fmtDate(r.createdAt)}${r.createdBy ? ` · ${r.createdBy}` : ''}`" />
      <KV :k="{ ar: 'اعتماد', en: 'Approved' }" :v="r.approvedAt ? fmtDate(r.approvedAt) : '—'" />
      <KV :k="{ ar: 'استلام', en: 'Received' }" :v="r.receivedAt ? fmtDate(r.receivedAt) : '—'" />
      <KV :k="{ ar: 'فحص', en: 'Inspected' }" :v="r.inspectedAt ? fmtDate(r.inspectedAt) : '—'" />
      <KV :k="{ ar: 'إقفال', en: 'Closed' }" :v="closedText" />
      <KV :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="r.notes || '—'" />
      <KV :k="{ ar: 'المرفقات', en: 'Attachments' }" :v="r.attachments?.length ? `${r.attachments.length} · Integration Pending` : '—'" />
    </div>

    <Tabs v-model="tab" variant="sm" :tabs="tabs" />
    <SectionCard v-if="tab === 'lines'" small :padded="false">
      <DataTable :columns="lineCols" :rows="r.lines || []" :row-key="(l) => l.id || l.lineNo" dense :min-width="860" :empty-text="{ ar: 'لا أسطر', en: 'No lines' }">
        <template #cell-product="{ row }"><div><div class="text-[11px] font-extrabold">{{ pname(row.product) }}</div><div class="cell-sub num">{{ row.product?.sku }}</div></div></template>
        <template #cell-decision="{ row }">
          <Chip v-if="row.decision" small :fg="DECISION_STYLE[row.decision]?.fg" :bg="DECISION_STYLE[row.decision]?.bg" :label="RETURN_DECISION_LABELS[row.decision] || row.decision" />
          <template v-else>—</template>
        </template>
      </DataTable>
    </SectionCard>

    <div v-if="tab === 'flow'" class="col !gap-2.5">
      <SectionCard small :title="{ ar: 'الاستلام', en: 'Receiving' }">
        <div v-if="r.receiving" class="text-[10.5px] leading-[1.9]">
          <span class="num">{{ fmtDate(r.receiving.receivedAt) }}</span> · {{ r.receiving.receivedBy || '—' }} · {{ t('الموقع', 'Bin') }}: <span class="num">{{ r.receiving.binCode || '—' }}</span>
          <div v-if="r.receiving.notes" class="muted">{{ r.receiving.notes }}</div>
        </div>
        <EmptyState v-else :text="{ ar: 'لم يُستلم بعد', en: 'Not received yet' }" />
      </SectionCard>
      <SectionCard small :title="{ ar: 'الفحص', en: 'Inspection' }">
        <div v-if="r.inspection" class="text-[10.5px] leading-[1.9]">
          <span class="num">{{ fmtDate(r.inspection.startedAt) }}</span> · {{ r.inspection.inspector || '—' }}
          <div>{{ r.inspection.findings || t('بدون نتائج مسجلة', 'No findings recorded') }}</div>
        </div>
        <EmptyState v-else :text="{ ar: 'لم يبدأ الفحص', en: 'Inspection not started' }" />
      </SectionCard>
      <SectionCard small :title="{ ar: 'القرارات', en: 'Decisions' }">
        <template v-if="(r.decisions || []).length">
          <div v-for="d in r.decisions" :key="d.id" class="row wrap border-b border-[#F7F6FA] py-1.5 text-[10.5px]">
            <Chip small :fg="DECISION_STYLE[d.decision]?.fg" :bg="DECISION_STYLE[d.decision]?.bg" :label="RETURN_DECISION_LABELS[d.decision] || d.decision" />
            <span class="num">{{ fmtNum(d.qty) }}</span><span class="muted">{{ d.decidedBy || '' }}</span><span class="num muted ltr">{{ fmtDate(d.at) }}</span>
            <span v-if="d.note">· {{ d.note }}</span><span v-if="d.movementId" class="muted">· {{ t('حركة مسجلة', 'movement recorded') }}</span>
          </div>
        </template>
        <EmptyState v-else :text="{ ar: 'لا قرار بعد', en: 'No decision yet' }" />
      </SectionCard>
    </div>

    <SectionCard v-if="tab === 'moves'" small :padded="false">
      <DataTable :columns="moveCols" :rows="r.movements || []" :row-key="(m) => m.number" dense :min-width="700" :empty-text="{ ar: 'لا حركات مخزون بعد — تُنشأ عند القرار', en: 'No movements yet — created at decision' }" />
    </SectionCard>
    <SectionCard v-if="tab === 'history'" small>
      <Timeline :items="history" :empty-text="{ ar: 'لا سجل', en: 'No history' }">
        <template #chip="{ item }"><span class="-ms-2 inline-flex"><Chip small :map="RETURN_LABELS" :k="item.status" /></span></template>
      </Timeline>
    </SectionCard>

    <DecisionModal v-if="decideOpen" :ret="r" @close="decideOpen = false" @done="emit('done')" />
  </div>
</template>
