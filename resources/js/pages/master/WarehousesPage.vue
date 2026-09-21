<script setup>
// Warehouse structure (nav `whs`): warehouse cards, profile + KPIs, staff & dock cards, zones (type chips, pick
// strategy, occupancy), stock-by-zone summary, bins table with status change, and the warehouse / zone / bin /
// assign / dock / move forms. Everything comes from the API.
// Deep links: /whs?wh=<code> selects a warehouse · /whs?q=<text> selects the warehouse with that code, otherwise
// searches the bins (that is what `entityPath('bin' | 'warehouse', …)` produces).
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, PageHead, ProgressBar, SectionCard, SelectInput, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm, showCustomLabel, showLabel, showLabels } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import BinForm from './BinForm.vue';
import BinStatusForm from './BinStatusForm.vue';
import DockForm from './DockForm.vue';
import MoveForm from './MoveForm.vue';
import SkuPill from './SkuPill.vue';
import StaffForm from './StaffForm.vue';
import StockRows from './StockRows.vue';
import WarehouseForm from './WarehouseForm.vue';
import WhKpi from './WhKpi.vue';
import WhMeta from './WhMeta.vue';
import ZoneForm from './ZoneForm.vue';
import { ACTIVE_LABELS, today } from './_shared';
import { BIN_STATUS_LABELS, BIN_TYPE_LABELS, DOCK_TYPE_LABELS, SHIFT_LABELS, STAFF_ROLE_LABELS, STAFF_ZONES_LABELS, TEMP_LABELS, WH_TYPE_LABELS, ZONE_TYPE_LABELS, lbl, opts, zoneOpts } from './_whForms';

const LINK = 'cursor-pointer whitespace-nowrap text-[9px] font-extrabold';
const STAFF_COLS = 'grid-cols-[minmax(120px,1.3fr)_110px_100px_80px_60px]';
const DOCK_COLS = 'grid-cols-[60px_80px_minmax(130px,1.3fr)_100px_110px_50px]';
const SUB_HEAD = 'grid gap-2 border-t border-line-2 bg-soft px-4 py-[7px] text-[9px] font-extrabold text-faint';
const SUB_ROW = 'grid items-center gap-2 border-t border-line-2 px-4 py-2';

const auth = useAuth();
const whStore = useWarehouse();
const route = useRoute();
const router = useRouter();
const str = (v) => (typeof v === 'string' ? v : '');
const canManage = computed(() => auth.can('warehouse.manage'));
const canMove = computed(() => auth.can('inventory.move'));
const enc = encodeURIComponent;

// ── selection (local; follows the global warehouse chip) ──
const sel = ref(str(route.query.wh).toUpperCase() || whStore.current?.code || '');
const zone = ref('');
const status = ref('');
const q = ref(str(route.query.q));
const qLive = ref(q.value);
const page = ref(1);
/** Result of the last zone creation (shown as a banner above the zones table). */
const zoneNew = ref(null);

const whList = useList('/warehouses', { pageSize: 100 });
const warehouses = computed(() => whList.data.value?.items || []);

function resetFilters() { zone.value = ''; status.value = ''; page.value = 1; zoneNew.value = null; }
function setSel(code) {
  sel.value = code; resetFilters(); q.value = ''; qLive.value = '';
  router.replace({ query: { ...route.query, wh: code } });
}
let deepQ = str(route.query.q);
watch(warehouses, (list) => {
  if (!list.length) return;
  if (deepQ) { // `?q=` naming a warehouse selects it instead of searching the bins
    const hit = !route.query.wh && list.find((x) => x.code.toLowerCase() === deepQ.toLowerCase());
    if (hit) { sel.value = hit.code; q.value = ''; qLive.value = ''; }
    deepQ = '';
  }
  if (!sel.value) sel.value = whStore.current?.code || list[0].code;
}, { immediate: true });
// Picking a warehouse in the topbar moves this page to it (a `?wh=` deep link wins on arrival).
watch(() => whStore.current?.code, (code) => { if (code && code !== sel.value) { sel.value = code; resetFilters(); } });

// ── data of the selected warehouse ──
const selPath = (suffix = '') => (sel.value ? `/warehouses/${enc(sel.value)}${suffix}` : null);
const detail = useGet(() => selPath());
const staff = useGet(() => selPath('/staff'));
const dockDate = ref(today());
const docks = useGet(() => selPath('/docks'), () => ({ date: dockDate.value || undefined }));
const summary = useGet('/inventory/balances/summary', () => ({ warehouse: sel.value }), { enabled: () => !!sel.value, retry: false, staleTime: 30_000 });
const bins = useList('/bins', () => ({ warehouse: sel.value, zone: zone.value || undefined, status: status.value || undefined, q: q.value || undefined, page: page.value, pageSize: 25 }), { enabled: () => !!sel.value });
const bal = useList('/inventory/balances', () => ({ warehouse: sel.value, zone: zone.value || undefined, pageSize: 500 }), { enabled: () => !!sel.value, retry: false });

