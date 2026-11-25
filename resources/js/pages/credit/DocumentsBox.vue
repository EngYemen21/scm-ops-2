<script setup>
// The nine papers of a request (slots from the API: required mark, filled, the current file and — for the reviewers —
// the replaced / removed ones, greyed, with who closed them and why).
//   upload  POST /credit/requests/{id}/documents { slot, fileName, base64 } — a PDF or an image of at most 5 MB; offered
//           when `can.addDocument` or `can.addOwnDocument` (the server decides per slot). A slot of one paper is
//           replaced by a new upload; «مرفق آخر» takes many.
//   open    GET …/documents/{file}?as=data → { fileName, mime, dataUrl } (a link could not carry the bearer token)
//   remove  POST …/documents/{file}/remove { reason? } (`file.canRemove`)
// A paper whose name is null is not yours to see: it is listed, never opened.
//   <DocumentsBox :request="detail" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { ask, toast } from '@/stores/ui';
import { blankWindow, fileProblem, fileSize, readAsDataUrl, showDataUrl } from './credit';
import { FILE } from './labels';

const props = defineProps({
  request: { type: Object, required: true },
});

const act = useAction({ invalidate: ['credit'] });
const reader = useAction({ invalidate: false, success: false });
const can = computed(() => props.request.can || {});
const mayUpload = computed(() => !!(can.value.addDocument || can.value.addOwnDocument));
const slots = computed(() => props.request.documents || []);
const base = () => `/credit/requests/${props.request.id}/documents`;

// ---- upload: one hidden input, the slot chosen by the button pressed
const input = ref(null);
const target = ref(null);
const uploading = ref(null);
function choose(slot) { target.value = slot; if (input.value) { input.value.value = ''; input.value.click(); } }
async function onFile(e) {
  const file = e.target.files?.[0];
  const slot = target.value;
  if (!file || !slot) return;
  const problem = fileProblem(file);
  if (problem) { toast.say(problem, 3200); return; }
  uploading.value = slot;
  try {
    const base64 = await readAsDataUrl(file);
    await act.run(() => api.postIdempotent(base(), { slot, fileName: file.name, base64 }), { success: t('أُضيفت الوثيقة', 'Paper added') });
  } catch {
    toast.say({ ar: 'تعذّرت قراءة الملف', en: 'The file could not be read' }, 3200);
  } finally { uploading.value = null; }
}

