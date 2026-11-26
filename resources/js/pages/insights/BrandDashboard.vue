<script setup>
// The dashboard of one brand owner — GET /insights/brands/{id}/dashboard?from&to (whole months, default the last 12).
// Every figure carries its definition (`definitions[...]`) and opens the list it is computed from (FigureLines,
// GET /insights/brands/{id}/lines?figure=…). A null KPI is one this user may not see: its tile is not drawn.
// Query string: from / to (YYYY-MM) · fig · figCustomer · figMonth · figProduct · figKind · figSource.
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiGrid, ProgressBar, SectionCard, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import FigureLines from './FigureLines.vue';
import TrendChart from './TrendChart.vue';
import { FR_STATUS, SOURCE, addMonths, amount, fmtDay, money, monthIso, monthLabel, nameOf, pct, printPage, useInsightsNav } from './insights';

const props = defineProps({
  ownerId: { type: String, required: true },
});

const nav = useInsightsNav();
const ym = /^\d{4}-\d{2}$/;
const from = computed(() => (ym.test(nav.get('from')) ? nav.get('from') : ''));
const to = computed(() => (ym.test(nav.get('to')) ? nav.get('to') : ''));
const q = useGet(() => `/insights/brands/${encodeURIComponent(props.ownerId)}/dashboard`, () => ({ from: from.value ? `${from.value}-01` : undefined, to: to.value ? `${to.value}-01` : undefined }));
const d = computed(() => q.data.value || null);
const k = computed(() => d.value?.kpis || {});
const defs = computed(() => d.value?.definitions || {});
const def = (key) => (defs.value[key] ? bi(defs.value[key]) : '');

// ---- period
const PRESETS = [3, 6, 12, 24];
const thisMonth = monthIso(new Date());
const preset = computed(() => {
  if (!from.value && !to.value) return 12;
  if (to.value && to.value !== thisMonth) return null;
  const n = PRESETS.find((p) => addMonths(thisMonth, -(p - 1)) === from.value);
  return n || null;
});
const setPreset = (n) => nav.patch({ from: n === 12 ? null : addMonths(thisMonth, -(n - 1)), to: null, fig: null });
const setFrom = (v) => nav.patch({ from: ym.test(v) ? v : null, fig: null });
const setTo = (v) => nav.patch({ to: ym.test(v) ? v : null, fig: null });

// ---- the list behind a figure
const fig = computed(() => (['sales', 'returns', 'overdue', 'payments'].includes(nav.get('fig')) ? nav.get('fig') : null));
function open(figure, extra = {}) {
  nav.patch({ fig: figure, figCustomer: extra.customer || null, figMonth: extra.month || null, figProduct: extra.product || null, figKind: extra.kind || null, figSource: extra.source || null });
}
const closeFig = () => nav.patch({ fig: null, figCustomer: null, figMonth: null, figProduct: null, figKind: null, figSource: null });
const figTitle = computed(() => {
  const f = fig.value;
  const base = { sales: { ar: 'بنود المشتريات', en: 'Purchase lines' }, returns: { ar: 'بنود المرتجعات', en: 'Return lines' }, overdue: { ar: 'الفواتير المتأخرة', en: 'Overdue invoices' }, payments: { ar: 'سندات القبض', en: 'Receipts' } }[f];
  if (!base) return '';
  const parts = [bi(base)];
  if (nav.get('figKind') === 'invoice') parts.push(t('الفواتير فقط', 'invoices only'));
  if (nav.get('figSource') === 'ops') parts.push(t('العمليات فقط', 'OPS only'));
  if (nav.get('figMonth')) parts.push(monthLabel(nav.get('figMonth')));
  const c = nav.get('figCustomer') && d.value?.franchisees?.find((r) => r.customer?.id === nav.get('figCustomer'));
  if (c) parts.push(nameOf(c.customer));
  const p = nav.get('figProduct') && d.value?.topProducts?.find((r) => r.product?.id === nav.get('figProduct'));
  if (p) parts.push(p.product.sku);
  return parts.join(' · ');
});

