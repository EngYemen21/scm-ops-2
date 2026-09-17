<script setup>
// One stop of the driver's current trip: customer / contact / address / order, items toggle, then the action for its state:
// pending → "Arrived" (POST /delivery/stops/:id/arrive with the phone's GPS), arrived → "Deliver (POD)" / "Unable to deliver",
// closed → the POD summary or the fail reason. One POD per stop: a stop with a POD (or any outcome) offers no further action.
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Chip, ErrorBanner } from '@/components';
import { bi, fmtDate, fmtNum, fmtTime, lang, t } from '@/i18n';
import { FAIL_REASON_LABELS, STOP_LABELS } from '@/shared';
import { BIG, STOP_DRIVER_LABELS, captureGps, isClosedStop } from './driver';
import FailPanel from './FailPanel.vue';
import PodPanel from './PodPanel.vue';
import { PENDING, labelOf } from './tms';

const props = defineProps({
  stop: { type: Object, required: true },
  /** This is the next stop in sequence (blue frame). */
  isNext: { type: Boolean, default: false },
  /** The trip is on route and the user may execute deliveries. */
  onRoute: { type: Boolean, default: false },
});

const act = useAction();
/** 'pod' | 'fail' | null */
const panel = ref(null);
const showItems = ref(false);

const fo = computed(() => props.stop.fo || {});
const lines = computed(() => fo.value.lines || []);
const packages = computed(() => fo.value.packages || []);
const cartons = computed(() => packages.value.reduce((a, p) => a + (p.cartons || 0), 0) || fo.value.cartons);
const itemsQty = computed(() => lines.value.reduce((a, l) => a + (l.qty || 0), 0));
const closed = computed(() => isClosedStop(props.stop));
const stKey = computed(() => (props.stop.status === 'pending' && props.isNext ? 'next' : props.stop.status));
const canArrive = computed(() => props.onRoute && props.stop.status === 'pending');
const canPod = computed(() => props.onRoute && props.stop.status === 'arrived' && !props.stop.pod);
const highlight = computed(() => props.isNext && !closed.value);
const badge = computed(() => (closed.value && STOP_DRIVER_LABELS[props.stop.status]) || { bg: '#EFEAF8', fg: '#654e92' });

async function arrive() {
  const g = await captureGps();
  await act.run(() => api.postIdempotent(`/delivery/stops/${props.stop.id}/arrive`, { gps: g.gps }), { success: (r) => r?.message || t('سُجل الوصول', 'Arrival recorded'), invalidate: ['delivery', 'transport'] });
}
</script>

