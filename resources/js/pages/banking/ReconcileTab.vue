<script setup>
// «المطابقة» — the reconcile workspace of one bank (`bank=<code>`, optionally one statement `stmt=<id>`): unmatched
// statement lines on one side, unmatched ledger entries on the other. Everyone with page.banking may look; holders of
// bank.reconcile tick lines and entries and confirm the match — POST /banking/matches { statementLines, ledgerLines,
// bankAccount (when no line), writeOff?: { rule, date? }, note?, origin: 'manual' }.
// The footer sums the selection in hundredths (exact). When the two sides differ, the active write-off rules that
// could take the difference are offered (bank less than books = 'out', more = 'in'; within the rule's limit and bank) —
// the server is the judge and its refusal is shown as it comes. A click on a line opens its proposals (`line=<id>`).
import { computed, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, DateInput, EmptyState, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ReconcileEntries from './ReconcileEntries.vue';
import ReconcileLines from './ReconcileLines.vue';
import { centsStr, fmtDay, money, nameOf, signClass, toCents, useBankingLookups, useBankingNav } from './banking';

const auth = useAuth();
const nav = useBankingNav();
const lk = useBankingLookups();
const canReconcile = computed(() => auth.can('bank.reconcile'));
const bankCode = computed(() => nav.bank.value);
const bank = computed(() => lk.findBank(bankCode.value));
const stmt = computed(() => nav.get('stmt') || '');

const statements = useList(() => (bankCode.value ? '/banking/statements' : null), () => ({ bank: bankCode.value, status: 'open', sort: 'dateTo', order: 'desc', pageSize: 100 }));
const statementOptions = computed(() => (statements.data.value?.items || []).map((s) => ({ v: s.id, l: `${s.number} · ${fmtDay(s.dateFrom)} — ${fmtDay(s.dateTo)}${s.totals?.unmatched ? ` (${s.totals.unmatched})` : ''}` })));

// ---- the selection: id → { id, amount, label } on each side, kept across pages and filters
const selLines = ref({});
const selEntries = ref({});
const note = ref('');
const ruleId = ref('');
const writeOffDate = ref('');
function clearSelection() { selLines.value = {}; selEntries.value = {}; note.value = ''; ruleId.value = ''; writeOffDate.value = ''; act.clearError(); }
watch(bankCode, clearSelection);
function toggleLine(l) {
  const next = { ...selLines.value };
  if (next[l.id]) delete next[l.id]; else next[l.id] = { id: l.id, amount: l.amount, label: l.kind === 'opening' ? t('الرصيد الافتتاحي', 'Opening balance') : `${l.statementNumber || ''}#${l.lineNo} ${l.reference || ''}`.trim() };
  selLines.value = next;
}
const entryLabel = (e) => e.document?.number || e.journalNumber || '';
function toggleEntry(e) {
  const next = { ...selEntries.value };
  if (next[e.id]) delete next[e.id]; else next[e.id] = { id: e.id, amount: e.amount, label: entryLabel(e) };
  selEntries.value = next;
}
/** A voided document and its reversal: the reversal mirrors the entry exactly, so its amount is the entry's with the other sign. */
function pickPair(e) {
  const next = { ...selEntries.value };
  next[e.id] = { id: e.id, amount: e.amount, label: entryLabel(e) };
  const mirrored = toCents(e.amount);
  next[e.pair.ledgerLineId] = next[e.pair.ledgerLineId] || { id: e.pair.ledgerLineId, amount: centsStr(-mirrored), label: e.pair.journalNumber || '' };
  selEntries.value = next;
}

const lineList = computed(() => Object.values(selLines.value));
const entryList = computed(() => Object.values(selEntries.value));
const stCents = computed(() => lineList.value.reduce((s, x) => s + toCents(x.amount), 0));
const bkCents = computed(() => entryList.value.reduce((s, x) => s + toCents(x.amount), 0));
const diffCents = computed(() => stCents.value - bkCents.value);
const any = computed(() => lineList.value.length + entryList.value.length > 0);
const contra = computed(() => !lineList.value.length && entryList.value.length >= 2 && diffCents.value === 0 && bkCents.value === 0);

/** The rules that could take the difference: usable write-off rules of this bank (or of any), of its direction, within their limit. */
const fittingRules = computed(() => {
  const d = diffCents.value;
  if (d === 0 || !lineList.value.length || !bank.value) return [];
  const dir = d < 0 ? 'out' : 'in';
  return lk.rules.value.filter((r) => r.kind === 'writeoff' && r.active && r.usable && (!r.bank || r.bank.id === bank.value.id) && (r.nature === 'both' || r.nature === dir) && r.maxAmount != null && Math.abs(d) <= toCents(r.maxAmount));
});
const ruleOptions = computed(() => fittingRules.value.map((r) => ({ v: r.id, l: `${nameOf(r)} — ${t('حتى', 'up to')} ${money(r.maxAmount)}${r.account ? ` → ${r.account.code}` : ''}` })));
watch(fittingRules, (rs) => { if (ruleId.value && !rs.some((r) => r.id === ruleId.value)) ruleId.value = ''; });

const act = useAction({ invalidate: ['banking'] });
async function confirmMatch() {
  const body = { origin: 'manual' };
  if (lineList.value.length) body.statementLines = lineList.value.map((x) => x.id); else body.bankAccount = bankCode.value;
  if (entryList.value.length) body.ledgerLines = entryList.value.map((x) => x.id);
  if (diffCents.value !== 0 && ruleId.value) body.writeOff = { rule: ruleId.value, ...(writeOffDate.value ? { date: writeOffDate.value } : {}) };
  if (note.value.trim()) body.note = note.value.trim();
  const r = await act.run(() => api.postIdempotent('/banking/matches', body), { success: (m) => t(`أُكدت المطابقة ${m.number}`, `Match ${m.number} confirmed`) });
  if (r) clearSelection();
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[260px]" :model-value="bankCode" :options="lk.bankOptions.value" :placeholder="{ ar: '— اختر الحساب البنكي —', en: '— choose the bank account —' }"
                 @update:model-value="(v) => nav.patch({ bank: v, stmt: null, lp: null, ep: null })" />
    <SelectInput v-if="bankCode" small class="w-[300px]" :model-value="stmt" :options="statementOptions" :placeholder="{ ar: 'كل الكشوف المفتوحة والمقفلة', en: 'Every live statement' }"
                 @update:model-value="(v) => nav.patch({ stmt: v, lp: null })" />
    <Btn v-if="stmt" size="sm" tone="soft" :label="{ ar: 'فتح الكشف', en: 'Open the statement' }" @click="nav.openStatement(stmt)" />
    <span class="grow" />
    <Btn v-if="bankCode" size="sm" tone="softBlue" :label="{ ar: 'كشف التسوية', en: 'Reconciliation statement' }" @click="nav.go('recon', { bank: bankCode })" />
  </div>

  <EmptyState v-if="!bankCode" tone="dashed" :text="{ ar: 'اختر حسابًا بنكيًا لتظهر سطور كشوفه غير المطابقة بجانب قيود دفاتره غير المطابقة.', en: 'Choose a bank account to see its unmatched statement lines beside its unmatched ledger entries.' }" />
  <template v-else>
    <div v-if="!canReconcile" class="hint !mt-0 mb-2.5">{{ t('تعرض هذه المساحة ما بقي دون مطابقة. تأكيد المطابقة يحتاج صلاحية المطابقة البنكية (bank.reconcile).', 'This workspace shows what is still unmatched. Confirming a match needs the bank reconciliation permission (bank.reconcile).') }}</div>
    <div v-if="bank?.sharedWith?.length" class="hint amber !mt-0 mb-2.5">{{ t(`حساب الأستاذ مشترك مع ${bank.sharedWith.join('، ')} — قيد لا يحدد مستنده بنكًا يظهر هنا وهناك حتى يُطابَق.`, `The ledger account is shared with ${bank.sharedWith.join(', ')} — an entry whose document names no bank shows here and there until it is matched.`) }}</div>
    <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
      <ReconcileLines :bank="bankCode" :statement="stmt" :selected="selLines" :selectable="canReconcile" @toggle="toggleLine" />
      <ReconcileEntries :bank="bankCode" :selected="selEntries" :selectable="canReconcile" @toggle="toggleEntry" @pair="pickPair" />
    </div>

    <!-- the selection and its confirmation -->
    <div v-if="canReconcile" class="card sticky bottom-0 z-10 mt-3 p-3 shadow-[0_-6px_24px_rgba(30,33,48,.08)] max-md:bottom-[calc(64px+env(safe-area-inset-bottom))]">
      <div v-if="!any" class="text-[10.5px] text-muted">{{ t('اختر سطرًا أو أكثر من الكشف والقيود التي تقابلها من الدفاتر — أو افتح سطرًا لترى اقتراحات النظام.', 'Tick one or more statement lines and the ledger entries they answer — or open a line to see the system’s proposals.') }}</div>
      <template v-else>
        <div class="grid grid-cols-3 gap-2 max-[640px]:grid-cols-1">
          <div class="tile"><div class="tile-v" :class="signClass(centsStr(stCents))">{{ money(centsStr(stCents)) }}</div><div class="tile-l">{{ t(`الكشف (${lineList.length})`, `Statement (${lineList.length})`) }}</div></div>
          <div class="tile"><div class="tile-v" :class="signClass(centsStr(bkCents))">{{ money(centsStr(bkCents)) }}</div><div class="tile-l">{{ t(`الدفاتر (${entryList.length})`, `Books (${entryList.length})`) }}</div></div>
          <div class="tile" :class="diffCents === 0 ? 'green' : 'red'">
            <div class="tile-v">{{ diffCents === 0 ? '✓ 0' : money(centsStr(diffCents)) }}</div>
            <div class="tile-l">{{ diffCents === 0 ? t('متساويان', 'Equal') : diffCents < 0 ? t('الفرق: البنك أقل من الدفاتر', 'Difference: bank less than books') : t('الفرق: البنك أكثر من الدفاتر', 'Difference: bank more than books') }}</div>
          </div>
        </div>
        <div class="mt-1.5 truncate text-[9.5px] text-muted">
          <span v-for="x in lineList" :key="x.id" class="me-2 inline-block"><span dir="auto">{{ x.label }}</span> <span class="num ltr">{{ money(x.amount) }}</span></span>
          <span v-if="lineList.length && entryList.length" class="me-2">⇄</span>
          <span v-for="x in entryList" :key="x.id" class="me-2 inline-block"><span class="num ltr">{{ x.label }}</span> <span class="num ltr">{{ money(x.amount) }}</span></span>
        </div>
        <div v-if="contra" class="hint teal !mb-0 mt-2">{{ t('مقاصة: قيود تلغي بعضها بلا سطر كشف (مثل سند ملغى وعكسه) — تُقفل معًا ولا تبقى بنودًا مفتوحة.', 'Contra: entries that cancel each other with no statement line (such as a voided receipt and its reversal) — cleared together, never left as open items.') }}</div>
        <div v-else-if="diffCents !== 0 && !lineList.length" class="hint amber !mb-0 mt-2">{{ t('القيود المختارة لا تلغي بعضها — اختر سطر الكشف الذي تقابله.', 'The chosen entries do not cancel each other — choose the statement line they settle.') }}</div>
        <div v-else-if="diffCents !== 0" class="mt-2">
          <div v-if="ruleOptions.length" class="form-grid">
            <SelectInput v-model="ruleId" :options="ruleOptions" :label="{ ar: 'سوِّ الفرق بقاعدة', en: 'Write the difference off with a rule' }" :placeholder="{ ar: '— بلا تسوية —', en: '— no write-off —' }" />
            <DateInput v-if="ruleId" v-model="writeOffDate" :label="{ ar: 'تاريخ قيد التسوية (اختياري)', en: 'Write-off journal date (optional)' }" :hint="{ ar: 'يُترك فارغًا ليأخذ تاريخ آخر سطر', en: 'Empty = the date of the last line' }" />
          </div>
          <div v-else class="hint amber !mb-0">{{ t('لا قاعدة تسوية فعّالة تقبل هذا الفرق (اتجاهه وحدّه وحسابه البنكي) — اختر قيودًا تساوي السطور، أو أضف قاعدة في «قواعد التسوية».', 'No active write-off rule accepts this difference (its direction, limit and bank) — choose entries that equal the lines, or add a rule under “Write-off rules”.') }}</div>
        </div>
        <div class="row wrap mt-2">
          <TextInput v-model="note" small class="w-[280px]" :placeholder="{ ar: 'ملاحظة (اختيارية)', en: 'Note (optional)' }" />
          <span class="grow" />
          <Btn tone="soft" :label="{ ar: 'إلغاء الاختيار', en: 'Clear' }" @click="clearSelection" />
          <Btn tone="primary" :loading="act.pending.value" :label="diffCents !== 0 && ruleId ? { ar: 'تأكيد مع تسوية الفرق', en: 'Confirm with the write-off' } : { ar: 'تأكيد المطابقة', en: 'Confirm the match' }" @click="confirmMatch" />
        </div>
      </template>
      <ErrorBanner :error="act.error.value" class="!mb-0 mt-2" @close="act.clearError()" />
    </div>
  </template>
</template>
