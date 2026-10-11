<script setup>
// Supplier invoice drawer (GET /procurement/invoices/:number): header, totals, three-way-match lines, variances, actions.
//   <InvoiceDrawer :number="numberOrNull" @close />
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Chip, DataTable, Drawer, ErrorBanner } from '@/components';
import { fmtDate, fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import InvoiceActions from './InvoiceActions.vue';
import { INVOICE_LABELS, MATCH_LABELS, pname, sname } from './shared';

const props = defineProps({
  /** Invoice number; null = closed. */
  number: { type: String, default: null },
});
const emit = defineEmits(['close']);
const router = useRouter();

const invQ = useGet(() => (props.number ? `/procurement/invoices/${encodeURIComponent(props.number)}` : null));
/** { number, supplierInvoiceNo, status, invoiceDate, dueDate, subtotal, discount, shippingCost, vatPct, vatAmount, total, variances?, notes?,
 *    registeredBy, approvedBy?, approvedAt?, approvedNote?, paidAt?, paidBy?, paymentRef?, overdue, supplier, po, lines[] } */
const inv = computed(() => (props.number ? invQ.data.value : null));

const VARIANCE_KIND = { qty: { ar: 'كمية', en: 'Qty' }, price: { ar: 'سعر', en: 'Price' }, charges: { ar: 'خصم / شحن', en: 'Discount / shipping' } };
const varianceText = (v) => {
  const kind = lang.value === 'ar' ? VARIANCE_KIND[v.kind]?.ar : VARIANCE_KIND[v.kind]?.en;
  if (v.kind === 'charges') return `${kind}: ${t('المتوقع', 'expected')} ${fmtMoney(v.expected?.discount)} / ${fmtMoney(v.expected?.shipping)} — ${t('الفعلي', 'actual')} ${fmtMoney(v.actual?.discount)} / ${fmtMoney(v.actual?.shipping)}`;
  return `${kind} · ${t('بند', 'line')} ${v.lineNo} ${v.product ? `(${v.product})` : ''}: ${t('المتوقع', 'expected')} ${v.kind === 'qty' ? fmtNum(v.expected) : fmtMoney(v.expected)} — ${t('الفعلي', 'actual')} ${v.kind === 'qty' ? fmtNum(v.actual) : fmtMoney(v.actual)}`;
};

const columns = [
  { key: 'lineNo', header: '#', width: '30px', kind: 'num' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(130px,1.4fr)', kind: 'name', value: (l) => pname(l.product) },
  { key: 'qty', header: { ar: 'كمية الفاتورة', en: 'Invoiced' }, width: '70px', kind: 'num', value: (l) => fmtNum(l.qty) },
  { key: 'receivedQty', header: { ar: 'مقبول GRN', en: 'Accepted' }, width: '70px', kind: 'num', value: (l) => fmtNum(l.receivedQty) },
  { key: 'previouslyInvoicedQty', header: { ar: 'سبق فوترته', en: 'Invoiced before' }, width: '75px', kind: 'num', class: 'muted', value: (l) => fmtNum(l.previouslyInvoicedQty) },
  { key: 'price', header: { ar: 'سعر الفاتورة', en: 'Inv. price' }, width: '80px', kind: 'num', value: (l) => fmtMoney(l.price) },
  { key: 'poPrice', header: { ar: 'سعر الأمر', en: 'PO price' }, width: '80px', kind: 'num', class: 'muted', value: (l) => fmtMoney(l.poPrice) },
  { key: 'match', header: { ar: 'المطابقة', en: 'Match' }, width: '90px' },
];
</script>

<template>
  <Drawer :open="!!number" :width="760" :title="inv ? `${inv.number}` : t('فاتورة مورد', 'Supplier invoice')" :sub="inv ? `${sname(inv.supplier)} · ${inv.supplierInvoiceNo} · ${t('أمر الشراء', 'PO')} ${inv.po?.number || '—'}` : null" @close="emit('close')">
    <template #headExtra><Chip v-if="inv" :map="INVOICE_LABELS" :k="inv.status" /></template>
    <ErrorBanner :error="invQ.error.value" :closable="false" />
    <div v-if="invQ.isLoading.value" class="skel min-h-[160px]" />
    <template v-if="inv">
      <div class="kpi-grid compact !mb-3">
        <div class="tile"><div class="tile-v">{{ fmtMoney(inv.subtotal) }}</div><div class="tile-l">{{ t('قيمة الأصناف', 'Goods') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(inv.discount) }} / {{ fmtMoney(inv.shippingCost) }}</div><div class="tile-l">{{ t('خصم / شحن', 'Discount / shipping') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(inv.vatAmount) }}</div><div class="tile-l">{{ t('ضريبة', 'VAT') }} {{ fmtNum(inv.vatPct) }}%</div></div>
        <div class="tile purple"><div class="tile-v">{{ fmtMoney(inv.total) }}</div><div class="tile-l">{{ t('الإجمالي ر.س', 'Total SAR') }}</div></div>
      </div>
      <div class="kv-grid">
        <div class="kv"><div class="kv-k">{{ t('تاريخ الفاتورة', 'Invoice date') }}</div><div class="kv-v ltr num text-start">{{ fmtDateOnly(inv.invoiceDate) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('الاستحقاق', 'Due') }}</div><div class="kv-v ltr num text-start">{{ fmtDateOnly(inv.dueDate) }} <Chip v-if="inv.overdue" small fg="#b23b3b" bg="#fdecec" :label="{ ar: 'متأخرة', en: 'Overdue' }" /></div></div>
        <div class="kv"><div class="kv-k">{{ t('سجّلها', 'Registered by') }}</div><div class="kv-v">{{ inv.registeredBy }} <span class="muted ltr num text-[9px]">{{ fmtDate(inv.createdAt) }}</span></div></div>
        <div class="kv"><div class="kv-k">{{ t('اعتماد الصرف', 'Approval') }}</div><div class="kv-v">{{ inv.approvedBy || '—' }} <span v-if="inv.approvedAt" class="muted ltr num text-[9px]">{{ fmtDate(inv.approvedAt) }}</span></div></div>
        <div v-if="inv.approvedNote" class="kv col-span-full"><div class="kv-k">{{ t('قرار الاعتماد', 'Approval decision') }}</div><div class="kv-v !font-normal">{{ inv.approvedNote }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('السداد', 'Payment') }}</div><div class="kv-v">{{ inv.paymentRef || '—' }}<span v-if="inv.paidAt" class="muted ltr num ms-1 text-[9px]">{{ fmtDate(inv.paidAt) }} · {{ inv.paidBy }}</span></div></div>
        <div class="kv"><div class="kv-k">{{ t('أمر الشراء', 'Purchase order') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="router.push(`/po/${encodeURIComponent(inv.po.number)}`)">{{ inv.po?.number }}</div></div>
        <div v-if="inv.notes" class="kv col-span-full"><div class="kv-k">{{ t('ملاحظات', 'Notes') }}</div><div class="kv-v !font-normal">{{ inv.notes }}</div></div>
      </div>

      <div v-if="inv.variances?.length" class="banner amber mt-3">
        <div class="font-extrabold">{{ t('فروقات المطابقة الثلاثية', 'Three-way match variances') }}</div>
        <div v-for="(v, i) in inv.variances" :key="i" class="text-[10px] leading-[1.8]">• {{ varianceText(v) }}</div>
      </div>

      <div class="mt-3 overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="columns" :rows="inv.lines || []" :row-key="(l) => l.id" :min-width="640">
          <template #cell-match="{ row }"><Chip :map="MATCH_LABELS" :k="row.match" small /></template>
        </DataTable>
      </div>
    </template>
    <template v-if="inv" #footer><InvoiceActions :invoice="inv" size="md" @changed="invQ.refetch()" /></template>
  </Drawer>
</template>
