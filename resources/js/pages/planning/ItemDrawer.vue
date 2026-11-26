<script setup>
// One item of a run, read as "why this quantity": the method asked and used (and why a fallback), the history it was
// built on, the forecast per period with the stock projection, then every number behind the suggestion — stock,
// safety stock, lead time, price, rounding, minimum / maximum — each with where it came from and how it was reached.
// GET /planning/runs/{run}/items/{item} → item + explain + periods + history + requirements + patterns + customers + push.
//   <ItemDrawer :run-id="…" :item-id="idOrNull" @close="…" />
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Chip, DataTable, Drawer, ErrorBanner, KV } from '@/components';
import { bi, fmtDate, fmtNum, num, t } from '@/i18n';
import { BAND, CRITICALITY, ITEM_STATUS, LEAD_SOURCE, METHOD, ORDERPOINT, PATTERN_STATE, PRICE_SOURCE, fmtDay, label, money, nameOf, qty } from './planning';

const props = defineProps({
  runId: { type: String, default: null },
  itemId: { type: String, default: null },
});
const emit = defineEmits(['close']);

const q = useGet(() => (props.runId && props.itemId ? `/planning/runs/${encodeURIComponent(props.runId)}/items/${encodeURIComponent(props.itemId)}` : null));
const it = computed(() => (props.itemId ? q.data.value || null : null));
const ex = computed(() => it.value?.explain || {});
const m = computed(() => ex.value.method || {});
const isStock = computed(() => it.value?.kind === 'stock');
const d1 = (v) => (v == null ? '—' : fmtNum(num(v), Number.isInteger(num(v)) ? 0 : 2));
const d3 = (v) => (v == null ? '—' : fmtNum(num(v), 3));

/** The one-paragraph answer: what is suggested and the chain that led there. */
const summary = computed(() => {
  const i = it.value; if (!i) return null;
  const s = num(i.suggestedQty);
  if (!isStock.value) return t(`حصة العميل من الطلب المتوقع: ${qty(i.forecast)} خلال فترة التوقع.`, `The customer's share of the forecast demand: ${qty(i.forecast)} over the forecast window.`);
  if (s <= 0) {
    return i.status === 'no_demand' ? t('لا طلب متوقع ولا طلبات محجوزة — لا يُقترح شراء.', 'No forecast demand and no booked order — nothing to buy.')
      : t(`المخزون المتاح (${qty(i.onHand)}) مع الوارد المحجوز يغطي الطلب المتوقع (${qty(i.forecast)}) والمخزون الآمن (${qty(i.safety)}) طوال فترة التوقع — لا يُقترح شراء.`,
        `Stock on hand (${qty(i.onHand)}) plus booked receipts covers the forecast demand (${qty(i.forecast)}) and the safety stock (${qty(i.safety)}) over the whole window — nothing to buy.`);
  }
  return t(`يُقترح شراء ${qty(s)} وحدة، أول طلب قبل ${fmtDay(i.orderBy)}: الرصيد المتوقع ينزل تحت المخزون الآمن (${qty(i.safety)}) ابتداءً من ${fmtDay(i.firstShortage)}، ومدة التوريد ${d1(i.leadDays)} يوم${num(ex.value.lead?.bufferDays) ? ` + هامش ${d1(ex.value.lead.bufferDays)} يوم` : ''}، والكمية مقرّبة إلى أقل كمية طلب (${qty(i.moq)}) ومضاعف (${qty(i.multiple)}).`,
    `Buy ${qty(s)} unit(s), first order by ${fmtDay(i.orderBy)}: the projected balance falls below the safety stock (${qty(i.safety)}) from ${fmtDay(i.firstShortage)}, the lead time is ${d1(i.leadDays)} day(s)${num(ex.value.lead?.bufferDays) ? ` + ${d1(ex.value.lead.bufferDays)} buffer day(s)` : ''}, and the quantity is rounded to the minimum order (${qty(i.moq)}) and multiple (${qty(i.multiple)}).`);
});

