<script setup>
// Pick & Pack › staging tab. Outbound: the packed orders waiting in STG-OUT read as LANES — grouped by the trip they
// leave on and the door that trip loads at (orders in loading order: last stop first), then the orders with no trip yet.
// Below: the staging entries and the balances of the staging bins (STG-OUT / STG-IN) with their age.
// Data: /api/fulfillment/staging (lanes) and /api/inventory/staging (entries + balances), refreshed every 30 s,
// scoped by the warehouse selector.
//   Lane         { trip: { number, status, date?, plannedStart?, dockCode?, vehicle?, driver? } | null,
//                  orders: [{ fo, so?, customer, customerEn?, zone?, cartons, kg, qty, stagedAt?, stopSeq? }] }
//   StagingItem  { id, sku, nameAr, nameEn?, warehouse, bin, direction: 'in'|'out', batch?, expiry?, onHand, reserved, ageHours?,
//                  reference?: { number?, type?, movement?, movementType?, by?, at? } }
//   StagingEntry { id, direction, warehouse, bin, referenceType, referenceNumber?, qty, status, createdAt }
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtAgo, fmtDate, fmtDateOnly, fmtNum, lang, nm, t } from '@/i18n';
import { MOVEMENT_LABELS, TRIP_LABELS } from '@/shared';
import { useWarehouse } from '@/stores/warehouse';
import StagingRef from './StagingRef.vue';

const wh = useWarehouse();
const dir = ref('out');
const staging = useGet('/inventory/staging', () => ({ ...wh.whParams }), { refetchInterval: 30_000 });
const lanes = useGet('/fulfillment/staging', () => ({ ...wh.whParams }), { refetchInterval: 30_000 });
const items = computed(() => (staging.data.value?.items || []).filter((x) => !dir.value || x.direction === dir.value));
const entries = computed(() => (staging.data.value?.entries || []).filter((x) => !dir.value || x.direction === dir.value));
const groups = computed(() => lanes.data.value?.groups || []);
const laneCust = (o) => (lang.value === 'ar' ? o.customer : o.customerEn || o.customer) || '—';

const DIRS = [{ k: 'out', label: { ar: 'Staging الصادر STG-OUT', en: 'Outbound STG-OUT' } }, { k: 'in', label: { ar: 'Staging الوارد STG-IN', en: 'Inbound STG-IN' } }, { k: '', label: { ar: 'الكل', en: 'All' } }];
const ageColor = (h) => ((h ?? 0) > 24 ? '#b23b3b' : (h ?? 0) > 8 ? '#b26a16' : '#1d7a3e');

