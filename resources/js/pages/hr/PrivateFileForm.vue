<script setup>
// The personal file of an employee (hr.private): PUT /hr/employees/{id}/private — only what changed is sent.
// For an employee that came from Odoo, the fields Odoo holds are locked while the section follows Odoo
// (`file.ownedFields`); the expiry of the identity, the passport and the notes are OPS's own and stay editable.
//   <PrivateFileForm :open :employee="card" @close />
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import HrFormDrawer from './HrFormDrawer.vue';
import { GENDER, MARITAL, changedOnly, optionsOf, pname, todayIso } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The employee card (it carries `private` for a holder of hr.private). */
  employee: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['hr'] });
const FIELDS = ['nationality', 'identityNo', 'identityExpiry', 'passportNo', 'passportExpiry', 'birthDate', 'gender', 'maritalStatus', 'children', 'homeAddress', 'privatePhone', 'privateEmail',
  'bankName', 'bankAccount', 'emergencyContact', 'emergencyPhone', 'endDate', 'departureReason', 'notes'];
const f = reactive({});
const initial = (p) => Object.fromEntries(FIELDS.map((k) => [k, p?.[k] ?? (k === 'children' ? null : '')]));
let before = initial(null);
watch(() => props.open, (open) => { if (open) { before = initial(props.employee?.private); Object.assign(f, before); act.clearError(); } }, { immediate: true });

const owned = computed(() => new Set(props.employee?.private?.ownedFields || []));
const locked = (field) => owned.value.has(field);
const none = { ar: '—', en: '—' };

async function submit() {
  const now = Object.fromEntries(FIELDS.map((k) => [k, typeof f[k] === 'string' ? f[k].trim() : f[k]]));
  const body = changedOnly(before, now);
  if (!Object.keys(body).length) { emit('close'); return; }
  const saved = await act.run(() => api.put(`/hr/employees/${props.employee.id}/private`, body), { success: t('حُفظ الملف الشخصي', 'Personal file saved') });
  if (saved) emit('close');
}
</script>

<template>
  <HrFormDrawer :open="open" :title="{ ar: 'الملف الشخصي', en: 'Personal file' }" :sub="employee ? `${pname(employee)} · ${employee.code}` : null" :width="600"
                :pending="act.pending.value" :error="act.error.value" :note="{ ar: 'يُسجَّل في سجل التدقيق أسماء الحقول المعدَّلة فقط — لا قيمها', en: 'The audit trail records the names of the changed fields only — never their values' }"
                @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="owned.size" class="banner teal">
      <div>{{ t('هذا الموظف مصدره أودو: الحقول المقفلة تُعدَّل في أودو. تاريخ انتهاء الهوية، الجواز وتاريخه، والملاحظات تُحفظ هنا.', 'This employee comes from Odoo: the locked fields are changed in Odoo. The expiry of the identity, the passport and its expiry, and the notes are kept here.') }}</div>
    </div>
    <div class="card-title mb-1.5">{{ t('الهوية والجواز', 'Identity and passport') }}</div>
    <div class="form-grid mb-4">
      <TextInput v-model="f.nationality" :label="{ ar: 'الجنسية', en: 'Nationality' }" :disabled="locked('nationality')" />
      <DateInput v-model="f.birthDate" :label="{ ar: 'تاريخ الميلاد', en: 'Birth date' }" :max="todayIso()" :disabled="locked('birthDate')" />
      <TextInput v-model="f.identityNo" :label="{ ar: 'رقم الهوية / الإقامة', en: 'ID / residence number' }" dir="ltr" :disabled="locked('identityNo')" />
      <DateInput v-model="f.identityExpiry" :label="{ ar: 'انتهاء الهوية / الإقامة', en: 'ID / residence expiry' }" :hint="{ ar: 'يُنبَّه قبل الانتهاء بـ 60 و30 و7 أيام', en: 'Alerts 60, 30 and 7 days before' }" :disabled="locked('identityExpiry')" />
      <TextInput v-model="f.passportNo" :label="{ ar: 'رقم الجواز', en: 'Passport number' }" dir="ltr" :disabled="locked('passportNo')" />
      <DateInput v-model="f.passportExpiry" :label="{ ar: 'انتهاء الجواز', en: 'Passport expiry' }" :disabled="locked('passportExpiry')" />
    </div>
    <div class="card-title mb-1.5">{{ t('الحالة الاجتماعية والسكن', 'Family and home') }}</div>
    <div class="form-grid mb-4">
      <SelectInput v-model="f.gender" :label="{ ar: 'الجنس', en: 'Gender' }" :options="optionsOf(GENDER)" :placeholder="none" :disabled="locked('gender')" />
      <SelectInput v-model="f.maritalStatus" :label="{ ar: 'الحالة الاجتماعية', en: 'Marital status' }" :options="optionsOf(MARITAL)" :placeholder="none" :disabled="locked('maritalStatus')" />
      <NumberInput v-model="f.children" :label="{ ar: 'عدد الأبناء', en: 'Children' }" :min="0" :max="30" :disabled="locked('children')" />
      <TextInput v-model="f.privatePhone" :label="{ ar: 'الجوال الشخصي', en: 'Private phone' }" dir="ltr" :disabled="locked('privatePhone')" />
      <TextInput v-model="f.privateEmail" :label="{ ar: 'البريد الشخصي', en: 'Private e-mail' }" dir="ltr" type="email" :disabled="locked('privateEmail')" />
      <TextArea v-model="f.homeAddress" :label="{ ar: 'عنوان السكن', en: 'Home address' }" :rows="2" :disabled="locked('homeAddress')" />
    </div>
    <div class="card-title mb-1.5">{{ t('الحساب البنكي والطوارئ', 'Bank account and emergency') }}</div>
    <div class="form-grid mb-4">
      <TextInput v-model="f.bankName" :label="{ ar: 'البنك', en: 'Bank' }" :disabled="locked('bankName')" />
      <TextInput v-model="f.bankAccount" :label="{ ar: 'رقم الحساب (IBAN)', en: 'Account number (IBAN)' }" dir="ltr" mono :disabled="locked('bankAccount')" />
      <TextInput v-model="f.emergencyContact" :label="{ ar: 'جهة الاتصال للطوارئ', en: 'Emergency contact' }" :disabled="locked('emergencyContact')" />
      <TextInput v-model="f.emergencyPhone" :label="{ ar: 'هاتف الطوارئ', en: 'Emergency phone' }" dir="ltr" :disabled="locked('emergencyPhone')" />
    </div>
    <div class="card-title mb-1.5">{{ t('ترك العمل', 'Leaving') }}</div>
    <div class="form-grid">
      <DateInput v-model="f.endDate" :label="{ ar: 'تاريخ ترك العمل', en: 'Leaving date' }" :disabled="locked('endDate')" />
      <TextInput v-model="f.departureReason" :label="{ ar: 'سبب ترك العمل', en: 'Reason for leaving' }" :disabled="locked('departureReason')" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" />
    </div>
  </HrFormDrawer>
</template>
