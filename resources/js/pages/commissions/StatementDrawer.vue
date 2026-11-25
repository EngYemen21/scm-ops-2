<script setup>
// One commission statement, from any tab: `statement=<number>` in the query string opens it, `view=` picks its part.
//   GET /commissions/statements/{number} → the statement, its terms, its adjustments, a breakdown by rule, its history
//   and `actions` — for every step whether THIS user may take it now, and the reason when he may not.
// The steps (each one POSTs and the server decides): recalculate · add / void an adjustment · exclude / include an
// invoice · submit · send back (reason) · approve · pay out (PayoutDrawer) · void the payout (reason).
// A user who only opens the section sees his own statement here and no step at all.
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api, useAction, useGet, useInvalidate } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, Tabs, Timeline } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { confirm, toast } from '@/stores/ui';
import AdjustmentDrawer from './AdjustmentDrawer.vue';
import PayoutDrawer from './PayoutDrawer.vue';
import ReasonDrawer from './ReasonDrawer.vue';
import StatementLines from './StatementLines.vue';
import { API, EARNER, LINE_KIND, ODOO_CHIP, STATEMENT_STATUS, STATUS_COLOR, commissionText, dayText, label, money, pct, periodLabel, personName, recordName, sar, termsText, useCommissions } from './commissions';

const { nav, seesAll } = useCommissions();
const number = computed(() => nav.get('statement') || null);
const detailQ = useGet(() => (number.value ? `${API}/statements/${encodeURIComponent(number.value)}` : null));
const s = computed(() => (number.value && detailQ.data.value && detailQ.data.value.number ? detailQ.data.value : null));
const can = (action) => !!s.value?.actions?.[action]?.allowed;
const why = (action) => { const a = s.value?.actions?.[action]; return a && !a.allowed ? bi({ ar: a.reasonAr, en: a.reasonEn }) : ''; };

const VIEWS = ['lines', 'adjustments', 'history'];
const view = computed(() => (VIEWS.includes(nav.get('view')) ? nav.get('view') : 'lines'));
const live = computed(() => (s.value?.adjustments || []).filter((a) => !a.voided));
const viewTabs = computed(() => [
  { k: 'lines', label: { ar: 'السطور المحتسبة', en: 'Calculated lines' }, badge: s.value?.linesCount || null },
  { k: 'adjustments', label: { ar: 'التسويات', en: 'Adjustments' }, badge: live.value.length || null },
  { k: 'history', label: { ar: 'سجل الحالة', en: 'History' } },
]);
const history = computed(() => (s.value?.history || []).map((h) => ({ at: h.at, by: h.by, note: h.note, label: STATEMENT_STATUS[h.to] || h.to, color: STATUS_COLOR[h.to] })));
const breakdown = computed(() => (s.value?.breakdown || []).filter((b) => b.lines > 0));

// ---- steps
const act = useAction({ invalidate: ['commissions'] });
const invalidate = useInvalidate();
const asking = ref(null);       // a reason prompt: { title, …, run(reason) }
const adjusting = ref(false);
const paying = ref(false);
watch(number, () => { asking.value = null; adjusting.value = false; paying.value = false; act.clearError(); });
const url = (suffix = '') => `${API}/statements/${encodeURIComponent(s.value.number)}${suffix}`;
const post = (suffix, body, success) => act.run(() => api.postIdempotent(url(suffix), body), { success });

