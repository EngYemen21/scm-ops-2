<script setup>
// Transport Control Tower — prototype `pTT`: 8 KPIs, quick actions, map placeholder, active & delayed trips, driver ranking,
// alerts, upcoming departures, fleet utilisation. Data: GET /api/transport/tower (30s) + /api/transport/fleet/kpis (60s) +
// alerts (60s) + today's upcoming trips (60s) + active trips (30s). Follows the warehouse selector.
// No live map, no fabricated positions / ETAs / idle time: those show "Integration Pending".
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, KpiCard, KpiGrid, PageHead, SectionCard } from '@/components';
import { bi, fmtNum, lang, t } from '@/i18n';
import { TRIP_LABELS, VEHICLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import BreakdownForm from './BreakdownForm.vue';
import DriverDrawer from './DriverDrawer.vue';
import DriverForm from './DriverForm.vue';
import FuelForm from './FuelForm.vue';
import MaintenanceForm from './MaintenanceForm.vue';
import FleetMap from './FleetMap.vue';
import NewTripForm from './NewTripForm.vue';
import RouteForm from './RouteForm.vue';
import { ALERT_SEV_LABELS, DRIVER_STATE_LABELS, PENDING, TEMP_LABELS, delayLabel, drvName, entityKind, etaText, labelOf, safetyColor, todayIso, tripProgress } from './tms';
import TripRoom from './TripRoom.vue';
import VehicleDrawer from './VehicleDrawer.vue';
import VehicleForm from './VehicleForm.vue';

const sum = (m, keys) => keys.reduce((a, k) => a + (m?.[k] || 0), 0);
const RANK_GRID = 'bgrid s2 grid grid-cols-[30px_minmax(110px,1.4fr)_68px_60px_60px_60px_56px_88px] px-3';
const SEV_GROUPS = [['c', 'high'], ['w', 'med'], ['i', 'low']];
const DRIVER_STATES = ['available', 'onroute', 'off', 'blocked'];

const auth = useAuth();
const router = useRouter();
const wh = useWarehouse();
/** 'trip' | 'vehicle' | 'driver' | 'route' | 'breakdown' | 'maint' | 'fuel' | null */
const form = ref(null);
const trip = ref(null);
const driver = ref(null);
const vehicle = ref(null);

const tower = useGet('/transport/tower', () => ({ ...wh.whParams }), { refetchInterval: 30_000 });
const kpis = useGet('/transport/fleet/kpis', undefined, { refetchInterval: 60_000 });
const alerts = useList('/transport/alerts', { pageSize: 8 }, { refetchInterval: 60_000 });
const upcoming = useList('/transport/trips', () => ({ status: 'planned,vassigned,dassigned,loading,ready', date: todayIso(), ...wh.whParams, pageSize: 8 }), { refetchInterval: 60_000 });
const active = useList('/transport/trips', () => ({ status: 'dispatched,onroute,partial,completed,returning', ...wh.whParams, pageSize: 12 }), { refetchInterval: 30_000 });
const tw = computed(() => tower.data.value);
const k = computed(() => kpis.data.value);
const activeItems = computed(() => active.data.value?.items || []);
const alertItems = computed(() => alerts.data.value?.items || []);
const upcomingItems = computed(() => upcoming.data.value?.items || []);
const ranking = computed(() => (k.value?.driverRanking || []).slice(0, 10));
const tripStates = computed(() => (tw.value ? Object.keys(TRIP_LABELS).filter((s) => tw.value.tripsToday?.[s]) : []));
const vehicleStates = computed(() => (tw.value ? Object.keys(VEHICLE_LABELS).filter((s) => tw.value.vehicles?.[s]) : []));

const kpiDefs = computed(() => {
  const x = tw.value;
  if (!x) return [];
  const critical = sum(x.openAlerts, ['c', 'high']); const failed = sum(x.tripsToday, ['failed']);
  return [
    { v: x.activeTrips, l: { ar: 'رحلات نشطة', en: 'Active trips' }, c: '#3C79F5', go: () => router.push('/trips') },
    { v: x.lateCount, l: { ar: 'رحلات متأخرة', en: 'Delayed' }, c: x.lateCount ? '#b26a16' : '#1d7a3e', go: () => router.push('/trips') },
    { v: x.vehicles?.available || 0, l: { ar: 'مركبات متاحة', en: 'Vehicles available' }, c: '#1d7a3e', go: () => router.push('/fleet?tab=veh&f=available') },
    { v: x.drivers?.available || 0, l: { ar: 'سائقون متاحون', en: 'Drivers available' }, c: '#1d7a3e', go: () => router.push('/fleet?tab=drv&f=available') },
    { v: sum(x.vehicles, ['loading', 'ready']), l: { ar: 'تحت التحميل', en: 'Loading' }, c: '#654e92', go: () => router.push('/dispatch') },
    { v: critical, l: { ar: 'تنبيهات حرجة', en: 'Critical alerts' }, c: critical ? '#b23b3b' : undefined, go: () => router.push('/fleet?tab=alerts') },
    { v: failed, l: { ar: 'رحلات فاشلة اليوم', en: 'Failed trips today' }, c: failed ? '#b23b3b' : undefined, go: () => router.push('/trips') },
    { v: sum(x.tripsToday, ['planned', 'vassigned', 'dassigned', 'loading', 'ready']), l: { ar: 'انطلاقات قادمة', en: 'Upcoming departures' }, c: '#0d7f93', go: () => router.push('/dispatch') },
  ];
});
const quick = computed(() => [
  { l: { ar: '+ رحلة جديدة', en: '+ New trip' }, go: () => { form.value = 'trip'; }, show: auth.can('trip.manage') }, { l: { ar: '+ مركبة', en: '+ Vehicle' }, go: () => { form.value = 'vehicle'; }, show: auth.can('vehicle.manage') },
  { l: { ar: '+ سائق', en: '+ Driver' }, go: () => { form.value = 'driver'; }, show: auth.can('driver.manage') }, { l: { ar: '+ مسار', en: '+ Route' }, go: () => { form.value = 'route'; }, show: auth.can('trip.manage') },
  { l: { ar: 'إبلاغ عن عطل', en: 'Report breakdown' }, go: () => { form.value = 'breakdown'; }, show: auth.can('vehicle.state') }, { l: { ar: '+ صيانة', en: '+ Maintenance' }, go: () => { form.value = 'maint'; }, show: auth.can('maintenance.manage') },
  { l: { ar: 'تسجيل وقود', en: 'Record fuel' }, go: () => { form.value = 'fuel'; }, show: auth.can('fuel.manage') }, { l: { ar: 'فتح استثناء', en: 'Open exception' }, go: () => router.push('/tower?new=1'), show: auth.can('exception.manage') },
  { l: { ar: 'لوحة الإرسال', en: 'Dispatch board' }, go: () => router.push('/dispatch'), show: true },
].filter((x) => x.show));
const util = computed(() => {
  const x = tw.value; const f = k.value;
  if (!x || !f) return [];
  const busy = (f.vehicles?.onroute || 0) + sum(f.vehicles?.byState, ['loading', 'ready', 'assigned', 'reserved', 'returning']);
  return [
    { k: t('استخدام الأسطول', 'Fleet utilisation'), v: f.vehicles?.total ? `${Math.round((busy / f.vehicles.total) * 100)}%` : '—' },
    { k: t('متوسط استخدام الحمولة', 'Avg. capacity use'), v: x.capacityUtilisationPct != null ? `${x.capacityUtilisationPct}%` : '—' },
    { k: t('رحلات مقفلة (إجمالي)', 'Closed trips (total)'), v: fmtNum(f.trips?.closed) }, { k: t('كم مقطوعة (مقفلة)', 'Km driven (closed)'), v: fmtNum(f.trips?.kmTotal) },
    { k: t('توقف الصيانة (أوامر مفتوحة)', 'Maintenance downtime (open)'), v: `${fmtNum(f.maintenance?.open)} · ${fmtNum(f.maintenance?.openCost)} ${t('ر.س', 'SAR')}` },
    { k: t('وقود 30 يومًا', 'Fuel 30d'), v: `${fmtNum(f.fuel?.cost30d)} ${t('ر.س', 'SAR')} · ${fmtNum(f.fuel?.liters30d)} L` },
    { k: t('تكلفة / كم', 'Cost / km'), v: f.trips?.kmTotal ? `${fmtNum(f.fuel?.cost30d / f.trips.kmTotal, 2)} ${t('ر.س', 'SAR')}` : '—' },
    { k: t('وقت الخمول · كم فارغة', 'Idle time · empty km'), v: bi(PENDING) },
    { k: t('طلبات تشغيلية مفتوحة', 'Open ops requests'), v: fmtNum(x.openOpsRequests) },
  ];
});

function openAlert(a) {
  const kind = a.entityCode ? entityKind(a.entityType) : null;
  if (kind === 'vehicle') vehicle.value = a.entityCode; else if (kind === 'driver') driver.value = a.entityCode; else if (kind === 'trip') trip.value = a.entityCode; else router.push('/fleet?tab=alerts');
}
const sevChipLabel = (ks) => `${bi(ALERT_SEV_LABELS[ks[0]])} ${sum(tw.value?.openAlerts, ks)}`;
</script>

<template>
  <PageHead :sub="t('وضع النقل كاملًا في أقل من 10 ثوانٍ', 'The whole transport picture in under 10 seconds')">
    <Btn v-if="auth.can('trip.manage')" tone="primary" size="sm" :label="{ ar: '+ رحلة', en: '+ Trip' }" @click="form = 'trip'" />
    <Btn size="sm" tone="outline" :label="{ ar: 'الأسطول', en: 'Fleet' }" @click="router.push('/fleet')" />
  </PageHead>
  <ErrorBanner :error="tower.error.value" :closable="false" /><ErrorBanner :error="kpis.error.value" :closable="false" />
  <KpiGrid compact>
    <template v-if="tw"><KpiCard v-for="(d, i) in kpiDefs" :key="i" compact :value="d.v" :label="d.l" :color="d.c" clickable @click="d.go()" /></template>
    <template v-else><KpiCard v-for="i in 8" :key="i" compact loading :value="null" label="" /></template>
  </KpiGrid>
  <div class="row wrap my-3 !gap-1.5">
    <button v-for="(x, i) in quick" :key="i" type="button" class="pill" @click="x.go()">{{ bi(x.l) }}</button>
  </div>

  <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-3.5">
    <div class="col !gap-3.5">
      <SectionCard small :title="{ ar: 'خريطة الرحلات', en: 'Trips map' }"><FleetMap :height="280" :warehouse="wh.whParams.warehouse || null" @trip="trip = $event" @vehicle="vehicle = $event" /></SectionCard>

      <SectionCard small :padded="false" :title="{ ar: 'الرحلات النشطة والمتأخرة', en: 'Active & delayed trips' }" :count="active.data.value?.total">
        <div v-if="active.isLoading.value" class="skel m-3 h-[60px]" />
        <EmptyState v-else-if="activeItems.length === 0" :text="{ ar: 'لا رحلات نشطة الآن', en: 'No active trips' }" />
        <div v-for="x in activeItems" :key="x.number" class="row wrap cursor-pointer border-t border-line-2 px-3.5 py-[9px]" @click="trip = x.number">
          <span class="num min-w-[90px] text-[10.5px] font-bold text-violet">{{ x.number }}</span>
          <span class="num min-w-10 text-[10px]">{{ x.vehicle?.code || '—' }}</span>
          <span class="min-w-[90px] text-[10px] font-bold">{{ drvName(x.driver, lang) }}</span>
          <div class="row min-w-[120px] flex-1 !gap-1.5"><div class="progress !h-1.5 flex-1"><div :style="{ width: tripProgress(x).pct + '%', background: '#1BC4DB' }" /></div><span class="num text-[9px]">{{ tripProgress(x).done }}/{{ tripProgress(x).total }}</span></div>
          <span class="num text-[9px] text-brand-dark">ETA {{ etaText(x) }}</span>
          <span class="text-[9.5px] font-extrabold" :class="x.delayMin ? 'text-warn' : 'text-ok'">{{ delayLabel(x.delayMin, lang) }}</span>
          <Chip small :map="TRIP_LABELS" :k="x.status" />
        </div>
        <div v-if="tw && tw.lateTrips?.length > 0" class="border-t border-line-2 px-3.5 py-2 text-[9.5px] font-extrabold text-warn">
          {{ t('أكثر الرحلات تأخرًا', 'Most delayed') }}: <span v-for="x in tw.lateTrips.slice(0, 5)" :key="x.number" class="num me-2 cursor-pointer" @click="trip = x.number">{{ x.number }} (+{{ x.delayMin }})</span>
        </div>
      </SectionCard>

      <SectionCard small :padded="false" :title="{ ar: 'ترتيب السائقين', en: 'Driver ranking' }">
        <div class="overflow-x-auto"><div class="min-w-[556px]">
          <div :class="RANK_GRID" class="bg-soft text-[9px] font-extrabold text-faint">
            <div>#</div><div>{{ t('الاسم', 'Name') }}</div><div>{{ t('في الموعد', 'On-time') }}</div><div>{{ t('نجاح', 'Success') }}</div><div>{{ t('السلامة', 'Safety') }}</div><div>{{ t('التقييم', 'Rating') }}</div><div>{{ t('وقود', 'Fuel') }}</div><div>{{ t('الحالة', 'State') }}</div>
          </div>
          <div v-for="(d, i) in ranking" :key="d.code" :class="RANK_GRID" class="cursor-pointer items-center border-t border-line-2 text-[10.5px]" @click="driver = d.code">
            <div class="num muted">{{ i + 1 }}</div><div class="font-extrabold">{{ drvName(d, lang) }}</div>
            <div class="num font-bold text-brand-dark">{{ fmtNum(d.ontimePct) }}%</div><div class="num font-bold text-ok">{{ fmtNum(d.okPct) }}%</div>
            <div class="num font-bold" :style="{ color: safetyColor(d.safety) }">{{ fmtNum(d.safety) }}</div><div class="num">{{ fmtNum(d.rating, 1) }}</div><div class="num">{{ fmtNum(d.fuelScore) }}</div>
            <div><Chip small :map="DRIVER_STATE_LABELS" :k="d.blocked ? 'blocked' : d.state" /></div>
          </div>
          <div v-if="!k" class="skel m-3 h-[60px]" />
        </div></div>
      </SectionCard>
    </div>

    <div class="col !gap-3.5">
      <SectionCard small :padded="false" :title="{ ar: 'الرحلات اليوم حسب الحالة', en: 'Today’s trips by status' }" :count="tw?.tripsTodayTotal">
        <div class="row wrap !gap-1.5 px-3.5 py-2.5">
          <span v-for="s in tripStates" :key="s" class="row !gap-1"><Chip small :map="TRIP_LABELS" :k="s" /><b class="num text-[10.5px]">{{ tw.tripsToday[s] }}</b></span>
          <span v-if="tw && !tw.tripsTodayTotal" class="muted text-[10px]">{{ t('لا رحلات اليوم', 'No trips today') }}</span>
        </div>
        <div class="px-3.5 pb-2 pt-1 text-[9.5px] font-extrabold text-muted">{{ t('المركبات حسب الحالة', 'Vehicles by state') }}</div>
        <div class="row wrap !gap-1.5 px-3.5 pb-2.5">
          <span v-for="s in vehicleStates" :key="s" class="row cursor-pointer !gap-1" @click="router.push(`/fleet?tab=veh&f=${s}`)"><Chip small :map="VEHICLE_LABELS" :k="s" dot /><b class="num text-[10.5px]">{{ tw.vehicles[s] }}</b></span>
        </div>
        <div class="px-3.5 pb-2 pt-1 text-[9.5px] font-extrabold text-muted">{{ t('السائقون', 'Drivers') }}</div>
        <div class="row wrap !gap-1.5 px-3.5 pb-3">
          <template v-if="tw"><span v-for="s in DRIVER_STATES" :key="s" class="row !gap-1"><Chip small :map="DRIVER_STATE_LABELS" :k="s" /><b class="num text-[10.5px]">{{ tw.drivers?.[s] || 0 }}</b></span></template>
        </div>
      </SectionCard>

      <SectionCard small :padded="false" :title="{ ar: 'التنبيهات', en: 'Alerts' }">
        <template #actions>
          <div class="row !gap-1">
            <template v-if="tw"><Chip v-for="ks in SEV_GROUPS" :key="ks[0]" small :map="ALERT_SEV_LABELS" :k="ks[0]" :label="sevChipLabel(ks)" /></template>
            <Btn size="sm" tone="ghost" :label="{ ar: 'الكل', en: 'All' }" @click="router.push('/fleet?tab=alerts')" />
          </div>
        </template>
        <div v-if="alerts.isLoading.value" class="skel m-3 h-[60px]" />
        <EmptyState v-else-if="alertItems.length === 0" :text="{ ar: 'لا تنبيهات مفتوحة ✓', en: 'No open alerts ✓' }" />
        <div v-for="a in alertItems" :key="a.code" class="row cursor-pointer border-t border-line-2 px-3.5 py-2" @click="openAlert(a)">
          <span class="h-2 w-2 flex-none rounded-full" :style="{ background: ALERT_SEV_LABELS[a.severity]?.fg || '#a8a4b8' }" />
          <span class="flex-1 text-[10.5px]">{{ (lang === 'en' && a.textEn) || a.textAr }}</span>
          <span class="num muted text-[9px]">{{ a.entityCode || '' }}</span>
        </div>
      </SectionCard>

      <SectionCard small :padded="false" :title="{ ar: 'الانطلاقات القادمة', en: 'Upcoming departures' }" :count="upcoming.data.value?.total">
        <div v-if="upcoming.isLoading.value" class="skel m-3 h-[60px]" />
        <EmptyState v-else-if="upcomingItems.length === 0" :text="{ ar: 'لا انطلاقات مخططة اليوم', en: 'No departures planned today' }" />
        <div v-for="u in upcomingItems" :key="u.number" class="row wrap cursor-pointer border-t border-line-2 px-3.5 py-2" @click="trip = u.number">
          <span class="num text-[10.5px] font-bold text-violet">{{ u.number }}</span><span class="num text-[10px]">{{ u.plannedStart || '—' }}</span>
          <span class="text-[9.5px] font-extrabold text-violet">{{ bi(labelOf(TEMP_LABELS, u.tempNeed)) }}</span>
          <span class="flex-1 text-[10px] text-sec">{{ (lang === 'en' && u.routeEn) || u.routeAr }}</span>
          <span class="text-[9.5px] font-extrabold" :class="u.vehicle && u.driver ? 'text-ok' : 'text-warn'">{{ u.vehicle && u.driver ? `${u.vehicle.code} · ${drvName(u.driver, lang)}` : t('بانتظار الإسناد', 'Awaiting assignment') }}</span>
        </div>
      </SectionCard>

      <div class="card !bg-night px-[18px] py-3.5 text-white">
        <div class="mb-2 text-[12px] font-extrabold">{{ t('استخدام الأسطول', 'Fleet Utilization') }}</div>
        <div v-for="r in util" :key="r.k" class="row justify-between border-b border-night-3 py-1.5 text-[10.5px]"><span class="font-extrabold text-[#8b90a5]">{{ r.k }}</span><span class="num font-bold text-brand">{{ r.v }}</span></div>
        <div v-if="!util.length" class="skel h-20" />
      </div>
    </div>
  </div>

  <TripRoom :number="trip" @close="trip = null" />
  <DriverDrawer :code="driver" initial-tab="perf" @close="driver = null" @open-trip="trip = $event" />
  <VehicleDrawer :code="vehicle" @close="vehicle = null" @open-trip="trip = $event" />
  <NewTripForm :open="form === 'trip'" @close="form = null" @done="(x) => { if (x?.number) trip = x.number; }" />
  <VehicleForm :open="form === 'vehicle'" @close="form = null" />
  <DriverForm :open="form === 'driver'" @close="form = null" />
  <RouteForm :open="form === 'route'" @close="form = null" />
  <BreakdownForm :open="form === 'breakdown'" @close="form = null" />
  <MaintenanceForm :open="form === 'maint'" @close="form = null" />
  <FuelForm :open="form === 'fuel'" @close="form = null" />
</template>
