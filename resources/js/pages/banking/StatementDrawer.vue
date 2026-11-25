<script setup>
// One statement (GET /banking/statements/{id}): where it stands, whether the bank's figures add up (opening + in + out =
// closing), every reason it cannot be locked yet (`blockers`, each in both languages — never a dead end), how it
// continues the previous statement, its lines, typed lines, the import of the bank's file, its imports and history.
// `can` says what the server would accept now; a button shows only for that AND the permission of the person:
//   bankstatement.manage: edit · lines · import · void (reason) · notes        bank.reconcile: auto-match · reconcile · reopen (reason)
// A history statement of Odoo is read-only (notes only); a record Odoo owns shows the "managed in Odoo" notice.
//   <StatementDrawer :id="idOrNull" @close="…" @edit="(statement) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Tabs, TextArea, Timeline } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import ImportWizard from './ImportWizard.vue';
import LinesEditor from './LinesEditor.vue';
import StatementLines from './StatementLines.vue';
import { OWNED_CHIP, OWNED_HELP, STATEMENT_STATUS, bankLabel, fmtDay, labelOf, money, nameOf, signClass, toCents, useBankingLookups, useBankingNav } from './banking';

const props = defineProps({
  /** The statement's id (or number); null closes the drawer. */
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'edit']);

const auth = useAuth();
const nav = useBankingNav();
const lk = useBankingLookups();
const canManage = computed(() => auth.can('bankstatement.manage'));
const canReconcile = computed(() => auth.can('bank.reconcile'));
const isOpen = computed(() => !!props.id);
const q = useGet(() => (props.id ? `/banking/statements/${encodeURIComponent(props.id)}` : null));
const s = computed(() => (isOpen.value ? q.data.value || null : null));
const can = computed(() => s.value?.can || {});
const act = useAction({ invalidate: ['banking'] });

const pane = ref('details');
const importing = ref(false);
const autoResult = ref(null);
const notes = ref('');
watch(() => props.id, () => { pane.value = 'details'; importing.value = false; autoResult.value = null; act.clearError(); });
watch(() => s.value?.notes, (n) => { notes.value = n || ''; }, { immediate: true });

const tot = computed(() => s.value?.totals || {});
const lineCount = computed(() => (tot.value.matched || 0) + (tot.value.unmatched || 0));
const panes = computed(() => [
  { k: 'details', label: { ar: 'الأرقام والحالة', en: 'Figures & status' }, badge: s.value?.blockers?.length || null },
  { k: 'lines', label: { ar: 'السطور', en: 'Lines' }, badge: lineCount.value || null },
  ...(canManage.value && can.value.lines ? [{ k: 'add', label: { ar: '+ إدخال سطور', en: '+ Type lines' } }] : []),
  { k: 'history', label: { ar: 'الاستيراد والسجل', en: 'Imports & history' } },
]);
const difference = computed(() => tot.value.difference);
const balanced = computed(() => difference.value != null && toCents(difference.value) === 0);

