<script setup>
// Packing row of a fulfillment order. A picked order (permission `pack.confirm`): the real number of cartons, weight,
// volume and — optionally — a weight per carton → "complete packing", which creates one row and one label per carton and
// opens the label dialog ("1 / N", carton code as CODE128, customer, stop) ready to print. Nothing is printed by the
// server, and nothing says it was. Any other state: read-only figures plus the corrections of a packed order
// (carton labels again, edit package, unpack, put back) through FoActions.
//   <PackRow :fo="fo" standalone @changed="refetch" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, NumberInput, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { FO_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { showLabels } from '@/stores/ui';
import FoActions from './FoActions.vue';
import { cartonLabels, custName, pickProgress, shortUnits } from './shared';

const props = defineProps({
  /** Fo (needs number, status, customer, cartons, weightKg, cbm, lines). */
  fo: { type: Object, required: true },
  /** Rendered alone inside a card (no row padding / separator). */
  standalone: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const act = useAction();
// the number of cartons is what gets labelled: the packer types what he really packed (the order's figure is an estimate)
const cartons = ref(null);
const kg = ref(props.fo.weightKg || null);
const cbm = ref(props.fo.cbm || null);
const weights = ref('');

const canDo = computed(() => auth.can('pack.confirm') && props.fo.status === 'picked');
const p = computed(() => pickProgress(props.fo));
const short = computed(() => shortUnits(props.fo));
const weightList = computed(() => weights.value.split(/[,،\s]+/).map((x) => x.trim()).filter(Boolean).map(Number));
const weightsBad = computed(() => weightList.value.length > 0 && (weightList.value.some((x) => !(x > 0)) || weightList.value.length !== (cartons.value || 0)));
const why = computed(() => (!cartons.value ? t('أدخل عدد الكراتين الفعلي — يُطبع ملصق لكل كرتون', 'Enter the real number of cartons — one label is printed per carton')
  : weightsBad.value ? t('أوزان الكراتين: وزن واحد أكبر من صفر لكل كرتون', 'Carton weights: one weight above zero per carton') : null));

async function pack() {
  const n = props.fo.number;
  const body = { cartons: cartons.value, ...(weightList.value.length ? { cartonWeights: weightList.value } : kg.value ? { weightKg: kg.value } : {}), ...(cbm.value ? { volumeM3: cbm.value } : {}) };
  const r = await act.run(() => api.postIdempotent(`/fulfillment/orders/${encodeURIComponent(n)}/pack`, body), {
    success: (x) => t(`اكتملت تعبئة ${n} — ${x.package} · ${x.cartons} كرتون — اطبع ملصقات الكراتين`, `${n} packed — ${x.package} · ${x.cartons} cartons — print the carton labels`),
    invalidate: ['fulfillment', 'sales', 'inventory', 'transport'],
  });
  if (!r) return;
  // the labels of what was just packed, ready to print (more than one job's worth prints later from «ملصقات الكراتين»)
  showLabels(cartonLabels({ fo: n, cartons: r.cartonList, customer: custName(props.fo.customer), stop: null }), n);
  emit('changed');
}
const BOX = 'rounded-[10px] border border-line-2 bg-soft py-[7px] text-center';
const BOX_L = 'text-[8px] font-extrabold text-faint';
</script>

<template>
  <div class="row wrap !gap-3.5" :class="standalone ? '' : 'border-t border-line-2 px-[18px] py-3'">
    <div class="min-w-[200px]">
      <RouterLink :to="`/fo/${encodeURIComponent(fo.number)}`" class="num-mixed text-[11.5px] font-extrabold !text-ink no-underline">{{ fo.number }}</RouterLink>
      <div class="mt-px text-[9px] text-faint">{{ custName(fo.customer) }}{{ fo.customer.zone ? ` · ${fo.customer.zone}` : '' }} · {{ t(`${fo.lines.length} أسطر · ${p.done} / ${p.need} قطعة`, `${fo.lines.length} lines · ${p.done} / ${p.need} units`) }}<span v-if="short" class="font-bold text-bad"> · {{ t(`ناقص ${short}`, `${short} short`) }}</span></div>
    </div>
    <div class="row wrap">
      <template v-if="canDo">
        <div :class="BOX" class="px-2.5"><NumberInput v-model="cartons" small center class="w-16" :min="1" :placeholder="fo.cartons ? `≈${fo.cartons}` : '1'" /><div :class="BOX_L" class="mt-[3px]">{{ t('كراتين', 'Cartons') }}</div></div>
        <div :class="BOX" class="px-2.5"><NumberInput v-model="kg" small center class="w-[72px]" :min="0" :step="0.1" :disabled="weightList.length > 0" /><div :class="BOX_L" class="mt-[3px]">{{ t('الوزن كجم', 'Weight kg') }}</div></div>
        <div :class="BOX" class="px-2.5"><NumberInput v-model="cbm" small center class="w-16" :min="0" :step="0.1" /><div :class="BOX_L" class="mt-[3px]">{{ t('الحجم م³', 'Volume m³') }}</div></div>
        <div :class="BOX" class="px-2.5"><TextInput v-model="weights" small dir="ltr" class="w-[150px]" placeholder="12.5, 11, 13" /><div :class="BOX_L" class="mt-[3px]">{{ t('وزن كل كرتون (اختياري)', 'Weight per carton (optional)') }}</div></div>
      </template>
      <template v-else>
        <div :class="BOX" class="px-[13px]"><div class="num text-[13px]">{{ fo.cartons || '—' }}</div><div :class="BOX_L">{{ t('كراتين', 'Cartons') }}</div></div>
        <div :class="BOX" class="px-[13px]"><div class="num text-[13px]">{{ fmtNum(fo.weightKg, 1) }}</div><div :class="BOX_L">{{ t('الوزن كجم', 'Weight kg') }}</div></div>
        <div :class="BOX" class="px-[13px]"><div class="num text-[13px]">{{ fmtNum(fo.cbm, 1) }}</div><div :class="BOX_L">{{ t('الحجم م³', 'Volume m³') }}</div></div>
      </template>
    </div>
    <div class="grow" />
    <Chip :map="FO_LABELS" :k="fo.status" small />
    <Btn v-if="canDo" tone="purple" class="!h-[38px] !rounded-[11px] !text-[10.5px]" :loading="act.pending.value" :disabled="!!why" :title="why" :label="{ ar: 'إتمام التعبئة وملصقات الكراتين', en: 'Complete packing & carton labels' }" @click="pack" />
    <FoActions :fo="fo" compact @changed="emit('changed')" />
    <div v-if="canDo && why" class="faint w-full text-[9px]">{{ why }}</div>
    <div v-if="act.error.value" class="w-full"><ErrorBanner :error="act.error.value" @close="act.clearError()" /></div>
  </div>
</template>
