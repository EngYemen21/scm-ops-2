<script setup>
// The files of one approval request: the list (who added what, when), opening one, adding more and taking one off.
// POST /approvals/requests/{n}/attachments { fileName, mime, base64 } · GET …/attachments/{id} (the bytes, fetched with the
// session token) · POST …/attachments/{id}/remove { reason? }.
// A file that was attached in Odoo opens once the Odoo file archive keeps its content (`available`); until then it says
// so. A removed file stays on record: it is listed apart, struck through.
//   <AttachmentsBox :number="r.number" :attachments="r.attachments" :can-attach="r.can.attach" />
import { computed, onBeforeUnmount, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, Modal } from '@/components';
import { t } from '@/i18n';
import { ask } from '@/stores/ui';
import { SOURCE_CHIP, TONE, attachmentPath, fetchBlob, fmtAt, isImage, isPdf, personName, readFile, saveBlob, sizeText } from './approvals';

const props = defineProps({
  number: { type: String, required: true },
  attachments: { type: Array, default: () => [] },
  canAttach: { type: Boolean, default: false },
});

const act = useAction({ invalidate: ['approvals'] });
const picker = ref(null);
/** Name of the file being sent right now ('' = none). */
const sending = ref('');
/** Id of the attachment being fetched right now. */
const fetching = ref(null);
/** The picture on screen: { name, url, blob } (null = closed). */
const viewer = ref(null);

const kept = computed(() => props.attachments.filter((a) => !a.removed));
const removed = computed(() => props.attachments.filter((a) => a.removed));
const icon = (a) => (isImage(a.mime) ? '🖼' : isPdf(a.mime) ? '📄' : '📎');

function pick() { picker.value?.click(); }
async function onPick(e) {
  const files = [...(e.target.files || [])];
  e.target.value = ''; // the same file can be picked again after a failure
  for (const file of files) {
    sending.value = file.name;
    const done = await act.run(async () => {
      const { fileName, mime, base64 } = await readFile(file);
      return api.postIdempotent(`/approvals/requests/${encodeURIComponent(props.number)}/attachments`, { fileName, mime, base64 });
    }, { success: t(`أُرفق «${file.name}»`, `“${file.name}” attached`) });
    if (done === undefined) break; // the banner says why; the remaining files are not sent blindly
  }
  sending.value = '';
}

function closeViewer() {
  if (viewer.value) URL.revokeObjectURL(viewer.value.url);
  viewer.value = null;
}
async function open(a) {
  fetching.value = a.id;
  const blob = await act.run(() => fetchBlob(attachmentPath(props.number, a.id)), { success: false, invalidate: false });
  fetching.value = null;
  if (!blob) return;
  if (isImage(a.mime)) { closeViewer(); viewer.value = { name: a.fileName, url: URL.createObjectURL(blob), blob }; } else saveBlob(blob, a.fileName);
}
async function remove(a) {
  const reason = await ask({
    title: { ar: `إزالة المرفق «${a.fileName}»؟`, en: `Remove “${a.fileName}”?` }, sub: { ar: 'يبقى الملف محفوظًا في السجل، ويُكتب من أزاله ولماذا.', en: 'The file stays on record, with who removed it and why.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إزالة', en: 'Remove' }, tone: 'danger',
  });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`${attachmentPath(props.number, a.id)}/remove`, reason ? { reason } : {}), { success: t('أُزيل المرفق', 'Attachment removed') });
}
onBeforeUnmount(closeViewer);
</script>

<template>
  <div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!kept.length" class="empty !p-3.5">{{ t('لا مرفقات على هذا الطلب.', 'No files on this request.') }}</div>
    <div v-for="a in kept" :key="a.id" class="row mb-1 rounded-[11px] border border-line-2 px-[12px] py-[8px]">
      <span class="flex-none text-[15px]">{{ icon(a) }}</span>
      <div class="min-w-0 flex-1">
        <div class="truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto" :title="a.fileName">{{ a.fileName }}</div>
        <div class="text-[9.5px] text-muted">
          <span class="num ltr">{{ sizeText(a.size) }}</span><template v-if="a.uploadedBy"> · {{ personName(a.uploadedBy) }}</template> · <span class="num ltr">{{ fmtAt(a.createdAt) }}</span>
        </div>
        <div v-if="!a.available" class="text-[9.5px] font-bold text-warn">{{ t('مرفق من أودو لم يُؤرشف محتواه هنا بعد — يُفتح بعد اكتمال أرشفة ملفات أودو.', 'A file of Odoo whose content is not archived here yet — it opens once the Odoo files are archived.') }}</div>
      </div>
      <Chip v-if="a.source === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
      <Btn tone="soft" size="sm" :loading="fetching === a.id" :disabled="!a.available" :label="isImage(a.mime) ? { ar: 'عرض', en: 'View' } : { ar: 'تنزيل', en: 'Download' }" @click="open(a)" />
      <Btn v-if="a.canRemove" tone="ghost" size="sm" class="!text-bad" :label="{ ar: 'إزالة', en: 'Remove' }" @click="remove(a)" />
    </div>
    <div v-if="canAttach" class="row wrap mt-2">
      <input ref="picker" type="file" multiple class="hidden" @change="onPick">
      <Btn tone="softPurple" size="sm" :loading="!!sending" :label="{ ar: '+ إرفاق ملف', en: '+ Attach a file' }" @click="pick" />
      <span v-if="sending" class="truncate text-[9.5px] font-bold text-muted" dir="auto">{{ t('جارٍ رفع', 'Uploading') }} {{ sending }}…</span>
      <span v-else class="text-[9.5px] text-faint">{{ t('حتى 5 ميجابايت للملف — صور، PDF، مستندات', 'Up to 5 MB per file — pictures, PDF, documents') }}</span>
    </div>
    <div v-if="removed.length" class="mt-2.5">
      <div class="text-[9.5px] font-extrabold text-faint">{{ t('مرفقات أُزيلت (باقية في السجل)', 'Removed files (kept on record)') }}</div>
      <div v-for="a in removed" :key="a.id" class="mt-1 text-[9.5px] text-muted">
        <span class="line-through" dir="auto">{{ a.fileName }}</span> — {{ a.removedBy || '—' }} · <span class="num ltr">{{ fmtAt(a.removedAt) }}</span><template v-if="a.removeReason"> · {{ a.removeReason }}</template>
        <Chip v-if="a.source === 'odoo'" small class="ms-1" :label="SOURCE_CHIP" :fg="TONE.off.fg" :bg="TONE.off.bg" />
      </div>
    </div>
    <!-- teleported: the picture must not be confined to the drawer it was opened from -->
    <Teleport to="body">
      <Modal :open="!!viewer" :width="760" :z-index="90" :title="viewer ? viewer.name : ''" @close="closeViewer">
        <img v-if="viewer" :src="viewer.url" :alt="viewer.name" class="mx-auto block max-h-[72vh] max-w-full rounded-xl border border-line bg-white">
        <template #footer>
          <div class="flex gap-2">
            <Btn tone="dark" class="flex-1" :label="{ ar: 'تنزيل الملف', en: 'Download the file' }" @click="viewer && saveBlob(viewer.blob, viewer.name)" />
            <Btn tone="soft" class="w-[110px]" :label="{ ar: 'إغلاق', en: 'Close' }" @click="closeViewer" />
          </div>
        </template>
      </Modal>
    </Teleport>
  </div>
</template>
