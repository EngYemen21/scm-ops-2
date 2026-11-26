<script setup>
// A new VAT return — POST /tax/returns { frequency, year, period, notes } (vatreturn.manage). Prefilled with the period
// the server suggests (`suggest` of the list); the server makes the draft and computes it from the books at once.
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { NumberInput, PillChoice, TextArea } from '@/components';
import { t } from '@/i18n';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { FREQUENCY, optionsOf, textOrNull } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** `{ frequency, year, period, code, from, to, exists }` of GET /tax/returns. */
  suggest: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['tax'] });
const f = reactive({ frequency: 'monthly', year: new Date().getFullYear(), period: 1, notes: '' });
watch(() => props.open, (open) => {
  if (!open) return;
  const s = props.suggest;
  Object.assign(f, { frequency: s?.frequency || 'monthly', year: s?.year || new Date().getFullYear(), period: s?.period || 1, notes: '' });
  act.clearError();
}, { immediate: true });

const maxPeriod = computed(() => (f.frequency === 'quarterly' ? 4 : 12));
const code = computed(() => (f.frequency === 'quarterly' ? `${f.year}-Q${f.period}` : `${f.year}-${String(f.period).padStart(2, '0')}`));
const ok = computed(() => Number.isInteger(f.year) && f.year >= 2018 && f.year <= 2100 && Number.isInteger(f.period) && f.period >= 1 && f.period <= maxPeriod.value);
watch(() => f.frequency, () => { if (f.period > maxPeriod.value) f.period = maxPeriod.value; });

async function submit() {
  const r = await act.run(() => api.postIdempotent('/tax/returns', { frequency: f.frequency, year: f.year, period: f.period, notes: textOrNull(f.notes) }),
    { success: (x) => t(`أُنشئ إقرار ${x?.periodCode || code.value}`, `Return ${x?.periodCode || code.value} created`) });
  if (r) { emit('done', r); }
}
</script>

<template>
  <TaxFormDrawer :open="open" :title="{ ar: 'إقرار ضريبة قيمة مضافة جديد', en: 'New VAT return' }" :sub="code" :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
                 :submit-label="{ ar: 'إنشاء المسودة', en: 'Create the draft' }" submit-tone="primary"
                 :missing="{ ar: 'سنة بين 2018 و2100، وفترة ضمن السنة', en: 'A year between 2018 and 2100 and a period within the year' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="suggest" class="hint teal !mt-0 mb-3">
      {{ t('المقترح', 'Suggested') }}: <b class="num">{{ suggest.code }}</b>
      <template v-if="suggest.exists"> — {{ t('يوجد إقرار لهذه الفترة مسبقًا', 'a return already exists for this period') }}</template>
    </div>
    <div class="form-grid">
      <PillChoice v-model="f.frequency" :label="{ ar: 'الدورية', en: 'Frequency' }" :options="optionsOf(FREQUENCY)" full />
      <NumberInput v-model="f.year" :label="{ ar: 'السنة', en: 'Year' }" required :min="2018" :max="2100" :step="1" />
      <NumberInput v-model="f.period" :label="f.frequency === 'quarterly' ? { ar: 'الربع (1–4)', en: 'Quarter (1–4)' } : { ar: 'الشهر (1–12)', en: 'Month (1–12)' }" required :min="1" :max="maxPeriod" :step="1" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" />
    </div>
    <div class="hint !mt-3">{{ t('تُنشأ المسودة من الدفاتر مباشرة: كل مستند قُيّد في الفترة يوضع على بند الإقرار حسب رمزه الضريبي.', 'The draft is built from the books at once: every document booked in the period lands on a line of the return by its tax code.') }}</div>
  </TaxFormDrawer>
</template>