<template>
  <div class="rounded-2xl border-[1.5px] bg-white px-4 py-3.5" :class="highlight ? 'border-azure shadow-[0_4px_18px_rgba(60,121,245,.12)]' : 'border-line'">
    <div class="row !gap-2.5">
      <div class="num flex h-[30px] w-[30px] flex-none items-center justify-center rounded-full text-[12px] font-bold" :style="{ background: badge.bg, color: badge.fg }">{{ stop.seq }}</div>
      <div class="min-w-0 flex-1">
        <div class="text-[12px] font-extrabold">{{ (lang === 'en' && stop.customerEn) || stop.customerAr || stop.customer?.nameAr }}</div>
        <div class="num mt-px text-[8.5px] text-faint">{{ fo.number || '—' }}{{ stop.customer?.zone ? ` · ${stop.customer.zone}` : '' }}{{ cartons != null ? ` · ${fmtNum(cartons)} ${t('كرتون', 'ctn')}` : '' }} · ETA {{ stop.plannedTime || stop.window || bi(PENDING) }}</div>
      </div>
      <Chip small :map="STOP_DRIVER_LABELS" :k="stKey" />
    </div>

    <div class="mt-[9px] text-[10px] leading-[1.8] text-sec">
      <div><b class="text-faint">{{ t('جهة الاتصال', 'Contact') }}</b> <span dir="ltr">{{ stop.contact || stop.customer?.contact || '—' }}</span></div>
      <div><b class="text-faint">{{ t('العنوان', 'Address') }}</b> {{ stop.address || stop.customer?.address || '—' }}</div>
      <div>
        <b class="text-faint">{{ t('الطلب', 'Order') }}</b> <span class="num">{{ fo.number || '—' }}</span><span v-if="stop.invoice" class="num"> · {{ stop.invoice }}</span> ·
        <b class="text-faint">{{ t('الأصناف', 'Items') }}</b> <span class="num">{{ lines.length || fmtNum(stop.items) }}</span> (<span class="num">{{ fmtNum(itemsQty || stop.items) }}</span>) ·
        <b class="text-faint">{{ t('الطرود', 'Packages') }}</b> <span class="num">{{ packages.length || '—' }}</span>
      </div>
      <div v-if="stop.note" class="hint amber !mt-[5px] !px-[9px] !py-1 !text-[9.5px]">{{ stop.note }}</div>
      <button v-if="lines.length > 0" type="button" class="btn ghost sm mt-1 !px-2 !py-0.5" @click="showItems = !showItems">{{ showItems ? t('إخفاء الأصناف', 'Hide items') : t('عرض الأصناف', 'Show items') }}</button>
      <div v-if="showItems" class="mt-1 overflow-hidden rounded-[10px] border border-line-2">
        <div v-for="l in lines" :key="l.id || l.lineNo" class="row border-b border-[#F7F6FA] px-2.5 py-[5px] text-[10px]">
          <span class="flex-1 font-bold">{{ (lang === 'en' && l.product?.nameEn) || l.product?.nameAr || l.sku }}</span><span class="num muted">{{ l.product?.sku }}</span><span class="num font-bold">×{{ fmtNum(l.qty) }}</span>
        </div>
      </div>
    </div>

    <ErrorBanner :error="act.error.value" class="mt-2" @close="act.clearError()" />
    <button v-if="canArrive" type="button" :class="BIG" class="mt-[11px] h-11 w-full rounded-[11px] bg-azure text-[12.5px] text-white" :disabled="act.pending.value" @click="arrive">{{ act.pending.value ? t('جارٍ التسجيل…', 'Recording…') : t('وصلت', 'Arrived') }}</button>
    <div v-if="canPod && !panel" class="row mt-[11px]">
      <button type="button" :class="BIG" class="h-11 flex-1 rounded-[11px] bg-ok text-[12.5px] text-white" @click="panel = 'pod'">{{ t('تسليم POD', 'Deliver (POD)') }}</button>
      <button type="button" :class="BIG" class="h-11 rounded-[11px] border-[1.5px] border-[#F3C4C4] bg-white px-[15px] text-[11px] text-bad" @click="panel = 'fail'">{{ t('تعذر التسليم', 'Unable to deliver') }}</button>
    </div>
    <PodPanel v-if="panel === 'pod' && canPod" :stop="stop" :total-qty="itemsQty" @close="panel = null" />
    <FailPanel v-if="panel === 'fail' && canPod" :stop="stop" @close="panel = null" />

    <div v-if="stop.pod" class="hint green !mt-2.5 !px-[11px] !py-[7px] !text-[9.5px] !leading-[1.8]">
      <b>POD <span class="num">{{ stop.pod.number }}</span></b> · <Chip small :map="STOP_LABELS" :k="stop.pod.result" /> · <span class="num">{{ fmtDate(stop.pod.at) }}</span>
      <div>{{ t('المستلم', 'Receiver') }}: {{ stop.pod.receiverName || '—' }}{{ stop.pod.deliveredQty != null ? ` · ${t('مسلّم', 'delivered')} ${fmtNum(stop.pod.deliveredQty)}` : '' }}{{ stop.pod.returnedQty ? ` · ${t('مرتجع', 'returned')} ${fmtNum(stop.pod.returnedQty)}` : '' }}{{ stop.pod.failReason ? ` · ${t('السبب', 'reason')}: ${bi(labelOf(FAIL_REASON_LABELS, stop.pod.failReason))}` : '' }}</div>
      <div class="text-muted">{{ t('التوقيع / الصورة / GPS: مراجع محفوظة — الرفع Integration Pending', 'Signature / photo / GPS: references stored — upload Integration Pending') }}</div>
    </div>
    <div v-if="closed && !stop.pod && stop.failReason" class="mt-2 text-[9.5px] font-bold text-bad">{{ t('سبب الفشل', 'Fail reason') }}: {{ bi(labelOf(FAIL_REASON_LABELS, stop.failReason)) }}</div>
    <div v-if="stop.arrivedAt && !closed" class="num mt-1.5 text-[9px] text-brand-dark">{{ t('وصول', 'Arrived') }} {{ fmtTime(stop.arrivedAt) }}</div>
  </div>
</template>
