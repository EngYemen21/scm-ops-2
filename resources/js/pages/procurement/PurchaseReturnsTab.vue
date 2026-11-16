<script setup>
// Purchase returns tab (procurement page, `?tab=ret`): returns to suppliers with their status and debit note.
// "مرتجع جديد": supplier → optional PO / GRN → lines from the stock rows of that supplier's receipts (PurchaseReturnForm).
// Row actions approve / ship / cancel by permission; a row opens the return drawer (lines, holds, movements, documents).
// Data: GET /api/returns?type=sup. Deep link: `?tab=ret&rtn=RTN-…`.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import { RETURN_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import ReturnDrawer from '../returns/ReturnDrawer.vue';
import ReturnStatusChip from '../returns/ReturnStatusChip.vue';
import { RETURN_REASON_LABELS, isFromStock, labelOf, pname, queryOf } from '../returns/shared';
import PurchaseReturnForm from './PurchaseReturnForm.vue';
import PurchaseReturnRowActions from './PurchaseReturnRowActions.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuth();

const sel = computed(() => queryOf(route, 'rtn'));
const setSel = (n) => router.replace({ query: { ...route.query, rtn: n || undefined } });

const q = ref('');
const status = ref('');
const page = ref(1);
const formOpen = ref(false);
watch([q, status], () => { page.value = 1; });
const list = useList('/returns', () => ({ type: 'sup', q: q.value.trim() || undefined, status: status.value || undefined, page: page.value, pageSize: 25 }), { refetchInterval: 60_000 });
const count = (s) => useList('/returns', { type: 'sup', status: s, pageSize: 1 }, { refetchInterval: 60_000 });
const kPending = count('pending');
const kApproved = count('approved');
const kClosed = count('closed');

