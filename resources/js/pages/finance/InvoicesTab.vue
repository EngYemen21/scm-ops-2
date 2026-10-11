<script setup>
// Customer invoices & credit notes (issued automatically from deliveries / returns): receivables KPIs, list, drawer
// (lines with cost, settlements, cancel) and the receipt form (customer payment allocated to invoices).
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KpiCard, KpiGrid, Tabs } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import FilterBar from '@/pages/procurement/FilterBar.vue';
import ReasonDrawer from '@/pages/procurement/ReasonDrawer.vue';
import { qs } from '@/pages/procurement/shared';
import ReceiptForm from './ReceiptForm.vue';
import { CI_KIND_LABELS, CI_LABELS, pname } from './shared';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const sel = computed(() => qs(route.query.invoice) || null);
const setSel = (n) => router.replace({ query: { ...route.query, invoice: n || undefined } });

const q = ref('');
const status = ref('');
const due = ref('');
const page = ref(1);
const receipt = ref(null); // { customer, invoice? } | null
const cancelling = ref(false);
watch([q, status, due], () => { page.value = 1; });
const list = useList('/finance/invoices', () => ({ q: q.value, status: status.value, due: due.value, page: page.value, pageSize: 25 }));
const sumQ = useGet('/finance/invoices/summary', undefined, { refetchInterval: 120_000 });
const sum = computed(() => sumQ.data.value);
const detailQ = useGet(() => (sel.value ? `/finance/invoices/${encodeURIComponent(sel.value)}` : null));
const inv = computed(() => (sel.value ? detailQ.data.value : null));
const act = useAction({ invalidate: ['finance'] });

