<script setup>
// Trip room · "PODs": proofs of delivery of the trip (GET /delivery/pods?trip=…). GPS shows what was captured; attachments
// (signature / photo) are references whose stored status is "Integration Pending" until object storage is connected.
import { useList } from '@/api/client';
import { Chip, DataTable, SectionCard } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { STOP_LABELS } from '@/shared';
import { PENDING } from './tms';

const props = defineProps({ trip: { type: Object, required: true } });
const pods = useList('/delivery/pods', () => ({ trip: props.trip.number, pageSize: 50 }));

const gpsText = (p) => (p.gpsStatus === 'captured' ? `✓ ${p.gpsLat?.toFixed?.(4) ?? ''},${p.gpsLng?.toFixed?.(4) ?? ''}` : p.gpsStatus || '—');
const columns = [
  { key: 'number', header: 'POD', kind: 'id', width: '120px' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, kind: 'name', value: (p) => p.customer?.nameAr || '—' },
  { key: 'fo', header: 'FO', kind: 'id', width: '110px', value: (p) => p.fo?.number || '—' },
  { key: 'result', header: { ar: 'النتيجة', en: 'Result' }, width: '110px' },
  { key: 'receiverName', header: { ar: 'المستلم', en: 'Receiver' } },
  { key: 'deliveredQty', header: { ar: 'مسلّم / مرتجع', en: 'Delivered / returned' }, kind: 'num', width: '110px', value: (p) => `${p.deliveredQty ?? '—'} / ${p.returnedQty ?? 0}` },
  { key: 'gpsStatus', header: 'GPS', width: '90px', value: gpsText },
  { key: 'att', header: { ar: 'المرفقات', en: 'Attachments' }, width: '130px', value: (p) => (p.attachments?.length ? `${p.attachments.length} · ${bi(PENDING)}` : '—') },
  { key: 'at', header: { ar: 'الوقت', en: 'Time' }, kind: 'date', width: '120px', value: (p) => fmtDate(p.at) },
];
</script>

<template>
  <SectionCard small :padded="false">
    <DataTable :columns="columns" :paged="pods.data.value" :loading="pods.isLoading.value" :row-key="(p) => p.id" :min-width="900" :empty-text="{ ar: 'لا إثباتات تسليم بعد', en: 'No PODs yet' }">
      <template #cell-result="{ row }"><Chip :map="STOP_LABELS" :k="row.result" /></template>
    </DataTable>
    <div class="px-3.5 py-2 text-[9.5px] text-faint">{{ t('التوقيع والصور تُحفظ كمراجع بحالة Integration Pending حتى ربط التخزين السحابي.', 'Signatures and photos are stored as references with status Integration Pending until object storage is connected.') }}</div>
  </SectionCard>
</template>
