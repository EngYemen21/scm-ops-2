<script setup>
// A leave request — new (POST /payroll/leaves { employee?, type, dateFrom, dateTo, halfDay, reason, submit }) or a draft
// being edited (PATCH /payroll/leaves/{id} { type, dateFrom, dateTo, halfDay, reason }, then POST …/submit to send it).
// «إرسال للاعتماد» sends it at once (a type that needs no approval is approved as it is sent); «حفظ مسودة» keeps it.
// The days are counted by the server on the person's own schedule (weekends and public holidays out); the form shows
// the balance left for a type that is counted against days granted. `forOthers` lets a leave officer enter it for an employee.
//   <LeaveForm :open :leave="draftOrNull" :for-others="bool" @close @done="(leave) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { DateInput, SelectInput, TextArea } from '@/components';
import { bi, t } from '@/i18n';
import FormShell from './FormShell.vue';
import { APPROVAL, SELECT, days, own, pname, todayIso, usePayrollLookups } from './payroll';

const props = defineProps({
  open: { type: Boolean, default: false },
  leave: { type: Object, default: null },
  forOthers: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['payroll'] });
const lk = usePayrollLookups(() => props.open);
const { leaveTypes, leaveTypeOptions, employeeOptions } = lk;
const edit = computed(() => !!props.leave);
const f = reactive({ employee: '', type: '', dateFrom: '', dateTo: '', halfDay: false, reason: '' });
const errors = ref({});

watch(() => [props.open, props.leave], () => {
  if (!props.open) return;
  const l = props.leave;
  Object.assign(f, l
    ? { employee: l.employee?.id || '', type: l.type?.id || '', dateFrom: l.dateFrom || '', dateTo: l.dateTo || '', halfDay: !!l.halfDay, reason: l.reason || '' }
    : { employee: '', type: '', dateFrom: todayIso(), dateTo: todayIso(), halfDay: false, reason: '' });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });
// a later start pulls the end along
watch(() => f.dateFrom, (v) => { if (v && (!f.dateTo || f.dateTo < v)) f.dateTo = v; });

const kind = computed(() => leaveTypes.value.find((x) => x.id === f.type) || null);
const oneDay = computed(() => !!f.dateFrom && f.dateFrom === f.dateTo);
watch([oneDay, kind], () => { if (!oneDay.value || !kind.value?.allowHalfDay) f.halfDay = false; });
const year = computed(() => Number(String(f.dateFrom || todayIso()).slice(0, 4)));
const whose = computed(() => (props.forOthers ? f.employee : null) || (edit.value ? props.leave.employee?.id : null));
const balances = useGet(() => (props.open && kind.value?.requiresAllocation && (!props.forOthers || f.employee) ? '/payroll/leave-balances' : null), () => ({ employee: whose.value || undefined, year: year.value }));
const balance = computed(() => (balances.data.value?.balances || []).find((b) => b.type.id === f.type) || null);

const ok = computed(() => !!f.type && !!f.dateFrom && !!f.dateTo && f.dateTo >= f.dateFrom && (!props.forOthers || edit.value || !!f.employee));
const body = () => ({ type: f.type, dateFrom: f.dateFrom, dateTo: f.dateTo, halfDay: f.halfDay, reason: f.reason.trim() || null });

async function save(send) {
  if (!ok.value) return;
  let r;
  if (edit.value) {
    r = await act.run(async () => {
      const saved = await api.patch(`/payroll/leaves/${encodeURIComponent(props.leave.id)}`, body());
      return send ? api.postIdempotent(`/payroll/leaves/${encodeURIComponent(saved.id)}/submit`) : saved;
    }, { success: send ? t('حُفظ الطلب وأُرسل للاعتماد', 'Saved and sent for approval') : t('حُفظ الطلب', 'Request saved') });
  } else {
    r = await act.run(() => api.postIdempotent('/payroll/leaves', { ...body(), ...(props.forOthers && f.employee ? { employee: f.employee } : {}), submit: send }), {
      success: (l) => (send ? (l.state === 'approved' ? t(`اعتُمدت الإجازة ${l.number} مباشرة (نوع بلا اعتماد)`, `Leave ${l.number} approved at once (no approval needed)`) : t(`أُرسل الطلب ${l.number} للاعتماد`, `Request ${l.number} sent for approval`)) : t(`حُفظ الطلب ${l.number} مسودة`, `Request ${l.number} saved as a draft`)),
    });
  }
  if (r) { emit('done', r); emit('close'); }
}
const title = computed(() => (edit.value ? { ar: `تعديل الطلب ${props.leave.number}`, en: `Edit request ${props.leave.number}` } : { ar: 'طلب إجازة', en: 'Leave request' }));
</script>

