<script setup>
// The list behind a figure of a brand dashboard — GET /insights/brands/{id}/lines?figure=sales|returns|overdue|payments
// &customer&product&month&from&to&source&kind&page&pageSize → paged items + totals + definition. The figure is computed
// from this very list (same scope, same rule, same rounding), so its total is shown under it: "= the figure".
//   <FigureLines :open :owner-id :figure :title :customer :month :product :kind :source :from :to @close />
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, Drawer, ErrorBanner, Tabs } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { entityPath } from '@/router/routes';
import { useAuth } from '@/stores/auth';
import { LINE_KIND, SOURCE, amount, canOpen, fmtDay, money, nameOf } from './insights';

const props = defineProps({
  open: { type: Boolean, default: false },
  ownerId: { type: String, required: true },
  figure: { type: String, default: 'sales' },
  title: { type: String, default: '' },
  customer: { type: String, default: '' },
  month: { type: String, default: '' },
  product: { type: String, default: '' },
  kind: { type: String, default: '' },
  source: { type: String, default: '' },
  from: { type: String, default: '' },
  to: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const finance = computed(() => canOpen(auth, 'finance'));
const page = ref(1);
const src = ref('');
const knd = ref('');
watch(() => [props.open, props.figure, props.customer, props.month, props.product, props.kind, props.source], () => { page.value = 1; src.value = props.source || ''; knd.value = props.kind || ''; }, { immediate: true });

const salesLike = computed(() => props.figure === 'sales' || props.figure === 'returns');
const list = useList(() => (props.open ? `/insights/brands/${encodeURIComponent(props.ownerId)}/lines` : null), () => ({
  figure: props.figure, customer: props.customer || undefined, product: props.product || undefined, month: props.month || undefined,
  from: props.month ? undefined : props.from || undefined, to: props.month ? undefined : props.to || undefined,
  source: salesLike.value ? src.value || undefined : undefined, kind: props.figure === 'sales' ? knd.value || undefined : undefined,
  page: page.value, pageSize: 50,
}));
const data = computed(() => list.data.value || null);
const totals = computed(() => data.value?.totals || {});
const withCost = computed(() => totals.value.cogs != null);

const SOURCES = [{ k: '', label: { ar: 'كل المصادر', en: 'Every source' } }, { k: 'ops', label: { ar: 'العمليات', en: 'OPS' } }, { k: 'odoo', label: { ar: 'تاريخ أودو', en: 'Odoo history' } }];
const KINDS = [{ k: '', label: { ar: 'الفواتير والإشعارات', en: 'Invoices and credit notes' } }, { k: 'invoice', label: { ar: 'الفواتير', en: 'Invoices' } }, { k: 'credit_note', label: { ar: 'إشعارات الدائن', en: 'Credit notes' } }];
const setSrc = (v) => { src.value = v; page.value = 1; };
const setKind = (v) => { knd.value = v; page.value = 1; };

const columns = computed(() => {
  if (props.figure === 'overdue') {
    return [
      { key: 'number', header: { ar: 'الفاتورة', en: 'Invoice' }, width: '150px' },
      { key: 'customer', header: { ar: 'الفرع', en: 'Franchisee' }, width: 'minmax(150px,1fr)' },
      { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date', value: (r) => fmtDay(r.date) },
      { key: 'dueDate', header: { ar: 'الاستحقاق', en: 'Due' }, width: '88px', kind: 'date', value: (r) => fmtDay(r.dueDate) },
      { key: 'daysOverdue', header: { ar: 'أيام التأخر', en: 'Days late' }, width: '72px', kind: 'num' },
      { key: 'total', header: { ar: 'إجمالي الفاتورة', en: 'Invoice total' }, width: '96px', kind: 'num', value: (r) => amount(r.total) },
      { key: 'open', header: { ar: 'المفتوح', en: 'Open' }, width: '90px', kind: 'num', value: (r) => amount(r.open) },
      { key: 'brandAmount', header: { ar: 'بنود العلامة', en: 'Brand lines' }, width: '90px', kind: 'num', value: (r) => amount(r.brandAmount) },
      { key: 'openAmount', header: { ar: 'المتأخر للعلامة', en: 'Brand overdue' }, width: '100px', kind: 'num' },
    ];
  }
  if (props.figure === 'payments') {
    return [
      { key: 'number', header: { ar: 'السند', en: 'Receipt' }, width: '130px' },
      { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date', value: (r) => fmtDay(r.date) },
      { key: 'customer', header: { ar: 'الفرع', en: 'Franchisee' }, width: 'minmax(160px,1fr)' },
      { key: 'method', header: { ar: 'الطريقة', en: 'Method' }, width: '96px', kind: 'muted' },
      { key: 'reference', header: { ar: 'المرجع', en: 'Reference' }, width: '120px', ltr: true, kind: 'muted' },
      { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num' },
    ];
  }
  return [
    { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date', value: (r) => fmtDay(r.date) },
    { key: 'document', header: { ar: 'المستند', en: 'Document' }, width: '130px', hidden: !data.value?.items?.some((r) => r.document) },
    { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '110px', bare: true },
    { key: 'customer', header: { ar: 'الفرع', en: 'Franchisee' }, width: 'minmax(140px,1fr)' },
    { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(160px,1.2fr)' },
    { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '68px', kind: 'num', value: (r) => fmtNum(r.qty, Number.isInteger(r.qty) ? 0 : 2) },
    { key: 'amount', header: { ar: 'القيمة', en: 'Amount' }, width: '96px', kind: 'num' },
    { key: 'cogs', header: { ar: 'التكلفة', en: 'Cost' }, width: '88px', kind: 'num', hidden: !withCost.value, value: (r) => (r.cogs == null ? '—' : amount(r.cogs)) },
    { key: 'margin', header: { ar: 'الهامش', en: 'Margin' }, width: '88px', kind: 'num', hidden: !withCost.value, value: (r) => (r.margin == null ? '—' : amount(r.margin)) },
  ];
});
const docLink = (r) => (finance.value && r.source === 'ops' && r.document?.number ? entityPath('customerinvoice', r.document.number) : null);
const invoiceLink = (r) => (finance.value && r.source === 'ops' && r.number ? entityPath('customerinvoice', r.number) : null);
const paymentLink = (r) => (finance.value && r.number ? entityPath('payment', r.number) : null);
const empty = { ar: 'لا بنود لهذا الرقم في الفترة والفلاتر.', en: 'No line behind this figure for the period and filters.' };
const period = computed(() => (data.value?.period ? `${fmtDay(data.value.period.from)} → ${fmtDay(data.value.period.to)}` : props.month ? props.month : ''));
</script>

<template>
  <Drawer :open="open" :title="title" :sub="period" :width="1040" :z-index="80" @close="emit('close')">
    <div v-if="data?.definition" class="hint teal !mt-0 mb-3"><b>{{ t('التعريف', 'Definition') }}:</b> {{ bi(data.definition) }}</div>
    <div v-if="salesLike" class="row wrap mb-2">
      <Tabs :model-value="src" :tabs="SOURCES" variant="pill" class="!mb-0" @update:model-value="setSrc" />
      <Tabs v-if="figure === 'sales'" :model-value="knd" :tabs="KINDS" variant="pill" class="!mb-0" @update:model-value="setKind" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="data" :loading="list.isFetching.value" :row-key="(r, i) => r.id || `${r.source}-${r.document?.id || r.documentId || r.number}-${i}`" :empty-text="empty" @page="(p) => (page = p)">
        <template #cell-number="{ row }">
          <span class="inline-flex items-center gap-1">
            <RouterLink v-if="figure === 'overdue' ? invoiceLink(row) : paymentLink(row)" class="cell-id text-azure underline" :to="figure === 'overdue' ? invoiceLink(row) : paymentLink(row)">{{ row.number }}</RouterLink>
            <span v-else class="cell-id">{{ row.number }}</span>
            <Chip v-if="row.source" small :map="SOURCE" :k="row.source" />
          </span>
        </template>
        <template #cell-document="{ row }">
          <RouterLink v-if="docLink(row)" class="cell-id text-azure underline" :to="docLink(row)">{{ row.document.number }}</RouterLink>
          <span v-else class="cell-id">{{ row.document?.number || '—' }}</span>
        </template>
        <template #cell-kind="{ row }">
          <span class="inline-flex flex-wrap items-center justify-center gap-1"><Chip small :map="LINE_KIND" :k="row.kind" /><Chip small :map="SOURCE" :k="row.source" /></span>
        </template>
        <template #cell-customer="{ row }">
          <div class="min-w-0">
            <div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ nameOf(row.customer) }}</div>
            <div class="cell-sub"><span class="num ltr inline-block">{{ row.customer?.code }}</span></div>
          </div>
        </template>
        <template #cell-product="{ row }">
          <div class="min-w-0">
            <div class="truncate text-[10.5px] text-ink" dir="auto">{{ row.product ? nameOf(row.product) : row.productName || '—' }}</div>
            <div class="cell-sub"><span v-if="row.product" class="num ltr inline-block">{{ row.product.sku }}</span><template v-else-if="row.productName">{{ t('اسم من أودو', 'Name from Odoo') }}</template></div>
          </div>
        </template>
        <template #cell-amount="{ row }"><b :class="row.amount < 0 ? 'text-bad' : ''">{{ amount(row.amount) }}</b></template>
        <template #cell-openAmount="{ row }"><b class="text-bad">{{ amount(row.openAmount) }}</b></template>
        <template #cell-daysOverdue="{ row }"><span class="font-bold text-bad">{{ fmtNum(row.daysOverdue) }}</span></template>
      </DataTable>
    </div>

    <div v-if="data" class="card mt-3 !p-3">
      <div class="row wrap !gap-x-5 !gap-y-1 text-[11px]">
        <span class="font-extrabold text-sec">{{ t('مجموع القائمة كلها (= الرقم)', 'Total of the whole list (= the figure)') }}</span>
        <template v-if="figure === 'overdue'">
          <span>{{ t('الفواتير', 'Invoices') }} <b class="num">{{ fmtNum(totals.count) }}</b></span>
          <span>{{ t('المتأخر للعلامة', 'Brand overdue') }} <b class="num text-bad">{{ money(totals.amount) }}</b></span>
        </template>
        <template v-else-if="figure === 'payments'">
          <span>{{ t('السندات', 'Receipts') }} <b class="num">{{ fmtNum(data.total) }}</b></span>
          <span>{{ t('المبلغ', 'Amount') }} <b class="num">{{ money(totals.amount) }}</b></span>
        </template>
        <template v-else>
          <span>{{ t('البنود', 'Lines') }} <b class="num">{{ fmtNum(data.total) }}</b></span>
          <span>{{ t('الكمية', 'Qty') }} <b class="num">{{ fmtNum(totals.qty, Number.isInteger(totals.qty) ? 0 : 2) }}</b></span>
          <span>{{ t('القيمة', 'Amount') }} <b class="num">{{ money(totals.amount) }}</b></span>
          <template v-if="withCost">
            <span>{{ t('مبيعات العمليات', 'OPS sales') }} <b class="num">{{ money(totals.opsAmount) }}</b></span>
            <span>{{ t('تكلفتها', 'Its cost') }} <b class="num">{{ money(totals.cogs) }}</b></span>
            <span>{{ t('الهامش', 'Margin') }} <b class="num text-ok">{{ money((totals.opsAmount || 0) - (totals.cogs || 0)) }}</b></span>
          </template>
        </template>
      </div>
      <div class="mt-1 text-[9px] text-faint">{{ t('المجموع لكل البنود المطابقة، لا للصفحة المعروضة فقط. المبالغ قبل الضريبة.', 'The total covers every matching line, not only the page on screen. Amounts before VAT.') }}</div>
    </div>
  </Drawer>
</template>
