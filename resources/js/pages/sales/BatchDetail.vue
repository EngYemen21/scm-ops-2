<script setup>
// Consolidation batch detail card: header with the next-step action (advance), stepper, KPIs, orders of the batch
// (remove while open / consolidating), orders that can still be added, trip link and the batch timeline.
//   <BatchDetail :number="openOc" :pool="poolOrders" @close="…" @open-so="…" />
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, Stepper, Timeline } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, t } from '@/i18n';
import { OC_LABELS, SO_LABELS, TRIP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
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
const editable = computed(() => !!d.value && ['open', 'consolidating'].includes(d.value.status) && auth.can('consol.manage'));
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
  if (s === 'open') return t('ابدأ التجميع ثم «جاهز للتجهيز» — ينشئ أوامر تنفيذ وقوائم تجهيز لكل طلب (يتطلب أن تكون كل الطلبات مخصصة FEFO).', 'Start consolidating, then "Ready for picking" creates fulfillment orders and pick lists for every order (all orders must be allocated).');
  if (s === 'readypick') return t('أُنشئت أوامر التنفيذ — تابع التجهيز من شاشة التجهيز والتعبئة.', 'Fulfillment orders created — continue in Pick & Pack.');
  if (s === 'packed' || s === 'readydisp') return t('اجمع أوامر التنفيذ في رحلة واحدة (الرحلات → رحلة جديدة) ثم «إرسال».', 'Put all fulfillment orders on one trip (Trips → new trip), then dispatch.');
  return '';
});
const history = computed(() => historyItems(d.value?.history, OC_LABELS));

async function run(path, success, body) {
  const r = await act.run(() => api.postIdempotent(path, body), { success, invalidate: ['sales', 'fulfillment', 'transport'] });
  if (r) det.refetch();
  return r;
}
const advance = () => run(`/sales/consolidations/${d.value.number}/advance`, (x) => t(`${x.number} → ${OC_LABELS[x.status]?.ar || x.status}`, `${x.number} → ${OC_LABELS[x.status]?.en || x.status}`));
const add = (a) => run(`/sales/consolidations/${d.value.number}/add`, t(`أُضيف ${a.number} إلى ${d.value.number}`, `${a.number} added`), { orderNumber: a.number });
async function remove(r) {
  if (!(await confirm({ title: { ar: `إزالة ${r.number} من الدفعة؟`, en: `Remove ${r.number} from the batch?` }, tone: 'danger' }))) return;
  await run(`/sales/consolidations/${d.value.number}/remove`, t(`أُزيل ${r.number} من ${d.value.number}`, `${r.number} removed`), { orderNumber: r.number });
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
        <Btn v-if="next && auth.can('consol.manage')" tone="primary" size="sm" :loading="act.pending.value" :label="{ ar: next[0], en: next[1] }" @click="advance" />
        <button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
      </div>
      <ErrorBanner class="mt-2.5" :error="act.error.value" @close="act.clearError()" />

      <div class="mt-4"><Stepper :steps="steps" :current="stepIdx" :max-width="820" /></div>
      <div class="mt-3.5 grid grid-cols-[repeat(auto-fit,minmax(100px,1fr))] gap-2">
        <div v-for="[v, l] in kpis" :key="l" class="rounded-xl border border-line-2 bg-soft px-3 py-[9px] text-center">
          <div class="num text-[15px] text-violet">{{ v }}</div><div class="text-[8.5px] font-extrabold text-faint">{{ l }}</div>
        </div>
      </div>
      <div v-if="hint" class="hint teal">{{ hint }}</div>

      <div class="mt-4 text-[12px] font-extrabold">{{ t('أوامر البيع في الدفعة', 'Orders in this batch') }}</div>
      <div class="col mt-2 !gap-1.5">
        <div v-if="d.orders.length === 0" class="empty !p-2.5">{{ t('لا طلبات', 'No orders') }}</div>
        <div v-for="r in d.orders" :key="r.number" class="row wrap !gap-2.5 rounded-[11px] border border-line-2 px-[13px] py-[9px]">
          <span class="num min-w-[110px] cursor-pointer text-[10.5px] text-violet" @click="emit('open-so', r.number)">{{ r.number }}</span>
          <div class="grow !min-w-[140px]">
            <div class="text-[11px] font-extrabold">{{ custName(r.customer) }}</div>
            <div class="text-[8.5px] text-faint">{{ r.customer.zone || '—' }} · {{ fmtDateOnly(r.dueDate) }} · {{ fmtNum(r.kg) }} {{ t('كجم', 'kg') }}</div>
          </div>
          <RouterLink v-for="f in r.fos || []" :key="f.number" :to="`/fo/${encodeURIComponent(f.number)}`" class="num ltr text-[9.5px] !text-brand-dark">{{ f.number }}</RouterLink>
          <Chip :map="SO_LABELS" :k="r.status" small />
          <span v-if="editable && d.orders.length > 2" class="cursor-pointer text-[9px] font-extrabold text-bad" @click="remove(r)">{{ t('إزالة', 'Remove') }}</span>
        </div>
      </div>

      <template v-if="editable && addable.length > 0">
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
