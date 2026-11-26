<script setup>
// The card of one employee: GET /hr/employees/{code}. Four parts — «العمل» (work: what the directory knows),
// «الملف الشخصي», «العقود», «الوثائق». The last three are shown only when the answer CARRIES them: the API leaves
// `private` + `contracts` out without hr.private and `documents` out without hr.manage / hr.private, so there is
// nothing here to hide — a part that is not in the answer is not a tab. The part on screen is in the query string (`etab`).
//   <EmployeeDrawer :code="nav.get('emp') || null" @close />
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, Drawer, EmptyState, ErrorBanner, KV, Tabs } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ContractForm from './ContractForm.vue';
import DocumentForm from './DocumentForm.vue';
import EmployeeForm from './EmployeeForm.vue';
import HrFiles from './HrFiles.vue';
import PrivateFileForm from './PrivateFileForm.vue';
import { CONTRACT_STATE, CONTRACT_TYPE, DOCUMENT_STATUS, EMPLOYEE_STATUS, FROM_ODOO, GENDER, MARITAL, PRIVATE_CHIP, STAGE, daysText, fmtAt, fmtDay, money, pname, useHrNav } from './shared';

const props = defineProps({ code: { type: String, default: null } });
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = useHrNav();
const detail = useGet(() => (props.code ? `/hr/employees/${encodeURIComponent(props.code)}` : null));
const e = computed(() => (detail.data.value && (detail.data.value.code === props.code || detail.data.value.id === props.code) ? detail.data.value : null));
const p = computed(() => e.value?.private || null);

const parts = computed(() => [
  { k: 'work', label: { ar: 'العمل', en: 'Work' } },
  ...(e.value && 'private' in e.value ? [{ k: 'personal', label: { ar: 'الملف الشخصي', en: 'Personal file' } }] : []),
  ...(e.value && 'contracts' in e.value ? [{ k: 'contracts', label: { ar: 'العقود', en: 'Contracts' }, badge: e.value.contracts.filter((c) => c.state === 'running').length || null }] : []),
  ...(e.value && 'documents' in e.value ? [{ k: 'documents', label: { ar: 'الوثائق', en: 'Documents' }, badge: (e.value.alerts || []).length || null }] : []),
]);
const part = computed(() => (parts.value.some((x) => x.k === nav.get('etab')) ? nav.get('etab') : 'work'));
const editing = ref(false);
const editingPrivate = ref(false);
const addingContract = ref(false);
const addingDocument = ref(false);
const docs = computed(() => e.value?.documents || []);
const dash = (v) => (v == null || v === '' ? '—' : v);
const expiry = (date, days) => (date ? `${fmtDay(date)} · ${daysText(days)}` : '—');
const stageOf = (days) => (days == null ? null : days < 0 ? 'expired' : days <= 7 ? 'd7' : days <= 30 ? 'd30' : days <= 60 ? 'd60' : null);
</script>

