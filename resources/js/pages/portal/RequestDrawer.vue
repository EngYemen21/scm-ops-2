<script setup>
// One order request: who asked for what, its lines, what it became (a quotation here, a sale order in Odoo), what was
// changed on its lines — and its decision: approve (a draft quotation in Sales), refuse, cancel; correct its lines
// while it waits.
//   <RequestDrawer :id="idOrNull" @close="…" @changed="…" />
// GET /portal/requests/{id} → the request + { lines, changes, events, can }. The buttons follow `can`; a request that
// Odoo owns offers only notes.
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, Tabs, TextArea, Timeline } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import PartyName from './PartyName.vue';
import RequestForm from './RequestForm.vue';
import { CHANGE_FIELD, CHANGE_TYPE, OWNED_CHIP, REQUEST_STATUS, customerLine, eventItem, labelOf, moment, money, usePortalNav } from './portal';

const props = defineProps({
  /** Id (or OPS number) of the request; null closes the drawer. */
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'changed']);

const auth = useAuth();
const nav = usePortalNav();
const canManage = computed(() => auth.can('portal.manage'));
const canQuote = computed(() => auth.can('sales.manage'));
const base = computed(() => (props.id ? `/portal/requests/${encodeURIComponent(props.id)}` : null));
const q = useGet(base);
const r = computed(() => (props.id ? q.data.value || null : null));
const act = useAction({ invalidate: ['portal', 'sales'] });
const events = computed(() => (r.value?.events || []).map(eventItem));

const pane = ref('lines');
watch(() => props.id, () => { pane.value = 'lines'; noteText.value = ''; });
const panes = computed(() => [
  { k: 'lines', label: { ar: 'الأسطر', en: 'Lines' }, badge: r.value?.linesCount || null },
  { k: 'changes', label: { ar: 'التغييرات على الأسطر', en: 'Line changes' }, badge: r.value?.changes.length || null },
  { k: 'history', label: { ar: 'السجل والملاحظات', en: 'History & notes' } },
]);
function done() { q.refetch(); emit('changed'); }

async function approve() {
  if (!(await confirm({ title: { ar: `اعتماد الطلب ${r.value.number}؟`, en: `Approve request ${r.value.number}?` }, sub: { ar: 'يُنشأ من أسطره عرض سعر مسودة في المبيعات للعميل (صالح 14 يومًا)، ويُتابَع من صفحة المبيعات.', en: 'A draft quotation is made from its lines in Sales for the customer (valid 14 days) and followed from the sales page.' },
    okLabel: { ar: 'اعتماد وإنشاء عرض السعر', en: 'Approve and make the quotation' }, tone: 'dark' }))) return;
  const x = await act.run(() => api.postIdempotent(`${base.value}/approve`), { success: (d) => t(`اعتُمد الطلب وأُنشئ عرض السعر ${d.quotation?.number || ''}`, `Approved — quotation ${d.quotation?.number || ''} made`) });
  if (x) done();
}
async function reject() {
  const reason = await ask({ title: { ar: `رفض الطلب ${r.value.number}؟`, en: `Reject request ${r.value.number}?` }, label: { ar: 'سبب الرفض', en: 'Reason' }, required: true, okLabel: { ar: 'رفض', en: 'Reject' }, tone: 'danger' });
  if (reason === null) return;
  const x = await act.run(() => api.postIdempotent(`${base.value}/reject`, { reason }), { success: t('رُفض الطلب', 'Request rejected') });
  if (x) done();
}
async function cancel() {
  const reason = await ask({ title: { ar: `إلغاء الطلب ${r.value.number}؟`, en: `Cancel request ${r.value.number}?` }, sub: { ar: 'للطلب الذي تراجع عنه العميل. يبقى في السجل ملغى.', en: 'For a request the customer withdrew. It stays on record as cancelled.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إلغاء الطلب', en: 'Cancel the request' }, tone: 'danger' });
  if (reason === null) return;
  const x = await act.run(() => api.postIdempotent(`${base.value}/cancel`, reason ? { reason } : {}), { success: t('أُلغي الطلب', 'Request cancelled') });
  if (x) done();
}
const noteText = ref('');
async function addNote() {
  const x = await act.run(() => api.postIdempotent(`${base.value}/notes`, { text: noteText.value.trim() }), { success: t('أُضيفت الملاحظة', 'Note added') });
  if (x) { noteText.value = ''; done(); }
}
const editing = ref(false);
const hasActions = computed(() => !!r.value && ['approve', 'reject', 'cancel', 'editLines'].some((k) => r.value.can[k]));