const DETAIL = {
  growthPct: { ar: 'النمو %', en: 'Growth %' }, scale: { ar: 'معامل تحجيم الموسم', en: 'Season scale' }, window: { ar: 'نافذة المتوسط', en: 'Window' },
  periods: { ar: 'أوزان الفترات', en: 'Period weights' }, days: { ar: 'أيام السجل المستخدمة', en: 'History days used' }, customers: { ar: 'العملاء', en: 'Customers' },
  active: { ar: 'أنماط نشطة', en: 'Active patterns' }, lapsed: { ar: 'أنماط منقطعة', en: 'Lapsed patterns' }, estimated: { ar: 'عملاء مقدّرون', en: 'Estimated customers' },
  seasonFactor: { ar: 'معامل الموسم', en: 'Season factor' }, rollup: { ar: 'الإجمالي', en: 'Roll-up' },
};
const detailRows = computed(() => Object.entries(m.value.detail || {}).map(([k, v]) => ({ k: DETAIL[k] || { ar: k, en: k }, v: Array.isArray(v) ? v.join(' · ') : v && typeof v === 'object' ? JSON.stringify(v) : v == null ? '—' : String(v) })));

const safetyText = computed(() => {
  const s = ex.value.safety; if (!s) return '';
  const pd = d3(ex.value.forecast?.perDay);
  return s.method === 'service_level'
    ? t(`مستوى خدمة: z (${s.z}) × انحراف الطلب اليومي (${d3(s.sigmaDaily)}) × √ أيام التعرّض (${d1(s.exposure)}) = ${qty(s.qty)}`, `Service level: z (${s.z}) × daily demand deviation (${d3(s.sigmaDaily)}) × √ exposure days (${d1(s.exposure)}) = ${qty(s.qty)}`)
    : t(`أيام طلب: متوسط الطلب اليومي (${pd}) × أيام الأمان (${d1(s.days)}) = ${qty(s.qty)}`, `Days of demand: daily demand (${pd}) × safety days (${d1(s.days)}) = ${qty(s.qty)}`);
});
const NETTING = { net: { ar: 'يُخصم المحجوز من التوقع', en: 'Booked orders netted off the forecast' }, gross: { ar: 'التوقع والمحجوز معًا', en: 'Forecast plus booked orders' }, ignore: { ar: 'المحجوز متجاهَل', en: 'Booked orders ignored' } };

