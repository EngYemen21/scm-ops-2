<script setup>
// Sales-order detail block — body of the Sales drawer and of the /so/:number deep link: header with state-dependent
// actions (allocate what is available / create fulfillment order / to consolidation / cancel …), stepper, key facts,
// what a waiting order still needs, the links between an order and its backorders, lines with ordered vs shipped and
// reserved / allocated / picked plus FEFO allocations per bin + batch, totals, traceability SO → FO → trip → POD →
// return, history.
//   <SoDetail :so="so" show-title @changed="refetch" />
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, KV, Stepper, Timeline } from '@/components';
import { fmtDate, fmtDateOnly, fmtMoney, fmtNum, lang, num, t } from '@/i18n';
import { FO_LABELS, OC_LABELS, RETURN_LABELS, SO_LABELS, TRIP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import TotalsBox from './TotalsBox.vue';
import { SO_CANCELLABLE, SO_STEPS, custName, historyItems, lineNetOf, prodName, soStepIndex } from './shared';

const props = defineProps({
  so: { type: Object, required: true },
  showTitle: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const router = useRouter();
const act = useAction();

const step = computed(() => soStepIndex(props.so.status));
const steps = SO_STEPS.map((s) => ({ label: { ar: SO_LABELS[s].ar, en: SO_LABELS[s].en } }));
// A plain cancel is for an order nothing was picked for. Once the picker started the goods go back to their bins first:
// that is the warehouse's put-back (Pick & Pack), so the button stays visible but disabled, with the reason.
const canCancel = computed(() => auth.can('so.cancel') && SO_CANCELLABLE.includes(props.so.status));
const cancelBlocked = computed(() => (auth.can('so.cancel') && ['picking', 'picked'].includes(props.so.status)
  ? t('بدأ التجهيز — يُعيد المستودع البضاعة إلى مواقعها من شاشة التجهيز والتعبئة (إرجاع للرف)، ثم يُلغى الأمر أو يعود للانتظار', 'Picking started — the warehouse puts the goods back from Pick & Pack first; the order is then cancelled or waits again')
  : null));
const history = computed(() => historyItems(props.so.history, SO_LABELS));
const enc = encodeURIComponent;

const waiting = computed(() => (props.so.status === 'confirmed' ? props.so.waiting || [] : []));
const hasLines = computed(() => (props.so.lines || []).some((l) => l.qty > 0));
const backorders = computed(() => props.so.backorders || []);
const pname = (x) => (lang.value === 'ar' ? x.nameAr : x.nameEn || x.nameAr) || x.sku;

async function run(path, success, body) {
  const r = await act.run(() => api.postIdempotent(path, body), { success, invalidate: ['sales', 'inventory', 'fulfillment', 'customers'] });
  if (r !== undefined) emit('changed');
}
const allocate = () => run(`/sales/orders/${props.so.number}/allocate`, (d) => (d?.allocation?.complete === false
  ? t(`حُجز ${d.allocation.gained} — الباقي ما زال بانتظار المخزون`, `${d.allocation.gained} reserved — the rest still waits for stock`)
  : t('تم التخصيص FEFO ✓', 'Allocated (FEFO) ✓')));
const fulfill = () => run(`/sales/orders/${props.so.number}/fulfill`, (d) => t(`أُنشئ أمر التنفيذ ${d?.number || ''}`, `Fulfillment order ${d?.number || ''} created`));
async function cancel() {
  const n = props.so.number;
  if (!(await confirm({ title: { ar: `إلغاء أمر البيع ${n}؟`, en: `Cancel ${n}?` }, sub: { ar: 'يُفرج عن الحجز ويُعاد احتساب التزام العميل — لا يمكن التراجع', en: 'Releases the reservation and recomputes what the customer owes — cannot be undone' } }))) return;
  await run(`/sales/orders/${n}/cancel`, { ar: `أُلغي ${n} — أُفرج عن الحجز`, en: `${n} cancelled` }, { reason: 'إلغاء من شاشة المبيعات' });
}

/** Stock state of a line: `{ l: label, c: colour }`. */
function lineStock(l) {
  const picked = l.pickedQty || 0; const alloc = l.allocatedQty || 0; const rsv = l.reservedQty || 0; const del = l.deliveredQty || 0;
  if (l.qty <= 0) return { l: t('لا يُشحن على هذا الأمر', 'Not shipped on this order'), c: '#a8a4b8' };
  if (del >= l.qty) return { l: t('مسلّم ✓', 'Delivered ✓'), c: '#1d7a3e' };
  if (del > 0) return { l: t(`مسلّم ${del} / ${l.qty}`, `Delivered ${del} / ${l.qty}`), c: '#b26a16' };
  if (picked >= l.qty) return { l: t('مصروف', 'Picked'), c: '#3C79F5' };
  if (picked > 0) return { l: t(`مصروف ${picked} / ${l.qty}`, `Picked ${picked} / ${l.qty}`), c: '#3C79F5' };
  if (alloc >= l.qty) return { l: t('مخصص FEFO ✓', 'Allocated FEFO ✓'), c: '#0d7f93' };
  if (rsv > 0) return { l: t(`محجوز ${rsv} / ${l.qty}`, `Reserved ${rsv} / ${l.qty}`), c: '#b26a16' };
  return { l: t('بانتظار المخزون', 'Waiting for stock'), c: '#b23b3b' };
}
const LINE_GRID = 'bgrid grid grid-cols-[minmax(180px,1.5fr)_110px_100px_110px_120px] gap-2';
const TRACE_ROW = 'row wrap !gap-2.5 rounded-[11px] border border-line-2 px-[13px] py-2';
</script>

<template>
  <div>
    <div class="row wrap !gap-2.5">
      <div v-if="showTitle" class="num-mixed text-[15px] font-extrabold">{{ so.number }}</div>
      <div class="text-[12px] font-extrabold">{{ custName(so.customer) }}</div>
      <Chip :map="SO_LABELS" :k="so.status" />
      <Chip v-if="so.priority === 'high'" :label="{ ar: 'أولوية عالية', en: 'High priority' }" fg="#b23b3b" bg="#fdecec" small />
      <Chip v-if="so.backorderOf" :label="{ ar: 'طلب معلّق', en: 'Backorder' }" fg="#b26a16" bg="#fbf0dd" small />
      <Chip v-if="so.hasShortage" :label="{ ar: 'فيه نقص', en: 'Short' }" fg="#b23b3b" bg="#fdecec" small />
      <div class="grow" />
      <Btn v-if="so.status === 'confirmed' && auth.can('so.allocate')" tone="primary" size="sm" :loading="act.pending.value" :disabled="!hasLines" :title="hasLines ? null : t('لا كمية باقية على هذا الأمر', 'Nothing is left on this order')" :label="{ ar: 'تخصيص المتاح FEFO', en: 'Allocate what is available' }" @click="allocate" />
      <Btn v-if="so.status === 'allocated' && auth.can('consol.manage')" tone="blue" size="sm" :loading="act.pending.value" :label="{ ar: 'إنشاء أمر تنفيذ وقائمة تجهيز', en: 'Create fulfillment order' }" @click="fulfill" />
      <Btn v-if="['confirmed', 'allocated'].includes(so.status) && !so.consolidation && auth.can('consol.manage')" tone="purple" size="sm" :label="{ ar: 'إلى تجميع الطلبات', en: 'To consolidation' }" @click="router.push({ path: '/consol', query: { pick: so.number } })" />
      <Btn v-if="so.consolidation" tone="softPurple" size="sm" :label="{ ar: `فتح دفعة التجميع ${so.consolidation.number}`, en: `Open batch ${so.consolidation.number}` }" @click="router.push({ path: '/consol', query: { q: so.consolidation.number } })" />
      <Btn v-if="so.quotation" tone="soft" size="sm" :label="{ ar: `فتح عرض السعر ${so.quotation.number}`, en: `Open quote ${so.quotation.number}` }" @click="router.push({ path: '/sales', query: { tab: 'qt', q: so.quotation.number } })" />
      <Btn v-if="['delivered', 'partial', 'completed'].includes(so.status) && auth.can('return.create')" tone="softAmber" size="sm" :label="{ ar: 'إنشاء مرتجع', en: 'Create return' }" @click="router.push({ path: '/returns', query: { so: so.number, customer: so.customer.code, fo: (so.fos || []).find((f) => ['delivered', 'partial'].includes(f.status))?.number } })" />
      <Btn v-if="canCancel" tone="dangerOutline" size="sm" :loading="act.pending.value" :label="{ ar: 'إلغاء الأمر', en: 'Cancel order' }" @click="cancel" />
      <Btn v-else-if="cancelBlocked" tone="dangerOutline" size="sm" disabled :title="cancelBlocked" :label="{ ar: 'إلغاء الأمر', en: 'Cancel order' }" />
    </div>
    <div v-if="cancelBlocked" class="mt-1.5 text-end text-[9.5px] text-muted">{{ t('الإلغاء غير متاح:', 'Cancel unavailable:') }} {{ cancelBlocked }}</div>
    <ErrorBanner class="mt-2.5" :error="act.error.value" @close="act.clearError()" />

    <!-- a waiting order: what it still needs and what the warehouse has right now -->
    <div v-if="so.status === 'confirmed'" class="banner amber mt-2.5"><div class="min-w-0 flex-1 font-normal">
      <div class="font-extrabold">{{ hasLines ? t('الأمر معلّق — ينتظر توفر المخزون', 'This order is waiting for stock') : t('لا كمية باقية على هذا الأمر — كل ما طُلب نُقل إلى طلب معلّق أو أُلغي. ألغِ الأمر.', 'Nothing is left on this order — everything it asked for moved to a backorder or was cancelled. Cancel it.') }}</div>
      <div v-for="w in waiting" :key="w.sku" class="mt-0.5">
        {{ pname(w) }} <span class="num ltr inline-block text-[9.5px]">{{ w.sku }}</span> — {{ t('ينقص', 'short by') }} <span class="num font-extrabold">{{ fmtNum(w.need) }}</span> · {{ t('المتاح الآن', 'available now') }} <span class="num font-extrabold" :class="w.available > 0 ? 'text-ok' : ''">{{ fmtNum(w.available) }}</span>
      </div>
      <div v-if="hasLines" class="mt-1 text-[9.5px]">{{ t('الطلبات المعلّقة يُحجز لها عند وصول المخزون، الأقدم أولًا (المهمة المجدولة scm:backorders-allocate) — أو اضغط «تخصيص المتاح» لحجز ما وصل الآن.', 'Waiting orders are reserved when stock arrives, oldest first (scheduled job scm:backorders-allocate) — or press "Allocate what is available" to take what is there now.') }}</div>
    </div></div>

    <div class="mt-3.5"><Stepper :steps="steps" :current="step.idx" :failed="step.failed" :max-width="760" /></div>

    <div class="kv-grid mt-3.5">
      <KV :k="{ ar: 'تاريخ الطلب', en: 'Order date' }" ltr><span class="num">{{ fmtDate(so.date) }}</span></KV>
      <KV :k="{ ar: 'التسليم', en: 'Delivery' }" ltr><span class="num">{{ fmtDateOnly(so.dueDate) }}{{ so.window ? ` · ${so.window}` : '' }}</span></KV>
      <KV :k="{ ar: 'المستودع', en: 'Warehouse' }" :v="`${so.warehouse.code} — ${so.warehouse.nameAr}`" />
      <KV :k="{ ar: 'الوزن / الحجم', en: 'Weight / volume' }" :v="`${fmtNum(so.kg)} ${t('كجم', 'kg')} · ${fmtNum(so.cbm, 1)} ${t('م³', 'm³')}`" />
      <KV :k="{ ar: 'عرض السعر', en: 'Quotation' }">
        <RouterLink v-if="so.quotation" :to="{ path: '/sales', query: { tab: 'qt', q: so.quotation.number } }" class="cell-id">{{ so.quotation.number }}</RouterLink>
        <template v-else>—</template>
      </KV>
      <KV :k="{ ar: 'دفعة التجميع', en: 'Consolidation' }">
        <span v-if="so.consolidation" class="row !gap-1.5"><RouterLink :to="{ path: '/consol', query: { q: so.consolidation.number } }" class="cell-id">{{ so.consolidation.number }}</RouterLink><Chip :map="OC_LABELS" :k="so.consolidation.status" small /></span>
        <template v-else>—</template>
      </KV>
      <KV :k="{ ar: 'شروط الدفع', en: 'Terms' }"><span class="text-violet">{{ so.customer.terms || '—' }}</span></KV>
      <KV :k="{ ar: 'جهة الاتصال', en: 'Contact' }" :v="`${so.customer.contact || '—'}${so.customer.zone ? ` · ${so.customer.zone}` : ''}`" />
      <KV :k="{ ar: 'أُنشئ بواسطة', en: 'Created by' }" :v="so.createdBy || '—'" />
      <KV v-if="so.discountApprovedBy" :k="{ ar: 'اعتمد الخصم', en: 'Discount approved by' }"><span class="text-ok">{{ so.discountApprovedBy }}</span></KV>
    </div>

    <!-- an order and its backorders: what the customer asked for lives on these documents together -->
    <div v-if="so.backorderOf || backorders.length" class="col mt-3 !gap-1.5">
      <div v-if="so.backorderOf" :class="TRACE_ROW" class="bg-[#FFFAF0]">
        <span class="text-[10px] font-extrabold text-warn">{{ t('طلب معلّق من الأمر', 'Backorder of') }}</span>
        <RouterLink :to="`/so/${enc(so.backorderOf.number)}`" class="cell-id">{{ so.backorderOf.number }}</RouterLink>
        <Chip :map="SO_LABELS" :k="so.backorderOf.status" small />
        <span class="muted text-[9.5px]">{{ t('يحمل ما لم يتوفر مخزونه عند ذلك الأمر — نفس العميل والأسعار وشروط التسليم', 'Carries what that order could not get from stock — same customer, prices and delivery terms') }}</span>
      </div>
      <div v-for="b in backorders" :key="b.number" :class="TRACE_ROW" class="bg-[#FFFAF0]">
        <span class="text-[10px] font-extrabold text-warn">{{ t('الباقي في الطلب المعلّق', 'The rest is on backorder') }}</span>
        <RouterLink :to="`/so/${enc(b.number)}`" class="cell-id">{{ b.number }}</RouterLink>
        <Chip :map="SO_LABELS" :k="b.status" small />
        <span v-if="b.status === 'confirmed'" class="muted text-[9.5px]">{{ b.fulfilStatus === 'partial' ? t('حُجز جزء منه — ينتظر الباقي', 'partly reserved — waiting for the rest') : t('ينتظر المخزون', 'waiting for stock') }}</span>
      </div>
    </div>

    <div class="mt-3.5 overflow-x-auto"><div class="min-w-[720px]">
      <div :class="LINE_GRID" class="rounded-[10px] bg-soft px-3.5 py-2 text-[9.5px] font-extrabold text-faint">
        <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('الكمية · المطلوب / المشحون', 'Qty · ordered / shipped') }}</div><div>{{ t('السعر', 'Price') }}</div><div>{{ t('الصافي', 'Net') }}</div><div>{{ t('حالة المخزون', 'Stock') }}</div>
      </div>
      <div v-for="l in so.lines" :key="l.id">
        <div :class="[LINE_GRID, l.allocations?.length ? '' : 'border-b border-line-2']" class="items-center px-3.5 py-[9px]">
          <div>
            <div class="text-[11px] font-extrabold">{{ prodName(l.product) }}</div>
            <div class="cell-sub"><RouterLink :to="`/product/${enc(l.product.sku)}`" class="!text-inherit">{{ l.product.sku }}</RouterLink>{{ l.product.storageClass ? ` · ${l.product.storageClass}` : '' }}</div>
          </div>
          <div class="num text-[11px]">
            {{ fmtNum(l.qty) }}
            <div v-if="l.orderedQty != null" class="cell-sub !text-bad">{{ t('طُلب', 'ordered') }} {{ l.orderedQty }} · {{ t('نقص', 'short') }} {{ l.shortQty }}</div>
            <div class="cell-sub">{{ t('محجوز', 'rsv') }} {{ l.reservedQty }} · {{ t('مخصص', 'alloc') }} {{ l.allocatedQty }} · {{ t('مصروف', 'picked') }} {{ l.pickedQty }}<template v-if="l.deliveredQty"> · {{ t('مسلّم', 'delivered') }} {{ l.deliveredQty }}</template></div>
          </div>
          <div class="num text-[11px]">{{ fmtMoney(l.price) }}<span v-if="num(l.discPct) > 0" class="text-[9px] text-warn"> −{{ l.discPct }}%</span></div>
          <div class="num text-[11.5px]">{{ fmtMoney(lineNetOf(l)) }}</div>
          <div class="text-[9.5px] font-extrabold" :style="{ color: lineStock(l).c }">{{ lineStock(l).l }}</div>
        </div>
        <div v-if="l.allocations?.length" class="border-b border-line-2 px-3.5 pb-[9px]">
          <div class="row wrap !gap-1.5">
            <span class="faint text-[8.5px] font-extrabold">{{ t('التخصيص FEFO:', 'FEFO allocation:') }}</span>
            <span v-for="a in l.allocations" :key="a.id" class="chip gap-1.5 bg-canvas text-sec">
              <span class="num text-violet">{{ a.bin?.code || '—' }}</span>
              <span v-if="a.batch" class="num text-brand-dark">{{ a.batch.batchNo }}{{ a.batch.expiryDate ? ` · ${fmtDateOnly(a.batch.expiryDate)}` : '' }}</span>
              <span class="num">{{ a.qty }}{{ a.pickedQty ? ` (${t('مصروف', 'picked')} ${a.pickedQty})` : '' }}</span>
            </span>
          </div>
        </div>
      </div>
    </div></div>
    <div class="mt-2.5 flex justify-end"><TotalsBox :totals="so.totals" wide currency /></div>

    <!-- traceability -->
    <div class="mt-4 text-[12px] font-extrabold">{{ t('التتبع: أمر بيع ← أمر تنفيذ ← رحلة ← إثبات تسليم ← مرتجع', 'Traceability: SO → FO → trip → POD → return') }}</div>
    <div class="col mt-2 !gap-1.5">
      <div v-if="(so.fos || []).length === 0" class="empty !p-2.5">{{ t('لا أوامر تنفيذ بعد', 'No fulfillment orders yet') }}</div>
      <div v-for="f in so.fos || []" :key="f.id" :class="TRACE_ROW">
        <RouterLink :to="`/fo/${enc(f.number)}`" class="cell-id min-w-[110px]">{{ f.number }}</RouterLink>
        <Chip :map="FO_LABELS" :k="f.status" />
        <template v-if="f.trip">
          <span class="faint text-[9px]">{{ t('الرحلة', 'Trip') }}</span>
          <RouterLink :to="`/trip/${enc(f.trip.number)}`" class="cell-id !text-brand-dark">{{ f.trip.number }}</RouterLink>
          <Chip :map="TRIP_LABELS" :k="f.trip.status" small />
        </template>
        <span v-else class="faint text-[9px]">{{ t('لا رحلة بعد', 'No trip yet') }}</span>
      </div>
      <div v-for="p in so.pods || []" :key="p.number" :class="TRACE_ROW" class="bg-[#FAFDFB]">
        <span class="cell-id min-w-[110px] !text-ok">{{ p.number }}</span>
        <span class="text-[10px] font-extrabold">{{ t('إثبات تسليم', 'POD') }} · {{ p.result }}</span>
        <span v-if="p.receiverName" class="muted text-[10px]">{{ p.receiverName }}</span>
        <span class="cell-date">{{ fmtDate(p.at) }}</span>
      </div>
      <div v-for="r in so.returns || []" :key="r.number" :class="TRACE_ROW">
        <RouterLink :to="`/rtn/${enc(r.number)}`" class="cell-id min-w-[110px] !text-bad">{{ r.number }}</RouterLink>
        <span class="text-[10px] font-extrabold">{{ t('مرتجع', 'Return') }}{{ r.type ? ` · ${r.type}` : '' }}</span>
        <Chip :map="RETURN_LABELS" :k="r.status" small />
      </div>
    </div>

    <div class="mt-4 text-[12px] font-extrabold">{{ t('سجل الحالة', 'Status history') }}</div>
    <div class="mt-1.5"><Timeline :items="history" :empty-text="{ ar: 'لا سجل', en: 'No history' }" /></div>
  </div>
</template>
