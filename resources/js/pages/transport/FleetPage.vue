<script setup>
// Fleet & Drivers — prototype `pFleet`: KPI strip (GET /api/transport/fleet/kpis, polled every 60s), live-map placeholder,
// tabs vehicles · drivers · maintenance & fuel · alert center · ops requests · routes (one component per tab).
// Deep links: /fleet?tab=veh|drv|maint|alerts|opreq|routes & f=<filter> & q=<search>.
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, KpiCard, KpiGrid, PageHead, SectionCard, Tabs } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import BreakdownForm from './BreakdownForm.vue';
import DriverDrawer from './DriverDrawer.vue';
import DriverForm from './DriverForm.vue';
import FleetAlertsTab from './FleetAlertsTab.vue';
import FleetDriversTab from './FleetDriversTab.vue';
import FleetMaintFuelTab from './FleetMaintFuelTab.vue';
import FleetOpsRequestsTab from './FleetOpsRequestsTab.vue';
import FleetRoutesTab from './FleetRoutesTab.vue';
import FleetVehiclesTab from './FleetVehiclesTab.vue';
import FuelForm from './FuelForm.vue';
import IncidentForm from './IncidentForm.vue';
import MaintenanceForm from './MaintenanceForm.vue';
import MapPlaceholder from './MapPlaceholder.vue';
import NewTripForm from './NewTripForm.vue';
import OpsRequestForm from './OpsRequestForm.vue';
import RouteForm from './RouteForm.vue';
import TripRoom from './TripRoom.vue';
import VehicleDrawer from './VehicleDrawer.vue';
import VehicleForm from './VehicleForm.vue';

const TAB_KEYS = ['veh', 'drv', 'maint', 'alerts', 'opreq', 'routes'];

const auth = useAuth();
const route = useRoute();
const router = useRouter();

// ---- tab / filter live in the query string ----
const qs = (k) => (typeof route.query[k] === 'string' ? route.query[k] : '');
const tab = computed(() => (TAB_KEYS.includes(qs('tab')) ? qs('tab') : 'veh'));
const filter = computed(() => qs('f'));
const initialQ = computed(() => qs('q'));
function setTab(k, f) {
  const query = { ...route.query, tab: k };
  if (f) query.f = f; else delete query.f;
  router.replace({ query });
}

/** 'trip' | 'vehicle' | 'driver' | 'maint' | 'fuel' | 'breakdown' | 'incident' | 'opreq' | 'route' | null */
const form = ref(null);
const vehicle = ref(null);
const driver = ref(null);
const trip = ref(null);
const editRoute = ref(null);

const kpis = useGet('/transport/fleet/kpis', undefined, { refetchInterval: 60_000 });
const k = computed(() => kpis.data.value);

