<script setup>
// The folders the caller may read, as a tree built from the flat list of GET /workspace/folders (folders.js): each
// with how many documents it holds, a mark when reading it is limited to some roles, when it came from Odoo and when
// it is archived. The first line stands for every folder at once; the switch at the bottom asks for what was put
// away as well (the page keeps one switch for archived folders and archived documents: `state` in the address).
//   <FolderTree :folders="folders" :selected="qs.get('folder')" :with-archived="state !== 'active'" @select="(id) => …" @archived="(on) => …" />
import { computed, ref } from 'vue';
import { Chip } from '@/components';
import { fmtNum, t } from '@/i18n';
import OdooMark from './OdooMark.vue';
import { folderRows } from './folders';
import { ARCHIVED_CHIP, TONE } from './workspace';

const props = defineProps({
  /** The flat list of GET folders. */
  folders: { type: Array, default: () => [] },
  /** Id of the folder on screen; '' = every folder. */
  selected: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  /** The list also carries the archived folders (the page asks for them together with the archived documents). */
  withArchived: { type: Boolean, default: false },
});
const emit = defineEmits(['select', 'archived']);

/** Ids of the folders whose children are folded. */
const closed = ref(new Set());
function toggle(id) {
  const next = new Set(closed.value);
  if (next.has(id)) next.delete(id); else next.add(id);
  closed.value = next;
}
const rows = computed(() => folderRows(props.folders, closed.value));
const LIMITED = { ar: 'مقيَّد', en: 'Restricted', ...TONE.violet };
</script>

<template>
  <nav :aria-label="t('المجلدات', 'Folders')">
    <button type="button" class="flex w-full cursor-pointer items-center gap-2 border-0 px-3 py-2.5 text-start text-[11.5px] font-extrabold" :class="selected === '' ? 'bg-violet-soft text-violet' : 'bg-transparent text-ink hover:bg-soft'"
            :aria-current="selected === '' ? 'true' : null" @click="emit('select', '')">
      {{ t('كل المستندات', 'All documents') }}
    </button>
    <div v-if="loading && !rows.length" class="flex flex-col gap-1.5 px-3 py-2"><div v-for="i in 5" :key="i" class="skel h-4" :style="{ width: `${50 + ((i * 23) % 40)}%` }" /></div>
    <div v-else-if="!rows.length" class="px-3 py-3 text-[10.5px] leading-[1.8] text-faint">{{ t('لا مجلدات متاحة لك بعد.', 'No folder is open to you yet.') }}</div>
    <div v-for="r in rows" :key="r.folder.id" class="flex items-center border-t border-line-2" :class="selected === r.folder.id ? 'bg-violet-soft' : 'hover:bg-soft'" :style="{ paddingInlineStart: `${6 + r.depth * 14}px` }">
      <button v-if="r.hasChildren" type="button" class="flex h-7 w-6 flex-none cursor-pointer items-center justify-center border-0 bg-transparent text-[9px] text-muted"
              :aria-label="closed.has(r.folder.id) ? t('فتح المجلد', 'Unfold') : t('طي المجلد', 'Fold')" :aria-expanded="!closed.has(r.folder.id)" @click="toggle(r.folder.id)">{{ closed.has(r.folder.id) ? '▸' : '▾' }}</button>
      <span v-else class="w-6 flex-none" />
      <button type="button" class="flex min-w-0 flex-1 cursor-pointer items-center gap-1.5 border-0 bg-transparent py-2 pe-3 text-start text-[11px]" :class="selected === r.folder.id ? 'font-extrabold text-violet' : 'font-bold text-ink'"
              :aria-current="selected === r.folder.id ? 'true' : null" @click="emit('select', r.folder.id)">
        <span class="min-w-0 flex-1 truncate" :class="{ 'text-muted line-through': r.folder.archived }" dir="auto">{{ r.folder.name }}</span>
        <Chip v-if="r.folder.readScope === 'roles'" small :label="LIMITED" :fg="LIMITED.fg" :bg="LIMITED.bg" :title="t('القراءة محصورة بأدوار محددة', 'Reading is limited to some roles')" />
        <Chip v-if="r.folder.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <OdooMark :row="r.folder" />
        <span class="num flex-none text-[9.5px] text-muted">{{ fmtNum(r.folder.documentCount || 0) }}</span>
      </button>
    </div>
    <label class="row cursor-pointer !gap-2 border-t border-line-2 px-3 py-2.5 text-[10px] font-bold text-muted">
      <input type="checkbox" :checked="withArchived" @change="emit('archived', $event.target.checked)">{{ t('إظهار المؤرشف (مجلدات ومستندات)', 'Show what is archived (folders and documents)') }}
    </label>
  </nav>
</template>
