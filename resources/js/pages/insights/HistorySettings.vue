<script setup>
// Where the brand figures come from (manager): Odoo's invoices up to the cutover day, OPS's own invoices after it.
// GET /insights/history → { cutover: { date, auto }, copy: { installed, phase, startedAt, finishedAt, invoices, lines, waiting } }
// PUT /insights/history/cutover { date | null } (null = automatic: the day before OPS's first invoice)
// POST /insights/history/sync { seconds } → advances the local copy of Odoo's invoices for at most that long.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, DateInput, ErrorBanner, KV, SectionCard } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { fmtDay } from './insights';

const q = useGet('/insights/history');
const h = computed(() => q.data.value || null);
const date = ref('');
watch(() => h.value?.cutover, (c) => { date.value = c && !c.auto ? c.date : ''; }, { immediate: true });

const act = useAction();
async function saveCutover(auto) {
  const value = auto ? null : date.value || null;
  if (!auto && !value) return;
  const ok = await confirm({
    title: auto ? { ar: 'العودة إلى يوم التحويل التلقائي؟', en: 'Go back to the automatic cutover day?' } : { ar: `يوم التحويل ${value}؟`, en: `Cutover day ${value}?` },
    sub: { ar: 'تتغير أرقام كل لوحات العلامات: فواتير أودو حتى هذا اليوم، وفواتير العمليات بعده.', en: 'Every brand dashboard changes: Odoo invoices up to this day, OPS invoices after it.' }, tone: 'dark',
  });
  if (!ok) return;
  await act.run(() => api.put('/insights/history/cutover', { date: value }), { success: t('حُفظ يوم التحويل', 'Cutover day saved'), invalidate: ['insights'] });
}
const lastSync = ref(null);
async function sync() {
  const r = await act.run(() => api.postIdempotent('/insights/history/sync', { seconds: 20 }), { success: (x) => (x.done ? t('اكتملت النسخة', 'The copy is complete') : t('تقدّمت النسخة — كرر حتى تكتمل', 'The copy moved on — repeat until it is complete')), invalidate: ['insights'] });
  if (r) lastSync.value = r;
}
const PHASE = { moves: { ar: 'نسخ الفواتير', en: 'Copying invoices' }, lines: { ar: 'نسخ البنود', en: 'Copying lines' }, feed: { ar: 'متابعة التغييرات', en: 'Following changes' } };
const phaseText = (p) => (p && PHASE[p] ? t(PHASE[p].ar, PHASE[p].en) : p || t('لم تبدأ', 'Not started'));
</script>

<template>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="!h && q.isLoading.value" class="skel h-[200px]" />
  <div v-else-if="h" class="grid-2">
    <SectionCard :title="{ ar: 'يوم التحويل', en: 'Cutover day' }" :sub="{ ar: 'تُحسب المشتريات من فواتير أودو حتى هذا اليوم (شاملًا)، ومن فواتير العمليات بعده — فلا تُعدّ فاتورة مرتين.', en: 'Purchases are counted from Odoo\'s invoices up to this day (included) and from OPS\'s invoices after it — no invoice is counted twice.' }">
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'اليوم المعمول به', en: 'Day in use' }" :v="fmtDay(h.cutover.date)" ltr />
        <KV :k="{ ar: 'كيف حُدد', en: 'Set by' }" :v="h.cutover.auto ? t('تلقائيًا: اليوم السابق لأول فاتورة في العمليات', 'Automatically: the day before OPS\'s first invoice') : t('يدويًا', 'By hand')" />
      </div>
      <div class="row wrap items-end">
        <DateInput v-model="date" small class="w-[170px]" :label="{ ar: 'يوم تحويل يدوي', en: 'Manual cutover day' }" />
        <Btn size="sm" tone="dark" :disabled="!date" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="saveCutover(false)" />
        <Btn v-if="!h.cutover.auto" size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'العودة للتلقائي', en: 'Back to automatic' }" @click="saveCutover(true)" />
      </div>
    </SectionCard>

    <SectionCard :title="{ ar: 'نسخة فواتير أودو المحلية', en: 'Local copy of Odoo\'s invoices' }" :sub="{ ar: 'تُقرأ من نسخة أودو داخل النظام ولا يُكتب في أودو شيء. الأمر المجدول يكمل النسخ؛ الزر هنا يدفعه 20 ثانية.', en: 'Read from the Odoo copy inside OPS; nothing is written to Odoo. The scheduled command does the copying; this button pushes it 20 seconds.' }">
      <div v-if="!h.copy.installed" class="hint amber !mt-0">{{ t('جداول القسم لم تُنشأ بعد على هذا الخادم.', 'The tables of the section do not exist yet on this server.') }}</div>
      <template v-else>
        <div class="kv-grid mb-3">
          <KV :k="{ ar: 'المرحلة', en: 'Phase' }" :v="phaseText(h.copy.phase)" />
          <KV :k="{ ar: 'الفواتير المنسوخة', en: 'Invoices copied' }" :v="fmtNum(h.copy.invoices)" ltr />
          <KV :k="{ ar: 'البنود المنسوخة', en: 'Lines copied' }" :v="fmtNum(h.copy.lines)" ltr />
          <KV :k="{ ar: 'تغييرات بانتظار المتابعة', en: 'Changes waiting' }" :v="fmtNum(h.copy.waiting)" ltr />
          <KV :k="{ ar: 'بدأت', en: 'Started' }" :v="fmtDate(h.copy.startedAt)" ltr />
          <KV :k="{ ar: 'اكتملت', en: 'Finished' }" :v="h.copy.finishedAt ? fmtDate(h.copy.finishedAt) : t('ليس بعد', 'Not yet')" />
        </div>
        <Btn size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'تقدّم بالنسخ الآن', en: 'Advance the copy now' }" @click="sync" />
        <div v-if="lastSync" class="mt-2 text-[10px] text-muted">
          {{ t('آخر دفعة', 'Last push') }}: {{ phaseText(lastSync.phase) }} · {{ t('فواتير', 'invoices') }} <b class="num">{{ fmtNum(lastSync.invoices) }}</b> · {{ t('بنود', 'lines') }} <b class="num">{{ fmtNum(lastSync.lines) }}</b>
          · {{ t('تغييرات', 'changes') }} <b class="num">{{ fmtNum(lastSync.changes) }}</b> · {{ lastSync.done ? t('اكتملت', 'complete') : t('لم تكتمل', 'not complete') }}
        </div>
      </template>
    </SectionCard>
  </div>
</template>
