<script setup>
// The documents: the folders the caller may read (a tree beside the list; a select above it on a phone) and the
// documents of the folder on screen — or of every folder — with search, filters, sort and paging. A document opens
// in a drawer (`doc=<number>`); a file or a link is added where the caller may write; whoever holds doc.manage keeps
// the folders (who reads and writes each, by role) and the tags. Nothing is deleted: folders and documents are
// archived, and a document keeps every version.
// The address holds folder, deep, state, tag, owner, kind, type, q, sort, order, page.
// GET /workspace/folders · GET /workspace/documents · GET /workspace/documents/lookups.
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import DocumentDrawer from './DocumentDrawer.vue';
import DocumentForm from './DocumentForm.vue';
import FolderForm from './FolderForm.vue';
import FolderTree from './FolderTree.vue';
import OdooMark from './OdooMark.vue';
import PersonName from './PersonName.vue';
import TagChips from './TagChips.vue';
import TagsManager from './TagsManager.vue';
import { folderOptions, folderPath, folderRows } from './folders';
import { API, ARCHIVED_CHIP, FILE_KIND, INVALIDATE, PAGE_SIZE, TONE, arr, downloadFile, fileKind, fileSize, personName, useLookups, useSearchBox, useSummary, useWsQuery } from './workspace';

const auth = useAuth();
const qs = useWsQuery();
const summary = useSummary();
const lookups = useLookups();
const search = useSearchBox(qs);

// ---- the filters of the address
const STATES = ['active', 'archived', 'all'];
const TYPES = ['pdf', 'image', 'doc', 'sheet', 'other'];
const FILTERS = ['deep', 'state', 'tag', 'owner', 'kind', 'type', 'q'];
const state = computed(() => (STATES.includes(qs.get('state')) ? qs.get('state') : 'active'));
const folderId = computed(() => qs.get('folder'));
const deep = computed(() => !!folderId.value && qs.get('deep') === '1');
const mineOnly = computed(() => qs.get('owner') === 'me');
const filtered = computed(() => FILTERS.some((k) => qs.get(k) !== ''));

// ---- folders
// the archived folders are asked for together with the archived documents: one switch for what was put away
const tree = useList(`${API}/folders`, () => (state.value === 'active' ? undefined : { archived: 1 }));
const folders = computed(() => arr(tree.data.value?.folders));
const folder = computed(() => folders.value.find((x) => x.id === folderId.value) || null);
const path = computed(() => (folder.value ? folderPath(folders.value, folder.value.id) : []));
const writable = computed(() => folders.value.filter((x) => x.canWrite && !x.archived));
const canAdd = computed(() => (folder.value ? !!folder.value.canWrite && !folder.value.archived : writable.value.length > 0));
const manages = computed(() => summary.data.value?.me?.manages?.documents ?? auth.can('doc.manage'));
const selectFolder = (id) => qs.patch({ folder: id, deep: '' });
/** The tree as a select, for a phone: each folder under its parents. */
const folderChoices = computed(() => folderRows(folders.value).map((r) => ({ v: r.folder.id, l: `${'— '.repeat(r.depth)}${r.folder.name ?? ''} (${r.folder.documentCount || 0})` })));
const roleNames = computed(() => new Map(arr(lookups.data.value?.roles).map((r) => [r.key, personName(r)])));
const scopeText = (scope, roles) => (scope === 'roles' ? (arr(roles).length ? arr(roles).map((k) => roleNames.value.get(k) || k).join('، ') : t('مدير المستندات فقط', 'The documents manager only')) : t('الجميع', 'Everyone'));

// ---- documents
const tagsQuery = useGet(`${API}/documents/lookups`, undefined, { staleTime: 60_000 });
const tags = computed(() => arr(tagsQuery.data.value?.tags));
const params = computed(() => ({
  folder: folderId.value || undefined, deep: deep.value ? 1 : undefined, state: state.value, tag: qs.get('tag') || undefined, owner: mineOnly.value ? 'me' : undefined,
  kind: ['file', 'link'].includes(qs.get('kind')) ? qs.get('kind') : undefined, type: TYPES.includes(qs.get('type')) ? qs.get('type') : undefined, q: qs.get('q') || undefined,
  sort: qs.get('sort') || undefined, order: qs.get('sort') ? (qs.get('order') === 'asc' ? 'asc' : 'desc') : undefined, page: qs.page(), pageSize: PAGE_SIZE,
}));
const list = useList(`${API}/documents`, params);

