<script setup>
// One form for the three things done to an employee document (hr.manage):
//   create  POST /hr/documents                 a new paper, with its scan
//   edit    PATCH /hr/documents/{id}           (+ POST …/files when a scan is added)
//   renew   POST /hr/documents/{id}/renew      the paper was renewed: a new one takes its place, the old one is kept
// Identity papers (residence permit, national id, passport, visa…) are always part of the personal file; any other
// paper becomes so when marked private — both need hr.private, which is why the choice is offered only with it.
//   <DocumentForm :open mode="create|edit|renew" :doc :employee-id @close @done />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import HrFilePick from './HrFilePick.vue';
import HrFormDrawer from './HrFormDrawer.vue';
import { SELECT, changedOnly, fmtDay, pname, todayIso, useHrLookups } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  mode: { type: String, default: 'create' },
  /** The document to edit or renew. */
  doc: { type: Object, default: null },
  /** Create: the employee the paper belongs to, when the form is opened from their card. */
  employeeId: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['hr'] });
const lookups = useHrLookups(() => props.open);
const f = reactive({ employee: '', kind: '', title: '', number: '', issueDate: '', expiryDate: '', private: false, notes: '' });
const file = ref(null);
const initial = () => {
  const d = props.mode === 'create' ? null : props.doc;
  // a renewal starts from the old paper's identity and asks for the new dates
  return { employee: d?.employee?.id || d?.employeeId || props.employeeId || '', kind: d?.kind || '', title: d?.title || '', number: d?.number || '',
    issueDate: props.mode === 'renew' ? todayIso() : d?.issueDate || '', expiryDate: props.mode === 'renew' ? '' : d?.expiryDate || '', private: !!d?.private, notes: props.mode === 'renew' ? '' : d?.notes || '' };
};
let before = initial();
watch(() => props.open, (open) => { if (open) { before = initial(); Object.assign(f, before); file.value = null; act.clearError(); } }, { immediate: true });

const kind = computed(() => lookups.documentKinds.value.find((k) => k.key === f.kind) || null);
const alwaysPrivate = computed(() => !!kind.value?.private || (props.mode !== 'create' && !!props.doc?.alwaysPrivate));
const canPrivate = computed(() => auth.can('hr.private'));
const isPrivate = computed(() => alwaysPrivate.value || f.private);
const title = computed(() => ({ create: { ar: 'وثيقة جديدة', en: 'New document' }, edit: { ar: 'تعديل الوثيقة', en: 'Edit the document' }, renew: { ar: 'تجديد الوثيقة', en: 'Renew the document' } })[props.mode]);
const sub = computed(() => (props.doc && props.mode !== 'create' ? `${bi(props.doc.kindLabel)}${props.doc.employee ? ` · ${pname(props.doc.employee)}` : ''}` : null));
const ok = computed(() => (props.mode === 'renew' ? !!f.expiryDate : !!f.employee && !!f.kind));
const dates = (v) => (v === '' ? null : v);

async function submit() {
  let saved;
  if (props.mode === 'create') {
    const body = { employee: f.employee, kind: f.kind, title: f.title.trim() || null, number: f.number.trim() || null, issueDate: dates(f.issueDate), expiryDate: dates(f.expiryDate),
      private: isPrivate.value, notes: f.notes.trim() || null, ...(file.value ? { file: { fileName: file.value.fileName, mime: file.value.mime, base64: file.value.base64 } } : {}) };
    saved = await act.run(() => api.postIdempotent('/hr/documents', body), { success: t('سُجِّلت الوثيقة', 'Document registered') });
  } else if (props.mode === 'renew') {
    const body = { expiryDate: f.expiryDate, issueDate: dates(f.issueDate), number: f.number.trim() || null, title: f.title.trim() || null, notes: f.notes.trim() || null,
      ...(file.value ? { file: { fileName: file.value.fileName, mime: file.value.mime, base64: file.value.base64 } } : {}) };
    saved = await act.run(() => api.postIdempotent(`/hr/documents/${props.doc.id}/renew`, body), { success: t('جُدِّدت الوثيقة — القديمة محفوظة في السجل', 'Document renewed — the old one stays on record') });
  } else {
    const body = changedOnly(before, { kind: f.kind, title: f.title.trim(), number: f.number.trim(), issueDate: f.issueDate, expiryDate: f.expiryDate, private: isPrivate.value, notes: f.notes.trim() });
    delete body.employee;
    saved = Object.keys(body).length ? await act.run(() => api.patch(`/hr/documents/${props.doc.id}`, body), { success: t('حُفظت الوثيقة', 'Document saved') }) : props.doc;
    if (saved && file.value) {
      saved = await act.run(() => api.postIdempotent(`/hr/documents/${props.doc.id}/files`, { fileName: file.value.fileName, mime: file.value.mime, base64: file.value.base64 }), { success: t('أُرفق الملف', 'File attached') });
    }
  }
  if (saved) { emit('done', saved); emit('close'); }
}
</script>

