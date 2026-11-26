<script setup>
// A to-do on a lead — add (POST /crm/leads/{lead}/activities) or change an open one (PATCH /crm/activities/{id}):
// its type, its due date, whose it is, a line of text and a note. With no text the type names it («اتصال»).
//   <ActivityForm :open="open" :lead="{ id, number, name }" :activity="activityOrNull" @close="…" @done="…" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { activityTypeOptions, todayIso, useCrmLookups } from './crm';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The lead the to-do is for: `{ id, number, name }`. */
  lead: { type: Object, default: null },
  /** An open to-do to change; null = a new one. */
  activity: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const { userOptions } = useCrmLookups();
const edit = computed(() => !!props.activity);
const mine = computed(() => (auth.user && userOptions.value.some((u) => u.v === auth.user.id) ? auth.user.id : undefined));
const fields = computed(() => [
  { k: 'type', label: { ar: 'نوع النشاط', en: 'Type' }, type: 'select', required: true, opts: activityTypeOptions(), def: 'call' },
  { k: 'dueDate', label: { ar: 'تاريخ الاستحقاق', en: 'Due date' }, type: 'date', required: true, def: todayIso() },
  { k: 'summary', label: { ar: 'المطلوب', en: 'What to do' }, full: true, ph: { ar: 'مثال: الاتصال لتأكيد موعد الزيارة', en: 'e.g. call to confirm the visit' } },
  { k: 'userId', label: { ar: 'المسؤول عنه', en: 'Whose to-do' }, type: 'select', opts: userOptions.value, def: mine.value, ph: { ar: 'أنا', en: 'Me' } },
  { k: 'note', label: { ar: 'تفاصيل', en: 'Details' }, type: 'area' },
]);
const initial = computed(() => {
  const a = props.activity;
  return a ? { type: a.type, dueDate: a.dueDate, summary: a.summary || '', userId: a.user?.id || '', note: a.note || '' } : null;
});
const title = computed(() => (edit.value ? { ar: 'تعديل النشاط', en: 'Change the to-do' } : { ar: 'نشاط جديد', en: 'New to-do' }));
const sub = computed(() => (props.lead ? `${props.lead.number} · ${props.lead.name}` : null));

function submit(values) {
  // the form drawer leaves emptied fields out: in an edit an emptied text must reach the server as "cleared"
  return edit.value
    ? api.patch(`/crm/activities/${props.activity.id}`, { ...values, summary: values.summary ?? null, note: values.note ?? null })
    : api.postIdempotent(`/crm/leads/${props.lead.id}/activities`, values);
}
const action = computed(() => ({ success: edit.value ? t('حُفظ النشاط', 'To-do saved') : t('أُضيف النشاط', 'To-do added'), invalidate: ['crm'] }));
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit" :action="action"
              :submit-label="edit ? { ar: 'حفظ', en: 'Save' } : { ar: 'إضافة النشاط', en: 'Add to-do' }" @close="emit('close')" @done="(a) => emit('done', a)" />
</template>