const histCols = [
  { key: 'label', header: { ar: 'الفترة', en: 'Period' }, width: 'minmax(90px,1fr)' },
  { key: 'from', header: { ar: 'من → إلى', en: 'From → to' }, width: '170px', kind: 'date', value: (r) => `${fmtDay(r.from)} → ${fmtDay(r.to)}` },
  { key: 'days', header: { ar: 'أيام', en: 'Days' }, width: '56px', kind: 'num' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '72px', kind: 'num', value: (r) => qty(r.qty) },
  { key: 'used', header: { ar: 'مستخدمة', en: 'Used' }, width: '72px', value: (r) => (r.used ? '✓' : '—') },
];
const perCols = computed(() => [
  { key: 'label', header: { ar: 'الفترة', en: 'Period' }, width: 'minmax(80px,1fr)' },
  { key: 'lastYear', header: { ar: 'العام الماضي', en: 'Last year' }, width: '64px', kind: 'num', value: (r) => qty(r.lastYear) },
  { key: 'forecast', header: { ar: 'التوقع', en: 'Forecast' }, width: '64px', kind: 'num', value: (r) => qty(r.forecast) },
  ...(isStock.value ? [
    { key: 'bookedIn', header: { ar: 'وارد محجوز', en: 'Booked in' }, width: '64px', kind: 'num', value: (r) => qty(r.bookedIn) },
    { key: 'bookedOut', header: { ar: 'صادر محجوز', en: 'Booked out' }, width: '64px', kind: 'num', value: (r) => qty(r.bookedOut) },
    { key: 'opening', header: { ar: 'رصيد البداية', en: 'Opening' }, width: '66px', kind: 'num', value: (r) => qty(r.opening) },
    { key: 'projected', header: { ar: 'الرصيد المتوقع', en: 'Projected' }, width: '70px', kind: 'num' },
    { key: 'shortage', header: { ar: 'عجز', en: 'Short' }, width: '58px', kind: 'num' },
    { key: 'surplus', header: { ar: 'فائض', en: 'Surplus' }, width: '58px', kind: 'num', value: (r) => qty(r.surplus) },
    { key: 'coverageDays', header: { ar: 'تغطية (يوم)', en: 'Cover (d)' }, width: '62px', kind: 'num', value: (r) => (r.coverageDays == null ? '—' : d1(r.coverageDays)) },
  ] : []),
]);
const reqCols = [
  { key: 'periodLabel', header: { ar: 'الفترة', en: 'Period' }, width: 'minmax(80px,1fr)' },
  { key: 'orderBy', header: { ar: 'اطلب قبل', en: 'Order by' }, width: '96px', kind: 'date' },
  { key: 'needDate', header: { ar: 'مطلوب في', en: 'Need' }, width: '84px', kind: 'date', value: (r) => fmtDay(r.needDate) },
  { key: 'demand', header: { ar: 'الطلب', en: 'Demand' }, width: '62px', kind: 'num', value: (r) => qty(r.demand) },
  { key: 'net', header: { ar: 'الصافي', en: 'Net' }, width: '62px', kind: 'num', value: (r) => qty(r.net) },
  { key: 'qty', header: { ar: 'بعد التقريب', en: 'Rounded' }, width: '70px', kind: 'num', value: (r) => qty(r.qty) },
  { key: 'value', header: { ar: 'القيمة', en: 'Value' }, width: '90px', kind: 'num', value: (r) => money(r.value) },
];
const patCols = [
  { key: 'customerName', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(130px,1.4fr)', value: (r) => r.customerName || '—' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '92px', bare: true },
  { key: 'orders', header: { ar: 'الطلبات', en: 'Orders' }, width: '56px', kind: 'num' },
  { key: 'avgInterval', header: { ar: 'كل (يوم)', en: 'Every (d)' }, width: '60px', kind: 'num', value: (r) => (r.avgInterval == null ? '—' : d1(r.avgInterval)) },
  { key: 'nextExpected', header: { ar: 'التالي', en: 'Next' }, width: '84px', kind: 'date', value: (r) => fmtDay(r.nextExpected) },
  { key: 'forecast', header: { ar: 'التوقع', en: 'Forecast' }, width: '62px', kind: 'num', value: (r) => qty(r.forecast) },
  { key: 'band', header: { ar: 'الثقة', en: 'Confidence' }, width: '80px', bare: true },
];
const custCols = [
  { key: 'customerName', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(130px,1.4fr)', value: (r) => r.customerName || r.customerCode || '—' },
  { key: 'methodUsed', header: { ar: 'الطريقة', en: 'Method' }, width: '120px', value: (r) => label(METHOD, r.methodUsed) },
  { key: 'historyTotal', header: { ar: 'السجل', en: 'History' }, width: '66px', kind: 'num', value: (r) => qty(r.historyTotal) },
  { key: 'forecast', header: { ar: 'التوقع', en: 'Forecast' }, width: '66px', kind: 'num', value: (r) => qty(r.forecast) },
];
</script>

<template>
  <Drawer :open="!!itemId" :width="900" :z-index="70" @close="emit('close')">
    <template #title><span dir="auto">{{ it ? nameOf(it) : t('البند', 'Item') }}</span></template>
    <template #sub>
      <span v-if="it"><span class="num ltr inline-block">{{ it.sku }}</span> · {{ it.warehouseCode || '—' }}<template v-if="it.customerName"> · <span dir="auto">{{ it.customerName }}</span></template></span>
    </template>
    <template v-if="it" #headExtra>
      <span class="inline-flex flex-wrap gap-1"><Chip :map="ITEM_STATUS" :k="it.status" /><Chip v-if="it.criticality" :map="CRITICALITY" :k="it.criticality" /></span>
    </template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-[260px]" />
    <template v-if="it">
      <!-- the answer -->
      <div class="hint purple !mt-0 mb-3 text-[12px] leading-[1.9]">{{ summary }}</div>
      <div v-if="isStock" class="mb-3 grid grid-cols-2 gap-2 md:grid-cols-5">
        <div class="tile green"><div class="tile-v">{{ qty(it.suggestedQty) }}</div><div class="tile-l">{{ t('الشراء المقترح', 'Suggested qty') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtDay(it.orderBy) }}</div><div class="tile-l">{{ t('اطلب قبل', 'Order by') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ money(it.suggestedValue) }}</div><div class="tile-l">{{ t('القيمة', 'Value') }}</div></div>
        <div class="tile red"><div class="tile-v">{{ fmtDay(it.firstShortage) }}</div><div class="tile-l">{{ t('أول عجز', 'First shortage') }} · <span class="num">{{ qty(it.shortage) }}</span></div></div>
        <div class="tile"><div class="tile-v">{{ it.coverageDays == null ? '—' : d1(it.coverageDays) }}</div><div class="tile-l">{{ t('أيام تغطية المتاح', 'Days covered by stock') }}</div></div>
      </div>

      <!-- method -->
      <div class="card mb-3 !p-3">
        <div class="card-title mb-1.5">{{ t('طريقة التوقع', 'Forecast method') }}</div>
        <div class="kv-grid">
          <KV :k="{ ar: 'المطلوبة', en: 'Asked' }" :v="label(METHOD, m.asked)" />
          <KV :k="{ ar: 'المستخدمة', en: 'Used' }" :v="label(METHOD, m.used || it.methodUsed)" />
          <KV :k="{ ar: 'الثقة (0–100)', en: 'Confidence (0–100)' }" :v="it.confidence == null ? '—' : d1(it.confidence)" ltr />
          <KV v-for="r in detailRows" :key="bi(r.k)" :k="r.k" :v="r.v" ltr />
        </div>
        <div v-if="m.reason" class="hint amber !mb-0 mt-2">
          <b>{{ m.fallback ? t('لماذا طريقة بديلة: ', 'Why a fallback: ') : t('ملاحظة: ', 'Note: ') }}</b>{{ t(m.reason.ar, m.reason.en) }}
        </div>
      </div>

      <!-- history -->
      <div class="card mb-3 !p-3">
        <div class="card-title mb-1.5">{{ t('السجل الذي بُني عليه', 'The history it was built on') }}</div>
        <div v-if="ex.history" class="kv-grid mb-2">
          <KV :k="{ ar: 'فترات السجل', en: 'History periods' }" :v="`${ex.history.used ?? 0} / ${ex.history.periods ?? 0}`" ltr />
          <KV :k="{ ar: 'إجمالي الطلب', en: 'Total demand' }" :v="qty(ex.history.total)" ltr />
          <KV :k="{ ar: 'متوسط الفترة', en: 'Mean per period' }" :v="d1(ex.history.mean)" ltr />
          <KV :k="{ ar: 'الانحراف المعياري', en: 'Std deviation' }" :v="d1(ex.history.stdev)" ltr />
          <KV :k="{ ar: 'المرتجعات', en: 'Returns' }" :v="`${qty(ex.history.returns)} ${ex.history.returnsNetted ? t('(مخصومة)', '(netted)') : t('(غير مخصومة)', '(not netted)')}`" />
          <KV :k="{ ar: 'من OPS / أودو', en: 'From OPS / Odoo' }" :v="`${qty(ex.history.from?.ops)} / ${qty(ex.history.from?.odoo)}`" ltr />
        </div>
        <DataTable :rows="it.history || []" :columns="histCols" dense :empty-text="{ ar: 'لا سجل.', en: 'No history.' }" :row-style="(r) => (r.used ? null : { opacity: 0.55 })" />
      </div>

      <!-- forecast + projection -->
      <div class="card mb-3 !p-3">
        <div class="card-title mb-1.5">{{ t('التوقع والرصيد المتوقع لكل فترة', 'Forecast and projected balance per period') }}</div>
        <div v-if="ex.forecast" class="kv-grid mb-2">
          <KV :k="{ ar: 'إجمالي التوقع', en: 'Forecast total' }" :v="qty(ex.forecast.total)" ltr />
          <KV :k="{ ar: 'الطلب اليومي', en: 'Per day' }" :v="d3(ex.forecast.perDay)" ltr />
          <KV :k="{ ar: 'أيام التوقع', en: 'Horizon days' }" :v="d1(ex.forecast.horizonDays)" ltr />
          <KV :k="{ ar: 'قبل بداية التوقع', en: 'Before the horizon' }" :v="`${qty(ex.forecast.beforeHorizon)} (${d1(ex.forecast.gapDays)} ${t('يوم', 'd')})`" ltr />
          <KV :k="{ ar: 'النمو', en: 'Growth' }" :v="`${d1(ex.forecast.growthPct)}%`" ltr />
        </div>
        <DataTable :rows="it.periods || []" :columns="perCols" dense compact :empty-text="{ ar: 'لا فترات.', en: 'No period.' }">
          <template #cell-projected="{ row }"><span class="num" :class="num(row.projected) < 0 ? 'font-extrabold text-bad' : ''">{{ qty(row.projected) }}</span></template>
          <template #cell-shortage="{ row }"><span class="num" :class="num(row.shortage) > 0 ? 'font-extrabold text-bad' : 'text-faint'">{{ qty(row.shortage) }}</span></template>
        </DataTable>
      </div>

      <template v-if="isStock">
        <div class="grid gap-3 md:grid-cols-2">
          <!-- stock -->
          <div v-if="ex.stock" class="card !p-3">
            <div class="card-title mb-1.5">{{ t('المخزون', 'Stock') }}</div>
            <div class="kv-grid">
              <KV :k="{ ar: 'المتاح الآن', en: 'On hand now' }" :v="qty(ex.stock.onHand)" ltr />
              <KV :k="{ ar: 'وارد محجوز', en: 'Booked in' }" :v="qty(ex.stock.bookedIn)" ltr />
              <KV :k="{ ar: 'صادر محجوز', en: 'Booked out' }" :v="qty(ex.stock.bookedOut)" ltr />
              <KV :k="{ ar: 'رصيد بداية التوقع', en: 'Opening balance' }" :v="qty(ex.stock.opening)" ltr />
              <KV :k="{ ar: 'المحجوز', en: 'Booked orders' }" :v="bi(NETTING[ex.stock.netting] || { ar: ex.stock.netting || '—', en: ex.stock.netting || '—' })" />
            </div>
            <div class="hint !mb-0 mt-2">{{ t('رصيد البداية = المتاح − الطلب المتوقع قبل بداية التوقع؛ ثم لكل فترة: الرصيد + الوارد − الطلب − الصادر.', 'Opening = on hand − demand expected before the horizon; then per period: balance + booked in − demand − booked out.') }}</div>
            <div v-if="(ex.stock.inDocs || []).length || (ex.stock.outDocs || []).length" class="mt-2 text-[10px]">
              <div v-for="d in ex.stock.inDocs || []" :key="`in-${d.ref}-${d.day}`" class="row"><span class="text-ok">+</span><span class="num ltr">{{ d.ref }}</span><div class="grow" /><span class="num">{{ fmtDay(d.day) }} · {{ qty(d.qty) }}</span></div>
              <div v-for="d in ex.stock.outDocs || []" :key="`out-${d.ref}-${d.day}`" class="row"><span class="text-bad">−</span><span class="num ltr">{{ d.ref }}</span><div class="grow" /><span class="num">{{ fmtDay(d.day) }} · {{ qty(d.qty) }}</span></div>
            </div>
          </div>
          <!-- safety -->
          <div v-if="ex.safety" class="card !p-3">
            <div class="card-title mb-1.5">{{ t('المخزون الآمن', 'Safety stock') }}</div>
            <div class="kv-grid">
              <KV :k="{ ar: 'الكمية', en: 'Quantity' }" :v="qty(ex.safety.qty)" ltr />
              <KV :k="{ ar: 'الطريقة', en: 'Method' }" :v="ex.safety.method === 'service_level' ? t('مستوى خدمة', 'Service level') : t('أيام طلب', 'Days of demand')" />
              <KV :k="{ ar: 'أيام التغطية المستهدفة', en: 'Target cover days' }" :v="d1(ex.coverDays)" ltr />
              <KV :k="{ ar: 'المستوى الأقصى', en: 'Max level' }" :v="qty(ex.maxLevel)" ltr />
            </div>
            <div class="hint !mb-0 mt-2">{{ safetyText }}</div>
          </div>
          <!-- lead -->
          <div v-if="ex.lead" class="card !p-3">
            <div class="card-title mb-1.5">{{ t('مدة التوريد والمورد', 'Lead time and vendor') }}</div>
            <div class="kv-grid">
              <KV :k="{ ar: 'مدة التوريد', en: 'Lead time' }" :v="`${d1(ex.lead.days)} ${t('يوم', 'd')}`" ltr />
              <KV :k="{ ar: 'مصدرها', en: 'Source' }" :v="bi(LEAD_SOURCE[ex.lead.source] || { ar: ex.lead.source || '—', en: ex.lead.source || '—' })" />
              <KV :k="{ ar: 'هامش التخطيط', en: 'Buffer' }" :v="`${d1(ex.lead.bufferDays)} ${t('يوم', 'd')}`" ltr />
              <KV :k="{ ar: 'المورد', en: 'Vendor' }" :v="ex.lead.vendor ? `${ex.lead.vendor.code || ''} ${ex.lead.vendor.name || ''}` : '—'" />
            </div>
            <div class="hint !mb-0 mt-2">{{ t('تاريخ الطلب = تاريخ الحاجة − مدة التوريد − الهامش.', 'Order date = need date − lead time − buffer.') }}</div>
          </div>
          <!-- price -->
          <div v-if="ex.price" class="card !p-3">
            <div class="card-title mb-1.5">{{ t('السعر', 'Price') }}</div>
            <div class="kv-grid">
              <KV :k="{ ar: 'سعر الوحدة', en: 'Unit price' }" :v="ex.price.price == null ? '—' : money(ex.price.price)" ltr />
              <KV :k="{ ar: 'المصدر', en: 'Source' }" :v="label(PRICE_SOURCE, ex.price.source)" />
              <KV v-if="ex.price.tierPrice != null" :k="{ ar: 'سعر الشريحة للكمية', en: 'Tier price for the qty' }" :v="money(ex.price.tierPrice)" ltr />
              <KV v-if="num(ex.price.quotedQty) > 1" :k="{ ar: 'سُعّر لكمية', en: 'Quoted for qty' }" :v="qty(ex.price.quotedQty)" ltr />
              <KV :k="{ ar: 'آخر سعر شراء', en: 'Last purchase price' }" :v="it.lastPurchasePrice == null ? '—' : money(it.lastPurchasePrice)" ltr />
            </div>
            <div v-if="ex.price.reason" class="hint !mb-0 mt-2">{{ t(ex.price.reason.ar, ex.price.reason.en) }}</div>
          </div>
          <!-- rounding -->
          <div v-if="ex.rounding" class="card !p-3">
            <div class="card-title mb-1.5">{{ t('التقريب', 'Rounding') }}</div>
            <div class="kv-grid">
              <KV :k="{ ar: 'أقل كمية طلب', en: 'Minimum order' }" :v="qty(ex.rounding.moq)" ltr />
              <KV :k="{ ar: 'المضاعف', en: 'Multiple' }" :v="qty(ex.rounding.multiple)" ltr />
              <KV :k="{ ar: 'مصدر المضاعف', en: 'Multiple from' }" :v="ex.rounding.multipleSource === 'pack' ? t('كرتون المنتج', 'Product pack') : t('وحدة واحدة', 'Single unit')" />
            </div>
            <div class="hint !mb-0 mt-2">{{ t('الكمية الصافية تُرفع إلى أقل كمية طلب ثم إلى أقرب مضاعف أعلى.', 'The net quantity is raised to the minimum order, then up to the next multiple.') }}</div>
          </div>
          <!-- min / max -->
          <div v-if="ex.minMax" class="card !p-3">
            <div class="card-title mb-1.5">{{ t('الحد الأدنى والأقصى (حد الطلب)', 'Reorder min / max') }}</div>
            <div class="kv-grid">
              <KV :k="{ ar: 'الأدنى المقترح', en: 'Suggested min' }" :v="qty(it.suggestedMin)" ltr />
              <KV :k="{ ar: 'الأقصى المقترح', en: 'Suggested max' }" :v="qty(it.suggestedMax)" ltr />
              <KV :k="{ ar: 'الحالي على المنتج', en: 'Current on product' }" :v="`${qty(it.currentMin)} / ${qty(it.currentMax)}`" ltr />
              <KV :k="{ ar: 'المقارنة', en: 'Comparison' }" :v="label(ORDERPOINT, it.orderpointState)" />
            </div>
            <div class="hint !mb-0 mt-2">{{ t(`الأدنى = المخزون الآمن (${qty(ex.minMax.safety)}) + طلب مدة التوريد والهامش (${qty(ex.minMax.leadDemand)}) · الأقصى = الأدنى + طلب أيام التغطية (${qty(ex.minMax.coverDemand)}).`, `Min = safety stock (${qty(ex.minMax.safety)}) + demand over lead time and buffer (${qty(ex.minMax.leadDemand)}) · Max = min + demand over the cover days (${qty(ex.minMax.coverDemand)}).`) }}</div>
            <div v-if="it.push" class="hint green !mb-0 mt-2">{{ t(`دُفع إلى المنتج في ${fmtDate(it.push.at)} بواسطة ${it.push.by}: ${it.push.oldMin ?? '—'}/${it.push.oldMax ?? '—'} ← ${it.push.newMin}/${it.push.newMax}`, `Pushed to the product on ${fmtDate(it.push.at)} by ${it.push.by}: ${it.push.oldMin ?? '—'}/${it.push.oldMax ?? '—'} → ${it.push.newMin}/${it.push.newMax}`) }}</div>
          </div>
        </div>

        <div class="card mb-3 mt-3 !p-3">
          <div class="card-title mb-1.5">{{ t('احتياجات الشراء لهذا البند', 'Purchase requirements of this item') }}</div>
          <DataTable :rows="it.requirements || []" :columns="reqCols" dense :empty-text="{ ar: 'لا احتياجات شراء.', en: 'No purchase requirement.' }">
            <template #cell-orderBy="{ row }"><span class="num" :class="row.late ? 'font-extrabold text-bad' : ''">{{ fmtDay(row.orderBy) }}</span></template>
          </DataTable>
        </div>
      </template>

      <div v-if="(it.patterns || []).length" class="card mb-3 !p-3">
        <div class="card-title mb-1.5">{{ t('أنماط طلبات العملاء', 'Customer reorder patterns') }}</div>
        <DataTable :rows="it.patterns" :columns="patCols" dense :page-size="20">
          <template #cell-state="{ row }"><Chip small :map="PATTERN_STATE" :k="row.state" /></template>
          <template #cell-band="{ row }"><Chip small :map="BAND" :k="row.band || 'none'" /></template>
        </DataTable>
      </div>
      <div v-if="(it.customers || []).length" class="card mb-3 !p-3">
        <div class="card-title mb-1.5">{{ t('حصص العملاء', 'Customer shares') }}</div>
        <DataTable :rows="it.customers" :columns="custCols" dense :page-size="20" />
      </div>
    </template>
  </Drawer>
</template>
