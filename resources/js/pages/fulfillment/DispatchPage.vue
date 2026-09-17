<script setup>
// Load & Dispatch: trip selector (loadable trips), trip card with vehicle / driver / temperature need, capacity bars
// (kg / cbm), loading plan in REVERSE delivery order (last stop loads first) with the next-to-load highlight, scan vehicle +
// scan order bar, "confirm load" per row and "dispatch trip". Server messages (wrong vehicle, over weight, unsuitable
// temperature class, blocking exceptions …) are shown verbatim.
// Data: /api/transport/trips, /api/fulfillment/trips/:n/loading|load|dispatch. Deep link: `/dispatch?trip=TRP-…`.
//   LoadingPlan { trip: { number, status, tempNeed?, vehicle: { code, plateAr?, plateEn?, state, maxKg, maxCbm? } | null, driver: { code, nameAr } | null },
//                 rows: LoadRow[], totals: { kg, cbm, loadedKg, loadedCbm, utilKg, utilCbm }, nextToLoad }
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, ErrorBanner, PageHead, ProgressBar, ScanInput } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { TRIP_LABELS, VEHICLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import LoadingRow from './LoadingRow.vue';

const LOADABLE = ['planned', 'vassigned', 'dassigned', 'loading', 'ready'];
const TEMP = { dry: { ar: 'جاف', en: 'Dry' }, chill: { ar: 'مبرد +4°', en: 'Chilled' }, reefer: { ar: 'مجمد −18°', en: 'Frozen' } };

const auth = useAuth();
const wh = useWarehouse();
const route = useRoute();
const router = useRouter();
const act = useAction();

const scanVeh = ref('');
const scanOrd = ref('');
const lastMsg = ref(null);
const tripNo = computed(() => (typeof route.query.trip === 'string' && route.query.trip ? route.query.trip : null));
function setTrip(n) { router.replace({ query: { ...route.query, trip: n || undefined } }); lastMsg.value = null; act.clearError(); }

// trips that can be loaded (the API accepts a comma list for status)
const trips = useList('/transport/trips', () => ({ status: LOADABLE.join(','), ...wh.whParams, pageSize: 50, sort: 'date', order: 'asc' }), { refetchInterval: 30_000 });
const tripItems = computed(() => trips.data.value?.items || []);
watch([tripItems, tripNo], () => { if (!tripNo.value && tripItems.value.length) setTrip(tripItems.value[0].number); }, { immediate: true });

const plan = useGet(() => (tripNo.value ? `/fulfillment/trips/${encodeURIComponent(tripNo.value)}/loading` : null), undefined, { refetchInterval: 20_000 });
const p = computed(() => plan.data.value);
const tripMeta = computed(() => tripItems.value.find((x) => x.number === tripNo.value));
const rows = computed(() => p.value?.rows || []);
const loadedN = computed(() => rows.value.filter((r) => r.loaded).length);
const readyN = computed(() => rows.value.filter((r) => r.ready && !r.loaded).length);
const canLoad = computed(() => auth.can('load.confirm') && !!p.value && LOADABLE.includes(p.value.trip.status));
const canDispatch = computed(() => auth.can('trip.dispatch') && !!p.value && ['loading', 'ready'].includes(p.value.trip.status) && loadedN.value > 0);
const veh = computed(() => p.value?.trip.vehicle || null);
const vehLabel = computed(() => (veh.value ? `${veh.value.code}${veh.value.plateAr || veh.value.plateEn ? ` · ${veh.value.plateAr || veh.value.plateEn}` : ''}` : t('لا مركبة مسندة', 'No vehicle assigned')));
const tripStatusLabel = (s) => (TRIP_LABELS[s] ? (lang.value === 'ar' ? TRIP_LABELS[s].ar : TRIP_LABELS[s].en) : s);
const tempLabel = (k) => (TEMP[k] ? (lang.value === 'ar' ? TEMP[k].ar : TEMP[k].en) : k);
const refresh = () => { plan.refetch(); trips.refetch(); };

async function load(foNumber) {
  if (!tripNo.value) return;
  const body = { foNumber, scannedVehicle: scanVeh.value.trim() || undefined, scannedOrder: scanOrd.value.trim() || undefined };
  const r = await act.run(() => api.postIdempotent(`/fulfillment/trips/${encodeURIComponent(tripNo.value)}/load`, body), { success: (x) => x.message, invalidate: ['fulfillment', 'transport', 'inventory', 'sales', 'exceptions'] });
  if (r) { lastMsg.value = r.message; scanOrd.value = ''; refresh(); }
}
/** Scanned order code → the matching row (case / space insensitive), else the raw code so the server explains the mismatch. */
function onScanOrder(code) {
  const row = rows.value.find((r) => r.fo.toUpperCase() === code.toUpperCase().replace(/\s+/g, ''));
  load(row ? row.fo : code);
}
async function dispatch() {
  if (!tripNo.value || !p.value) return;
  const n = tripNo.value; const trip = p.value.trip;
  const notLoaded = rows.value.filter((r) => !r.loaded).length;
  const ok = await confirm({
    title: { ar: `إرسال الرحلة ${n}؟`, en: `Dispatch ${n}?` },
    sub: {
      ar: `${loadedN.value} طلبات محمّلة على ${trip.vehicle?.code || '—'} · السائق ${trip.driver?.nameAr || '—'}${notLoaded ? ` — ${notLoaded} طلبات غير محمّلة ستُزال من الرحلة` : ''} — يُشعر منصة B2B (ShipmentDispatched)`,
      en: `${loadedN.value} orders loaded on ${trip.vehicle?.code || '—'} · driver ${trip.driver?.nameAr || '—'}${notLoaded ? ` — ${notLoaded} unloaded orders will leave the trip` : ''}`,
    },
    tone: 'primary', okLabel: { ar: 'إرسال الرحلة', en: 'Dispatch' },
  });
  if (!ok) return;
  const r = await act.run(() => api.postIdempotent(`/fulfillment/trips/${encodeURIComponent(n)}/dispatch`), { success: (x) => x.message, invalidate: ['fulfillment', 'transport', 'inventory', 'sales'] });
  if (r) { lastMsg.value = r.message; refresh(); }
}
</script>

<template>
  <div>
    <PageHead :sub="t('التحميل بعكس ترتيب التوصيل — آخر عميل يُحمّل أولًا', 'Load in reverse delivery order — the last customer loads first')">
      <Btn tone="outline" :label="{ ar: 'الرحلات', en: 'Trips' }" @click="router.push('/trips')" />
      <Btn v-if="tripNo" tone="softBlue" :label="{ ar: `فتح الرحلة ${tripNo}`, en: `Open trip ${tripNo}` }" @click="router.push(`/trip/${encodeURIComponent(tripNo)}`)" />
    </PageHead>

    <!-- trip selector -->
    <div class="row wrap mb-3">
      <div class="text-[10.5px] font-extrabold">{{ t('رحلات جاهزة للتحميل', 'Trips ready to load') }}</div>
      <div v-if="trips.isLoading.value && tripItems.length === 0" class="skel h-[30px] w-[200px]" />
      <span v-if="!trips.isLoading.value && tripItems.length === 0" class="faint text-[10px]">{{ t('لا رحلات مخططة — أنشئ رحلة من الرحلات وأسند المركبة والسائق', 'No planned trips — create one from Trips and assign a vehicle and driver') }}</span>
      <div class="pill-bar !mb-0">
        <button v-for="x in tripItems" :key="x.number" type="button" class="pill" :class="{ active: tripNo === x.number }" @click="setTrip(x.number)">
          <span class="num">{{ x.number }}</span><span class="opacity-70">{{ fmtDateOnly(x.date) }}</span><span v-if="x.vehicle" class="num opacity-80">{{ x.vehicle.code }}</span><span class="text-[8.5px] opacity-80">{{ tripStatusLabel(x.status) }}</span>
        </button>
      </div>
    </div>
    <ErrorBanner :error="trips.error.value" :closable="false" />
    <ErrorBanner :error="plan.error.value" :closable="false" />
    <div v-if="tripNo && !p && !plan.error.value" class="skel h-[200px]" />

    <template v-if="p">
      <div class="card px-5 py-[18px]">
        <div class="row wrap !gap-2.5">
          <div>
            <div class="text-[14px] font-extrabold"><span class="num-mixed">{{ p.trip.number }}</span> <span class="text-[10px] text-faint">· {{ tripMeta?.routeAr || tripMeta?.routeEn || tripMeta?.warehouse.code || '' }}{{ tripMeta ? ` · ${fmtDateOnly(tripMeta.date)}${tripMeta.plannedStart ? ` ${tripMeta.plannedStart}` : ''}` : '' }}</span></div>
            <div class="row wrap mt-[3px] !gap-1.5 text-[10px] text-muted">
              <span class="num">{{ vehLabel }}</span><Chip v-if="veh?.state" :map="VEHICLE_LABELS" :k="veh.state" small />
              <span>· {{ p.trip.driver ? p.trip.driver.nameAr : t('لا سائق مسند', 'No driver assigned') }}</span>
              <span v-if="p.trip.tempNeed">· {{ tempLabel(p.trip.tempNeed) }}</span>
            </div>
          </div>
          <Chip :map="TRIP_LABELS" :k="p.trip.status" />
          <div class="grow" />
          <div class="num text-[11px] text-brand-dark">{{ t(`محمّل ${loadedN} / ${rows.length}`, `Loaded ${loadedN} / ${rows.length}`) }}<span v-if="readyN > 0" class="text-warn"> · {{ t(`${readyN} جاهز`, `${readyN} ready`) }}</span></div>
          <Btn v-if="canDispatch" tone="success" class="!h-[42px] !rounded-xl !px-5 !text-[12px]" :loading="act.pending.value" :label="{ ar: 'إرسال الرحلة Dispatch', en: 'Dispatch trip' }" @click="dispatch" />
          <Chip v-if="['dispatched', 'onroute'].includes(p.trip.status)" :label="{ ar: 'انطلقت ✓', en: 'Dispatched ✓' }" fg="#1d7a3e" bg="#e6f9ec" />
        </div>
        <div v-if="!veh || !p.trip.driver" class="hint amber">
          {{ t(`${!veh ? 'لا مركبة مسندة — ' : ''}${!p.trip.driver ? 'لا سائق مسند — ' : ''}أسند من صفحة الرحلة قبل التحميل/الإرسال.`, `${!veh ? 'No vehicle — ' : ''}${!p.trip.driver ? 'no driver — ' : ''}assign from the trip page before loading/dispatch.`) }}
          <RouterLink :to="`/trip/${encodeURIComponent(p.trip.number)}`" class="num font-extrabold !text-violet">{{ p.trip.number }}</RouterLink>
        </div>

        <!-- capacity -->
        <div class="mt-3.5 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3">
          <ProgressBar :pct="p.totals.utilKg ?? 0" auto :show-pct="p.totals.utilKg != null"
                       :label="{ ar: `الوزن: ${fmtNum(p.totals.loadedKg)} / ${veh ? fmtNum(veh.maxKg) : '—'} كجم (خطة ${fmtNum(p.totals.kg)})`, en: `Weight: ${fmtNum(p.totals.loadedKg)} / ${veh ? fmtNum(veh.maxKg) : '—'} kg (plan ${fmtNum(p.totals.kg)})` }" />
          <ProgressBar :pct="p.totals.utilCbm ?? 0" auto :show-pct="p.totals.utilCbm != null"
                       :label="{ ar: `الحجم: ${fmtNum(p.totals.loadedCbm, 1)} / ${veh?.maxCbm ? fmtNum(veh.maxCbm, 1) : '—'} م³ (خطة ${fmtNum(p.totals.cbm, 1)})`, en: `Volume: ${fmtNum(p.totals.loadedCbm, 1)} / ${veh?.maxCbm ? fmtNum(veh.maxCbm, 1) : '—'} m³ (plan ${fmtNum(p.totals.cbm, 1)})` }" />
        </div>
        <div v-if="veh && p.totals.kg > veh.maxKg" class="hint amber">{{ t(`تنبيه: وزن الخطة ${fmtNum(p.totals.kg)} كجم يتجاوز سعة ${veh.code} (${fmtNum(veh.maxKg)} كجم) — سيُرفض التحميل عند التجاوز.`, `Warning: plan weight ${fmtNum(p.totals.kg)} kg exceeds ${veh.code} capacity (${fmtNum(veh.maxKg)} kg).`) }}</div>
        <ErrorBanner class="mt-3" :error="act.error.value" @close="act.clearError()" />
        <div v-if="lastMsg && !act.error.value" class="hint green !mt-3">{{ lastMsg }}</div>

        <!-- stops in reverse delivery order -->
        <div class="col mt-[15px]">
          <div v-if="rows.length === 0" class="empty dashed !p-6">{{ t('لا طلبات على هذه الرحلة — أضف أوامر التنفيذ من صفحة الرحلة', 'No orders on this trip — add fulfillment orders from the trip page') }}</div>
          <LoadingRow v-for="(r, i) in rows" :key="r.fo" :row="r" :load-seq="i + 1" :is-next="p.nextToLoad === r.fo" :can-load="canLoad" :loading="act.pending.value" @load="load(r.fo)" />
        </div>
      </div>

      <!-- scan bar -->
      <div class="card row wrap mt-[11px] !rounded-[13px] px-3.5 py-2.5">
        <div class="text-[10.5px] font-extrabold">{{ t('Scan الشاحنة والطلب', 'Scan truck & order') }}</div>
        <ScanInput v-model="scanVeh" small :clear-on-submit="false" class="w-[190px]" placeholder="Scan plate / vehicle id" />
        <ScanInput v-model="scanOrd" small :clear-on-submit="false" class="w-[170px]" placeholder="Scan order (FO-…)" :disabled="!canLoad" @submit="onScanOrder" />
        <div class="text-[9.5px] text-muted">{{ veh ? t(`المسندة: ${veh.code}${veh.plateAr ? ` · ${veh.plateAr}` : ''} — مسح لوحة مختلفة يُرفض ويفتح استثناء`, `Assigned: ${veh.code}${veh.plateEn ? ` · ${veh.plateEn}` : ''} — a different plate is rejected and raises an exception`) : t('لا مركبة مسندة', 'No vehicle assigned') }}</div>
      </div>
      <div class="hint">{{ t('قواعد التحميل: آخر عميل في المسار يُحمّل أولًا · لا تحميل قبل اكتمال التعبئة · تطابق المركبة والطلب بالمسح · الوزن والحجم التراكمي ضمن سعة المركبة · المجمد/المبرد على مركبة مناسبة · لا Dispatch مع استثناء حرج مفتوح أو بدون سائق. الرسائل تأتي من الخادم كما هي.', 'Loading rules: the last stop loads first · no loading before packing · vehicle and order must match the scan · cumulative weight/volume within capacity · frozen/chilled on a suitable vehicle · no dispatch with open critical exceptions or without a driver. Messages come verbatim from the server.') }}</div>
    </template>
  </div>
</template>
