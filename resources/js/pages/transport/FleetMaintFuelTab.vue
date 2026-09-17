<script setup>
// Fleet · maintenance & fuel tab: maintenance orders (open / closed / all, close → MaintenanceCloseModal) and the fuel log
// (optionally anomalies only). GET /transport/maintenance · GET /transport/fuel.
import { ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SectionCard } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import MaintenanceCloseModal from './MaintenanceCloseModal.vue';
import { MAINT_KIND_LABELS, MAINT_STATUS_LABELS, drvName } from './tms';

const props = defineProps({
  /** 'anomaly' pre-selects "anomalies only" on the fuel log. */
  filter: { type: String, default: '' },
});
const emit = defineEmits(['open-vehicle', 'new-maint', 'new-fuel']);

const auth = useAuth();
const mStatus = ref('open');
const mPage = ref(1);
const fPage = ref(1);
const anomaly = ref(props.filter === 'anomaly');
const closing = ref(null);
const maint = useList('/transport/maintenance', () => ({ status: mStatus.value || undefined, page: mPage.value, pageSize: 20 }));
const fuel = useList('/transport/fuel', () => ({ anomaly: anomaly.value ? 'true' : undefined, page: fPage.value, pageSize: 20 }));

const M_STATUS = [['open', { ar: 'مفتوحة', en: 'Open' }], ['closed', { ar: 'مقفلة', en: 'Closed' }], ['', { ar: 'الكل', en: 'All' }]];
const openVehicle = (code) => { if (code) emit('open-vehicle', code); };

const mCols = [
  { key: 'number', header: { ar: 'أمر الصيانة', en: 'Order' }, width: '120px', kind: 'id' },
  { key: 'vehicle', header: { ar: 'المركبة', en: 'Vehicle' }, width: '110px' },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '110px' },
  { key: 'desc', header: { ar: 'الوصف', en: 'Description' }, width: 'minmax(200px,1.5fr)' },
  { key: 'shop', header: { ar: 'الورشة', en: 'Workshop' }, width: '130px', kind: 'muted', value: (m) => m.shop || '—' },
  { key: 'dates', header: { ar: 'البدء ← الإنهاء', en: 'Start → end' }, width: '160px', kind: 'date', value: (m) => `${fmtDateOnly(m.startDate)}${m.endDate ? ` ← ${fmtDateOnly(m.endDate)}` : ''}` },
  { key: 'odometer', header: { ar: 'العداد', en: 'Odo' }, width: '80px', kind: 'num', value: (m) => fmtNum(m.odometer) },
  { key: 'cost', header: { ar: 'التكلفة ر.س', en: 'Cost SAR' }, width: '85px', kind: 'num', value: (m) => fmtNum(m.cost) },
  { key: 'parts', header: { ar: 'قطع', en: 'Parts' }, width: '70px', kind: 'num', value: (m) => fmtNum(m.parts) },
  { key: 'labor', header: { ar: 'عمالة', en: 'Labor' }, width: '70px', kind: 'num', value: (m) => fmtNum(m.labor) },
  { key: 'down', header: { ar: 'التوقف', en: 'Downtime' }, width: '80px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '90px' },
  { key: 'act', header: '', width: '90px' },
];
const fCols = [
  { key: 'vehicle', header: { ar: 'المركبة', en: 'Vehicle' }, width: '80px' },
  { key: 'driver', header: { ar: 'السائق', en: 'Driver' }, width: '130px', value: (f) => drvName(f.driver, lang.value) },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (f) => fmtDateOnly(f.date) },
  { key: 'odometer', header: { ar: 'العداد', en: 'Odo' }, width: '85px', kind: 'num', value: (f) => fmtNum(f.odometer) },
  { key: 'liters', header: { ar: 'لترات', en: 'Liters' }, width: '70px', kind: 'num', value: (f) => fmtNum(f.liters, 1) },
  { key: 'cost', header: { ar: 'التكلفة', en: 'Cost' }, width: '80px', kind: 'num', value: (f) => fmtNum(f.cost, 2) },
  { key: 'station', header: { ar: 'المحطة', en: 'Station' }, width: 'minmax(170px,1.3fr)', kind: 'muted', value: (f) => f.station || '—' },
  { key: 'full', header: { ar: 'التعبئة', en: 'Fill' }, width: '70px', value: (f) => (f.full ? t('كامل', 'Full') : t('جزئي', 'Partial')) },
  { key: 'kmPerL', header: 'KM/L', width: '80px', kind: 'num' },
  { key: 'costPerKm', header: { ar: 'ر.س/كم', en: 'SAR/km' }, width: '80px', kind: 'num', value: (f) => (f.costPerKm != null ? fmtNum(f.costPerKm, 2) : '—') },
  { key: 'anomaly', header: '', width: '90px' },
];
</script>