const STATE_TABS = [{ k: 'active', label: { ar: 'السارية', en: 'Current' } }, { k: 'archived', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } }];
const TYPE_OPTIONS = TYPES.map((v) => ({ v, l: FILE_KIND[v] }));
const KIND_OPTIONS = [{ v: 'file', l: { ar: 'ملفات', en: 'Files' } }, { v: 'link', l: FILE_KIND.link }];
const tagOptions = computed(() => tags.value.filter((x) => x.active !== false || x.id === qs.get('tag')).filter((x) => !x.folderId || !folderId.value || x.folderId === folderId.value || x.id === qs.get('tag'))
  .map((x) => ({ v: x.id, l: x.group ? `${x.group}: ${x.name}` : String(x.name ?? '') })));
const columns = computed(() => [
  { key: 'number', header: { ar: 'الرقم', en: 'Number' }, width: '128px', kind: 'id', sortable: true },
  { key: 'name', header: { ar: 'المستند', en: 'Document' }, width: 'minmax(220px,2.2fr)', sortable: true },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '110px', bare: true },
  { key: 'owner', header: { ar: 'المالك', en: 'Owner' }, width: 'minmax(110px,1fr)' },
  { key: 'size', header: { ar: 'الحجم', en: 'Size' }, width: '84px', kind: 'num', sortable: true, value: (r) => (r.kind === 'link' ? '—' : fileSize(r.size) || '—') },
  { key: 'updatedAt', header: { ar: 'آخر تعديل', en: 'Last change' }, width: '120px', kind: 'date', sortable: true, value: (r) => fmtDate(r.updatedAt) },
  { key: 'act', header: '', width: '90px', bare: true },
]);
const kindOf = (r) => (r.kind === 'link' ? FILE_KIND.link : FILE_KIND[fileKind(r.mime, r.name)] || FILE_KIND.other);
const linkOf = (r) => (r.kind === 'link' && /^https?:\/\/\S+$/i.test(String(r.url || '')) ? r.url : null);
const emptyText = computed(() => (filtered.value ? { ar: 'لا مستندات تطابق هذا الفلتر.', en: 'No document matches this filter.' }
  : folder.value ? { ar: 'لا مستندات في هذا المجلد بعد.', en: 'This folder holds no document yet.' } : { ar: 'لا مستندات بعد.', en: 'No documents yet.' }));

// ---- what is done from here
const act = useAction();
/** Number of the document whose file is being fetched. */
const fetching = ref(null);
async function download(row) {
  fetching.value = row.number;
  await act.run(() => downloadFile(row.file), { success: (name) => t(`تم تنزيل ${name}`, `Downloaded ${name}`), invalidate: false });
  fetching.value = null;
}
async function archiveFolder() {
  const x = folder.value;
  if (!(await confirm({ title: { ar: `أرشفة المجلد «${x.name}»؟`, en: `Archive the folder “${x.name}”?` }, sub: { ar: 'يُؤرشف المجلد الفارغ فقط: انقل أو أرشف مستنداته ومجلداته الفرعية أولًا. لا يُحذف شيء.', en: 'Only an empty folder is archived: move or archive its documents and sub-folders first. Nothing is removed.' }, tone: 'danger', okLabel: { ar: 'أرشفة المجلد', en: 'Archive the folder' } }))) return;
  await act.run(() => api.postIdempotent(`${API}/folders/${x.id}/archive`), { success: t('أُرشف المجلد', 'Folder archived'), invalidate: INVALIDATE });
}
const restoreFolder = () => act.run(() => api.postIdempotent(`${API}/folders/${folder.value.id}/restore`), { success: t('استُعيد المجلد', 'Folder restored'), invalidate: INVALIDATE });

// ---- what is open
/** { mode: file | link, doc: the document being edited | null } while the document form is open */
const docForm = ref(null);
/** { folder: the folder being changed | null, parentId } while the folder form is open */
const folderForm = ref(null);
const tagsOpen = ref(false);
function onDocSaved(doc) { if (!docForm.value?.doc && doc?.number) qs.open('doc', doc.number); }
function onFolderSaved(saved) { if (!folderForm.value?.folder && saved?.id) selectFolder(saved.id); }
</script>

