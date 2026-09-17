<script setup>
// Warehouse transfers list: KPI tiles, search + quick filters, table → /trf/:number.
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { bi, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { TRANSFER_LABELS } from '@/shared';
import { TRANSFER_REASON_LABELS, labelOf, useAutoOpen } from './shared';

const props = defineProps({
  /** `?q=` the page was opened with (deep link). */
  initialQ: { type: String, default: '' },
});

const router = useRouter();
const openTrf = (n) => router.push(`/trf/${encodeURIComponent(n)}`);

/** Quick filters → `status` param (comma-separated list of transfer states). */
const TRF_QUICK = [
  { k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'open', label: { ar: 'مفتوحة', en: 'Open' }, status: 'draft,requested,approved,picking,ready,transit,received,partial' }, { k: 'requested', label: { ar: 'بانتظار الاعتماد', en: 'Pending approval' }, status: 'requested' },
  { k: 'transit', label: { ar: 'في العبور', en: 'In transit' }, status: 'transit' }, { k: 'partial', label: { ar: 'بفروقات', en: 'With variance' }, status: 'partial' }, { k: 'done', label: { ar: 'مكتملة', en: 'Completed' }, status: 'done' },
];
const q = ref(props.initialQ);
const quick = ref(props.initialQ ? '' : 'open'); // a deep link searches every state, otherwise start on the open transfers
const page = ref(1);
const status = computed(() => TRF_QUICK.find((x) => x.k === quick.value)?.status);
watch(() => props.initialQ, (v) => { if (v) { q.value = v; quick.value = ''; page.value = 1; } }); // a new deep link while the page is open
const setQuick = (k) => { quick.value = k; page.value = 1; };

const list = useList('/inventory/transfers', () => ({ q: q.value.trim() || undefined, status: status.value, page: page.value, pageSize: 25 }), { refetchInterval: 60_000 });
useAutoOpen(() => props.initialQ, () => list.data.value?.items, openTrf);

// KPI tiles (pageSize 1 → only `total` is used); a click applies the matching quick filter.
const TILES = [
  { k: 'requested', label: { ar: 'بانتظار الاعتماد', en: 'Pending approval' }, c: '#b26a16' }, { k: 'transit', label: { ar: 'في العبور', en: 'In transit' }, c: '#3C79F5' },
  { k: 'partial', label: { ar: 'بفروقات', en: 'With variance' }, c: '#b23b3b' }, { k: 'done', label: { ar: 'مكتملة', en: 'Completed' }, c: '#1d7a3e' },
];
const counters = TILES.map((x) => useList('/inventory/transfers', { status: x.k, pageSize: 1 }, { refetchInterval: 60_000 }));

const columns = [
  { key: 'number', header: { ar: 'التحويل', en: 'Transfer' }, width: '130px', kind: 'id' },
  { key: 'route', header: { ar: 'المسار', en: 'Route' }, width: 'minmax(150px,1.2fr)' },
  { key: 'lines', header: { ar: 'أسطر', en: 'Lines' }, width: '60px', kind: 'num', value: (r) => r.lines?.length ?? '—' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '80px', kind: 'num', value: (r) => `${fmtNum(r.totalQty)}${r.receivedQty ? ` / ${fmtNum(r.receivedQty)}` : ''}` },
  { key: 'requestedBy', header: { ar: 'الطالب', en: 'Requested by' }, width: '120px', kind: 'muted', value: (r) => r.requestedBy || '—' },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '95px', kind: 'date', value: (r) => fmtDateOnly(r.date || r.createdAt) },
  { key: 'eta', header: { ar: 'الوصول المتوقع', en: 'ETA' }, width: '100px', kind: 'date', value: (r) => (r.eta ? fmtDateOnly(r.eta) : '—') },
  { key: 'reason', header: { ar: 'السبب', en: 'Reason' }, width: 'minmax(140px,1.2fr)', kind: 'muted', value: (r) => (lang.value === 'en' && r.reasonEn) || r.reasonAr || bi(labelOf(TRANSFER_REASON_LABELS, r.reasonCode, r.reasonCode || '—')) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px' },
];
</script>

<template>
  <div>
    <div class="mb-3 grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-2">
      <div v-for="(x, i) in TILES" :key="x.k" class="tile cursor-pointer" @click="setQuick(x.k)">
        <div class="tile-v" :style="{ color: x.c }">{{ counters[i].data.value?.total ?? '—' }}</div><div class="tile-l">{{ bi(x.label) }}</div>
      </div>
    </div>

    <div class="row wrap mb-2.5">
      <TextInput v-model="q" small class="!w-[220px]" :placeholder="{ ar: 'بحث برقم التحويل', en: 'Search transfer number' }" @update:model-value="page = 1" />
      <Tabs :model-value="quick" :tabs="TRF_QUICK" variant="pill" class="!mb-0" @update:model-value="setQuick" />
      <div class="grow" />
      <div class="text-[10.5px] font-extrabold text-muted"><span class="num">{{ list.data.value?.total ?? '—' }}</span> {{ t('تحويل', 'transfers') }}</div>
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="900" sticky-head :row-key="(r) => r.number" :empty-text="{ ar: 'لا تحويلات مطابقة', en: 'No matching transfers' }"
                 @page="page = $event" @row-click="(r) => openTrf(r.number)">
        <template #cell-route="{ row }"><span class="text-[10.5px] font-extrabold text-brand-dark">{{ row.fromWarehouse?.code }} ← {{ row.toWarehouse?.code }}</span></template>
        <template #cell-status="{ row }"><Chip :map="TRANSFER_LABELS" :k="row.status" /></template>
      </DataTable>
    </div>
    <div class="hint teal mt-3">{{ t('مسودة ← بانتظار الاعتماد ← معتمد ← قيد التجهيز ← جاهز للشحن ← في العبور ← مستلم ← مكتمل. الكميات تُحجز بالمصدر عند الاعتماد وتُصرف عند الشحن وتُضاف بالوجهة عند الاستلام.', 'Draft → requested → approved → picking → ready → in transit → received → done. Quantities are reserved at source on approval, issued on shipment and added at destination on receipt.') }}</div>
  </div>
</template>
