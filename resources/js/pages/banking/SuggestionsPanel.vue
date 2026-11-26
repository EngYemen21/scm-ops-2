<script setup>
// The proposals for one statement line (GET /banking/lines/{id}/suggestions?window=N) — proposed, never decided:
// each with its kind, score, «safe» badge, the reasons in words, the lines and entries it uses, both totals and the
// difference, and one click that confirms it (POST /banking/matches with its `proposal` body and origin 'suggestion').
// Also the write-off rules that would take the WHOLE line (a bank charge with no document in the books), and for the
// opening balance of a first statement, what the books held before it. Opened from any tab by `line=<id>`.
//   <SuggestionsPanel :line-id="idOrNull" @close="…" />
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { ENTRY_KIND, MATCH_KIND, NATURE, SUGGESTION_KIND, fmtDay, money, nameOf, signClass, suggestionReason, toCents, useBankingLookups, useBankingNav } from './banking';

const props = defineProps({
  /** The statement line's id; null closes the panel. */
  lineId: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = useBankingNav();
const lk = useBankingLookups();
const canReconcile = computed(() => auth.can('bank.reconcile'));
const win = ref(7);
watch(() => lk.window.value.default, (d) => { win.value = d; }, { immediate: true });
const windowParam = ref(win.value);
let timer = null;
watch(win, (w) => { clearTimeout(timer); timer = setTimeout(() => { windowParam.value = w; }, 300); });
onBeforeUnmount(() => clearTimeout(timer));

const isOpen = computed(() => !!props.lineId);
const q = useGet(() => (props.lineId ? `/banking/lines/${encodeURIComponent(props.lineId)}/suggestions` : null), () => ({ window: windowParam.value }));
const data = computed(() => (isOpen.value ? q.data.value || null : null));
const line = computed(() => data.value?.line || null);
const act = useAction({ invalidate: ['banking'] });
watch(() => props.lineId, () => act.clearError());

const wholeLineRules = computed(() => (data.value?.rules || []).filter((r) => r.fitsLine));
const otherRules = computed(() => (data.value?.rules || []).filter((r) => !r.fitsLine));

async function take(s) {
  const body = { ...s.proposal, origin: 'suggestion' };
  if (!s.safe && !(await confirm({ title: { ar: 'تأكيد هذا الاقتراح؟', en: 'Confirm this proposal?' }, sub: s.rule ? { ar: `يُرحَّل فرق ${money(s.difference)} بقاعدة «${s.rule.nameAr}»`, en: `A difference of ${money(s.difference)} is posted by rule “${s.rule.nameEn || s.rule.nameAr}”` } : { ar: 'تُطابَق السطور والقيود المعروضة معًا.', en: 'The lines and entries shown are matched together.' }, tone: 'primary', okLabel: { ar: 'تأكيد', en: 'Confirm' } }))) return;
  const r = await act.run(() => api.postIdempotent('/banking/matches', body), { success: (m) => t(`أُكدت المطابقة ${m.number}`, `Match ${m.number} confirmed`) });
  if (r) emit('close');
}
async function bookWithRule(rule) {
  if (!(await confirm({ title: { ar: `تسوية السطر كاملًا بقاعدة «${rule.nameAr}»؟`, en: `Book the whole line with “${rule.nameEn || rule.nameAr}”?` }, sub: { ar: `يُرحَّل قيد بمبلغ ${money(line.value.amount)} إلى ${rule.account?.code || ''} ويُطابَق به السطر.`, en: `A journal of ${money(line.value.amount)} is posted to ${rule.account?.code || ''} and matched with the line.` }, tone: 'primary', okLabel: { ar: 'تسوية', en: 'Book it' } }))) return;
  const r = await act.run(() => api.postIdempotent('/banking/matches', { statementLines: [line.value.id], writeOff: { rule: rule.id }, origin: 'manual' }), { success: (m) => t(`أُكدت المطابقة ${m.number}`, `Match ${m.number} confirmed`) });
  if (r) emit('close');
}
const isLive = computed(() => !!line.value && !line.value.matched);
</script>

<template>
  <Drawer :open="isOpen" :width="720" :z-index="76" :title="{ ar: 'اقتراحات المطابقة', en: 'Matching proposals' }" @close="emit('close')">
    <template #sub>
      <span v-if="line"><span class="num ltr">{{ line.statementNumber }}#{{ line.lineNo }}</span> · <span class="num ltr">{{ fmtDay(line.date) }}</span> · <b class="num ltr" :class="signClass(line.amount)">{{ money(line.amount) }}</b></span>
    </template>
    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="line">
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'المرجع', en: 'Reference' }" :v="line.kind === 'opening' ? t('الرصيد الافتتاحي', 'Opening balance') : line.reference || '—'" ltr />
        <KV :k="{ ar: 'الطرف', en: 'Counterparty' }"><span dir="auto">{{ line.partnerName || '—' }}</span><span v-if="line.partnerAccount" class="num ltr text-[9px] text-faint"> · {{ line.partnerAccount }}</span></KV>
        <KV :k="{ ar: 'الوصف', en: 'Description' }"><span dir="auto">{{ line.description || '—' }}</span></KV>
        <KV :k="{ ar: 'تاريخ القيمة', en: 'Value date' }" :v="fmtDay(line.valueDate)" ltr />
      </div>
      <div v-if="line.matched" class="hint green !mt-0">
        {{ t('هذا السطر مطابَق', 'This line is matched') }}<template v-if="line.match">: <button type="button" class="cell-id" @click="nav.patch({ match: line.match.id, line: null })">{{ line.match.number }}</button> <Chip small :map="MATCH_KIND" :k="line.match.kind" /></template>
      </div>

      <template v-if="isLive">
        <div class="row wrap mb-3 rounded-[12px] bg-soft px-3 py-2">
          <span class="text-[10.5px] font-bold text-sec">{{ t('نافذة البحث بالأيام', 'Search window, days') }} ±</span>
          <input v-model.number="win" type="range" min="0" :max="lk.window.value.max" step="1" class="w-[200px] accent-[#654e92]" :aria-label="t('نافذة البحث', 'Search window')">
          <b class="num w-[28px] text-center">{{ win }}</b>
          <span class="text-[9.5px] text-faint">{{ t('القيد المذكور بمرجعه يُوجد أبعد من النافذة', 'An entry named by its reference is found beyond the window') }}</span>
        </div>

        <div v-if="data.openingHint" class="banner teal mb-3">
          <div class="font-extrabold">{{ t('الرصيد الافتتاحي لأول كشف', 'The opening balance of the first statement') }}</div>
          <div class="text-[10.5px]">{{ t(`في الدفاتر قبل بداية الكشف ${data.openingHint.entries} قيد غير مطابق مجموعها ${money(data.openingHint.total)} — الفرق عن الرصيد الافتتاحي ${money(data.openingHint.difference)}.`, `Before the statement starts the books hold ${data.openingHint.entries} unmatched entr(ies) totalling ${money(data.openingHint.total)} — ${money(data.openingHint.difference)} away from the opening balance.`) }}</div>
        </div>

        <div class="card-title mb-1.5">{{ t('الاقتراحات', 'Proposals') }} <span class="card-count num ms-1">{{ data.suggestions.length }}</span></div>
        <div v-if="!data.suggestions.length" class="empty !p-4">{{ t('لا اقتراح لهذا السطر ضمن النافذة — وسّع النافذة، أو اختر القيود بنفسك في مساحة المطابقة، أو سوّه بقاعدة إن كان رسمًا بنكيًا.', 'No proposal for this line within the window — widen the window, pick the entries yourself in the workspace, or book it with a rule if it is a bank charge.') }}</div>
        <div class="col !gap-2">
          <div v-for="(s, i) in data.suggestions" :key="i" class="rounded-[14px] border px-3 py-2.5" :class="s.safe ? 'border-[#bfe8cc] bg-[#f6fdf8]' : 'border-line-2'">
            <div class="row wrap !gap-1.5">
              <Chip :map="SUGGESTION_KIND" :k="s.kind" />
              <span class="num text-[10px] font-extrabold" :class="s.score >= 80 ? 'text-ok' : s.score >= 55 ? 'text-warn' : 'text-muted'">{{ s.score }}%</span>
              <Chip v-if="s.safe" small :label="{ ar: 'مؤكد', en: 'Certain' }" fg="#1d7a3e" bg="#e6f9ec" />
              <span class="grow" />
              <span class="num ltr text-[10px]">{{ t('الكشف', 'Bank') }} <b>{{ money(s.statementTotal) }}</b> · {{ t('الدفاتر', 'Books') }} <b>{{ money(s.ledgerTotal) }}</b>
                <template v-if="toCents(s.difference) !== 0"> · {{ t('فرق', 'diff') }} <b class="text-bad">{{ money(s.difference) }}</b></template></span>
            </div>
            <ul class="mt-1.5 list-disc ps-5 text-[10.5px] text-sec">
              <li v-for="(r, ri) in s.reasons" :key="ri">{{ suggestionReason(r) }}</li>
            </ul>
            <div v-if="s.statementLines.length > 1" class="mt-1.5">
              <div class="field-l">{{ t('سطور الكشف', 'Statement lines') }}</div>
              <div v-for="l in s.statementLines" :key="l.id" class="row !gap-1.5 text-[10px]"><span class="num ltr text-faint">{{ l.statementNumber }}#{{ l.lineNo }}</span><span class="cell-date">{{ fmtDay(l.date) }}</span><span class="min-w-0 flex-1 truncate" dir="auto">{{ l.reference || l.description }}</span><span class="num ltr" :class="signClass(l.amount)">{{ money(l.amount) }}</span></div>
            </div>
            <div v-if="s.ledgerLines.length" class="mt-1.5">
              <div class="field-l">{{ t('قيود الدفاتر', 'Ledger entries') }}</div>
              <div v-for="e in s.ledgerLines" :key="e.id" class="row !gap-1.5 text-[10px]">
                <span class="cell-id !text-[10px]">{{ e.document?.number || e.journalNumber }}</span><Chip small :map="ENTRY_KIND" :k="e.kind" />
                <span class="cell-date">{{ fmtDay(e.date) }}</span>
                <span class="min-w-0 flex-1 truncate text-muted" dir="auto">{{ e.party ? nameOf(e.party) : e.description || e.memo || '' }}</span>
                <span class="num ltr" :class="signClass(e.amount)">{{ money(e.amount) }}</span>
              </div>
            </div>
            <div v-if="s.rule" class="mt-1.5 text-[10px] text-violet">{{ t('قاعدة التسوية', 'Write-off rule') }}: <b dir="auto">{{ nameOf(s.rule) }}</b> <span class="text-faint">({{ t('حتى', 'up to') }} <span class="num">{{ money(s.rule.maxAmount) }}</span>)</span></div>
            <div v-if="canReconcile" class="row mt-2"><span class="grow" /><Btn size="sm" :tone="s.safe ? 'success' : 'primary'" :loading="act.pending.value" :label="{ ar: 'أكّد هذا', en: 'Confirm this' }" @click="take(s)" /></div>
          </div>
        </div>

        <template v-if="line.kind === 'line'">
          <div class="card-title mb-1.5 mt-4">{{ t('تسوية السطر كاملًا بقاعدة', 'Book the whole line with a rule') }}</div>
          <div v-if="!wholeLineRules.length" class="empty !p-3">{{ t('لا قاعدة تقبل مبلغ هذا السطر كاملًا (اتجاهه وحدّه).', 'No rule accepts the whole amount of this line (its direction and limit).') }}</div>
          <div class="col !gap-1.5">
            <div v-for="r in wholeLineRules" :key="r.id" class="row wrap rounded-[12px] border border-line-2 px-3 py-2 text-[10.5px]">
              <b class="min-w-0 flex-1 truncate" dir="auto">{{ nameOf(r) }}</b>
              <Chip small :map="NATURE" :k="r.nature" />
              <span class="num">{{ t('حتى', 'up to') }} {{ money(r.maxAmount) }}</span>
              <span v-if="r.account" class="num ltr text-muted">→ {{ r.account.code }}</span>
              <Btn v-if="canReconcile" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'سوِّ بها', en: 'Book it' }" @click="bookWithRule(r)" />
            </div>
          </div>
          <div v-if="otherRules.length" class="mt-1.5 text-[9.5px] text-faint">{{ t(`قواعد أخرى فعّالة لهذا الحساب لا تقبل هذا المبلغ: ${otherRules.map((r) => r.nameAr).join('، ')}`, `Other active rules of this account that do not accept this amount: ${otherRules.map((r) => r.nameEn || r.nameAr).join(', ')}`) }}</div>
        </template>
      </template>
      <div v-if="!canReconcile && isLive" class="hint mt-3">{{ t('تأكيد الاقتراحات يحتاج صلاحية المطابقة البنكية (bank.reconcile).', 'Confirming proposals needs the bank reconciliation permission (bank.reconcile).') }}</div>
    </template>

    <template v-if="line" #footer>
      <div class="row">
        <Btn v-if="isLive && line.statementId" tone="soft" :label="{ ar: 'فتح الكشف', en: 'Open the statement' }" @click="nav.patch({ st: line.statementId, line: null })" />
        <span class="grow" />
        <Btn tone="ghost" :label="{ ar: 'إغلاق', en: 'Close' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
