<script setup>
// Packing row of a fulfillment order: cartons / weight / volume inputs → "complete packing & print labels" when the
// order is picked (permission `pack.confirm`); read-only cartons / weight otherwise.
//   <PackRow :fo="fo" standalone @changed="refetch" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, NumberInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { FO_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { custName, pickProgress } from './shared';

const props = defineProps({
  /** Fo (needs number, status, customer, cartons, weightKg, cbm, lines). */
  fo: { type: Object, required: true },
  /** Rendered alone inside a card (no row padding / separator). */
  standalone: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const act = useAction();
const cartons = ref(props.fo.cartons || 1);
const kg = ref(props.fo.weightKg || null);
const cbm = ref(props.fo.cbm || null);

const canDo = computed(() => auth.can('pack.confirm') && props.fo.status === 'picked');
const p = computed(() => pickProgress(props.fo));

async function pack() {
  const n = props.fo.number;
  const body = { cartons: cartons.value || 1, ...(kg.value ? { weightKg: kg.value } : {}), ...(cbm.value ? { volumeM3: cbm.value } : {}) };
  const r = await act.run(() => api.postIdempotent(`/fulfillment/orders/${encodeURIComponent(n)}/pack`, body), {
    success: (x) => t(`اكتملت تعبئة ${n} — ${x.package} · طُبع Packing Slip وShipping Label`, `${n} packed — ${x.package}`),
    invalidate: ['fulfillment', 'sales', 'inventory'],
  });
  if (r) emit('changed');
}
const BOX = 'rounded-[10px] border border-line-2 bg-soft py-[7px] text-center';
const BOX_L = 'text-[8px] font-extrabold text-faint';
</script>

<template>
  <div class="row wrap !gap-3.5" :class="standalone ? '' : 'border-t border-line-2 px-[18px] py-3'">
    <div class="min-w-[200px]">
      <div class="num-mixed text-[11.5px] font-extrabold">{{ fo.number }}</div>
      <div class="mt-px text-[9px] text-faint">{{ custName(fo.customer) }}{{ fo.customer.zone ? ` · ${fo.customer.zone}` : '' }} · {{ t(`${fo.lines.length} أسطر · ${p.done} / ${p.need} قطعة`, `${fo.lines.length} lines · ${p.done} / ${p.need} units`) }}</div>
    </div>
    <div class="row">
      <template v-if="canDo">
        <div :class="BOX" class="px-2.5"><NumberInput v-model="cartons" small center class="w-16" :min="1" /><div :class="BOX_L" class="mt-[3px]">{{ t('كراتين', 'Cartons') }}</div></div>
        <div :class="BOX" class="px-2.5"><NumberInput v-model="kg" small center class="w-[72px]" :min="0" :step="0.1" /><div :class="BOX_L" class="mt-[3px]">{{ t('الوزن كجم', 'Weight kg') }}</div></div>
        <div :class="BOX" class="px-2.5"><NumberInput v-model="cbm" small center class="w-16" :min="0" :step="0.1" /><div :class="BOX_L" class="mt-[3px]">{{ t('الحجم م³', 'Volume m³') }}</div></div>
      </template>
      <template v-else>
        <div :class="BOX" class="px-[13px]"><div class="num text-[13px]">{{ fo.cartons || '—' }}</div><div :class="BOX_L">{{ t('كراتين', 'Cartons') }}</div></div>
        <div :class="BOX" class="px-[13px]"><div class="num text-[13px]">{{ fmtNum(fo.weightKg, 1) }}</div><div :class="BOX_L">{{ t('الوزن كجم', 'Weight kg') }}</div></div>
      </template>
    </div>
    <div class="grow" />
    <Chip :map="FO_LABELS" :k="fo.status" small />
    <Btn v-if="canDo" tone="purple" class="!h-[38px] !rounded-[11px] !text-[10.5px]" :loading="act.pending.value" :label="{ ar: 'إتمام التعبئة وطباعة الملصقات', en: 'Complete packing & print labels' }" @click="pack" />
    <div v-if="act.error.value" class="w-full"><ErrorBanner :error="act.error.value" @close="act.clearError()" /></div>
  </div>
</template>
