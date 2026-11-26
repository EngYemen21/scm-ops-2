<script setup>
// Cash counted by denomination: one row per coin / note of `lookups.denominations`, a whole-number count each, the line
// total and the live grand total.   <DenominationGrid v-model="counts" :denominations="lk.denominations.value" />
// v-model is `{ [value]: count }`; `countsTotal` / `countsBody` (./pos.js) turn it into halalas / the API's rows.
import { computed } from 'vue';
import { NumberInput } from '@/components';
import { fmtMoney, num, t } from '@/i18n';
import { DENOM_KIND, cents, countsTotal, fmtCents, labelOf } from './pos';

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
  denominations: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

/** Notes first, the largest first — the order a drawer is counted in. */
const rows = computed(() => [...props.denominations].sort((a, b) => (a.kind === b.kind ? num(b.value) - num(a.value) : a.kind === 'note' ? -1 : 1)));
const countOf = (d) => props.modelValue?.[d.value] ?? null;
function setCount(d, v) {
  const n = v == null ? null : Math.max(0, Math.floor(num(v)));
  const next = { ...(props.modelValue || {}) };
  if (n == null || n === 0) delete next[d.value]; else next[d.value] = n;
  emit('update:modelValue', next);
}
const step = (d, by) => setCount(d, Math.max(0, (Number(countOf(d)) || 0) + by));
const lineCents = (d) => cents(d.value) * (Number(countOf(d)) || 0);
const total = computed(() => countsTotal(props.modelValue));
const label = (d) => d.label || `${fmtMoney(num(d.value))}`;
</script>

<template>
  <div class="rounded-xl border border-line">
    <div v-if="!rows.length" class="p-3 text-[11px] text-muted">{{ t('لا فئات نقدية معرّفة — يُدخل المبلغ مرة واحدة، أو يضيف المدير الفئات من «الإعداد».', 'No denominations are defined — enter one amount, or a manager adds them under "Setup".') }}</div>
    <div v-for="d in rows" :key="d.id || d.value" class="flex items-center gap-2 border-b border-line px-3 py-1.5 last:border-b-0">
      <div class="min-w-0 flex-1">
        <div class="num ltr text-start text-[13px] font-extrabold text-ink">{{ label(d) }}</div>
        <div class="text-[9.5px] text-muted">{{ labelOf(DENOM_KIND, d.kind) }}</div>
      </div>
      <button type="button" class="btn soft sm !w-8 !px-0" :disabled="disabled || !countOf(d)" :aria-label="t('إنقاص', 'Less')" @click="step(d, -1)">−</button>
      <NumberInput small center class="!w-[72px]" field-class="!mb-0" :model-value="countOf(d)" :min="0" :step="1" :disabled="disabled" placeholder="0" @update:model-value="(v) => setCount(d, v)" />
      <button type="button" class="btn soft sm !w-8 !px-0" :disabled="disabled" :aria-label="t('زيادة', 'More')" @click="step(d, 1)">+</button>
      <div class="num ltr w-[92px] text-end text-[11.5px] font-bold" :class="lineCents(d) ? 'text-ink' : 'text-faint'">{{ fmtMoney(lineCents(d) / 100) }}</div>
    </div>
    <div class="flex items-center justify-between rounded-b-xl bg-soft px-3 py-2">
      <span class="text-[11px] font-extrabold text-sec">{{ t('إجمالي المعدود', 'Counted total') }}</span>
      <span class="num ltr text-[15px] font-extrabold text-ink">{{ fmtCents(total) }}</span>
    </div>
  </div>
</template>
