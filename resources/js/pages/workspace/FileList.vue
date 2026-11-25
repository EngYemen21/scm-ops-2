<script setup>
// The files of a ticket, a message, a task — or the versions of a document: name, type and size, who added each and
// when. A file is asked for with the session's token and saved (GET /workspace/files/{id}); a picture or a PDF can
// be looked at in a new tab. An attachment that came from Odoo and whose content the backup does not hold yet says
// «لم يُؤرشف بعد» instead of offering a download. Taking a file off (POST files/{id}/remove) is offered where the
// server says it may be (`removable`): the file stays on record with who removed it.
//   <FileList :files="ticket.files" />      <FileList :files="doc.versions" versions :current-id="doc.file?.id" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { ask } from '@/stores/ui';
import { API, FILE_KIND, INVALIDATE, ODOO_CHIP, TONE, arr, downloadFile, fileKind, fileSize, isInline, openFile } from './workspace';

const props = defineProps({
  /** [File] */
  files: { type: Array, default: () => [] },
  /** The rows are the versions of a document: show the number and the note of each. */
  versions: { type: Boolean, default: false },
  /** Id of the version in force. */
  currentId: { type: String, default: null },
  emptyText: { type: [String, Object], default: null },
});

const NOT_YET = { ar: 'لم يُؤرشف بعد', en: 'Not archived yet', ...TONE.off };
const CURRENT = { ar: 'النسخة السارية', en: 'Current', ...TONE.ok };
const rows = computed(() => arr(props.files).filter((f) => f && typeof f === 'object').map((f) => {
  const kind = fileKind(f.mime, f.name);
  return { file: f, key: f.id, kind, about: [FILE_KIND[kind] ? t(FILE_KIND[kind].ar, FILE_KIND[kind].en) : '', fileSize(f.size)].filter(Boolean).join(' · '),
    by: [f.createdBy, f.createdAt ? fmtDate(f.createdAt) : ''].filter(Boolean).join(' · '), viewable: f.available !== false && isInline(f.mime) };
}));
const waiting = computed(() => rows.value.some((r) => r.file.available === false));

const act = useAction();
/** `<id>:<what>` of the file being fetched or removed. */
const busy = ref(null);
async function download(r) {
  busy.value = `${r.key}:save`;
  await act.run(() => downloadFile(r.file), { success: (name) => t(`تم تنزيل ${name}`, `Downloaded ${name}`), invalidate: false });
  busy.value = null;
}
async function view(r) {
  busy.value = `${r.key}:view`;
  await act.run(() => openFile(r.file), { success: false, invalidate: false });
  busy.value = null;
}
async function remove(r) {
  const reason = await ask({ title: { ar: `إزالة الملف «${r.file.name}»؟`, en: `Take “${r.file.name}” off?` }, sub: { ar: 'يبقى الملف في السجل مع اسم من أزاله، ولا يظهر بعد ذلك في القائمة.', en: 'The file stays on record with who removed it; the list no longer shows it.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'إزالة', en: 'Remove' } });
  if (reason === null) return;
  busy.value = `${r.key}:remove`;
  await act.run(() => api.postIdempotent(`${API}/files/${r.key}/remove`, reason ? { reason } : {}), { success: t('أُزيل الملف', 'File removed'), invalidate: INVALIDATE });
  busy.value = null;
}
</script>

<template>
  <div>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="!rows.length" class="empty !p-3.5">{{ emptyText ? bi(emptyText) : t('لا ملفات.', 'No files.') }}</div>
  <template v-else>
    <div class="overflow-hidden rounded-xl border border-line-2">
      <div v-for="r in rows" :key="r.key" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-line-2 px-3 py-2 first:border-t-0">
        <span v-if="versions" class="num flex-none rounded-lg bg-soft px-2 py-1 text-[10px] text-violet">v{{ r.file.versionNo ?? '—' }}</span>
        <div class="min-w-0 flex-1 basis-[190px]">
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="break-words text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto">{{ r.file.name }}</span>
            <Chip v-if="versions && currentId && r.key === currentId" small :label="CURRENT" :fg="CURRENT.fg" :bg="CURRENT.bg" />
            <Chip v-if="r.file.fromOdoo" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" />
          </div>
          <div class="num-mixed mt-px text-[9.5px] text-muted"><span v-if="r.about" class="ltr inline-block">{{ r.about }}</span><span v-if="r.about && r.by"> · </span><span v-if="r.by">{{ r.by }}</span></div>
          <div v-if="versions && r.file.note" class="mt-0.5 whitespace-pre-wrap text-[10px] leading-[1.7] text-sec" dir="auto">{{ r.file.note }}</div>
        </div>
        <template v-if="r.file.available !== false">
          <Btn v-if="r.viewable" tone="soft" size="sm" :loading="busy === `${r.key}:view`" :disabled="act.pending.value" :label="{ ar: 'عرض', en: 'View' }" @click="view(r)" />
          <Btn tone="softBlue" size="sm" :loading="busy === `${r.key}:save`" :disabled="act.pending.value" :label="{ ar: 'تنزيل', en: 'Download' }" @click="download(r)" />
        </template>
        <Chip v-else small :label="NOT_YET" :fg="NOT_YET.fg" :bg="NOT_YET.bg" />
        <Btn v-if="r.file.removable" tone="ghost" size="sm" class="!text-bad" :loading="busy === `${r.key}:remove`" :disabled="act.pending.value" :label="{ ar: 'إزالة', en: 'Remove' }" @click="remove(r)" />
      </div>
    </div>
    <div v-if="waiting" class="hint !mt-2">{{ t('«لم يُؤرشف بعد»: الملف مسجَّل في أودو باسمه وحجمه، ومحتواه لم يُنسخ إلى نسخة النظام الاحتياطية حتى الآن. يظهر التنزيل هنا حين يُنسخ.', '“Not archived yet”: Odoo lists the file with its name and size, and its content has not been copied into the OPS backup so far. The download appears here once it is.') }}</div>
  </template>
  </div>
</template>
