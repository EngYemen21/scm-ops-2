<script setup>
// The two steps of a run that touch the books (payroll.manage + gl.post):
//   mode="post"  POST /payroll/runs/{id}/post { date? } — one balanced journal through the finance ledger (date: the end of the month by default)
//   mode="pay"   POST /payroll/runs/{id}/pay { bankAccount, reference, date? } — the net leaves a bank account or cash box;
//                the expense claims the run reimburses are marked paid with the run as their reference
//   <RunBooksForm :run="runOrNull" mode="post|pay" @close />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import FormShell from './FormShell.vue';
import { SELECT, money, todayIso, usePayrollLookups } from './payroll';

const props = defineProps({
  run: { type: Object, default: null },
  mode: { type: String, default: 'post' },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['payroll'] });
const { bankOptions } = usePayrollLookups(() => !!props.run && props.mode === 'pay');
const f = reactive({ date: '', bankAccount: '', reference: '' });
const errors = ref({});
watch(() => [props.run, props.mode], () => {
  if (!props.run) return;
  Object.assign(f, { date: props.mode === 'post' ? props.run.dateTo || '' : todayIso(), bankAccount: '', reference: '' });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const pay = computed(() => props.mode === 'pay');
const ok = computed(() => (pay.value ? !!f.bankAccount && f.reference.trim().length >= 2 && (!f.date || f.date <= todayIso()) : true));
async function save() {
  if (!ok.value) return;
  const id = encodeURIComponent(props.run.id);
  const r = pay.value
    ? await act.run(() => api.postIdempotent(`/payroll/runs/${id}/pay`, { bankAccount: f.bankAccount, reference: f.reference.trim(), ...(f.date ? { date: f.date } : {}) }), { success: t('سُجِّل صرف المسير', 'Payment recorded') })
    : await act.run(() => api.postIdempotent(`/payroll/runs/${id}/post`, f.date ? { date: f.date } : undefined), { success: (x) => t(`رُحِّل المسير — قيد ${x.journalNumber || ''}`, `Run posted — journal ${x.journalNumber || ''}`) });
  if (r) emit('close');
}
</script>

<template>
  <FormShell :open="!!run" :width="480" :z-index="72" :title="pay ? { ar: 'صرف المسير', en: 'Pay the run' } : { ar: 'ترحيل المسير إلى الدفاتر', en: 'Post the run to the books' }"
             :sub="run ? `${run.number} · ${t('الصافي', 'net')} ${money(run.net)}` : null" :submit-label="pay ? { ar: 'تسجيل الصرف', en: 'Record the payment' } : { ar: 'ترحيل', en: 'Post' }" submit-tone="primary"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="pay ? { ar: 'اختر الحساب واكتب مرجع الصرف (والتاريخ لا يكون في المستقبل)', en: 'Pick the account and write the reference (the date cannot be in the future)' } : null"
             @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <template v-if="pay">
        <SelectInput v-model="f.bankAccount" full required :label="{ ar: 'من حساب بنكي / صندوق', en: 'From bank account / cash box' }" :options="bankOptions" :placeholder="SELECT" :error="errors.bankAccount" />
        <TextInput v-model="f.reference" required dir="ltr" :label="{ ar: 'مرجع الصرف', en: 'Payment reference' }" :placeholder="{ ar: 'رقم ملف التحويل أو الحوالة', en: 'Transfer file or remittance number' }" :error="errors.reference" />
        <DateInput v-model="f.date" :max="todayIso()" :label="{ ar: 'تاريخ الصرف', en: 'Payment date' }" :error="errors.date" />
      </template>
      <DateInput v-else v-model="f.date" :label="{ ar: 'تاريخ القيد', en: 'Journal date' }" :hint="{ ar: 'افتراضيًا آخر يوم في شهر المسير؛ الفترة المحاسبية يجب أن تكون مفتوحة', en: 'By default the last day of the month; the accounting period must be open' }" :error="errors.date" />
    </div>
    <div class="hint">{{ pay
      ? t('يُكتب قيد صرف: من «رواتب مستحقة الدفع» إلى الحساب المختار، وتُعلَّم مطالبات المصروفات التي يعوّضها المسير مسدَّدة. يُتراجع عنه بـ«التراجع عن الصرف» بسبب.', 'A payment journal is written: from “salaries payable” to the chosen account, and the expense claims the run reimburses are marked paid. It is taken back with “Take the payment back” and a reason.')
      : t('يُكتب قيد واحد متوازن من سطور القسائم نفسها عبر دفتر الأستاذ في المالية. يُرفض إن كان بند توجيه بلا حساب — راجع «معاينة القيد».', 'One balanced journal is written from the payslip lines themselves through the finance ledger. It is refused when a posting key has no account — see the journal preview.') }}</div>
  </FormShell>
</template>