async function recalculate() {
  const r = await post('/recalculate', undefined, false);
  if (!r) return;
  if (r.statement === null) { toast.say(t('لم يعد لهذه المسودة ما يبررها — لا فواتير ولا خطة فعّالة — فسُحبت', 'Nothing justifies this draft any more — no invoice, no active plan — so it was withdrawn'), 4200); nav.closeStatement(); return; }
  const mine = (r.run?.statements || []).find((x) => x.number === s.value?.number);
  toast.say(mine && mine.outcome !== 'unchanged'
    ? t(`أُعيد الاحتساب: +${mine.added || 0} سطر · ${mine.changed || 0} تغيّر · −${mine.removed || 0} — الإجمالي ${money(mine.total)}`, `Recalculated: +${mine.added || 0} lines · ${mine.changed || 0} changed · −${mine.removed || 0} — total ${money(mine.total)}`)
    : t('أُعيد الاحتساب — لا تغيير', 'Recalculated — nothing changed'));
}
async function submit() {
  if (!(await confirm({ title: { ar: `تقديم الكشف ${s.value.number} للاعتماد؟`, en: `Submit statement ${s.value.number} for approval?` },
    sub: { ar: 'يُعاد احتسابه أولًا من الفواتير كما هي الآن؛ إن تغيّر شيء عُرض عليك قبل التقديم. بعد التقديم يُقفل: لا تسويات ولا إعادة احتساب.', en: 'It is recalculated first from the invoices as they stand now; if anything changed you are shown it before it is submitted. Once submitted it is closed: no adjustment, no recalculation.' }, tone: 'dark' }))) return;
  const r = await post('/submit', undefined, t('قُدّم الكشف للاعتماد', 'Submitted for approval'));
  // refused because the recalculation moved its figures: they are already saved — show them (the banner says why)
  if (!r && act.error.value?.code === 'COMMISSION_STATEMENT_CHANGED') invalidate(['commissions']);
  // … or because nothing justified it any more: it was withdrawn — there is nothing left to show
  if (!r && act.error.value?.code === 'COMMISSION_STATEMENT_WITHDRAWN') { toast.say(act.error.value.localized(), 4200); act.clearError(); invalidate(['commissions']); nav.closeStatement(); }
}
async function approve() {
  if (!(await confirm({ title: { ar: `اعتماد الكشف ${s.value.number}؟`, en: `Approve statement ${s.value.number}?` },
    sub: { ar: `${personName(s.value.beneficiary)} · ${periodLabel(s.value.period)} · ${money(s.value.total)} ${sar()}`, en: `${personName(s.value.beneficiary)} · ${periodLabel(s.value.period)} · ${money(s.value.total)} ${sar()}` }, tone: 'primary' }))) return;
  await post('/approve', undefined, t('اعتُمد الكشف — بانتظار الصرف', 'Approved — waiting for its payout'));
}
const askReturn = () => { asking.value = {
  title: s.value.status === 'approved' ? { ar: `إعادة فتح الكشف ${s.value.number}`, en: `Reopen statement ${s.value.number}` } : { ar: `إعادة الكشف ${s.value.number} إلى المسودة`, en: `Send statement ${s.value.number} back to draft` },
  sub: { ar: 'يعود مسودة تُعدَّل ويُعاد احتسابها ثم تُقدَّم من جديد', en: 'It becomes a draft again: it can be corrected, recalculated and submitted anew' }, okLabel: { ar: 'إعادة إلى المسودة', en: 'Back to draft' }, min: 5,
  run: (reason) => post('/return', { reason }, t('أُعيد الكشف إلى المسودة', 'Sent back to draft')) }; };
const askVoidPayout = () => { asking.value = {
  title: { ar: `إلغاء صرف الكشف ${s.value.number}`, en: `Void the payout of ${s.value.number}` },
  sub: { ar: 'يُلغى سند المصروف في المالية ويُعكس قيده، ويعود الكشف «معتمدًا بانتظار الصرف»', en: 'The expense voucher is voided in finance and its journal reversed; the statement is approved and unpaid again' },
  okLabel: { ar: 'إلغاء الصرف', en: 'Void the payout' }, tone: 'danger', min: 5,
  run: (reason) => act.run(() => api.postIdempotent(url('/void-payout'), { reason }), { success: t('أُلغي الصرف وعُكس السند', 'Payout voided, voucher reversed'), invalidate: ['commissions', 'finance'] }) }; };
