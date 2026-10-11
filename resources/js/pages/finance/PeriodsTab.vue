<script setup>
// Fiscal periods: one row per month with journal counts; close in order, reopen (newest first) with a reason.
import { computed } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner } from '@/components';
import { fmtDate, fmtMoney, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { PERIOD_LABELS } from './shared';

const auth = useAuth();
const q = useGet('/finance/periods');
const act = useAction({ invalidate: ['finance'] });
const items = computed(() => q.data.value?.items || []);
async function close(p) {
  const yes = await confirm({ title: { ar: `إقفال الفترة ${p.code}؟`, en: `Close ${p.code}?` }, sub: { ar: 'لن يُقبل أي ترحيل في هذه الفترة — آلي أو يدوي — حتى تُعاد فتحها بسبب موثّق', en: 'No posting will be accepted in it — automatic or manual — until it is reopened with a reason' }, tone: 'dark', okLabel: { ar: 'إقفال', en: 'Close' } });
  if (!yes) return;
  const r = await act.run(() => api.postIdempotent(`/finance/periods/${p.code}/close`, {}), { success: t(`أُقفلت ${p.code}`, `${p.code} closed`) });
  if (r) q.refetch();
}
async function reopen(p) {
  const reason = await ask({ title: { ar: `إعادة فتح ${p.code}`, en: `Reopen ${p.code}` }, label: { ar: 'السبب (يُسجَّل في Audit Trail)', en: 'Reason (audited)' }, required: true, okLabel: { ar: 'إعادة فتح', en: 'Reopen' } });
  if (!reason) return;
  const r = await act.run(() => api.postIdempotent(`/finance/periods/${p.code}/reopen`, { reason }), { success: t(`أُعيد فتح ${p.code}`, `${p.code} reopened`) });
  if (r) q.refetch();
}
const columns = [
  { key: 'code', header: { ar: 'الفترة', en: 'Period' }, width: '90px', kind: 'id' },
  { key: 'journals', header: { ar: 'قيود مرحَّلة', en: 'Posted journals' }, width: '110px', kind: 'num', value: (p) => fmtNum(p.journals) },
  { key: 'debit', header: { ar: 'حجم الحركة', en: 'Movement' }, width: '120px', kind: 'num', value: (p) => fmtMoney(p.debit) },
  { key: 'drafts', header: { ar: 'مسودات', en: 'Drafts' }, width: '80px', kind: 'num', value: (p) => fmtNum(p.drafts) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '100px' },
  { key: 'by', header: { ar: 'أُقفلت / أُعيد فتحها', en: 'Closed / reopened' }, width: 'minmax(200px,1.5fr)', class: 'text-[9.5px] text-muted', value: (p) => [p.closedBy ? `${p.closedBy} · ${fmtDate(p.closedAt)}` : null, p.reopenedBy ? `↺ ${p.reopenedBy} · ${fmtDate(p.reopenedAt)}` : null].filter(Boolean).join('   ') || '—' },
  { key: 'act', header: '', width: '120px' },
];
</script>

<template>
  <ErrorBanner :error="q.error.value || act.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :rows="items" :loading="q.isLoading.value" :row-key="(p) => p.code" :min-width="760" :empty-text="{ ar: 'لا فترات بعد — تُنشأ الفترة مفتوحة مع أول قيد فيها.', en: 'No periods yet — a period is created open with its first journal.' }">
      <template #cell-status="{ row }"><Chip :map="PERIOD_LABELS" :k="row.status" /></template>
      <template #cell-act="{ row }">
        <template v-if="auth.can('gl.close')">
          <Btn v-if="row.status === 'open'" size="sm" tone="dark" class="!h-7 !text-[9px]" :loading="act.pending.value" :label="{ ar: 'إقفال', en: 'Close' }" @click="close(row)" />
          <Btn v-else size="sm" tone="soft" class="!h-7 !text-[9px]" :label="{ ar: 'إعادة فتح', en: 'Reopen' }" @click="reopen(row)" />
        </template>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الإقفال بالترتيب الزمني ولا يُقبل مع وجود مسودات. حدث تشغيلي يحاول الترحيل في فترة مغلقة يفشل برسالة واضحة (PERIOD_CLOSED) بدل أن يُرحَّل في شهر خاطئ. إعادة الفتح من الأحدث إلى الأقدم وبسبب مكتوب.', 'Close in chronological order; drafts block closing. An operational event that would post into a closed period fails loudly (PERIOD_CLOSED) instead of landing in the wrong month. Reopen newest first, with a written reason.') }}</div>
</template>