// ---- tiles (a null figure is not allowed for this user: no tile)
const franchiseesEl = ref(null);
const toFranchisees = () => franchiseesEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
const tiles = computed(() => {
  const x = k.value;
  const out = [
    { key: 'purchases', label: { ar: 'صافي المشتريات', en: 'Net purchases' }, value: money(x.purchases), color: '#654e92', def: def('purchases'), click: () => open('sales') },
    { key: 'invoiced', label: { ar: 'المفوتر', en: 'Invoiced' }, value: money(x.invoiced), color: '#3C79F5', def: def('invoiced'), click: () => open('sales', { kind: 'invoice' }) },
  ];
  if (x.returns != null) out.push({ key: 'returns', label: { ar: 'المرتجعات', en: 'Returns' }, value: money(x.returns), sub: x.returnsPct != null ? t(`${pct(x.returnsPct)} من المفوتر`, `${pct(x.returnsPct)} of invoiced`) : null, color: '#b26a16', def: def('returns'), click: () => open('returns') });
  out.push({ key: 'target', label: { ar: 'الهدف', en: 'Target' }, value: money(x.target), color: '#20242E', def: def('target'), click: toFranchisees });
  out.push({ key: 'achievement', label: { ar: 'نسبة التحقيق', en: 'Achievement' }, value: pct(x.achievementPct), color: x.achievementPct == null ? '#20242E' : x.achievementPct >= 100 ? '#1d7a3e' : x.achievementPct >= 70 ? '#b26a16' : '#b23b3b', def: def('achievement'), click: toFranchisees });
  out.push({ key: 'franchisees', label: { ar: 'فروع تشتري', en: 'Buying franchisees' }, value: fmtNum(x.franchisees), color: '#0d7f93', def: def('franchisees'), click: () => nav.patch({ sec: 'franchisees', fig: null }) });
  if (x.overdue) out.push({ key: 'overdue', label: { ar: 'متأخرات الفواتير', en: 'Overdue invoices' }, value: money(x.overdue.amount), sub: t(`${fmtNum(x.overdue.count)} فاتورة`, `${fmtNum(x.overdue.count)} invoice(s)`), color: x.overdue.count ? '#b23b3b' : '#20242E', def: def('overdue'), click: () => open('overdue') });
  if (x.payments) out.push({ key: 'payments', label: { ar: 'المدفوعات', en: 'Payments' }, value: money(x.payments.amount), sub: t(`${fmtNum(x.payments.count)} سند`, `${fmtNum(x.payments.count)} receipt(s)`), color: '#1d7a3e', def: def('payments'), click: () => open('payments') });
  if (x.margin) out.push({ key: 'margin', label: { ar: 'هامش الربح', en: 'Margin' }, value: money(x.margin.margin), sub: t(`${pct(x.margin.marginPct)} من مبيعات العمليات ${amount(x.margin.sales)}`, `${pct(x.margin.marginPct)} of OPS sales ${amount(x.margin.sales)}`), color: '#1d7a3e', def: def('margin'), click: () => open('sales', { source: 'ops' }) });
  return out;
});

