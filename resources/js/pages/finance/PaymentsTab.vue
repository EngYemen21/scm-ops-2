<script setup>
// Receipts (in) and supplier payments (out): list with direction / status / date filters, drawer with allocations and the
// journal, void with reason; "+ سند صرف" pays approved supplier invoices (partial or full) from a bank account.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, Drawer, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import FilterBar from '@/pages/procurement/FilterBar.vue';
import ReasonDrawer from '@/pages/procurement/ReasonDrawer.vue';
import { qs } from '@/pages/procurement/shared';
import PaymentForm from './PaymentForm.vue';
import ReceiptForm from './ReceiptForm.vue';
import { DIRECTION_LABELS, METHOD_LABELS, PAYMENT_STATUS_LABELS, pname } from './shared';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const sel = computed(() => qs(route.query.payment) || null);
const setSel = (n) => router.replace({ query: { ...route.query, payment: n || undefined } });
const q = ref('');
const direction = ref('');
const status = ref('');
const from = ref('');
const to = ref('');
const page = ref(1);
const payForm = ref(false);
const receiptForm = ref(false);
const voiding = ref(false);
watch([q, direction, status, from, to], () => { page.value = 1; });
const list = useList('/finance/payments', () => ({ q: q.value, direction: direction.value, status: status.value, from: from.value, to: to.value, page: page.value, pageSize: 25 }));
const detailQ = useGet(() => (sel.value ? `/finance/payments/${encodeURIComponent(sel.value)}` : null));
const p = computed(() => (sel.value ? detailQ.data.value : null));
const act = useAction({ invalidate: ['finance', 'procurement'] });
async function doVoid(reason) {
  const r = await act.run(() => api.postIdempotent(`/finance/payments/${p.value.number}/void`, { reason }), { success: t(`أُلغي ${p.value.number} وأُعيد فتح فواتيره`, `${p.value.number} voided`) });
  if (r) { voiding.value = false; detailQ.refetch(); list.refetch(); }
}
const DIRS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'in', label: { ar: 'مقبوضات', en: 'Receipts' } }, { k: 'out', label: { ar: 'مدفوعات', en: 'Payments' } }];
const STATUSES = [{ k: '', label: { ar: 'كل الحالات', en: 'Any status' } }, { k: 'posted', label: { ar: 'مرحَّلة', en: 'Posted' } }, { k: 'void', label: { ar: 'ملغاة', en: 'Void' } }];
const columns = [
  { key: 'number', header: { ar: 'السند', en: 'Voucher' }, width: '130px', kind: 'id' },
  { key: 'direction', header: { ar: 'النوع', en: 'Type' }, width: '80px' },
  { key: 'partner', header: { ar: 'الطرف', en: 'Partner' }, width: 'minmax(150px,1.3fr)', kind: 'name', value: (r) => pname(r.partner) },
  { key: 'paymentDate', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.paymentDate) },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num', sortable: true, value: (r) => fmtMoney(r.amount) },
  { key: 'allocatedAmount', header: { ar: 'الموزَّع', en: 'Allocated' }, width: '90px', kind: 'num', class: 'muted', value: (r) => fmtMoney(r.allocatedAmount) },
  { key: 'bank', header: { ar: 'الحساب', en: 'Account' }, width: '120px', class: 'text-[10px]', value: (r) => pname(r.bankAccount) },
  { key: 'reference', header: { ar: 'المرجع', en: 'Reference' }, width: '110px', kind: 'id', value: (r) => r.reference || '—' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '90px' },
];
</script>

