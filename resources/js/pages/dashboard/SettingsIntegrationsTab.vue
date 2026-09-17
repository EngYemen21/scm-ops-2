<script setup>
// Settings → integrations + outbox. GET /integrations/status (every minute) shows each adapter with exactly the status
// the API reports (`integration_pending` → "Integration Pending"; never "connected" unless the API says so).
// GET /integrations/outbox (paged, every 30 s); with `settings.manage`: run the outbox and retry failed events.
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput } from '@/components';
import { fmtDate, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CardTitle from './CardTitle.vue';
import IntegrationChip from './IntegrationChip.vue';
import { OUTBOX_LABELS, OUTBOX_OPTIONS } from './settings';

const auth = useAuth();
const manage = auth.can('settings.manage');

const status = useGet('/integrations/status', undefined, { refetchInterval: 60_000 });
const obStatus = ref('');
const page = ref(1);
const outbox = useList('/integrations/outbox', () => ({ status: obStatus.value || undefined, page: page.value, pageSize: 20 }), { refetchInterval: 30_000 });

const act = useAction();
/** Result: { target: string | null, processed, sent, failed, pending } — the toast repeats the server's counts. */
function runOutbox() {
  act.run(() => api.postIdempotent('/integrations/outbox/run', { limit: 50 }), {
    success: (r) => (r?.target
      ? t(`تم تشغيل الـ Outbox عبر ${r.target}: أُرسل ${r.sent} · فشل ${r.failed} · متبقٍ ${r.pending}`, `Outbox run via ${r.target}: sent ${r.sent} · failed ${r.failed} · pending ${r.pending}`)
      : t(`لا تكامل متصل — بقيت ${r?.pending ?? 0} أحداث بحالة Integration Pending`, `No integration connected — ${r?.pending ?? 0} events remain Integration Pending`)),
    invalidate: ['integrations'],
  });
}
function retry(r) {
  act.run(() => api.postIdempotent(`/integrations/outbox/${r.id}/retry`), { success: { ar: 'أُعيد للطابور', en: 'Queued for retry' }, invalidate: ['integrations'] });
}
const shortErr = (s) => (s ? (s.length > 70 ? `${s.slice(0, 70)}…` : s) : '—');

const cols = computed(() => [
  { key: 'type', header: { ar: 'الحدث', en: 'Event' }, width: 'minmax(170px,1.2fr)' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '120px' },
  { key: 'attempts', header: { ar: 'محاولات', en: 'Attempts' }, width: '70px', kind: 'num' },
  { key: 'lastError', header: { ar: 'آخر خطأ', en: 'Last error' }, width: 'minmax(140px,1fr)' },
  { key: 'createdAt', header: { ar: 'أُنشئ', en: 'Created' }, width: '130px', kind: 'date', value: (r) => fmtDate(r.createdAt) },
  { key: 'sentAt', header: { ar: 'أُرسل', en: 'Sent' }, width: '130px', kind: 'date', value: (r) => fmtDate(r.sentAt) },
  { key: 'act', header: '', width: '80px', hidden: !manage },
]);
</script>

<template>
  <div class="col !gap-3.5">
    <div class="card">
      <CardTitle>{{ t('حالة التكاملات', 'Integration status') }}</CardTitle>
      <div v-if="status.error.value" class="mx-[18px]"><ErrorBanner :error="status.error.value" :closable="false" /></div>
      <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-2.5 px-[18px] pb-4">
        <div v-for="i in status.data.value || []" :key="i.key" class="tile flex flex-col gap-1.5">
          <div class="row justify-between"><span class="text-[11px] font-extrabold">{{ lang === 'ar' ? i.nameAr : i.nameEn }}</span><IntegrationChip :status="i.status" small /></div>
          <div class="num-mixed text-[9.5px] leading-[1.6] text-muted">{{ lang === 'ar' ? i.detailAr : i.detail }}</div>
        </div>
        <div v-if="status.isLoading.value && !status.data.value" class="skel h-10" />
      </div>
    </div>

    <div class="card">
      <CardTitle>
        {{ t('Outbox — أحداث B2B / ERP', 'Outbox — B2B / ERP events') }} <span class="num font-normal text-faint">({{ fmtNum(outbox.data.value?.total || 0) }})</span>
        <template #right>
          <span class="row">
            <SelectInput small class="w-[150px]" :model-value="obStatus" :options="OUTBOX_OPTIONS" :placeholder="{ ar: 'كل الحالات', en: 'All statuses' }" @update:model-value="(v) => { obStatus = v; page = 1; }" />
            <Btn v-if="manage" tone="primary" size="sm" :loading="act.pending.value" :label="{ ar: '▶ تشغيل Outbox', en: '▶ Run outbox' }" @click="runOutbox" />
          </span>
        </template>
      </CardTitle>
      <div v-if="outbox.error.value || act.error.value" class="mx-[18px]"><ErrorBanner :error="outbox.error.value || act.error.value" @close="act.clearError()" /></div>
      <DataTable :columns="cols" :paged="outbox.data.value" :loading="outbox.isLoading.value" :row-key="(r) => r.id" :min-width="820" dense :empty-text="{ ar: 'لا أحداث في الـ Outbox.', en: 'No outbox events.' }" @page="page = $event">
        <template #cell-type="{ row }"><span class="num text-brand-dark" dir="ltr">{{ row.type }}</span></template>
        <template #cell-status="{ row }"><Chip :map="OUTBOX_LABELS" :k="row.status" small /></template>
        <template #cell-lastError="{ row }"><span class="num-mixed text-[10px] text-bad" :title="row.lastError || null">{{ shortErr(row.lastError) }}</span></template>
        <template #cell-act="{ row }"><Btn v-if="row.status === 'failed'" tone="softAmber" size="sm" :label="{ ar: 'إعادة', en: 'Retry' }" @click.stop="retry(row)" /></template>
      </DataTable>
      <div class="hint mx-[18px] mt-0 mb-4">{{ t('الأحداث تُرسل فقط عند اتصال منصة B2B أو ERP (متغير بيئة مُهيّأ). بدون اتصال تبقى الأحداث «بانتظار الإرسال» — Integration Pending — ولا تُعرض كمُرسلة أبدًا.', 'Events are delivered only when the B2B or ERP platform is connected (env var configured). Without a connection they stay Pending — Integration Pending — and are never shown as sent.') }}</div>
    </div>
  </div>
</template>