// ---- franchisees of the dashboard
const frColumns = computed(() => [
  { key: 'customer', header: { ar: 'الفرع (العميل)', en: 'Franchisee (customer)' }, width: 'minmax(190px,1.5fr)', sortable: true, sortValue: (r) => nameOf(r.customer) },
  { key: 'place', header: { ar: 'المنطقة / المدينة', en: 'Region / city' }, width: 'minmax(110px,0.8fr)' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '84px', bare: true, sortable: true },
  { key: 'monthlyTarget', header: { ar: 'الهدف الشهري', en: 'Monthly target' }, width: '92px', kind: 'num', sortable: true },
  { key: 'periodTarget', header: { ar: 'هدف الفترة', en: 'Period target' }, width: '96px', kind: 'num', sortable: true },
  { key: 'purchases', header: { ar: 'المشتريات', en: 'Purchases' }, width: '100px', kind: 'num', sortable: true },
  { key: 'achievementPct', header: { ar: 'التحقيق', en: 'Achievement' }, width: '120px', sortable: true },
  { key: 'lastPurchase', header: { ar: 'آخر شراء', en: 'Last purchase' }, width: '88px', kind: 'date', sortable: true, value: (r) => fmtDay(r.lastPurchase) },
  { key: 'overdue', header: { ar: 'متأخر', en: 'Overdue' }, width: '96px', kind: 'num', hidden: !d.value?.access?.invoices, sortValue: (r) => r.overdue?.amount ?? 0, sortable: true },
]);
const prodColumns = [
  { key: 'name', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(180px,1.6fr)' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '80px', kind: 'num' },
  { key: 'amount', header: { ar: 'القيمة', en: 'Amount' }, width: '100px', kind: 'num' },
];
const overdueColumns = [
  { key: 'number', header: { ar: 'الفاتورة', en: 'Invoice' }, width: '120px', kind: 'id' },
  { key: 'customer', header: { ar: 'الفرع', en: 'Franchisee' }, width: 'minmax(140px,1fr)' },
  { key: 'dueDate', header: { ar: 'الاستحقاق', en: 'Due' }, width: '88px', kind: 'date', value: (r) => fmtDay(r.dueDate) },
  { key: 'daysOverdue', header: { ar: 'أيام التأخر', en: 'Days late' }, width: '76px', kind: 'num' },
  { key: 'openAmount', header: { ar: 'مبلغ العلامة المفتوح', en: 'Open brand amount' }, width: '110px', kind: 'num' },
];
const overdueCustomer = (r) => d.value?.franchisees?.find((f) => f.customer?.id === r.customerId)?.customer || null;
</script>

<template>
  <div class="row wrap mb-2.5 no-print">
    <div class="pill-bar !mb-0">
      <button v-for="n in PRESETS" :key="n" type="button" class="pill" :class="{ active: preset === n }" @click="setPreset(n)">{{ t(`آخر ${n} شهرًا`, `Last ${n} months`) }}</button>
    </div>
    <TextInput small type="month" class="w-[150px]" dir="ltr" :model-value="from || d?.period?.from?.slice(0, 7) || ''" :placeholder="{ ar: 'من YYYY-MM', en: 'From YYYY-MM' }" @update:model-value="setFrom" />
    <TextInput small type="month" class="w-[150px]" dir="ltr" :model-value="to || d?.period?.to?.slice(0, 7) || ''" :placeholder="{ ar: 'إلى YYYY-MM', en: 'To YYYY-MM' }" @update:model-value="setTo" />
    <div class="grow" />
    <Btn size="sm" tone="soft" :label="{ ar: 'طباعة', en: 'Print' }" @click="printPage" />
  </div>

  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="!d && q.isLoading.value" class="skel h-[260px]" />
  <template v-else-if="d">
    <div class="mb-2 text-[10px] text-muted">
      {{ t('الفترة', 'Period') }} <b class="num ltr inline-block">{{ fmtDay(d.period.from) }} → {{ fmtDay(d.period.to) }}</b>
      · {{ t('منتجات العلامة', 'Brand products') }} <b class="num">{{ fmtNum(d.products) }}</b>
      · {{ t('يُحسب من أودو حتى', 'Counted from Odoo up to') }} <b class="num ltr inline-block">{{ fmtDay(d.cutover?.date) }}</b> {{ t('ومن العمليات بعده', 'and from OPS after it') }}<template v-if="d.cutover?.auto"> ({{ t('يوم تحويل تلقائي', 'automatic cutover day') }})</template>
      <template v-if="d.alerts?.unread"> · <button type="button" class="font-extrabold text-bad underline" @click="nav.patch({ sec: 'alerts', fig: null })">{{ t(`${d.alerts.unread} تنبيه غير مقروء`, `${d.alerts.unread} unread alert(s)`) }}</button></template>
    </div>

    <div v-if="!d.products" class="hint amber !mt-0 mb-3">{{ t('لا منتجات في نطاق هذه العلامة بعد — كل الأرقام صفر حتى تُسمّى منتجاتها أو فئاتها.', 'No product is in this brand\'s scope yet — every figure is zero until its products or categories are named.') }}</div>

    <KpiGrid :min="180">
      <button v-for="tile in tiles" :key="tile.key" type="button" class="kpi click text-start" :title="tile.def" @click="tile.click">
        <div class="kpi-v num" :style="{ color: tile.color }">{{ tile.value }}</div>
        <div class="kpi-l">{{ bi(tile.label) }}</div>
        <div v-if="tile.sub" class="mt-[2px] text-[9.5px] font-bold text-sec">{{ tile.sub }}</div>
        <div class="mt-1 line-clamp-3 text-[9px] leading-[1.6] text-faint">{{ tile.def }}</div>
      </button>
    </KpiGrid>

    <SectionCard class="mt-3" :title="{ ar: 'المشتريات مقابل الهدف شهريًا', en: 'Purchases vs target per month' }" :sub="{ ar: 'اضغط عمود شهر لفتح بنوده.', en: 'Click a month\'s bar to open its lines.' }">
      <TrendChart :trend="d.trend" @month="(m) => open('sales', { month: m })" />
    </SectionCard>

    <div ref="franchiseesEl" class="mt-3">
      <SectionCard :padded="false" :title="{ ar: 'الفروع في الفترة', en: 'Franchisees in the period' }" :count="d.franchisees.length" :sub="{ ar: 'اضغط فرعًا لفتح مشترياته. التحقيق = المشتريات ÷ هدف الفترة.', en: 'Click a franchisee to open its purchases. Achievement = purchases ÷ period target.' }">
        <DataTable :columns="frColumns" :rows="d.franchisees" :page-size="25" :row-key="(r) => r.id" :empty-text="{ ar: 'لا فروع لهذه العلامة بعد.', en: 'This brand has no franchisee yet.' }"
                   @row-click="(r) => r.customer && open('sales', { customer: r.customer.id })">
          <template #cell-customer="{ row }">
            <div class="min-w-0">
              <div class="cell-name truncate" dir="auto">{{ nameOf(row.customer) }}</div>
              <div class="cell-sub"><span class="num ltr inline-block">{{ row.customer?.code }}</span><template v-if="row.sourceSystem === 'odoo'"> · {{ t('من أودو', 'From Odoo') }}</template></div>
            </div>
          </template>
          <template #cell-place="{ row }"><span class="truncate text-[10px] text-sec" dir="auto">{{ [row.region, row.city].filter(Boolean).join(' / ') || '—' }}</span></template>
          <template #cell-status="{ row }"><Chip small :map="FR_STATUS" :k="row.status" /></template>
          <template #cell-monthlyTarget="{ row }">{{ amount(row.monthlyTarget) }}</template>
          <template #cell-periodTarget="{ row }">{{ amount(row.periodTarget) }}</template>
          <template #cell-purchases="{ row }"><b>{{ amount(row.purchases) }}</b></template>
          <template #cell-achievementPct="{ row }">
            <div v-if="row.achievementPct != null" class="w-full">
              <div class="num text-[10px] font-extrabold" :class="row.achievementPct >= 100 ? 'text-ok' : row.achievementPct >= 70 ? 'text-warn' : 'text-bad'">{{ pct(row.achievementPct) }}</div>
              <ProgressBar :pct="row.achievementPct" :height="5" :color="row.achievementPct >= 100 ? '#1d7a3e' : row.achievementPct >= 70 ? '#b26a16' : '#b23b3b'" />
            </div>
            <span v-else class="text-[9.5px] text-faint">{{ t('بلا هدف', 'No target') }}</span>
          </template>
          <template #cell-overdue="{ row }">
            <span v-if="row.overdue?.count" class="font-bold text-bad">{{ amount(row.overdue.amount) }} <span class="text-[9px]">({{ row.overdue.count }})</span></span>
            <span v-else class="text-faint">0</span>
          </template>
        </DataTable>
      </SectionCard>
    </div>

    <div class="grid-2 mt-3">
      <SectionCard :padded="false" :title="{ ar: 'أعلى المنتجات قيمة', en: 'Top products by value' }" :sub="{ ar: 'أعلى عشرة في الفترة؛ اضغط منتجًا لفتح بنوده.', en: 'Top ten of the period; click one to open its lines.' }">
        <DataTable :columns="prodColumns" :rows="d.topProducts" :row-key="(r, i) => r.product?.id || `${r.name}-${i}`" :empty-text="{ ar: 'لا مشتريات في الفترة.', en: 'No purchase in the period.' }"
                   @row-click="(r) => r.product && open('sales', { product: r.product.id })">
          <template #cell-name="{ row }">
            <div class="min-w-0">
              <div class="cell-name truncate" dir="auto">{{ row.product ? nameOf(row.product) : row.name }}</div>
              <div class="cell-sub"><span v-if="row.product" class="num ltr inline-block">{{ row.product.sku }}</span><template v-else>{{ t('منتج من أودو غير مربوط', 'Odoo product not linked') }}</template></div>
            </div>
          </template>
          <template #cell-qty="{ row }">{{ fmtNum(row.qty, Number.isInteger(row.qty) ? 0 : 2) }}</template>
          <template #cell-amount="{ row }">{{ amount(row.amount) }}</template>
        </DataTable>
      </SectionCard>

      <SectionCard v-if="d.overdueInvoices" :padded="false" :title="{ ar: 'أقدم الفواتير المتأخرة', en: 'Oldest overdue invoices' }" :sub="{ ar: 'أقدم عشر؛ القائمة الكاملة من «عرض الكل».', en: 'The ten oldest; the full list from “Show all”.' }">
        <template #actions><Btn size="sm" tone="ghost" :label="{ ar: 'عرض الكل', en: 'Show all' }" @click="open('overdue')" /></template>
        <DataTable :columns="overdueColumns" :rows="d.overdueInvoices" :row-key="(r) => `${r.source}-${r.documentId}`" :empty-text="{ ar: 'لا فواتير متأخرة.', en: 'No overdue invoice.' }" @row-click="() => open('overdue')">
          <template #cell-number="{ row }"><span class="cell-id">{{ row.number }}</span> <Chip small :map="SOURCE" :k="row.source" /></template>
          <template #cell-customer="{ row }"><span class="truncate text-[10px]" dir="auto">{{ nameOf(overdueCustomer(row)) }}</span></template>
          <template #cell-daysOverdue="{ row }"><span class="font-bold text-bad">{{ fmtNum(row.daysOverdue) }}</span></template>
          <template #cell-openAmount="{ row }">{{ amount(row.openAmount) }}</template>
        </DataTable>
      </SectionCard>
    </div>
    <div class="mt-2 text-[9px] text-faint">{{ t('المبالغ بالريال قبل الضريبة.', 'Amounts in SAR before VAT.') }}</div>

    <FigureLines :open="!!fig" :owner-id="ownerId" :figure="fig || 'sales'" :title="figTitle" :customer="nav.get('figCustomer')" :month="nav.get('figMonth')" :product="nav.get('figProduct')"
                 :kind="nav.get('figKind')" :source="nav.get('figSource')" :from="d.period.from" :to="d.period.to" @close="closeFig" />
  </template>
</template>