const askVoidAdjustment = (line) => { asking.value = {
  title: { ar: 'إلغاء التسوية', en: 'Void the adjustment' }, sub: { ar: `${commissionText(line.commission)} — ${line.reason || ''}`, en: `${commissionText(line.commission)} — ${line.reason || ''}` },
  okLabel: { ar: 'إلغاء التسوية', en: 'Void it' }, tone: 'danger', min: 5,
  run: (reason) => act.run(() => api.postIdempotent(`${API}/lines/${line.id}/void`, { reason }), { success: t('أُلغيت التسوية — تبقى ظاهرة ولا تُحتسب', 'Adjustment voided — it stays visible and counts no more') }) }; };
const askExclude = (line) => { asking.value = {
  title: { ar: `استبعاد ${line.docNumber} من العمولة`, en: `Exclude ${line.docNumber} from the commission` },
  sub: { ar: 'تبقى الفاتورة في الكشف بعمولة صفر، ولا تدخل كشفًا آخر', en: 'The invoice stays on the statement with no commission, and enters no other statement' }, okLabel: { ar: 'استبعاد', en: 'Exclude' }, min: 3,
  run: (reason) => act.run(() => api.postIdempotent(`${API}/lines/${line.id}/exclude`, { reason }), { success: t('استُبعدت الفاتورة', 'Invoice excluded') }) }; };
async function include(line) {
  if (!(await confirm({ title: { ar: `إرجاع ${line.docNumber} إلى الاحتساب؟`, en: `Count ${line.docNumber} again?` }, tone: 'dark' }))) return;
  await act.run(() => api.postIdempotent(`${API}/lines/${line.id}/include`), { success: t('عادت الفاتورة إلى الاحتساب', 'The invoice counts again') });
}
async function runAsked(reason) {
  const r = await asking.value.run(reason);
  if (r) asking.value = null;
}
/** What happens next, in one line — or why nothing can. */
const next = computed(() => {
  const x = s.value;
  if (!x) return null;
  if (x.owned) return { tone: 'purple', text: t('كشف من أودو: للاطلاع فقط ما دام القسم يتبع أودو.', 'A statement of Odoo: read-only while the section follows Odoo.') };
  if (x.status === 'draft') return { tone: '', text: can('submit') ? t('مسودة: راجع السطور والتسويات ثم قدّمها للاعتماد.', 'Draft: review the lines and adjustments, then submit it for approval.') : (x.mine ? t('كشفك ما زال مسودة قيد المراجعة — أرقامه قد تتغير حتى يُقدَّم.', 'Your statement is still a draft under review — its figures may change until it is submitted.')
    : x.actions?.submit?.code === 'FORBIDDEN' ? t('مسودة قيد الإعداد — يقدّمها مسؤول العمولات للاعتماد.', 'A draft in preparation — the commissions manager submits it for approval.') : why('submit')) };
  if (x.status === 'submitted') return { tone: 'amber', text: can('approve') ? t('بانتظار اعتمادك: اعتمده أو أعده إلى المسودة مع السبب.', 'Waiting for your approval: approve it, or send it back with a reason.') : (why('approve') || t('بانتظار اعتماد صاحب الصلاحية.', 'Waiting for the approver.')) };
  if (x.status === 'approved') return { tone: 'teal', text: can('payout') ? t('معتمد: اصرفه بسند مصروف من المالية.', 'Approved: release its payout through an expense voucher.') : (why('payout') || t('معتمد وبانتظار الصرف.', 'Approved and waiting for its payout.')) };
  return { tone: 'green', text: x.payout?.expense ? t(`صُرف بسند المصروف ${x.payout.expense.number}.`, `Paid with expense voucher ${x.payout.expense.number}.`) : (x.sourceSystem ? t('صُرف في أودو.', 'Paid in Odoo.') : t('أُقفل بلا صرف (الإجمالي صفر).', 'Closed with nothing to pay (the total is zero).')) };
});
const anyStep = computed(() => ['recalculate', 'adjust', 'submit', 'approve', 'return', 'payout', 'voidPayout'].some(can));
</script>

