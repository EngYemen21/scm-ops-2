<script setup>
// One e-invoice — GET /tax/einvoices/{id}: its facts, the fields of its QR code, the validation issues (errors /
// warnings with their rule codes), the authority's messages, the log of attempts, the other documents of the same
// invoice, and the XML (GET /tax/einvoices/{id}/xml, `?cleared=1` for the copy the authority stamped).
// Actions (einvoice.manage) follow the `can` object of the server:
//   POST /tax/einvoices/{id}/remake { reason } · queue · unqueue { reason } · send
// While its unit is not onboarded (`notOnboarded`) the drawer says so: nothing is sent to the authority.
import { computed } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { DOC_KIND, LEVEL, PROFILE_KIND, TONE, downloadFile, labelOf, sar, useTaxLookups } from './tax';

const props = defineProps({
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'open']);

const auth = useAuth();
const lk = useTaxLookups();
const q = useGet(() => (props.id ? `/tax/einvoices/${encodeURIComponent(props.id)}` : null));
const e = computed(() => q.data.value || null);
const can = computed(() => (auth.can('einvoice.manage') ? e.value?.can || {} : {}));
const act = useAction({ invalidate: ['tax'] });
const dl = useAction({ invalidate: false });
const path = (to) => `/tax/einvoices/${encodeURIComponent(e.value.id)}/${to}`;

