<script setup>
// Fleet · drivers tab: search + state filter + "blocked — docs" toggle → GET /transport/drivers (polled every 30s).
// License / Iqama expiry warnings and performance figures per row.
import { ref } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { bi, fmtNum, lang, t } from '@/i18n';
import { DRIVER_STATE_LABELS, SHIFT_LABELS, docDaysLabel, drvName, labelOf, safetyColor } from './tms';

const PAGE_SIZE = 25;
const props = defineProps({
  /** Initial filter from the page query (?f=available | onroute | blocked). */
  filter: { type: String, default: '' },
  initialQ: { type: String, default: '' },
});
const emit = defineEmits(['open']);

const q = ref(props.initialQ);
const state = ref(props.filter === 'blocked' ? '' : props.filter);
const blocked = ref(props.filter === 'blocked');
const page = ref(1);
const list = useList('/transport/drivers', () => ({ q: q.value.trim() || undefined, state: state.value || undefined, blocked: blocked.value ? 'true' : undefined, page: page.value, pageSize: PAGE_SIZE }), { refetchInterval: 30_000 });
const reset = () => { page.value = 1; };

const stateOpts = ['available', 'loading', 'onroute', 'off', 'inactive'].map((v) => [v, DRIVER_STATE_LABELS[v]]);
/** Expiry label of one document ('license' | 'iqama'); falls back to the driver's overall document days. */
function doc(d, key) {
  const x = (d.docs || []).find((z) => z.key === key || z.k === key || String(z.key || '').toLowerCase().startsWith(key) || String(z.label || '').toLowerCase().includes(key));
  return docDaysLabel(x ? x.days : d.docDays, lang.value);
}

const columns = [
  { key: 'rank', header: '#', width: '32px', kind: 'num' },
  { key: 'name', header: { ar: 'السائق', en: 'Driver' }, width: 'minmax(160px,1.3fr)' },
  { key: 'shift', header: { ar: 'الوردية', en: 'Shift' }, width: '120px', kind: 'muted', value: (d) => bi(labelOf(SHIFT_LABELS, d.shift, (lang.value === 'en' && d.shiftEn) || d.shift || '—')) },
  { key: 'veh', header: { ar: 'المركبة', en: 'Vehicle' }, width: '70px' },
  { key: 'lic', header: { ar: 'الرخصة', en: 'License' }, width: '100px' },
  { key: 'iq', header: { ar: 'الإقامة', en: 'Iqama' }, width: '100px' },
  { key: 'deliveries', header: { ar: 'توصيلات', en: 'Deliveries' }, width: '75px', kind: 'num', value: (d) => fmtNum(d.deliveries) },
  { key: 'okPct', header: { ar: 'نجاح', en: 'Success' }, width: '70px', kind: 'num' },
  { key: 'ontimePct', header: { ar: 'في الموعد', en: 'On-time' }, width: '75px', kind: 'num' },
  { key: 'rating', header: { ar: 'التقييم', en: 'Rating' }, width: '65px', kind: 'num', value: (d) => fmtNum(d.rating, 1) },
  { key: 'safety', header: { ar: 'السلامة', en: 'Safety' }, width: '65px', kind: 'num' },
  { key: 'state', header: { ar: 'الحالة', en: 'Status' }, width: '140px' },
];
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput v-model="q" small class="w-60" :placeholder="{ ar: 'بحث بالاسم / الرقم / الجوال', en: 'Search name / code / mobile' }" @update:model-value="reset" />
      <SelectInput v-model="state" small class="w-40" :placeholder="{ ar: '— كل الحالات —', en: '— all states —' }" :options="stateOpts" @update:model-value="reset" />
      <button type="button" class="pill" :class="{ active: blocked }" @click="blocked = !blocked; reset()">{{ t('موقوفون — وثائق', 'Blocked — docs') }}</button>
      <div class="grow" />
      <div class="text-[10.5px] font-extrabold text-muted"><span class="num">{{ list.data.value?.total ?? '—' }}</span> {{ t('سائق', 'drivers') }}</div>
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="1200" :row-key="(d) => d.code" :empty-text="{ ar: 'لا سائقون', en: 'No drivers' }" @page="page = $event" @row-click="(d) => emit('open', d.code)">
        <template #cell-rank="{ index }"><span class="muted">{{ (page - 1) * PAGE_SIZE + index + 1 }}</span></template>
        <template #cell-name="{ row }">
          <div><div class="text-[11px] font-extrabold">{{ drvName(row, lang) }}</div><div class="cell-sub num" dir="ltr">{{ [row.code, row.employeeNo, row.mobile].filter(Boolean).join(' · ') }}</div></div>
        </template>
        <template #cell-veh="{ row }"><span class="num font-bold text-violet">{{ row.defaultVehicle?.code || row.activeTrip?.vehicle?.code || '—' }}</span></template>
        <template #cell-lic="{ row }"><b class="text-[10px]" :style="{ color: doc(row, 'license').color }">{{ doc(row, 'license').text }}</b></template>
        <template #cell-iq="{ row }"><b class="text-[10px]" :style="{ color: doc(row, 'iqama').color }">{{ doc(row, 'iqama').text }}</b></template>
        <template #cell-okPct="{ row }"><b class="text-ok">{{ fmtNum(row.okPct) }}%</b></template>
        <template #cell-ontimePct="{ row }"><b class="text-brand-dark">{{ fmtNum(row.ontimePct) }}%</b></template>
        <template #cell-safety="{ row }"><b :style="{ color: safetyColor(row.safety) }">{{ fmtNum(row.safety) }}</b></template>
        <template #cell-state="{ row }"><Chip :map="DRIVER_STATE_LABELS" :k="row.blocked ? 'blocked' : row.state" /></template>
      </DataTable>
    </div>
  </div>
</template>
