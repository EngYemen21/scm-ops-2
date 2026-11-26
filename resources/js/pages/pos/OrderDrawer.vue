<script setup>
// One order (`order=<id>` in the query string): lines (issued / owed / refunded), payments, the sale it refunds or its
// refunds, its journals; print 80mm / A4; refund (when `refundable`, in the user's own open session); issue the stock a
// sale still owes (pos.manage).
//   GET /pos/orders/{id} · GET /pos/orders/{id}/receipt?format=80mm|a4 (text/html) · POST /pos/orders/{id}/issue-pending
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV } from '@/components';
import { fmtDate, fmtMoney, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import RefundForm from './RefundForm.vue';
import { HISTORY_CHIP, METHOD_KIND, ORDER_KIND, money, nameOf, pct, usePosLookups, usePosNav } from './pos';
import { usePrint } from './receipt';

const props = defineProps({
  id: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = usePosNav();
const lk = usePosLookups();
const det = useGet(() => (props.id ? `/pos/orders/${encodeURIComponent(props.id)}` : null));
const o = computed(() => det.data.value || null);
const pr = usePrint();
const act = useAction();

const showCost = computed(() => (o.value?.lines || []).some((l) => l.cogs != null));
const canRefund = computed(() => !!o.value?.refundable && !o.value.history && auth.can('pos.sell'));
const canIssue = computed(() => !!o.value && o.value.kind === 'sale' && o.value.pendingQty > 0 && !o.value.history && auth.can('pos.manage'));
const refunding = ref(false);

async function issuePending() {
  if (!(await confirm({ title: { ar: 'صرف الكمية المستحقة؟', en: 'Issue the owed stock?' }, sub: { ar: `يُصرف من المخزون ما يغطيه الرف الآن من ${o.value.pendingQty} قطعة مستحقة.`, en: `Takes from stock what the shelf now covers of the ${o.value.pendingQty} owed item(s).` }, tone: 'dark' }))) return;
  await act.run(() => api.postIdempotent(`/pos/orders/${encodeURIComponent(o.value.id)}/issue-pending`), { success: t('صُرفت الكمية المتاحة', 'Available stock issued'), invalidate: ['pos'] });
}
function onRefunded(r) { refunding.value = false; if (r?.id) nav.openOrder(r.id); }

const lineCols = computed(() => [
  { key: 'name', header: { ar: 'الصنف', en: 'Product' }, width: 'minmax(160px,2fr)' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '58px', kind: 'num' },
  { key: 'unitPrice', header: { ar: 'السعر', en: 'Price' }, width: '80px', kind: 'num', value: (l) => fmtMoney(num(l.unitPrice)) },
  { key: 'discountPct', header: { ar: 'خصم', en: 'Disc.' }, width: '60px', kind: 'num', value: (l) => (num(l.discountPct) ? pct(l.discountPct) : '—') },
  { key: 'lineTotal', header: { ar: 'الصافي', en: 'Net' }, width: '90px', kind: 'num', value: (l) => fmtMoney(num(l.lineTotal)) },
  { key: 'state', header: { ar: 'المخزون / الإرجاع', en: 'Stock / refund' }, width: '130px', bare: true, hidden: o.value?.kind !== 'sale' },
  { key: 'cogs', header: { ar: 'التكلفة', en: 'Cost' }, width: '80px', kind: 'num', hidden: !showCost.value, value: (l) => (l.cogs != null ? fmtMoney(num(l.cogs)) : '—') },
]);
const payCols = [
  { key: 'method', header: { ar: 'الطريقة', en: 'Method' }, width: 'minmax(120px,1fr)' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '90px', kind: 'num', value: (p) => fmtMoney(num(p.amount)) },
  { key: 'applied', header: { ar: 'المحتسب', en: 'Applied' }, width: '90px', kind: 'num', value: (p) => fmtMoney(num(p.applied)) },
  { key: 'reference', header: { ar: 'المرجع', en: 'Reference' }, width: '110px', ltr: true },
  { key: 'finPaymentNumber', header: { ar: 'سند المالية', en: 'Receipt' }, width: '110px', kind: 'id' },
];
</script>

<template>
  <Drawer :open="!!id" :title="o ? o.number : t('طلب', 'Order')" :sub="o ? fmtDate(o.orderedAt) : null" :width="720" @close="emit('close')">
    <ErrorBanner :error="det.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!o && !det.error.value" class="skel h-[220px]" />
    <template v-else-if="o">
      <div class="row wrap mb-3">
        <Chip :map="ORDER_KIND" :k="o.kind" />
        <Chip v-if="o.history" :label="HISTORY_CHIP" :fg="HISTORY_CHIP.fg" :bg="HISTORY_CHIP.bg" />
        <Chip v-if="o.pendingQty > 0" :label="{ ar: `مستحق على المخزون ${o.pendingQty}`, en: `${o.pendingQty} owed by stock` }" fg="#b23b3b" bg="#fdecec" />
        <div class="grow" />
        <Btn tone="soft" size="sm" :loading="pr.printing.value === '80mm'" :label="{ ar: 'طباعة 80مم', en: 'Print 80mm' }" @click="pr.print(o.id, '80mm')" />
        <Btn tone="soft" size="sm" :loading="pr.printing.value === 'a4'" :label="{ ar: 'طباعة A4', en: 'Print A4' }" @click="pr.print(o.id, 'a4')" />
        <Btn v-if="canIssue" tone="softAmber" size="sm" :loading="act.pending.value" :label="{ ar: 'صرف المستحق', en: 'Issue owed stock' }" @click="issuePending" />
        <Btn v-if="canRefund" tone="danger" size="sm" :label="{ ar: 'استرجاع', en: 'Refund' }" @click="refunding = true" />
      </div>
      <div v-if="o.history" class="hint purple !mt-0 mb-3">{{ t('طلب من سجل أودو: للقراءة فقط، ولا يُسترجع من هنا.', 'An order of the Odoo history: read only, not refunded here.') }}</div>
      <div v-else-if="o.refundable && auth.can('pos.sell') && !lk.session.value" class="hint !mt-0 mb-3">{{ t('للاسترجاع افتح جلستك على صندوق من تبويب «البيع» — يُسجَّل المرتجع في جلستك.', 'To refund, open your session on a register in the Sell tab — the refund is recorded in your session.') }}</div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'العميل', en: 'Customer' }" :v="o.customer ? `${o.customer.code} · ${nameOf(o.customer)}` : o.customerName" />
        <KV :k="{ ar: 'الكاشير', en: 'Cashier' }" :v="o.cashierName" />
        <KV :k="{ ar: 'الجلسة', en: 'Session' }"><button v-if="o.session" type="button" class="num ltr font-bold text-brand-dark hover:underline" @click="nav.openSession(o.sessionId || o.session.id)">{{ o.session.number }}</button><span v-else>—</span></KV>
        <KV :k="{ ar: 'الصندوق', en: 'Register' }" :v="o.register ? `${o.register.code} · ${nameOf(o.register)}` : '—'" />
        <KV :k="{ ar: 'الفاتورة / الإشعار', en: 'Invoice / note' }" :v="o.invoiceNumber || '—'" ltr />
        <KV v-if="o.refundOf" :k="{ ar: 'مرتجع من', en: 'Refund of' }"><button type="button" class="num ltr font-bold text-brand-dark hover:underline" @click="nav.openOrder(o.refundOfId)">{{ o.refundOf.number }}</button></KV>
        <KV v-if="o.reason" :k="{ ar: 'سبب الاسترجاع', en: 'Refund reason' }" :v="o.reason" />
      </div>

      <div class="card-title mb-1.5">{{ t('الأسطر', 'Lines') }}</div>
      <div class="card mb-3">
        <DataTable :columns="lineCols" :rows="o.lines || []" dense :row-key="(l) => l.id">
          <template #cell-name="{ row }">
            <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ nameOf({ nameAr: row.name, nameEn: row.nameEn }) }}</div><div class="cell-sub">{{ row.sku }}</div></div>
          </template>
          <template #cell-state="{ row }">
            <span class="inline-flex flex-col items-center text-[9.5px] leading-[1.5]">
              <span v-if="row.pendingQty > 0" class="font-bold text-bad">{{ t('مستحق', 'Owed') }} <span class="num">{{ row.pendingQty }}</span></span>
              <span v-else class="text-ok">{{ t('صُرف', 'Issued') }} <span class="num">{{ row.issuedQty }}</span></span>
              <span v-if="row.refundedQty > 0" class="text-warn">{{ t('مُرتجع', 'Refunded') }} <span class="num">{{ row.refundedQty }}</span></span>
            </span>
          </template>
        </DataTable>
      </div>

      <div class="mb-4 ms-auto grid max-w-[320px] grid-cols-2 gap-y-1 text-[11.5px]">
        <span class="text-sec">{{ t('الصافي قبل الضريبة', 'Net before VAT') }}</span><span class="num ltr text-end">{{ money(o.subtotal) }}</span>
        <span class="text-sec">{{ t('الخصم', 'Discount') }}</span><span class="num ltr text-end">{{ money(o.discountTotal) }}</span>
        <span class="text-sec">{{ t('الضريبة', 'VAT') }} <span class="num">{{ pct(o.vatPct) }}</span></span><span class="num ltr text-end">{{ money(o.vatAmount) }}</span>
        <span class="text-[13px] font-extrabold">{{ t('الإجمالي', 'Total') }}</span><span class="num ltr text-end text-[13px] font-extrabold">{{ money(o.total) }}</span>
        <template v-if="o.kind === 'sale'">
          <span class="text-sec">{{ t('المدفوع', 'Tendered') }}</span><span class="num ltr text-end">{{ money(o.tendered) }}</span>
          <span class="text-sec">{{ t('الباقي', 'Change') }}</span><span class="num ltr text-end">{{ money(o.changeDue) }}</span>
          <span v-if="num(o.onAccount) > 0" class="text-sec">{{ t('على الحساب', 'On account') }}</span><span v-if="num(o.onAccount) > 0" class="num ltr text-end">{{ money(o.onAccount) }}</span>
          <span v-if="num(o.refundedTotal) > 0" class="text-warn">{{ t('المُرتجع منه', 'Refunded') }}</span><span v-if="num(o.refundedTotal) > 0" class="num ltr text-end text-warn">{{ money(o.refundedTotal) }}</span>
        </template>
      </div>

      <div class="card-title mb-1.5">{{ o.kind === 'refund' ? t('المبالغ المردودة', 'Money paid back') : t('المدفوعات', 'Payments') }}</div>
      <div class="card mb-4">
        <DataTable :columns="payCols" :rows="o.payments || []" dense :row-key="(p) => p.lineNo" :empty-text="{ ar: 'لا مدفوعات.', en: 'No payments.' }">
          <template #cell-method="{ row }">
            <span class="inline-flex items-center gap-1.5"><span class="truncate font-bold" dir="auto">{{ row.method ? nameOf(row.method) : row.methodCode }}</span><Chip small :map="METHOD_KIND" :k="row.kind" /></span>
          </template>
        </DataTable>
      </div>

      <template v-if="(o.refunds || []).length">
        <div class="card-title mb-1.5">{{ t('المرتجعات على هذا البيع', 'Refunds of this sale') }}</div>
        <div class="mb-4 flex flex-col gap-1.5">
          <button v-for="r in o.refunds" :key="r.id" type="button" class="flex items-center gap-2 rounded-xl border border-line p-2 text-start hover:border-brand" @click="nav.openOrder(r.id)">
            <span class="num ltr text-[11px] font-extrabold text-violet">{{ r.number }}</span>
            <span class="min-w-0 flex-1 truncate text-[10.5px] text-sec" dir="auto">{{ r.reason || '—' }}</span>
            <span class="num ltr text-[10px] text-muted">{{ fmtDate(r.orderedAt) }}</span>
            <span class="num ltr text-[11.5px] font-extrabold text-warn">{{ money(r.total) }}</span>
          </button>
        </div>
      </template>

      <template v-if="(o.journals || []).length">
        <div class="card-title mb-1.5">{{ t('القيود المحاسبية', 'Journal entries') }}</div>
        <div class="mb-2 flex flex-col gap-1">
          <div v-for="j in o.journals" :key="j.number" class="flex items-center gap-2 rounded-lg bg-soft px-2.5 py-1.5 text-[10.5px]">
            <span class="num ltr font-bold text-violet">{{ j.number }}</span>
            <span class="min-w-0 flex-1 truncate" dir="auto">{{ j.memo }}</span>
            <span class="text-muted">{{ j.status }}</span>
            <span class="num ltr font-bold">{{ fmtMoney(num(j.totalDebit)) }}</span>
          </div>
        </div>
      </template>
    </template>
  </Drawer>
  <RefundForm :open="refunding" :order="o" @close="refunding = false" @done="onRefunded" />
</template>