async function remake() {
  const reason = await ask({ title: { ar: 'إعادة إصدار الفاتورة الإلكترونية', en: 'Re-make the e-invoice' }, sub: { ar: 'تُستبدل هذه النسخة بنسخة جديدة من الفاتورة كما هي الآن، بعداد جديد في السلسلة.', en: 'This version is replaced by a new one made from the invoice as it stands now, with a new counter in the chain.' }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (5 characters at least)' }, required: true });
  if (reason === null) return;
  const r = await act.run(() => api.postIdempotent(path('remake'), { reason }), { success: t('أُعيد الإصدار', 'Re-made') });
  if (r?.id && r.id !== e.value?.id) emit('open', r.id);
}
async function queue() { await act.run(() => api.postIdempotent(path('queue')), { success: t('وُضعت في طابور الإرسال', 'Queued for sending') }); }
async function unqueue() {
  const reason = await ask({ title: { ar: 'سحب من طابور الإرسال', en: 'Take out of the queue' }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (5 characters at least)' }, required: true, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(path('unqueue'), { reason }), { success: t('سُحبت من الطابور', 'Taken out of the queue') });
}
async function sendNow() {
  if (!(await confirm({ title: { ar: 'إرسال الآن إلى الهيئة؟', en: 'Send to the authority now?' }, tone: 'primary' }))) return;
  await act.run(() => api.postIdempotent(path('send')), { success: (r) => t(`النتيجة: ${bi(lk.einvoiceStates.value[r?.status] || { ar: r?.status, en: r?.status })}`, `Result: ${bi(lk.einvoiceStates.value[r?.status] || { ar: r?.status, en: r?.status })}`) });
}
const xml = (cleared) => dl.run(() => downloadFile(path('xml'), cleared ? { cleared: 1 } : null, `${e.value.docNumber}-icv${e.value.icv}${cleared ? '-cleared' : ''}.xml`), { success: (name) => t(`نُزِّل ${name}`, `Downloaded ${name}`) });

const issues = computed(() => e.value?.issues || []);
const messages = computed(() => e.value?.messages || []);
const MSG_TONE = { ERROR: 'red', WARNING: 'amber', INFO: 'teal' };
const OUTCOME = { accepted: { ar: 'قُبلت', en: 'Accepted', ...TONE.ok }, warnings: { ar: 'قُبلت بتحذيرات', en: 'With warnings', ...TONE.warn }, rejected: { ar: 'رُفضت', en: 'Rejected', ...TONE.bad }, retry: { ar: 'تُعاد', en: 'Retry', ...TONE.blue } };
const logCols = [
  { key: 'at', header: { ar: 'الوقت', en: 'At' }, width: '118px', kind: 'date', value: (r) => fmtDate(r.at) },
  { key: 'api', header: { ar: 'الواجهة', en: 'API' }, width: 'minmax(110px,1fr)', kind: 'id' },
  { key: 'httpStatus', header: 'HTTP', width: '56px', kind: 'num' },
  { key: 'outcome', header: { ar: 'النتيجة', en: 'Outcome' }, width: '104px', bare: true },
  { key: 'error', header: { ar: 'الخطأ', en: 'Error' }, width: 'minmax(140px,1.4fr)', value: (r) => r.error || (r.messages?.length ? r.messages.map((m) => m.message).join(' · ') : '—') },
  { key: 'by', header: { ar: 'بواسطة', en: 'By' }, width: '84px', kind: 'muted' },
];
</script>

<template>
  <Drawer :open="!!id" :title="e ? `${e.docNumber} · ICV ${e.icv}` : { ar: 'فاتورة إلكترونية', en: 'E-invoice' }" :sub="e ? labelOf(DOC_KIND, e.docKind) : null" :width="820" @close="emit('close')">
    <ErrorBanner :error="q.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value || dl.error.value" @close="act.clearError(); dl.clearError()" />
    <div v-if="!e && q.isLoading.value" class="grid gap-3"><div class="skel h-[40px]" /><div class="skel h-[200px]" /></div>
    <template v-else-if="e">
      <div v-if="e.notOnboarded" class="banner amber" role="status">
        <div>{{ t('الوحدة غير مربوطة بالهيئة (Integration Pending): أُنشئ ملف XML وبصمته في السلسلة ورمز QR الأساسي، ولم يُرسل شيء إلى الهيئة.', 'The unit is not onboarded (Integration Pending): the XML, its hash in the chain and the basic QR code were made, and nothing was sent to the authority.') }}</div>
      </div>
      <div class="row wrap mb-3 items-center gap-2">
        <Chip :map="lk.einvoiceStates.value" :k="e.status" />
        <Chip small :map="PROFILE_KIND" :k="e.profile" />
        <Chip v-if="!e.isCurrent" small :label="{ ar: 'نسخة مستبدلة', en: 'Replaced version' }" :fg="TONE.neutral.fg" :bg="TONE.neutral.bg" />
        <Chip v-if="e.signed" small :label="{ ar: 'موقّعة', en: 'Signed' }" :fg="TONE.violet.fg" :bg="TONE.violet.bg" />
        <div class="grow" />
        <Btn size="sm" tone="soft" :loading="dl.pending.value" :label="{ ar: 'تنزيل XML', en: 'Download XML' }" @click="xml(false)" />
        <Btn v-if="e.hasClearedXml" size="sm" tone="soft" :loading="dl.pending.value" :label="{ ar: 'XML المختوم من الهيئة', en: 'Cleared XML' }" @click="xml(true)" />
        <Btn v-if="can.queue" size="sm" tone="primary" :loading="act.pending.value" :label="{ ar: 'إلى طابور الإرسال', en: 'Queue' }" @click="queue" />
        <Btn v-if="can.sendNow" size="sm" tone="success" :loading="act.pending.value" :label="{ ar: 'إرسال الآن', en: 'Send now' }" @click="sendNow" />
        <Btn v-if="can.unqueue" size="sm" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'سحب من الطابور', en: 'Unqueue' }" @click="unqueue" />
        <Btn v-if="can.remake" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'إعادة الإصدار', en: 'Re-make' }" @click="remake" />
      </div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'المستند', en: 'Document' }" :v="e.docNumber" ltr />
        <KV :k="{ ar: 'المستند الأصلي', en: 'Source document' }" :v="e.source ? `${e.source.number} · ${e.source.status}` : '—'" ltr />
        <KV :k="{ ar: 'العداد (ICV)', en: 'Counter (ICV)' }" :v="e.icv" ltr />
        <KV :k="{ ar: 'النسخة', en: 'Version' }" :v="e.attemptNo" ltr />
        <KV k="UUID" :v="e.uuid" ltr />
        <KV :k="{ ar: 'الإصدار', en: 'Issued' }" :v="fmtDate(e.issueAt)" ltr />
        <KV :k="{ ar: 'رمز النوع / المعاملة', en: 'Type / transaction code' }" :v="`${e.typeCode || '—'} / ${e.transactionCode || '—'}`" ltr />
        <KV :k="{ ar: 'المشتري', en: 'Buyer' }" :v="e.buyerName || '—'" />
        <KV :k="{ ar: 'رقم المشتري الضريبي', en: 'Buyer VAT' }" :v="e.buyerVat || '—'" ltr />
        <KV :k="{ ar: 'الصافي', en: 'Net' }" :v="`${sar(e.net)} ${e.currency || ''}`" ltr />
        <KV :k="{ ar: 'الضريبة', en: 'VAT' }" :v="`${sar(e.vat)} ${e.currency || ''}`" ltr />
        <KV :k="{ ar: 'الإجمالي', en: 'Total' }" :v="`${sar(e.total)} ${e.currency || ''}`" ltr />
        <KV :k="{ ar: 'الوحدة', en: 'Unit' }" :v="e.unit ? `${e.unit.name} · ${e.unit.environment}` : '—'" />
        <KV :k="{ ar: 'طريقة الإرسال', en: 'Via' }" :v="e.via || '—'" ltr />
        <KV :k="{ ar: 'أُرسلت', en: 'Sent' }" :v="e.sentAt ? fmtDate(e.sentAt) : '—'" ltr />
        <KV :k="{ ar: 'قُبلت', en: 'Accepted' }" :v="e.acceptedAt ? fmtDate(e.acceptedAt) : '—'" ltr />
        <KV :k="{ ar: 'المحاولات', en: 'Attempts' }" :v="e.attempts" ltr />
        <KV :k="{ ar: 'المحاولة التالية', en: 'Next attempt' }" :v="e.nextAttemptAt ? fmtDate(e.nextAttemptAt) : '—'" ltr />
        <KV :k="{ ar: 'HTTP آخر رد', en: 'Last HTTP' }" :v="e.httpStatus || '—'" ltr />
        <KV :k="{ ar: 'أنشأها', en: 'Made by' }" :v="`${e.createdBy || '—'} · ${fmtDate(e.createdAt)}`" />
      </div>
      <div v-if="e.lastError" class="banner red"><div><b>{{ t('آخر خطأ', 'Last error') }}:</b> <span dir="auto">{{ e.lastError }}</span></div></div>

      <div class="card-title mb-1">{{ t('البصمة', 'Hash') }}</div>
      <div class="tile mb-3 text-[10px]">
        <div class="truncate"><span class="text-muted">{{ t('بصمة الفاتورة', 'Invoice hash') }}:</span> <span class="num ltr">{{ e.hash || '—' }}</span></div>
        <div class="truncate"><span class="text-muted">{{ t('بصمة السابقة', 'Previous hash') }}:</span> <span class="num ltr">{{ e.previousHash || '—' }}</span></div>
      </div>

      <div class="card-title mb-1">{{ t('حقول رمز QR', 'QR code fields') }}</div>
      <div v-if="e.qrFields?.length" class="mb-3 grid gap-1">
        <div v-for="f in e.qrFields" :key="f.tag" class="flex items-center gap-2 border-b border-line py-1 text-[10px] last:border-0">
          <span class="num w-6 text-muted">{{ f.tag }}</span><span class="w-40 shrink-0 font-bold">{{ bi(f) }}</span>
          <span v-if="f.value != null" class="num ltr min-w-0 flex-1 truncate" :title="f.value">{{ f.value }}</span>
          <span v-else class="flex-1 text-muted">{{ t('موجود', 'present') }} · <span class="num">{{ f.bytes }}</span> {{ t('بايت', 'bytes') }}</span>
        </div>
      </div>
      <div v-else class="mb-3 text-[10px] text-muted">{{ t('لا رمز QR مقروء', 'No readable QR code') }}</div>

      <div class="card-title mb-1">{{ t('ملاحظات التحقق', 'Validation issues') }} <span class="num text-muted">({{ issues.length }})</span></div>
      <div v-if="!issues.length" class="mb-3 text-[10px] text-ok">{{ t('لا ملاحظات — اجتازت قواعد التحقق', 'No issue — the validation rules passed') }}</div>
      <div v-for="(i, n) in issues" :key="`i${n}`" class="banner" :class="(LEVEL[i.level] || LEVEL.info).cls">
        <div class="flex-1"><b>{{ bi(LEVEL[i.level] || LEVEL.info) }}:</b> {{ bi(i) }} <code v-if="i.code">{{ i.code }}</code></div>
      </div>

      <div class="card-title mb-1 mt-3">{{ t('رسائل الهيئة', 'Authority messages') }} <span class="num text-muted">({{ messages.length }})</span></div>
      <div v-if="!messages.length" class="mb-3 text-[10px] text-muted">{{ e.notOnboarded ? t('لا رسائل — لم يُرسل شيء إلى الهيئة', 'No message — nothing was sent to the authority') : t('لا رسائل', 'No message') }}</div>
      <div v-for="(m, n) in messages" :key="`m${n}`" class="banner" :class="MSG_TONE[m.type] || 'neutral'">
        <div class="flex-1"><b class="num">{{ m.type }}</b> <code v-if="m.code">{{ m.code }}</code> <span v-if="m.category" class="num">{{ m.category }}</span> — <span dir="ltr">{{ m.message }}</span></div>
      </div>

      <div class="card-title mb-1 mt-3">{{ t('سجل المحاولات', 'Attempts log') }}</div>
      <DataTable :columns="logCols" :rows="e.log || []" :row-key="(r) => r.id" :page-size="10" :empty-text="{ ar: 'لا محاولات', en: 'No attempt' }">
        <template #cell-outcome="{ row }"><Chip small :map="OUTCOME" :k="row.outcome" /></template>
      </DataTable>

      <template v-if="e.otherDocuments?.length">
        <div class="card-title mb-1 mt-3">{{ t('نسخ أخرى لنفس الفاتورة', 'Other versions of the same invoice') }}</div>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="o in e.otherDocuments" :key="o.id" type="button" class="pill" @click="emit('open', o.id)">
            ICV <span class="num">{{ o.icv }}</span> · {{ t('نسخة', 'v') }}<span class="num">{{ o.attemptNo }}</span>
            <Chip small :map="lk.einvoiceStates.value" :k="o.status" />
            <template v-if="o.isCurrent"> · {{ t('السارية', 'current') }}</template>
          </button>
        </div>
      </template>
    </template>
  </Drawer>
</template>
