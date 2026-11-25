<script setup>
// A project is created (POST /workspace/projects — project.manage) or its details are corrected
// (PATCH /workspace/projects/{id} — its manager or project.manage). A new project gets three stages of its own
// (to do · in progress · done); its members and its stages are kept from the project's drawer afterwards.
//   <ProjectForm :open :project="editing" :lookups="lookups" @close @done="(project) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import PeoplePicker from './PeoplePicker.vue';
import WsFormDrawer from './WsFormDrawer.vue';
import { API, INVALIDATE, VISIBILITY_OPTIONS, userOptions } from './workspace';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The project being edited (the answer of GET projects/{id}); null = a new project. */
  project: { type: Object, default: null },
  /** GET lookups: the users a project is made of. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction();
const editing = computed(() => !!props.project);
function values(x) {
  return { name: x?.name || '', description: x?.description || '', managerId: x ? x.manager?.id || '' : auth.user?.id || '', memberKeys: [], startDate: x?.startDate || '', endDate: x?.endDate || '', visibility: x?.visibility || 'all' };
}
const f = ref(values(props.project));
watch(() => props.open, (now) => { if (now) { f.value = values(props.project); act.clearError(); } });

const people = computed(() => userOptions(props.lookups?.users));
const missing = computed(() => {
  if (!f.value.name.trim()) return { ar: 'اكتب اسم المشروع.', en: 'Give the project a name.' };
  if (f.value.startDate && f.value.endDate && f.value.endDate < f.value.startDate) return { ar: 'تاريخ النهاية قبل تاريخ البداية.', en: 'The end date is before the start date.' };
  return null;
});

async function save() {
  if (missing.value) return;
  const v = f.value;
  const done = editing.value
    ? await act.run(() => api.patch(`${API}/projects/${props.project.id}`, { name: v.name.trim(), description: v.description.trim() || null, managerId: v.managerId || null, startDate: v.startDate || null, endDate: v.endDate || null, visibility: v.visibility }),
      { success: t('حُفظ المشروع', 'Project saved'), invalidate: INVALIDATE })
    : await act.run(() => api.postIdempotent(`${API}/projects`, { name: v.name.trim(), description: v.description.trim() || undefined, managerId: v.managerId || undefined, memberKeys: v.memberKeys, startDate: v.startDate || undefined, endDate: v.endDate || undefined, visibility: v.visibility }),
      { success: (x) => t(`أُنشئ المشروع ${x?.number || ''}`, `Project ${x?.number || ''} created`), invalidate: INVALIDATE });
  if (done) { emit('done', done); emit('close'); }
}
</script>

<template>
  <WsFormDrawer :open="open" :title="editing ? { ar: `تعديل المشروع ${project.number}`, en: `Edit project ${project.number}` } : { ar: 'مشروع جديد', en: 'New project' }"
                :submit-label="editing ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إنشاء المشروع', en: 'Create the project' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!!missing" :why="missing" @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.name" :label="{ ar: 'اسم المشروع', en: 'Project name' }" required full maxlength="250" />
      <SelectInput v-model="f.managerId" :label="{ ar: 'مدير المشروع', en: 'Project manager' }" :options="people" :placeholder="{ ar: '— بلا مدير —', en: '— no manager —' }" />
      <SelectInput v-model="f.visibility" :label="{ ar: 'من يرى المشروع', en: 'Who sees the project' }" :options="VISIBILITY_OPTIONS"
                   :hint="{ ar: 'يعمل على مهامه أعضاؤه ومديره؛ غيرهم يقرأ فقط إن كان متاحًا للجميع.', en: 'Its members and its manager work its tasks; the others only read it when it is open to everyone.' }" />
      <DateInput v-model="f.startDate" :label="{ ar: 'تاريخ البداية', en: 'Start date' }" />
      <DateInput v-model="f.endDate" :label="{ ar: 'تاريخ النهاية', en: 'End date' }" :min="f.startDate || null" />
      <PeoplePicker v-if="!editing" v-model="f.memberKeys" :users="lookups?.users" :label="{ ar: 'الأعضاء', en: 'Members' }" :empty-text="{ ar: 'لا أعضاء بعد.', en: 'No members yet.' }"
                    :hint="{ ar: 'المدير يعمل على المشروع دون أن يُضاف هنا.', en: 'The manager works the project without being added here.' }" />
      <TextArea v-model="f.description" :label="{ ar: 'الوصف', en: 'Description' }" :rows="4" maxlength="20000" />
    </div>
    <div class="hint">
      {{ editing ? t('الأعضاء والمراحل والحالة تُدار من «تفاصيل المشروع».', 'Members, stages and status are kept from “Project details”.')
        : t('يبدأ المشروع بثلاث مراحل: للتنفيذ · قيد التنفيذ · مكتملة. تُعدَّل من «تفاصيل المشروع» بعد إنشائه.', 'The project starts with three stages: to do · in progress · done. They are changed from “Project details” once it exists.') }}
    </div>
  </WsFormDrawer>
</template>
