<script setup>
// An employment contract (hr.private): POST /hr/contracts · PATCH /hr/contracts/{id} — an edit sends only what changed.
// A new contract is a draft; «تشغيل» on its card puts it in force. A contract that came from Odoo keeps Odoo's terms
// while the section follows Odoo: only a note can be added to it here.
//   <ContractForm :open :contract="row | null" :employee-id @close @done="(contract) => …" />
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import HrFormDrawer from './HrFormDrawer.vue';
import { CONTRACT_TYPE, SELECT, changedOnly, money, optionsOf, pname, useHrLookups } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The contract to edit; null writes a new one. */
  contract: { type: Object, default: null },
  /** New contract: the employee, when the form is opened from their card. */
  employeeId: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['hr'] });
const lookups = useHrLookups(() => props.open);
const f = reactive({});
const numberOrNull = (v) => (v == null || v === '' ? null : Number(v));
const initial = (c) => ({
  employee: c?.employee?.id || c?.employeeId || props.employeeId || '', type: c?.type || 'permanent', jobTitle: c?.jobTitle || '', departmentId: c?.department?.id || '', reference: c?.reference || '',
  startDate: c?.startDate || '', endDate: c?.endDate || '', trialEndDate: c?.trialEndDate || '', wage: numberOrNull(c?.wage), housingAllowance: numberOrNull(c?.housingAllowance) ?? 0,
  transportAllowance: numberOrNull(c?.transportAllowance) ?? 0, otherAllowances: numberOrNull(c?.otherAllowances) ?? 0, notes: c?.notes || '',
});
let before = initial(null);
watch(() => props.open, (open) => { if (open) { before = initial(props.contract); Object.assign(f, before); act.clearError(); } }, { immediate: true });

const locked = computed(() => !!props.contract?.locked);
const pack = computed(() => (Number(f.wage) || 0) + (Number(f.housingAllowance) || 0) + (Number(f.transportAllowance) || 0) + (Number(f.otherAllowances) || 0));
const ok = computed(() => locked.value || (!!f.employee && !!f.startDate && f.wage != null && Number(f.wage) >= 0 && (!f.endDate || f.endDate >= f.startDate)));

async function submit() {
  let saved;
  if (props.contract) {
    const now = locked.value ? { notes: f.notes.trim() } : { type: f.type, jobTitle: f.jobTitle.trim(), departmentId: f.departmentId, reference: f.reference.trim(), startDate: f.startDate, endDate: f.endDate,
      trialEndDate: f.trialEndDate, wage: f.wage, housingAllowance: f.housingAllowance ?? 0, transportAllowance: f.transportAllowance ?? 0, otherAllowances: f.otherAllowances ?? 0, notes: f.notes.trim() };
    const body = changedOnly(before, now);
    if (!Object.keys(body).length) { emit('close'); return; }
    saved = await act.run(() => api.patch(`/hr/contracts/${props.contract.id}`, body), { success: t('حُفظ العقد', 'Contract saved') });
  } else {
    const body = { employee: f.employee, type: f.type, jobTitle: f.jobTitle.trim() || null, departmentId: f.departmentId || null, reference: f.reference.trim() || null, startDate: f.startDate, endDate: f.endDate || null,
      trialEndDate: f.trialEndDate || null, wage: Number(f.wage), housingAllowance: Number(f.housingAllowance) || 0, transportAllowance: Number(f.transportAllowance) || 0, otherAllowances: Number(f.otherAllowances) || 0, notes: f.notes.trim() || null };
    saved = await act.run(() => api.postIdempotent('/hr/contracts', body), { success: (c) => t(`أُنشئ العقد ${c.number} كمسودة`, `Contract ${c.number} created as a draft`) });
  }
  if (saved) { emit('done', saved); emit('close'); }
}
</script>

<template>
  <HrFormDrawer :open="open" :title="contract ? { ar: `تعديل ${contract.number}`, en: `Edit ${contract.number}` } : { ar: 'عقد عمل جديد', en: 'New employment contract' }"
                :sub="contract?.employee ? pname(contract.employee) : null" :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
                :missing="{ ar: 'حدِّد الموظف، تاريخ البداية والأجر — ونهاية لا تسبق البداية', en: 'Name the employee, the start date and the wage — and an end that is not before the start' }"
                :note="{ ar: 'يُسجَّل في سجل التدقيق أسماء الحقول المعدَّلة فقط — لا المبالغ', en: 'The audit trail records the names of the changed fields only — never the amounts' }"
                @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="locked" class="banner teal"><div>{{ t('هذا العقد مصدره أودو: بنوده تُعدَّل هناك. يمكنك هنا إضافة ملاحظة، أو تجديده بعقد جديد.', 'This contract comes from Odoo: its terms are changed there. Here you can add a note, or renew it with a new contract.') }}</div></div>
    <div class="form-grid">
      <template v-if="!locked">
        <SelectInput v-if="!contract" v-model="f.employee" :label="{ ar: 'الموظف', en: 'Employee' }" required :options="lookups.employeeOptions.value" :placeholder="SELECT" :disabled="!!employeeId" full />
        <SelectInput v-model="f.type" :label="{ ar: 'نوع العقد', en: 'Contract type' }" :options="optionsOf(CONTRACT_TYPE)" />
        <TextInput v-model="f.reference" :label="{ ar: 'مرجع العقد الورقي', en: 'Reference of the paper contract' }" dir="ltr" />
        <TextInput v-model="f.jobTitle" :label="{ ar: 'المسمى في العقد', en: 'Job title in the contract' }" :hint="{ ar: 'يُترك فارغًا ليؤخذ من بطاقة الموظف', en: 'Leave empty to take it from the employee card' }" />
        <SelectInput v-model="f.departmentId" :label="{ ar: 'القسم', en: 'Department' }" :options="lookups.departmentOptions.value" :placeholder="{ ar: '— كما في بطاقة الموظف —', en: '— as on the employee card —' }" />
        <DateInput v-model="f.startDate" :label="{ ar: 'بداية العقد', en: 'Start' }" required />
        <DateInput v-model="f.endDate" :label="{ ar: 'نهاية العقد', en: 'End' }" :min="f.startDate || null" :hint="{ ar: 'فارغ = غير محدد المدة', en: 'Empty = open-ended' }" />
        <DateInput v-model="f.trialEndDate" :label="{ ar: 'نهاية فترة التجربة', en: 'End of the trial period' }" :min="f.startDate || null" />
        <NumberInput v-model="f.wage" :label="{ ar: 'الأجر الأساسي الشهري', en: 'Monthly basic wage' }" required :min="0" />
        <NumberInput v-model="f.housingAllowance" :label="{ ar: 'بدل السكن', en: 'Housing allowance' }" :min="0" />
        <NumberInput v-model="f.transportAllowance" :label="{ ar: 'بدل النقل', en: 'Transport allowance' }" :min="0" />
        <NumberInput v-model="f.otherAllowances" :label="{ ar: 'بدلات أخرى', en: 'Other allowances' }" :min="0" />
        <div class="field full"><div class="hint teal !mt-0">{{ t('إجمالي الشهر', 'Monthly package') }}: <b class="num">{{ money(pack) }}</b> {{ t('ر.س', 'SAR') }}</div></div>
      </template>
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" />
    </div>
  </HrFormDrawer>
</template>