<template>
  <HrFormDrawer :open="open" :title="title" :sub="sub" :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
                :submit-label="mode === 'renew' ? { ar: 'تجديد', en: 'Renew' } : { ar: 'حفظ', en: 'Save' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="mode === 'renew' && doc" class="hint teal !mt-0 mb-3">
      {{ t(`تنتهي الوثيقة الحالية في ${fmtDay(doc.expiryDate)}. التجديد ينشئ وثيقة جديدة بالتواريخ الجديدة ويُبقي القديمة في السجل كـ «جُدِّدت».`, `The current paper expires on ${fmtDay(doc.expiryDate)}. Renewing creates a new document with the new dates and keeps the old one on record as “renewed”.`) }}
    </div>
    <div class="form-grid">
      <template v-if="mode !== 'renew'">
        <SelectInput v-model="f.employee" :label="{ ar: 'الموظف', en: 'Employee' }" required :options="lookups.employeeOptions.value" :placeholder="SELECT" :disabled="mode !== 'create' || !!employeeId" full />
        <SelectInput v-model="f.kind" :label="{ ar: 'نوع الوثيقة', en: 'Kind' }" required :options="lookups.kindOptions.value" :placeholder="SELECT" />
      </template>
      <TextInput v-model="f.title" :label="{ ar: 'الوصف', en: 'Title' }" :placeholder="{ ar: 'اختياري', en: 'Optional' }" />
      <TextInput v-model="f.number" :label="{ ar: 'رقم الوثيقة', en: 'Document number' }" dir="ltr" />
      <DateInput v-model="f.issueDate" :label="{ ar: 'تاريخ الإصدار', en: 'Issue date' }" :max="todayIso()" />
      <DateInput v-model="f.expiryDate" :label="mode === 'renew' ? { ar: 'تاريخ الانتهاء الجديد', en: 'New expiry date' } : { ar: 'تاريخ الانتهاء', en: 'Expiry date' }" :required="mode === 'renew'"
                 :hint="{ ar: 'يُنبَّه قبله بـ 60 و30 و7 أيام، وبعده', en: 'Alerts 60, 30 and 7 days before it, and after' }" />
      <div v-if="mode !== 'renew' && (canPrivate || alwaysPrivate)" class="field full">
        <label class="row cursor-pointer text-[11px] font-bold">
          <input type="checkbox" class="h-4 w-4" :checked="isPrivate" :disabled="alwaysPrivate || !canPrivate" @change="f.private = $event.target.checked">
          <span>{{ t('وثيقة خاصة: جزء من الملف الشخصي، لا يراها إلا من يملك صلاحية الملفات الشخصية', 'Private: part of the personal file — only holders of the personal-files permission see it') }}</span>
        </label>
        <div v-if="alwaysPrivate" class="field-hint">{{ t('وثائق الهوية خاصة دائمًا', 'Identity papers are always private') }}</div>
      </div>
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" />
      <HrFilePick v-model="file" :label="{ ar: 'صورة الوثيقة', en: 'Scan of the document' }" :hint="{ ar: 'PDF أو صورة حتى 5 ميجابايت', en: 'A PDF or a picture, up to 5 MB' }" />
    </div>
  </HrFormDrawer>
</template>