const laneCols = [
  { key: 'stopSeq', header: { ar: 'المحطة', en: 'Stop' }, width: '70px', kind: 'num', value: (o) => o.stopSeq ?? '—' },
  { key: 'fo', header: { ar: 'أمر التنفيذ', en: 'Order' }, width: '120px' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(180px,1.5fr)' },
  { key: 'cartons', header: { ar: 'كراتين', en: 'Cartons' }, width: '80px', kind: 'num', value: (o) => fmtNum(o.cartons) },
  { key: 'kg', header: { ar: 'كجم', en: 'kg' }, width: '80px', kind: 'num', value: (o) => fmtNum(o.kg, 1) },
  { key: 'qty', header: { ar: 'قطع', en: 'Units' }, width: '70px', kind: 'num', value: (o) => fmtNum(o.qty) },
  { key: 'stagedAt', header: { ar: 'في Staging منذ', en: 'Staged' }, width: '130px' },
];
const entryCols = [
  { key: 'ref', header: { ar: 'المرجع', en: 'Reference' }, width: '130px' },
  { key: 'bin', header: { ar: 'الموقع', en: 'Bin' }, width: '90px' },
  { key: 'wh', header: { ar: 'المستودع', en: 'WH' }, width: '70px' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num', value: (r) => fmtNum(r.qty) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '110px' },
  { key: 'at', header: { ar: 'منذ', en: 'Since' }, width: '120px' },
];
const itemCols = [
  { key: 'bin', header: { ar: 'الموقع', en: 'Bin' }, width: '90px' },
  { key: 'wh', header: { ar: 'المستودع', en: 'WH' }, width: '70px' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(180px,1.5fr)' },
  { key: 'batch', header: { ar: 'الدفعة · الصلاحية', en: 'Batch · expiry' }, width: '130px' },
  { key: 'onHand', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num', value: (r) => fmtNum(r.onHand) },
  { key: 'ref', header: { ar: 'المرجع', en: 'Reference' }, width: '120px' },
  { key: 'mv', header: { ar: 'آخر حركة', en: 'Last movement' }, width: '150px' },
  { key: 'age', header: { ar: 'العمر', en: 'Age' }, width: '80px' },
];
</script>

<template>
  <div>
    <Tabs v-model="dir" :tabs="DIRS" variant="pill" />
    <ErrorBanner :error="staging.error.value" :closable="false" />

    <!-- outbound lanes: trip → door → orders -->
    <template v-if="dir !== 'in'">
      <ErrorBanner :error="lanes.error.value" :closable="false" />
      <div class="card mb-3.5">
        <div class="px-[18px] pb-[9px] pt-[13px] text-[13px] font-extrabold">{{ t('الصادر حسب الرحلة وباب التحميل', 'Outbound by trip and loading door') }} <span class="num muted text-[10px]">({{ lanes.data.value?.total ?? 0 }})</span></div>
        <div v-if="lanes.isLoading.value && groups.length === 0" class="skel m-[18px] h-20" />
        <div v-if="!lanes.isLoading.value && groups.length === 0" class="empty !p-5">{{ t('لا طلبات معبّأة بانتظار التحميل', 'No packed orders waiting to load') }}</div>
        <div v-for="g in groups" :key="g.trip?.number || '-'" class="border-t border-line-2">
          <div class="row wrap !gap-2.5 bg-soft px-[18px] py-2">
            <template v-if="g.trip">
              <span class="rounded-lg px-2.5 py-1 text-[11px] font-extrabold" :class="g.trip.dockCode ? 'bg-violet-soft text-violet' : 'bg-warn-soft text-warn'"><span class="num">{{ g.trip.dockCode || '—' }}</span> · {{ g.trip.dockCode ? t('باب التحميل', 'door') : t('لم يُحدَّد باب', 'no door set') }}</span>
              <RouterLink :to="{ path: '/dispatch', query: { trip: g.trip.number } }" class="cell-id !text-brand-dark">{{ g.trip.number }}</RouterLink>
              <Chip :map="TRIP_LABELS" :k="g.trip.status" small />
              <span class="num text-[10px] text-sec">{{ fmtDateOnly(g.trip.date) }}{{ g.trip.plannedStart ? ` ${g.trip.plannedStart}` : '' }}</span>
              <span v-if="g.trip.vehicle" class="num text-[10px] text-sec">{{ g.trip.vehicle }}</span>
              <span v-if="g.trip.driver" class="text-[10px] text-sec">{{ g.trip.driver }}</span>
            </template>
            <span v-else class="text-[11px] font-extrabold text-muted">{{ t('بلا رحلة بعد — تُضاف من الرحلات', 'No trip yet — add them from Trips') }}</span>
            <span class="grow" />
            <span class="num text-[10px] text-faint">{{ g.orders.length }} {{ t('طلبات', 'orders') }}</span>
          </div>
          <DataTable :columns="laneCols" :rows="g.orders" :row-key="(o) => o.fo" :zebra="false" dense>
            <template #cell-fo="{ row }"><RouterLink :to="`/fo/${encodeURIComponent(row.fo)}`" class="cell-id">{{ row.fo }}</RouterLink></template>
            <template #cell-customer="{ row }"><div><div class="cell-name">{{ laneCust(row) }}</div><div class="cell-sub">{{ [row.so, row.zone].filter(Boolean).join(' · ') }}</div></div></template>
            <template #cell-stagedAt="{ row }"><span class="cell-date">{{ row.stagedAt ? fmtAgo(row.stagedAt) : '—' }}</span></template>
          </DataTable>
        </div>
        <div class="hint !m-0 !rounded-none border-t border-line-2">{{ t('منطقة الصادر واحدة (STG-OUT)؛ الطلبات تُفرز هنا حسب الرحلة وبابها — داخل كل رحلة بترتيب التحميل (آخر محطة أولًا). الباب يُحدَّد من صفحة التحميل والتوصيل.', 'There is one outbound area (STG-OUT); orders are sorted here by trip and its door — inside a trip in loading order (last stop first). The door is set on the Load & Dispatch page.') }}</div>
      </div>
    </template>

    <div class="card">
      <div class="px-[18px] pb-[9px] pt-[13px] text-[13px] font-extrabold">{{ t('طرود بانتظار التحميل', 'Packages awaiting loading') }} <span class="num muted text-[10px]">({{ entries.length }})</span></div>
      <DataTable :columns="entryCols" :rows="entries" :loading="staging.isLoading.value" :row-key="(r) => r.id" :min-width="640" :empty-text="{ ar: 'لا طرود في Staging', en: 'Nothing staged' }">
        <template #cell-ref="{ row }"><StagingRef :type="row.referenceType" :number="row.referenceNumber" /></template>
        <template #cell-bin="{ row }"><span class="num text-violet">{{ row.bin }}</span></template>
        <template #cell-wh="{ row }"><span class="sec text-[10px] font-bold">{{ row.warehouse }}</span></template>
        <template #cell-status="{ row }"><Chip :label="row.status === 'waiting' ? { ar: 'بانتظار التحميل', en: 'Awaiting load' } : row.status" fg="#b26a16" bg="#fbf0dd" small /></template>
        <template #cell-at="{ row }"><span class="cell-date">{{ fmtDate(row.createdAt) }} · {{ fmtAgo(row.createdAt) }}</span></template>
      </DataTable>
    </div>
    <div class="card mt-3.5">
      <div class="px-[18px] pb-[9px] pt-[13px] text-[13px] font-extrabold">{{ t('أرصدة مواقع Staging', 'Staging bin balances') }} <span class="num muted text-[10px]">({{ items.length }})</span></div>
      <DataTable :columns="itemCols" :rows="items" :loading="staging.isLoading.value" :row-key="(r) => r.id" :min-width="900" :empty-text="{ ar: 'لا أرصدة في مواقع Staging', en: 'No stock in staging bins' }">
        <template #cell-bin="{ row }"><span class="num" :class="row.direction === 'out' ? 'text-violet' : 'text-ok'">{{ row.bin }}</span></template>
        <template #cell-wh="{ row }"><span class="sec text-[10px] font-bold">{{ row.warehouse }}</span></template>
        <template #cell-product="{ row }"><div><div class="cell-name">{{ nm(row) }}</div><div class="cell-sub">{{ row.sku }}</div></div></template>
        <template #cell-batch="{ row }"><span class="num text-[9.5px] text-brand-dark">{{ row.batch || '—' }}{{ row.expiry ? ` · ${fmtDateOnly(row.expiry)}` : '' }}</span></template>
        <template #cell-ref="{ row }"><StagingRef :type="row.reference?.type" :number="row.reference?.number" /></template>
        <template #cell-mv="{ row }">
          <div v-if="row.reference" class="row !gap-1.5"><Chip :map="MOVEMENT_LABELS" :k="row.reference.movementType || ''" small /><span class="cell-sub">{{ row.reference.by || '' }}</span></div>
          <template v-else>—</template>
        </template>
        <template #cell-age="{ row }"><span class="num text-[10px]" :style="{ color: ageColor(row.ageHours) }">{{ row.ageHours != null ? t(`${row.ageHours} س`, `${row.ageHours}h`) : '—' }}</span></template>
      </DataTable>
    </div>
    <div class="hint">{{ t('STG-OUT يحتفظ بالكميات المعبأة حتى تحميلها على الشاحنة (التحميل والتوصيل) — الأعمار فوق 24 ساعة تُظهر تأخرًا في الشحن.', 'STG-OUT holds packed stock until it is loaded onto the truck (Load & Dispatch) — ages above 24h indicate a shipping delay.') }}</div>
  </div>
</template>
