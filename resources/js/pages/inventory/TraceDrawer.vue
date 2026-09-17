<script setup>
// Reference trace drawer: `GET /inventory/trace/:ref` — every movement carrying that document number,
// in / out totals and a count per movement type.
//   <TraceDrawer :reference="trace" @close="trace = null" @open-movement="(n) => sel = n" />
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Chip, Drawer, ErrorBanner } from '@/components';
import { fmtNum, t } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import MovementTable from './MovementTable.vue';
import RefLink from './RefLink.vue';
import Tile from './Tile.vue';
import { dictLabel, pname } from './shared';

const props = defineProps({
  /** Document number or transaction id; null = closed. */
  reference: { type: String, default: null },
});
const emit = defineEmits(['close', 'open-movement']);

const q = useGet(() => (props.reference ? `/inventory/trace/${encodeURIComponent(props.reference)}` : null));
/** { referenceNumber, referenceType, referenceId, count, inQty, outQty, byType: { type: n }, products: [], movements: Movement[] } */
const d = computed(() => q.data.value);
</script>

<template>
  <Drawer :open="!!reference" :width="760" @close="emit('close')">
    <template #title>{{ t('تتبع مستند', 'Document trace') }} · <span class="num">{{ reference }}</span></template>
    <template v-if="d?.referenceType" #sub>{{ d.referenceType }} · <RefLink :type="d.referenceType" :number="d.referenceNumber" /></template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-20" />
    <template v-if="d">
      <div class="grid grid-cols-3 gap-2">
        <Tile :value="d.count" :label="{ ar: 'حركات', en: 'Movements' }" />
        <Tile :value="d.inQty" :label="{ ar: 'داخل +', en: 'In +' }" tone="green" />
        <Tile :value="d.outQty" :label="{ ar: 'خارج −', en: 'Out −' }" tone="red" />
      </div>
      <div class="row wrap mt-2.5 !gap-1.5">
        <Chip v-for="(v, k) in d.byType" :key="k" small :map="MOVEMENT_LABELS" :k="k">{{ dictLabel(MOVEMENT_LABELS, k) }} ·&nbsp;<b class="num">{{ fmtNum(v) }}</b></Chip>
        <Chip v-for="p in d.products" :key="p.id" small :label="`${p.sku} · ${pname(p)}`" fg="#55506a" bg="#F1EFF6" />
      </div>
      <div class="mt-3">
        <MovementTable :rows="d.movements" full clickable :min-width="700" :empty-text="{ ar: 'لا حركات تحمل هذا المرجع', en: 'No movements carry this reference' }" @open="(n) => emit('open-movement', n)" />
      </div>
      <div class="muted mt-2 text-[9px]">{{ t('يُطابق رقم المستند المرجعي أو معرّف المعاملة — التتبع من PO ← GRN ← Putaway ← تخصيص ← صرف ← تحميل ← مرتجع.', 'Matches the reference number or transaction id — trace PO → GRN → putaway → allocation → pick → load → return.') }}</div>
    </template>
  </Drawer>
</template>
