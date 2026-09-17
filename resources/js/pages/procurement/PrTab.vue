<script setup>
// Purchase requisitions tab: list + row actions (submit · approve · reject with reason · convert to PO / RFQ).
// Emits `new-rfq` with an RfqForm `initial` when the user wants to pick suppliers manually for a PR.
import { ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import FilterBar from './FilterBar.vue';
import PrToPoDrawer from './PrToPoDrawer.vue';
import PrToRfqDrawer from './PrToRfqDrawer.vue';
import ReasonDrawer from './ReasonDrawer.vue';
import { PRIORITY_LABELS, PR_STATUS_LABELS, linesSummary, pname } from './shared';

const emit = defineEmits(['newRfq']);
const auth = useAuth();
const wh = useWarehouse();
const act = useAction({ invalidate: ['procurement'] });

const q = ref('');
const status = ref('');
const page = ref(1);
watch([q, status, () => wh.whParams.warehouse], () => { page.value = 1; });
const list = useList('/procurement/pr', () => ({ q: q.value, status: status.value, page: page.value, pageSize: 25, ...wh.whParams }));

/** PRs currently in the reject / to-PO / to-RFQ drawers (null = closed). */
const reject = ref(null);
const toPo = ref(null);
const toRfq = ref(null);

const inReview = (r) => ['submitted', 'review'].includes(r.status);
const submitPr = (r) => act.run(() => api.postIdempotent(`/procurement/pr/${r.number}/submit`), { success: t(`أُرسل ${r.number} للمراجعة`, `${r.number} submitted`) });
async function approve(r) {
  const yes = await confirm({ title: { ar: `اعتماد ${r.number}؟`, en: `Approve ${r.number}?` }, sub: { ar: 'بعد الاعتماد يمكن تحويله إلى RFQ أو أمر شراء', en: 'After approval it can be converted to an RFQ or PO' }, tone: 'dark', okLabel: { ar: 'اعتماد', en: 'Approve' } });
  if (yes) void act.run(() => api.postIdempotent(`/procurement/pr/${r.number}/approve`, {}), { success: t(`اعتُمد ${r.number}`, `${r.number} approved`) });
}
async function doReject(reason) {
  const pr = reject.value;
  const r = await act.run(() => api.postIdempotent(`/procurement/pr/${pr.number}/reject`, { reason }), { success: t(`رُفض ${pr.number}`, `${pr.number} rejected`) });
  if (r) reject.value = null;
}
function closeReject() { reject.value = null; act.clearError(); }
function manualRfq(pr) {
  toRfq.value = null;
  emit('newRfq', { prNumber: pr.number, lines: pr.lines.map((l) => ({ sku: l.product.sku, name: pname(l.product), qty: l.qty })), deliveryWarehouseCode: pr.warehouse.code, notes: pr.justification });
}

const STATUSES = [
  { k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'submitted,review', label: { ar: 'بانتظار المراجعة', en: 'Pending review' } }, { k: 'approved', label: { ar: 'معتمد', en: 'Approved' } },
  { k: 'converted', label: { ar: 'حُوّل', en: 'Converted' } }, { k: 'rejected', label: { ar: 'مرفوض', en: 'Rejected' } }, { k: 'draft', label: { ar: 'مسودة', en: 'Draft' } },
];
const columns = [
  { key: 'number', header: { ar: 'الطلب', en: 'PR' }, width: '110px', kind: 'id' },
  { key: 'wh', header: { ar: 'المستودع', en: 'Warehouse' }, width: '95px', class: 'text-[9.5px]', value: (r) => `${r.warehouse.code} · ${pname(r.warehouse)}` },
  { key: 'items', header: { ar: 'الأصناف', en: 'Items' }, width: 'minmax(160px,1.3fr)', kind: 'name', value: (r) => linesSummary(r.lines) },
  { key: 'needDate', header: { ar: 'تاريخ الحاجة', en: 'Need by' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.needDate) },
  { key: 'priority', header: { ar: 'الأولوية', en: 'Priority' }, width: '80px' },
  { key: 'why', header: { ar: 'المبرر', en: 'Reason' }, width: 'minmax(150px,1.2fr)', class: 'text-[9.5px] text-muted', value: (r) => r.justification },
  { key: 'by', header: { ar: 'الطالب', en: 'Requester' }, width: '100px', class: 'text-[10px] font-bold', value: (r) => r.requestedBy },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '130px' },
  { key: 'act', header: '', width: '220px' },
];
</script>

<template>
  <FilterBar v-model="q"><Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" /></FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1000" :empty-text="{ ar: 'لا طلبات شراء مطابقة.', en: 'No matching requisitions.' }" @page="page = $event">
      <template #cell-priority="{ row }"><Chip :map="PRIORITY_LABELS" :k="row.priority" small /></template>
      <template #cell-status="{ row }"><Chip :map="PR_STATUS_LABELS" :k="row.status" /></template>
      <template #cell-act="{ row }">
        <div class="row wrap justify-end !gap-[5px]">
          <Btn v-if="row.status === 'draft' && auth.can('pr.create')" size="sm" tone="success" :loading="act.pending.value" :label="{ ar: 'إرسال للمراجعة', en: 'Submit' }" @click="submitPr(row)" />
          <template v-if="inReview(row) && auth.can('pr.approve')">
            <Btn size="sm" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve(row)" />
            <Btn size="sm" tone="dangerOutline" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject = row" />
          </template>
          <Btn v-if="row.status === 'approved' && auth.can('po.create')" size="sm" tone="dark" :label="{ ar: 'تحويل إلى PO', en: 'To PO' }" @click="toPo = row" />
          <Btn v-if="row.status === 'approved' && auth.can('rfq.create')" size="sm" tone="softPurple" label="RFQ" @click="toRfq = row" />
        </div>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('طلب الشراء الداخلي يُراجَع من المشتريات؛ بعد اعتماده يُحوَّل إلى RFQ (طلب عروض من عدة موردين) أو مباشرة إلى أمر شراء عند وجود مورد معتمد وسعر متفق عليه.', 'Requisitions are reviewed by procurement; once approved they convert to an RFQ (multi-supplier quotes) or directly to a PO when a preferred supplier and agreed price exist.') }}</div>

  <ReasonDrawer :open="!!reject" :title="{ ar: `رفض ${reject?.number || ''}`, en: `Reject ${reject?.number || ''}` }" :ok-label="{ ar: 'رفض', en: 'Reject' }" :pending="act.pending.value" :error="act.error.value" @submit="doReject" @close="closeReject" @clear-error="act.clearError()" />
  <PrToPoDrawer :pr="toPo" @close="toPo = null" />
  <PrToRfqDrawer :pr="toRfq" @close="toRfq = null" @manual="manualRfq" />
</template>