const w = computed(() => detail.data.value);
const zones = computed(() => w.value?.zones || []);
const whSummary = computed(() => summary.data.value?.warehouses.find((x) => x.code === sel.value));
const costVisible = computed(() => !!summary.data.value?.costVisible);
const bs = computed(() => w.value?.binsByStatus || {});
const totalBins = computed(() => w.value?.counts?.bins ?? Object.values(bs.value).reduce((a, b) => a + b, 0));
const offBins = computed(() => (bs.value.blocked || 0) + (bs.value.inactive || 0));
const pickStrategies = computed(() => (zones.value.length ? [...new Set(zones.value.map((z) => z.pickStrategy.toUpperCase()))].join(' / ') : '—'));
const whName = (x) => (lang.value === 'ar' ? x.nameAr : x.nameEn || x.nameAr);

// ── forms ──
/** 'wh' | 'zone' | 'bin' | 'staff' | 'dock' | 'move' | null */
const form = ref(null);
const binZone = ref('');
const movePreset = ref(null);
const statusBin = ref(null);
const act = useAction({ invalidate: ['warehouses'] });
function openMove(preset) { movePreset.value = preset; form.value = 'move'; }
function openBin(z) { binZone.value = z || ''; form.value = 'bin'; }
function openForm(k) { if (k === 'bin') openBin(zone.value); else if (k === 'move') openMove(null); else form.value = k; }
const commands = computed(() => [
  { k: 'wh', label: { ar: '+ مستودع', en: '+ Warehouse' }, tone: 'primary', show: canManage.value },
  { k: 'zone', label: { ar: '+ منطقة', en: '+ Zone' }, tone: 'dark', show: canManage.value },
  { k: 'bin', label: { ar: '+ موقع Bin', en: '+ Bin' }, tone: 'dark', show: canManage.value },
  { k: 'staff', label: { ar: 'تعيين عامل', en: 'Assign worker' }, tone: 'dark', show: canManage.value },
  { k: 'move', label: { ar: 'نقل Bin-to-Bin', en: 'Move' }, tone: 'dark', show: canMove.value },
  { k: 'dock', label: { ar: 'حجز رصيف', en: 'Dock slot' }, tone: 'dark', show: canManage.value },
].filter((c) => c.show));

// ── stock by zone (aggregated from the live balances, ≤ 500 rows) ──
const byZone = computed(() => {
  const m = new Map();
  for (const r of bal.data.value?.items || []) {
    const a = m.get(r.zone) || { zone: r.zone, onHand: 0, reserved: 0, available: 0, quarantine: 0, _skus: new Set(), _bins: new Set() };
    a.onHand += r.onHand; a.reserved += r.reserved; a.available += r.available;
    if (r.quarantine || r.zoneType === 'quarantine') a.quarantine += r.onHand;
    if (r.onHand > 0) { a._skus.add(r.sku); a._bins.add(r.bin); }
    m.set(r.zone, a);
  }
  return [...m.values()].map((a) => ({ zone: a.zone, skus: a._skus.size, bins: a._bins.size, onHand: a.onHand, reserved: a.reserved, available: a.available, quarantine: a.quarantine })).sort((x, y) => x.zone.localeCompare(y.zone));
});
const balPartial = computed(() => (bal.data.value?.total || 0) > (bal.data.value?.items?.length || 0));
/** Share of a zone's bins that hold stock → { pct, color }. */
function occupancy(z) {
  const a = byZone.value.find((x) => x.zone === z.code);
  const pct = z.binsCount ? Math.min(100, Math.round(((a?.bins || 0) / z.binsCount) * 100)) : 0;
  return { pct, color: pct >= 90 ? '#b23b3b' : pct >= 70 ? '#b26a16' : '#1BC4DB' };
}
const zoneOf = (code) => zones.value.find((x) => x.code === code);
const zoneStyle = (type) => ZONE_TYPE_LABELS[type] || {};

// ── actions ──
async function removeStaff(s) {
  if (!(await confirm({ title: { ar: `إلغاء تعيين ${s.who}؟`, en: `Remove ${s.who}?` }, sub: { ar: 'لن تُوجَّه له مهام Scan بعد الآن', en: 'Scan tasks will no longer be routed to them' }, tone: 'danger' }))) return;
  await act.run(() => api.del(`/warehouses/${enc(sel.value)}/staff/${s.id}`), { success: { ar: 'أُلغي التعيين', en: 'Assignment removed' } });
}
async function cancelDock(d) {
  if (!(await confirm({ title: { ar: `إلغاء حجز ${d.dock} — ${d.reference}؟`, en: `Cancel ${d.dock} — ${d.reference}?` }, tone: 'danger' }))) return;
  await act.run(() => api.del(`/warehouses/${enc(sel.value)}/docks/${d.id}`), { success: { ar: 'أُلغي الحجز', en: 'Appointment cancelled' } });
}
function submitSearch() { q.value = qLive.value.trim(); page.value = 1; }
function clearBinFilters() { q.value = ''; qLive.value = ''; zone.value = ''; status.value = ''; page.value = 1; }
function toggleZone(code) { zone.value = zone.value === code ? '' : code; page.value = 1; }
const isNewStaff = (s) => !!s.createdAt && Date.now() - new Date(s.createdAt).getTime() < 7 * 86400_000;

