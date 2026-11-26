<script setup>
// The attachments of a document: name, type, size and date as Odoo lists them. A file whose content the backup
// already holds (`archived` with a `url`, GET /api/odoo-backup/files/{id}) has a download link — the file is asked
// for with the session's token and saved, the way the reports page saves a CSV. Until then the row says
// «لم يُؤرشف بعد»: the list of attachments is copied before their content is. An attachment that is only an address
// in Odoo (`link`) opens that address.
//   <ArchiveFiles :items="doc.attachments" />
import { computed, ref } from 'vue';
import { useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner } from '@/components';
import { t } from '@/i18n';
import { TONE } from '../integration/odoo';
import { apiPathOf, dayText, downloadFromApi, fileSize, fileType, isObj, outsideUrl, textOf } from './archive';

const props = defineProps({
  /** [{ id, name, mimetype, size, archived, url, at?, link? }] */
  items: { type: Array, default: () => [] },
});

const NOT_YET = { ar: 'لم يُؤرشف بعد', en: 'Not archived yet', fg: TONE.off.fg, bg: TONE.off.bg };
const rows = computed(() => props.items.filter(isObj).map((a, i) => {
  const kept = a.archived === true || a.archived === 1;
  const name = textOf(a.name) || t('ملف بلا اسم', 'Unnamed file');
  const address = outsideUrl(a.link);
  return {
    key: a.id ?? `i${i}`, name, address,
    about: [address ? t('رابط', 'Link') : fileType(a.mimetype), address ? '' : fileSize(a.size), dayText(a.at)].filter(Boolean).join(' · '),
    path: kept ? apiPathOf(a.url) : null, outside: kept ? outsideUrl(a.url) : null,
  };
}));
const waiting = computed(() => rows.value.some((r) => !r.path && !r.outside && !r.address));

const act = useAction();
/** Key of the file being fetched. */
const busy = ref(null);
async function download(r) {
  busy.value = r.key;
  await act.run(() => downloadFromApi(r.path, null, r.name), { success: (file) => t(`تم تنزيل ${file}`, `Downloaded ${file}`), invalidate: false });
  busy.value = null;
}
</script>

<template>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="!rows.length" class="empty !p-3.5">{{ t('لا مرفقات على هذا المستند في أودو. تظهر هنا ملفاته: عقود، صور، مستندات ممسوحة.', 'This document has no attachment in Odoo. Its files appear here: contracts, pictures, scanned papers.') }}</div>
  <template v-else>
    <div class="overflow-hidden rounded-xl border border-line-2">
      <div v-for="r in rows" :key="r.key" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-line-2 px-3 py-2 first:border-t-0">
        <div class="min-w-0 flex-1 basis-[200px]">
          <div class="break-words text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto">{{ r.name }}</div>
          <div v-if="r.about" class="num-mixed mt-px text-[9.5px] text-muted">{{ r.about }}</div>
        </div>
        <Btn v-if="r.path" tone="softBlue" size="sm" :loading="busy === r.key" :disabled="act.pending.value" :label="{ ar: 'تنزيل', en: 'Download' }" @click="download(r)" />
        <a v-else-if="r.outside" :href="r.outside" target="_blank" rel="noopener noreferrer" class="btn softBlue sm">{{ t('تنزيل', 'Download') }}</a>
        <a v-else-if="r.address" :href="r.address" target="_blank" rel="noopener noreferrer" class="btn soft sm">{{ t('فتح الرابط', 'Open the link') }}</a>
        <Chip v-else small :label="NOT_YET" :fg="NOT_YET.fg" :bg="NOT_YET.bg" />
      </div>
    </div>
    <div v-if="waiting" class="hint !mt-2.5">{{ t('«لم يُؤرشف بعد»: الملف مسجَّل في أودو باسمه وحجمه، ومحتواه لم يُنسخ إلى نسخة النظام الاحتياطية حتى الآن. يظهر رابط التنزيل هنا حين يُنسخ.', '“Not archived yet”: Odoo lists the file with its name and size, and its content has not been copied into the OPS backup so far. The download link appears here once it is.') }}</div>
  </template>
</template>