<template>
  <FormShell :open="open" :title="title" :sub="{ ar: 'الأيام تُحسب على جدول دوامك: العطل الأسبوعية والرسمية لا تُحسب', en: 'Days are counted on your schedule: weekends and public holidays are not counted' }"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="edit ? { ar: 'حفظ وإرسال للاعتماد', en: 'Save and send' } : { ar: 'إرسال للاعتماد', en: 'Send for approval' }"
             submit-tone="primary" :second-label="edit ? { ar: 'حفظ', en: 'Save' } : { ar: 'حفظ مسودة', en: 'Save as draft' }" @submit="save(true)" @second="save(false)" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-if="forOthers && !edit" v-model="f.employee" full required :label="{ ar: 'الموظف', en: 'Employee' }" :options="employeeOptions" :placeholder="{ ar: '— نفسي —', en: '— myself —' }" :hint="{ ar: 'اتركه فارغًا لطلب إجازة لنفسك', en: 'Leave it empty to ask for yourself' }" :error="errors.employee" />
      <SelectInput v-model="f.type" full required :label="{ ar: 'نوع الإجازة', en: 'Leave type' }" :options="leaveTypeOptions" :placeholder="SELECT" :error="errors.type" />
      <DateInput v-model="f.dateFrom" required :label="{ ar: 'من', en: 'From' }" :error="errors.dateFrom" />
      <DateInput v-model="f.dateTo" required :min="f.dateFrom || null" :label="{ ar: 'إلى', en: 'To' }" :error="errors.dateTo" />
      <label v-if="kind?.allowHalfDay && oneDay" class="field full flex-row items-center gap-2 text-[11px] font-bold">
        <input v-model="f.halfDay" type="checkbox" class="h-4 w-4">{{ t('نصف يوم', 'Half a day') }}
      </label>
      <TextArea v-model="f.reason" :rows="3" :label="{ ar: 'السبب / ملاحظة (اختياري)', en: 'Reason / note (optional)' }" :hint="{ ar: 'يراه من يقرّر الطلب فقط، ولا يُكتب في سجل التدقيق', en: 'Only the deciders see it; it is not written to the audit trail' }" :error="errors.reason" />
    </div>

    <div v-if="kind" class="hint">
      <div>{{ t('الاعتماد', 'Approval') }}: <b>{{ bi(own(APPROVAL, kind.approval) || { ar: kind.approval, en: kind.approval }) }}</b> · {{ kind.paid ? t('مدفوعة الأجر', 'Paid') : t('غير مدفوعة — تُخصم من الراتب', 'Unpaid — deducted from pay') }}<template v-if="kind.requiresDocument"> · {{ t('تحتاج مستندًا مؤيدًا', 'Needs a supporting paper') }}</template></div>
      <div v-if="kind.requiresAllocation">
        <template v-if="balances.isLoading.value">{{ t('جارٍ قراءة الرصيد…', 'Reading the balance…') }}</template>
        <template v-else-if="balance">{{ t(`المتاح من «${pname(kind)}» لسنة ${year}`, `Available of “${pname(kind)}” for ${year}`) }}: <b class="num">{{ days(balance.available) }}</b> {{ t('يوم', 'day(s)') }}<template v-if="balance.pending"> · {{ t('منها بانتظار القرار', 'waiting') }} <span class="num">{{ days(balance.pending) }}</span></template><template v-if="kind.allowNegative"> · {{ t('يُسمح بالسالب', 'may go negative') }}</template></template>
        <template v-else-if="forOthers && !f.employee && !edit">{{ t('اختر الموظف لرؤية رصيده.', 'Pick the employee to see the balance.') }}</template>
      </div>
      <div v-else>{{ t('هذا النوع لا يُحسب من رصيد ممنوح.', 'This type is not counted against granted days.') }}</div>
    </div>
  </FormShell>
</template>
