<script setup>
// Trip room · "PODs": proofs of delivery of the trip (GET /delivery/pods?trip=…). Each row shows what the proof holds:
// the scan (or the reason there was none), the GPS that was captured, and the stored signature / photo — a click opens them.
import { useList } from '@/api/client';
import { Chip, DataTable, SectionCard } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { STOP_LABELS } from '@/shared';
import PodFiles from './PodFiles.vue';
import { NO_SCAN_REASON_LABELS, labelOf } from './tms';

const props = defineProps({ trip: { type: Object, required: true } });
const pods = useList('/delivery/pods', () => ({ trip: props.trip.number, pageSize: 50 }));

const gpsText = (p) => (p.gpsStatus === 'captured' ? `✓ ${p.gpsLat?.toFixed?.(4) ?? ''},${p.gpsLng?.toFixed?.(4) ?? ''}` : p.gpsStatus || '—');
const scanText = (p) => (p.scanOk === true ? t('مسح ✓', 'Scanned ✓') : p.noScanReason ? `${t('بلا مسح', 'No scan')}: ${bi(labelOf(NO_SCAN_REASON_LABELS, p.noScanReason))}` : '—');
const columns = [
  { key: 'number', header: 'POD', kind: 'id', width: '120px' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, kind: 'name', value: (p) => p.customer?.nameAr || '—' },
  { key: 'fo', header: 'FO', kind: 'id', width: '110px', value: (p) => p.fo?.number || '—' },
  { key: 'result', header: { ar: 'النتيجة', en: 'Result' }, width: '110px' },
  { key: 'receiverName', header: { ar: 'المستلم', en: 'Receiver' } },
  { key: 'deliveredQty', header: { ar: 'مسلّم / مرتجع', en: 'Delivered / returned' }, kind: 'num', width: '110px', value: (p) => `${p.deliveredQty ?? '—'} / ${p.returnedQty ?? 0}` },
  { key: 'scan', header: { ar: 'مسح الطلب', en: 'Order scan' }, width: '130px' },
  { key: 'gpsStatus', header: 'GPS', width: '90px', value: gpsText },
  { key: 'att', header: { ar: 'التوقيع / الصورة', en: 'Signature / photo' }, width: '170px', bare: true },
  { key: 'at', header: { ar: 'الوقت', en: 'Time' }, kind: 'date', width: '120px', value: (p) => fmtDate(p.at) },
];
</script>

<template>
  <SectionCard small :padded="false">
    <DataTable :columns="columns" :paged="pods.data.value" :loading="pods.isLoading.value" :row-key="(p) => p.id" :empty-text="{ ar: 'لا إثباتات تسليم بعد', en: 'No PODs yet' }">
      <template #cell-result="{ row }"><Chip :map="STOP_LABELS" :k="row.result" /></template>
      <template #cell-scan="{ row }"><span class="text-[9.5px] font-bold" :class="row.scanOk === true ? 'text-ok' : row.noScanReason ? 'text-warn' : 'text-faint'">{{ scanText(row) }}</span></template>
      <template #cell-att="{ row }"><PodFiles :attachments="row.attachments || []" dense /></template>
    </DataTable>
    <div class="px-3.5 py-2 text-[9.5px] text-faint">{{ t('التوقيع وصورة التسليم يُحفظان مع الإثبات (في التخزين السحابي إن كان مربوطًا، وإلا في قاعدة البيانات) ويُعرضان بالنقر. إثباتات ما قبل هذا التحديث قد تحمل مرجعًا بلا ملف.', 'The signature and the delivery photo are stored with the proof (object storage when connected, otherwise the database) and open on a click. Proofs from before this update may carry a reference without a file.') }}</div>
  </SectionCard>
</template>
