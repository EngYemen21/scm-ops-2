<script setup>
// The tax code of one OPS document — GET /tax/documents/{docType}/{docId}: the code it carries today and how it got
// it (a person's decision, the partner, the fiscal position, the default), and the returns it was declared in.
// A person with vatreturn.manage gives it another code, or clears the decision (codeId null) —
// POST /tax/documents/{docType}/{docId}/code { codeId, reason }. A draft return picks it up when it is recomputed.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, SelectInput, TextArea } from '@/components';
import { fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { DOC_TYPE, HOW, USE, boxLabel, codeLabel, labelOf, pct, useTaxLookups } from './tax';

const props = defineProps({
  docType: { type: String, default: null },
  docId: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const lk = useTaxLookups();
const q = useGet(() => (props.docType && props.docId ? `/tax/documents/${encodeURIComponent(props.docType)}/${encodeURIComponent(props.docId)}` : null));
const d = computed(() => q.data.value || null);
const act = useAction({ invalidate: ['tax'] });
const codeId = ref('');
const reason = ref('');
watch(() => [props.docType, props.docId], () => { codeId.value = ''; reason.value = ''; act.clearError(); });
watch(d, (v) => { if (v) codeId.value = v.decision?.codeId || ''; });
const canManage = computed(() => auth.can('vatreturn.manage'));
const options = computed(() => (d.value ? lk.codeOptions(d.value.use) : []));
const decidedCode = computed(() => (d.value?.decision?.codeId ? lk.codes.value.find((c) => c.id === d.value.decision.codeId) : null));

async function save(clear = false) {
  const body = { codeId: clear ? null : codeId.value || null, reason: reason.value.trim() || null };
  const r = await act.run(() => api.postIdempotent(`/tax/documents/${encodeURIComponent(props.docType)}/${encodeURIComponent(props.docId)}/code`, body),
    { success: clear ? t('أُزيل قرار الرمز', 'Decision cleared') : t('حُفظ رمز المستند', 'Document code saved') });
  if (r) { reason.value = ''; q.refetch(); }
}
</script>

<template>
  <Drawer :open="!!docType && !!docId" :title="d ? `${labelOf(DOC_TYPE, d.docType)} ${d.number}` : { ar: 'الرمز الضريبي للمستند', en: 'Tax code of the document' }" :sub="{ ar: 'الرمز الضريبي للمستند', en: 'Tax code of the document' }"
          :width="520" :z-index="90" @close="emit('close')">
    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="!d && q.isLoading.value" class="skel h-[160px]" />
    <template v-else-if="d">
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'المستند', en: 'Document' }" :v="d.number" ltr />
        <KV :k="{ ar: 'الاستخدام', en: 'Use' }" :v="labelOf(USE, d.use)" />
        <KV :k="{ ar: 'النسبة', en: 'Rate' }" :v="pct(d.rate)" ltr />
        <KV :k="{ ar: 'الرمز الحالي', en: 'Current code' }" :v="d.code ? codeLabel(d.code) : t('بلا رمز', 'No code')" />
        <KV :k="{ ar: 'بند الإقرار', en: 'Return line' }" :v="d.code ? boxLabel(lk.boxMap.value, d.code.returnBox) : '—'" />
      </div>
      <div class="mb-3 flex items-center gap-2 text-[10.5px]">{{ t('مصدر الرمز', 'Where the code comes from') }}: <Chip small :map="HOW" :k="d.how" /></div>
      <div v-if="d.decision" class="hint purple !mt-0 mb-3">
        {{ t('قرار مستخدم', 'A person\'s decision') }}: <b>{{ decidedCode ? codeLabel(decidedCode) : (d.decision.codeId || t('بلا رمز', 'no code')) }}</b>
        — {{ d.decision.setBy }} · <span class="num">{{ fmtDate(d.decision.setAt) }}</span>
        <div v-if="d.decision.reason" dir="auto">{{ d.decision.reason }}</div>
      </div>
      <div v-if="d.returns?.length" class="mb-3">
        <div class="card-title mb-1">{{ t('ورد في الإقرارات', 'Declared in returns') }}</div>
        <div class="flex flex-wrap gap-1.5">
          <span v-for="r in d.returns" :key="r.id" class="pill !cursor-default"><span class="num">{{ r.periodCode }}</span><Chip small :map="lk.returnStates.value" :k="r.status" /></span>
        </div>
        <div class="mt-1 text-[9.5px] text-muted">{{ t('الإقرار المراجَع أو المقدَّم يحتفظ بما أُعلن؛ التغيير يدخل المسودات عند إعادة احتسابها.', 'A reviewed or filed return keeps what was declared; a change enters drafts when they are recomputed.') }}</div>
      </div>

      <template v-if="canManage">
        <div class="card-title mb-1">{{ t('تحديد رمز لهذا المستند', 'Give this document a code') }}</div>
        <ErrorBanner :error="act.error.value" @close="act.clearError()" />
        <div class="form-grid">
          <SelectInput v-model="codeId" :label="{ ar: 'الرمز الضريبي', en: 'Tax code' }" :options="options" :placeholder="{ ar: '— اختر رمزًا —', en: '— pick a code —' }" full />
          <TextArea v-model="reason" :label="{ ar: 'السبب', en: 'Reason' }" :rows="2" />
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
          <Btn tone="primary" :loading="act.pending.value" :disabled="!codeId" :label="{ ar: 'حفظ الرمز', en: 'Save code' }" @click="save(false)" />
          <Btn v-if="d.decision" tone="softRed" :loading="act.pending.value" :label="{ ar: 'إزالة القرار', en: 'Clear decision' }" @click="save(true)" />
        </div>
      </template>
    </template>
  </Drawer>
</template>