<template>
  <FilterBar v-model="q">
    <Tabs v-model="direction" :tabs="DIRS" variant="pill" class="!mb-0" />
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <DateInput v-model="from" small class="w-[130px]" /><DateInput v-model="to" small class="w-[130px]" />
    <span class="grow" />
    <Btn v-if="auth.can('ar.receive')" size="sm" tone="success" :label="{ ar: '+ سند قبض', en: '+ Receipt' }" @click="receiptForm = true" />
    <Btn v-if="auth.can('ap.pay')" size="sm" tone="primary" :label="{ ar: '+ سند صرف لمورد', en: '+ Supplier payment' }" @click="payForm = true" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1000" :empty-text="{ ar: 'لا سندات بعد.', en: 'No vouchers yet.' }" @page="page = $event" @row-click="(r) => setSel(r.number)">
      <template #cell-direction="{ row }"><Chip :map="DIRECTION_LABELS" :k="row.direction" small /></template>
      <template #cell-status="{ row }"><Chip :map="PAYMENT_STATUS_LABELS" :k="row.status" small /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('السند هو السجل المحاسبي للحركة البنكية (التحويل نفسه يتم في البنك). السداد الجزئي مسموح؛ تتحول فاتورة المورد إلى «مسدَّدة» عند اكتمال السداد. إلغاء السند يعكس قيده ويعيد فتح فواتيره.', 'A voucher is the accounting record of the bank movement (the transfer happens at the bank). Partial payments are allowed; a supplier invoice turns paid when fully settled. Voiding reverses the journal and re-opens the invoices.') }}</div>

  <Drawer :open="!!sel" :width="560" :title="p ? p.number : ''" :sub="p ? `${pname(p.partner)} · ${fmtDateOnly(p.paymentDate)} · ${t(METHOD_LABELS[p.method]?.ar || p.method, METHOD_LABELS[p.method]?.en || p.method)}` : null" @close="setSel(null)">
    <template #headExtra><div v-if="p" class="row !gap-1"><Chip :map="DIRECTION_LABELS" :k="p.direction" small /><Chip :map="PAYMENT_STATUS_LABELS" :k="p.status" small /></div></template>
    <ErrorBanner :error="detailQ.error.value || act.error.value" @close="act.clearError()" />
    <template v-if="p">
      <div class="kpi-grid compact !mb-3">
        <div class="tile purple"><div class="tile-v">{{ fmtMoney(p.amount) }}</div><div class="tile-l">{{ t('المبلغ', 'Amount') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(p.allocatedAmount) }}</div><div class="tile-l">{{ t('موزَّع على فواتير', 'Allocated') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(Number(p.amount) - Number(p.allocatedAmount)) }}</div><div class="tile-l">{{ t('على الحساب', 'On account') }}</div></div>
      </div>
      <div class="kv-grid mb-3">
        <div class="kv"><div class="kv-k">{{ t('الحساب البنكي', 'Bank account') }}</div><div class="kv-v">{{ pname(p.bankAccount) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('المرجع', 'Reference') }}</div><div class="kv-v num">{{ p.reference || '—' }}</div></div>
        <div v-if="p.journal" class="kv"><div class="kv-k">{{ t('القيد', 'Journal') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="router.push(`/jv/${encodeURIComponent(p.journal.number)}`)">{{ p.journal.number }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('سجّله', 'Created by') }}</div><div class="kv-v">{{ p.createdBy }}</div></div>
        <div v-if="p.voidReason" class="kv col-span-full"><div class="kv-k">{{ t('سبب الإلغاء', 'Void reason') }}</div><div class="kv-v !font-normal">{{ p.voidReason }} — {{ p.voidedBy }}</div></div>
      </div>
      <div class="field-l mb-1.5">{{ t('التوزيع', 'Allocations') }}</div>
      <div v-if="!p.allocations?.length" class="empty">{{ t('بلا توزيع — المبلغ على حساب الطرف', 'Unallocated — on the partner account') }}</div>
      <div v-for="a in p.allocations || []" :key="a.id" class="row rounded-[11px] border border-line-2 px-[13px] py-[8px] text-[10.5px]">
        <span class="num font-extrabold">{{ a.invoice?.number || a.invoiceId }}</span><span class="text-faint">{{ a.invoice?.status }}</span><span class="grow" /><span class="num">{{ fmtMoney(a.amount) }}</span>
      </div>
    </template>
    <template v-if="p && p.status === 'posted' && auth.can('gl.approve')" #footer>
      <Btn tone="dangerOutline" :label="{ ar: 'إلغاء السند', en: 'Void voucher' }" @click="voiding = true" />
    </template>
  </Drawer>
  <Teleport v-if="voiding" to="body">
    <ReasonDrawer open :title="{ ar: `إلغاء ${p?.number}`, en: `Void ${p?.number}` }" :ok-label="{ ar: 'إلغاء السند', en: 'Void' }" :min="5" :pending="act.pending.value" :error="act.error.value" @submit="doVoid" @close="voiding = false" @clear-error="act.clearError()" />
  </Teleport>
  <PaymentForm :open="payForm" @close="payForm = false" @done="list.refetch()" />
  <ReceiptForm :open="receiptForm" @close="receiptForm = false" @done="list.refetch()" />
</template>