function onZoneCreated(r, wc) { if (wc !== sel.value) setSel(wc); zoneNew.value = r; zone.value = r.zone.code; page.value = 1; }
function onBinCreated(b) { zone.value = b.zone.code; status.value = ''; q.value = ''; qLive.value = ''; page.value = 1; }

// ── columns ──
const zoneCols = [
  { key: 'code', header: { ar: 'المنطقة', en: 'Zone' }, width: 'minmax(170px,1.4fr)' },
  { key: 'type', header: { ar: 'نوع التخزين', en: 'Storage' }, width: '100px' },
  { key: 'pickStrategy', header: { ar: 'الصرف', en: 'Pick' }, width: '58px' },
  { key: 'racksCount', header: { ar: 'رفوف', en: 'Racks' }, width: '58px', kind: 'num', align: 'center' },
  { key: 'binsCount', header: { ar: 'مواقع', en: 'Bins' }, width: '58px', kind: 'num', align: 'center' },
  { key: 'cap', header: { ar: 'سعة الموقع', en: 'Bin cap.' }, width: '100px', ltr: true },
  { key: 'temp', header: { ar: 'الحرارة', en: 'Temp' }, width: '80px', ltr: true },
  { key: 'occ', header: { ar: 'الإشغال', en: 'Occupancy' }, width: 'minmax(130px,1fr)' },
  { key: 'act', header: '', width: '150px', align: 'end' },
];
const aggCols = [
  { key: 'zone', header: { ar: 'المنطقة', en: 'Zone' }, width: 'minmax(140px,1.2fr)' },
  { key: 'skus', header: { ar: 'أصناف', en: 'SKUs' }, width: '64px', kind: 'num', align: 'center' },
  { key: 'bins', header: { ar: 'مواقع مشغولة', en: 'Bins used' }, width: '84px', kind: 'num', align: 'center' },
  { key: 'onHand', header: { ar: 'فعلي', en: 'On hand' }, width: '70px', kind: 'num', align: 'center' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '70px', kind: 'num', align: 'center' },
  { key: 'available', header: { ar: 'متاح', en: 'Available' }, width: '70px', kind: 'num', align: 'center' },
  { key: 'quarantine', header: { ar: 'محجور', en: 'Quarantine' }, width: '70px', kind: 'num', align: 'center' },
];
const binCols = [
  { key: 'zone', header: { ar: 'المنطقة', en: 'Zone' }, width: '110px' },
  { key: 'rack', header: { ar: 'الرف', en: 'Rack' }, width: '80px', kind: 'muted', ltr: true, value: (b) => b.rack?.code || '—' },
  { key: 'code', header: { ar: 'الموقع Bin', en: 'Bin' }, width: '120px', kind: 'id' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '84px' },
  { key: 'cap', header: { ar: 'السعة', en: 'Capacity' }, width: '104px', ltr: true },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '84px' },
  { key: 'fixed', header: { ar: 'تخصيص ثابت', en: 'Fixed product' }, width: 'minmax(150px,1.2fr)' },
  { key: 'bal', header: { ar: 'أرصدة', en: 'Rows' }, width: '56px', align: 'center' },
  { key: 'act', header: '', width: '170px', align: 'end', bare: true },
];
/** Labels of EVERY bin matching the current filter (not only the visible page), up to the print limit. */
const printingBins = ref(false);
async function printBinLabels() {
  printingBins.value = true;
  try {
    const r = await api.get('/bins', { warehouse: sel.value, zone: zone.value || undefined, status: status.value || undefined, q: q.value || undefined, page: 1, pageSize: 300 });
    showLabels((r.items || []).map((b) => ({ type: 'code128', text: b.code, title: b.code, sub: `${b.zone?.code || ''} · ${lbl(BIN_TYPE_LABELS, b.type)}` })), sel.value);
  } finally { printingBins.value = false; }
}

const capText = (x) => `${x.capacityUnits != null ? fmtNum(x.capacityUnits) : '—'} u · ${x.maxKg != null ? fmtNum(x.maxKg) : '—'} kg`;
const tempText = (z) => (z.minTempC != null || z.maxTempC != null ? `${z.minTempC ?? '—'}° … ${z.maxTempC ?? '—'}°` : '—');
const binZoneOpts = computed(() => zoneOpts(zones.value));
</script>

