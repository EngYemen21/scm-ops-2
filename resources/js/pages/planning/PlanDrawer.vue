<script setup>
// One purchase plan: its lines (product, warehouse, vendor, qty, price, order date) with what became of each — purchase
// request, purchase order, received — its totals and progress, and the moves its state allows:
//   draft       edit (PATCH plans/{id} { name, note, rfqGrouping }), edit a line (PlanLineEdit), cancel a line, submit, cancel
//   to_approve  approve { note } / return { reason } (purchaseplan.approve, never one he submitted), cancel
//   approved    request → purchase requests (all open lines or one; needs pr.create too), cancel a line, close { note }, cancel
// GET /planning/plans/{id} → plan + totals + lines + budget + run.
//   <PlanDrawer :plan-id="idOrNull" @close="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, ProgressBar, SelectInput, TextArea, TextInput } from '@/components';
import { bi, fmtDate, fmtNum, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import PlanLineEdit from './PlanLineEdit.vue';
import { GROUPING, LINE_STATUS, OWNED_CHIP, OWNED_HELP, PLAN_STATUS, fmtDay, label, money, options, qty, usePlanningLookups, usePlanningNav } from './planning';

const props = defineProps({
  planId: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = usePlanningNav();
const lk = usePlanningLookups();
const q = useGet(() => (props.planId ? `/planning/plans/${encodeURIComponent(props.planId)}` : null));
const plan = computed(() => (props.planId ? q.data.value || null : null));
const st = computed(() => plan.value?.status);
const owned = computed(() => !!plan.value?.owned);
const canManage = computed(() => auth.can('purchaseplan.manage') && !owned.value);
const canApprove = computed(() => auth.can('purchaseplan.approve') && !owned.value);
const canRequest = computed(() => canManage.value && auth.can('pr.create') && lk.can.value.request !== false);
const act = useAction({ invalidate: ['planning'] });
const url = (s = '') => `/planning/plans/${plan.value.id}${s}`;
const editingLine = ref(null);
const requests = ref(null);
const editingHead = ref(false);
const head = ref({ name: '', note: '', rfqGrouping: 'date' });
watch(() => props.planId, () => { editingLine.value = null; requests.value = null; editingHead.value = false; act.clearError(); });

// ---- moves
async function submit() {
  if (!(await confirm({ title: { ar: `رفع ${plan.value.number} للاعتماد؟`, en: `Submit ${plan.value.number} for approval?` }, tone: 'primary' }))) return;
  await act.run(() => api.postIdempotent(url('/submit')), { success: t('رُفعت الخطة للاعتماد', 'Submitted for approval') });
}
async function approve() {
  const note = await ask({ title: { ar: `اعتماد ${plan.value.number}`, en: `Approve ${plan.value.number}` }, label: { ar: 'ملاحظة (اختياري)', en: 'Note (optional)' }, okLabel: { ar: 'اعتماد', en: 'Approve' }, tone: 'primary' });
  if (note === null) return;
  await act.run(() => api.postIdempotent(url('/approve'), note ? { note } : undefined), { success: t('اعتُمدت الخطة', 'Plan approved') });
}
async function sendBack() {
  const reason = await ask({ title: { ar: `إعادة ${plan.value.number} إلى المسودة`, en: `Return ${plan.value.number} to draft` }, label: { ar: 'السبب', en: 'Reason' }, required: true, okLabel: { ar: 'إعادة', en: 'Return' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('/return'), { reason }), { success: t('أُعيدت الخطة إلى المسودة', 'Plan returned to draft') });
}
async function close() {
  const note = await ask({ title: { ar: `إغلاق ${plan.value.number}`, en: `Close ${plan.value.number}` }, label: { ar: 'ملاحظة (اختياري)', en: 'Note (optional)' }, okLabel: { ar: 'إغلاق', en: 'Close' }, tone: 'dark' });
  if (note === null) return;
  await act.run(() => api.postIdempotent(url('/close'), note ? { note } : undefined), { success: t('أُغلقت الخطة', 'Plan closed') });
}
async function cancel() {
  const reason = await ask({ title: { ar: `إلغاء ${plan.value.number}`, en: `Cancel ${plan.value.number}` }, label: { ar: 'السبب', en: 'Reason' }, required: true, okLabel: { ar: 'إلغاء الخطة', en: 'Cancel the plan' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('/cancel'), { reason }), { success: t('أُلغيت الخطة', 'Plan cancelled') });
}
async function request(line = null) {
  const title = line ? { ar: `تحويل البند ${line.lineNo} إلى طلب شراء؟`, en: `Turn line ${line.lineNo} into a purchase request?` } : { ar: 'تحويل كل البنود القائمة إلى طلبات شراء؟', en: 'Turn every open line into purchase requests?' };
  if (!(await confirm({ title, sub: { ar: `طلب لكل مستودع ومورد و${bi(GROUPING[plan.value.rfqGrouping] || GROUPING.date)} — يُرفع للمشتريات للمراجعة`, en: `One request per warehouse, vendor and ${bi(GROUPING[plan.value.rfqGrouping] || GROUPING.date).toLowerCase()} — submitted to procurement` }, tone: 'primary' }))) return;
  const r = await act.run(() => api.postIdempotent(url('/request'), line ? { lineIds: [line.id] } : undefined), { success: (x) => t(`أُنشئ ${x?.requests?.length || 0} طلب شراء`, `${x?.requests?.length || 0} purchase request(s) made`) });
  if (r) requests.value = r.requests || [];
}
async function cancelLine(line) {
  const reason = await ask({ title: { ar: `إلغاء البند ${line.lineNo} · ${line.sku}`, en: `Cancel line ${line.lineNo} · ${line.sku}` }, label: { ar: 'السبب', en: 'Reason' }, required: true, okLabel: { ar: 'إلغاء البند', en: 'Cancel the line' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url(`/lines/${line.id}/cancel`), { reason }), { success: t('أُلغي البند', 'Line cancelled') });
}
function editHead() { head.value = { name: plan.value.name || '', note: plan.value.note || '', rfqGrouping: plan.value.rfqGrouping || 'date' }; editingHead.value = true; }
async function saveHead() {
  const r = await act.run(() => api.patch(url(), { name: head.value.name || null, note: head.value.note || null, rfqGrouping: head.value.rfqGrouping }), { success: t('حُفظت الخطة', 'Plan saved') });
  if (r) editingHead.value = false;
}

const lineCanCancel = (l) => canManage.value && !l.cancelled && !l.request && ['draft', 'approved'].includes(st.value);
const columns = [
  { key: 'lineNo', header: '#', width: '40px', kind: 'num' },
  { key: 'sku', header: { ar: 'المنتج · المستودع', en: 'Product · warehouse' }, width: 'minmax(120px,1.2fr)' },
  { key: 'vendor', header: { ar: 'المورد', en: 'Vendor' }, width: 'minmax(100px,1fr)', value: (r) => r.vendor?.name || '—' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '60px', kind: 'num', value: (r) => qty(r.qty) },
  { key: 'subtotal', header: { ar: 'القيمة', en: 'Value' }, width: '92px', kind: 'num', value: (r) => (r.priceUnit == null ? t('بلا سعر', 'no price') : money(r.subtotal)) },
  { key: 'orderDate', header: { ar: 'تاريخ الطلب', en: 'Order date' }, width: '88px', kind: 'date' },
  { key: 'needDate', header: { ar: 'مطلوب في', en: 'Need' }, width: '84px', kind: 'date' },
  { key: 'status', header: { ar: 'المصير', en: 'Outcome' }, width: '150px', bare: true },
  { key: 'actions', header: '', width: '120px', bare: true },
];
const T = computed(() => plan.value?.totals || {});
</script>

<template>
  <Drawer :open="!!planId" :width="980" :z-index="75" @close="emit('close')">
    <template #title><span dir="auto">{{ plan ? plan.name || plan.number : planId }}</span></template>
    <template #sub>
      <span v-if="plan"><span class="num ltr inline-block">{{ plan.number }}</span> · <span class="num">{{ fmtDay(plan.dateFrom) }} → {{ fmtDay(plan.dateTo) }}</span> · {{ label(GROUPING, plan.rfqGrouping) }}<template v-if="plan.planner"> · <span dir="auto">{{ plan.planner }}</span></template></span>
    </template>
    <template v-if="plan" #headExtra>
      <span class="inline-flex gap-1"><Chip :map="PLAN_STATUS" :k="plan.status" /><Chip v-if="owned" :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" /></span>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[220px]" />
    <template v-if="plan">
      <div v-if="owned" class="hint purple !mt-0 mb-3">{{ bi(OWNED_HELP) }}</div>
      <div v-if="plan.returnReason && st === 'draft'" class="hint amber !mt-0 mb-3">{{ t('أُعيدت للمسودة:', 'Returned to draft:') }} <span dir="auto">{{ plan.returnReason }}</span></div>
      <div v-if="plan.cancelReason" class="hint amber !mt-0 mb-3">{{ t('سبب الإلغاء:', 'Cancel reason:') }} <span dir="auto">{{ plan.cancelReason }}</span></div>

      <!-- moves -->
      <div class="row wrap mb-3 !gap-1.5">
        <template v-if="st === 'draft' && canManage">
          <Btn size="sm" tone="primary" :loading="act.pending.value" :label="{ ar: 'رفع للاعتماد', en: 'Submit' }" @click="submit" />
          <Btn size="sm" tone="soft" :label="{ ar: 'تعديل الخطة', en: 'Edit plan' }" @click="editHead" />
        </template>
        <template v-if="st === 'to_approve' && canApprove">
          <Btn size="sm" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
          <Btn size="sm" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'إعادة للمسودة', en: 'Return' }" @click="sendBack" />
        </template>
        <template v-if="st === 'approved' && canManage">
          <Btn v-if="canRequest" size="sm" tone="purple" :loading="act.pending.value" :label="{ ar: 'تحويل إلى طلبات شراء', en: 'Make purchase requests' }" @click="request()" />
          <Btn size="sm" tone="dark" :loading="act.pending.value" :label="{ ar: 'إغلاق الخطة', en: 'Close' }" @click="close" />
        </template>
        <Btn v-if="['draft', 'to_approve', 'approved'].includes(st) && canManage && !T.requested" size="sm" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء الخطة', en: 'Cancel plan' }" @click="cancel" />
        <div class="grow" />
        <button v-if="plan.run" type="button" class="pill" @click="nav.patch({ plan: null, tab: null, run: plan.run.id })"><span class="num ltr inline-block">{{ plan.run.number }}</span></button>
        <button v-if="plan.budget" type="button" class="pill" @click="nav.patch({ plan: null, budget: plan.budget.id })" dir="auto">{{ plan.budget.name }}</button>
      </div>
      <div v-if="st === 'approved' && canManage && !canRequest" class="hint !mt-0 mb-3">{{ t('التحويل إلى طلبات شراء يحتاج أيضًا صلاحية إنشاء طلب الشراء.', 'Turning the plan into purchase requests also needs the permission to create a purchase request.') }}</div>
      <div v-if="requests" class="hint green !mt-0 mb-3">
        {{ t('طلبات الشراء المنشأة:', 'Purchase requests made:') }}
        <span v-for="r in requests" :key="r.id" class="me-2 inline-block"><span class="num ltr inline-block font-extrabold">{{ r.number }}</span> ({{ r.lines }} {{ t('بند', 'line(s)') }}<template v-if="r.vendor"> · <span dir="auto">{{ r.vendor }}</span></template>)</span>
      </div>

      <div v-if="editingHead" class="card mb-3 !p-3">
        <div class="form-grid">
          <TextInput v-model="head.name" :label="{ ar: 'الاسم', en: 'Name' }" />
          <SelectInput v-model="head.rfqGrouping" :options="options(GROUPING)" :label="{ ar: 'تجميع طلبات الشراء', en: 'Group purchase requests' }" />
          <TextArea v-model="head.note" :label="{ ar: 'ملاحظة', en: 'Note' }" full />
        </div>
        <div class="row mt-2 !justify-end">
          <Btn size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="editingHead = false" />
          <Btn size="sm" tone="primary" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="saveHead" />
        </div>
      </div>

      <!-- totals -->
      <div class="mb-2 grid grid-cols-2 gap-2 md:grid-cols-5">
        <div class="tile"><div class="tile-v">{{ money(T.budget) }}</div><div class="tile-l">{{ t('ميزانية أشهر الخطة', 'Budget of the months') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ money(T.planned) }}</div><div class="tile-l">{{ t('المخطط', 'Planned') }} · <span class="num">{{ T.lines ?? 0 }}</span> {{ t('بند', 'lines') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(T.committed) }}</div><div class="tile-l">{{ t('الملتزم به (أوامر شراء)', 'Committed (POs)') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ money(T.receivedValue) }}</div><div class="tile-l">{{ t('المستلم', 'Received') }}</div></div>
        <div class="tile" :class="num(T.remainingBudget) < 0 ? 'red' : ''"><div class="tile-v">{{ money(T.remainingBudget) }}</div><div class="tile-l">{{ t('المتبقي من الميزانية', 'Budget left') }}</div></div>
      </div>
      <div class="row mb-1 text-[11px] font-bold"><span>{{ t('تقدّم الاستلام (كمية)', 'Received progress (qty)') }}</span><div class="grow" /><span class="num">{{ fmtNum(num(T.progressPct), 1) }}%</span></div>
      <ProgressBar class="mb-2" :pct="num(T.progressPct)" />
      <div class="row wrap mb-3 !gap-1.5 text-[10.5px]">
        <span class="pill !cursor-default">{{ t('طُلبت', 'Requested') }} <span class="num">{{ T.requested ?? 0 }}</span></span>
        <span class="pill !cursor-default">{{ t('بأمر شراء', 'Ordered') }} <span class="num">{{ T.ordered ?? 0 }}</span></span>
        <span class="pill !cursor-default">{{ t('استُلمت', 'Received') }} <span class="num">{{ T.received ?? 0 }}</span></span>
        <span class="pill !cursor-default">{{ t('ملغاة', 'Cancelled') }} <span class="num">{{ T.cancelled ?? 0 }}</span></span>
        <span class="pill !cursor-default" :class="T.lateOrder ? 'text-bad' : ''">{{ t('طلب متأخر', 'Late to order') }} <span class="num">{{ T.lateOrder ?? 0 }}</span></span>
        <span class="pill !cursor-default" :class="T.lateReceipt ? 'text-bad' : ''">{{ t('استلام متأخر', 'Late receipt') }} <span class="num">{{ T.lateReceipt ?? 0 }}</span></span>
      </div>

      <PlanLineEdit v-if="editingLine" :plan-id="plan.id" :line="editingLine" @close="editingLine = null" />

      <div class="card">
        <DataTable :columns="columns" :rows="plan.lines || []" :row-key="(r) => r.id" :page-size="50" compact :empty-text="{ ar: 'لا بنود.', en: 'No line.' }"
                   :row-style="(r) => (r.cancelled ? { opacity: 0.55 } : null)">
          <template #cell-sku="{ row }">
            <div class="min-w-0">
              <div class="num truncate text-[10.5px] font-extrabold ltr">{{ row.sku }}</div>
              <div class="cell-sub">{{ lk.whCode(row.warehouseId) }}<template v-if="row.priceUnit != null"> · <span class="num">{{ qty(row.priceUnit) }}</span></template></div>
            </div>
          </template>
          <template #cell-orderDate="{ row }"><span class="num" :class="row.lateOrder ? 'font-extrabold text-bad' : ''">{{ fmtDay(row.orderDate) }}</span></template>
          <template #cell-needDate="{ row }"><span class="num" :class="row.lateReceipt ? 'font-extrabold text-bad' : ''">{{ fmtDay(row.needDate) }}</span></template>
          <template #cell-status="{ row }">
            <span class="inline-flex min-w-0 flex-col items-center gap-0.5">
              <Chip small :map="LINE_STATUS" :k="row.status" />
              <span v-if="row.request" class="num text-[9px] text-sec">{{ row.request.number }}</span>
              <span v-if="row.purchaseOrder" class="num text-[9px] text-violet">{{ row.purchaseOrder.number }} · {{ qty(row.receivedQty) }}/{{ qty(row.orderedQty) }}</span>
              <span v-if="row.cancelled && row.cancelReason" class="truncate text-[9px] text-muted" :title="row.cancelReason">{{ row.cancelReason }}</span>
            </span>
          </template>
          <template #cell-actions="{ row }">
            <span class="inline-flex flex-wrap justify-center gap-1">
              <Btn v-if="st === 'draft' && canManage && !row.cancelled" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="editingLine = row" />
              <Btn v-if="st === 'approved' && canRequest && !row.cancelled && !row.request" size="sm" tone="softPurple" :label="{ ar: 'طلب', en: 'Request' }" @click.stop="request(row)" />
              <Btn v-if="lineCanCancel(row)" size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click.stop="cancelLine(row)" />
            </span>
          </template>
        </DataTable>
      </div>

      <div class="kv-grid mt-3">
        <KV :k="{ ar: 'أُنشئت', en: 'Created' }" :v="fmtDate(plan.createdAt)" ltr />
        <KV v-if="plan.submittedAt" :k="{ ar: 'رُفعت', en: 'Submitted' }" :v="`${fmtDate(plan.submittedAt)} · ${plan.submittedBy || ''}`" />
        <KV v-if="plan.approvedAt" :k="{ ar: 'اعتُمدت', en: 'Approved' }" :v="`${fmtDate(plan.approvedAt)} · ${plan.approvedBy || ''}`" />
        <KV v-if="plan.closedAt" :k="{ ar: 'أُغلقت', en: 'Closed' }" :v="`${fmtDate(plan.closedAt)} · ${plan.closedBy || ''}${plan.closeNote ? ` · ${plan.closeNote}` : ''}`" />
        <KV v-if="plan.cancelledAt" :k="{ ar: 'أُلغيت', en: 'Cancelled' }" :v="`${fmtDate(plan.cancelledAt)} · ${plan.cancelledBy || ''}`" />
        <KV v-if="plan.note" :k="{ ar: 'ملاحظة', en: 'Note' }" :v="plan.note" />
      </div>
    </template>
  </Drawer>
</template>
