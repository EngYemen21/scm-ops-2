<script setup>
// One document (deep link `doc=<number>`): where it is filed, who owns it, its tags, the record of OPS it is linked
// to, and every version it ever had — each one downloadable through GET /workspace/files/{id}, which asks the
// permission of the folder. What may be done follows the document's own `can.*` flags:
//   POST /workspace/documents/{id}/versions { file, note? }   a new version; the old ones stay
//   POST /workspace/documents/{id}/move { folderId } · archive { reason? } · restore        (never a deletion)
// Its name, description, tags and link are corrected in the form (`edit`). A link document opens its address.
//   <DocumentDrawer :number="qs.get('doc')" :folders="folders" :lookups="lookups" @close @edit="(doc) => …" @folder="(id) => …" />
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import FileList from './FileList.vue';
import FilePicker from './FilePicker.vue';
import OdooMark from './OdooMark.vue';
import PersonName from './PersonName.vue';
import TagChips from './TagChips.vue';
import { folderOptions } from './folders';
import { API, ARCHIVED_CHIP, FILE_KIND, INVALIDATE, OWNED_NOTE, arr, downloadFile, fileKind, fileSize, refLabel, refPath, toUploads } from './workspace';

const props = defineProps({
  /** Number (or id) of the document on screen; empty = closed. */
  number: { type: String, default: '' },
  /** The flat list of GET folders (where the document may be moved). */
  folders: { type: Array, default: () => [] },
  /** GET lookups: the rules of an upload. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'edit', 'folder']);

const detail = useGet(() => (props.number ? `${API}/documents/${encodeURIComponent(props.number)}` : null));
const d = computed(() => (props.number ? detail.data.value || null : null));
const can = computed(() => d.value?.can || {});
const isLink = computed(() => d.value?.kind === 'link');
const kind = computed(() => (isLink.value ? FILE_KIND.link : FILE_KIND[fileKind(d.value?.mime, d.value?.name)] || FILE_KIND.other));
/** The address of a link, when it is one a browser may be sent to. */
const href = computed(() => (isLink.value && /^https?:\/\/\S+$/i.test(String(d.value?.url || '')) ? d.value.url : null));
const targets = computed(() => folderOptions(props.folders, (x) => !!x.canWrite && !x.archived && x.id !== d.value?.folder?.id));
const anyAction = computed(() => ['edit', 'move', 'version', 'archive', 'restore'].some((k) => can.value[k]) || (can.value.download && d.value?.file?.available !== false && !!d.value?.file));

const act = useAction();
const post = (step, body, success) => act.run(() => api.postIdempotent(`${API}/documents/${d.value.id}/${step}`, body), { success, invalidate: INVALIDATE });
const download = () => act.run(() => downloadFile(d.value.file), { success: (name) => t(`تم تنزيل ${name}`, `Downloaded ${name}`), invalidate: false });
function onMove(e) {
  const folderId = e.target.value;
  e.target.value = '';
  if (folderId) post('move', { folderId }, t('نُقل المستند', 'Document moved'));
}
async function archive() {
  const reason = await ask({ title: { ar: `أرشفة المستند ${d.value.number}؟`, en: `Archive document ${d.value.number}?` }, sub: { ar: 'لا يُحذف شيء: يخرج المستند من القوائم ويبقى بكل نسخه؛ يُستعاد متى شئت.', en: 'Nothing is removed: the document leaves the lists and keeps every version; it can be restored at any time.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } });
  if (reason === null) return;
  await post('archive', reason ? { reason } : {}, t('أُرشف المستند', 'Document archived'));
}
async function restore() {
  if (!(await confirm({ title: { ar: `استعادة المستند ${d.value.number} من الأرشيف؟`, en: `Restore document ${d.value.number} from the archive?` }, tone: 'dark' }))) return;
  await post('restore', undefined, t('استُعيد المستند', 'Document restored'));
}

// ---- a new version
const versioning = ref(false);
const files = ref([]);
const note = ref('');
const picking = ref(false);
function resetVersion() { versioning.value = false; files.value = []; note.value = ''; }
watch(() => props.number, () => { resetVersion(); act.clearError(); });
async function saveVersion() {
  if (!files.value.length || picking.value) return;
  const done = await post('versions', { file: toUploads(files.value)[0], ...(note.value.trim() ? { note: note.value.trim() } : {}) }, t('رُفعت النسخة الجديدة', 'New version uploaded'));
  if (done) resetVersion();
}
</script>

<template>
  <Drawer :open="!!number" :width="640" :sub="d ? d.name : null" @close="emit('close')">
    <template #title><span class="num ltr inline-block text-violet">{{ d ? d.number : number }}</span></template>

    <ErrorBanner :error="detail.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!d && !detail.error.value" class="skel h-[180px]" />
    <template v-else-if="d">
      <div v-if="d.owned" class="banner teal">{{ t(OWNED_NOTE.ar, OWNED_NOTE.en) }}</div>

      <div class="row wrap mb-3">
        <Chip :label="kind" :fg="kind.fg" :bg="kind.bg" />
        <span v-if="!isLink && d.version" class="num rounded-lg bg-soft px-2 py-1 text-[10px] text-violet">v{{ d.version }}</span>
        <Chip v-if="d.archived" :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <OdooMark :row="d" />
        <TagChips :tags="d.tags" />
      </div>

      <div v-if="anyAction || href" class="tile mb-3.5 row wrap">
        <a v-if="href" :href="href" target="_blank" rel="noopener noreferrer" class="btn primary sm">{{ t('فتح الرابط', 'Open the link') }}</a>
        <Btn v-if="!isLink && can.download && d.file && d.file.available !== false" tone="primary" size="sm" :loading="act.pending.value" :label="{ ar: 'تنزيل', en: 'Download' }" @click="download" />
        <Btn v-if="!isLink && can.version && !versioning" tone="softPurple" size="sm" :label="{ ar: 'رفع نسخة جديدة', en: 'Upload a new version' }" @click="versioning = true" />
        <select v-if="can.move && targets.length" class="inp sm !w-auto max-w-[240px]" :disabled="act.pending.value" :aria-label="t('نقل إلى مجلد', 'Move to a folder')" @change="onMove">
          <option value="">{{ t('نقل إلى مجلد…', 'Move to folder…') }}</option>
          <option v-for="o in targets" :key="o.v" :value="o.v">{{ o.l }}</option>
        </select>
        <span class="grow" />
        <Btn v-if="can.edit" tone="outline" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', d)" />
        <Btn v-if="can.archive" tone="dangerOutline" size="sm" :disabled="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
        <Btn v-if="can.restore" tone="softBlue" size="sm" :loading="act.pending.value" :label="{ ar: 'استعادة من الأرشيف', en: 'Restore' }" @click="restore" />
      </div>

      <!-- a new version: the file in force changes, the earlier ones stay -->
      <div v-if="versioning && can.version" class="mb-3.5 rounded-xl border border-line bg-soft p-3">
        <div class="mb-2 text-[11px] font-extrabold">{{ t('نسخة جديدة من المستند', 'A new version of the document') }}</div>
        <FilePicker v-model="files" :lookups="lookups" single required :label="{ ar: 'الملف', en: 'File' }" :disabled="act.pending.value" @busy="picking = $event" />
        <TextInput v-model="note" field-class="mt-2" :label="{ ar: 'ما الذي تغيّر؟ (اختياري)', en: 'What changed? (optional)' }" maxlength="500" :disabled="act.pending.value" />
        <div class="row mt-2.5">
          <Btn tone="dark" size="sm" :loading="act.pending.value" :disabled="!files.length || picking" :label="{ ar: 'رفع النسخة', en: 'Upload the version' }" @click="saveVersion" />
          <Btn tone="soft" size="sm" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="resetVersion" />
          <span class="field-hint min-w-0 flex-1">{{ t('النسخ السابقة تبقى محفوظة وقابلة للتنزيل.', 'The earlier versions are kept and can still be downloaded.') }}</span>
        </div>
      </div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'المجلد', en: 'Folder' }">
          <span class="inline-flex flex-wrap items-center gap-1">
            <template v-for="(p, i) in arr(d.path)" :key="p.id">
              <span v-if="i" class="text-faint">/</span>
              <button type="button" class="cursor-pointer border-0 bg-transparent p-0 font-extrabold text-brand-dark" @click="emit('folder', p.id)"><span dir="auto">{{ p.name }}</span></button>
            </template>
            <span v-if="!arr(d.path).length" dir="auto">{{ d.folder ? d.folder.name : '—' }}</span>
          </span>
        </KV>
        <KV :k="{ ar: 'المالك', en: 'Owner' }"><PersonName :person="d.owner" /></KV>
        <KV v-if="!isLink" :k="{ ar: 'الحجم', en: 'Size' }" :v="fileSize(d.size) || '—'" ltr />
        <KV :k="{ ar: 'أُضيف', en: 'Added' }"><span class="num ltr inline-block">{{ fmtDate(d.createdAt) }}</span><span v-if="d.createdBy" class="text-muted"> · {{ d.createdBy }}</span></KV>
        <KV :k="{ ar: 'آخر تعديل', en: 'Last change' }" :v="fmtDate(d.updatedAt)" ltr />
        <KV v-if="d.ref" :k="{ ar: 'سجل مرتبط', en: 'Linked record' }">
          <RouterLink v-if="refPath(d.ref)" :to="refPath(d.ref)" class="font-extrabold">{{ refLabel(d.ref) }}</RouterLink>
          <span v-else>{{ refLabel(d.ref) }}</span>
        </KV>
      </div>

      <div v-if="isLink" class="mb-4 break-all rounded-xl border border-line-2 bg-soft px-3 py-2 text-[10.5px]"><span class="ltr inline-block">{{ d.url || '—' }}</span></div>
      <div v-if="d.description" class="mb-4 whitespace-pre-wrap break-words text-[11.5px] leading-[1.9] text-ink [text-align:match-parent]" dir="auto">{{ d.description }}</div>
      <div v-if="d.archived" class="hint amber !mt-0 mb-4">
        {{ t('مؤرشف', 'Archived') }}<span v-if="d.archivedAt"> · <span class="num ltr inline-block">{{ fmtDate(d.archivedAt) }}</span></span><span v-if="d.archivedBy"> · {{ d.archivedBy }}</span>
        <div v-if="d.archiveReason" dir="auto">{{ d.archiveReason }}</div>
      </div>

      <template v-if="!isLink">
        <div class="card-title mb-1.5">{{ t('النسخ', 'Versions') }}<span class="card-count num ms-2">{{ arr(d.versions).length }}</span></div>
        <FileList :files="can.download ? d.versions : []" versions :current-id="d.file ? d.file.id : null" :empty-text="can.download ? { ar: 'لا محتوى لهذا المستند بعد.', en: 'This document has no content yet.' } : { ar: 'لا تملك تنزيل هذا المستند.', en: 'You may not download this document.' }" />
      </template>
    </template>
  </Drawer>
</template>
