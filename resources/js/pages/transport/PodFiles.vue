<script setup>
// The signature and the photo of a proof of delivery: one small button per stored file; a click opens it.
// The bytes come from GET /integrations/files/{key}?as=data (a data URL — an <img> tag cannot send the bearer token),
// loaded only when the viewer is opened. A row written before files were stored has no file and says so.
//   <PodFiles :attachments="pod.attachments" />        <PodFiles :attachments="…" dense />
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner, Modal } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { ATTACHMENT_STATUS_LABELS, POD_FILE_KIND_LABELS, filePath, hasFile, labelOf } from './tms';

const props = defineProps({
  attachments: { type: Array, default: () => [] },
  /** Table cell: icons + short labels on one line. */
  dense: { type: Boolean, default: false },
});

const files = computed(() => [...(props.attachments || [])].sort((a, b) => String(b.kind).localeCompare(String(a.kind))));
/** The attachment row shown in the viewer (null = closed). */
const cur = ref(null);
const q = useGet(() => (cur.value ? filePath(cur.value.storageKey) : null), { as: 'data' }, { staleTime: 10 * 60_000 });
const file = computed(() => (cur.value && q.data.value?.key === cur.value.storageKey ? q.data.value : null));
const isImage = computed(() => String(file.value?.mime || '').startsWith('image/'));
const kindLabel = (a) => bi(labelOf(POD_FILE_KIND_LABELS, a.kind));
const sizeText = (n) => (n == null ? '' : n >= 1024 * 1024 ? `${fmtNum(n / 1024 / 1024, 1)} MB` : `${fmtNum(Math.max(1, Math.round(n / 1024)))} KB`);
</script>

<template>
  <span v-if="files.length === 0" class="text-faint">—</span>
  <span v-else class="inline-flex flex-wrap items-center gap-1.5">
    <template v-for="a in files" :key="a.id || a.storageKey || a.kind">
      <button v-if="hasFile(a)" type="button" class="btn ghost sm !px-2 !py-0.5 !text-[9.5px]" :title="bi(labelOf(ATTACHMENT_STATUS_LABELS, a.status))" @click.stop="cur = a">
        {{ a.kind === 'signature' ? '✍' : '📷' }} {{ dense ? kindLabel(a) : t(`عرض ${kindLabel(a)}`, `View ${kindLabel(a).toLowerCase()}`) }}
      </button>
      <span v-else class="text-[9px] text-faint" :title="a.fileName || ''">{{ kindLabel(a) }}: {{ bi(labelOf(ATTACHMENT_STATUS_LABELS, a.status)) }}</span>
    </template>
    <!-- teleported: the viewer must not be confined to the table cell / drawer it was opened from -->
    <Teleport to="body">
      <Modal :open="!!cur" :width="640" :z-index="90" :title="cur ? kindLabel(cur) : ''" :sub="cur?.fileName || ''" @close="cur = null">
        <ErrorBanner :error="q.error.value" :closable="false" />
        <div v-if="q.isLoading.value && !file" class="skel h-[220px]" />
        <template v-if="file">
          <img v-if="isImage" :src="file.dataUrl" :alt="cur ? kindLabel(cur) : ''" class="mx-auto block max-h-[70vh] max-w-full rounded-xl border border-line bg-white">
          <a v-else :href="file.dataUrl" :download="file.fileName" class="btn">{{ t('تنزيل الملف', 'Download the file') }}</a>
          <div class="mt-2 text-center text-[9.5px] text-muted">
            <span class="num">{{ sizeText(file.size) }}</span> · <span dir="ltr" class="num">{{ file.mime }}</span><template v-if="cur"> · {{ bi(labelOf(ATTACHMENT_STATUS_LABELS, cur.status)) }}</template>
          </div>
        </template>
      </Modal>
    </Teleport>
  </span>
</template>
