<script setup>
// One VAT return — GET /tax/returns/{id}: the form (lines 1–16), the checks between it and its next step, the totals,
// the reconciliation with the ledger, the adjustment lines and the way from draft to locked:
//   POST /tax/returns/{id}/refresh · review { note } · reopen { reason } · cancel { reason }      (vatreturn.manage)
//   POST /tax/returns/{id}/file { reference, filedOn } · unfile { reason } · lock                  (vatreturn.file)
// Buttons follow the `can` object of the server (which already says who may do what in this state).
// A figure opens the documents behind it: `emit('box', { box, column })` → `box=` in the query string.
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Tabs } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import AdjustmentForm from './AdjustmentForm.vue';
import ExplainForm from './ExplainForm.vue';
import FileReturnForm from './FileReturnForm.vue';
import ReturnAdjustments from './ReturnAdjustments.vue';
import ReturnEditForm from './ReturnEditForm.vue';
import ReturnLines from './ReturnLines.vue';
import ReturnReconciliation from './ReturnReconciliation.vue';
import { FREQUENCY, LEVEL, fmtDay, labelOf, money, sar, signClass, useTaxLookups } from './tax';

const props = defineProps({
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'box']);

const lk = useTaxLookups();
const q = useGet(() => (props.id ? `/tax/returns/${encodeURIComponent(props.id)}` : null));
const r = computed(() => q.data.value || null);
const can = computed(() => r.value?.can || {});
const checks = computed(() => r.value?.checks || []);
const act = useAction({ invalidate: ['tax'] });
const section = ref('form');
const sections = computed(() => [
  { k: 'form', label: { ar: 'نموذج الإقرار', en: 'Return form' } },
  { k: 'recon', label: { ar: 'المطابقة مع الدفاتر', en: 'Ledger reconciliation' }, badge: r.value?.reconciliation?.items?.filter((i) => !i.explained).length || null },
  { k: 'adjust', label: { ar: 'التسويات', en: 'Adjustments' }, badge: (r.value?.adjustments || []).filter((a) => !a.voided).length || null },
  { k: 'info', label: { ar: 'السجل', en: 'Record' } },
]);

// ---- forms opened from the drawer
const editing = ref(false);
const filing = ref(false);
const adjusting = ref(false);
const takeItem = ref(null);
const explainItem = ref(null);
function addAdjustment(item = null) { takeItem.value = item; adjusting.value = true; }
function closeAdjustment() { adjusting.value = false; takeItem.value = null; }

