<script setup>
// The papers of an onboarding application: commercial registration, VAT certificate, national address, the owner's
// identity, a bank letter, the chamber certificate. Listed with where they came from; opened through the API (a link
// cannot carry the session), added as a PDF or an image up to 5 MB, taken off with a reason — never deleted.
//   <ApplicationPapers :application="detail" @changed="…" />
// `documents[]`: { id, kind, fileName, mime, size, origin: ops | odoo, available, unavailable: removed | private |
// not_archived | null, uploadedBy, createdAt, removedAt, removedBy, removeReason }.
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, SelectInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import { DOCUMENT_KINDS, DOCUMENT_KIND_OPTIONS, DOCUMENT_UNAVAILABLE, OWNED_CHIP, fetchBlob, fileSize, isViewable, labelOf, moment, readFile, saveBlob, showBlob } from './portal';

const props = defineProps({
  /** The application as GET /portal/applications/{id} gives it. */
  application: { type: Object, required: true },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const canManage = computed(() => auth.can('portal.manage'));
const act = useAction({ invalidate: ['portal'] });
const base = computed(() => `/portal/applications/${encodeURIComponent(props.application.id)}/documents`);
const kept = computed(() => props.application.documents.filter((d) => !d.removedAt));
const removed = computed(() => props.application.documents.filter((d) => d.removedAt));

const fetching = ref(null);
async function open(d) {
  fetching.value = d.id;
  const blob = await act.run(() => fetchBlob(`${base.value}/${encodeURIComponent(d.id)}`), { success: false, invalidate: false });
  fetching.value = null;
  if (!blob) return;
  if (!isViewable(d.mime) || !showBlob(blob)) saveBlob(blob, d.fileName);
}

const kind = ref('cr');
const picker = ref(null);
const sending = ref(null);
const pick = () => picker.value?.click();
async function onPick(e) {
  const files = [...(e.target.files || [])];
  e.target.value = '';
  for (const file of files) {
    sending.value = file.name;
    const r = await act.run(async () => { const f = await readFile(file); return api.postIdempotent(base.value, { kind: kind.value, fileName: f.fileName, mime: f.mime, base64: f.base64 }); },
      { success: t(`أُضيف المستند «${file.name}»`, `Document “${file.name}” added`) });
    if (!r) break;
    emit('changed');
  }
  sending.value = null;
}
async function remove(d) {
  const reason = await ask({ title: { ar: `إزالة «${d.fileName}» من الطلب؟`, en: `Take “${d.fileName}” off the application?` }, sub: { ar: 'يبقى الملف محفوظًا في السجل، ويُكتب من أزاله ولماذا.', en: 'The file stays on record, with who removed it and why.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إزالة', en: 'Remove' }, tone: 'danger' });
  if (reason === null) return;
  const r = await act.run(() => api.postIdempotent(`${base.value}/${encodeURIComponent(d.id)}/remove`, reason ? { reason } : {}), { success: t('أُزيل المستند', 'Document removed') });
  if (r) emit('changed');
}
/** A paper Odoo holds is taken off in Odoo while the application is Odoo's. */
const removable = (d) => canManage.value && !(d.origin === 'odoo' && props.application.owned);
</script>

<template>
  <div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!kept.length" class="empty !p-3.5">{{ t('لا مستندات على هذا الطلب.', 'No papers on this application.') }}</div>
    <div v-for="d in kept" :key="d.id" class="row mb-1 rounded-[11px] border border-line-2 px-3 py-2">
      <div class="min-w-0 flex-1">
        <div class="text-[10px] font-extrabold text-violet">{{ labelOf(DOCUMENT_KINDS, d.kind) }}</div>
        <div class="truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto" :title="d.fileName">{{ d.fileName }}</div>
        <div class="text-[9.5px] text-muted"><span v-if="d.size" class="num ltr">{{ fileSize(d.size) }} · </span><template v-if="d.uploadedBy">{{ d.uploadedBy }} · </template><span class="num ltr">{{ moment(d.createdAt) }}</span></div>
        <div v-if="!d.available && d.unavailable" class="text-[9.5px] font-bold text-warn">{{ labelOf(DOCUMENT_UNAVAILABLE, d.unavailable) }}</div>
      </div>
      <Chip v-if="d.origin === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      <Btn tone="soft" size="sm" :loading="fetching === d.id" :disabled="!d.available" :label="isViewable(d.mime) ? { ar: 'عرض', en: 'View' } : { ar: 'تنزيل', en: 'Download' }" @click="open(d)" />
      <Btn v-if="removable(d)" tone="ghost" size="sm" class="!text-bad" :label="{ ar: 'إزالة', en: 'Remove' }" @click="remove(d)" />
    </div>

    <div v-if="canManage && application.can.addDocument" class="row wrap mt-2 !items-end">
      <SelectInput v-model="kind" small field-class="w-[190px]" :options="DOCUMENT_KIND_OPTIONS" :label="{ ar: 'نوع المستند', en: 'Kind of paper' }" />
      <input ref="picker" type="file" accept="application/pdf,image/png,image/jpeg,image/webp" multiple class="hidden" @change="onPick">
      <Btn tone="softPurple" size="sm" :loading="!!sending" :label="{ ar: '+ إرفاق مستند', en: '+ Attach a paper' }" @click="pick" />
      <span v-if="sending" class="truncate text-[9.5px] font-bold text-muted" dir="auto">{{ t('جارٍ رفع', 'Uploading') }} {{ sending }}…</span>
      <span v-else class="text-[9.5px] text-faint">{{ t('PDF أو صورة، حتى 5 ميجابايت', 'A PDF or an image, up to 5 MB') }}</span>
    </div>

    <div v-if="removed.length" class="mt-2.5">
      <div class="text-[9.5px] font-extrabold text-faint">{{ t('مستندات أُزيلت (باقية في السجل)', 'Removed papers (kept on record)') }}</div>
      <div v-for="d in removed" :key="d.id" class="mt-1 text-[9.5px] text-muted">
        {{ labelOf(DOCUMENT_KINDS, d.kind) }} · <span class="line-through" dir="auto">{{ d.fileName }}</span> — {{ d.removedBy || '—' }} · <span class="num ltr">{{ moment(d.removedAt) }}</span><template v-if="d.removeReason"> · {{ d.removeReason }}</template>
      </div>
    </div>
  </div>
</template>
