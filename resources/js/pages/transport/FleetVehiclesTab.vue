<script setup>
// Fleet · vehicles tab: search + state / box type / warehouse filters → GET /transport/vehicles (polled every 30s).
// Document-expiry and next-maintenance warnings per row; GPS is "Integration Pending" (only the device id is real).
import { ref } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { bi, fmtNum, lang, t } from '@/i18n';
import { OWNERSHIP_LABELS, VEHICLE_LABELS } from '@/shared';
import { PENDING, VEHICLE_KIND_LABELS, docDaysLabel, labelOf, optsOf } from './tms';
import { useWarehouseOptions } from './tmsComposables';

const props = defineProps({
  /** Initial state filter from the page query (?f=available | loading,ready …). */
  filter: { type: String, default: '' },
  initialQ: { type: String, default: '' },
});
const emit = defineEmits(['open']);

const q = ref(props.initialQ);
const state = ref(props.filter);
const kind = ref('');
const warehouse = ref('');
const page = ref(1);
const whOpts = useWarehouseOptions();
const list = useList('/transport/vehicles', () => ({ q: q.value.trim() || undefined, state: state.value || undefined, kind: kind.value || undefined, warehouse: warehouse.value || undefined, page: page.value, pageSize: 25 }), { refetchInterval: 30_000 });
const reset = () => { page.value = 1; };

const stateOpts = optsOf(VEHICLE_LABELS);
const kindOpts = optsOf(VEHICLE_KIND_LABELS);
/** Km left until the next maintenance (null when not planned by km). */
const remainKm = (v) => (v.nextMaintKm != null ? v.nextMaintKm - (v.odometer || 0) : null);
const remainColor = (rem) => (rem <= 0 ? '#b23b3b' : rem <= 3000 ? '#b26a16' : '#1d7a3e');

const columns = [
  { key: 'code', header: { ar: 'المركبة', en: 'Vehicle' }, width: '120px' },
  { key: 'type', header: { ar: 'النوع / الملكية', en: 'Type / ownership' }, width: 'minmax(140px,1.1fr)' },
  { key: 'cap', header: { ar: 'السعة', en: 'Capacity' }, width: 'minmax(150px,1.2fr)' },
  { key: 'wh', header: { ar: 'المستودع', en: 'WH' }, width: '90px', kind: 'muted', value: (v) => v.warehouse?.code || '—' },
  { key: 'odometer', header: { ar: 'العداد كم', en: 'Odometer' }, width: '90px', kind: 'num', value: (v) => fmtNum(v.odometer) },
  { key: 'docs', header: { ar: 'الوثائق', en: 'Documents' }, width: '100px' },
  { key: 'maint', header: { ar: 'الصيانة القادمة', en: 'Next maint.' }, width: '120px' },
  { key: 'gps', header: 'GPS', width: '110px' },
  { key: 'alerts', header: { ar: 'تنبيهات', en: 'Alerts' }, width: '70px', kind: 'num' },
  { key: 'state', header: { ar: 'الحالة', en: 'Status' }, width: '130px' },
];
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput v-model="q" small class="w-60" :placeholder="{ ar: 'بحث برقم المركبة / اللوحة / الماركة', en: 'Search code / plate / brand' }" @update:model-value="reset" />
      <SelectInput v-model="state" small class="w-40" :placeholder="{ ar: '— كل الحالات —', en: '— all states —' }" :options="stateOpts" @update:model-value="reset" />
      <SelectInput v-model="kind" small class="w-[150px]" :placeholder="{ ar: '— كل الأنواع —', en: '— all types —' }" :options="kindOpts" @update:model-value="reset" />
      <SelectInput v-model="warehouse" small class="w-[180px]" :placeholder="{ ar: 'كل المستودعات', en: 'All warehouses' }" :options="whOpts" @update:model-value="reset" />
      <div class="grow" />
      <div class="text-[10.5px] font-extrabold text-muted"><span class="num">{{ list.data.value?.total ?? '—' }}</span> {{ t('مركبة', 'vehicles') }}</div>
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="1240" :row-key="(v) => v.code" :empty-text="{ ar: 'لا مركبات', en: 'No vehicles' }" @page="page = $event" @row-click="(v) => emit('open', v.code)">
        <template #cell-code="{ row }">
          <div><div class="num text-[11px] font-bold text-violet">{{ row.code }}</div><div class="cell-sub" dir="ltr">{{ row.plateAr }}{{ row.plateEn ? ` · ${row.plateEn}` : '' }}</div></div>
        </template>
        <template #cell-type="{ row }">
          <div>
            <div class="text-[10.5px] font-extrabold">{{ (lang === 'en' && row.typeEn) || row.typeAr || bi(labelOf(VEHICLE_KIND_LABELS, row.kind)) }}</div>
            <div class="cell-sub">{{ [row.brand, row.model].filter(Boolean).join(' ') }} · <span class="font-sans">{{ bi(labelOf(OWNERSHIP_LABELS, row.ownership)) }}</span></div>
          </div>
        </template>
        <template #cell-cap="{ row }"><span class="num text-[10px]">{{ fmtNum(row.maxKg) }} {{ t('كجم', 'kg') }} · {{ fmtNum(row.maxCbm, 1) }} {{ t('م³', 'm³') }} · {{ fmtNum(row.pallets) }} {{ t('طبلية', 'plt') }}</span></template>
        <template #cell-docs="{ row }"><b class="text-[10px]" :style="{ color: docDaysLabel(row.docDays, lang).color }">{{ docDaysLabel(row.docDays, lang).text }}</b></template>
        <template #cell-maint="{ row }">
          <span v-if="remainKm(row) == null" class="muted">—</span>
          <b v-else class="num text-[10px]" :style="{ color: remainColor(remainKm(row)) }">{{ fmtNum(remainKm(row)) }} {{ t('كم', 'km') }}</b>
        </template>
        <template #cell-gps="{ row }"><span class="text-[9px] font-extrabold" :class="row.gpsDeviceId ? 'text-muted' : 'text-faint'">{{ row.gpsDeviceId ? `${row.gpsDeviceId} · ` : '' }}{{ bi(PENDING) }}</span></template>
        <template #cell-alerts="{ row }"><span class="font-extrabold" :class="row.openAlerts ? 'text-bad' : 'text-faint'">{{ row.openAlerts || 0 }}</span></template>
        <template #cell-state="{ row }"><Chip :map="VEHICLE_LABELS" :k="row.state" dot /></template>
      </DataTable>
    </div>
  </div>
</template>
