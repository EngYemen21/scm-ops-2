<script setup>
// The labels people put on tasks (project.manage: POST | PATCH /workspace/task-tags) or on documents (doc.manage:
// POST | PATCH /workspace/doc-tags — a document tag also has a heading it stands under and may belong to one folder).
// A tag is never removed: one that is no longer used is put away and stays on what already carries it. A tag that
// came from Odoo is read-only while the section follows Odoo.
//   <TagsManager :open :tags="work.tags" @close />        <TagsManager :open docs :tags="tags" :folder-options="…" @close />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import OdooMark from './OdooMark.vue';
import { API, INVALIDATE, TAG_COLORS, TONE, arr, tagStyle } from './workspace';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The tags of documents (heading + folder) instead of the tags of tasks. */
  docs: { type: Boolean, default: false },
  /** Every tag — the ones put away included. */
  tags: { type: Array, default: () => [] },
  /** docs: the folders a tag may be tied to, as select options. */
  folderOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const rows = computed(() => arr(props.tags));
const folderName = (id) => { const o = props.folderOptions.find((x) => x.v === id); return o ? String(o.l) : ''; };
const OFF = { ar: 'موقوف', en: 'Put away', ...TONE.off };

/** { id | null, name, color, group, folderId, active } while a tag is added or edited */
const form = ref(null);
const act = useAction();
function edit(tag = null) {
  act.clearError();
  form.value = tag ? { id: tag.id, name: tag.name || '', color: Number(tag.color) || 0, group: tag.group || '', folderId: tag.folderId || '', active: tag.active !== false }
    : { id: null, name: '', color: rows.value.length % TAG_COLORS.length, group: '', folderId: '', active: true };
}
watch(() => props.open, (now) => { if (!now) form.value = null; });

async function save() {
  const x = form.value;
  if (!x || !x.name.trim()) return;
  const body = { name: x.name.trim(), color: x.color, ...(props.docs ? { group: x.group.trim() || null, folderId: x.folderId || null } : {}), ...(x.id ? { active: !!x.active } : {}) };
  const url = `${API}/${props.docs ? 'doc-tags' : 'task-tags'}`;
  const done = await act.run(() => (x.id ? api.patch(`${url}/${x.id}`, body) : api.postIdempotent(url, body)), { success: t('حُفظ الوسم', 'Tag saved'), invalidate: INVALIDATE });
  if (done) form.value = null;
}
</script>

<template>
  <Drawer :open="open" :width="560" :title="docs ? { ar: 'وسوم المستندات', en: 'Document tags' } : { ar: 'وسوم المهام', en: 'Task tags' }"
          :sub="{ ar: 'لا يُحذف وسم: ما لم يعد مستعملًا يُوقَف ويبقى على ما يحمله', en: 'A tag is never removed: one that is no longer used is put away and stays on what carries it' }" @close="emit('close')">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div class="row mb-2.5">
      <span class="min-w-0 flex-1 text-[10.5px] text-muted">{{ t(`${rows.length} وسم`, `${rows.length} tag(s)`) }}</span>
      <Btn v-if="!form" tone="primary" size="sm" :label="{ ar: '+ وسم', en: '+ Tag' }" @click="edit()" />
    </div>

    <div v-if="form" class="mb-3 rounded-xl border border-line bg-soft p-3">
      <div class="mb-2 text-[11px] font-extrabold">{{ form.id ? t('تعديل الوسم', 'Edit the tag') : t('وسم جديد', 'New tag') }}</div>
      <div class="form-grid">
        <TextInput v-model="form.name" :label="{ ar: 'الاسم', en: 'Name' }" required :maxlength="docs ? 120 : 80" @enter="save" />
        <template v-if="docs">
          <TextInput v-model="form.group" :label="{ ar: 'العنوان الذي يندرج تحته', en: 'Heading it stands under' }" maxlength="120" :placeholder="{ ar: 'مثل: الحالة، نوع الوثيقة', en: 'e.g. Status, Kind of paper' }" />
          <SelectInput v-model="form.folderId" :label="{ ar: 'خاص بمجلد', en: 'Of one folder' }" :options="folderOptions" :placeholder="{ ar: 'كل المجلدات', en: 'Every folder' }" full />
        </template>
        <div class="field full">
          <div class="field-l">{{ t('اللون', 'Colour') }}</div>
          <div class="row wrap !gap-1.5">
            <button v-for="(c, i) in TAG_COLORS" :key="i" type="button" class="h-7 w-7 cursor-pointer rounded-full border-2" :style="{ background: c.bg, borderColor: form.color === i ? c.fg : 'transparent', color: c.fg }"
                    :aria-label="t(`اللون ${i + 1}`, `Colour ${i + 1}`)" :aria-pressed="form.color === i" @click="form.color = i">{{ form.color === i ? '✓' : '' }}</button>
          </div>
        </div>
        <label v-if="form.id" class="field full row cursor-pointer !gap-2 text-[10.5px] font-extrabold"><input v-model="form.active" type="checkbox">{{ t('فعّال (يُعرض عند الاختيار)', 'Active (offered when tags are picked)') }}</label>
      </div>
      <div class="row mt-2.5">
        <Btn tone="dark" size="sm" :loading="act.pending.value" :disabled="!form.name.trim()" :label="{ ar: 'حفظ الوسم', en: 'Save tag' }" @click="save" />
        <Btn tone="soft" size="sm" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="form = null" />
      </div>
    </div>

    <div v-if="!rows.length" class="empty !p-3.5">{{ t('لا وسوم معرَّفة بعد.', 'No tags defined yet.') }}</div>
    <div v-else class="overflow-hidden rounded-xl border border-line-2">
      <div v-for="tag in rows" :key="tag.id" class="flex flex-wrap items-center gap-2 border-t border-line-2 px-3 py-2 first:border-t-0">
        <Chip :fg="tagStyle(tag.color).fg" :bg="tagStyle(tag.color).bg">{{ tag.name }}</Chip>
        <span v-if="docs && tag.group" class="text-[10px] text-muted" dir="auto">{{ tag.group }}</span>
        <span v-if="docs && tag.folderId && folderName(tag.folderId)" class="text-[10px] text-sec" dir="auto">· {{ folderName(tag.folderId) }}</span>
        <span class="grow" />
        <Chip v-if="tag.active === false" small :label="OFF" :fg="OFF.fg" :bg="OFF.bg" />
        <OdooMark :row="tag" />
        <Btn v-if="!tag.owned" tone="ghost" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="edit(tag)" />
      </div>
    </div>
  </Drawer>
</template>