const lineCols = [
  { key: 'lineNo', header: '#', width: '36px', kind: 'num' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.6fr)' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num' },
  { key: 'price', header: { ar: 'سعر الوحدة', en: 'Unit price' }, width: '90px', kind: 'num', value: (l) => money(l.price) },
  { key: 'subtotal', header: { ar: 'الصافي', en: 'Net' }, width: '96px', kind: 'num', value: (l) => money(l.subtotal) },
];
const changeCols = [
  { key: 'changedAt', header: { ar: 'الوقت', en: 'When' }, width: '118px', kind: 'date', value: (c) => moment(c.changedAt) },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(150px,1.5fr)' },
  { key: 'type', header: { ar: 'التغيير', en: 'Change' }, width: '104px', bare: true },
  { key: 'before', header: { ar: 'قبل', en: 'Before' }, width: '70px', kind: 'num' },
  { key: 'after', header: { ar: 'بعد', en: 'After' }, width: '70px', kind: 'num' },
  { key: 'changedBy', header: { ar: 'غيّره', en: 'By' }, width: 'minmax(100px,1fr)' },
];
/** The whole log of this request (or of the order it became), in the change-log view. */
const allChanges = () => nav.go('requests', { view: 'changes', ...(r.value.sourceSystem ? { orderRef: r.value.order?.number || r.value.order?.ref } : { request: r.value.id }) });
</script>

