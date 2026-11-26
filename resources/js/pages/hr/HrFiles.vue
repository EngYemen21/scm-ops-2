<script setup>
// The files of a record (a document's scans, the receipts of a claim line, the papers of the personal file): one small
// button per file; a click opens it. The bytes come from GET /hr/files/{id}?as=data (a data URL — an <img> or a link
// cannot send the bearer token) and are asked for only when the viewer opens; the server checks the permission of what
// the file hangs on, and its refusal is shown as it is (an attachment that came from Odoo and is not archived yet says so).
//   <HrFiles :files="line.receipts" />     <HrFiles :files="doc.files" removable @remove="(f) => …" />
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner, Modal } from '@/components';
import { t } from '@/i18n';
import { fileSize } from './shared';

const props = defineProps({
  files: { type: Array, default: () => [] },
  /** Show a ✕ beside each file (the parent removes it). */
  removable: { type: Boolean, default: false },
  /** Text when there is no file; null shows nothing. */
  empty: { type: String, default: null },
});
const emit = defineEmits(['remove']);

/** The file shown in the viewer (null = closed). */
const cur = ref(null);
const q = useGet(() => (cur.value ? `/hr/files/${cur.value.id}` : null), { as: 'data' }, { staleTime: 10 * 60_000 });
const file = computed(() => (cur.value && q.data.value?.id === cur.value.id ? q.data.value : null));
const isImage = computed(() => String(file.value?.mime || '').startsWith('image/'));
const isPdf = computed(() => file.value?.mime === 'application/pdf');

// a PDF is shown from a blob URL (browsers refuse to frame a data: URL of a PDF); it is released when the viewer closes
const blobUrl = ref(null);
function release() { if (blobUrl.value) { URL.revokeObjectURL(blobUrl.value); blobUrl.value = null; } }
watch(file, (f) => {
  release();
  if (!f || f.mime !== 'application/pdf') return;
  try {
    const bytes = Uint8Array.from(atob(String(f.dataUrl).split(',')[1] || ''), (c) => c.charCodeAt(0));
    blobUrl.value = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
  } catch { blobUrl.value = null; }
});
onBeforeUnmount(release);
const icon = (f) => (String(f.mime || '').startsWith('image/') ? '🖼' : '📄');
</script>

<template>
  <span v-if="!files.length && empty" class="text-[10px] text-faint">{{ empty }}</span>
  <span v-else-if="files.length" class="inline-flex flex-wrap items-center gap-1.5">
    <span v-for="f in files" :key="f.id" class="inline-flex items-center">
      <button type="button" class="btn ghost sm !max-w-[220px] !px-2 !py-0.5 !text-[9.5px]" :title="`${f.name} · ${fileSize(f.size)}`" @click.stop="cur = f">
        {{ icon(f) }} <span class="truncate" dir="auto">{{ f.name }}</span><span v-if="f.fromOdoo" class="text-violet"> · {{ t('أودو', 'Odoo') }}</span>
      </button>
      <button v-if="removable" type="button" class="x-btn !h-5 !w-5 !text-[9px]" :aria-label="t('إزالة الملف', 'Remove the file')" @click.stop="emit('remove', f)">✕</button>
    </span>
    <!-- teleported: the viewer must not be confined to the table cell or the drawer it was opened from -->
    <Teleport to="body">
      <Modal :open="!!cur" :width="760" :z-index="90" :title="cur ? cur.name : ''" :sub="cur ? fileSize(cur.size) : ''" @close="cur = null">
        <ErrorBanner :error="q.error.value" :closable="false" />
        <div v-if="q.isLoading.value && !file" class="skel h-[220px]" />
        <template v-if="file">
          <img v-if="isImage" :src="file.dataUrl" :alt="file.fileName" class="mx-auto block max-h-[70vh] max-w-full rounded-xl border border-line bg-white">
          <iframe v-else-if="isPdf && blobUrl" :src="blobUrl" :title="file.fileName" class="h-[70vh] w-full rounded-xl border border-line bg-white" />
          <div class="mt-2.5 flex flex-wrap items-center justify-center gap-2 text-[9.5px] text-muted">
            <a :href="file.dataUrl" :download="file.fileName" class="btn soft sm">{{ t('تنزيل الملف', 'Download the file') }}</a>
            <span class="num">{{ fileSize(file.size) }}</span> · <span dir="ltr" class="num">{{ file.mime }}</span>
          </div>
        </template>
      </Modal>
    </Teleport>
  </span>
</template>
