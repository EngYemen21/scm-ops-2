<script setup>
// Fleet · maintenance & fuel tab: maintenance orders (open / closed / cancelled / all) and the fuel log (live / voided /
// all, optionally anomalies only). GET /transport/maintenance · GET /transport/fuel.
// Row actions — nothing is ever deleted:
//   maintenance (open orders): close → MaintenanceCloseModal · edit → MaintenanceForm (PATCH) · cancel with a reason → POST …/cancel
//   fuel (live records):       correct → FuelForm (PATCH, reason mandatory) · void with a reason → POST …/void
// Cancelled orders and voided fills stay listed, struck through; the fuel totals never count a voided fill.
import { ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SectionCard } from '@/components';
import { bi, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import { FUEL_STATUS_PILLS, MAINT_STATE_LABELS, MAINT_STATUS_PILLS } from './fleet';
import FuelForm from './FuelForm.vue';
import MaintenanceCloseModal from './MaintenanceCloseModal.vue';
import MaintenanceForm from './MaintenanceForm.vue';
import { MAINT_KIND_LABELS, drvName } from './tms';

const props = defineProps({
  /** 'anomaly' pre-selects "anomalies only" on the fuel log. */
  filter: { type: String, default: '' },
});
const emit = defineEmits(['open-vehicle', 'open-trip', 'new-maint', 'new-fuel']);

const auth = useAuth();
const act = useAction();
const mStatus = ref('open');
const mPage = ref(1);
const fStatus = ref('');
const fPage = ref(1);
const anomaly = ref(props.filter === 'anomaly');
const closing = ref(null);
const editingOrder = ref(null);
const fixingFuel = ref(null);
const maint = useList('/transport/maintenance', () => ({ status: mStatus.value || undefined, page: mPage.value, pageSize: 20 }));
const fuel = useList('/transport/fuel', () => ({ anomaly: anomaly.value ? 'true' : undefined, status: fStatus.value || undefined, page: fPage.value, pageSize: 20 }));

const canMaint = () => auth.can('maintenance.manage');
const canFuel = () => auth.can('fuel.manage');
const openVehicle = (code) => { if (code) emit('open-vehicle', code); };
const struck = (off) => (off ? { textDecoration: 'line-through', opacity: 0.55 } : null);

async function cancelOrder(m) {
  const reason = await ask({
    title: { ar: `إلغاء أمر الصيانة ${m.number}؟`, en: `Cancel maintenance order ${m.number}?` },
    sub: { ar: 'للأوامر التي فُتحت بالخطأ أو لن تُنفَّذ. لا يُرحَّل شيء، وتعود المركبة «متاحة» إن لم يبقَ لها أمر مفتوح. لا يمكن التراجع.', en: 'For orders opened by mistake or that will not be done. Nothing is posted, and the vehicle becomes available again when it has no other open order. This cannot be undone.' },
    label: { ar: 'سبب الإلغاء', en: 'Cancellation reason' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء الأمر', en: 'Cancel order' }, cancelLabel: { ar: 'تراجع', en: 'Back' },
  });
  if (!reason) return;
  await act.run(() => api.postIdempotent(`/transport/maintenance/${m.number}/cancel`, { reason }), { success: (r) => t(r?.message, r?.messageEn || r?.message) || t('أُلغي أمر الصيانة', 'Maintenance order cancelled'), invalidate: ['transport'] });
}
async function voidFuel(f) {
  const reason = await ask({
    title: { ar: `إلغاء سجل الوقود ${f.number}؟`, en: `Void fuel record ${f.number}?` },
    sub: { ar: 'للتعبئة المسجلة مرتين أو على مركبة خاطئة. يُعكس قيدها المحاسبي ويبقى السجل مشطوبًا، وتخرج من الإجماليات والمتوسطات وتكلفة الرحلة. لتعديل رقم خاطئ استخدم «تصحيح». لا يمكن التراجع.', en: 'For a fill entered twice or on the wrong vehicle. Its journal is reversed and the record stays, struck through, out of every total, average and trip cost. To fix a wrong figure use "Correct". This cannot be undone.' },
    label: { ar: 'سبب الإلغاء', en: 'Void reason' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء السجل', en: 'Void record' }, cancelLabel: { ar: 'تراجع', en: 'Back' },
  });
  if (!reason) return;
  await act.run(() => api.postIdempotent(`/transport/fuel/${f.number}/void`, { reason }), { success: (r) => t(r?.message, r?.messageEn || r?.message) || t('أُلغي سجل الوقود', 'Fuel record voided'), invalidate: ['transport', 'finance'] });
}

const mCols = [
  { key: 'number', header: { ar: 'أمر الصيانة', en: 'Order' }, width: '100px', kind: 'id' },
  { key: 'vehicle', header: { ar: 'المركبة', en: 'Vehicle' }, width: '70px' },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '88px' },
  { key: 'desc', header: { ar: 'الوصف', en: 'Description' }, width: 'minmax(160px,1.5fr)' },
  { key: 'shop', header: { ar: 'الورشة', en: 'Workshop' }, width: 'minmax(100px,1fr)', kind: 'muted', value: (m) => m.shop || t('لم تُحدَّد', 'not set') },
  { key: 'dates', header: { ar: 'البدء ← الإنهاء', en: 'Start → end' }, width: '120px', kind: 'date', value: (m) => `${fmtDateOnly(m.startDate)}${m.endDate ? ` ← ${fmtDateOnly(m.endDate)}` : ''}` },
  { key: 'odometer', header: { ar: 'العداد', en: 'Odo' }, width: '68px', kind: 'num', value: (m) => fmtNum(m.odometer) },
  { key: 'cost', header: { ar: 'التكلفة ر.س', en: 'Cost SAR' }, width: '104px' },
  { key: 'down', header: { ar: 'التوقف', en: 'Downtime' }, width: '70px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '84px', bare: true },
  { key: 'act', header: '', width: '184px', bare: true },
];
const fCols = [
  { key: 'vehicle', header: { ar: 'المركبة', en: 'Vehicle' }, width: '80px' },
  { key: 'driver', header: { ar: 'السائق', en: 'Driver' }, width: '120px', value: (f) => drvName(f.driver, lang.value) },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (f) => fmtDateOnly(f.date) },
  { key: 'odometer', header: { ar: 'العداد', en: 'Odo' }, width: '80px', kind: 'num', value: (f) => fmtNum(f.odometer) },
  { key: 'liters', header: { ar: 'لترات', en: 'Liters' }, width: '84px' },
  { key: 'cost', header: { ar: 'التكلفة', en: 'Cost' }, width: '80px', kind: 'num', value: (f) => fmtNum(f.cost, 2) },
  { key: 'station', header: { ar: 'المحطة', en: 'Station' }, width: 'minmax(140px,1.3fr)', kind: 'muted', value: (f) => f.station || '—' },
  { key: 'trip', header: { ar: 'الرحلة', en: 'Trip' }, width: '112px' },
  { key: 'kmPerL', header: 'KM/L', width: '76px' },
  { key: 'costPerKm', header: { ar: 'ر.س/كم', en: 'SAR/km' }, width: '72px', kind: 'num', value: (f) => (f.costPerKm != null ? fmtNum(f.costPerKm, 2) : '—') },
  { key: 'act', header: '', width: '186px', bare: true },
];
</script>

<template>
  <div class="col !gap-3.5">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <SectionCard :padded="false" :title="{ ar: 'أوامر الصيانة', en: 'Maintenance orders' }" :count="maint.data.value?.total">
      <template #actions>
        <div class="row !gap-1.5">
          <div class="pill-bar !mb-0">
            <button v-for="[v, l] in MAINT_STATUS_PILLS" :key="v" type="button" class="pill" :class="{ active: mStatus === v }" @click="mStatus = v; mPage = 1">{{ bi(l) }}</button>
          </div>
          <Btn v-if="canMaint()" size="sm" :label="{ ar: '+ أمر صيانة', en: '+ Maintenance' }" @click="emit('new-maint')" />
        </div>
      </template>
      <ErrorBanner :error="maint.error.value" :closable="false" class="m-3" />
      <DataTable :columns="mCols" :paged="maint.data.value" :loading="maint.isLoading.value" :row-key="(m) => m.number" :row-style="(m) => struck(m.status === 'cancelled')" :empty-text="{ ar: 'لا أوامر صيانة', en: 'No maintenance orders' }" @page="mPage = $event">
        <template #cell-vehicle="{ row }"><span class="cell-id cursor-pointer" @click.stop="openVehicle(row.vehicle?.code)">{{ row.vehicle?.code || '—' }}</span></template>
        <template #cell-kind="{ row }"><Chip small :map="MAINT_KIND_LABELS" :k="row.kind" /></template>
        <template #cell-desc="{ row }"><span class="text-[10.5px]" :title="row.cancelReason ? `${t('سبب الإلغاء', 'Cancelled')}: ${row.cancelReason}` : undefined">{{ (lang === 'en' && row.descEn) || row.descAr || row.typeAr || '—' }}</span></template>
        <template #cell-cost="{ row }">
          <span class="num">{{ fmtNum(row.cost) }}</span>
          <span v-if="Number(row.parts) || Number(row.labor)" class="num ms-1 text-[9px] text-faint" :title="t('قطع الغيار + العمالة', 'Parts + labor')">({{ fmtNum(row.parts) }} + {{ fmtNum(row.labor) }})</span>
          <span v-else-if="row.status === 'open'" class="ms-1 text-[9px] text-faint">{{ t('تقديرية', 'est.') }}</span>
        </template>
        <template #cell-down="{ row }"><b class="text-[10px] text-warn">{{ row.downLabel || (row.downDays != null ? `${row.downDays} ${t('يوم', 'd')}` : '—') }}</b></template>
        <template #cell-status="{ row }"><Chip :map="MAINT_STATE_LABELS" :k="row.status" :title="row.cancelReason || undefined" /></template>
        <template #cell-act="{ row }">
          <div v-if="row.status === 'open' && canMaint()" class="row !gap-1 justify-center">
            <Btn size="sm" tone="softGreen" :label="{ ar: 'إقفال', en: 'Close' }" @click.stop="closing = row" />
            <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="editingOrder = row" />
            <Btn size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click.stop="cancelOrder(row)" />
          </div>
          <span v-else-if="row.status === 'cancelled'" class="text-[9.5px] text-faint" :title="row.cancelReason">{{ row.cancelledBy || '' }}</span>
        </template>
      </DataTable>
    </SectionCard>

    <SectionCard :padded="false" :title="{ ar: 'سجل الوقود', en: 'Fuel log' }" :count="fuel.data.value?.total">
      <template #actions>
        <div class="row !gap-1.5">
          <div class="pill-bar !mb-0">
            <button v-for="[v, l] in FUEL_STATUS_PILLS" :key="v" type="button" class="pill" :class="{ active: fStatus === v }" @click="fStatus = v; fPage = 1">{{ bi(l) }}</button>
          </div>
          <button type="button" class="pill" :class="{ active: anomaly }" @click="anomaly = !anomaly; fPage = 1">{{ t('الانحرافات فقط', 'Anomalies only') }}</button>
          <Btn v-if="canFuel()" size="sm" :label="{ ar: '+ تعبئة وقود', en: '+ Fuel' }" @click="emit('new-fuel')" />
        </div>
      </template>
      <ErrorBanner :error="fuel.error.value" :closable="false" class="m-3" />
      <div v-if="fuel.data.value?.totals" class="row wrap !gap-3 border-b border-line-2 px-[15px] py-2 text-[10px] text-sec">
        <span>{{ t('إجمالي الفلتر (بدون الملغاة):', 'Filter totals (voided excluded):') }}</span>
        <b class="num">{{ fmtNum(fuel.data.value.totals.records) }} {{ t('سجل', 'records') }}</b>
        <b class="num">{{ fmtNum(fuel.data.value.totals.liters, 1) }} {{ t('لتر', 'L') }}</b>
        <b class="num">{{ fmtNum(fuel.data.value.totals.cost, 2) }} {{ t('ر.س', 'SAR') }}</b>
        <span v-if="fuel.data.value.totals.voided" class="text-faint">{{ t('ملغاة:', 'Voided:') }} <span class="num">{{ fmtNum(fuel.data.value.totals.voided) }}</span></span>
      </div>
      <DataTable :columns="fCols" :paged="fuel.data.value" :loading="fuel.isLoading.value" :row-key="(f) => f.id" :row-style="(f) => struck(!!f.voidedAt)" :empty-text="{ ar: 'لا سجلات وقود', en: 'No fuel records' }" @page="fPage = $event">
        <template #cell-vehicle="{ row }"><span class="cell-id cursor-pointer" @click="openVehicle(row.vehicle?.code)">{{ row.vehicle?.code || '—' }}</span></template>
        <template #cell-liters="{ row }">
          <span class="num">{{ Number(row.liters) > 0 ? fmtNum(row.liters, 1) : '—' }}</span>
          <span v-if="!row.full" class="ms-1 text-[9px] text-faint" :title="Number(row.liters) > 0 ? undefined : t('لم تُسجَّل اللترات — لا تدخل في متوسط الاستهلاك', 'No litres recorded — outside the consumption average')">{{ t('جزئي', 'partial') }}</span>
        </template>
        <template #cell-trip="{ row }">
          <span v-if="row.tripNumber" class="cell-id cursor-pointer" @click.stop="emit('open-trip', row.tripNumber)">{{ row.tripNumber }}</span>
          <template v-else>—</template>
        </template>
        <template #cell-kmPerL="{ row }">
          <b v-if="row.kmPerL != null" class="num" :class="row.anomaly && !row.voidedAt ? 'text-bad' : 'text-ok'" :title="row.anomaly ? t('انحراف عن متوسط المركبة', 'Below the vehicle average') : undefined">{{ fmtNum(row.kmPerL, 1) }}{{ row.anomaly && !row.voidedAt ? ' ⚠' : '' }}</b>
          <template v-else>—</template>
        </template>
        <template #cell-act="{ row }">
          <div class="row !gap-1 justify-center">
            <Chip v-if="row.voidedAt" small fg="#7d7990" bg="#F1EFF6" :label="{ ar: 'ملغى', en: 'Voided' }" :title="`${row.voidedBy || ''} — ${row.voidReason || ''}`" />
            <template v-else>
              <Chip v-if="row.correctedAt" small fg="#3C79F5" bg="#e8effe" :label="{ ar: 'مُصحَّح', en: 'Corrected' }" :title="`${row.correctedBy || ''} — ${row.correctionNote || ''}`" />
              <template v-if="canFuel()">
                <Btn size="sm" tone="soft" :label="{ ar: 'تصحيح', en: 'Correct' }" @click.stop="fixingFuel = row" />
                <Btn size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'إلغاء', en: 'Void' }" @click.stop="voidFuel(row)" />
              </template>
            </template>
          </div>
        </template>
      </DataTable>
    </SectionCard>
    <MaintenanceCloseModal :order="closing" @close="closing = null" />
    <MaintenanceForm :open="!!editingOrder" :order="editingOrder" @close="editingOrder = null" />
    <FuelForm :open="!!fixingFuel" :record="fixingFuel" @close="fixingFuel = null" />
  </div>
</template>
