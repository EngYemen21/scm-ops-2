<script setup>
// A correction of one day of a draft sheet: PATCH /payroll/sheets/{id}/lines/{line} { late?, early?, overtime?, note } —
// the minutes AFTER the policy, with the note that explains them (five characters at least). The value replaced, who
// changed it and why go to the line's change log; a recompute keeps the correction.
//   <SheetLineForm :sheet="sheetOrNull" :line="lineOrNull" @close />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { NumberInput, TextArea } from '@/components';
import { t } from '@/i18n';
import FormShell from './FormShell.vue';
import { fmtDay, hm, weekdayName } from './payroll';

const props = defineProps({
  sheet: { type: Object, default: null },
  line: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['payroll'] });
const f = reactive({ late: 0, early: 0, overtime: 0, note: '' });
const errors = ref({});
watch(() => props.line, (l) => {
  if (!l) return;
  Object.assign(f, { late: l.late ?? 0, early: l.early ?? 0, overtime: l.overtime ?? 0, note: '' });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const FIELDS = ['late', 'early', 'overtime'];
const valid = (v) => v != null && v !== '' && Number(v) >= 0 && Number(v) <= 1440;
const changed = computed(() => (props.line ? FIELDS.filter((k) => valid(f[k]) && Number(f[k]) !== Number(props.line[k] ?? 0)) : []));
const ok = computed(() => FIELDS.every((k) => valid(f[k])) && changed.value.length > 0 && f.note.trim().length >= 5);
const missing = computed(() => (!FIELDS.every((k) => valid(f[k])) ? { ar: 'الدقائق بين 0 و1440', en: 'Minutes are between 0 and 1440' }
  : !changed.value.length ? { ar: 'لم يتغيّر رقم بعد', en: 'No figure changed yet' } : { ar: 'اكتب سبب التصحيح (5 أحرف على الأقل)', en: 'Write why (at least 5 characters)' }));

async function save() {
  if (!ok.value) return;
  const body = { note: f.note.trim() };
  for (const k of changed.value) body[k] = Number(f[k]);
  const r = await act.run(() => api.patch(`/payroll/sheets/${encodeURIComponent(props.sheet.id)}/lines/${encodeURIComponent(props.line.id)}`, body), { success: t('صُحِّح اليوم وحُفظ ما كان عليه', 'Day corrected, what it said is kept') });
  if (r) emit('close');
}
</script>

<template>
  <FormShell :open="!!line" :width="480" :z-index="72" :title="{ ar: 'تصحيح يوم في الكشف', en: 'Correct a day of the sheet' }" :sub="line ? `${weekdayName(line.weekday)} ${fmtDay(line.date)}` : null"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="missing" @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="line" class="hint !mt-0 mb-3">
      {{ t('الفعلي قبل السياسة', 'Actual before the policy') }}: {{ t('تأخير', 'late') }} <span class="num">{{ hm(line.lateActual, false) }}</span> · {{ t('مبكر', 'early') }} <span class="num">{{ hm(line.earlyActual, false) }}</span> · {{ t('إضافي', 'overtime') }} <span class="num">{{ hm(line.overtimeActual, false) }}</span>
    </div>
    <div class="form-grid">
      <NumberInput v-model="f.late" :min="0" :max="1440" :label="{ ar: 'تأخير يُحتسب (دقيقة)', en: 'Lateness counted (min)' }" :error="errors.late" />
      <NumberInput v-model="f.early" :min="0" :max="1440" :label="{ ar: 'خروج مبكر يُحتسب (دقيقة)', en: 'Early leave counted (min)' }" :error="errors.early" />
      <NumberInput v-model="f.overtime" :min="0" :max="1440" :label="{ ar: 'إضافي يُحتسب (دقيقة)', en: 'Overtime counted (min)' }" :error="errors.overtime" />
      <TextArea v-model="f.note" required :rows="2" :label="{ ar: 'سبب التصحيح (5 أحرف على الأقل)', en: 'Why (at least 5 characters)' }" :error="errors.note" />
    </div>
  </FormShell>
</template>