<template>
  <div class="col !gap-3.5">
    <SectionCard :padded="false" :title="{ ar: 'أوامر الصيانة', en: 'Maintenance orders' }" :count="maint.data.value?.total">
      <template #actions>
        <div class="row !gap-1.5">
          <div class="pill-bar !mb-0">
            <button v-for="[v, l] in M_STATUS" :key="v" type="button" class="pill" :class="{ active: mStatus === v }" @click="mStatus = v; mPage = 1">{{ t(l.ar, l.en) }}</button>
          </div>
          <Btn v-if="auth.can('maintenance.manage')" size="sm" :label="{ ar: '+ أمر صيانة', en: '+ Maintenance' }" @click="emit('new-maint')" />
        </div>
      </template>
      <ErrorBanner :error="maint.error.value" :closable="false" class="m-3" />
      <DataTable :columns="mCols" :paged="maint.data.value" :loading="maint.isLoading.value" :min-width="1300" :row-key="(m) => m.number" :empty-text="{ ar: 'لا أوامر صيانة', en: 'No maintenance orders' }" @page="mPage = $event">
        <template #cell-vehicle="{ row }"><span class="cell-id cursor-pointer" @click.stop="openVehicle(row.vehicle?.code)">{{ row.vehicle?.code || '—' }}</span></template>
        <template #cell-kind="{ row }"><Chip small :map="MAINT_KIND_LABELS" :k="row.kind" /></template>
        <template #cell-desc="{ row }"><span class="text-[10.5px]">{{ (lang === 'en' && row.descEn) || row.descAr || row.typeAr || '—' }}</span></template>
        <template #cell-down="{ row }"><b class="text-[10px] text-warn">{{ row.downLabel || (row.downDays != null ? `${row.downDays} ${t('يوم', 'd')}` : '—') }}</b></template>
        <template #cell-status="{ row }"><Chip :map="MAINT_STATUS_LABELS" :k="row.status" /></template>
        <template #cell-act="{ row }"><Btn v-if="row.status === 'open' && auth.can('maintenance.manage')" size="sm" tone="softGreen" :label="{ ar: 'إقفال', en: 'Close' }" @click.stop="closing = row" /></template>
      </DataTable>
    </SectionCard>

    <SectionCard :padded="false" :title="{ ar: 'سجل الوقود', en: 'Fuel log' }" :count="fuel.data.value?.total">
      <template #actions>
        <div class="row !gap-1.5">
          <button type="button" class="pill" :class="{ active: anomaly }" @click="anomaly = !anomaly; fPage = 1">{{ t('الانحرافات فقط', 'Anomalies only') }}</button>
          <Btn v-if="auth.can('fuel.manage')" size="sm" :label="{ ar: '+ تعبئة وقود', en: '+ Fuel' }" @click="emit('new-fuel')" />
        </div>
      </template>
      <ErrorBanner :error="fuel.error.value" :closable="false" class="m-3" />
      <DataTable :columns="fCols" :paged="fuel.data.value" :loading="fuel.isLoading.value" :min-width="1000" :row-key="(f) => f.id" :empty-text="{ ar: 'لا سجلات وقود', en: 'No fuel records' }" @page="fPage = $event">
        <template #cell-vehicle="{ row }"><span class="cell-id cursor-pointer" @click="openVehicle(row.vehicle?.code)">{{ row.vehicle?.code || '—' }}</span></template>
        <template #cell-kmPerL="{ row }"><b :class="row.anomaly ? 'text-bad' : 'text-ok'">{{ row.kmPerL != null ? fmtNum(row.kmPerL, 1) : '—' }}</b></template>
        <template #cell-anomaly="{ row }"><Chip v-if="row.anomaly" small fg="#b23b3b" bg="#fdecec" :label="{ ar: 'انحراف', en: 'Anomaly' }" /></template>
      </DataTable>
    </SectionCard>
    <MaintenanceCloseModal :order="closing" @close="closing = null" />
  </div>
</template>