const STATUSES = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...['pending', 'approved', 'closed', 'cancelled', 'rejected'].map((k) => ({ k, label: { ar: RETURN_LABELS[k]?.ar || k, en: RETURN_LABELS[k]?.en || k } }))];
STATUSES[2].label = { ar: 'معتمد — بانتظار الشحن', en: 'Approved — awaiting shipping' }; // a purchase return is shipped, never received
const columns = [
  { key: 'number', header: { ar: 'المرتجع', en: 'Return' }, width: '150px' },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(150px,1.2fr)', kind: 'name', value: (r) => (r.supplier ? `${r.supplier.nameAr} · ${r.supplier.code}` : '—') },
  { key: 'items', header: { ar: 'الأصناف', en: 'Items' }, width: 'minmax(150px,1.2fr)' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '64px', kind: 'num', value: (r) => fmtNum((r.lines || []).reduce((a, l) => a + (l.qty || 0), 0)) },
  { key: 'reference', header: { ar: 'المرجع', en: 'Reference' }, width: '120px', kind: 'id', value: (r) => r.reference || '—' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px', bare: true },
  { key: 'note', header: { ar: 'إشعار المدين', en: 'Debit note' }, width: '150px' },
  { key: 'act', header: '', width: '170px', bare: true },
];
function onCreated(r) { if (r?.number) setSel(r.number); }
</script>

<template>
  <KpiGrid compact class="!mb-3">
    <KpiCard compact clickable :value="kPending.data.value?.total" :label="{ ar: 'بانتظار اعتماد المستودع', en: 'Awaiting warehouse approval' }" color="#b26a16" :active="status === 'pending'" :loading="kPending.isLoading.value" @click="status = status === 'pending' ? '' : 'pending'" />
    <KpiCard compact clickable :value="kApproved.data.value?.total" :label="{ ar: 'محجوزة — بانتظار الشحن', en: 'Held — awaiting shipping' }" color="#3C79F5" :active="status === 'approved'" :loading="kApproved.isLoading.value" @click="status = status === 'approved' ? '' : 'approved'" />
    <KpiCard compact clickable :value="kClosed.data.value?.total" :label="{ ar: 'أُرجعت للمورد', en: 'Returned to supplier' }" color="#1d7a3e" :active="status === 'closed'" :loading="kClosed.isLoading.value" @click="status = status === 'closed' ? '' : 'closed'" />
  </KpiGrid>

  <div class="row wrap mb-2.5">
    <TextInput v-model="q" small type="search" class="w-[240px]" :placeholder="{ ar: 'بحث برقم المرتجع / المورد / المرجع', en: 'Search return / supplier / reference' }" />
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <div class="grow" />
    <Btn v-if="auth.can('return.create')" tone="primary" size="sm" :label="{ ar: '+ مرتجع جديد', en: '+ New return' }" @click="formOpen = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :empty-text="{ ar: 'لا مرتجعات شراء مطابقة — أنشئ مرتجعًا من أرصدة ما ورّده المورد.', en: 'No matching purchase returns — create one from the stock the supplier delivered.' }" @page="page = $event" @row-click="(r) => setSel(r.number)">
      <template #cell-number="{ row }">
        <div>
          <div class="cell-id cursor-pointer" @click.stop="setSel(row.number)">{{ row.number }}</div>
          <div class="num ltr text-[8px] text-faint">{{ fmtDateOnly(row.createdAt) }} · {{ row.warehouse?.code }}<template v-if="row.createdBy"> · {{ row.createdBy }}</template></div>
        </div>
      </template>
      <template #cell-items="{ row }">
        <div class="min-w-0">
          <div class="ellipsis text-[10.5px] font-bold">{{ row.lines?.[0] ? pname(row.lines[0].product) : '—' }}<span v-if="(row.lines?.length || 0) > 1" class="muted"> {{ t(`+${row.lines.length - 1}`, `+${row.lines.length - 1}`) }}</span></div>
          <div class="cell-sub">{{ t(labelOf(RETURN_REASON_LABELS, row.reasonCode).ar, labelOf(RETURN_REASON_LABELS, row.reasonCode).en) }}<template v-if="row.lines?.[0]?.fromBin"> · <span class="num">{{ row.lines[0].fromBin.code }}</span></template></div>
        </div>
      </template>
      <template #cell-status="{ row }">
        <ReturnStatusChip :r="row" small />
        <div v-if="!isFromStock(row) && ['pending', 'approved'].includes(row.status)" class="mt-0.5 text-[8.5px] font-bold text-warn">{{ t('بلا موقع مصدر — يُلغى ويُعاد إنشاؤه', 'No source bin — cancel and recreate') }}</div>
      </template>
      <template #cell-note="{ row }">
        <div v-if="(row.debitNotes || []).length">
          <div v-for="n in row.debitNotes" :key="n.number"><span class="cell-id">{{ n.number }}</span> <span class="num text-[10px] font-extrabold">{{ fmtMoney(n.total) }}</span></div>
        </div>
        <Chip v-else-if="row.status === 'closed'" small fg="#b26a16" bg="#fbf0dd" :label="{ ar: 'بلا إشعار سارٍ', en: 'No active note' }" />
        <span v-else class="muted text-[10px]">{{ t('يصدر عند الشحن', 'issued at shipping') }}</span>
      </template>
      <template #cell-act="{ row }"><div @click.stop><PurchaseReturnRowActions :ret="row" @done="list.refetch()" /></div></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('مرتجع الشراء يؤخذ من موقع البضاعة الفعلي (رف / حجر / تالف): المشتريات تنشئ الطلب، المستودع يعتمده فتُحجز الكمية، ثم يشحنه فتخرج من المخزون بتكلفتها ويصدر إشعار مدين على المورد بسعر الشراء والضريبة — ينخفض مستحق المورد بإجمالي الإشعار ويظهر في كشف حسابه. الإلغاء قبل الشحن يعيد الكمية إلى المتاح؛ إلغاء الإشعار بعد الشحن من المالية.', 'A purchase return is taken from the real bin of the goods (shelf / quarantine / damaged): procurement creates the request, the warehouse approves it and the quantity is held, then ships it — the stock leaves at cost and a debit note is issued at the purchase price + VAT, so the payable to the supplier goes down by the note total and shows on the statement. Cancelling before shipping gives the quantity back; a note is cancelled by finance after shipping.') }}</div>

  <ReturnDrawer :number="sel" @close="setSel(null)" />
  <PurchaseReturnForm :open="formOpen" @close="formOpen = false" @done="onCreated" />
</template>
