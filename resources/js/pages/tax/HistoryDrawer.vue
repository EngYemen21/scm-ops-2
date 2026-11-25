<script setup>
// One e-invoice record of Odoo's history — GET /tax/history/{id}. Read-only. When the backup of Odoo's attachments
// holds the file Odoo sent (`fileUrl`), it is downloaded with the session's token.
import { computed } from 'vue';
import { useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV } from '@/components';
import { fmtDate, t } from '@/i18n';
import { HISTORY_STATUS, MOVE_TYPE, downloadFile, fmtDay, labelOf, sar } from './tax';

const props = defineProps({
  id: { type: String, default: null },
});
const emit = defineEmits(['close']);

const q = useGet(() => (props.id ? `/tax/history/${encodeURIComponent(props.id)}` : null));
const h = computed(() => q.data.value || null);
const dl = useAction({ invalidate: false });
// `fileUrl` is an /api path; the download helper takes the path after /api
const download = () => dl.run(() => downloadFile(String(h.value.fileUrl).replace(/^\/api(?=\/)/, ''), null, h.value.attachmentName || 'einvoice.xml'), { success: (name) => t(`نُزِّل ${name}`, `Downloaded ${name}`) });
</script>

<template>
  <Drawer :open="!!id" :title="h ? h.moveNumber || h.moveRef || h.externalRef : { ar: 'سجل أودو', en: 'Odoo record' }" :sub="h ? labelOf(MOVE_TYPE, h.moveType) : null" :width="560" @close="emit('close')">
    <ErrorBanner :error="q.error.value" :closable="false" />
    <ErrorBanner :error="dl.error.value" @close="dl.clearError()" />
    <div v-if="!h && q.isLoading.value" class="skel h-[240px]" />
    <template v-else-if="h">
      <div class="row wrap mb-3 items-center gap-2">
        <Chip :map="HISTORY_STATUS" :k="h.status" />
        <span v-if="h.state" class="num text-[10px] text-muted">Odoo: {{ h.state }}</span>
        <div class="grow" />
        <Btn v-if="h.fileUrl" size="sm" tone="soft" :loading="dl.pending.value" :label="{ ar: 'تنزيل الملف', en: 'Download file' }" @click="download" />
      </div>
      <div v-if="h.error" class="banner red"><div><b>{{ t('رسالة الخطأ', 'Error message') }}:</b> <span dir="auto">{{ h.error }}</span><template v-if="h.blockingLevel"> <code>{{ h.blockingLevel }}</code></template></div></div>
      <div class="kv-grid">
        <KV :k="{ ar: 'المستند', en: 'Document' }" :v="h.moveNumber || '—'" ltr />
        <KV :k="{ ar: 'المرجع', en: 'Reference' }" :v="h.moveRef || '—'" ltr />
        <KV :k="{ ar: 'التاريخ', en: 'Date' }" :v="fmtDay(h.invoiceDate)" ltr />
        <KV :k="{ ar: 'الطرف', en: 'Partner' }" :v="h.partnerName || '—'" />
        <KV :k="{ ar: 'مرجع الطرف', en: 'Partner ref.' }" :v="h.partnerRef || '—'" ltr />
        <KV :k="{ ar: 'الإجمالي', en: 'Total' }" :v="sar(h.amountTotal)" ltr />
        <KV :k="{ ar: 'الضريبة', en: 'VAT' }" :v="sar(h.amountTax)" ltr />
        <KV k="UUID" :v="h.uuid || '—'" ltr />
        <KV :k="{ ar: 'ترتيب السلسلة', en: 'Chain index' }" :v="h.chainIndex ?? '—'" ltr />
        <KV :k="{ ar: 'تأكيد الإرسال', en: 'Confirmed' }" :v="h.confirmedAt ? fmtDate(h.confirmedAt) : '—'" ltr />
        <KV :k="{ ar: 'الصيغة', en: 'Format' }" :v="[h.formatCode, h.formatName].filter(Boolean).join(' · ') || '—'" />
        <KV :k="{ ar: 'نوع الأرشفة', en: 'Archive kind' }" :v="h.archiveKind || '—'" ltr />
        <KV :k="{ ar: 'الملف', en: 'File' }" :v="h.attachmentName || '—'" ltr />
        <KV :k="{ ar: 'الملف في نسخة أودو', en: 'File in the Odoo backup' }" :v="h.fileUrl ? t('محفوظ', 'held') : t('غير محفوظ', 'not held')" />
        <KV :k="{ ar: 'المصدر', en: 'Source' }" :v="`${h.sourceSystem || '—'} #${h.externalRef || ''}`" ltr />
        <KV :k="{ ar: 'أُنشئ في المصدر', en: 'Created at source' }" :v="h.sourceCreatedAt ? fmtDate(h.sourceCreatedAt) : '—'" ltr />
        <KV :k="{ ar: 'آخر تعديل في المصدر', en: 'Updated at source' }" :v="h.sourceUpdatedAt ? fmtDate(h.sourceUpdatedAt) : '—'" ltr />
        <KV v-if="h.sourceDeletedAt" :k="{ ar: 'حُذف في المصدر', en: 'Deleted at source' }" :v="fmtDate(h.sourceDeletedAt)" ltr />
      </div>
    </template>
  </Drawer>
</template>