const kpiDefs = computed(() => {
  const x = k.value;
  if (!x) return [];
  const by = x.vehicles.byState || {};
  return [
    { v: x.vehicles.total, l: { ar: 'إجمالي المركبات', en: 'Total vehicles' }, go: () => setTab('veh') },
    { v: x.vehicles.available, l: { ar: 'متاحة', en: 'Available' }, c: '#1d7a3e', go: () => setTab('veh', 'available') },
    { v: x.vehicles.onroute, l: { ar: 'في رحلة', en: 'In transit' }, c: '#3C79F5', go: () => setTab('veh', 'onroute') },
    { v: (by.loading || 0) + (by.ready || 0), l: { ar: 'تحت التحميل', en: 'Loading' }, c: '#654e92', go: () => setTab('veh', 'loading,ready') },
    { v: x.vehicles.maintenance, l: { ar: 'صيانة / عطل', en: 'Maintenance' }, c: '#b23b3b', go: () => setTab('veh', 'maintenance,breakdown') },
    { v: (by.oos || 0) + (by.inactive || 0), l: { ar: 'خارج الخدمة', en: 'Out of service' }, c: '#7d7990', go: () => setTab('veh', 'oos,inactive') },
    { v: x.vehicles.docsExpiring, l: { ar: 'وثائق مركبات تنتهي', en: 'Vehicle docs expiring' }, c: x.vehicles.docsExpiring ? '#b26a16' : undefined, go: () => setTab('alerts', 'vehdoc') },
    { v: x.drivers.available, l: { ar: 'سائقون متاحون', en: 'Drivers available' }, c: '#1d7a3e', go: () => setTab('drv', 'available') },
    { v: x.drivers.onroute, l: { ar: 'سائقون في رحلات', en: 'Drivers on trips' }, c: '#3C79F5', go: () => setTab('drv', 'onroute') },
    { v: x.drivers.blocked, l: { ar: 'سائقون موقوفون', en: 'Drivers blocked' }, c: x.drivers.blocked ? '#b23b3b' : undefined, go: () => setTab('drv', 'blocked') },
    { v: x.drivers.docsExpiring, l: { ar: 'وثائق سائقين تنتهي', en: 'Driver docs expiring' }, c: x.drivers.docsExpiring ? '#b26a16' : undefined, go: () => setTab('alerts', 'driverdoc') },
    { v: x.maintenance.open, l: { ar: 'أوامر صيانة مفتوحة', en: 'Open maintenance' }, c: '#b26a16', sub: `${fmtNum(x.maintenance.openCost)} ${t('ر.س', 'SAR')}`, go: () => setTab('maint') },
    { v: x.fuel.anomalies, l: { ar: 'انحرافات وقود', en: 'Fuel anomalies' }, c: x.fuel.anomalies ? '#b23b3b' : undefined, go: () => setTab('maint', 'anomaly') },
    { v: fmtNum(x.fuel.cost30d), l: { ar: 'تكلفة الوقود 30 يومًا ر.س', en: 'Fuel cost 30d SAR' }, sub: `${fmtNum(x.fuel.liters30d)} L`, go: () => setTab('maint') },
    { v: fmtNum(x.trips.kmTotal), l: { ar: 'كم الرحلات المقفلة', en: 'Closed-trip km' }, sub: `${fmtNum(x.trips.closed)} ${t('رحلة', 'trips')}` },
  ];
});

const tabs = computed(() => [
  { k: 'veh', label: { ar: 'المركبات', en: 'Vehicles' }, badge: k.value?.vehicles.total }, { k: 'drv', label: { ar: 'السائقون', en: 'Drivers' }, badge: k.value?.drivers.total },
  { k: 'maint', label: { ar: 'الصيانة والوقود', en: 'Maintenance & Fuel' }, badge: k.value?.maintenance.open }, { k: 'alerts', label: { ar: 'مركز التنبيهات', en: 'Alert Center' } },
  { k: 'opreq', label: { ar: 'الطلبات التشغيلية', en: 'Ops requests' } }, { k: 'routes', label: { ar: 'المسارات', en: 'Routes' } },
]);
const LEGEND = [['#1d7a3e', { ar: 'متاحة', en: 'Available' }], ['#3C79F5', { ar: 'في الطريق', en: 'On route' }], ['#654e92', { ar: 'تحميل', en: 'Loading' }], ['#b26a16', { ar: 'متأخرة', en: 'Delayed' }], ['#b23b3b', { ar: 'عطل / حرج', en: 'Breakdown / critical' }], ['#a8a4b8', { ar: 'غير متصلة', en: 'Offline' }]];

function newRoute() { editRoute.value = null; form.value = 'route'; }
function editRouteRow(r) { editRoute.value = r; form.value = 'route'; }
</script>

