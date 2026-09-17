<script setup>
// Ledger transaction drawer: `GET /inventory/ledger/:number` + the movements of the same transaction.
//   <MovementDrawer :number="sel" traceable @close="sel = null" @trace="(ref) => …" />
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import MovementTable from './MovementTable.vue';
import RefLink from './RefLink.vue';
import Tile from './Tile.vue';
import { fmtSigned, locLabel, pname, signColor } from './shared';

const props = defineProps({
  /** Movement number (TX-…); null = closed. */
  number: { type: String, default: null },
  /** Show the "trace document" button (emits `trace` with the reference number). */
  traceable: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'trace']);

const q = useGet(() => (props.number ? `/inventory/ledger/${encodeURIComponent(props.number)}` : null));
/** Movement & { sameTransaction: Movement[] } */
const m = computed(() => q.data.value);
</script>

<template>
  <Drawer :open="!!number" :width="560" @close="emit('close')">
    <template #title><span class="num">{{ number }}</span></template>
    <template v-if="m" #sub>{{ t('حركة مخزون — append-only', 'Inventory movement — append-only') }} · <span class="num">{{ fmtDate(m.createdAt) }}</span></template>
    <template v-if="m?.referenceNumber && traceable" #headExtra>
      <Btn tone="soft" size="sm" :label="{ ar: 'تتبع المستند', en: 'Trace document' }" @click="emit('trace', m.referenceNumber)" />
    </template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-20" />
    <template v-if="m">
      <div class="row wrap mb-3">
        <Chip :map="MOVEMENT_LABELS" :k="m.type" />
        <span class="text-[12px] font-extrabold">{{ pname(m.product) }}</span>
        <span class="cell-sub !mt-0">{{ m.product.sku }}</span>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <Tile :label="{ ar: 'الكمية ±', en: 'Qty ±' }"><template #value><span :style="{ color: signColor(m.signedQty) }">{{ fmtSigned(m.signedQty) }}</span></template></Tile>
        <Tile :value="m.beforeQty == null ? '—' : fmtNum(m.beforeQty)" :label="{ ar: 'قبل', en: 'Before' }" />
        <Tile :value="m.afterQty == null ? '—' : fmtNum(m.afterQty)" :label="{ ar: 'بعد', en: 'After' }" tone="green" />
      </div>
      <div class="mt-3 overflow-hidden rounded-xl border border-line-2">
        <KV :k="{ ar: 'من موقع', en: 'Source' }"><span class="num text-brand-dark">{{ locLabel(m.src) }}</span><span v-if="m.src" class="muted text-[9px]"> · {{ m.src.zone }} ({{ m.src.zoneType }})</span></KV>
        <KV :k="{ ar: 'إلى موقع', en: 'Destination' }"><span class="num text-brand-dark">{{ locLabel(m.dst) }}</span><span v-if="m.dst" class="muted text-[9px]"> · {{ m.dst.zone }} ({{ m.dst.zoneType }})</span></KV>
        <KV :k="{ ar: 'الدفعة', en: 'Batch' }"><span class="cell-id">{{ m.batchNo || '—' }}</span></KV>
        <KV :k="{ ar: 'المستند المرجعي', en: 'Reference' }"><RefLink :type="m.referenceType" :number="m.referenceNumber" /><span v-if="m.referenceType" class="muted text-[9px]"> · {{ m.referenceType }}</span></KV>
        <KV :k="{ ar: 'المعاملة Tx', en: 'Transaction' }"><span class="num text-[9.5px]">{{ m.transactionId || '—' }}</span></KV>
        <KV :k="{ ar: 'المستخدم', en: 'User' }" :v="m.username || '—'" />
        <KV :k="{ ar: 'التاريخ والوقت', en: 'Timestamp' }"><span class="num">{{ fmtDate(m.createdAt) }}</span></KV>
        <KV v-if="m.note" :k="{ ar: 'ملاحظة', en: 'Note' }" :v="m.note" />
      </div>
      <template v-if="m.sameTransaction?.length">
        <div class="mx-0.5 mb-2 mt-4 text-[11px] font-extrabold text-muted">{{ t('حركات المعاملة نفسها', 'Same transaction') }} <span class="card-count num">{{ m.sameTransaction.length }}</span></div>
        <MovementTable :rows="m.sameTransaction" />
      </template>
    </template>
  </Drawer>
</template>