async function cancel(reason) {
  const r = await act.run(() => api.postIdempotent(`/finance/invoices/${inv.value.number}/cancel`, { reason }), { success: t(`أُلغيت ${inv.value.number} وعُكست قيودها`, `${inv.value.number} cancelled, journals reversed`) });
  if (r) { cancelling.value = false; detailQ.refetch(); list.refetch(); }
}
const STATUSES = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'issued,partially_paid', label: { ar: 'مفتوحة', en: 'Open' } }, { k: 'paid', label: { ar: 'مسدَّدة', en: 'Paid' } }, { k: 'cancelled', label: { ar: 'ملغاة', en: 'Cancelled' } }];
const DUE = [{ k: '', label: { ar: 'كل الاستحقاقات', en: 'Any due' } }, { k: 'overdue', label: { ar: 'متأخرة', en: 'Overdue' } }, { k: 'week', label: { ar: 'خلال أسبوع', en: 'This week' } }];
const columns = [
  { key: 'number', header: { ar: 'الفاتورة', en: 'Invoice' }, width: '140px' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(150px,1.3fr)', kind: 'name', value: (r) => pname(r.customer) },
  { key: 'order', header: { ar: 'أمر البيع', en: 'Order' }, width: '120px', kind: 'id', value: (r) => r.order?.number || '—' },
  { key: 'invoiceDate', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.invoiceDate) },
  { key: 'dueDate', header: { ar: 'الاستحقاق', en: 'Due' }, width: '110px' },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num', sortable: true, value: (r) => fmtMoney(r.total) },
  { key: 'balance', header: { ar: 'المتبقي', en: 'Balance' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.balance) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px' },
];
const lineCols = [
  { key: 'lineNo', header: '#', width: '30px', kind: 'num' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(150px,1.5fr)', kind: 'name', value: (l) => pname(l.product) },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '60px', kind: 'num', value: (l) => fmtNum(l.qty) },
  { key: 'price', header: { ar: 'السعر', en: 'Price' }, width: '80px', kind: 'num', value: (l) => fmtMoney(l.price) },
  { key: 'lineTotal', header: { ar: 'الصافي', en: 'Net' }, width: '90px', kind: 'num', value: (l) => fmtMoney(l.lineTotal) },
  { key: 'unitCost', header: { ar: 'التكلفة', en: 'Cost' }, width: '80px', kind: 'num', class: 'muted', value: (l) => fmtMoney(l.unitCost) },
  { key: 'cogs', header: { ar: 'تكلفة المبيعات', en: 'COGS' }, width: '90px', kind: 'num', class: 'muted', value: (l) => fmtMoney(l.cogs) },
];
</script>

<template>
  <KpiGrid compact class="!mb-3">
    <KpiCard compact :value="sum?.openCount" :label="{ ar: 'فواتير مفتوحة', en: 'Open invoices' }" :sub="sum ? `${fmtMoney(sum.openTotal)} ${t('ر.س', 'SAR')}` : null" color="#3C79F5" :loading="!sum" />
    <KpiCard compact clickable :value="sum?.overdue?.count" :label="{ ar: 'متأخرة التحصيل', en: 'Overdue' }" :sub="sum?.overdue?.count ? `${fmtMoney(sum.overdue.total)} ${t('ر.س', 'SAR')}` : null" color="#b23b3b" :active="due === 'overdue'" :loading="!sum" @click="due = due === 'overdue' ? '' : 'overdue'" />
    <KpiCard compact clickable :value="sum?.dueThisWeek?.count" :label="{ ar: 'تستحق خلال أسبوع', en: 'Due this week' }" color="#b26a16" :active="due === 'week'" :loading="!sum" @click="due = due === 'week' ? '' : 'week'" />
  </KpiGrid>
  <FilterBar v-model="q">
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <Tabs v-model="due" :tabs="DUE" variant="pill" class="!mb-0" />
    <span class="grow" />
    <Btn v-if="auth.can('ar.receive')" size="sm" tone="primary" :label="{ ar: '+ سند قبض', en: '+ Receipt' }" @click="receipt = {}" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1000" :empty-text="{ ar: 'لا فواتير — تصدر فاتورة العميل تلقائيًا عند إثبات التسليم (POD).', en: 'No invoices — a customer invoice is issued automatically when a delivery is proven (POD).' }" @page="page = $event" @row-click="(r) => setSel(r.number)">
      <template #cell-number="{ row }"><div><div class="cell-id">{{ row.number }}</div><Chip :map="CI_KIND_LABELS" :k="row.kind" small /></div></template>
      <template #cell-dueDate="{ row }"><span class="cell-date ltr">{{ fmtDateOnly(row.dueDate) }}</span><Chip v-if="row.overdue" small fg="#b23b3b" bg="#fdecec" :label="{ ar: 'متأخرة', en: 'Overdue' }" class="ms-1" /></template>
      <template #cell-status="{ row }"><Chip :map="CI_LABELS" :k="row.status" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الفاتورة تصدر بالكمية المسلَّمة فعلًا وبسعر الأمر وضريبته (الإيراد عند انتقال السيطرة)، وتحمل تكلفة البضاعة المباعة من تكلفة التحميل. مرتجع العميل بعد التسليم يصدر إشعارًا دائنًا يُطبَّق على فاتورته.', 'Invoices carry the quantity actually delivered at the order price and VAT (revenue when control transfers) and the cost of goods from the load cost. A post-delivery customer return issues a credit note applied to its invoice.') }}</div>

  <Drawer :open="!!sel" :width="720" :title="inv ? inv.number : t('فاتورة', 'Invoice')" :sub="inv ? `${pname(inv.customer)} · ${fmtDateOnly(inv.invoiceDate)} · ${t('استحقاق', 'due')} ${fmtDateOnly(inv.dueDate)}` : null" @close="setSel(null)">
    <template #headExtra><div v-if="inv" class="row !gap-1"><Chip :map="CI_LABELS" :k="inv.status" small /><Chip :map="CI_KIND_LABELS" :k="inv.kind" small /></div></template>
    <ErrorBanner :error="detailQ.error.value || act.error.value" @close="act.clearError()" />
    <template v-if="inv">
      <div class="kpi-grid compact !mb-3">
        <div class="tile"><div class="tile-v">{{ fmtMoney(inv.subtotal) }}</div><div class="tile-l">{{ t('الصافي قبل الضريبة', 'Net') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(inv.vatAmount) }}</div><div class="tile-l">{{ t('ضريبة', 'VAT') }} {{ fmtNum(inv.vatPct) }}%</div></div>
        <div class="tile purple"><div class="tile-v">{{ fmtMoney(inv.total) }}</div><div class="tile-l">{{ t('الإجمالي', 'Total') }}</div></div>
        <div class="tile" :class="inv.balance > 0 ? 'amber' : 'green'"><div class="tile-v">{{ fmtMoney(inv.balance) }}</div><div class="tile-l">{{ t('المتبقي', 'Balance') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(inv.cogsTotal) }}</div><div class="tile-l">{{ t('تكلفة المبيعات', 'COGS') }}</div></div>
      </div>
      <div class="kv-grid mb-3">
        <div v-if="inv.order" class="kv"><div class="kv-k">{{ t('أمر البيع', 'Sales order') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="router.push(`/so/${encodeURIComponent(inv.order.number)}`)">{{ inv.order.number }}</div></div>
        <div v-if="inv.fo" class="kv"><div class="kv-k">{{ t('أمر التجهيز / التسليم', 'Fulfilment') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="router.push(`/fo/${encodeURIComponent(inv.fo.number)}`)">{{ inv.fo.number }}</div></div>
        <div v-if="inv.journal" class="kv"><div class="kv-k">{{ t('القيد', 'Journal') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="router.push(`/jv/${encodeURIComponent(inv.journal.number)}`)">{{ inv.journal.number }}</div></div>
        <div v-if="inv.creditOf" class="kv"><div class="kv-k">{{ t('يُطبَّق على الفاتورة', 'Credits invoice') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="setSel(inv.creditOf.number)">{{ inv.creditOf.number }}</div></div>
        <div v-if="inv.creditNotes?.length" class="kv"><div class="kv-k">{{ t('إشعارات دائنة', 'Credit notes') }}</div><div class="kv-v num">{{ inv.creditNotes.map((c) => `${c.number} (${fmtMoney(c.total)})`).join(' · ') }}</div></div>
        <div v-if="inv.notes" class="kv col-span-full"><div class="kv-k">{{ t('ملاحظات', 'Notes') }}</div><div class="kv-v !font-normal">{{ inv.notes }}</div></div>
      </div>
      <div class="overflow-hidden rounded-xl border border-line-2"><DataTable :columns="lineCols" :rows="inv.lines || []" :row-key="(l) => l.id" :min-width="600" /></div>
    </template>
    <template v-if="inv" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="auth.can('ar.receive') && inv.kind === 'invoice' && inv.balance > 0 && inv.status !== 'cancelled'" tone="success" :label="{ ar: 'تسجيل تحصيل', en: 'Record receipt' }" @click="receipt = { customer: inv.customer.code, invoice: inv.number, amount: inv.balance }" />
        <Btn v-if="auth.can('ar.manage') && inv.status !== 'cancelled' && Number(inv.paidAmount) === 0" tone="dangerOutline" :label="{ ar: 'إلغاء الفاتورة', en: 'Cancel invoice' }" @click="cancelling = true" />
      </div>
    </template>
  </Drawer>
  <Teleport v-if="cancelling" to="body">
    <ReasonDrawer open :title="{ ar: `إلغاء ${inv?.number}`, en: `Cancel ${inv?.number}` }" :sub="{ ar: 'تُعكس قيود الإيراد وتكلفة المبيعات ويبقى الأصل والعكس في الأستاذ', en: 'Revenue and COGS journals are reversed; both stay in the ledger' }" :ok-label="{ ar: 'إلغاء الفاتورة', en: 'Cancel invoice' }" :min="5" :pending="act.pending.value" :error="act.error.value" @submit="cancel" @close="cancelling = false" @clear-error="act.clearError()" />
  </Teleport>
  <ReceiptForm :open="!!receipt" :initial="receipt" @close="receipt = null" @done="list.refetch(); sumQ.refetch(); detailQ.refetch()" />
</template>
