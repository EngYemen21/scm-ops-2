<script setup>
// A presence entered by hand (POST /payroll/attendance { employee, checkIn, checkOut?, reason }) or a correction of one
// (PATCH /payroll/attendance/{id} { checkIn?, checkOut?, reason }) — attendance.manage, always with a reason of at least
// five characters. The record keeps what it said first; every changed value gets a line in its change log. Times are
// typed on this device's clock and sent as exact moments. A day inside a confirmed sheet is refused (ATTENDANCE_IN_SHEET):
// reopen the sheet first.
//   <AttendanceForm :open :record="rowOrNull" @close @done="(record) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea } from '@/components';
import { t } from '@/i18n';
import FormShell from './FormShell.vue';
import { SELECT, fmtAt, fromLocalInput, pname, toLocalInput, usePayrollLookups } from './payroll';

const props = defineProps({
  open: { type: Boolean, default: false },
  record: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['payroll'] });
const { employeeOptions } = usePayrollLookups(() => props.open && !props.record);
const edit = computed(() => !!props.record);
const f = reactive({ employee: '', checkIn: '', checkOut: '', reason: '' });
let before = { checkIn: '', checkOut: '' };
const errors = ref({});
watch(() => [props.open, props.record], () => {
  if (!props.open) return;
  before = { checkIn: toLocalInput(props.record?.checkIn), checkOut: toLocalInput(props.record?.checkOut) };
  Object.assign(f, { employee: '', reason: '', ...before });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const changed = computed(() => f.checkIn !== before.checkIn || f.checkOut !== before.checkOut);
const timesOk = computed(() => !!f.checkIn && (!f.checkOut || f.checkOut > f.checkIn));
const ok = computed(() => timesOk.value && f.reason.trim().length >= 5 && (edit.value ? changed.value : !!f.employee));
const missing = computed(() => (!timesOk.value ? { ar: 'الانصراف يكون بعد الحضور', en: 'The check-out comes after the check-in' }
  : edit.value && !changed.value ? { ar: 'لم يتغيّر وقت بعد', en: 'No time changed yet' }
    : f.reason.trim().length < 5 ? { ar: 'اكتب السبب (5 أحرف على الأقل)', en: 'Write the reason (at least 5 characters)' } : null));

async function save() {
  if (!ok.value) return;
  const reason = f.reason.trim();
  let r;
  if (edit.value) {
    const body = { reason };
    if (f.checkIn !== before.checkIn) body.checkIn = fromLocalInput(f.checkIn);
    if (f.checkOut !== before.checkOut) body.checkOut = f.checkOut ? fromLocalInput(f.checkOut) : null;
    r = await act.run(() => api.patch(`/payroll/attendance/${encodeURIComponent(props.record.id)}`, body), { success: t('صُحِّح السجل وحُفظ أصله', 'Record corrected, its original kept') });
  } else {
    r = await act.run(() => api.postIdempotent('/payroll/attendance', { employee: f.employee, checkIn: fromLocalInput(f.checkIn), ...(f.checkOut ? { checkOut: fromLocalInput(f.checkOut) } : {}), reason }), { success: t('أُدخل الحضور', 'Attendance entered') });
  }
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <FormShell :open="open" :width="520" :z-index="72" :title="edit ? { ar: 'تصحيح سجل حضور', en: 'Correct an attendance record' } : { ar: 'إدخال حضور يدوي', en: 'Enter attendance by hand' }"
             :sub="edit ? `${pname(record.employee)} · ${record.workDate}` : { ar: 'لمن نسي التسجيل أو تعذّر عليه', en: 'For someone who forgot to clock or could not' }"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="missing" @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-if="!edit" v-model="f.employee" full required :label="{ ar: 'الموظف', en: 'Employee' }" :options="employeeOptions" :placeholder="SELECT" :error="errors.employee" />
      <DateInput v-model="f.checkIn" time required :label="{ ar: 'وقت الحضور', en: 'Check-in' }" :error="errors.checkIn" />
      <DateInput v-model="f.checkOut" time :label="{ ar: 'وقت الانصراف', en: 'Check-out' }" :hint="{ ar: 'اتركه فارغًا إن لم ينصرف بعد', en: 'Leave empty if not checked out yet' }" :error="errors.checkOut" />
      <TextArea v-model="f.reason" required :rows="2" :label="{ ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }" :placeholder="{ ar: 'مثال: نسي التسجيل — أكّد المدير حضوره الساعة 8', en: 'e.g. forgot to clock — the manager confirmed he came at 8' }" :error="errors.reason" />
    </div>
    <div v-if="edit" class="hint">
      {{ t('الوقت الحالي في السجل', 'Now on the record') }}: <span class="num ltr inline-block">{{ fmtAt(record.checkIn) }} → {{ fmtAt(record.checkOut) }}</span>
      <template v-if="record.corrected"> · {{ t('الأصل قبل أول تصحيح', 'Original before the first correction') }}: <span class="num ltr inline-block">{{ fmtAt(record.originalCheckIn) }} → {{ fmtAt(record.originalCheckOut) }}</span></template>
    </div>
  </FormShell>
</template>
