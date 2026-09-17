<script setup>
// Trips — prototype `pTrips`: search + quick filters (all / active / delayed / planned / closed), status · warehouse · date
// filters, trips table → Trip Control Room drawer. Data: GET /api/transport/trips (polled every 30s).
// Deep links: /trips?trip=TRP-… opens the control room, /trips?q=… pre-fills the search.
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, DateInput, ErrorBanner, PageHead, SelectInput, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { TRIP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import NewTripForm from './NewTripForm.vue';
import RouteForm from './RouteForm.vue';
import { ACTIVE_TRIP, ENDED_TRIP, optsOf } from './tms';
import { useWarehouseOptions } from './tmsComposables';
import TripRoom from './TripRoom.vue';
import TripTable from './TripTable.vue';

const QUICK = [
  { k: 'all', label: { ar: 'الكل', en: 'All' } },
  { k: 'active', label: { ar: 'نشطة', en: 'Active' }, status: ACTIVE_TRIP.join(',') },
  { k: 'delayed', label: { ar: 'متأخرة', en: 'Delayed' }, status: ACTIVE_TRIP.join(',') },
  { k: 'planned', label: { ar: 'مخططة', en: 'Planned' }, status: 'draft,planned,vassigned,dassigned' },
  { k: 'closed', label: { ar: 'مقفلة', en: 'Closed' }, status: ENDED_TRIP.join(',') },
];

const auth = useAuth();
const wh = useWarehouse();
const whOpts = useWarehouseOptions();
const route = useRoute();
const router = useRouter();

// ---- deep link: ?trip=… ----
const openTrip = computed(() => (typeof route.query.trip === 'string' && route.query.trip ? route.query.trip : null));
function setOpenTrip(n) {
  const query = { ...route.query };
  if (n) query.trip = n; else delete query.trip;
  router.replace({ query });
}

// ---- filters ----
const q = ref(typeof route.query.q === 'string' ? route.query.q : '');
const quick = ref('all');
const status = ref('');
const warehouse = ref(wh.current?.code || '');
const date = ref('');
const page = ref(1);
const form = ref(null); // 'trip' | 'route' | null

const quickDef = computed(() => QUICK.find((x) => x.k === quick.value));
const list = useList('/transport/trips', () => ({ q: q.value.trim() || undefined, status: status.value || quickDef.value.status, warehouse: warehouse.value || undefined, date: date.value || undefined, page: page.value, pageSize: quick.value === 'delayed' ? 100 : 25 }), { refetchInterval: 30_000 });
/** "Delayed" is a client-side filter over the active trips (delayMin > 0). */
const delayedRows = computed(() => (list.data.value?.items || []).filter((r) => (r.delayMin || 0) > 0));
const statusOpts = optsOf(TRIP_LABELS);
const filtered = computed(() => !!(q.value || status.value || date.value || quick.value !== 'all'));

const reset = () => { page.value = 1; };
function pickQuick(k) { quick.value = k; status.value = ''; reset(); }
function pickStatus(v) { status.value = v; if (v) quick.value = 'all'; reset(); }
function clear() { q.value = ''; status.value = ''; date.value = ''; quick.value = 'all'; reset(); }
</script>

<template>
  <PageHead :sub="t('دورة الرحلة كاملة: تخطيط ← إسناد ← تحميل ← إرسال ← تسليم ← إقفال ← تكلفة', 'Full trip cycle: plan → assign → load → dispatch → deliver → close → cost')">
    <Btn v-if="auth.can('trip.manage')" tone="primary" :label="{ ar: '+ رحلة جديدة', en: '+ New trip' }" @click="form = 'trip'" />
    <Btn v-if="auth.can('trip.manage')" :label="{ ar: '+ مسار', en: '+ Route' }" @click="form = 'route'" />
  </PageHead>

  <div class="row wrap mb-2.5 !items-end">
    <TextInput v-model="q" small class="w-[280px]" :placeholder="{ ar: 'ابحث: رحلة، مركبة، سائق، مسار…', en: 'Search: trip, vehicle, driver, route…' }" @update:model-value="reset" />
    <div class="pill-bar !mb-0">
      <button v-for="f in QUICK" :key="f.k" type="button" class="pill" :class="{ active: quick === f.k }" @click="pickQuick(f.k)">{{ bi(f.label) }}</button>
    </div>
    <SelectInput :model-value="status" small class="w-[170px]" :placeholder="{ ar: '— كل الحالات —', en: '— all statuses —' }" :options="statusOpts" @update:model-value="pickStatus" />
    <SelectInput v-model="warehouse" small class="w-[190px]" :placeholder="{ ar: 'كل المستودعات', en: 'All warehouses' }" :options="whOpts" @update:model-value="reset" />
    <DateInput v-model="date" small class="w-[150px]" @update:model-value="reset" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلتر', en: 'Clear' }" @click="clear" />
    <div class="grow" />
    <div class="text-[10.5px] font-extrabold text-muted"><span class="num">{{ quick === 'delayed' ? delayedRows.length : list.data.value?.total ?? '—' }}</span> {{ t('رحلة', 'trips') }}</div>
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <TripTable v-if="quick === 'delayed'" :rows="delayedRows" :loading="list.isLoading.value" :selected-key="openTrip" :empty-text="{ ar: 'لا رحلات متأخرة ✓', en: 'No delayed trips ✓' }" @open="setOpenTrip" />
    <TripTable v-else :paged="list.data.value" :loading="list.isLoading.value" :selected-key="openTrip" :empty-text="{ ar: 'لا رحلات مطابقة', en: 'No matching trips' }" @page="page = $event" @open="setOpenTrip" />
  </div>
  <div class="hint teal !mt-2.5">{{ t('ETA والتتبع الحي تظهر بحالة Integration Pending حتى ربط مزود الخرائط / Telematics. الحمولة تُشتق من الطلبات المجهزة (Packed) والتكلفة تُحتسب عند الإقفال.', 'ETA and live tracking show as Integration Pending until a maps / telematics provider is connected. Load derives from packed orders; cost is computed at close.') }}</div>

  <TripRoom :number="openTrip" @close="setOpenTrip(null)" />
  <NewTripForm :open="form === 'trip'" @close="form = null" @done="(trip) => { if (trip?.number) setOpenTrip(trip.number); }" />
  <RouteForm :open="form === 'route'" @close="form = null" />
</template>
