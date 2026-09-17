<script setup>
// Driver drawer (520): overview · performance · documents (expiry warnings) · trips · violations, state + document block,
// record incident. GET /transport/drivers/:code.
//   <DriverDrawer :code="driver" initial-tab="perf" @close="driver = null" @open-trip="(n) => (trip = n)" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, EmptyState, ErrorBanner, Tabs } from '@/components';
import { bi, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { STOP_LABELS, TRIP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import IncidentForm from './IncidentForm.vue';
import KvList from './KvList.vue';
import { ALERT_SEV_LABELS, DRIVER_STATE_LABELS, INCIDENT_TYPE_LABELS, SHIFT_LABELS, delayLabel, docDaysLabel, drvName, labelOf, safetyColor, whyNot } from './tms';

const props = defineProps({
  code: { type: String, default: null },
  /** 'overview' | 'perf' | 'docs' | 'trips' | 'incidents' */
  initialTab: { type: String, default: 'overview' },
});
const emit = defineEmits(['close', 'open-trip']);

const auth = useAuth();
const act = useAction();
const tab = ref(props.initialTab);
const inc = ref(false);
watch(() => [props.code, props.initialTab], () => { tab.value = props.initialTab; inc.value = false; });

const q = useGet(() => (props.code ? `/transport/drivers/${encodeURIComponent(props.code)}` : null));
const d = computed(() => (props.code ? q.data.value : null));
const p = computed(() => d.value?.performance || {});

const setState = (to) => act.run(() => api.postIdempotent(`/transport/drivers/${d.value.code}/state`, { to }), { success: (r) => r?.message || t('تم تغيير الحالة', 'State changed'), invalidate: ['transport'] });
const setBlocked = (blocked) => act.run(() => api.patch(`/transport/drivers/${d.value.code}`, { blocked }), { success: (r) => r?.message || (blocked ? t('أُوقف السائق', 'Driver blocked') : t('رُفع الإيقاف', 'Driver unblocked')), invalidate: ['transport'] });
const nextStates = computed(() => ['available', 'off', 'inactive'].filter((s) => s !== d.value?.state));

const tabs = computed(() => [{ k: 'overview', label: { ar: 'نظرة عامة', en: 'Overview' } }, { k: 'perf', label: { ar: 'الأداء', en: 'Performance' } }, { k: 'docs', label: { ar: 'الوثائق', en: 'Documents' } }, { k: 'trips', label: { ar: 'الرحلات', en: 'Trips' } }, { k: 'incidents', label: { ar: 'المخالفات والحوادث', en: 'Violations' }, badge: d.value?.incidentsList?.length }]);

const overview = computed(() => {
  const x = d.value;
  if (!x) return [];
  const days = docDaysLabel(x.docDays, lang.value);
  const alerts = x.alerts || [];
  return [
    { k: { ar: 'الوردية', en: 'Shift' }, v: bi(labelOf(SHIFT_LABELS, x.shift, (lang.value === 'en' && x.shiftEn) || x.shift || '—')) },
    { k: { ar: 'الجنسية', en: 'Nationality' }, v: x.nationality || '—' },
    { k: { ar: 'الرخصة', en: 'License' }, v: `${x.licenseNo || '—'}${x.licenseType ? ` · ${x.licenseType}` : ''}` },
    { k: { ar: 'المركبة الافتراضية', en: 'Default vehicle' }, v: x.defaultVehicle ? `${x.defaultVehicle.code} · ${x.defaultVehicle.plateAr}` : '—' },
    { id: 'trip', k: { ar: 'الإسناد الحالي', en: 'Current assignment' }, v: t('لا رحلة نشطة', 'No active trip') },
    { k: { ar: 'تاريخ الالتحاق', en: 'Join date' }, num: true, v: fmtDateOnly(x.joinDate) },
    { k: { ar: 'حساب الدخول', en: 'Login' }, v: x.user ? `${x.user.username} · ${x.user.active ? t('نشط', 'active') : t('موقوف', 'inactive')}` : t('بدون حساب', 'No login') },
    { k: { ar: 'الوثائق', en: 'Documents' }, v: days.text, c: days.color },
    { k: { ar: 'التنبيهات المفتوحة', en: 'Open alerts' }, v: alerts.length ? alerts.map((a) => (lang.value === 'en' && a.textEn) || a.textAr).join(' · ') : '—', c: alerts.length ? '#b23b3b' : undefined },
  ];
});
const docs = computed(() => (d.value?.docs || []).map((x) => { const dl = docDaysLabel(x.days, lang.value); return { k: lang.value === 'en' ? x.labelEn || x.label : x.labelAr || x.label, v: `${fmtDateOnly(x.date || x.expiry)} · ${dl.text}`, c: dl.color }; }));

/** 12 performance tiles: value, label, optional colour. */
const perf = computed(() => {
  const x = p.value;
  return [
    { v: fmtNum(x.trips), l: t('رحلات', 'Trips') }, { v: fmtNum(x.deliveries), l: t('توصيلات', 'Deliveries') }, { v: fmtNum(x.fails), l: t('فشل', 'Failed'), c: x.fails ? '#b23b3b' : undefined },
    { v: `${fmtNum(x.okPct)}%`, l: t('نجاح', 'Success'), c: '#1d7a3e' }, { v: `${fmtNum(x.ontimePct)}%`, l: t('في الموعد', 'On-time'), c: '#0d7f93' }, { v: fmtNum(x.rating, 1), l: t('التقييم', 'Rating') },
    { v: fmtNum(x.safety), l: t('السلامة', 'Safety'), c: safetyColor(x.safety) }, { v: fmtNum(x.fuelScore), l: t('وقود', 'Fuel score') }, { v: fmtNum(x.km), l: t('كم', 'km') },
    { v: fmtNum(x.complaints), l: t('شكاوى', 'Complaints'), c: x.complaints ? '#b26a16' : undefined }, { v: fmtNum(x.incidents), l: t('حوادث', 'Incidents'), c: x.incidents ? '#b23b3b' : undefined }, { v: x.avgDelay || '—', l: t('متوسط التأخر', 'Avg delay') },
  ];
});
const podSummary = computed(() => Object.entries(p.value.pods || {}).map(([k, n]) => `${bi(labelOf(STOP_LABELS, k))} ${n}`).join(' · ') || '—');

const tripCols = [
  { key: 'number', header: { ar: 'الرحلة', en: 'Trip' }, kind: 'id', width: '110px' }, { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, kind: 'date', width: '90px', value: (r) => fmtDateOnly(r.date) },
  { key: 'routeAr', header: { ar: 'المسار', en: 'Route' } }, { key: 'vehicle', header: { ar: 'المركبة', en: 'Vehicle' }, width: '70px', value: (r) => r.vehicle?.code || '—' }, { key: 'stops', header: { ar: 'محطات', en: 'Stops' }, kind: 'num', width: '60px', value: (r) => r._count?.stops ?? '—' },
  { key: 'delayMin', header: { ar: 'تأخر', en: 'Delay' }, width: '80px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '110px' },
];
</script>

<template>
  <Drawer :open="!!code" :width="520" :title="d ? drvName(d, lang) : code" @close="emit('close')">
    <template v-if="d" #sub><span class="num" dir="ltr">{{ [d.code, d.employeeNo, d.mobile].filter(Boolean).join(' · ') }}</span></template>
    <ErrorBanner :error="q.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value && !d" class="skel h-[120px]" />
    <template v-if="d">
      <div class="row wrap">
        <Chip :map="DRIVER_STATE_LABELS" :k="d.blocked ? 'blocked' : d.state" />
        <span class="text-[9.5px] font-extrabold" :class="d.canAssign?.ok ? 'text-ok' : 'text-bad'">{{ d.canAssign?.ok ? t('قابل للإسناد ✓', 'Assignable ✓') : `${t('غير قابل للإسناد', 'Not assignable')} — ${whyNot(d.canAssign, lang)}` }}</span>
      </div>
      <Tabs v-model="tab" variant="sm" :tabs="tabs" class="!mx-0 !mb-3 !mt-3.5" />

      <KvList v-if="tab === 'overview'" :rows="overview">
        <template #v-trip="{ row }">
          <span v-if="d.activeTrip" class="cell-id cursor-pointer" @click="emit('open-trip', d.activeTrip.number)">{{ d.activeTrip.number }} · <span class="font-sans">{{ d.activeTrip.routeAr }}</span> · {{ d.activeTrip.vehicle?.code || '' }}</span>
          <template v-else>{{ row.v }}</template>
        </template>
      </KvList>
      <div v-else-if="tab === 'perf'" class="grid grid-cols-3 gap-2">
        <div v-for="x in perf" :key="x.l" class="tile text-center"><div class="tile-v !text-center" :style="{ color: x.c }">{{ x.v }}</div><div class="tile-l">{{ x.l }}</div></div>
        <div class="col-span-full text-[9.5px] text-muted">POD: {{ podSummary }}</div>
      </div>
      <KvList v-else-if="tab === 'docs'" :rows="docs" />
      <DataTable v-else-if="tab === 'trips'" :columns="tripCols" :rows="d.tripsList || []" :row-key="(r) => r.number" dense :min-width="560" :empty-text="{ ar: 'لا رحلات', en: 'No trips' }" @row-click="(r) => emit('open-trip', r.number)">
        <template #cell-delayMin="{ row }"><span class="text-[9.5px] font-extrabold" :class="row.delayMin ? 'text-warn' : 'text-ok'">{{ delayLabel(row.delayMin, lang) }}</span></template>
        <template #cell-status="{ row }"><Chip :map="TRIP_LABELS" :k="row.status" /></template>
      </DataTable>
      <div v-else-if="tab === 'incidents'" class="col">
        <EmptyState v-if="(d.incidentsList || []).length === 0" :text="{ ar: 'لا مخالفات أو حوادث مسجلة', en: 'No violations or incidents' }" />
        <div v-for="i in d.incidentsList || []" :key="i.id" class="card sm px-[13px] py-2.5">
          <div class="row wrap"><b class="text-[11px]">{{ bi(labelOf(INCIDENT_TYPE_LABELS, i.type)) }}</b><Chip small :map="ALERT_SEV_LABELS" :k="i.severity" /><div class="flex-1" /><span class="num text-[9.5px] text-muted">{{ fmtDateOnly(i.date) }}</span></div>
          <div class="mt-1 text-[10px] text-sec">{{ i.desc }}</div>
        </div>
        <Btn v-if="auth.can('driver.manage')" tone="dangerOutline" size="sm" :label="{ ar: '+ تسجيل حادثة / مخالفة', en: '+ Record incident' }" @click="inc = true" />
      </div>

      <template v-if="auth.can('driver.manage')">
        <div class="mx-0.5 mb-2 mt-4 text-[10.5px] font-extrabold text-muted">{{ t('الحالة والإيقاف', 'State & blocking') }}</div>
        <div class="row wrap !gap-1.5">
          <Btn v-for="s in nextStates" :key="s" size="sm" tone="outline" :loading="act.pending.value" :label="DRIVER_STATE_LABELS[s]" @click="setState(s)" />
          <Btn v-if="d.blocked" size="sm" tone="softGreen" :label="{ ar: 'رفع الإيقاف', en: 'Unblock' }" @click="setBlocked(false)" />
          <Btn v-else size="sm" tone="dangerOutline" :label="{ ar: 'إيقاف — وثائق', en: 'Block — docs' }" @click="setBlocked(true)" />
        </div>
      </template>
    </template>
  </Drawer>
  <IncidentForm :open="inc && !!d" :driver-code="d?.code" @close="inc = false" />
</template>