const url = (suffix = '') => `/banking/statements/${s.value.id}${suffix}`;
async function voidIt() {
  const reason = await ask({ title: { ar: `إلغاء الكشف ${s.value.number}`, en: `Void statement ${s.value.number}` }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (5 characters at least)' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء الكشف', en: 'Void' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('/void'), { reason }), { success: t('أُلغي الكشف', 'Statement voided') });
}
async function reconcile() {
  if (!(await confirm({ title: { ar: `اعتماد مطابقة الكشف ${s.value.number} وإقفاله؟`, en: `Reconcile and lock ${s.value.number}?` }, sub: { ar: 'لا يُعدَّل الكشف ولا تُفك مطابقاته بعد الإقفال إلا بإعادة فتحه مع ذكر السبب.', en: 'Once locked, nothing of it changes until it is reopened with a reason.' }, tone: 'primary', okLabel: { ar: 'اعتماد وإقفال', en: 'Reconcile' } }))) return;
  await act.run(() => api.postIdempotent(url('/reconcile')), { success: t('اعتُمدت مطابقة الكشف وأُقفل', 'Statement reconciled and locked') });
}
async function reopen() {
  const reason = await ask({ title: { ar: `إعادة فتح الكشف ${s.value.number}`, en: `Reopen statement ${s.value.number}` }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (5 characters at least)' }, required: true, tone: 'dark', okLabel: { ar: 'إعادة فتح', en: 'Reopen' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('/reopen'), { reason }), { success: t('أُعيد فتح الكشف', 'Statement reopened') });
}
async function autoMatch() {
  if (!(await confirm({ title: { ar: 'مطابقة المؤكد تلقائيًا؟', en: 'Match the certain lines automatically?' }, sub: { ar: 'يُؤكَّد لكل سطر غير مطابق الاقتراح الذي لا شك فيه فقط (المبلغ نفسه مع المرجع، أو المبلغ الوحيد في الجهتين). الباقي يبقى لك.', en: 'For every unmatched line only the proposal that leaves no doubt is confirmed (same amount named by its reference, or the only such amount on both sides). The rest is left to you.' }, tone: 'primary', okLabel: { ar: 'مطابقة', en: 'Match' } }))) return;
  const r = await act.run(() => api.postIdempotent(url('/auto-match'), { window: lk.window.value.default }), {
    success: (x) => t(`طوبق ${x.matched.length} سطر — بقي ${x.left}`, `${x.matched.length} line(s) matched — ${x.left} left`),
  });
  if (r) { autoResult.value = r; pane.value = 'details'; }
}
async function saveNotes() {
  await act.run(() => api.patch(url(), { notes: notes.value.trim() || null }), { success: t('حُفظت الملاحظات', 'Notes saved') });
}
function workspace() { nav.go('reconcile', { bank: s.value.bank?.code, stmt: s.value.id }); }

const timeline = computed(() => (s.value?.timeline || []).map((h) => ({ at: h.at, by: h.by, label: labelOf(STATEMENT_STATUS, h.to), color: labelOf(STATEMENT_STATUS, h.to).fg, note: h.note, from: h.from })));
</script>

<template>
  <Drawer :open="isOpen" :width="820" @close="emit('close')">
    <template #title><span class="num ltr">{{ s ? s.number : id }}</span><span v-if="s?.name" class="ms-2 text-[12px] font-bold text-sec" dir="auto">{{ s.name }}</span></template>
    <template #sub>
      <span v-if="s"><span dir="auto">{{ s.bank ? bankLabel(s.bank) : s.sourceJournal?.name || '—' }}</span> · <span class="num ltr inline-block">{{ fmtDay(s.dateFrom) }} — {{ fmtDay(s.dateTo) }}</span><template v-if="s.externalRef"> · <span class="num ltr inline-block">Odoo #{{ s.externalRef }}</span></template></span>
    </template>
    <template v-if="s" #headExtra>
      <span class="inline-flex gap-1"><Chip :map="STATEMENT_STATUS" :k="s.status" /><Chip v-if="s.owned" :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" /></span>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[240px]" />
    <template v-if="s">
      <div v-if="s.owned" class="hint purple !mt-0 mb-3">{{ bi(OWNED_HELP) }}</div>
      <div v-if="s.history" class="hint purple !mt-0 mb-3">{{ t('كشف تاريخي من النظام السابق: يُقرأ هنا للرجوع إليه ولا يُعدَّل ولا يُطابَق — تُضاف إليه الملاحظات فقط.', 'A history statement of the previous system: kept here for reference, never changed or matched — only notes may be added.') }}</div>
      <div v-if="s.status === 'void'" class="hint amber !mt-0 mb-3">{{ t('ملغى', 'Voided') }} · <span dir="auto">{{ s.voidedBy }}</span> · <span class="num ltr">{{ fmtDate(s.voidedAt) }}</span><template v-if="s.voidReason"> — <span dir="auto">{{ s.voidReason }}</span></template></div>
      <div v-if="s.status === 'reconciled'" class="hint green !mt-0 mb-3">{{ t('مطابَق ومقفل', 'Reconciled and locked') }} · <span dir="auto">{{ s.reconciledBy }}</span> · <span class="num ltr">{{ fmtDate(s.reconciledAt) }}</span> — {{ t('لا يُعدَّل شيء منه حتى يُعاد فتحه.', 'Nothing of it changes until it is reopened.') }}</div>
      <div v-if="s.reopenedAt && s.status === 'open'" class="hint amber !mt-0 mb-3">{{ t('أُعيد فتحه', 'Reopened') }} · <span dir="auto">{{ s.reopenedBy }}</span> · <span class="num ltr">{{ fmtDate(s.reopenedAt) }}</span><template v-if="s.reopenReason"> — <span dir="auto">{{ s.reopenReason }}</span></template></div>

      <!-- the bank's own figures: opening + in + out = computed closing, against the closing it states -->
      <div class="grid grid-cols-6 gap-2 max-[640px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ money(s.openingBalance) }}</div><div class="tile-l">{{ t('الافتتاحي', 'Opening') }}</div></div>
        <div class="tile green"><div class="tile-v">+{{ money(tot.in) }}</div><div class="tile-l">{{ t('الوارد', 'Money in') }}</div></div>
        <div class="tile red"><div class="tile-v">{{ money(tot.out) }}</div><div class="tile-l">{{ t('الصادر', 'Money out') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(tot.computedClosing) }}</div><div class="tile-l">{{ t('= الختامي المحسوب', '= Computed closing') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ money(s.closingBalance) }}</div><div class="tile-l">{{ t('الختامي في الكشف', 'Closing per bank') }}</div></div>
        <div class="tile" :class="difference == null ? '' : balanced ? 'green' : 'red'">
          <div class="tile-v" :class="balanced ? 'text-ok' : signClass(difference)">{{ difference == null ? '—' : balanced ? '✓ 0' : money(difference) }}</div>
          <div class="tile-l">{{ balanced ? t('متوازن', 'Balanced') : t('الفرق', 'Difference') }}</div>
        </div>
      </div>
      <div class="mt-2 text-[10.5px] text-sec">
        {{ t('السطور', 'Lines') }}: <b class="num">{{ tot.lines || 0 }}</b> · {{ t('مطابَقة', 'matched') }} <b class="num text-ok">{{ tot.matched || 0 }}</b> · {{ t('غير مطابَقة', 'unmatched') }} <b class="num" :class="tot.unmatched ? 'text-warn' : 'text-ok'">{{ tot.unmatched || 0 }}</b>
        <template v-if="tot.hasOpeningLine"> · {{ tot.openingMatched ? t('الرصيد الافتتاحي مطابَق', 'opening balance matched') : t('الرصيد الافتتاحي غير مطابَق بعد', 'opening balance not matched yet') }}</template>
      </div>

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-2.5 mt-4" />

      <template v-if="pane === 'details'">
        <div v-if="autoResult" class="banner green mb-3">
          <div class="font-extrabold">{{ t(`المطابقة التلقائية: طوبق ${autoResult.matched.length} سطر، وبقي ${autoResult.left} سطر لك.`, `Automatic matching: ${autoResult.matched.length} line(s) matched, ${autoResult.left} left for you.`) }}</div>
          <div v-for="m in autoResult.matched" :key="m.match" class="text-[10px]"><span class="num">#{{ m.lineNo }}</span> → <span class="num ltr">{{ m.match }}</span> · <span class="num ltr">{{ m.documents.join(', ') }}</span></div>
        </div>
        <template v-if="s.blockers?.length">
          <div class="field-l mb-1">{{ t('ما يمنع اعتماد المطابقة الآن', 'What keeps it from being reconciled now') }}</div>
          <div class="col mb-3 !gap-1.5">
            <div v-for="b in s.blockers" :key="b.code" class="banner amber !m-0">
              <div class="text-[11px] font-bold" dir="auto">{{ t(b.ar, b.en) }}</div>
              <div class="mt-0.5 text-[9px] opacity-70"><code>{{ b.code }}</code>
                <template v-if="b.code === 'BANK_STATEMENT_UNMATCHED'"> · <button type="button" class="font-bold underline" @click="pane = 'lines'">{{ t('اعرض السطور غير المطابقة', 'Show the unmatched lines') }}</button></template>
                <template v-else-if="b.code === 'BANK_STATEMENT_UNBALANCED' && canManage && can.lines"> · <button type="button" class="font-bold underline" @click="pane = 'add'">{{ t('أدخل السطور الناقصة', 'Type the missing lines') }}</button></template>
                <template v-else-if="(b.code === 'BANK_STATEMENT_ORDER' || b.code === 'BANK_STATEMENT_GAP') && s.continuity?.previous"> · <button type="button" class="font-bold underline" @click="nav.openStatement(s.continuity.previous.id)">{{ t(`افتح ${s.continuity.previous.number}`, `Open ${s.continuity.previous.number}`) }}</button></template>
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="s.status === 'open'" class="hint green !mt-0 mb-3">{{ t('لا شيء يمنع اعتماد المطابقة: الأرقام متوازنة وكل السطور مطابَقة.', 'Nothing keeps it from being reconciled: the figures add up and every line is matched.') }}</div>

        <div v-if="s.continuity" class="mb-3 rounded-[12px] border border-line-2 px-3 py-2 text-[10.5px]">
          <div class="field-l mb-1">{{ t('الاستمرارية مع الكشف السابق', 'Continuity with the previous statement') }}</div>
          <template v-if="s.continuity.previous">
            <div class="row wrap !gap-1.5">
              <button type="button" class="cell-id" @click="nav.openStatement(s.continuity.previous.id)">{{ s.continuity.previous.number }}</button>
              <Chip small :map="STATEMENT_STATUS" :k="s.continuity.previous.status" />
              <span>{{ t('انتهى في', 'ended') }} <span class="num ltr">{{ fmtDay(s.continuity.previous.dateTo) }}</span> {{ t('برصيد', 'at') }} <b class="num ltr">{{ money(s.continuity.previous.closingBalance) }}</b></span>
              <span>· {{ t('افتتاحي هذا الكشف', 'this opening') }} <b class="num ltr">{{ money(s.continuity.opening) }}</b></span>
              <span v-if="s.continuity.ok" class="font-extrabold text-ok">✓ {{ t('متصل', 'continuous') }}</span>
              <span v-else class="font-extrabold text-bad">{{ t('فجوة', 'gap') }} <span class="num ltr">{{ money(s.continuity.gap) }}</span></span>
            </div>
          </template>
          <div v-else class="text-muted">{{ t('أول كشف لهذا الحساب — رصيده الافتتاحي سطر يُطابَق بقيود الدفاتر التي قبله.', 'The first statement of this account — its opening balance is a line, matched with the ledger entries before it.') }}</div>
        </div>

        <div class="kv-grid">
          <KV :k="{ ar: 'الحساب', en: 'Account' }"><span dir="auto">{{ s.bankAccount ? bankLabel(s.bankAccount) : '—' }}</span></KV>
          <KV :k="{ ar: 'البنك', en: 'Bank' }"><span dir="auto">{{ s.bankAccount?.bankName || '—' }}</span></KV>
          <KV :k="{ ar: 'الآيبان', en: 'IBAN' }" :v="s.bankAccount?.iban || '—'" ltr />
          <KV :k="{ ar: 'حساب الأستاذ', en: 'Ledger account' }"><span v-if="s.bankAccount?.ledgerAccount"><span class="num ltr">{{ s.bankAccount.ledgerAccount.code }}</span> <span dir="auto">{{ nameOf(s.bankAccount.ledgerAccount) }}</span></span><template v-else>—</template></KV>
          <KV :k="{ ar: 'العملة', en: 'Currency' }" :v="s.currency || '—'" ltr />
          <KV v-if="s.sourceJournal" :k="{ ar: 'دفتر أودو', en: 'Odoo journal' }"><span class="num ltr">{{ s.sourceJournal.code }}</span> <span dir="auto">{{ s.sourceJournal.name }}</span></KV>
          <KV :k="{ ar: 'أُنشئ', en: 'Created' }"><span class="num ltr">{{ fmtDate(s.createdAt) }}</span><span v-if="s.createdBy" class="text-[9px] font-normal text-faint" dir="auto"> · {{ s.createdBy }}</span></KV>
          <KV :k="{ ar: 'آخر تعديل', en: 'Last change' }" :v="fmtDate(s.updatedAt)" ltr />
        </div>

        <div class="mt-3.5">
          <template v-if="canManage && can.note">
            <TextArea v-model="notes" :rows="3" :label="{ ar: 'ملاحظات', en: 'Notes' }" />
            <div class="row mt-1.5"><span class="grow" /><Btn size="sm" tone="dark" :loading="act.pending.value" :disabled="notes.trim() === (s.notes || '').trim()" :label="{ ar: 'حفظ الملاحظات', en: 'Save notes' }" @click="saveNotes" /></div>
          </template>
          <template v-else-if="s.notes">
            <div class="field-l mb-1">{{ t('ملاحظات', 'Notes') }}</div>
            <div class="whitespace-pre-wrap rounded-[12px] bg-soft px-3 py-2.5 text-[11px] leading-[1.9] text-sec [overflow-wrap:anywhere]" dir="auto">{{ s.notes }}</div>
          </template>
        </div>
      </template>

      <StatementLines v-else-if="pane === 'lines'" :statement="s" />

      <template v-else-if="pane === 'add'">
        <div class="hint !mt-0 mb-2.5">{{ t(`المبلغ موجب للوارد وسالب للصادر، والتاريخ داخل فترة الكشف (${fmtDay(s.dateFrom)} — ${fmtDay(s.dateTo)}).`, `Amounts are positive for money in and negative for money out; dates fall inside the statement period (${fmtDay(s.dateFrom)} — ${fmtDay(s.dateTo)}).`) }}</div>
        <LinesEditor :statement="s" @done="pane = 'lines'" />
      </template>

      <template v-else>
        <div class="field-l mb-1">{{ t('ملفات الاستيراد', 'Imports') }}</div>
        <div v-if="!s.imports?.length" class="empty !p-4">{{ t('لم يُستورد ملف لهذا الكشف.', 'No file has been imported into this statement.') }}</div>
        <div class="col mb-4 !gap-1.5">
          <div v-for="i in s.imports" :key="i.id" class="row wrap rounded-[12px] border border-line-2 px-3 py-2 text-[10.5px]">
            <span class="min-w-0 flex-1 truncate font-bold text-ink" dir="auto" :title="i.fileName">{{ i.fileName }}</span>
            <span class="num uppercase text-faint">{{ i.format }}</span>
            <span class="num">{{ t('صفوف', 'rows') }} {{ i.total }}</span>
            <span class="num text-ok">{{ t('أُخذ', 'taken') }} {{ i.taken }}</span>
            <span class="num text-warn">{{ t('مكرر', 'duplicate') }} {{ i.duplicates }}</span>
            <span class="num text-bad">{{ t('مرفوض', 'refused') }} {{ i.refused }}</span>
            <span class="cell-date">{{ fmtDate(i.createdAt) }}</span>
            <span class="text-faint" dir="auto">{{ i.createdBy }}</span>
          </div>
        </div>
        <div class="field-l mb-1">{{ t('سجل الحالة', 'Status history') }}</div>
        <Timeline :items="timeline" :empty-text="{ ar: 'لا انتقالات مسجّلة.', en: 'No transition on record.' }">
          <template #note="{ item }">
            <div v-if="item.from" class="text-muted">{{ t('من', 'from') }} {{ bi(labelOf(STATEMENT_STATUS, item.from)) }}</div>
            <div v-if="item.note" class="whitespace-pre-wrap text-ink [overflow-wrap:anywhere]" dir="auto">{{ item.note }}</div>
          </template>
        </Timeline>
      </template>
    </template>

    <template v-if="s && (canManage || canReconcile)" #footer>
      <div class="row wrap">
        <Btn v-if="canReconcile && can.reconcile" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد المطابقة', en: 'Reconcile' }" @click="reconcile" />
        <Btn v-if="canReconcile && can.match" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'مطابقة المؤكد', en: 'Auto-match' }" @click="autoMatch" />
        <Btn v-if="s.bank && s.status !== 'void' && s.status !== 'history'" tone="softBlue" :label="{ ar: 'مساحة المطابقة', en: 'Workspace' }" @click="workspace" />
        <Btn v-if="canManage && can.import" tone="soft" :label="{ ar: 'استيراد ملف البنك', en: 'Import bank file' }" @click="importing = true" />
        <Btn v-if="canReconcile && can.reopen" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'إعادة فتح', en: 'Reopen' }" @click="reopen" />
        <span class="grow" />
        <Btn v-if="canManage && can.void" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء الكشف', en: 'Void' }" @click="voidIt" />
        <Btn v-if="canManage && can.edit" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', s)" />
      </div>
    </template>
  </Drawer>

  <ImportWizard :statement="importing ? s : null" @close="importing = false" @done="importing = false; pane = 'lines'" />
</template>