<template>
  <Drawer :open="!!number" :width="940" :title="s ? s.number : t('كشف عمولة', 'Commission statement')" :sub="s ? `${personName(s.beneficiary)} · ${periodLabel(s.period)}` : null" @close="nav.closeStatement()">
    <template #headExtra>
      <div v-if="s" class="row !gap-1"><Chip :map="STATEMENT_STATUS" :k="s.status" small /><Chip v-if="s.sourceSystem === 'odoo'" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" /></div>
    </template>
    <ErrorBanner :error="detailQ.error.value" :closable="false" />
    <ErrorBanner v-if="!asking" :error="act.error.value" @close="act.clearError()" />
    <div v-if="!s && !detailQ.error.value" class="col"><div class="skel h-[70px]" /><div class="skel h-[160px]" /></div>

    <template v-if="s">
      <div class="kpi-grid compact !mb-3">
        <div class="tile"><div class="tile-v">{{ money(s.baseTotal) }}</div><div class="tile-l">{{ t('أساس العمولة', 'Commission base') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(s.commissionTotal) }}</div><div class="tile-l">{{ t('عمولة السطور', 'Lines commission') }} <span class="num">({{ s.linesCount }})</span></div></div>
        <div class="tile" :class="Number(s.adjustmentsTotal) < 0 ? 'red' : Number(s.adjustmentsTotal) > 0 ? 'green' : ''"><div class="tile-v">{{ money(s.adjustmentsTotal) }}</div><div class="tile-l">{{ t('التسويات', 'Adjustments') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ money(s.total) }} <span class="text-[9px] font-bold text-muted">{{ sar() }}</span></div><div class="tile-l">{{ t('المستحق', 'Payable') }}</div></div>
      </div>
      <div v-if="next" class="hint !mt-0 mb-3" :class="next.tone">{{ next.text }}</div>

      <div class="kv-grid mb-3">
        <div class="kv"><div class="kv-k">{{ t('المستحق له', 'Earner') }}</div><div class="kv-v" dir="auto">{{ personName(s.beneficiary) }}<span v-if="s.beneficiary && s.beneficiary.external" class="ms-1 text-[9px] font-normal text-muted">{{ t('(بالاسم — بلا مستخدم)', '(by name — no user)') }}</span></div></div>
        <div class="kv"><div class="kv-k">{{ t('النوع', 'Kind') }}</div><div class="kv-v">{{ label(EARNER, s.earnerType) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('الفترة', 'Window') }}</div><div class="kv-v num ltr">{{ dayText(s.dateFrom) }} – {{ dayText(s.dateTo) }}</div></div>
        <div v-if="s.plan" class="kv"><div class="kv-k">{{ t('الخطة', 'Plan') }}</div>
          <div class="kv-v" dir="auto"><button v-if="seesAll" type="button" class="cursor-pointer border-0 bg-transparent p-0 text-start font-bold text-violet" @click="nav.patch({ statement: null, view: null, lq: null, lkind: null, lpage: null, plan: s.plan.code }, true)">{{ recordName(s.plan) }}</button><span v-else>{{ recordName(s.plan) }}</span></div></div>
        <div v-if="s.reference" class="kv"><div class="kv-k">{{ t('مرجع أودو', 'Odoo reference') }}</div><div class="kv-v num ltr">{{ s.reference }}</div></div>
        <div v-if="s.calculatedAt" class="kv"><div class="kv-k">{{ t('آخر احتساب', 'Last calculated') }}</div><div class="kv-v"><span class="num ltr">{{ fmtDate(s.calculatedAt) }}</span><span v-if="s.calculatedBy" class="text-muted"> · {{ s.calculatedBy }}</span></div></div>
        <div v-if="s.processedAt" class="kv"><div class="kv-k">{{ t('أُكد في أودو', 'Confirmed in Odoo') }}</div><div class="kv-v num ltr">{{ fmtDate(s.processedAt) }}</div></div>
        <div v-if="s.submittedAt" class="kv"><div class="kv-k">{{ t('قُدّم', 'Submitted') }}</div><div class="kv-v"><span class="num ltr">{{ fmtDate(s.submittedAt) }}</span><span v-if="s.submittedBy" class="text-muted"> · {{ s.submittedBy }}</span></div></div>
        <div v-if="s.approvedAt" class="kv"><div class="kv-k">{{ t('اعتُمد', 'Approved') }}</div><div class="kv-v"><span class="num ltr">{{ fmtDate(s.approvedAt) }}</span><span v-if="s.approvedBy" class="text-muted"> · {{ s.approvedBy }}</span></div></div>
        <div v-if="s.approvalNote" class="kv"><div class="kv-k">{{ t('ملاحظة الاعتماد', 'Approval note') }}</div><div class="kv-v !font-normal" dir="auto">{{ s.approvalNote }}</div></div>
        <div v-if="s.status === 'draft' && s.returned" class="kv col-span-full"><div class="kv-k">{{ t('أُعيد إلى المسودة', 'Sent back to draft') }}</div><div class="kv-v !font-normal" dir="auto">{{ s.returned.reason }} <span class="text-muted">— {{ s.returned.by }} · <span class="num ltr">{{ fmtDate(s.returned.at) }}</span></span></div></div>
        <template v-if="s.payout">
          <div v-if="s.payout.date" class="kv"><div class="kv-k">{{ t('تاريخ الصرف', 'Payout date') }}</div><div class="kv-v num ltr">{{ dayText(s.payout.date) }}</div></div>
          <div v-if="s.payout.reference" class="kv"><div class="kv-k">{{ t('مرجع الحوالة', 'Transfer reference') }}</div><div class="kv-v num ltr">{{ s.payout.reference }}</div></div>
          <div v-if="s.payout.expense" class="kv"><div class="kv-k">{{ t('سند المصروف', 'Expense voucher') }}</div>
            <div class="kv-v num ltr"><RouterLink :to="`/finance?tab=expenses&expense=${encodeURIComponent(s.payout.expense.number)}`" class="font-bold text-violet">{{ s.payout.expense.number }}</RouterLink><span class="text-muted"> · {{ s.payout.account }} ← {{ s.payout.bankAccount }}</span></div></div>
        </template>
      </div>

      <div v-if="s.terms" class="hint teal !mt-0 mb-3">
        <b>{{ t('شروط الاحتساب', 'Terms') }}:</b> {{ termsText(s.terms) }}
        <span v-if="s.terms.origin === 'odoo'" class="text-muted">— {{ t('كما احتُسب في أودو (نسبة من هامش ربح الفواتير المسدَّدة)', 'as Odoo calculated it (a rate of the margin of paid invoices)') }}</span>
        <div v-if="breakdown.length > 1" class="mt-1.5 flex flex-col gap-0.5">
          <div v-for="(b, i) in breakdown" :key="i" class="flex flex-wrap items-baseline gap-x-2 text-[10px]">
            <Chip :map="LINE_KIND" :k="b.kind" small /><span dir="auto">{{ b.ruleLabel || t('القاعدة', 'Rule') }}</span>
            <span class="num ltr">{{ b.lines }} × </span><span class="num ltr">{{ money(b.base) }}</span>
            <span v-if="b.rateMin != null" class="num ltr">@ {{ b.rateMin === b.rateMax ? pct(b.rateMin) : `${pct(b.rateMin)}–${pct(b.rateMax)}` }}</span>
            <span class="num ltr font-bold">= {{ money(b.commission) }}</span>
          </div>
        </div>
      </div>

      <Tabs :model-value="view" :tabs="viewTabs" variant="sm" @update:model-value="(k) => nav.patch({ view: k === 'lines' ? null : k }, true)" />
      <StatementLines v-if="view === 'lines'" :number="s.number" :can-change="can('adjust')" @exclude="askExclude" @include="include" />
      <template v-else-if="view === 'adjustments'">
        <div v-if="!s.adjustments.length" class="empty dashed !rounded-xl !p-5">{{ t('لا تسويات على هذا الكشف.', 'No adjustment on this statement.') }}</div>
        <div v-else class="overflow-hidden rounded-xl border border-line-2">
          <div v-for="a in s.adjustments" :key="a.id" class="flex flex-wrap items-start gap-x-3 gap-y-1 border-t border-line-2 px-3 py-2.5 first:border-t-0" :class="{ 'opacity-60': a.voided }">
            <div class="num ltr w-[92px] flex-none text-[12px] font-bold" :class="[Number(a.commission) < 0 ? 'text-bad' : 'text-ok', a.voided ? 'line-through' : '']">{{ commissionText(a.commission) }}</div>
            <div class="min-w-0 flex-1 basis-[200px]">
              <div class="text-[11px] font-bold leading-[1.7]" dir="auto">{{ a.reason }}</div>
              <div class="text-[9.5px] text-muted">{{ a.createdBy }} · <span class="num ltr">{{ fmtDate(a.createdAt) }}</span></div>
              <div v-if="a.voided" class="text-[9.5px] font-bold text-warn" dir="auto">{{ t('أُلغيت', 'Voided') }}: {{ a.voided.reason }} — {{ a.voided.by }}</div>
            </div>
            <Btn v-if="can('adjust') && !a.voided" size="sm" tone="dangerOutline" class="!h-7 !text-[9px]" :label="{ ar: 'إلغاء', en: 'Void' }" @click="askVoidAdjustment(a)" />
          </div>
        </div>
        <div class="hint">{{ t('التسوية مبلغ يُضاف للمستحق أو يُخصم منه بسبب مكتوب (مكافأة، خصم متفق عليه، ترحيل رصيد). تُلغى ولا تُحذف.', 'An adjustment is an amount added to the earner or deducted from him, with a written reason (a bonus, an agreed deduction, a balance carried over). It is voided, never deleted.') }}</div>
      </template>
      <Timeline v-else :items="history" :empty-text="s.sourceSystem ? { ar: 'لا سجل حالة في العمليات: هذا الكشف جاء من أودو بحالته.', en: 'No history in OPS: this statement came from Odoo in its state.' } : { ar: 'لم تتغير حالته بعد.', en: 'Its state has not changed yet.' }" />
    </template>

    <template v-if="s && anyStep" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="can('approve')" tone="primary" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
        <Btn v-if="can('payout')" tone="success" :label="Math.abs(Number(s.total)) < 0.005 ? { ar: 'إقفال بلا صرف', en: 'Close, nothing to pay' } : { ar: 'صرف الكشف', en: 'Pay out' }" @click="paying = true" />
        <Btn v-if="can('submit')" tone="dark" :loading="act.pending.value" :label="{ ar: 'تقديم للاعتماد', en: 'Submit for approval' }" @click="submit" />
        <Btn v-if="can('adjust')" tone="softPurple" :label="{ ar: '+ تسوية', en: '+ Adjustment' }" @click="adjusting = true" />
        <Btn v-if="can('recalculate')" tone="soft" :loading="act.pending.value" :label="{ ar: 'إعادة الاحتساب', en: 'Recalculate' }" @click="recalculate" />
        <span class="grow" />
        <Btn v-if="can('return')" tone="outline" :label="s.status === 'approved' ? { ar: 'إعادة فتح', en: 'Reopen' } : { ar: 'إعادة إلى المسودة', en: 'Back to draft' }" @click="askReturn" />
        <Btn v-if="can('voidPayout')" tone="dangerOutline" :label="{ ar: 'إلغاء الصرف', en: 'Void the payout' }" @click="askVoidPayout" />
      </div>
    </template>
  </Drawer>

  <AdjustmentDrawer :open="adjusting" :statement="s" @close="adjusting = false" />
  <PayoutDrawer :open="paying" :statement="s" @close="paying = false" />
  <ReasonDrawer :ask="asking" :pending="act.pending.value" :error="act.error.value" @submit="runAsked" @close="asking = null; act.clearError()" @clear-error="act.clearError()" />
</template>
