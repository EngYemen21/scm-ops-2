<script setup>
// A folder of the documents tree is created or changed — by whoever holds doc.manage
// (POST /workspace/folders · PATCH /workspace/folders/{id}): its name, where it stands, and who may read and write
// it, by role. «الجميع» = everybody who opens the section; «أدوار محددة» = the roles ticked, and with none ticked the
// documents manager alone. Writing includes reading. On a folder that came from Odoo — while the section follows
// Odoo — only the access may change here (it is OPS's own): readScope, readRoles, writeScope, writeRoles.
//   <FolderForm :open :folder="editing" :parent-id="current" :folders="folders" :roles="lookups.roles" @close @done="(folder) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import WsFormDrawer from './WsFormDrawer.vue';
import { folderBranch, folderOptions } from './folders';
import { API, INVALIDATE, OWNED_NOTE, arr, personName } from './workspace';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The folder being changed; null = a new folder. */
  folder: { type: Object, default: null },
  /** Where a new folder is put ('' = the top level). */
  parentId: { type: String, default: '' },
  /** The flat list of GET folders (where a folder may stand). */
  folders: { type: Array, default: () => [] },
  /** lookups.roles: [{ key, nameAr, nameEn }] */
  roles: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const editing = computed(() => !!props.folder);
/** Odoo owns the folder: only who reads and writes it is OPS's to say. */
const accessOnly = computed(() => !!props.folder?.owned);
function values(x) {
  return { name: x?.name || '', parentId: x ? x.parentId || '' : props.parentId || '', description: x?.description || '', sequence: x?.sequence ?? 0,
    readScope: x?.readScope === 'roles' ? 'roles' : 'all', readRoles: [...arr(x?.readRoles)], writeScope: x?.writeScope === 'roles' ? 'roles' : 'all', writeRoles: [...arr(x?.writeRoles)],
    ownRead: !!x?.ownRead, ownWrite: !!x?.ownWrite };
}
const f = ref(values(props.folder));
watch(() => props.open, (now) => { if (now) { f.value = values(props.folder); act.clearError(); } });

const SCOPES = [{ v: 'all', l: { ar: 'الجميع', en: 'Everyone' } }, { v: 'roles', l: { ar: 'أدوار محددة', en: 'Some roles' } }];
/** A folder cannot be put inside itself or under one of its own sub-folders. */
const parentOptions = computed(() => {
  const own = props.folder ? folderBranch(props.folders, props.folder.id) : new Set();
  return folderOptions(props.folders, (x) => !own.has(x.id) && (!x.archived || x.id === f.value.parentId));
});
function toggle(list, key) {
  f.value[list] = f.value[list].includes(key) ? f.value[list].filter((k) => k !== key) : [...f.value[list], key];
}
const missing = computed(() => (!accessOnly.value && !f.value.name.trim() ? { ar: 'اكتب اسم المجلد.', en: 'Give the folder a name.' } : null));

async function save() {
  if (missing.value) return;
  const v = f.value;
  const access = { readScope: v.readScope, readRoles: v.readScope === 'roles' ? v.readRoles : [], writeScope: v.writeScope, writeRoles: v.writeScope === 'roles' ? v.writeRoles : [] };
  const body = accessOnly.value ? access
    : { name: v.name.trim(), parentId: v.parentId || null, description: v.description.trim() || null, sequence: Number(v.sequence) || 0, ...access, ownRead: !!v.ownRead, ownWrite: !!v.ownWrite };
  const done = editing.value
    ? await act.run(() => api.patch(`${API}/folders/${props.folder.id}`, body), { success: t('حُفظ المجلد', 'Folder saved'), invalidate: INVALIDATE })
    : await act.run(() => api.postIdempotent(`${API}/folders`, body), { success: t('أُنشئ المجلد', 'Folder created'), invalidate: INVALIDATE });
  if (done) { emit('done', done); emit('close'); }
}
</script>

