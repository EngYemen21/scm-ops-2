<script setup>
// Supplier invoices tab: payables snapshot + list (status pills, due filter, search) + row actions
// (approve · pay · cancel) and the invoice drawer (lines, three-way match, variances).
// Deep link: `?tab=inv&invoice=INV-…` opens the drawer.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet, useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, Tabs } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import FilterBar from './FilterBar.vue';
import InvoiceActions from './InvoiceActions.vue';
import InvoiceDrawer from './InvoiceDrawer.vue';
import { INVOICE_LABELS, qs, sname } from './shared';

const route = useRoute();
const router = useRouter();

const sel = computed(() => qs(route.query.invoice) || null);
const setSel = (n) => router.replace({ query: { ...route.query, invoice: n || undefined } });

const q = ref('');
const status = ref('');
const due = ref('');
const page = ref(1);
watch([q, status, due], () => { page.value = 1; });
const list = useList('/procurement/invoices', () => ({ q: q.value, status: status.value, due: due.value, page: page.value, pageSize: 25 }));
/** { disputed, awaitingApproval, openTotal, overdue{ count, total }, dueThisWeek{ count, total } } */
const sumQ = useGet('/procurement/invoices/summary', undefined, { refetchInterval: 60_000 });
const sum = computed(() => sumQ.data.value);

const STATUSES = [
  { k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'disputed', label: { ar: 'مختلف عليها', en: 'Disputed' } }, { k: 'matched', label: { ar: 'مطابقة — بانتظار الاعتماد', en: 'Matched — awaiting approval' } },
  { k: 'approved', label: { ar: 'معتمدة للصرف', en: 'Approved for payment' } }, { k: 'paid', label: { ar: 'مسدَّدة', en: 'Paid' } }, { k: 'cancelled', label: { ar: 'ملغاة', en: 'Cancelled' } },
];
const DUE = [{ k: '', label: { ar: 'كل الاستحقاقات', en: 'Any due date' } }, { k: 'overdue', label: { ar: 'متأخرة السداد', en: 'Overdue' } }, { k: 'week', label: { ar: 'تستحق خلال أسبوع', en: 'Due this week' } }];
const columns = [
  { key: 'number', header: { ar: 'الفاتورة', en: 'Invoice' }, width: '150px' },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(150px,1.2fr)', kind: 'name', value: (r) => sname(r.supplier) },
  { key: 'po', header: { ar: 'أمر الشراء', en: 'PO' }, width: '120px', kind: 'id', value: (r) => r.po?.number || '—' },
  { key: 'invoiceDate', header: { ar: 'تاريخ الفاتورة', en: 'Invoice date' }, width: '95px', kind: 'date', value: (r) => fmtDateOnly(r.invoiceDate) },
  { key: 'dueDate', header: { ar: 'الاستحقاق', en: 'Due' }, width: '110px', sortable: true },
  { key: 'total', header: { ar: 'الإجمالي (شامل الضريبة)', en: 'Total (incl. VAT)' }, width: '120px', kind: 'num', sortable: true, value: (r) => fmtMoney(r.total) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px' },
  { key: 'act', header: '', width: 'minmax(160px,1fr)' },
];
</script>

<template>
  <KpiGrid compact class="!mb-3">
    <KpiCard compact clickable :value="sum?.disputed" :label="{ ar: 'فواتير مختلف عليها', en: 'Disputed invoices' }" color="#b23b3b" :active="status === 'disputed'" :loading="!sum" @click="status = status === 'disputed' ? '' : 'disputed'" />
    <KpiCard compact clickable :value="sum?.awaitingApproval" :label="{ ar: 'بانتظار اعتماد الصرف', en: 'Awaiting approval' }" color="#b26a16" :active="status === 'matched'" :loading="!sum" @click="status = status === 'matched' ? '' : 'matched'" />
    <KpiCard compact :value="sum ? fmtMoney(sum.openTotal) : null" :label="{ ar: 'مستحقات مفتوحة ر.س', en: 'Open payables SAR' }" color="#654e92" :loading="!sum" />
    <KpiCard compact clickable :value="sum?.overdue?.count" :label="{ ar: 'متأخرة السداد', en: 'Overdue' }" :sub="sum?.overdue?.count ? `${fmtMoney(sum.overdue.total)} ${t('ر.س', 'SAR')}` : null" color="#7a1f1f" :active="due === 'overdue'" :loading="!sum" @click="due = due === 'overdue' ? '' : 'overdue'" />
    <KpiCard compact clickable :value="sum?.dueThisWeek?.count" :label="{ ar: 'تستحق خلال أسبوع', en: 'Due this week' }" :sub="sum?.dueThisWeek?.count ? `${fmtMoney(sum.dueThisWeek.total)} ${t('ر.س', 'SAR')}` : null" color="#0d7f93" :active="due === 'week'" :loading="!sum" @click="due = due === 'week' ? '' : 'week'" />
  </KpiGrid>

  <FilterBar v-model="q">
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <Tabs v-model="due" :tabs="DUE" variant="pill" class="!mb-0" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1040" :empty-text="{ ar: 'لا فواتير موردين مطابقة — تُسجَّل الفاتورة من صفحة أمر الشراء بعد الاستلام.', en: 'No matching supplier invoices — register one from the PO page after receiving.' }" @page="page = $event" @row-click="(r) => setSel(r.number)">
      <template #cell-number="{ row }">
        <div>
          <div class="cell-id cursor-pointer" @click.stop="setSel(row.number)">{{ row.number }}</div>
          <div class="num ltr text-start text-[8px] text-faint">{{ row.supplierInvoiceNo }} · {{ fmtNum(row._count?.lines || 0) }} {{ t('بند', 'lines') }}</div>
        </div>
      </template>
      <template #cell-dueDate="{ row }">
        <span class="cell-date ltr">{{ fmtDateOnly(row.dueDate) }}</span>
        <Chip v-if="row.overdue" small fg="#b23b3b" bg="#fdecec" :label="{ ar: 'متأخرة', en: 'Overdue' }" class="ms-1" />
      </template>
      <template #cell-status="{ row }">
        <Chip :map="INVOICE_LABELS" :k="row.status" />
        <div v-if="row.status === 'disputed' && row.variances?.length" class="mt-0.5 text-[8.5px] text-bad">{{ fmtNum(row.variances.length) }} {{ t('فرق', 'variance(s)') }}</div>
      </template>
      <template #cell-act="{ row }"><div @click.stop><InvoiceActions :invoice="row" /></div></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('المطابقة الثلاثية: كمية الفاتورة ≤ المقبول في إشعارات الاستلام، والسعر ضمن نسبة التسامح عن سعر أمر الشراء، والخصم والشحن كما في الأمر — أي فرق يجعل الفاتورة «مختلفًا عليها» ويحتاج قرارًا مكتوبًا من المالية. مسجّل الفاتورة لا يعتمدها، والسداد يُسجَّل بمرجع التحويل.', 'Three-way match: invoiced qty ≤ accepted on GRNs, price within the tolerance of the PO price, discount/shipping as ordered — any variance marks the invoice disputed and needs a written finance decision. The registrar never approves; payment is recorded with the transfer reference.') }}</div>

  <InvoiceDrawer :number="sel" @close="setSel(null)" />
</template>
