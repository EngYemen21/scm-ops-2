<script setup>
// «وثائق مستحقة» — what expires (hr.manage or hr.private): two views of the same papers.
//   المستحق   GET /hr/alerts?stage=&category=&q=&page=        one line per paper, identity, passport or contract that is
//             within 60 days of its date or past it — the stages 60 / 30 / 7 days and "expired". «تحديث» re-reads the dates.
//   السجل     GET /hr/documents?due=&kind=&status=&employee=&q=&page=   every registered document; add, renew, edit, void.
// What belongs to the personal file (identity, passport, contracts, private papers) is in neither list without
// hr.private — the server leaves it out, so this tab shows whatever it is given. View, filters and page are in the query string.
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import DocumentForm from './DocumentForm.vue';
import HrFiles from './HrFiles.vue';
import { ALERT_CATEGORY, ANY, DOCUMENT_STATUS, PRIVATE_CHIP, STAGE, STAGES, daysText, fmtDay, optionsOf, pname, useHrLookups, useHrNav, useSearchParam } from './shared';

const auth = useAuth();
const nav = useHrNav();
const { page } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const view = computed(() => (nav.get('view') === 'register' ? 'register' : 'alerts'));
const views = [{ k: 'alerts', label: { ar: 'المستحق والمنتهي', en: 'Due and expired' } }, { k: 'register', label: { ar: 'سجل الوثائق', en: 'Document register' } }];
const act = useAction({ invalidate: ['hr'] });

// ---- what is due
const stage = computed(() => nav.get('stage').split(',').filter((x) => STAGES.includes(x)).join(','));
const category = computed(() => (Object.keys(ALERT_CATEGORY).includes(nav.get('category')) ? nav.get('category') : ''));
const alerts = useList(() => (view.value === 'alerts' ? '/hr/alerts' : null), () => ({ q: nav.get('q'), stage: stage.value || undefined, category: category.value || undefined, page: page.value, pageSize: 25 }));
const counts = computed(() => alerts.data.value?.counts || null);
const stageTabs = computed(() => [{ k: '', label: ANY, badge: counts.value?.total ?? null }, ...STAGES.map((k) => ({ k, label: STAGE[k], badge: counts.value?.[k] ?? null }))]);
const alertColumns = [
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(180px,1.4fr)' },
  { key: 'what', header: { ar: 'ما الذي ينتهي', en: 'What expires' }, width: 'minmax(220px,2fr)' },
  { key: 'dueDate', header: { ar: 'التاريخ', en: 'Date' }, width: '92px', kind: 'date', value: (r) => fmtDay(r.dueDate) },
  { key: 'days', header: { ar: 'المتبقي', en: 'Time left' }, width: '110px', value: (r) => daysText(r.days) },
  { key: 'stage', header: { ar: 'المرحلة', en: 'Stage' }, width: '128px', bare: true },
];
/** An alert leads to where the date is renewed: the contract, the personal file, or the employee's documents. */
const openAlert = (a) => (a.category === 'contract' ? nav.openContract(a.refId) : nav.openEmployee(a.employee.code, a.category === 'document' ? 'documents' : 'personal'));
const refresh = () => act.run(() => api.postIdempotent('/hr/alerts/refresh'), { success: (r) => t(`حُدِّثت التنبيهات — جديد ${fmtNum(r.created)} · أُقفل ${fmtNum(r.resolved)}`, `Alerts refreshed — ${fmtNum(r.created)} new · ${fmtNum(r.resolved)} resolved`) });

// ---- the register
const lookups = useHrLookups(() => view.value === 'register');
const due = computed(() => (['expired', 'd7', 'd30', 'd60', 'due', 'valid', 'none'].includes(nav.get('due')) ? nav.get('due') : ''));
const kind = computed(() => nav.get('kind'));
const dstatus = computed(() => (['replaced', 'void', 'all'].includes(nav.get('dstatus')) ? nav.get('dstatus') : ''));
const employee = computed(() => nav.get('employee'));
const register = useList(() => (view.value === 'register' ? '/hr/documents' : null), () => ({
  q: nav.get('q'), due: due.value || undefined, kind: kind.value || undefined, status: dstatus.value || undefined, employee: employee.value || undefined, page: page.value, pageSize: 25,
}));
const dueOptions = [['due', { ar: 'مستحقة أو منتهية', en: 'Due or expired' }], ['expired', STAGE.expired], ['d7', STAGE.d7], ['d30', STAGE.d30], ['d60', STAGE.d60], ['valid', { ar: 'سارية (أبعد من 60 يومًا)', en: 'Valid (beyond 60 days)' }], ['none', { ar: 'بلا تاريخ انتهاء', en: 'No expiry date' }]];
const statusOptions = [['replaced', DOCUMENT_STATUS.replaced], ['void', DOCUMENT_STATUS.void], ['all', { ar: 'كل الحالات', en: 'Every state' }]];
const employeeName = computed(() => { const e = lookups.employees.value.find((x) => x.id === employee.value); return e ? `${pname(e)} · ${e.code}` : employee.value; });
const registerColumns = [
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(170px,1.3fr)' },
  { key: 'kind', header: { ar: 'الوثيقة', en: 'Document' }, width: 'minmax(170px,1.4fr)' },
  { key: 'expiryDate', header: { ar: 'الانتهاء', en: 'Expiry' }, width: '132px' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '120px', bare: true },
  { key: 'files', header: { ar: 'المرفقات', en: 'Files' }, width: 'minmax(120px,1fr)' },
  { key: 'act', header: '', width: '172px', bare: true },
];

