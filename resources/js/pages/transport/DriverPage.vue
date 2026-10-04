<script setup>
// Driver "My Trips" — prototype `pDrv`, mobile-first 430px column. GET /api/delivery/my-trips → { current, upcoming, gps }
// (polled every 30s). Start route / arrive / deliver (POD: receiver, signature, photo, phone GPS) / partial / fail; ops requests.
// ETA, live tracking and file upload are "Integration Pending" — shown as such, never simulated.
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Chip, ErrorBanner, PageHead } from '@/components';
import { bi, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { TRIP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { BIG } from './driver';
import DriverStopCard from './DriverStopCard.vue';
import DriverTrackingStatus from './DriverTrackingStatus.vue';
import OpsRequestForm from './OpsRequestForm.vue';
import { OPREQ_STATE_LABELS, OPREQ_TYPE_LABELS, drvName, labelOf, vehName } from './tms';

const auth = useAuth();
const act = useAction();
// Dispatch on behalf of a driver (phone dead, no account yet): /driver?driver=DRV-15 — only for users who manage trips.
// The server applies the same rule (DeliveryService::myTrips); every action is recorded under the signed-in user.
const route = useRoute();
const onBehalf = computed(() => (auth.can('trip.manage') && typeof route.query.driver === 'string' && route.query.driver ? route.query.driver : null));
const q = useGet('/delivery/my-trips', computed(() => (onBehalf.value ? { driver: onBehalf.value } : undefined)), { refetchInterval: 30_000 });
const opr = useList('/transport/ops-requests', { pageSize: 5 });
const oprOpen = ref(false);

const cur = computed(() => q.data.value?.current || null);
const upcoming = computed(() => q.data.value?.upcoming || []);
const stops = computed(() => cur.value?.stops || []);
const myRequests = computed(() => opr.data.value?.items || []);
const prog = computed(() => cur.value?.progress || { done: 0, total: stops.value.length });
const progPct = computed(() => (prog.value.total ? Math.round((prog.value.done / prog.value.total) * 100) : 0));
const nextSeq = computed(() => cur.value?.nextStop?.seq ?? stops.value.find((s) => s.status === 'pending' || s.status === 'arrived')?.seq);

const canExec = computed(() => auth.can('delivery.execute'));
const started = computed(() => !!cur.value && (cur.value.events || []).some((e) => e.label === 'START'));
const canStart = computed(() => canExec.value && !!cur.value && cur.value.status === 'onroute' && !started.value);
const onRoute = computed(() => !!cur.value && ['onroute', 'partial'].includes(cur.value.status));
const waitingDispatch = computed(() => !!cur.value && cur.value.status !== 'onroute' && !['partial', 'completed', 'returning'].includes(cur.value.status));
const notDriver = computed(() => q.error.value?.code === 'NOT_A_DRIVER');

const start = () => act.run(() => api.postIdempotent(`/delivery/trips/${encodeURIComponent(cur.value.number)}/start`), { success: (r) => r?.message || t('بدأت الرحلة — بالتوفيق', 'Trip started — drive safe'), invalidate: ['delivery', 'transport'] });

const userName = computed(() => (auth.user ? (lang.value === 'ar' ? auth.user.nameAr : auth.user.nameEn) : ''));
const sub = computed(() => `${userName.value} · ${t('واجهة السائق Mobile-first — يرى رحلاته فقط', 'Driver mobile-first view — own trips only')}`);
</script>

<template>
  <div class="mx-auto max-w-[430px]">
    <PageHead :title="{ ar: 'رحلاتي', en: 'My Trips' }" :sub="sub" />

    <button v-if="auth.can('opreq.create')" type="button" :class="BIG" class="mb-3 h-12 w-full gap-2 rounded-[14px] border-[1.5px] border-[#DCD2EE] bg-white text-[12.5px] text-violet" @click="oprOpen = true">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="#654e92" stroke-width="2.6" stroke-linecap="round" /></svg>{{ t('طلب تشغيلي', 'Operational request') }}
    </button>
    <div v-if="myRequests.length > 0" class="card mb-3">
      <div class="px-[15px] pb-[7px] pt-[11px] text-[12px] font-extrabold">{{ t('طلباتي التشغيلية', 'My operational requests') }}</div>
      <div v-for="r in myRequests" :key="r.number" class="row !gap-[9px] border-t border-line-2 px-[15px] py-[9px]">
        <div class="min-w-0 flex-1">
          <div class="text-[11px] font-extrabold">{{ bi(labelOf(OPREQ_TYPE_LABELS, r.type)) }} <span class="num text-[9px] text-faint">{{ r.number }}</span></div>
          <div class="ellipsis text-[9px] text-muted">{{ r.desc }}{{ Number(r.amount) > 0 ? ` · ${fmtNum(r.amount, 2)} ${t('ر.س', 'SAR')}` : '' }}</div>
        </div>
        <Chip small :map="OPREQ_STATE_LABELS" :k="r.status" />
      </div>
    </div>

    <div v-if="onBehalf" class="mb-3 rounded-[14px] border border-[#F0D9A8] bg-[#FFF8E8] px-3.5 py-2.5 text-[11px] font-bold leading-[1.8] text-[#8a5a00]">
      {{ t(`تسجيل بالنيابة عن السائق ${onBehalf} — كل إجراء يُسجَّل باسمك في سجل الرحلة وإثبات التسليم.`, `Acting on behalf of driver ${onBehalf} — every action is recorded under your name.`) }}
    </div>
    <ErrorBanner v-if="!notDriver" :error="q.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value && !q.data.value" class="skel h-[140px] !rounded-[20px]" />
    <div v-if="notDriver || (q.data.value && !cur)" class="rounded-[20px] border-[1.5px] border-dashed border-[#DCD2EE] bg-white px-[26px] py-[50px] text-center">
      <div class="text-[15px] font-extrabold">{{ notDriver ? t('الحساب غير مرتبط بسائق', 'Account is not linked to a driver') : t('لا رحلات نشطة', 'No active trips') }}</div>
      <div class="mt-1.5 text-[11px] leading-[2] text-muted">{{ notDriver ? q.error.value?.localized(lang) : t('ستظهر رحلتك هنا فور إسنادك من لوحة الإرسال.', 'Your trip appears here as soon as dispatch assigns you.') }}</div>
    </div>

    <template v-if="cur">
      <div class="rounded-[20px] px-5 py-[18px] text-white" style="background: linear-gradient(140deg, #1E2130, #2A2E42)">
        <div class="row !gap-[9px]">
          <div class="flex-1">
            <div class="row"><div class="num text-[14px] font-extrabold">{{ cur.number }}</div><Chip small :map="TRIP_LABELS" :k="cur.status" /></div>
            <div class="mt-1 text-[9.5px] text-[#8b90a5]">{{ (lang === 'en' && cur.routeEn) || cur.routeAr }} · {{ vehName(cur.vehicle) }} · {{ drvName(cur.driver, lang) }}</div>
          </div>
          <div class="num text-[13px] font-bold text-brand">{{ prog.done }}/{{ prog.total }}</div>
        </div>
        <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-night-3"><div class="h-full bg-brand" :style="{ width: progPct + '%' }" /></div>
        <DriverTrackingStatus v-if="!onBehalf" />
        <button v-if="canStart" type="button" :class="BIG" class="mt-[13px] h-[46px] w-full rounded-xl bg-brand text-[12.5px] text-[#0b2a30]" :disabled="act.pending.value" @click="start">{{ t('بدء الرحلة — Start Route', 'Start Route') }}</button>
        <div v-if="waitingDispatch" class="mt-2.5 text-[9.5px] text-[#8b90a5]">{{ t('بانتظار التحميل والإرسال من المستودع (Dispatch) قبل بدء الرحلة.', 'Waiting for warehouse loading & dispatch before the trip can start.') }}</div>
      </div>

      <div class="col mt-3 !gap-2.5">
        <DriverStopCard v-for="s in stops" :key="s.id" :stop="s" :is-next="s.seq === nextSeq" :on-route="onRoute && canExec" />
      </div>
      <div class="hint">{{ t('كل تسليم يُنشئ POD مرقّمًا بالوقت والموقع. التوقيع والصور تُحفظ كمراجع (رفع الملفات: Integration Pending). الفشل يعيد الطلب للمستودع كمرتجع توصيل.', 'Each delivery creates a numbered POD with time and location. Signatures and photos are stored as references (file upload: Integration Pending). A failed stop returns the order to the warehouse as a delivery return.') }}</div>
    </template>

    <div v-if="upcoming.length > 0" class="card mt-3">
      <div class="px-[15px] pb-[7px] pt-[11px] text-[12px] font-extrabold">{{ t('الرحلات القادمة', 'Upcoming trips') }}</div>
      <div v-for="u in upcoming" :key="u.number" class="row !gap-[9px] border-t border-line-2 px-[15px] py-[9px]">
        <div class="min-w-0 flex-1">
          <div class="num text-[11px] font-extrabold">{{ u.number }} <span class="text-[9.5px] font-bold text-muted">{{ fmtDateOnly(u.date) }} · {{ u.plannedStart }}</span></div>
          <div class="ellipsis text-[9px] text-muted">{{ (lang === 'en' && u.routeEn) || u.routeAr }} · {{ vehName(u.vehicle) }} · <span class="num">{{ u.progress?.total ?? u.stops?.length ?? 0 }}</span> {{ t('محطات', 'stops') }}</div>
        </div>
        <Chip small :map="TRIP_LABELS" :k="u.status" />
      </div>
    </div>
    <OpsRequestForm :open="oprOpen" :trip-number="cur?.number" :vehicle-code="cur?.vehicle?.code" @close="oprOpen = false" />
  </div>
</template>