const path = (to) => `/tax/returns/${encodeURIComponent(r.value.id)}/${to}`;
async function refresh() { await act.run(() => api.postIdempotent(path('refresh')), { success: t('أُعيد احتساب الإقرار من الدفاتر', 'Return recomputed from the books') }); }
async function review() {
  const note = await ask({ title: { ar: `مراجعة إقرار ${r.value.periodCode}`, en: `Review return ${r.value.periodCode}` }, sub: { ar: 'تُجمَّد أرقام الإقرار على ما هي عليه الآن.', en: 'The figures of the return are frozen as they are now.' }, label: { ar: 'ملاحظة (اختيارية)', en: 'Note (optional)' }, okLabel: { ar: 'مراجعة', en: 'Review' }, tone: 'primary' });
  if (note === null) return;
  await act.run(() => api.postIdempotent(path('review'), note ? { note } : {}), { success: t('تمت مراجعة الإقرار', 'Return reviewed') });
}
async function withReason(to, title, success, tone = 'dark') {
  const reason = await ask({ title, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (5 characters at least)' }, required: true, tone });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(path(to), { reason }), { success });
}
const reopen = () => withReason('reopen', { ar: 'إعادة الإقرار إلى مسودة', en: 'Reopen the return as a draft' }, t('أُعيد الإقرار إلى مسودة', 'Return reopened'));
const unfile = () => withReason('unfile', { ar: 'التراجع عن تسجيل التقديم', en: 'Take the filing back' }, t('أُلغي تسجيل التقديم', 'Filing taken back'), 'danger');
const cancel = () => withReason('cancel', { ar: `إلغاء إقرار ${r.value.periodCode}`, en: `Cancel return ${r.value.periodCode}` }, t('أُلغي الإقرار', 'Return cancelled'), 'danger');
async function lock() {
  if (!(await confirm({ title: { ar: `إقفال إقرار ${r.value.periodCode}؟`, en: `Lock return ${r.value.periodCode}?` }, sub: { ar: 'الإقرار المقفل لا يُعدَّل ولا يُتراجع عن تقديمه.', en: 'A locked return is never changed nor taken back.' }, tone: 'danger' }))) return;
  await act.run(() => api.postIdempotent(path('lock')), { success: t('أُقفل الإقرار', 'Return locked') });
}
const openBox = (e) => emit('box', e);
const anyAction = computed(() => Object.values(can.value).some(Boolean));
</script>

<template>
  <Drawer :open="!!id" :title="r ? { ar: `إقرار ${r.periodCode}`, en: `Return ${r.periodCode}` } : { ar: 'إقرار ضريبة القيمة المضافة', en: 'VAT return' }"
          :sub="r ? `${fmtDay(r.periodFrom)} – ${fmtDay(r.periodTo)} · ${labelOf(FREQUENCY, r.frequency)}` : null" :width="900" @close="emit('close')">
    <ErrorBanner :error="q.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!r && q.isLoading.value" class="grid gap-3"><div class="skel h-[40px]" /><div class="skel h-[260px]" /><div class="skel h-[120px]" /></div>
    <template v-else-if="r">
      <div class="row wrap mb-3 items-center gap-2">
        <Chip :map="lk.returnStates.value" :k="r.status" />
        <span class="text-[10.5px] text-sec">{{ t('صافي المستحق', 'Net due') }}: <b class="num text-[13px]" :class="signClass(r.netDue)">{{ money(r.netDue) }}</b></span>
        <div class="grow" />
        <template v-if="anyAction">
          <Btn v-if="can.edit" size="sm" tone="soft" :loading="act.pending.value" :label="{ ar: 'إعادة الاحتساب', en: 'Recompute' }" @click="refresh" />
          <Btn v-if="can.edit" size="sm" tone="soft" :label="{ ar: 'الرصيد والملاحظات', en: 'Credit & notes' }" @click="editing = true" />
          <Btn v-if="can.review" size="sm" tone="primary" :loading="act.pending.value" :label="{ ar: 'مراجعة', en: 'Review' }" @click="review" />
          <Btn v-if="can.reopen" size="sm" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'إعادة إلى مسودة', en: 'Reopen' }" @click="reopen" />
          <Btn v-if="can.file" size="sm" tone="success" :label="{ ar: 'تسجيل التقديم', en: 'Record filing' }" @click="filing = true" />
          <Btn v-if="can.unfile" size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'التراجع عن التقديم', en: 'Unfile' }" @click="unfile" />
          <Btn v-if="can.lock" size="sm" tone="dark" :loading="act.pending.value" :label="{ ar: 'إقفال', en: 'Lock' }" @click="lock" />
          <Btn v-if="can.cancel" size="sm" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء الإقرار', en: 'Cancel return' }" @click="cancel" />
        </template>
      </div>

      <!-- what stands between the return and its next step -->
      <div v-for="c in checks" :key="c.key" class="banner" :class="(LEVEL[c.level] || LEVEL.info).cls">
        <div class="flex-1"><b>{{ bi(LEVEL[c.level] || LEVEL.info) }}:</b> {{ bi(c) }} <code v-if="c.code">{{ c.code }}</code></div>
      </div>
      <div v-if="r.drift?.changed" class="hint amber !mt-0 mb-2">
        {{ t('تغيّرت الدفاتر بعد المراجعة', 'The books changed after the review') }}: <span class="num">{{ r.drift.entries }}</span> {{ t('مستند', 'documents') }} · <span class="num">{{ r.drift.ledgerLines }}</span> {{ t('حركة على حسابات الضريبة', 'VAT-account movements') }}
      </div>
      <div v-if="r.selfFiling" class="hint purple !mt-0 mb-2">{{ t('راجعتَ هذا الإقرار بنفسك — يقدّمه شخص آخر بصلاحية التقديم (فصل المسؤوليات).', 'You reviewed this return yourself — somebody else with the filing permission files it (segregation of duties).') }}</div>
      <div v-if="r.status === 'reviewed' && !can.file && !r.selfFiling && !r.drift?.changed" class="hint !mt-0 mb-2">{{ t('الإقرار جاهز للتقديم — يسجّله من يملك صلاحية تقديم الإقرارات.', 'The return is ready to be filed — recorded by someone with the filing permission.') }}</div>

      <div class="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
        <div class="tile"><div class="text-[9.5px] text-muted">{{ t('ضريبة المخرجات', 'Output VAT') }}</div><b class="num">{{ sar(r.totals?.outputVat) }}</b></div>
        <div class="tile"><div class="text-[9.5px] text-muted">{{ t('ضريبة المدخلات', 'Input VAT') }}</div><b class="num">{{ sar(r.totals?.inputVat) }}</b></div>
        <div class="tile"><div class="text-[9.5px] text-muted">{{ t('منها احتساب عكسي', 'of which reverse charge') }}</div><b class="num">{{ sar(r.totals?.reverseChargeVat) }}</b></div>
        <div class="tile"><div class="text-[9.5px] text-muted">{{ t('المستحق عن الفترة', 'Due for the period') }}</div><b class="num" :class="signClass(r.totals?.vatDuePeriod)">{{ sar(r.totals?.vatDuePeriod) }}</b></div>
        <div class="tile" :class="r.corrections?.overLimit ? 'amber' : ''"><div class="text-[9.5px] text-muted">{{ t('التصحيحات', 'Corrections') }}</div><b class="num" :class="signClass(r.totals?.corrections)">{{ sar(r.totals?.corrections) }}</b></div>
        <div class="tile"><div class="text-[9.5px] text-muted">{{ t('الرصيد المرحَّل', 'Credit carried') }}</div><b class="num">{{ sar(r.totals?.creditCarried) }}</b></div>
        <div class="tile purple col-span-2"><div class="text-[9.5px] text-muted">{{ t('صافي المستحق', 'Net due') }}</div><b class="num text-[15px]" :class="signClass(r.totals?.netDue)">{{ money(r.totals?.netDue) }}</b></div>
      </div>

      <Tabs v-model="section" :tabs="sections" variant="sm" />
      <ReturnLines v-if="section === 'form'" :ret="r" :can-edit="!!can.edit" @open="openBox" @edit-credit="editing = true" />
      <ReturnReconciliation v-else-if="section === 'recon'" :ret="r" :can-edit="!!can.edit" @explain="(it) => (explainItem = it)" @take-in="addAdjustment" />
      <ReturnAdjustments v-else-if="section === 'adjust'" :ret="r" :can-edit="!!can.edit" @add="addAdjustment()" />
      <div v-else class="kv-grid">
        <KV :k="{ ar: 'أنشأه', en: 'Created by' }" :v="`${r.createdBy || '—'} · ${fmtDate(r.createdAt)}`" />
        <KV :k="{ ar: 'آخر احتساب', en: 'Computed' }" :v="r.computedAt ? fmtDate(r.computedAt) : '—'" ltr />
        <KV :k="{ ar: 'راجعه', en: 'Reviewed by' }" :v="r.reviewedBy ? `${r.reviewedBy} · ${fmtDate(r.reviewedAt)}` : '—'" />
        <KV :k="{ ar: 'مرجع التقديم', en: 'Filing reference' }" :v="r.filedReference || '—'" ltr />
        <KV :k="{ ar: 'تاريخ التقديم', en: 'Filed on' }" :v="r.filedOn ? fmtDay(r.filedOn) : '—'" ltr />
        <KV :k="{ ar: 'سجّل التقديم', en: 'Filing recorded by' }" :v="r.filedBy ? `${r.filedBy} · ${fmtDate(r.filedAt)}` : '—'" />
        <KV :k="{ ar: 'أقفله', en: 'Locked by' }" :v="r.lockedBy ? `${r.lockedBy} · ${fmtDate(r.lockedAt)}` : '—'" />
        <KV v-if="r.cancelledBy" :k="{ ar: 'ألغاه', en: 'Cancelled by' }" :v="`${r.cancelledBy} · ${fmtDate(r.cancelledAt)} · ${r.cancelReason || ''}`" />
        <KV :k="{ ar: 'رصيد حساب المخرجات', en: 'Output account balance' }" :v="sar(r.ledgerOutput)" ltr />
        <KV :k="{ ar: 'رصيد حساب المدخلات', en: 'Input account balance' }" :v="sar(r.ledgerInput)" ltr />
        <KV :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="r.notes || '—'" />
      </div>
    </template>
  </Drawer>

  <ReturnEditForm :open="editing" :ret="r" @close="editing = false" />
  <FileReturnForm :open="filing" :ret="r" @close="filing = false" />
  <AdjustmentForm :open="adjusting" :return-id="r?.id || null" :item="takeItem" @close="closeAdjustment" />
  <ExplainForm :open="!!explainItem" :return-id="r?.id || null" :item="explainItem" @close="explainItem = null" />
</template>