const form = ref(null); // { mode, doc } | null
const canWrite = computed(() => auth.can('hr.manage'));
async function voidDoc(d) {
  const reason = await ask({ title: { ar: `إلغاء الوثيقة: ${bi(d.kindLabel)}`, en: `Void the document: ${bi(d.kindLabel)}` }, sub: { ar: 'تبقى في السجل ملغاة مع السبب؛ لا يُحذف شيء.', en: 'It stays on record as void with the reason; nothing is deleted.' },
    label: { ar: 'سبب الإلغاء (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء الوثيقة', en: 'Void' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/hr/documents/${d.id}/void`, { reason }), { success: t('أُلغيت الوثيقة', 'Document voided') });
}
async function removeFile(d, f) {
  if (!(await confirm({ title: { ar: `إزالة الملف «${f.name}»؟`, en: `Remove the file “${f.name}”?` }, tone: 'danger' }))) return;
  await act.run(() => api.del(`/hr/documents/${d.id}/files/${f.id}`), { success: t('أُزيل الملف', 'File removed') });
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="view" :tabs="views" variant="sm" class="!mb-0" @update:model-value="(k) => nav.go('due', k === 'register' ? { view: 'register' } : {})" />
    <span class="grow" />
    <Btn v-if="view === 'alerts'" size="sm" tone="soft" :loading="act.pending.value" :label="{ ar: 'تحديث التنبيهات', en: 'Refresh alerts' }" @click="refresh" />
    <Btn v-if="canWrite" size="sm" tone="primary" :label="{ ar: '+ وثيقة', en: '+ Document' }" @click="form = { mode: 'create', doc: null }" />
  </div>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />

  <!-- ── what is due -->
  <template v-if="view === 'alerts'">
    <div class="row wrap mb-2.5">
      <TextInput small type="search" class="w-[220px]" :model-value="qText" :placeholder="{ ar: 'بحث باسم الموظف أو رقمه…', en: 'Search employee name or code…' }" @update:model-value="typeQ" @enter="applyQ" />
      <Tabs :model-value="stage" :tabs="stageTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('stage', k)" />
      <SelectInput small class="w-[150px]" :model-value="category" :options="optionsOf(ALERT_CATEGORY)" :placeholder="{ ar: 'كل الأنواع', en: 'Every kind' }" @update:model-value="(v) => nav.setFilter('category', v)" />
    </div>
    <ErrorBanner :error="alerts.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="alertColumns" :paged="alerts.data.value" :loading="alerts.isFetching.value" :row-key="(r) => r.id"
                 :empty-text="stage || category || nav.get('q') ? { ar: 'لا تنبيهات مطابقة.', en: 'No matching alert.' } : { ar: 'لا شيء ينتهي خلال 60 يومًا ✓', en: 'Nothing expires within 60 days ✓' }"
                 @page="nav.setPage" @row-click="openAlert">
        <template #cell-employee="{ row }">
          <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ pname(row.employee) }}</div><div class="truncate text-[9.5px] text-muted"><span class="num ltr">{{ row.employee.code }}</span><template v-if="row.employee.jobTitle"> · {{ row.employee.jobTitle }}</template></div></div>
        </template>
        <template #cell-what="{ row }"><span class="text-[11px] font-bold" dir="auto">{{ t(row.textAr, row.textEn) }}</span></template>
        <template #cell-stage="{ row }">
          <span class="inline-flex flex-wrap items-center justify-center gap-1"><Chip small :map="STAGE" :k="row.stage" /><Chip v-if="row.private" small :label="PRIVATE_CHIP" :fg="PRIVATE_CHIP.fg" :bg="PRIVATE_CHIP.bg" /></span>
        </template>
      </DataTable>
    </div>
    <div class="hint">{{ t('يُنشأ التنبيه حين يبقى على التاريخ 60 يومًا، ثم ينتقل إلى مرحلة 30 يومًا فـ 7 أيام فـ «منتهية»، ويُبلَّغ أصحاب الصلاحية مرة عند كل مرحلة. يُقفل تلقائيًا عند تجديد الوثيقة أو تعديل تاريخها، أو إذا ترك الموظف العمل.', 'An alert is raised when 60 days are left, then moves to the 30-day stage, the 7-day stage and “expired”; the people who can act are told once per stage. It resolves itself when the paper is renewed or its date changed, or when the employee leaves.') }}</div>
  </template>

  <!-- ── the register -->
  <template v-else>
    <div class="row wrap mb-2.5">
      <TextInput small type="search" class="w-[220px]" :model-value="qText" :placeholder="{ ar: 'بحث بالموظف، الوصف أو رقم الوثيقة…', en: 'Search employee, title or number…' }" @update:model-value="typeQ" @enter="applyQ" />
      <SelectInput small class="w-[190px]" :model-value="due" :options="dueOptions" :placeholder="{ ar: 'كل التواريخ', en: 'Any date' }" @update:model-value="(v) => nav.setFilter('due', v)" />
      <SelectInput small class="w-[170px]" :model-value="kind" :options="lookups.kindOptions.value" :placeholder="{ ar: 'كل الأنواع', en: 'Every kind' }" @update:model-value="(v) => nav.setFilter('kind', v)" />
      <SelectInput small class="w-[140px]" :model-value="dstatus" :options="statusOptions" :placeholder="{ ar: 'القائمة فقط', en: 'Current only' }" @update:model-value="(v) => nav.setFilter('dstatus', v)" />
      <button v-if="employee" type="button" class="pill active" @click="nav.setFilter('employee', null)">{{ employeeName }} ✕</button>
    </div>
    <ErrorBanner :error="register.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="registerColumns" :paged="register.data.value" :loading="register.isFetching.value" :row-key="(r) => r.id"
                 :empty-text="due || kind || dstatus || employee || nav.get('q') ? { ar: 'لا وثائق مطابقة.', en: 'No matching document.' } : { ar: 'لا وثائق مسجَّلة بعد — أضف أول وثيقة.', en: 'No document registered yet — add the first one.' }"
                 @page="nav.setPage">
        <template #cell-employee="{ row }">
          <button type="button" class="min-w-0 cursor-pointer border-0 bg-transparent p-0 text-start" @click="nav.openEmployee(row.employee.code, 'documents')">
            <span class="cell-name block truncate" dir="auto">{{ pname(row.employee) }}</span><span class="num ltr block text-[9.5px] text-muted">{{ row.employee.code }}</span>
          </button>
        </template>
        <template #cell-kind="{ row }">
          <div class="min-w-0">
            <div class="truncate text-[11px] font-extrabold">{{ bi(row.kindLabel) }}<span v-if="row.title" class="ms-1.5 font-normal text-sec">{{ row.title }}</span></div>
            <div v-if="row.number" class="num ltr truncate text-[9.5px] text-muted">{{ row.number }}</div>
          </div>
        </template>
        <template #cell-expiryDate="{ row }">
          <div v-if="row.expiryDate"><span class="cell-date">{{ fmtDay(row.expiryDate) }}</span><div v-if="row.days != null" class="text-[9px] text-muted">{{ daysText(row.days) }}</div></div>
          <span v-else class="text-[9.5px] text-faint">{{ t('بلا تاريخ', 'No date') }}</span>
        </template>
        <template #cell-state="{ row }">
          <span class="inline-flex flex-wrap items-center justify-center gap-1">
            <Chip v-if="row.status !== 'active'" small :map="DOCUMENT_STATUS" :k="row.status" :title="row.voidReason || null" />
            <Chip v-else-if="row.stage" small :map="STAGE" :k="row.stage" />
            <Chip v-if="row.private" small :label="PRIVATE_CHIP" :fg="PRIVATE_CHIP.fg" :bg="PRIVATE_CHIP.bg" />
          </span>
        </template>
        <template #cell-files="{ row }"><HrFiles :files="row.files" :removable="canWrite && row.status === 'active'" :empty="t('بلا مرفق', 'No file')" @remove="(f) => removeFile(row, f)" /></template>
        <template #cell-act="{ row }">
          <span v-if="canWrite && row.status === 'active'" class="inline-flex flex-wrap justify-center gap-1">
            <Btn size="sm" tone="softGreen" class="!h-7 !px-2 !text-[9px]" :label="{ ar: 'تجديد', en: 'Renew' }" @click="form = { mode: 'renew', doc: row }" />
            <Btn size="sm" tone="soft" class="!h-7 !px-2 !text-[9px]" :label="{ ar: 'تعديل', en: 'Edit' }" @click="form = { mode: 'edit', doc: row }" />
            <Btn size="sm" tone="dangerOutline" class="!h-7 !px-2 !text-[9px]" :label="{ ar: 'إلغاء', en: 'Void' }" @click="voidDoc(row)" />
          </span>
        </template>
      </DataTable>
    </div>
    <div class="hint">{{ t('وثائق الهوية (الإقامة، الهوية الوطنية، الجواز، التأشيرة) وكل وثيقة تُعلَّم «خاصة» جزء من الملف الشخصي: لا تظهر في هذا السجل ولا في التنبيهات إلا لمن يملك صلاحية الملفات الشخصية. الملفات تُحفظ في تخزين القسم نفسه وتُفتح بصلاحية وثيقتها فقط.', 'Identity papers (residence permit, national ID, passport, visa) and any paper marked “private” are part of the personal file: they appear in this register and in the alerts only for holders of the personal-files permission. Files are kept in the section’s own store and open only with the permission of their document.') }}</div>
  </template>

  <DocumentForm :open="!!form" :mode="form ? form.mode : 'create'" :doc="form ? form.doc : null" @close="form = null" />
</template>
