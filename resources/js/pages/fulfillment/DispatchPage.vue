<script setup>
// Load & Dispatch: trip selector (loadable trips), trip card with vehicle / driver / temperature need / loading door,
// capacity bars (kg / cbm), loading plan in REVERSE delivery order (last stop loads first) with the next-to-load
// highlight and the real load sequence, scan vehicle + scan order bar, "confirm load" / "unload" per row and
// "dispatch trip". What can and cannot be done comes from the server with its reason (`loadGate`, `dispatch`, each row's
// `waitFor` / `loadFirst`): a button that cannot work is disabled and says why — it never promises what the endpoint
// refuses. Server messages (wrong vehicle, over weight, unsuitable temperature class …) are shown verbatim.
// Data: /api/transport/trips, /api/fulfillment/trips/:n/loading|load|unload|dock|dispatch. Deep link: `/dispatch?trip=TRP-…`.
//   LoadingPlan { trip: { number, status, tempNeed?, warehouse?, dockCode?, vehicle: { code, plateAr?, plateEn?, state, maxKg, maxCbm? } | null, driver: { code, nameAr } | null },
//                 rows: LoadRow[], totals: { kg, cbm, loadedKg, loadedCbm, utilKg, utilCbm }, nextToLoad,
//                 dock: { code, options: [{ code, busyBy }] }, loadGate: { code, reasonAr, reasonEn } | null,
//                 dispatch: { allowed, code, reasonAr, reasonEn, loaded: string[], packedNotLoaded: string[], notPacked: string[] },
//                 policy: { enforceSequence, requireOrderScan } }
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, ErrorBanner, PageHead, ProgressBar, ScanInput, SelectInput } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { TRIP_LABELS, VEHICLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import LoadingRow from './LoadingRow.vue';

const LOADABLE = ['planned', 'vassigned', 'dassigned', 'loading', 'ready'];
const TEMP = { dry: { ar: 'جاف', en: 'Dry' }, chill: { ar: 'مبرد +4°', en: 'Chilled' }, reefer: { ar: 'مجمد −18°', en: 'Frozen' } };
const INVALIDATE = ['fulfillment', 'transport', 'inventory', 'sales', 'exceptions'];

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
const veh = computed(() => p.value?.trip.vehicle || null);
const vehLabel = computed(() => (veh.value ? `${veh.value.code}${veh.value.plateAr || veh.value.plateEn ? ` · ${veh.value.plateAr || veh.value.plateEn}` : ''}` : t('لا مركبة مسندة', 'No vehicle assigned')));
const tripStatusLabel = (s) => (TRIP_LABELS[s] ? (lang.value === 'ar' ? TRIP_LABELS[s].ar : TRIP_LABELS[s].en) : s);
const tempLabel = (k) => (TEMP[k] ? (lang.value === 'ar' ? TEMP[k].ar : TEMP[k].en) : k);
const said = (x) => (x ? (lang.value === 'ar' ? x.reasonAr : x.reasonEn || x.reasonAr) : null);
const refresh = () => { plan.refetch(); trips.refetch(); };
const tripPath = computed(() => `/fulfillment/trips/${encodeURIComponent(tripNo.value || '')}`);

// what the user may do, and why not
const canOverride = computed(() => auth.can('load.override'));
const loadable = computed(() => !!p.value && LOADABLE.includes(p.value.trip.status));
const canLoad = computed(() => auth.can('load.confirm') && loadable.value);
const canUnload = computed(() => auth.can('load.confirm') && !!p.value && ['loading', 'ready'].includes(p.value.trip.status));
const gateWhy = computed(() => said(p.value?.loadGate));
const scanNeeded = computed(() => !!p.value?.policy?.requireOrderScan);
/** Why "confirm load" cannot work for any row right now: the trip (no vehicle …) or the missing order scan. */
const loadWhy = computed(() => gateWhy.value
  || (scanNeeded.value && !scanOrd.value.trim() && !canOverride.value ? t('امسح ملصق الطلب أو أحد كراتينه في حقل المسح أولًا (التحميل بلا مسح قرار مشرف)', 'Scan the order label or one of its cartons in the scan field first (loading without a scan is a supervisor decision)') : null));
const dsp = computed(() => p.value?.dispatch || null);
const showDispatch = computed(() => auth.can('trip.dispatch') && loadable.value);
const dispatchWhy = computed(() => (dsp.value && !dsp.value.allowed ? said(dsp.value) : null));

// loading door
const dockOptions = computed(() => [{ v: '', l: { ar: '— بلا باب —', en: '— no door —' } },
  ...(p.value?.dock?.options || []).map((o) => ({ v: o.code, l: o.busyBy ? { ar: `${o.code} — مشغول (${o.busyBy})`, en: `${o.code} — busy (${o.busyBy})` } : o.code, disabled: !!o.busyBy }))]);
async function setDock(code) {
  if ((p.value?.dock?.code || '') === code) return;
  const r = await act.run(() => api.postIdempotent(`${tripPath.value}/dock`, { dockCode: code || null }), { success: (x) => x.message, invalidate: ['fulfillment', 'transport'] });
  if (r) refresh();
}

/**
 * Load one order. `code` is what identifies it (a row's FO number, or whatever was scanned — FO, sales order, package,
 * label, carton: the server resolves it). `scanned` proves it. `extra` carries a supervisor override.
 */
async function load(code, scanned, extra = {}) {
  if (!tripNo.value) return;
  const body = { foNumber: code, scannedVehicle: scanVeh.value.trim() || undefined, scannedOrder: scanned || undefined, ...extra };
  const r = await act.run(() => api.postIdempotent(`${tripPath.value}/load`, body), { success: (x) => x.message, invalidate: INVALIDATE });
  if (r) { lastMsg.value = r.message; scanOrd.value = ''; refresh(); }
}
/** The scan field: the code is both the order and its proof — a carton of the order loads the order. */
function onScanOrder(code) { const c = code.trim(); if (c) load(c, c); }
/** A row's button: the scan field (when filled) is the proof; without it a supervisor gives a reason. */
async function loadRow(r) {
  const scanned = scanOrd.value.trim();
  if (scanned || !scanNeeded.value) { load(r.fo, scanned); return; }
  const reason = await ask({ title: { ar: `تحميل ${r.fo} بلا مسح؟`, en: `Load ${r.fo} without a scan?` }, sub: { ar: 'يُسجَّل التجاوز باسمك مع السبب. الأصل: مسح ملصق الطلب أو أحد كراتينه.', en: 'The override is recorded under your name with the reason. The rule: scan the order label or one of its cartons.' }, label: { ar: 'السبب (مثال: الملصق تالف)', en: 'Reason (e.g. damaged label)' }, required: true, okLabel: { ar: 'تحميل بتجاوز', en: 'Load with override' } });
  if (reason !== null) load(r.fo, '', { reason });
}
async function overrideRow(r) {
  const blockers = [...(r.waitFor || []), ...(r.loadFirst || [])].join(lang.value === 'ar' ? '، ' : ', ');
  const reason = await ask({ title: { ar: `تحميل ${r.fo} خارج الترتيب؟`, en: `Load ${r.fo} out of turn?` }, sub: { ar: `محطات لاحقة لم تُحمَّل بعد: ${blockers}. ما يُحمَّل الآن سيكون خلفها أو أمامها في الشاحنة — قرار مشرف يُسجَّل بالسبب.`, en: `Later stops are not on the truck yet: ${blockers}. A supervisor decision, recorded with its reason.` }, label: { ar: 'السبب', en: 'Reason' }, required: true, tone: 'danger', okLabel: { ar: 'تحميل بتجاوز الترتيب', en: 'Load out of turn' } });
  if (reason !== null) load(r.fo, scanOrd.value.trim(), { overrideSequence: true, reason });
}
async function unloadRow(r) {
  const reason = await ask({ title: { ar: `إنزال ${r.fo} من المركبة؟`, en: `Unload ${r.fo}?` }, sub: { ar: 'يعود الطلب إلى Staging الصادر بحالة «معبأ». إن فرغت المركبة تعود الرحلة للتخطيط ويمكن تغيير المركبة أو إلغاؤها.', en: 'The order returns to outbound staging as "packed". When the truck is empty the trip goes back to planning and can be re-assigned or cancelled.' }, label: { ar: 'السبب', en: 'Reason' }, required: true, tone: 'danger', okLabel: { ar: 'إنزال', en: 'Unload' } });
  if (reason === null) return;
  const x = await act.run(() => api.postIdempotent(`${tripPath.value}/unload`, { foNumber: r.fo, reason }), { success: (y) => y.message, invalidate: INVALIDATE });
  if (x) { lastMsg.value = x.message; refresh(); }
}
async function dispatch() {
  if (!tripNo.value || !p.value || !dsp.value?.allowed) return;
  const n = tripNo.value; const trip = p.value.trip; const d = dsp.value;
  const leaving = d.notPacked.length;
  const ok = await confirm({
    title: { ar: `إرسال الرحلة ${n}؟`, en: `Dispatch ${n}?` },
    sub: {
      ar: `${d.loaded.length} طلبات محمّلة تنطلق على ${trip.vehicle?.code || '—'} · السائق ${trip.driver?.nameAr || '—'}${leaving ? ` — ${leaving} طلبات لم تُعبَّأ بعد ستخرج من الرحلة (${d.notPacked.join('، ')}) وتبقى للتجهيز` : ''} — يُشعر منصة B2B (ShipmentDispatched)`,
      en: `${d.loaded.length} loaded orders leave on ${trip.vehicle?.code || '—'} · driver ${trip.driver?.nameAr || '—'}${leaving ? ` — ${leaving} orders not packed yet leave the trip (${d.notPacked.join(', ')}) and stay in preparation` : ''}`,
    },
    tone: 'primary', okLabel: { ar: 'إرسال الرحلة', en: 'Dispatch' },
  });
  if (!ok) return;
  const r = await act.run(() => api.postIdempotent(`${tripPath.value}/dispatch`), { success: (x) => x.message, invalidate: INVALIDATE });
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
          <!-- loading door -->
          <div class="row !gap-1.5">
            <span class="text-[10px] font-extrabold text-faint">{{ t('باب التحميل', 'Loading door') }}</span>
            <SelectInput v-if="canLoad && dockOptions.length > 1" small class="w-[150px]" :model-value="p.dock.code || ''" :options="dockOptions" :disabled="act.pending.value" @update:model-value="setDock" />
            <span v-else class="num text-[11px] font-extrabold" :class="p.dock.code ? 'text-violet' : 'text-faint'">{{ p.dock.code || t('لم يُحدَّد', 'not set') }}</span>
          </div>
          <div class="grow" />
          <div class="num text-[11px] text-brand-dark">{{ t(`محمّل ${loadedN} / ${rows.length}`, `Loaded ${loadedN} / ${rows.length}`) }}<span v-if="readyN > 0" class="text-warn"> · {{ t(`${readyN} جاهز`, `${readyN} ready`) }}</span></div>
          <Btn v-if="showDispatch" tone="success" class="!h-[42px] !rounded-xl !px-5 !text-[12px]" :loading="act.pending.value" :disabled="!dsp?.allowed" :title="dispatchWhy" :label="{ ar: 'إرسال الرحلة Dispatch', en: 'Dispatch trip' }" @click="dispatch" />
          <Chip v-if="['dispatched', 'onroute'].includes(p.trip.status)" :label="{ ar: 'انطلقت ✓', en: 'Dispatched ✓' }" fg="#1d7a3e" bg="#e6f9ec" />
        </div>

        <!-- why loading / dispatch cannot happen yet — the server's own reason -->
        <div v-if="gateWhy && loadable" class="hint amber">
          {{ t('التحميل متوقف:', 'Loading is blocked:') }} {{ gateWhy }}
          <RouterLink :to="`/trip/${encodeURIComponent(p.trip.number)}`" class="num font-extrabold !text-violet">{{ p.trip.number }}</RouterLink>
        </div>
        <div v-if="showDispatch && dispatchWhy" class="hint" :class="dsp.packedNotLoaded.length || !p.trip.driver ? 'amber' : ''">
          {{ t('الإرسال غير متاح بعد:', 'Dispatch is not possible yet:') }} {{ dispatchWhy }}
          <span v-if="!p.trip.driver || !veh"> — {{ t('أسند من صفحة الرحلة', 'assign from the trip page') }} <RouterLink :to="`/trip/${encodeURIComponent(p.trip.number)}`" class="num font-extrabold !text-violet">{{ p.trip.number }}</RouterLink></span>
        </div>
        <div v-if="showDispatch && dsp && (dsp.packedNotLoaded.length || dsp.notPacked.length)" class="row wrap mt-2 !gap-2 text-[10px]">
          <span v-if="dsp.packedNotLoaded.length" class="rounded-lg bg-warn-soft px-2 py-1 font-bold text-warn">{{ t(`${dsp.packedNotLoaded.length} معبّأة لم تُحمَّل — تمنع الإرسال:`, `${dsp.packedNotLoaded.length} packed, not loaded — block dispatch:`) }} <span class="num">{{ dsp.packedNotLoaded.join(' · ') }}</span></span>
          <span v-if="dsp.notPacked.length" class="rounded-lg bg-soft px-2 py-1 font-bold text-sec">{{ t(`${dsp.notPacked.length} لم تُعبَّأ — تخرج من الرحلة عند الإرسال:`, `${dsp.notPacked.length} not packed — leave the trip at dispatch:`) }} <span class="num">{{ dsp.notPacked.join(' · ') }}</span></span>
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
          <LoadingRow v-for="(r, i) in rows" :key="r.fo" :row="r" :plan-seq="i + 1" :is-next="p.nextToLoad === r.fo" :can-load="canLoad" :load-why="loadWhy" :can-override="canOverride" :can-unload="canUnload" :loading="act.pending.value"
                      @load="loadRow(r)" @override="overrideRow(r)" @unload="unloadRow(r)" />
        </div>
      </div>

      <!-- scan bar -->
      <div class="card row wrap mt-[11px] !rounded-[13px] px-3.5 py-2.5">
        <div class="text-[10.5px] font-extrabold">{{ t('Scan الشاحنة والطلب', 'Scan truck & order') }}</div>
        <ScanInput v-model="scanVeh" small :clear-on-submit="false" class="w-[190px]" placeholder="Scan plate / vehicle id" />
        <ScanInput v-model="scanOrd" small :clear-on-submit="false" class="w-[220px]" placeholder="Scan order / carton / package" :disabled="!canLoad || !!gateWhy" @submit="onScanOrder" />
        <div class="text-[9.5px] text-muted">
          {{ veh ? t(`المسندة: ${veh.code}${veh.plateAr ? ` · ${veh.plateAr}` : ''} — مسح لوحة مختلفة يُرفض ويفتح استثناء.`, `Assigned: ${veh.code}${veh.plateEn ? ` · ${veh.plateEn}` : ''} — a different plate is rejected and raises an exception.`) : t('لا مركبة مسندة.', 'No vehicle assigned.') }}
          {{ t('مسح أي رمز للطلب — رقم الأمر، أمر البيع، الطرد، الملصق أو أحد الكراتين — يحمّل الطلب الذي يخصّه.', 'Scanning any code of the order — its number, sales order, package, label or one of its cartons — loads the order it belongs to.') }}
        </div>
      </div>
      <div class="hint">{{ t(`قواعد التحميل: آخر عميل في المسار يُحمّل أولًا${p.policy?.enforceSequence ? ' ولا يُحمَّل طلب قبل تعبئة ما بعده في المسار' : ''} · لا تحميل قبل اكتمال التعبئة · ${p.policy?.requireOrderScan ? 'مسح الطلب إلزامي (أو تجاوز مشرف بسبب)' : 'مسح الطلب اختياري'} · الوزن والحجم التراكمي ضمن سعة المركبة · المجمد/المبرد على مركبة مناسبة · «إنزال» يعيد الطلب إلى Staging ما دامت الرحلة لم تنطلق · لا Dispatch مع استثناء حرج مفتوح أو بدون سائق أو مع طلب معبّأ غير محمَّل. الرسائل تأتي من الخادم كما هي.`,
        `Loading rules: the last stop loads first${p.policy?.enforceSequence ? ', and nothing loads before the later stops are packed' : ''} · no loading before packing · ${p.policy?.requireOrderScan ? 'the order scan is required (or a supervisor override with a reason)' : 'the order scan is optional'} · cumulative weight/volume within capacity · frozen/chilled on a suitable vehicle · "Unload" returns an order to staging while the trip has not left · no dispatch with open critical exceptions, without a driver, or with a packed order not loaded. Messages come verbatim from the server.`) }}</div>
    </template>
  </div>
</template>
