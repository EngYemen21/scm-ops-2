<script setup>
// The pictures of one request: thumbnails, a viewer, adding more and taking one off.
// POST /product-requests/{id}/pictures { fileName, base64 } (an image; a phone photo is shrunk in the browser first)
// · GET …/pictures/{pid} (the bytes, fetched with the session token) · POST …/pictures/{pid}/remove { reason? }.
// A picture that was attached in Odoo is shown once the Odoo file archive keeps its content (`available`); until
// then its tile says so. A removed picture stays on record on the server; it simply leaves the strip.
//   <PicturesStrip :request-id="r.id" :pictures="r.pictures" :can-add="r.can.addPicture" :limit="8" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, Modal } from '@/components';
import { fmtNum, t } from '@/i18n';
import { ask } from '@/stores/ui';
import PreqPicture from './PreqPicture.vue';
import { API, SOURCE_CHIP, fetchBlob, fmtAt, readPicture, sizeText } from './preq';

const props = defineProps({
  requestId: { type: String, required: true },
  pictures: { type: Array, default: () => [] },
  canAdd: { type: Boolean, default: false },
  /** Pictures one request may carry, and the size one picture may have (GET lookups → limits). */
  limit: { type: Number, default: 8 },
  maxBytes: { type: Number, default: 4 * 1024 * 1024 },
});

const act = useAction({ invalidate: ['product-requests'] });
const picker = ref(null);
/** Name of the file being sent right now ('' = none). */
const sending = ref('');
/** The picture on screen in the viewer (null = closed). */
const viewing = ref(null);
const saving = ref(false);

const room = computed(() => Math.max(0, props.limit - props.pictures.length));
const waiting = computed(() => props.pictures.some((p) => !p.available));

function pick() { if (picker.value) picker.value.click(); }
async function onPick(e) {
  const files = [...(e.target.files || [])].slice(0, room.value);
  e.target.value = ''; // the same file can be picked again after a failure
  for (const file of files) {
    sending.value = file.name;
    const done = await act.run(async () => {
      const { fileName, base64 } = await readPicture(file, props.maxBytes);
      return api.postIdempotent(`${API}/${props.requestId}/pictures`, { fileName, base64 });
    }, { success: t('أُضيفت الصورة', 'Picture added') });
    if (done === undefined) break; // the banner says why; the remaining files are not sent blindly
  }
  sending.value = '';
}
async function remove(p) {
  const reason = await ask({
    title: { ar: 'إزالة هذه الصورة من الطلب؟', en: 'Take this picture off the request?' }, sub: { ar: 'تبقى محفوظة في السجل مع من أزالها ولماذا.', en: 'It stays on record, with who removed it and why.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إزالة', en: 'Remove' }, tone: 'danger',
  });
  if (reason === null) return;
  const done = await act.run(() => api.postIdempotent(`${API}/${props.requestId}/pictures/${p.id}/remove`, reason ? { reason } : {}), { success: t('أُزيلت الصورة', 'Picture removed') });
  if (done !== undefined && viewing.value && viewing.value.id === p.id) viewing.value = null;
}
/** Hands the picture on screen to the browser as a file. */
async function save() {
  const p = viewing.value;
  if (!p) return;
  saving.value = true;
  const blob = await act.run(() => fetchBlob(p.url), { success: false, invalidate: false });
  saving.value = false;
  if (!blob) return;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = p.fileName || 'picture';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}
</script>

<template>
  <div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!pictures.length && !canAdd" class="empty !p-3.5">{{ t('لا صور على هذا الطلب.', 'No pictures on this request.') }}</div>
    <div v-else class="flex flex-wrap gap-2">
      <div v-for="p in pictures" :key="p.id" class="relative">
        <button type="button" class="block cursor-pointer overflow-hidden rounded-xl border border-line bg-white p-0 hover:border-[#7FD6E5]" :disabled="!p.available" :title="p.fileName" @click="viewing = p">
          <PreqPicture :picture="p" class="h-[86px] w-[86px]" />
        </button>
        <span v-if="p.origin === 'odoo'" class="pointer-events-none absolute start-1 top-1 rounded-md bg-[#efeaf8] px-1 text-[7.5px] font-extrabold text-violet">{{ t('أودو', 'Odoo') }}</span>
        <button v-if="p.canRemove" type="button" class="x-btn absolute end-1 top-1 !h-5 !w-5 !rounded-md !bg-white/90 !text-[9px] !text-bad" :title="t('إزالة الصورة', 'Remove the picture')" :aria-label="t('إزالة الصورة', 'Remove the picture')" @click="remove(p)">✕</button>
      </div>
      <template v-if="canAdd && room > 0">
        <input ref="picker" type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="hidden" @change="onPick">
        <button type="button" class="flex h-[86px] w-[86px] cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-[#DCD2EE] bg-[#FAF8FD] text-violet" :disabled="!!sending" @click="pick">
          <span v-if="sending" class="spinner" />
          <span v-else class="text-[18px] leading-none">+</span>
          <span class="text-[9px] font-extrabold">{{ sending ? t('جارٍ الرفع…', 'Uploading…') : t('إضافة صورة', 'Add a picture') }}</span>
        </button>
      </template>
    </div>
    <div v-if="canAdd" class="mt-1.5 text-[9px] text-faint">{{ t(`حتى ${fmtNum(limit)} صور — تُصغَّر صور الهاتف تلقائيًا قبل الرفع`, `Up to ${fmtNum(limit)} pictures — phone photos are shrunk before they are sent`) }}</div>
    <div v-if="waiting" class="hint !mt-2">{{ t('«لم تُؤرشف»: الصورة مسجَّلة في أودو ولم يُنسخ محتواها إلى نسخة النظام الاحتياطية بعد. تظهر هنا تلقائيًا حين يُنسخ.', '“Not archived”: Odoo lists the picture and its content has not been copied into the OPS backup yet. It appears here by itself once it is.') }}</div>

    <!-- teleported: the picture must not be confined to the drawer it was opened from -->
    <Teleport to="body">
      <Modal :open="!!viewing" :width="780" :z-index="90" :title="viewing ? viewing.fileName : ''" @close="viewing = null">
        <template v-if="viewing">
          <PreqPicture :key="viewing.id" :picture="viewing" fit="contain" class="mx-auto h-[66vh] max-h-[640px] w-full rounded-xl border border-line !bg-white" />
          <div class="mt-2 flex flex-wrap items-center gap-2 text-[10px] text-muted">
            <Chip v-if="viewing.origin === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
            <span class="num ltr">{{ sizeText(viewing.size) }}</span>
            <span v-if="viewing.uploadedBy">· {{ viewing.uploadedBy }}</span>
            <span class="num ltr">· {{ fmtAt(viewing.createdAt) }}</span>
          </div>
        </template>
        <template #footer>
          <div class="flex gap-2">
            <Btn tone="dark" class="flex-1" :loading="saving" :label="{ ar: 'تنزيل الصورة', en: 'Download the picture' }" @click="save" />
            <Btn tone="soft" class="w-[110px]" :label="{ ar: 'إغلاق', en: 'Close' }" @click="viewing = null" />
          </div>
        </template>
      </Modal>
    </Teleport>
  </div>
</template>
