<script setup>
// Consolidation batch detail card: header with the next-step action (advance) and cancel, stepper, KPIs, orders of the
// batch, orders that can still be added, trip link and the batch timeline.
// What may be done comes from the server (`actions` on the batch): which orders may leave it (`removable`: any order
// before picking starts, and later the ones that went back to waiting or were cancelled), whether orders may join
// (`canAdd`) and whether the batch may be cancelled (`canCancel` + the reason when not). An order that still waits for
// stock is tried once at "ready for picking"; when it stays short the refusal names it and it can be removed.
//   <BatchDetail :number="openOc" :pool="poolOrders" @close="…" @open-so="…" />
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, Stepper, Timeline } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { OC_LABELS, SO_LABELS, TRIP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { OC_STEPS, custsOf, itemsOf, sumOf, zonesOf } from './consol';
import { custName, historyItems } from './shared';

const props = defineProps({
  number: { type: String, required: true },
  /** Confirmed / allocated orders not consolidated yet (candidates for "add"). */
  pool: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'open-so']);

const auth = useAuth();
const router = useRouter();
const act = useAction();
const det = useGet(() => `/sales/consolidations/${encodeURIComponent(props.number)}`);
const d = computed(() => det.data.value);

const steps = OC_STEPS.map((s) => ({ label: { ar: OC_LABELS[s].ar, en: OC_LABELS[s].en } }));
const stepIdx = computed(() => OC_STEPS.indexOf(d.value?.status));
/** `[ar, en]` label of the next-step button, from the status table. */
const next = computed(() => OC_LABELS[d.value?.status]?.act || null);
const manage = computed(() => auth.can('consol.manage'));
const actions = computed(() => d.value?.actions || { canAdd: false, removable: [], canCancel: false });
const canAdd = computed(() => manage.value && actions.value.canAdd);
const removable = (r) => manage.value && (actions.value.removable || []).includes(r.number);
const live = computed(() => (d.value?.orders || []).filter((o) => o.status !== 'cancelled'));
const cancelReason = computed(() => (actions.value.canCancel ? null : (lang.value === 'ar' ? actions.value.cancelReasonAr : actions.value.cancelReasonEn) || null));
const trip = computed(() => d.value?.trips?.[0] || null);
const addable = computed(() => (d.value ? props.pool.filter((o) => o.warehouse.code === d.value.warehouse.code && !d.value.orders.some((x) => x.number === o.number)) : []));
const kpis = computed(() => {
  const o = d.value?.orders || [];
  return [
    [o.length, t('طلبات', 'Orders')], [custsOf(o), t('عملاء', 'Customers')], [fmtNum(o.reduce((s, x) => s + itemsOf(x), 0)), t('قطعة', 'Items')],
    [fmtNum(sumOf(o, 'kg')), t('كجم', 'kg')], [fmtNum(sumOf(o, 'cbm'), 1), t('م³', 'm³')], [zonesOf(o).length, t('مناطق', 'Zones')],
  ];
});
const hint = computed(() => {
  const s = d.value?.status;
  if (live.value.length === 0 && d.value) return t('لا طلبات قائمة في هذه الدفعة — لا يمكن تقديمها. ألغِ الدفعة.', 'No live order is left in this batch — it cannot advance. Cancel the batch.');
  if (s === 'open' || s === 'consolidating') return t('«جاهز للتجهيز» ينشئ أوامر تنفيذ وقوائم تجهيز لكل طلب. الطلب الذي ما زال ينتظر المخزون يُحاوَل حجزه مرة واحدة عندها؛ إن بقي ناقصًا يُسمّى في الرسالة وتستطيع إزالته والمتابعة بدونه. الطلب الملغى يخرج من الدفعة تلقائيًا.', '"Ready for picking" creates fulfillment orders and pick lists for every order. An order that still waits for stock is tried once then; when it stays short the message names it and you can remove it and go on. A cancelled order leaves the batch by itself.');
  if (s === 'readypick') return t('أُنشئت أوامر التنفيذ — تابع التجهيز من شاشة التجهيز والتعبئة. قبل بدء التجهيز يمكن إخراج أي طلب من الدفعة ليُكمل وحده.', 'Fulfillment orders created — continue in Pick & Pack. Until picking starts any order may leave the batch and go on alone.');
  if (s === 'picking') return t('«اكتمال التعبئة» يتطلب تعبئة كل الطلبات. الطلب الذي أعاده المستودع للانتظار (إرجاع للرف) أو أُلغي يمكن إزالته لتُكمل الدفعة بدونه.', '"Packed" needs every order packed. An order the warehouse put back to waiting, or a cancelled one, can be removed so the batch goes on without it.');
  if (s === 'packed' || s === 'readydisp') return t('اجمع أوامر التنفيذ في رحلة واحدة (الرحلات → رحلة جديدة) ثم «إرسال».', 'Put all fulfillment orders on one trip (Trips → new trip), then dispatch.');
  if (s === 'dispatched') return t('«اكتمال التسليم» بعد إغلاق كل المحطات — التسليم الجزئي أو الفاشل يُعدّ منتهيًا.', '"Complete" once every stop is closed — a partial or failed delivery counts as finished.');
  return '';
});
const history = computed(() => historyItems(d.value?.history, OC_LABELS));
/** A note under an order that explains why it stands out in the batch. */
function orderNote(r) {
  if (r.status === 'cancelled') return { c: 'text-bad', l: t('ملغى — يخرج من الدفعة عند الخطوة التالية', 'Cancelled — leaves the batch at the next step') };
  if (r.status === 'confirmed') return { c: 'text-warn', l: r.fulfilStatus === 'partial' ? t('حجز جزئي — ينتظر باقي المخزون', 'Partly reserved — waiting for the rest') : t('ينتظر المخزون', 'Waiting for stock') };
  return null;
}

async function run(path, success, body) {
  const r = await act.run(() => api.postIdempotent(path, body), { success, invalidate: ['sales', 'fulfillment', 'transport'] });
  // a refused advance may still have changed the batch (cancelled orders leave it first), so it is re-read either way
  det.refetch();
  return r;
}
const advance = () => run(`/sales/consolidations/${d.value.number}/advance`, (x) => t(`${x.number} → ${OC_LABELS[x.status]?.ar || x.status}`, `${x.number} → ${OC_LABELS[x.status]?.en || x.status}`));
const add = (a) => run(`/sales/consolidations/${d.value.number}/add`, t(`أُضيف ${a.number} إلى ${d.value.number}`, `${a.number} added`), { orderNumber: a.number });
async function remove(r) {
  const last = d.value.orders.length <= 2;
  if (!(await confirm({
    title: { ar: `إزالة ${r.number} من الدفعة؟`, en: `Remove ${r.number} from the batch?` },
    sub: last ? { ar: 'ستبقى الدفعة بطلب واحد أو بلا طلبات — يمكن متابعتها أو إلغاؤها', en: 'The batch will be left with one order or none — it can go on or be cancelled' } : { ar: 'يعود الطلب إلى قائمة التجميع ويُكمل وحده', en: 'The order goes back to the pool and continues on its own' },
    tone: 'danger',
  }))) return;
  await run(`/sales/consolidations/${d.value.number}/orders/${encodeURIComponent(r.number)}/remove`, t(`أُزيل ${r.number} من ${d.value.number}`, `${r.number} removed`));
}
async function cancelBatch() {
  const n = d.value.number;
  const reason = await ask({
    title: { ar: `إلغاء دفعة التجميع ${n}؟`, en: `Cancel batch ${n}?` },
    sub: { ar: 'تعود طلباتها إلى قائمة التجميع كما هي (الحجز لا يتغيّر) وتُحذف الدفعة — يبقى أثرها في سجل التدقيق', en: 'Its orders go back to the pool untouched (reservations stay) and the batch is removed — its audit trail stays' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إلغاء الدفعة', en: 'Cancel batch' }, tone: 'danger',
  });
  if (reason === null) return;
  const r = await act.run(() => api.postIdempotent(`/sales/consolidations/${n}/cancel`, reason ? { reason } : undefined), {
    success: (x) => t(`أُلغيت الدفعة ${n}${x.released?.length ? ` — عادت ${x.released.length} طلبات لقائمة التجميع` : ''}`, `Batch ${n} cancelled`),
    invalidate: ['sales'],
  });
  if (r) emit('close'); else det.refetch();
}
</script>

<template>
  <div class="card selected mt-3.5 px-5 py-[18px]">
    <template v-if="det.error.value">
      <ErrorBanner :error="det.error.value" :closable="false" />
      <Btn tone="soft" size="sm" :label="{ ar: 'إغلاق', en: 'Close' }" @click="emit('close')" />
    </template>
    <div v-else-if="!d" class="skel h-[140px]" />
    <template v-else>
      <div class="row wrap !gap-2.5">
        <div class="num-mixed text-[15px] font-extrabold">{{ d.number }}</div>
        <Chip :map="OC_LABELS" :k="d.status" />
        <div class="text-[10px] text-muted">{{ d.warehouse.code }} · {{ d.rule || '—' }} · <span class="num ltr inline-block">{{ fmtDate(d.createdAt) }}</span>{{ d.createdBy ? ` · ${d.createdBy}` : '' }}</div>
        <div class="grow" />
        <Btn v-if="trip" tone="softBlue" size="sm" :label="{ ar: `فتح الرحلة ${trip.number}`, en: `Open trip ${trip.number}` }" @click="router.push(`/trip/${encodeURIComponent(trip.number)}`)" />
        <Chip v-if="trip" :map="TRIP_LABELS" :k="trip.status" small />
        <Btn v-if="next && manage" tone="primary" size="sm" :loading="act.pending.value" :disabled="live.length === 0" :title="live.length === 0 ? t('لا طلبات قائمة في الدفعة', 'No live order in the batch') : null" :label="{ ar: next[0], en: next[1] }" @click="advance" />
        <Btn v-if="manage && d.status !== 'done'" tone="dangerOutline" size="sm" :loading="act.pending.value" :disabled="!actions.canCancel" :title="cancelReason" :label="{ ar: 'إلغاء الدفعة', en: 'Cancel batch' }" @click="cancelBatch" />
        <button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
      </div>
      <div v-if="manage && d.status !== 'done' && cancelReason" class="mt-1.5 text-end text-[9.5px] text-muted">{{ t('إلغاء الدفعة غير متاح:', 'Cancel unavailable:') }} {{ cancelReason }}</div>
      <ErrorBanner class="mt-2.5" :error="act.error.value" @close="act.clearError()" />

      <div class="mt-4"><Stepper :steps="steps" :current="stepIdx" :max-width="820" /></div>
      <div class="mt-3.5 grid grid-cols-[repeat(auto-fit,minmax(100px,1fr))] gap-2">
        <div v-for="[v, l] in kpis" :key="l" class="rounded-xl border border-line-2 bg-soft px-3 py-[9px] text-center">
          <div class="num text-[15px] text-violet">{{ v }}</div><div class="text-[8.5px] font-extrabold text-faint">{{ l }}</div>
        </div>
      </div>
      <div v-if="hint" class="hint" :class="live.length === 0 ? 'amber' : 'teal'">{{ hint }}</div>

      <div class="mt-4 text-[12px] font-extrabold">{{ t('أوامر البيع في الدفعة', 'Orders in this batch') }}</div>
      <div class="col mt-2 !gap-1.5">
        <div v-if="d.orders.length === 0" class="empty !p-2.5">{{ t('لا طلبات', 'No orders') }}</div>
        <div v-for="r in d.orders" :key="r.number" class="row wrap !gap-2.5 rounded-[11px] border border-line-2 px-[13px] py-[9px]">
          <span class="num min-w-[110px] cursor-pointer text-[10.5px] text-violet" @click="emit('open-so', r.number)">{{ r.number }}</span>
          <div class="grow !min-w-[140px]">
            <div class="text-[11px] font-extrabold">{{ custName(r.customer) }}</div>
            <div class="text-[8.5px] text-faint">{{ r.customer.zone || '—' }} · {{ fmtDateOnly(r.dueDate) }} · {{ fmtNum(r.kg) }} {{ t('كجم', 'kg') }}</div>
            <div v-if="orderNote(r)" class="text-[8.5px] font-extrabold" :class="orderNote(r).c">{{ orderNote(r).l }}</div>
          </div>
          <RouterLink v-for="f in r.fos || []" :key="f.number" :to="`/fo/${encodeURIComponent(f.number)}`" class="num ltr text-[9.5px] !text-brand-dark" :class="{ 'line-through opacity-60': f.status === 'cancelled' }">{{ f.number }}</RouterLink>
          <Chip :map="SO_LABELS" :k="r.status" small />
          <Btn v-if="removable(r)" tone="softRed" size="sm" :loading="act.pending.value" :label="{ ar: 'إزالة', en: 'Remove' }" @click="remove(r)" />
        </div>
      </div>

      <template v-if="canAdd && addable.length > 0">
        <div class="mt-3 text-[10.5px] font-extrabold text-muted">{{ t('طلبات يمكن إضافتها', 'Orders that can be added') }}</div>
        <div class="row wrap mt-1.5 !gap-1.5">
          <div v-for="a in addable" :key="a.number" class="flex h-[30px] cursor-pointer items-center gap-[5px] rounded-full bg-canvas px-[11px] text-[9.5px] font-extrabold text-sec" @click="add(a)">
            <span class="text-brand-dark">{{ t('+ إضافة', '+ Add') }}</span> <span class="num ltr">{{ a.number }}</span> · {{ custName(a.customer) }}
          </div>
        </div>
      </template>

      <div class="mt-4 text-[12px] font-extrabold">{{ t('سجل الدفعة', 'Batch timeline') }}</div>
      <div class="mt-1.5"><Timeline :items="history" :empty-text="{ ar: 'لا سجل', en: 'No history' }" /></div>
    </template>
  </div>
</template>