<template>
  <Drawer :open="!!code" :title="e ? pname(e) : code" :sub="e ? `${e.code}${e.jobTitle ? ` · ${e.jobTitle}` : ''}` : null" :width="620" @close="emit('close')">
    <ErrorBanner :error="detail.error.value" :closable="false" />
    <div v-if="!e && !detail.error.value" class="skel h-[180px]" />
    <template v-if="e">
      <div class="row wrap mb-3">
        <Chip :map="EMPLOYEE_STATUS" :k="e.status" />
        <Chip v-if="e.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        <Chip v-for="a in (e.alerts || []).slice(0, 3)" :key="a.id" small :map="STAGE" :k="a.stage" :title="t(a.textAr, a.textEn)">{{ t(a.textAr, a.textEn) }}</Chip>
        <span class="grow" />
        <Btn v-if="auth.can('hr.manage')" size="sm" tone="outline" :label="e.locked ? { ar: 'ربط / ملاحظات', en: 'Links / notes' } : { ar: 'تعديل', en: 'Edit' }" @click="editing = true" />
      </div>
      <Tabs v-if="parts.length > 1" :model-value="part" :tabs="parts" variant="sm" @update:model-value="(k) => nav.patch({ etab: k === 'work' ? null : k })" />

      <!-- ── work: what every colleague may know -->
      <template v-if="part === 'work'">
        <div class="kv-grid mb-4">
          <KV :k="{ ar: 'القسم', en: 'Department' }" :v="e.department ? pname(e.department) : '—'" />
          <KV :k="{ ar: 'المسمى الوظيفي', en: 'Job position' }" :v="e.job ? pname(e.job) : dash(e.jobTitle)" />
          <KV :k="{ ar: 'المدير المباشر', en: 'Manager' }">
            <button v-if="e.manager" type="button" class="btn ghost sm !h-auto !p-0 !text-[10.5px]" @click="nav.openEmployee(e.manager.code)">{{ pname(e.manager) }}</button>
            <template v-else>—</template>
          </KV>
          <KV :k="{ ar: 'تاريخ المباشرة', en: 'Start date' }" :v="fmtDay(e.startDate)" ltr />
          <KV :k="{ ar: 'بريد العمل', en: 'Work e-mail' }" :v="dash(e.workEmail)" ltr />
          <KV :k="{ ar: 'جوال العمل', en: 'Work mobile' }" :v="dash(e.workMobile)" ltr />
          <KV :k="{ ar: 'هاتف العمل', en: 'Work phone' }" :v="dash(e.workPhone)" ltr />
          <KV :k="{ ar: 'موقع العمل', en: 'Work location' }" :v="dash(e.workLocation)" />
          <KV :k="{ ar: 'الرقم الوظيفي', en: 'Staff number' }" :v="dash(e.employeeNo)" ltr />
          <KV :k="{ ar: 'مستخدم النظام', en: 'OPS login' }" :v="e.user ? e.user.username : t('بلا حساب', 'No login')" :ltr="!!e.user" />
          <KV v-if="e.driver" :k="{ ar: 'سجل السائق', en: 'Driver record' }" :v="e.driver.code" ltr />
        </div>
        <template v-if="e.manages.length">
          <div class="card-title mb-1">{{ t('يدير الأقسام', 'Manages') }}</div>
          <div class="row wrap mb-3"><span v-for="d in e.manages" :key="d.id" class="pill">{{ pname(d) }}</span></div>
        </template>
        <template v-if="e.reports.length">
          <div class="card-title mb-1">{{ t('يتبعون له مباشرة', 'Direct reports') }} <span class="card-count num ms-1">{{ e.reports.length }}</span></div>
          <div class="m-list mb-3">
            <button v-for="r in e.reports" :key="r.id" type="button" class="m-list-row !min-h-[44px]" @click="nav.openEmployee(r.code)">
              <span class="min-w-0 flex-1"><span class="cell-name">{{ pname(r) }}</span><span class="ms-2 text-[9.5px] text-muted">{{ r.jobTitle }}</span></span>
              <span class="cell-id">{{ r.code }}</span>
            </button>
          </div>
        </template>
        <div v-if="e.notes" class="hint !mt-0 whitespace-pre-line" dir="auto">{{ e.notes }}</div>
        <div class="mt-3 text-[9.5px] text-faint">{{ t('أُضيف', 'Added') }} <span class="num ltr">{{ fmtAt(e.createdAt) }}</span><template v-if="e.createdBy"> · {{ e.createdBy }}</template></div>
      </template>

      <!-- ── the personal file: here only because the answer carried it (hr.private) -->
      <template v-else-if="part === 'personal' && p">
        <div class="row mb-2">
          <Chip small :label="PRIVATE_CHIP" :fg="PRIVATE_CHIP.fg" :bg="PRIVATE_CHIP.bg" />
          <span class="grow" />
          <Btn size="sm" tone="outline" :label="{ ar: 'تعديل الملف الشخصي', en: 'Edit the personal file' }" @click="editingPrivate = true" />
        </div>
        <div class="kv-grid mb-4">
          <KV :k="{ ar: 'الجنسية', en: 'Nationality' }" :v="dash(p.nationality)" />
          <KV :k="{ ar: 'تاريخ الميلاد', en: 'Birth date' }" :v="fmtDay(p.birthDate)" ltr />
          <KV :k="{ ar: 'رقم الهوية / الإقامة', en: 'ID / residence no.' }" :v="dash(p.identityNo)" ltr />
          <KV :k="{ ar: 'انتهاء الهوية / الإقامة', en: 'ID / residence expiry' }">
            <span class="num-mixed">{{ expiry(p.identityExpiry, p.identityDays) }}</span> <Chip v-if="stageOf(p.identityDays)" small :map="STAGE" :k="stageOf(p.identityDays)" />
          </KV>
          <KV :k="{ ar: 'رقم الجواز', en: 'Passport no.' }" :v="dash(p.passportNo)" ltr />
          <KV :k="{ ar: 'انتهاء الجواز', en: 'Passport expiry' }">
            <span class="num-mixed">{{ expiry(p.passportExpiry, p.passportDays) }}</span> <Chip v-if="stageOf(p.passportDays)" small :map="STAGE" :k="stageOf(p.passportDays)" />
          </KV>
          <KV :k="{ ar: 'الجنس', en: 'Gender' }" :v="p.gender ? bi(GENDER[p.gender] || p.gender) : '—'" />
          <KV :k="{ ar: 'الحالة الاجتماعية', en: 'Marital status' }" :v="p.maritalStatus ? bi(MARITAL[p.maritalStatus] || p.maritalStatus) : '—'" />
          <KV :k="{ ar: 'عدد الأبناء', en: 'Children' }" :v="p.children ?? '—'" ltr />
          <KV :k="{ ar: 'الجوال الشخصي', en: 'Private phone' }" :v="dash(p.privatePhone)" ltr />
          <KV :k="{ ar: 'البريد الشخصي', en: 'Private e-mail' }" :v="dash(p.privateEmail)" ltr />
          <KV :k="{ ar: 'عنوان السكن', en: 'Home address' }" :v="dash(p.homeAddress)" />
          <KV :k="{ ar: 'البنك', en: 'Bank' }" :v="dash(p.bankName)" />
          <KV :k="{ ar: 'رقم الحساب (IBAN)', en: 'Account (IBAN)' }" :v="dash(p.bankAccount)" ltr />
          <KV :k="{ ar: 'جهة الطوارئ', en: 'Emergency contact' }" :v="dash(p.emergencyContact)" />
          <KV :k="{ ar: 'هاتف الطوارئ', en: 'Emergency phone' }" :v="dash(p.emergencyPhone)" ltr />
          <KV v-if="p.endDate || p.departureReason" :k="{ ar: 'ترك العمل', en: 'Left' }" :v="`${fmtDay(p.endDate)}${p.departureReason ? ` · ${p.departureReason}` : ''}`" />
        </div>
        <div v-if="p.notes" class="hint !mt-0 mb-3 whitespace-pre-line" dir="auto">{{ p.notes }}</div>
        <template v-if="(e.files || []).length">
          <div class="card-title mb-1">{{ t('أوراق مرفقة بالموظف في أودو', 'Papers attached to the employee in Odoo') }}</div>
          <HrFiles :files="e.files" />
        </template>
        <div v-if="p.updatedAt" class="mt-3 text-[9.5px] text-faint">{{ t('آخر تعديل', 'Last change') }} <span class="num ltr">{{ fmtAt(p.updatedAt) }}</span> · {{ p.updatedBy }}</div>
      </template>

      <!-- ── contracts (hr.private) -->
      <template v-else-if="part === 'contracts'">
        <div class="row mb-2"><span class="grow" /><Btn size="sm" tone="primary" :label="{ ar: '+ عقد', en: '+ Contract' }" @click="addingContract = true" /></div>
        <EmptyState v-if="!e.contracts.length" :text="{ ar: 'لا عقود مسجَّلة لهذا الموظف.', en: 'No contract on record for this employee.' }" />
        <div v-else class="m-list">
          <button v-for="c in e.contracts" :key="c.id" type="button" class="m-list-row" @click="nav.openContract(c.number)">
            <span class="min-w-0 flex-1">
              <span class="cell-id">{{ c.number }}</span> <span class="ms-1 text-[10px] font-bold">{{ c.typeLabel || bi(CONTRACT_TYPE[c.type] || c.type) }}</span>
              <span class="block text-[9.5px] text-muted"><span class="num ltr">{{ fmtDay(c.startDate) }} → {{ c.endDate ? fmtDay(c.endDate) : '∞' }}</span> · <span class="num ltr">{{ money(c.package) }}</span> {{ t('شهريًا', 'a month') }}</span>
            </span>
            <Chip v-if="c.stage" small :map="STAGE" :k="c.stage" /><Chip small :map="CONTRACT_STATE" :k="c.state" />
          </button>
        </div>
      </template>

      <!-- ── documents (hr.manage; the private ones only with hr.private — the answer simply does not list them otherwise) -->
      <template v-else-if="part === 'documents'">
        <div class="row mb-2">
          <Btn size="sm" tone="ghost" :label="{ ar: 'كل وثائقه في السجل', en: 'All their papers in the register' }" @click="nav.patch({ tab: 'due', view: 'register', employee: e.id, emp: null, etab: null, page: null, q: null })" />
          <span class="grow" />
          <Btn v-if="auth.can('hr.manage')" size="sm" tone="primary" :label="{ ar: '+ وثيقة', en: '+ Document' }" @click="addingDocument = true" />
        </div>
        <EmptyState v-if="!docs.length" :text="{ ar: 'لا وثائق مسجَّلة لهذا الموظف.', en: 'No document on record for this employee.' }" />
        <div v-else class="m-list">
          <div v-for="d in docs" :key="d.id" class="m-list-row !cursor-default">
            <span class="min-w-0 flex-1">
              <span class="cell-name">{{ bi(d.kindLabel) }}</span><span v-if="d.title" class="ms-1.5 text-[10px] text-sec">{{ d.title }}</span>
              <span class="block text-[9.5px] text-muted"><template v-if="d.number"><span class="num ltr">{{ d.number }}</span> · </template>{{ d.expiryDate ? `${t('تنتهي', 'expires')} ${fmtDay(d.expiryDate)}` : t('بلا تاريخ انتهاء', 'no expiry date') }}</span>
              <HrFiles :files="d.files" />
            </span>
            <Chip v-if="d.private" small :label="PRIVATE_CHIP" :fg="PRIVATE_CHIP.fg" :bg="PRIVATE_CHIP.bg" />
            <Chip v-if="d.status !== 'active'" small :map="DOCUMENT_STATUS" :k="d.status" />
            <Chip v-else-if="d.stage" small :map="STAGE" :k="d.stage" />
          </div>
        </div>
      </template>
    </template>
  </Drawer>

  <EmployeeForm :open="editing" :employee="e" @close="editing = false" />
  <PrivateFileForm :open="editingPrivate" :employee="e" @close="editingPrivate = false" />
  <ContractForm :open="addingContract" :employee-id="e ? e.id : null" @close="addingContract = false" @done="(c) => nav.openContract(c.number)" />
  <DocumentForm :open="addingDocument" mode="create" :employee-id="e ? e.id : null" @close="addingDocument = false" />
</template>