// ---- open / download / remove
async function open(f, download = false) {
  const win = download ? null : blankWindow();
  const res = await reader.run(() => api.get(`${base()}/${f.id}`, { as: 'data' }));
  if (!res?.dataUrl) { if (win) win.close(); return; }
  showDataUrl(res, win, download || !win);
}
async function remove(slot, f) {
  const reason = await ask({ title: { ar: `إزالة «${f.name || bi({ ar: slot.labelAr, en: slot.labelEn })}»`, en: `Remove "${f.name || slot.labelEn || slot.labelAr}"` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'إزالة', en: 'Remove' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`${base()}/${f.id}/remove`, reason ? { reason } : undefined), { success: t('أُزيلت الوثيقة — تبقى في السجل', 'Paper removed — it stays on record') });
}
const current = (s) => (s.files || []).filter((f) => f.status === 'current');
const closed = (s) => (s.files || []).filter((f) => f.status !== 'current');
const filledCount = computed(() => slots.value.filter((s) => s.filled).length);
</script>

<template>
  <div>
    <ErrorBanner :error="act.error.value || reader.error.value" @close="act.clearError(); reader.clearError()" />
    <div class="row mb-2 text-[10.5px] text-sec">
      <span class="grow">{{ t('الوثائق المرفوعة', 'Papers uploaded') }}: <b class="num">{{ filledCount }}</b> / <span class="num">{{ slots.length }}</span></span>
      <span class="text-[9px] text-faint">{{ t('PDF أو صورة، حتى 5 ميغابايت', 'PDF or image, up to 5 MB') }}</span>
    </div>
    <input ref="input" type="file" class="hidden" accept="application/pdf,image/*" @change="onFile">
    <div class="col !gap-1.5">
      <div v-for="s in slots" :key="s.slot" class="rounded-[12px] border px-3 py-2" :class="s.required && !s.filled ? 'border-[#F0DEB8] bg-[#FFFBF3]' : 'border-line-2'">
        <div class="flex flex-wrap items-center gap-2">
          <span class="min-w-0 flex-1 text-[11px] font-extrabold text-ink" dir="auto">{{ t(s.labelAr, s.labelEn || s.labelAr) }}<span v-if="s.required" class="text-bad" :title="t('مطلوبة', 'Required')"> *</span></span>
          <Chip small :label="s.filled ? { ar: 'مرفوعة', en: 'Uploaded' } : s.required ? { ar: 'مطلوبة — ناقصة', en: 'Required — missing' } : { ar: 'لا ملف', en: 'No file' }"
                :fg="s.filled ? '#1d7a3e' : s.required ? '#b26a16' : '#55506a'" :bg="s.filled ? '#e6f9ec' : s.required ? '#fbf0dd' : '#F1EFF6'" />
          <Btn v-if="mayUpload" size="sm" tone="softPurple" :loading="uploading === s.slot" :disabled="!!uploading"
               :label="s.filled && !s.many ? { ar: 'استبدال', en: 'Replace' } : { ar: '+ رفع', en: '+ Upload' }" @click="choose(s.slot)" />
        </div>
        <div v-for="f in current(s)" :key="f.id" class="row wrap mt-1.5 !gap-1.5 text-[10px]">
          <span class="min-w-0 flex-1 truncate font-bold text-sec" dir="auto" :title="f.name || ''">{{ f.name || t('ملف غير ظاهر لك', 'A file not yours to see') }}</span>
          <span class="num ltr text-muted">{{ fileSize(f.size) }}</span>
          <span class="text-muted" dir="auto">{{ f.uploadedBy || '—' }} · <span class="num ltr inline-block">{{ fmtDate(f.uploadedAt) }}</span></span>
          <span v-if="f.source === 'odoo'" class="text-[9px] font-bold text-violet">{{ t('من أودو', 'from Odoo') }}</span>
          <span v-if="!f.available" class="text-[9px] text-warn">{{ t('المحتوى غير محفوظ لدينا', 'Content not kept here') }}</span>
          <template v-if="f.canOpen && f.available">
            <Btn size="sm" tone="soft" :loading="reader.pending.value" :label="{ ar: 'فتح', en: 'Open' }" @click="open(f)" />
            <Btn size="sm" tone="ghost" :label="{ ar: 'تنزيل', en: 'Download' }" @click="open(f, true)" />
          </template>
          <Btn v-if="f.canRemove" size="sm" tone="ghost" :label="{ ar: 'إزالة', en: 'Remove' }" @click="remove(s, f)" />
        </div>
        <div v-for="f in closed(s)" :key="f.id" class="row wrap mt-1 !gap-1.5 text-[9.5px] opacity-55">
          <Chip small :map="FILE" :k="f.status" />
          <span class="min-w-0 flex-1 truncate line-through" dir="auto">{{ f.name || t('ملف غير ظاهر لك', 'A file not yours to see') }}</span>
          <span dir="auto">{{ f.closedBy || '—' }} · <span class="num ltr inline-block">{{ fmtDate(f.closedAt) }}</span><template v-if="f.closeReason"> — {{ f.closeReason }}</template></span>
          <Btn v-if="f.canOpen && f.available" size="sm" tone="ghost" :label="{ ar: 'فتح', en: 'Open' }" @click="open(f)" />
        </div>
      </div>
    </div>
    <div v-if="!slots.length" class="empty !p-4">{{ t('لا خانات وثائق لهذا الطلب.', 'No paper slots on this request.') }}</div>
  </div>
</template>
