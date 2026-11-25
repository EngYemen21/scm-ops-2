<script setup>
// A line of a résumé — add (POST /payroll/skills/employees/{id}/resume) or edit (PATCH …/resume/{line}):
// { typeId?, title, dateStart?, dateEnd?, description? }. A résumé is a personal paper: the trail records that a line
// was written, not what it says.
//   <ResumeForm :open :employee-id="id" :line="lineOrNull" :types="catalog.resumeTypes" @close />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import FormShell from './FormShell.vue';
import { pname } from './payroll';

const props = defineProps({
  open: { type: Boolean, default: false },
  employeeId: { type: String, default: null },
  line: { type: Object, default: null },
  types: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['payroll'] });
const f = reactive({ typeId: '', title: '', dateStart: '', dateEnd: '', description: '' });
const errors = ref({});
watch(() => [props.open, props.line], () => {
  if (!props.open) return;
  const l = props.line;
  Object.assign(f, { typeId: l?.type?.id || '', title: l?.title || '', dateStart: l?.dateStart || '', dateEnd: l?.dateEnd || '', description: l?.description || '' });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const typeOptions = computed(() => props.types.map((x) => [x.id, pname(x)]));
const ok = computed(() => f.title.trim().length >= 2 && (!f.dateStart || !f.dateEnd || f.dateEnd >= f.dateStart));
async function save() {
  if (!ok.value) return;
  const body = { typeId: f.typeId || null, title: f.title.trim(), dateStart: f.dateStart || null, dateEnd: f.dateEnd || null, description: f.description.trim() || null };
  const base = `/payroll/skills/employees/${encodeURIComponent(props.employeeId)}/resume`;
  const r = await act.run(() => (props.line ? api.patch(`${base}/${encodeURIComponent(props.line.id)}`, body) : api.postIdempotent(base, body)), { success: t('حُفظ سطر السيرة', 'Résumé line saved') });
  if (r) emit('close');
}
</script>

<template>
  <FormShell :open="open" :width="520" :z-index="72" :title="line ? { ar: 'تعديل سطر السيرة', en: 'Edit the résumé line' } : { ar: 'سطر في السيرة الذاتية', en: 'Résumé line' }"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="{ ar: 'العنوان حرفان على الأقل، والنهاية لا تسبق البداية', en: 'A title of two characters at least; the end not before the start' }"
             @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.title" full required :label="{ ar: 'العنوان', en: 'Title' }" :placeholder="{ ar: 'مثال: مشرف مستودع — شركة …', en: 'e.g. Warehouse supervisor — company …' }" :error="errors.title" />
      <SelectInput v-model="f.typeId" full :label="{ ar: 'النوع', en: 'Kind' }" :options="typeOptions" :placeholder="{ ar: '—', en: '—' }" :error="errors.typeId" />
      <DateInput v-model="f.dateStart" :label="{ ar: 'من', en: 'From' }" :error="errors.dateStart" />
      <DateInput v-model="f.dateEnd" :min="f.dateStart || null" :label="{ ar: 'إلى', en: 'To' }" :hint="{ ar: 'فارغ = حتى الآن', en: 'Empty = to date' }" :error="errors.dateEnd" />
      <TextArea v-model="f.description" :rows="4" :label="{ ar: 'الوصف', en: 'Description' }" :error="errors.description" />
    </div>
  </FormShell>
</template>