<template>
  <PageHead :sub="t('Warehouse ← Zone ← Aisle ← Rack ← Bin — لكل موقع باركود وسعة ونوع تخزين', 'Warehouse → zone → aisle → rack → bin — every bin has a barcode, capacity and storage type')">
    <Btn v-for="c in commands" :key="c.k" :tone="c.tone" size="sm" class="!h-[34px] !rounded-[10px]" :disabled="c.k !== 'wh' && !sel" :label="c.label" @click="openForm(c.k)" />
    <Btn tone="soft" size="sm" class="!h-[34px] !rounded-[10px]" :label="{ ar: 'ملصق مخصص', en: 'Custom label' }" @click="showCustomLabel()" />
  </PageHead>

  <!-- ── warehouse cards ── -->
  <ErrorBanner :error="whList.error.value" :closable="false" />
  <div class="mb-3 grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-2.5">
    <template v-if="whList.isLoading.value && !warehouses.length"><div v-for="i in 3" :key="i" class="skel min-h-[84px] !rounded-[14px]" /></template>
    <div v-for="x in warehouses" :key="x.id" class="cursor-pointer rounded-[14px] px-3.5 py-3" :class="[x.code === sel ? 'border-[1.5px] border-violet bg-soft' : 'border border-line bg-white', { 'opacity-55': !x.active }]" @click="setSel(x.code)">
      <div class="row">
        <span class="num text-[13px] font-bold text-violet">{{ x.code }}</span><span class="ellipsis text-[11.5px] font-extrabold">{{ whName(x) }}</span><span class="grow" />
        <Chip v-if="!x.active" :map="ACTIVE_LABELS" k="inactive" small />
      </div>
      <div class="mt-1 text-[9.5px] text-muted">{{ x.city || '—' }} · {{ lbl(WH_TYPE_LABELS, x.type) }}</div>
      <div class="mt-1.5 text-[9.5px] text-faint">
        <b class="num text-ink">{{ fmtNum(x.zonesCount) }}</b> {{ t('مناطق', 'zones') }} · <b class="num text-ink">{{ fmtNum(x.binsCount) }}</b> {{ t('موقع', 'bins') }} · <b class="num text-ink">{{ fmtNum(x.docks) }}</b> {{ t('أرصفة', 'docks') }}
      </div>
    </div>
    <div v-if="canManage" class="empty flex cursor-pointer items-center justify-center rounded-[14px] border-[1.5px] border-dashed border-[#DCD2EE] !p-3.5 font-extrabold !text-violet" @click="form = 'wh'">{{ t('+ مستودع جديد', '+ New warehouse') }}</div>
    <div v-if="!whList.isLoading.value && !warehouses.length && !canManage" class="empty">{{ t('لا مستودعات معرّفة', 'No warehouses') }}</div>
  </div>

  <!-- ── profile ── -->
  <ErrorBanner :error="detail.error.value" :closable="false" />
  <div v-if="sel" class="rounded-[18px] border border-line bg-white px-5 py-4">
    <div v-if="detail.isLoading.value && !w" class="skel min-h-[120px]" />
    <template v-if="w">
      <div class="row wrap !gap-2.5">
        <div class="text-[15px] font-extrabold">{{ whName(w) }}</div>
        <div class="text-[10px] text-faint">
          <bdi dir="ltr" class="num">{{ w.code }}</bdi> · <bdi dir="ltr">{{ lang === 'ar' ? w.nameEn : w.nameAr }}</bdi> ·
          {{ t(`${fmtNum(w.counts?.zones ?? zones.length)} مناطق · ${fmtNum(totalBins)} موقعًا`, `${fmtNum(w.counts?.zones ?? zones.length)} zones · ${fmtNum(totalBins)} bins`) }}
        </div>
        <Chip :map="WH_TYPE_LABELS" :k="w.type" small />
        <Chip v-if="!w.active" :map="ACTIVE_LABELS" k="inactive" small />
      </div>
      <div class="mt-2.5 grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-x-[18px] gap-y-2">
        <WhMeta :k="{ ar: 'المدينة', en: 'City' }" :v="w.city || '—'" />
        <WhMeta :k="{ ar: 'المساحة', en: 'Area' }"><span v-if="w.areaM2 != null" class="num">{{ fmtNum(w.areaM2) }} م²</span><template v-else>—</template></WhMeta>
        <WhMeta :k="{ ar: 'الأرصفة', en: 'Docks' }"><span class="num">{{ fmtNum(w.docks) }}</span></WhMeta>
        <WhMeta :k="{ ar: 'مناطق الحرارة', en: 'Temp zones' }" :v="lbl(TEMP_LABELS, w.tempZones)" />
        <WhMeta :k="{ ar: 'التشغيل', en: 'Hours' }"><bdi dir="ltr" class="num">{{ w.hours || '—' }}</bdi></WhMeta>
        <WhMeta :k="{ ar: 'تاريخ التشغيل', en: 'Open since' }"><span v-if="w.openDate" class="num ltr">{{ fmtDateOnly(w.openDate) }}</span><template v-else>—</template></WhMeta>
        <WhMeta :k="{ ar: 'استراتيجية الصرف', en: 'Pick strategy' }" :v="pickStrategies" />
        <WhMeta :k="{ ar: 'المستخدمون', en: 'Users' }"><span class="num">{{ fmtNum(w.counts?.users ?? 0) }}</span></WhMeta>
      </div>
      <div class="mt-3.5 grid grid-cols-[repeat(auto-fit,minmax(110px,1fr))] gap-2">
        <WhKpi :v="fmtNum(totalBins)" :l="{ ar: 'مواقع Bins', en: 'Bins' }" />
        <WhKpi :v="fmtNum(bs.active || 0)" :l="{ ar: 'مواقع نشطة', en: 'Active bins' }" color="#1d7a3e" />
        <WhKpi :v="fmtNum(bs.full || 0)" :l="{ ar: 'ممتلئة', en: 'Full' }" color="#b26a16" />
        <WhKpi :v="fmtNum(offBins)" :l="{ ar: 'محظورة / موقوفة', en: 'Blocked / inactive' }" :color="offBins ? '#b23b3b' : '#a8a4b8'" />
        <WhKpi :v="fmtNum(w.counts?.docks_ ?? 0)" :l="{ ar: 'حجوزات الأرصفة', en: 'Dock bookings' }" />
        <WhKpi :v="fmtNum(w.counts?.staff ?? staff.data.value?.length ?? 0)" :l="{ ar: 'فريق المستودع', en: 'Staff' }" />
        <WhKpi :v="summary.error.value ? '—' : summary.isLoading.value ? '…' : fmtNum(whSummary?.available)" :l="{ ar: 'وحدة متاحة', en: 'Available units' }" color="#1d7a3e" />
        <!-- stock value only when the server says cost is visible for this user (inventory.view_cost) -->
        <WhKpi v-if="costVisible" :v="whSummary?.value != null ? fmtMoney(whSummary.value) : '—'" :l="{ ar: 'قيمة المخزون ر.س', en: 'Stock value SAR' }" color="#20242E" />
        <WhKpi v-else :v="summary.error.value ? '—' : fmtNum(w.counts?.vehicles ?? 0)" :l="{ ar: 'مركبات مرتبطة', en: 'Vehicles' }" color="#20242E" />
      </div>
    </template>
  </div>

  <template v-if="sel">
    <!-- ── staff + docks ── -->
    <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(320px,1fr))] gap-3">
      <SectionCard small :title="{ ar: 'فريق المستودع', en: 'Warehouse staff' }" :count="staff.data.value?.length" :padded="false">
        <template #actions><Btn v-if="canManage" tone="softPurple" size="sm" class="!h-7" :label="{ ar: '+ تعيين', en: '+ Assign' }" @click="form = 'staff'" /></template>
        <ErrorBanner :error="staff.error.value" :closable="false" />
        <div class="overflow-x-auto">
          <div class="min-w-[520px]">
            <div :class="[SUB_HEAD, STAFF_COLS]"><div>{{ t('الاسم', 'Name') }}</div><div>{{ t('الدور', 'Role') }}</div><div>{{ t('المناطق المسموحة', 'Zones') }}</div><div>{{ t('الوردية', 'Shift') }}</div><div /></div>
            <div v-if="staff.isLoading.value" class="skel min-h-[60px]" />
            <div v-if="staff.data.value?.length === 0" class="empty">{{ t('لا تعيينات بعد — عيّن أول عامل', 'No staff assigned yet') }}</div>
            <div v-for="s in staff.data.value || []" :key="s.id" :class="[SUB_ROW, STAFF_COLS]">
              <div class="row !gap-1.5"><div class="text-[10.5px] font-extrabold">{{ s.who }}</div><Chip v-if="isNewStaff(s)" small :label="{ ar: 'جديد', en: 'New' }" fg="#654e92" bg="#EFEAF8" /></div>
              <div class="text-[9.5px] font-bold text-violet" :title="s.cert || null">{{ lbl(STAFF_ROLE_LABELS, s.role) }}</div>
              <div class="num text-[9.5px] text-sec">{{ lbl(STAFF_ZONES_LABELS, s.zones) }}</div>
              <div class="text-[9.5px] text-muted">{{ lbl(SHIFT_LABELS, s.shift) }}</div>
              <div class="text-end"><span v-if="canManage" :class="LINK" class="text-bad" @click="removeStaff(s)">{{ t('إلغاء', 'Remove') }}</span></div>
            </div>
          </div>
        </div>
      </SectionCard>

      <SectionCard small :title="{ ar: 'حجوزات الأرصفة', en: 'Dock appointments' }" :count="docks.data.value?.length" :padded="false">
        <template #actions>
          <div class="row !gap-1.5">
            <DateInput v-model="dockDate" small class="w-[130px]" />
            <span v-if="dockDate" :class="LINK" class="text-muted" @click="dockDate = ''">{{ t('الكل', 'All') }}</span>
            <Btn v-if="canManage" tone="softPurple" size="sm" class="!h-7" :label="{ ar: '+ حجز', en: '+ Book' }" @click="form = 'dock'" />
          </div>
        </template>
        <ErrorBanner :error="docks.error.value" :closable="false" />
        <div class="overflow-x-auto">
          <div class="min-w-[520px]">
            <div :class="[SUB_HEAD, DOCK_COLS]"><div>{{ t('الرصيف', 'Dock') }}</div><div>{{ t('النوع', 'Type') }}</div><div>{{ t('المرجع', 'Reference') }}</div><div>{{ t('التاريخ · الفترة', 'Date · slot') }}</div><div>{{ t('الناقل', 'Carrier') }}</div><div /></div>
            <div v-if="docks.isLoading.value" class="skel min-h-[60px]" />
            <div v-if="docks.data.value?.length === 0" class="empty">{{ dockDate ? t('لا حجوزات في هذا اليوم', 'No appointments on this day') : t('لا حجوزات', 'No appointments') }}</div>
            <div v-for="d in docks.data.value || []" :key="d.id" :class="[SUB_ROW, DOCK_COLS]">
              <div class="num text-[11px] font-bold text-violet">{{ d.dock }}</div>
              <div><Chip :map="DOCK_TYPE_LABELS" :k="d.type" small /></div>
              <div class="text-[9.5px] text-sec"><span class="cell-id">{{ d.reference }}</span><div class="cell-sub">{{ d.number }}</div></div>
              <div class="num text-[9.5px] text-muted" dir="ltr">{{ fmtDateOnly(d.date) }} · {{ d.slot }}</div>
              <div class="ellipsis text-[9.5px] text-sec">{{ d.carrier || '—' }}</div>
              <div class="text-end"><span v-if="canManage" :class="LINK" class="text-bad" @click="cancelDock(d)">{{ t('إلغاء', 'Cancel') }}</span></div>
            </div>
          </div>
        </div>
      </SectionCard>
    </div>

    <!-- ── zones ── -->
    <SectionCard class="mt-3" :title="{ ar: 'المناطق Zones', en: 'Zones' }" :sub="{ ar: 'نوع التخزين يُفرض آليًا على Putaway · الصرف حسب استراتيجية المنطقة', en: 'Storage type is enforced on putaway · picking follows the zone strategy' }" :count="zones.length" :padded="false">
      <template #actions><Btn v-if="canManage" tone="dark" size="sm" class="!h-[30px]" :label="{ ar: '+ منطقة', en: '+ Zone' }" @click="form = 'zone'" /></template>
      <div v-if="zoneNew" class="hint purple !mx-[18px] !mb-2.5 !mt-0">
        {{ t(`أُنشئت المنطقة ${zoneNew.zone.code} — ${fmtNum(zoneNew.racksCount)} رفًا و ${fmtNum(zoneNew.binsCount)} موقعًا مولّدًا تلقائيًا (${zoneNew.firstBin} … ${zoneNew.lastBin}) — ملصقات الباركود جاهزة للطباعة`, `Zone ${zoneNew.zone.code} created — ${fmtNum(zoneNew.racksCount)} racks and ${fmtNum(zoneNew.binsCount)} bins generated (${zoneNew.firstBin} … ${zoneNew.lastBin})`) }}
        <span :class="LINK" class="ms-2 text-violet" @click="zoneNew = null">✕</span>
      </div>
      <DataTable :columns="zoneCols" :rows="zones" :loading="detail.isLoading.value" :row-key="(z) => z.id" dense :min-width="900" :selected-key="zoneOf(zone)?.id ?? null" :empty-text="{ ar: 'لا مناطق بعد — أضف أول منطقة (A سريعة الحركة، CH مبردات، FZ مجمدات…)', en: 'No zones yet — add the first zone' }" @row-click="(z) => toggleZone(z.code)">
        <template #cell-code="{ row }">
          <span class="row !gap-1.5"><Chip :label="`${row.code} · ${whName(row)}`" :fg="zoneStyle(row.type).fg" :bg="zoneStyle(row.type).bg" /><Chip v-if="!row.active" :map="ACTIVE_LABELS" k="inactive" small /></span>
        </template>
        <template #cell-type="{ row }"><span class="text-[9.5px] font-extrabold" :style="{ color: zoneStyle(row.type).fg }">{{ lbl(ZONE_TYPE_LABELS, row.type) }}</span></template>
        <template #cell-pickStrategy="{ row }"><span class="num text-[9.5px] font-extrabold text-violet">{{ row.pickStrategy.toUpperCase() }}</span></template>
        <template #cell-cap="{ row }"><span class="num text-[9.5px] text-sec">{{ capText(row) }}</span></template>
        <template #cell-temp="{ row }"><span class="num text-[9.5px] text-muted">{{ tempText(row) }}</span></template>
        <template #cell-occ="{ row }">
          <span class="row">
            <span class="min-w-[60px] flex-1"><ProgressBar :pct="occupancy(row).pct" :color="occupancy(row).color" /></span>
            <span class="num text-[10.5px] font-bold" :style="{ color: occupancy(row).color }">{{ bal.isLoading.value ? '…' : `${occupancy(row).pct}%` }}</span>
          </span>
        </template>
        <template #cell-act="{ row }">
          <span class="row justify-end !gap-2.5">
            <span :class="LINK" class="text-brand-dark" @click.stop="toggleZone(row.code)">{{ zone === row.code ? t('كل المواقع', 'All bins') : t('عرض المواقع', 'Show bins') }}</span>
            <span v-if="canManage" :class="LINK" class="inline-flex h-[26px] items-center rounded-lg bg-canvas px-[9px] text-violet" @click.stop="openBin(row.code)">{{ t('+ موقع', '+ Bin') }}</span>
          </span>
        </template>
      </DataTable>
    </SectionCard>

    <!-- ── stock by zone ── -->
    <div class="mt-3 grid gap-3" :class="zone ? 'grid-cols-[repeat(auto-fit,minmax(360px,1fr))]' : 'grid-cols-1'">
      <SectionCard small :title="{ ar: 'الرصيد حسب المنطقة', en: 'Stock by zone' }" :padded="false"
                   :sub="balPartial ? { ar: `تقريبي — أول ${fmtNum(bal.data.value?.items.length)} من ${fmtNum(bal.data.value?.total)} صفًا`, en: `Approximate — first ${fmtNum(bal.data.value?.items.length)} of ${fmtNum(bal.data.value?.total)} rows` } : null">
        <ErrorBanner :error="bal.error.value" :closable="false" />
        <DataTable :columns="aggCols" :rows="byZone" :loading="bal.isLoading.value" :row-key="(a) => a.zone" dense :min-width="560" :selected-key="zone || null" :empty-text="{ ar: 'لا رصيد في هذا المستودع', en: 'No stock in this warehouse' }" @row-click="(a) => toggleZone(a.zone)">
          <template #cell-zone="{ row }"><Chip :label="zoneOf(row.zone) ? `${row.zone} · ${zoneOf(row.zone).nameAr}` : row.zone" :fg="zoneStyle(zoneOf(row.zone)?.type).fg" :bg="zoneStyle(zoneOf(row.zone)?.type).bg" small /></template>
          <template #cell-reserved="{ row }"><span :class="row.reserved ? 'text-warn' : 'text-faint'">{{ fmtNum(row.reserved) }}</span></template>
          <template #cell-available="{ row }"><span :class="row.available > 0 ? 'text-ok' : 'text-faint'">{{ fmtNum(row.available) }}</span></template>
          <template #cell-quarantine="{ row }"><span :class="row.quarantine ? 'text-bad' : 'text-faint'">{{ fmtNum(row.quarantine) }}</span></template>
        </DataTable>
      </SectionCard>
      <SectionCard v-if="zone" small :title="{ ar: `أرصدة المنطقة ${zone} حسب الموقع / الدفعة`, en: `Zone ${zone} — per bin / batch` }" :count="bal.data.value?.total" :padded="false">
        <template #actions><span :class="LINK" class="text-muted" @click="zone = ''">✕</span></template>
        <StockRows :rows="bal.data.value?.items || []" :loading="bal.isLoading.value" :movable="canMove" @move="(r) => openMove({ sku: r.sku, fromBin: r.bin, batchNo: r.batch })" />
      </SectionCard>
    </div>

    <!-- ── bins ── -->
    <SectionCard class="mt-3" :title="{ ar: 'المواقع Bins', en: 'Bins' }" :count="bins.data.value?.total" :padded="false">
      <template #actions>
        <div class="row wrap !gap-1.5">
          <TextInput scan v-model="qLive" small class="w-[200px]" :placeholder="{ ar: 'بحث رمز الموقع / SKU…', en: 'Search bin / SKU…' }" @enter="submitSearch" />
          <Btn tone="soft" size="sm" :label="{ ar: 'بحث', en: 'Search' }" @click="submitSearch" />
          <SelectInput v-model="zone" small class="w-[150px]" :options="binZoneOpts" :placeholder="{ ar: 'كل المناطق', en: 'All zones' }" @update:model-value="page = 1" />
          <SelectInput v-model="status" small class="w-[120px]" :options="opts(BIN_STATUS_LABELS)" :placeholder="{ ar: 'كل الحالات', en: 'All statuses' }" @update:model-value="page = 1" />
          <Btn v-if="q || zone || status" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلتر', en: 'Clear' }" @click="clearBinFilters" />
          <Btn tone="softPurple" size="sm" class="!h-[30px]" :loading="printingBins" :disabled="!bins.data.value?.total" :label="{ ar: `طباعة ملصقات المواقع (${bins.data.value?.total ?? 0})`, en: `Print bin labels (${bins.data.value?.total ?? 0})` }" @click="printBinLabels" />
          <Btn v-if="canManage" tone="dark" size="sm" class="!h-[30px]" :label="{ ar: '+ موقع Bin', en: '+ Bin' }" @click="openBin(zone)" />
        </div>
      </template>
      <ErrorBanner :error="bins.error.value" :closable="false" />
      <DataTable :columns="binCols" :paged="bins.data.value" :loading="bins.isLoading.value" :row-key="(b) => b.id" dense :min-width="960" :row-style="(b) => (b.status !== 'active' ? { opacity: 0.7 } : null)" :empty-text="{ ar: 'لا مواقع مطابقة', en: 'No matching bins' }" @page="page = $event">
        <template #cell-zone="{ row }"><Chip :label="row.zone.code" :fg="zoneStyle(row.zone.type).fg" :bg="zoneStyle(row.zone.type).bg" small :title="row.zone.nameAr" /></template>
        <template #cell-type="{ row }"><span class="text-[9.5px] font-bold text-sec">{{ lbl(BIN_TYPE_LABELS, row.type) }}</span></template>
        <template #cell-cap="{ row }"><span class="num text-[9.5px] text-sec">{{ capText(row) }}</span></template>
        <template #cell-status="{ row }"><Chip :map="BIN_STATUS_LABELS" :k="row.status" small /></template>
        <template #cell-fixed="{ row }">
          <span v-if="row.fixedProduct" class="row !gap-1.5"><SkuPill :sku="row.fixedProduct.sku" /><span class="ellipsis text-[10px] font-bold">{{ row.fixedProduct.nameAr }}</span></span>
          <span v-else class="text-[9.5px] text-faint">{{ t('ديناميكي', 'Dynamic') }}</span>
        </template>
        <template #cell-bal="{ row }"><span class="num text-[10px]" :class="row._count?.balances ? 'text-ink' : 'text-faint'">{{ fmtNum(row._count?.balances || 0) }}</span></template>
        <template #cell-act="{ row }">
          <span class="row justify-end !gap-2.5">
            <span :class="LINK" class="text-sec" @click.stop="showLabel({ type: 'code128', text: row.code, title: row.code, sub: `${row.zone?.code || ''} · ${lbl(BIN_TYPE_LABELS, row.type)}` })">{{ t('ملصق', 'Label') }}</span>
            <span v-if="canMove && !!row._count?.balances" :class="LINK" class="text-brand-dark" @click.stop="openMove({ fromBin: row.code })">{{ t('نقل', 'Move') }}</span>
            <span v-if="canManage" :class="LINK" class="text-violet" @click.stop="statusBin = row">{{ t('تغيير الحالة', 'Status') }}</span>
          </span>
        </template>
      </DataTable>
    </SectionCard>
  </template>

  <div class="hint">{{ t('المناطق المبردة والمجمدة تُقيَّد آليًا: منتج مجمد لا يُخزن خارج FZ ولا يُخصص له موقع خارجها. المواقع المحظورة/الموقوفة تُستثنى من التخصيص والالتقاط، ولا يُوقف موقع يحوي رصيدًا. كل تغيير على الهيكل (مستودع، منطقة، موقع، تعيين، حجز رصيف) يُسجل في Audit Trail.', 'Cold zones are enforced automatically: frozen products never leave FZ. Blocked / inactive bins are skipped by allocation and picking; a bin holding stock cannot be deactivated. Every structural change is recorded in the audit trail.') }}</div>

  <!-- ── forms ── -->
  <WarehouseForm :open="form === 'wh'" @close="form = null" @done="(x) => setSel(x.code)" />
  <ZoneForm :open="form === 'zone'" :warehouses="warehouses" :warehouse-code="sel" @close="form = null" @done="onZoneCreated" />
  <BinForm :open="form === 'bin'" :warehouses="warehouses" :warehouse-code="sel" :zones="zones" :zone="binZone" @close="form = null" @done="onBinCreated" />
  <StaffForm :open="form === 'staff'" :warehouses="warehouses" :warehouse-code="sel" @close="form = null" />
  <DockForm :open="form === 'dock'" :warehouses="warehouses" :warehouse-code="sel" @close="form = null" />
  <MoveForm v-if="sel" :open="form === 'move'" :warehouse-code="sel" :preset="movePreset" @close="form = null" />
  <BinStatusForm :bin="statusBin" :open="!!statusBin" @close="statusBin = null" />
</template>
