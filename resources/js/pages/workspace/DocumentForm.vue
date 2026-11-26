<script setup>
// A document is added to a folder the caller may write in — one file, or a link (POST /workspace/documents) — or
// its details are corrected (PATCH /workspace/documents/{id}: name, description, tags, the linked record, and the
// address of a link). A new FILE of an existing document is not an edit: it is a new version, uploaded from the
// document itself, and the old one stays.
//   <DocumentForm :open :mode="'file' | 'link'" :folder-id="current" :doc="editing" :folders="folders" :tags="tags" :lookups="lookups" @close @done="(doc) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import FilePicker from './FilePicker.vue';
import RecordLinkField from './RecordLinkField.vue';
import TagPicker from './TagPicker.vue';
import WsFormDrawer from './WsFormDrawer.vue';
import { folderOptions } from './folders';
import { API, INVALIDATE, SELECT_PH, arr, toUploads } from './workspace';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** What a new document is: file | link. */
  mode: { type: String, default: 'file' },
  /** The folder a new document goes to unless another one is chosen. */
  folderId: { type: String, default: '' },
  /** The document being edited (the answer of GET documents/{id}); null = a new document. */
  doc: { type: Object, default: null },
  /** The flat list of GET folders. */
  folders: { type: Array, default: () => [] },
  /** GET documents/lookups → tags. */
  tags: { type: Array, default: () => [] },
  /** GET lookups: record kinds, the rules of an upload. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const editing = computed(() => !!props.doc);
const isLink = computed(() => (props.doc ? props.doc.kind === 'link' : props.mode === 'link'));
const writable = (x) => !!x.canWrite && !x.archived;
function values(x) {
  const start = arr(props.folders).find((y) => y.id === props.folderId);
  return { folderId: x ? x.folder?.id || '' : start && writable(start) ? start.id : '', name: x?.name || '', url: x?.url || '', description: x?.description || '',
    tagIds: arr(x?.tags).map((tag) => String(tag.id)), refType: x?.ref?.type || '', refNumber: x?.ref?.number || '', files: [] };
}
const f = ref(values(props.doc));
/** The picker is still reading the file from the disk. */
const picking = ref(false);
watch(() => props.open, (now) => { if (now) { f.value = values(props.doc); act.clearError(); } });

const folderChoices = computed(() => folderOptions(props.folders, writable));
/** The tags of every folder, and the ones of the folder the document is in. */
const offered = computed(() => arr(props.tags).filter((tag) => !tag.folderId || tag.folderId === f.value.folderId || f.value.tagIds.includes(String(tag.id))));
const validUrl = computed(() => /^https?:\/\/\S+$/i.test(f.value.url.trim()));
const halfLink = computed(() => !!f.value.refType !== !!f.value.refNumber.trim());
const missing = computed(() => {
  const v = f.value;
  if (!editing.value && !v.folderId) return { ar: 'اختر المجلد.', en: 'Pick the folder.' };
  if (isLink.value && !validUrl.value) return { ar: 'اكتب عنوان الرابط كاملًا (يبدأ بـ https:// أو http://).', en: 'Type the whole address of the link (it starts with https:// or http://).' };
  if ((isLink.value || editing.value) && !v.name.trim()) return { ar: 'اكتب اسم المستند.', en: 'Give the document a name.' };
  if (!editing.value && !isLink.value && !v.files.length) return picking.value ? { ar: 'جارٍ قراءة الملف…', en: 'Reading the file…' } : { ar: 'اختر الملف.', en: 'Pick the file.' };
  if (halfLink.value) return { ar: 'أكمل ربط السجل (النوع والرقم معًا) أو أفرغه.', en: 'Complete the record link (kind and number together) or clear it.' };
  if (picking.value) return { ar: 'جارٍ قراءة الملف…', en: 'Reading the file…' };
  return null;
});

async function save() {
  if (missing.value) return;
  const v = f.value;
  let done;
  if (editing.value) {
    // an edit says everything the form holds: what was emptied is cleared
    const body = { name: v.name.trim(), description: v.description.trim() || null, tagIds: v.tagIds, refType: v.refType || null, refNumber: v.refType ? v.refNumber.trim() : null, ...(isLink.value ? { url: v.url.trim() } : {}) };
    done = await act.run(() => api.patch(`${API}/documents/${props.doc.id}`, body), { success: t('حُفظ المستند', 'Document saved'), invalidate: INVALIDATE });
  } else {
    const body = { folderId: v.folderId, name: v.name.trim() || undefined, ...(isLink.value ? { url: v.url.trim() } : { file: toUploads(v.files)[0] }), description: v.description.trim() || undefined,
      tagIds: v.tagIds, refType: v.refType || undefined, refNumber: v.refType ? v.refNumber.trim() : undefined };
    done = await act.run(() => api.postIdempotent(`${API}/documents`, body), { success: (x) => t(`أُضيف المستند ${x?.number || ''}`, `Document ${x?.number || ''} added`), invalidate: INVALIDATE });
  }
  if (done) { emit('done', done); emit('close'); }
}
</script>

<template>
  <WsFormDrawer :open="open" :title="editing ? { ar: `تعديل المستند ${doc.number}`, en: `Edit document ${doc.number}` } : isLink ? { ar: 'رابط جديد', en: 'New link' } : { ar: 'رفع مستند', en: 'Upload a document' }"
                :submit-label="editing ? { ar: 'حفظ التعديلات', en: 'Save changes' } : isLink ? { ar: 'إضافة الرابط', en: 'Add the link' } : { ar: 'رفع المستند', en: 'Upload' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!!missing" :why="missing" @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-if="!editing" v-model="f.folderId" :label="{ ar: 'المجلد', en: 'Folder' }" required full :options="folderChoices" :placeholder="SELECT_PH"
                   :hint="folderChoices.length ? null : { ar: 'لا مجلد تملك الكتابة فيه.', en: 'There is no folder you may write in.' }" />
      <TextInput v-if="isLink" v-model="f.url" :label="{ ar: 'عنوان الرابط', en: 'Address of the link' }" required full dir="ltr" placeholder="https://" maxlength="2000" />
      <FilePicker v-else-if="!editing" v-model="f.files" :lookups="lookups" single required :label="{ ar: 'الملف', en: 'File' }" @busy="picking = $event" />
      <TextInput v-model="f.name" :label="{ ar: 'اسم المستند', en: 'Document name' }" :required="isLink || editing" full maxlength="250"
                 :hint="!editing && !isLink ? { ar: 'إن تُرك فارغًا أخذ المستند اسم الملف.', en: 'Left empty, the document takes the name of the file.' } : null" />
      <TagPicker v-model="f.tagIds" :tags="offered" :label="{ ar: 'الوسوم', en: 'Tags' }" />
      <TextArea v-model="f.description" :label="{ ar: 'الوصف', en: 'Description' }" :rows="3" maxlength="5000" />
      <RecordLinkField v-model:type="f.refType" v-model:number="f.refNumber" :types="lookups?.recordTypes" />
    </div>
    <div v-if="editing && !isLink" class="hint">{{ t('لاستبدال الملف ارفع «نسخة جديدة» من المستند نفسه: النسخة السابقة تبقى محفوظة.', 'To replace the file, upload a “new version” from the document itself: the earlier one is kept.') }}</div>
  </WsFormDrawer>
</template>