<template>
  <Drawer :open="!!id" :width="720" @close="emit('close')">
    <template #title><span class="num ltr">{{ r?.number || t('طلب شراء', 'Order request') }}</span></template>
    <template v-if="r" #sub><span dir="auto">{{ customerLine(r.customer) }}<template v-if="r.branch"> · {{ t('فرع', 'branch') }} {{ r.branch.name }}</template></span></template>
    <template v-if="r" #headExtra>
      <div class="row !gap-1">
        <Chip small :map="REQUEST_STATUS" :k="r.status" />
        <Chip v-if="r.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="r">
      <div v-if="r.owned" class="hint purple !mt-0 mb-3">{{ t('هذا الطلب يُدار من أودو: قراره وأسطره هناك، ويُعرض هنا بما صار إليه وما تغيّر على أمره. تُضاف هنا الملاحظات.', 'This request is managed in Odoo: its decision and its lines are made there; it is shown here with what it became and what was changed on its order. Notes are added here.') }}</div>
      <div v-if="r.status === 'rejected' && r.rejectionReason" class="hint amber !mt-0 mb-3"><b>{{ t('سبب الرفض:', 'Rejected because:') }}</b> {{ r.rejectionReason }}</div>

      <div class="kpi-grid compact !mb-3">
        <div class="tile purple"><div class="tile-v">{{ money(r.amountTotal) }}</div><div class="tile-l">{{ t('الإجمالي', 'Total') }} {{ r.currency }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtNum(r.linesCount) }}</div><div class="tile-l">{{ t('عدد الأسطر', 'Lines') }}</div></div>
        <div v-if="r.decisionHours != null" class="tile"><div class="tile-v">{{ fmtNum(Number(r.decisionHours), 1) }}</div><div class="tile-l">{{ t('ساعات حتى القرار', 'Hours to the decision') }}</div></div>
      </div>
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'العميل', en: 'Customer' }"><PartyName :party="r.customer" /></KV>
        <KV :k="{ ar: 'الفرع', en: 'Branch' }" :v="r.branch ? r.branch.name : null" />
        <KV :k="{ ar: 'طلبه', en: 'Requested by' }" :v="r.requestedBy" />
        <KV :k="{ ar: 'تاريخ الطلب', en: 'Requested' }" :v="moment(r.requestDate)" ltr />
        <KV :k="{ ar: 'القرار', en: 'Decision' }"><span v-if="r.decidedAt"><span dir="auto">{{ r.decidedBy || '—' }}</span> · <span class="num ltr">{{ moment(r.decidedAt) }}</span></span><span v-else class="text-warn">{{ t('بانتظار القرار', 'Waiting') }}</span></KV>
        <KV :k="{ ar: 'صار', en: 'Became' }">
          <RouterLink v-if="r.quotation" :to="`/sales?tab=qt&q=${encodeURIComponent(r.quotation.number)}`" class="num ltr font-extrabold">{{ r.quotation.number }}</RouterLink>
          <span v-else-if="r.order" class="num ltr" :title="t('أمر البيع في أودو', 'The sale order in Odoo')">{{ r.order.number || `#${r.order.ref}` }} <span class="font-normal text-faint">· Odoo</span></span>
          <span v-else class="text-faint">—</span>
        </KV>
        <KV v-if="r.priceList" :k="{ ar: 'قائمة الأسعار', en: 'Price list' }" :v="r.priceList" />
        <KV v-if="r.note" class="col-span-full" :k="{ ar: 'ملاحظة الطلب', en: 'Request note' }" :v="r.note" />
      </div>

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-2.5" />
      <div v-if="pane === 'lines'" class="overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="lineCols" :rows="r.lines" :row-key="(l) => l.id" dense :empty-text="{ ar: 'لا أسطر.', en: 'No lines.' }">
          <template #cell-product="{ row }">
            <PartyName v-if="row.product" :party="row.product" product />
            <span v-else dir="auto">{{ row.description || '—' }}</span>
          </template>
        </DataTable>
      </div>
      <template v-else-if="pane === 'changes'">
        <div class="overflow-hidden rounded-xl border border-line-2">
          <DataTable :columns="changeCols" :rows="r.changes" :row-key="(c) => c.id" dense :empty-text="{ ar: 'لم يتغيّر شيء على أسطر هذا الطلب.', en: 'Nothing was changed on the lines of this request.' }">
            <template #cell-product="{ row }">
              <PartyName v-if="row.product" :party="row.product" product />
              <span v-else dir="auto">{{ row.productName || '—' }}</span>
            </template>
            <template #cell-type="{ row }">
              <Chip small :map="CHANGE_TYPE" :k="row.type" />
              <div v-if="row.field || row.fieldLabel" class="mt-0.5 text-[9px] text-muted">{{ row.field ? labelOf(CHANGE_FIELD, row.field) : row.fieldLabel }}</div>
            </template>
            <template #cell-before="{ row }"><span v-if="row.oldValue != null" class="num text-muted line-through">{{ row.oldValue }}</span><span v-else class="text-faint">—</span></template>
            <template #cell-after="{ row }"><span v-if="row.newValue != null" class="num font-extrabold">{{ row.newValue }}</span><span v-else class="text-faint">—</span></template>
            <template #cell-changedBy="{ row }"><span class="truncate" dir="auto">{{ row.changedBy || '—' }}</span></template>
          </DataTable>
        </div>
        <div v-if="r.changes.length >= 200" class="mt-1.5 text-[9.5px] text-faint">{{ t('تظهر أحدث 200 تغيير.', 'The newest 200 changes are shown.') }} <button type="button" class="cursor-pointer border-0 bg-transparent p-0 font-extrabold text-violet" @click="allChanges">{{ t('كل التغييرات في السجل', 'Every change, in the log') }}</button></div>
      </template>
      <template v-else>
        <div v-if="canManage" class="mb-3">
          <TextArea v-model="noteText" :rows="2" :label="{ ar: 'ملاحظة على الطلب', en: 'A note on the request' }" />
          <div class="mt-1.5"><Btn size="sm" tone="softPurple" :disabled="!noteText.trim()" :loading="act.pending.value" :label="{ ar: 'إضافة الملاحظة', en: 'Add the note' }" @click="addNote" /></div>
        </div>
        <Timeline :items="events" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }" />
      </template>
    </template>

    <template v-if="r && canManage && hasActions" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="r.can.approve && canQuote" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد — عرض سعر', en: 'Approve — quotation' }" @click="approve" />
        <Btn v-if="r.can.editLines" tone="dark" :label="{ ar: 'تصحيح الأسطر', en: 'Correct the lines' }" @click="editing = true" />
        <Btn v-if="r.can.reject" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject" />
        <Btn v-if="r.can.cancel" tone="soft" :loading="act.pending.value" :label="{ ar: 'إلغاء الطلب', en: 'Cancel the request' }" @click="cancel" />
      </div>
      <div v-if="r.can.approve && !canQuote" class="mt-2 text-[10px] font-bold text-warn">{{ t('الاعتماد ينشئ عرض سعر في المبيعات ويحتاج صلاحية إدارة المبيعات.', 'Approving makes a quotation in Sales and needs the sales permission.') }}</div>
    </template>
  </Drawer>

  <RequestForm :open="editing" :request="r" @close="editing = false" @done="() => { editing = false; done(); }" />
</template>
