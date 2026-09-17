<script setup>
// Vehicle drawer (520): overview · documents (expiry warnings) · maintenance · fuel · trips, state changes limited to the
// allowed next states of the state machine, and "report breakdown". GET /transport/vehicles/:code.
//   <VehicleDrawer :code="vehicle" @close="vehicle = null" @open-trip="(n) => (trip = n)" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, EmptyState, ErrorBanner, Tabs } from '@/components';
import { bi, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { OWNERSHIP_LABELS, TRIP_LABELS, VEHICLE_LABELS, VEHICLE_TRANSITIONS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import BreakdownForm from './BreakdownForm.vue';
import KvList from './KvList.vue';
import { MAINT_KIND_LABELS, MAINT_STATUS_LABELS, PENDING, VEHICLE_KIND_LABELS, docDaysLabel, labelOf, whyNot } from './tms';

const props = defineProps({ code: { type: String, default: null } });
const emit = defineEmits(['close', 'open-trip']);

const auth = useAuth();
const act = useAction();
const tab = ref('overview');
const bd = ref(false);
watch(() => props.code, () => { tab.value = 'overview'; bd.value = false; });

const q = useGet(() => (props.code ? `/transport/vehicles/${encodeURIComponent(props.code)}` : null));
const v = computed(() => (props.code ? q.data.value : null));

/** Next states the server allows; falls back to the shared state machine when the API does not send them. */
const transitions = computed(() => v.value?.allowedTransitions ?? VEHICLE_TRANSITIONS[v.value?.state] ?? []);
async function setState(to) {
  const x = v.value;
  if (!x) return;
  if (['breakdown', 'oos', 'inactive', 'maintenance'].includes(to)) {
    const ok = await confirm({ title: { ar: `تغيير حالة ${x.code} إلى «${VEHICLE_LABELS[to]?.ar || to}»؟`, en: `Change ${x.code} to "${VEHICLE_LABELS[to]?.en || to}"?` }, sub: { ar: 'المركبة لن تكون قابلة للإسناد. يُسجل في Audit.', en: 'The vehicle will not be assignable. Recorded in the audit trail.' } });
    if (!ok) return;
  }
  await act.run(() => api.postIdempotent(`/transport/vehicles/${x.code}/state`, { to }), { success: (r) => r?.message || t('تم تغيير الحالة', 'State changed'), invalidate: ['transport'] });
}

const title = computed(() => (v.value ? `${v.value.code} — ${[v.value.brand, v.value.model, v.value.year].filter(Boolean).join(' ')}` : props.code));
const tabs = computed(() => [{ k: 'overview', label: { ar: 'نظرة عامة', en: 'Overview' } }, { k: 'docs', label: { ar: 'الوثائق', en: 'Documents' } }, { k: 'maint', label: { ar: 'الصيانة', en: 'Maintenance' }, badge: v.value?.openMaintenance?.length }, { k: 'fuel', label: { ar: 'الوقود', en: 'Fuel' } }, { k: 'trips', label: { ar: 'الرحلات', en: 'Trips' } }]);

const overview = computed(() => {
  const x = v.value;
  if (!x) return [];
  const days = docDaysLabel(x.docDays, lang.value);
  const alerts = x.alerts || [];
  return [
    { k: { ar: 'النوع / الصندوق', en: 'Type / box' }, v: `${(lang.value === 'en' && x.typeEn) || x.typeAr || ''} · ${bi(labelOf(VEHICLE_KIND_LABELS, x.kind))}` },
    { k: { ar: 'الملكية', en: 'Ownership' }, v: bi(labelOf(OWNERSHIP_LABELS, x.ownership)) },
    { k: { ar: 'السعة', en: 'Capacity' }, num: true, v: `${fmtNum(x.maxKg)} ${t('كجم', 'kg')} · ${fmtNum(x.maxCbm, 1)} ${t('م³', 'm³')} · ${fmtNum(x.pallets)} ${t('طبلية', 'pallets')}` },
    { k: { ar: 'المستودع', en: 'Warehouse' }, v: x.warehouse ? `${x.warehouse.code} · ${x.warehouse.nameAr}` : '—' },
    { k: { ar: 'العداد', en: 'Odometer' }, num: true, v: `${fmtNum(x.odometer)} ${t('كم', 'km')}` },
    { k: { ar: 'الوقود', en: 'Fuel' }, v: `${x.fuelType || '—'}${x.tankL ? ` · ${x.tankL} L` : ''} · ${fmtNum(x.avgKmL, 1)} ${t('كم/ل', 'km/L')}` },
    { k: { ar: 'الصيانة القادمة', en: 'Next maintenance' }, num: x.nextMaintKm != null, v: x.nextMaintKm != null ? `${fmtNum(x.nextMaintKm)} ${t('كم', 'km')} (${t('متبقٍ', 'remaining')} ${fmtNum(x.maintenanceDueKm)})` : x.nextMaintDate ? fmtDateOnly(x.nextMaintDate) : '—', c: x.maintenanceDueKm != null && x.maintenanceDueKm <= 3000 ? '#b26a16' : undefined },
    { k: { ar: 'الوثائق', en: 'Documents' }, v: days.text, c: days.color },
    { k: { ar: 'السائقون الافتراضيون', en: 'Default drivers' }, v: (x.drivers || []).map((d) => d.nameAr).join(' · ') || '—' },
    { id: 'trip', k: { ar: 'الرحلة الحالية', en: 'Active trip' }, v: '—' },
    { k: { ar: 'التنبيهات المفتوحة', en: 'Open alerts' }, v: alerts.length ? alerts.map((a) => (lang.value === 'en' && a.textEn) || a.textAr).join(' · ') : '—', c: alerts.length ? '#b23b3b' : undefined },
    { k: { ar: 'الموقع الحي', en: 'Live position' }, v: bi(PENDING), c: '#7d7990' },
    { k: { ar: 'إجمالي الرحلات', en: 'Total trips' }, num: true, v: fmtNum(x.tripsCount) },
  ];
});
const docs = computed(() => (v.value?.docs || []).map((d) => { const dl = docDaysLabel(d.days, lang.value); return { k: lang.value === 'en' ? d.labelEn || d.label : d.labelAr || d.label, v: `${fmtDateOnly(d.date || d.expiry)} · ${dl.text}`, c: dl.color }; }));

const fuelCols = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, kind: 'date', width: '90px', value: (f) => fmtDateOnly(f.date) },
  { key: 'driver', header: { ar: 'السائق', en: 'Driver' }, value: (f) => f.driver?.nameAr || '—' },
  { key: 'odometer', header: { ar: 'العداد', en: 'Odo' }, kind: 'num', width: '80px' }, { key: 'liters', header: { ar: 'لترات', en: 'Liters' }, kind: 'num', width: '70px' }, { key: 'cost', header: { ar: 'التكلفة', en: 'Cost' }, kind: 'num', width: '80px' },
  { key: 'kmPerL', header: 'KM/L', kind: 'num', width: '70px' },
];
const tripCols = [
  { key: 'number', header: { ar: 'الرحلة', en: 'Trip' }, kind: 'id', width: '110px' }, { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, kind: 'date', width: '90px', value: (r) => fmtDateOnly(r.date) },
  { key: 'routeAr', header: { ar: 'المسار', en: 'Route' } }, { key: 'driver', header: { ar: 'السائق', en: 'Driver' }, value: (r) => r.driver?.nameAr || '—' }, { key: 'km', header: { ar: 'كم', en: 'km' }, kind: 'num', width: '60px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '110px' },
];
</script>

<template>
  <Drawer :open="!!code" :width="520" :title="title" @close="emit('close')">
    <template v-if="v" #sub><span class="num" dir="ltr">{{ [v.plateAr, v.plateEn, v.vin].filter(Boolean).join(' · ') }}</span></template>
    <ErrorBanner :error="q.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value && !v" class="skel h-[120px]" />
    <template v-if="v">
      <div class="row wrap">
        <Chip :map="VEHICLE_LABELS" :k="v.state" />
        <span class="text-[9.5px] font-extrabold" :class="v.canAssign?.ok ? 'text-ok' : 'text-bad'">{{ v.canAssign?.ok ? t('قابلة للإسناد ✓', 'Assignable ✓') : `${t('غير قابلة للإسناد', 'Not assignable')} — ${whyNot(v.canAssign, lang)}` }}</span>
        <Chip v-if="v.gpsOnline" small fg="#1d7a3e" bg="#e6f9ec" label="GPS ✓" />
        <Chip v-else small :label="{ ar: 'GPS: Integration Pending', en: 'GPS: Integration Pending' }" />
      </div>
      <Tabs v-model="tab" variant="sm" :tabs="tabs" class="!mx-0 !mb-3 !mt-3.5" />

      <KvList v-if="tab === 'overview'" :rows="overview">
        <template #v-trip>
          <span v-if="v.activeTrip" class="cell-id cursor-pointer" @click="emit('open-trip', v.activeTrip.number)">{{ v.activeTrip.number }} · <span class="font-sans">{{ v.activeTrip.routeAr }}</span></span>
          <template v-else>—</template>
        </template>
      </KvList>
      <KvList v-else-if="tab === 'docs'" :rows="docs" />
      <div v-else-if="tab === 'maint'" class="col">
        <EmptyState v-if="(v.maintenance || []).length === 0" :text="{ ar: 'لا أوامر صيانة', en: 'No maintenance orders' }" />
        <div v-for="m in v.maintenance || []" :key="m.id" class="card sm px-[13px] py-2.5">
          <div class="row wrap"><span class="num font-bold">{{ m.number }}</span><Chip small :map="MAINT_KIND_LABELS" :k="m.kind" /><Chip small :map="MAINT_STATUS_LABELS" :k="m.status" /><div class="flex-1" /><span class="num text-[10px]">{{ fmtNum(m.cost) }} {{ t('ر.س', 'SAR') }}</span></div>
          <div class="mt-1 text-[10px] text-sec">{{ (lang === 'en' && m.descEn) || m.descAr || m.typeAr }} · {{ m.shop || '—' }} · <span class="num">{{ fmtDateOnly(m.startDate) }}{{ m.endDate ? ` ← ${fmtDateOnly(m.endDate)}` : '' }}</span></div>
        </div>
      </div>
      <DataTable v-else-if="tab === 'fuel'" :columns="fuelCols" :rows="v.fuelHistory || []" :row-key="(f) => f.id" dense :min-width="440" :empty-text="{ ar: 'لا سجلات وقود', en: 'No fuel records' }">
        <template #cell-kmPerL="{ row }"><span :class="row.anomaly ? 'text-bad' : 'text-ok'">{{ row.kmPerL != null ? fmtNum(row.kmPerL, 1) : '—' }}{{ row.anomaly ? ' ⚠' : '' }}</span></template>
      </DataTable>
      <DataTable v-else-if="tab === 'trips'" :columns="tripCols" :rows="v.recentTrips || []" :row-key="(r) => r.number" dense :min-width="520" :empty-text="{ ar: 'لا رحلات', en: 'No trips' }" @row-click="(r) => emit('open-trip', r.number)">
        <template #cell-status="{ row }"><Chip :map="TRIP_LABELS" :k="row.status" /></template>
      </DataTable>

      <template v-if="auth.can('vehicle.state')">
        <div class="mx-0.5 mb-2 mt-4 text-[10.5px] font-extrabold text-muted">{{ t('تغيير الحالة (وفق State Machine)', 'Change state (state machine)') }}</div>
        <div class="row wrap !gap-1.5">
          <Btn v-for="to in transitions" :key="to" size="sm" :tone="['breakdown', 'oos'].includes(to) ? 'dangerOutline' : 'outline'" :loading="act.pending.value" :label="labelOf(VEHICLE_LABELS, to)" @click="setState(to)" />
          <span v-if="!transitions.length" class="text-[9.5px] text-faint">{{ t('لا انتقالات متاحة من هذه الحالة', 'No transitions from this state') }}</span>
          <Btn size="sm" tone="danger" :label="{ ar: 'إبلاغ عن عطل', en: 'Report breakdown' }" @click="bd = true" />
        </div>
      </template>
    </template>
  </Drawer>
  <BreakdownForm :open="bd && !!v" :vehicle-code="v?.code" @close="bd = false" />
</template>