<template>
  <WsFormDrawer :open="open" :title="editing ? { ar: 'تعديل المجلد', en: 'Edit folder' } : { ar: 'مجلد جديد', en: 'New folder' }" :sub="editing ? folder.name : null"
                :pending="act.pending.value" :error="act.error.value" :disabled="!!missing" :why="missing" @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="accessOnly" class="banner teal">{{ t(OWNED_NOTE.ar, OWNED_NOTE.en) }} {{ t('يُحدَّد هنا من يقرأ المجلد ومن يكتب فيه فقط.', 'Only who reads the folder and who writes in it is set here.') }}</div>
    <div class="form-grid">
      <TextInput v-model="f.name" :label="{ ar: 'اسم المجلد', en: 'Folder name' }" required full :disabled="accessOnly" maxlength="190" />
      <SelectInput v-model="f.parentId" :label="{ ar: 'داخل المجلد', en: 'Inside the folder' }" :options="parentOptions" :placeholder="{ ar: '— المستوى الأعلى —', en: '— the top level —' }" :disabled="accessOnly" />
      <NumberInput v-model="f.sequence" :label="{ ar: 'الترتيب', en: 'Order' }" :min="0" :max="100000" :disabled="accessOnly" :hint="{ ar: 'الأصغر أولًا.', en: 'Smallest first.' }" />
      <TextArea v-model="f.description" :label="{ ar: 'الوصف', en: 'Description' }" :rows="2" :disabled="accessOnly" />

      <div class="field full">
        <PillChoice v-model="f.readScope" :label="{ ar: 'من يقرأ المجلد', en: 'Who reads the folder' }" :options="SCOPES" />
        <div v-if="f.readScope === 'roles'" class="row wrap !gap-1.5">
          <button v-for="r in roles" :key="r.key" type="button" class="pill purple" :class="{ active: f.readRoles.includes(r.key) }" :aria-pressed="f.readRoles.includes(r.key)" @click="toggle('readRoles', r.key)">{{ personName(r) }}</button>
        </div>
        <div v-if="f.readScope === 'roles' && !f.readRoles.length" class="field-hint">{{ t('لم يُختر دور: يقرؤه مدير المستندات ومن يملك الكتابة فيه فقط.', 'No role ticked: only the documents manager, and whoever may write in it, reads it.') }}</div>
      </div>
      <div class="field full">
        <PillChoice v-model="f.writeScope" :label="{ ar: 'من يرفع ويعدّل فيه', en: 'Who uploads and changes in it' }" :options="SCOPES" />
        <div v-if="f.writeScope === 'roles'" class="row wrap !gap-1.5">
          <button v-for="r in roles" :key="r.key" type="button" class="pill purple" :class="{ active: f.writeRoles.includes(r.key) }" :aria-pressed="f.writeRoles.includes(r.key)" @click="toggle('writeRoles', r.key)">{{ personName(r) }}</button>
        </div>
        <div v-if="f.writeScope === 'roles' && !f.writeRoles.length" class="field-hint">{{ t('لم يُختر دور: يكتب فيه مدير المستندات فقط.', 'No role ticked: only the documents manager writes in it.') }}</div>
        <div class="field-hint">{{ t('من يملك الكتابة يملك القراءة.', 'Whoever may write may read.') }}</div>
      </div>

      <label class="field full row cursor-pointer !gap-2 text-[10.5px] font-extrabold"><input v-model="f.ownRead" type="checkbox" :disabled="accessOnly">{{ t('القارئ يرى مستنداته هو فقط', 'A reader sees only the documents he owns') }}</label>
      <label class="field full row cursor-pointer !gap-2 text-[10.5px] font-extrabold"><input v-model="f.ownWrite" type="checkbox" :disabled="accessOnly">{{ t('الكاتب يعدّل مستنداته هو فقط', 'A writer changes only the documents he owns') }}</label>
    </div>
  </WsFormDrawer>
</template>