<template>
  <div class="grid gap-3.5 md:grid-cols-[270px_minmax(0,1fr)]">
    <!-- the folders: a tree on a wide screen, a select on a phone -->
    <aside v-if="!isMobile" class="card self-start">
      <div class="card-head">
        <div class="card-title">{{ t('المجلدات', 'Folders') }}</div>
        <Btn v-if="manages" tone="outline" size="sm" :label="{ ar: '+ مجلد', en: '+ Folder' }" @click="folderForm = { folder: null, parentId: folder && !folder.archived ? folder.id : '' }" />
      </div>
      <FolderTree :folders="folders" :selected="folderId" :loading="tree.isLoading.value" :with-archived="state !== 'active'" @select="selectFolder" @archived="(on) => qs.patch({ state: on ? 'all' : '' })" />
    </aside>
    <div v-else class="row wrap">
      <SelectInput field-class="min-w-0 flex-1" :model-value="folderId" :options="folderChoices" :placeholder="{ ar: 'كل المستندات', en: 'All documents' }" :aria-label="t('المجلد', 'Folder')" @update:model-value="selectFolder" />
      <Btn v-if="manages" tone="outline" size="sm" :label="{ ar: '+ مجلد', en: '+ Folder' }" @click="folderForm = { folder: null, parentId: folder && !folder.archived ? folder.id : '' }" />
    </div>

    <div class="min-w-0">
      <ErrorBanner :error="tree.error.value" :closable="false" />
      <!-- the folder on screen -->
      <div v-if="folder" class="card mb-3">
        <div class="card-body !pt-3.5">
          <div class="row wrap">
            <div class="min-w-0 flex-1 text-[13px] font-extrabold">
              <template v-for="(p, i) in path" :key="p.id">
                <span v-if="i" class="mx-1 text-faint">/</span>
                <button v-if="i < path.length - 1" type="button" class="cursor-pointer border-0 bg-transparent p-0 font-extrabold text-brand-dark" @click="selectFolder(p.id)"><span dir="auto">{{ p.name }}</span></button>
                <span v-else dir="auto">{{ p.name }}</span>
              </template>
            </div>
            <Chip v-if="folder.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
            <OdooMark :row="folder" />
            <template v-if="folder.canManage">
              <Btn tone="outline" size="sm" :label="{ ar: 'تعديل المجلد', en: 'Edit folder' }" @click="folderForm = { folder, parentId: '' }" />
              <Btn v-if="!folder.archived && !folder.owned" tone="dangerOutline" size="sm" :disabled="act.pending.value" :label="{ ar: 'أرشفة المجلد', en: 'Archive folder' }" @click="archiveFolder" />
              <Btn v-if="folder.archived && !folder.owned" tone="softBlue" size="sm" :loading="act.pending.value" :label="{ ar: 'استعادة المجلد', en: 'Restore folder' }" @click="restoreFolder" />
            </template>
          </div>
          <div v-if="folder.description" class="mt-1.5 whitespace-pre-wrap text-[10.5px] leading-[1.8] text-sec" dir="auto">{{ folder.description }}</div>
          <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-[10px] text-muted">
            <span>{{ t('القراءة', 'Reading') }}: <b class="text-sec">{{ scopeText(folder.readScope, folder.readRoles) }}</b></span>
            <span>{{ t('الكتابة', 'Writing') }}: <b class="text-sec">{{ scopeText(folder.writeScope, folder.writeRoles) }}</b></span>
            <span v-if="folder.ownRead">{{ t('القارئ يرى مستنداته فقط', 'A reader sees only his own documents') }}</span>
            <span v-if="folder.ownWrite">{{ t('الكاتب يعدّل مستنداته فقط', 'A writer changes only his own documents') }}</span>
            <span v-if="!folder.canWrite" class="font-bold text-warn">{{ t('لك القراءة فقط في هذا المجلد', 'You may only read in this folder') }}</span>
          </div>
        </div>
      </div>

      <div class="row wrap mb-2">
        <TextInput v-model="search" small type="search" class="w-[240px]" :placeholder="{ ar: 'بحث بالاسم أو الرقم…', en: 'Search name or number…' }" />
        <Tabs :model-value="state" :tabs="STATE_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => qs.patch({ state: k === 'active' ? '' : k })" />
        <span class="grow" />
        <Btn v-if="manages" tone="outline" size="sm" :label="{ ar: 'الوسوم', en: 'Tags' }" @click="tagsOpen = true" />
        <template v-if="canAdd">
          <Btn tone="outline" size="sm" :label="{ ar: '+ رابط', en: '+ Link' }" @click="docForm = { mode: 'link', doc: null }" />
          <Btn tone="primary" size="sm" :label="{ ar: '+ رفع مستند', en: '+ Upload' }" @click="docForm = { mode: 'file', doc: null }" />
        </template>
      </div>
      <div class="row wrap mb-2.5">
        <SelectInput small class="w-[150px]" :model-value="qs.get('type')" :options="TYPE_OPTIONS" :placeholder="{ ar: 'كل الأنواع', en: 'Every type' }" :aria-label="t('نوع الملف', 'File type')" @update:model-value="(v) => qs.patch({ type: v })" />
        <SelectInput small class="w-[130px]" :model-value="qs.get('kind')" :options="KIND_OPTIONS" :placeholder="{ ar: 'ملفات وروابط', en: 'Files and links' }" :aria-label="t('ملف أم رابط', 'File or link')" @update:model-value="(v) => qs.patch({ kind: v })" />
        <SelectInput small class="w-[170px]" :model-value="qs.get('tag')" :options="tagOptions" :placeholder="{ ar: 'كل الوسوم', en: 'Every tag' }" :aria-label="t('الوسم', 'Tag')" @update:model-value="(v) => qs.patch({ tag: v })" />
        <button type="button" class="pill" :class="{ active: mineOnly }" :aria-pressed="mineOnly" @click="qs.patch({ owner: mineOnly ? '' : 'me' })">{{ t('مستنداتي', 'My documents') }}</button>
        <button v-if="folder" type="button" class="pill" :class="{ active: deep }" :aria-pressed="deep" @click="qs.patch({ deep: deep ? '' : '1' })">{{ t('مع المجلدات الفرعية', 'With sub-folders') }}</button>
        <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="qs.patch(Object.fromEntries(FILTERS.map((k) => [k, ''])))" />
      </div>

      <ErrorBanner :error="list.error.value" :closable="false" />
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="card">
        <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number" :selected-key="qs.get('doc') || null" server-sort :sort="qs.sort()" :empty-text="emptyText"
                   @sort="qs.setSort" @page="(p) => qs.patch({ page: p })" @row-click="(r) => qs.open('doc', r.number)">
          <template #cell-name="{ row }">
            <div class="cell-name break-words" :class="{ 'text-muted line-through': row.archived }" dir="auto">{{ row.name }}</div>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[9.5px] text-muted">
              <span v-if="row.folder && (!folder || deep)" dir="auto">{{ row.folder.name }}</span>
              <span v-if="row.kind !== 'link' && row.version > 1" class="num">v{{ row.version }}</span>
              <TagChips :tags="row.tags" />
              <OdooMark :row="row" />
            </div>
          </template>
          <template #cell-type="{ row }">
            <span class="inline-flex flex-wrap items-center gap-1">
              <Chip small :label="kindOf(row)" :fg="kindOf(row).fg" :bg="kindOf(row).bg" />
              <Chip v-if="row.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
            </span>
          </template>
          <template #cell-owner="{ row }"><PersonName :person="row.owner" /></template>
          <template #cell-act="{ row }">
            <a v-if="linkOf(row)" :href="linkOf(row)" target="_blank" rel="noopener noreferrer" class="btn softBlue sm" @click.stop>{{ t('فتح', 'Open') }}</a>
            <Btn v-else-if="row.file && row.file.available !== false" tone="softBlue" size="sm" :loading="fetching === row.number" :disabled="act.pending.value" :label="{ ar: 'تنزيل', en: 'Download' }" @click.stop="download(row)" />
            <Chip v-else-if="row.file" small :label="{ ar: 'لم يُؤرشف بعد', en: 'Not archived yet' }" :fg="TONE.off.fg" :bg="TONE.off.bg" />
          </template>
        </DataTable>
      </div>
      <div class="hint">{{ t('لا يُحذف مستند: يُؤرشف ويُستعاد. رفع ملف جديد لمستند قائم يصير «نسخة جديدة» وتبقى السابقة. التنزيل يمرّ على صلاحية المجلد في كل مرة.', 'A document is never deleted: it is archived and restored. A new file for an existing document becomes a “new version” and the earlier one stays. Every download is checked against the folder permission.') }}</div>
    </div>
  </div>

  <DocumentDrawer :number="qs.get('doc')" :folders="folders" :lookups="lookups.data.value" @close="qs.close('doc')" @edit="(doc) => (docForm = { mode: doc.kind === 'link' ? 'link' : 'file', doc })" @folder="(id) => qs.replace({ tab: 'docs', folder: id })" />
  <DocumentForm :open="!!docForm" :mode="docForm ? docForm.mode : 'file'" :doc="docForm ? docForm.doc : null" :folder-id="folderId" :folders="folders" :tags="tags" :lookups="lookups.data.value" @close="docForm = null" @done="onDocSaved" />
  <FolderForm :open="!!folderForm" :folder="folderForm ? folderForm.folder : null" :parent-id="folderForm ? folderForm.parentId : ''" :folders="folders" :roles="lookups.data.value?.roles" @close="folderForm = null" @done="onFolderSaved" />
  <TagsManager :open="tagsOpen" docs :tags="tags" :folder-options="folderOptions(folders, (x) => !x.archived)" @close="tagsOpen = false" />
</template>