<template>
  <PageHead :sub="t('Fleet Control Center — الأسطول والسائقون والرحلات والتنبيهات في شاشة واحدة', 'Fleet Control Center — fleet, drivers, trips and alerts in one screen')">
    <Btn v-if="auth.can('trip.manage')" tone="primary" size="sm" :label="{ ar: '+ رحلة', en: '+ Trip' }" @click="form = 'trip'" />
    <Btn v-if="auth.can('vehicle.manage')" size="sm" :label="{ ar: '+ مركبة', en: '+ Vehicle' }" @click="form = 'vehicle'" />
    <Btn v-if="auth.can('driver.manage')" size="sm" :label="{ ar: '+ سائق', en: '+ Driver' }" @click="form = 'driver'" />
    <Btn v-if="auth.can('maintenance.manage')" size="sm" tone="outline" :label="{ ar: '+ صيانة', en: '+ Maintenance' }" @click="form = 'maint'" />
    <Btn v-if="auth.can('fuel.manage')" size="sm" tone="outline" :label="{ ar: 'وقود', en: 'Fuel' }" @click="form = 'fuel'" />
    <Btn v-if="auth.can('vehicle.state')" size="sm" tone="dangerOutline" :label="{ ar: 'عطل', en: 'Breakdown' }" @click="form = 'breakdown'" />
    <Btn v-if="auth.can('driver.manage')" size="sm" tone="dangerOutline" :label="{ ar: 'حادثة', en: 'Incident' }" @click="form = 'incident'" />
  </PageHead>

  <ErrorBanner :error="kpis.error.value" :closable="false" />
  <KpiGrid compact>
    <template v-if="k">
      <KpiCard v-for="(d, i) in kpiDefs" :key="i" compact :value="d.v" :label="d.l" :color="d.c" :sub="d.sub" :clickable="!!d.go" @click="d.go && d.go()" />
    </template>
    <template v-else>
      <KpiCard v-for="i in 8" :key="i" compact loading :value="null" label="" />
    </template>
  </KpiGrid>

  <SectionCard small class="mt-3" :title="{ ar: 'الخريطة الحية — مواقع المركبات لحظيًا', en: 'Live map — real-time vehicle positions' }">
    <template #actions>
      <div class="row wrap !gap-2.5 text-[9px] font-extrabold text-muted">
        <span v-for="[c, l] in LEGEND" :key="c" class="row !gap-1"><span class="inline-block h-2 w-2 rounded-full" :style="{ background: c }" />{{ t(l.ar, l.en) }}</span>
      </div>
    </template>
    <MapPlaceholder :height="200" />
  </SectionCard>

  <div class="row wrap mb-2.5 mt-3.5">
    <Tabs :model-value="tab" class="!mb-0" :tabs="tabs" @update:model-value="(x) => setTab(x)" />
    <button v-if="filter" type="button" class="pill purple active" @click="setTab(tab)">{{ t('مفلتر', 'Filtered') }}: <span class="num">{{ filter }}</span> · {{ t('إزالة الفلتر', 'Clear') }}</button>
  </div>

  <!-- keyed by the filter so a KPI click re-seeds the tab's own filter state -->
  <FleetVehiclesTab v-if="tab === 'veh'" :key="`veh-${filter}`" :filter="filter" :initial-q="initialQ" @open="vehicle = $event" />
  <FleetDriversTab v-else-if="tab === 'drv'" :key="`drv-${filter}`" :filter="filter" :initial-q="initialQ" @open="driver = $event" />
  <FleetMaintFuelTab v-else-if="tab === 'maint'" :key="`maint-${filter}`" :filter="filter" @open-vehicle="vehicle = $event" @new-maint="form = 'maint'" @new-fuel="form = 'fuel'" />
  <FleetAlertsTab v-else-if="tab === 'alerts'" :key="`alerts-${filter}`" :filter="filter" @open-vehicle="vehicle = $event" @open-driver="driver = $event" @open-trip="trip = $event" />
  <FleetOpsRequestsTab v-else-if="tab === 'opreq'" @new="form = 'opreq'" @open-trip="trip = $event" @open-vehicle="vehicle = $event" />
  <FleetRoutesTab v-else-if="tab === 'routes'" @new="newRoute" @edit="editRouteRow" />

  <div class="hint teal !mt-3">{{ t('الموقع الحي، GPS وحرارة الصندوق بحالة Integration Pending (Telematics). حالات المركبات تتغير عبر State Machine فقط والوثائق المنتهية تمنع الإسناد.', 'Live position, GPS and box temperature are Integration Pending (telematics). Vehicle states change only via the state machine; expired documents block assignment.') }}</div>

  <VehicleDrawer :code="vehicle" @close="vehicle = null" @open-trip="trip = $event" />
  <DriverDrawer :code="driver" @close="driver = null" @open-trip="trip = $event" />
  <TripRoom :number="trip" @close="trip = null" />
  <NewTripForm :open="form === 'trip'" @close="form = null" @done="(x) => { if (x?.number) trip = x.number; }" />
  <VehicleForm :open="form === 'vehicle'" @close="form = null" />
  <DriverForm :open="form === 'driver'" @close="form = null" />
  <MaintenanceForm :open="form === 'maint'" @close="form = null" />
  <FuelForm :open="form === 'fuel'" @close="form = null" />
  <BreakdownForm :open="form === 'breakdown'" @close="form = null" />
  <IncidentForm :open="form === 'incident'" @close="form = null" />
  <OpsRequestForm :open="form === 'opreq'" @close="form = null" />
  <RouteForm :open="form === 'route'" :route="editRoute" @close="form = null" />
</template>
