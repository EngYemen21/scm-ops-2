<script setup>
// One match (GET /banking/matches/{id}): its two sides — the statement lines and the ledger entries — with their
// totals, the write-off (amount, rule, journal and its reversal), who confirmed it and when, and who undid it and why.
// «فك المطابقة» (bank.reconcile, when `can.undo`): POST /banking/matches/{id}/undo { reason, date? } — the lines and
// entries are free again and a write-off journal is reversed (dated `date`, else today; its period must be open).
//   <MatchDrawer :id="idOrNull" @close="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DateInput, Drawer, ErrorBanner, KV, Modal, TextArea } from '@/components';
import { fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ENTRY_KIND, MATCH_KIND, MATCH_ORIGIN, MATCH_STATUS, bankLabel, fmtDay, money, nameOf, signClass, useBankingNav } from './banking';

const props = defineProps({
  /** The match's id (or number); null closes the drawer. */
  id: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = useBankingNav();
const isOpen = computed(() => !!props.id);
const q = useGet(() => (props.id ? `/banking/matches/${encodeURIComponent(props.id)}` : null));
const m = computed(() => (isOpen.value ? q.data.value || null : null));
const act = useAction({ invalidate: ['banking'] });
const canUndo = computed(() => auth.can('bank.reconcile') && !!m.value?.can?.undo);

// ---- undo: a reason (5 characters at least) and, for a write-off, the day of its reversal
const undoing = ref(false);
const reason = ref('');
const date = ref('');
const reasonError = ref(null);
watch(() => props.id, () => { undoing.value = false; act.clearError(); });
function startUndo() { reason.value = ''; date.value = ''; reasonError.value = null; act.clearError(); undoing.value = true; }
async function undo() {
  if (reason.value.trim().length < 5) { reasonError.value = t('اكتب السبب — 5 أحرف على الأقل', 'Write the reason — 5 characters at least'); return; }
  const body = { reason: reason.value.trim(), ...(date.value ? { date: date.value } : {}) };
  const r = await act.run(() => api.postIdempotent(`/banking/matches/${m.value.id}/undo`, body), { success: t('فُكت المطابقة', 'Match undone') });
  if (r) undoing.value = false;
}
</script>

<template>
  <Drawer :open="isOpen" :width="720" :z-index="66" @close="emit('close')">
    <template #title><span class="num ltr">{{ m ? m.number : id }}</span></template>
    <template #sub><span v-if="m"><span dir="auto">{{ bankLabel(m.bank) }}</span> · {{ t('سُوّيت', 'cleared') }} <span class="num ltr">{{ fmtDay(m.clearedDate) }}</span></span></template>
    <template v-if="m" #headExtra><span class="inline-flex gap-1"><Chip :map="MATCH_STATUS" :k="m.status" /><Chip :map="MATCH_KIND" :k="m.kind" /></span></template>

    <ErrorBanner :error="q.error.value || (undoing ? null : act.error.value)" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="m">
      <div v-if="m.status === 'undone'" class="hint amber !mt-0 mb-3">{{ t('فُكت', 'Undone') }} · <span dir="auto">{{ m.undoneBy }}</span> · <span class="num ltr">{{ fmtDate(m.undoneAt) }}</span><template v-if="m.undoReason"> — <span dir="auto">{{ m.undoReason }}</span></template></div>
      <div v-if="m.kind === 'system'" class="hint !mt-0 mb-3">{{ t('مطابقة من عمل النظام: قيد تسوية معكوس مع عكسه — لا تُفك.', 'Made by the system: a reversed write-off journal held with its reversal — it is not undone.') }}</div>
      <div v-if="m.kind === 'contra'" class="hint teal !mt-0 mb-3">{{ t('مقاصة: قيود تلغي بعضها بلا سطر كشف.', 'Contra: entries that cancel each other with no statement line.') }}</div>

      <div class="grid grid-cols-3 gap-2 max-[560px]:grid-cols-1">
        <div class="tile"><div class="tile-v" :class="signClass(m.statementTotal)">{{ money(m.statementTotal) }}</div><div class="tile-l">{{ t('مجموع الكشف', 'Statement total') }}</div></div>
        <div class="tile"><div class="tile-v" :class="signClass(m.ledgerTotal)">{{ money(m.ledgerTotal) }}</div><div class="tile-l">{{ t('مجموع الدفاتر (مع التسوية)', 'Books total (with write-off)') }}</div></div>
        <div class="tile" :class="m.writeOff ? 'purple' : ''"><div class="tile-v">{{ m.writeOff ? money(m.writeOff.amount) : '—' }}</div><div class="tile-l">{{ t('فرق مُسوّى', 'Written off') }}</div></div>
      </div>

      <div class="card-title mb-1.5 mt-4">{{ t('سطور الكشف', 'Statement lines') }} <span class="card-count num ms-1">{{ m.statementLines.length }}</span></div>
      <div v-if="!m.statementLines.length" class="empty !p-3">{{ t('لا سطور — مقاصة بين قيود.', 'No line — entries cleared against each other.') }}</div>
      <div class="col !gap-1">
        <div v-for="x in m.statementLines" :key="x.itemId" class="row wrap rounded-[10px] border border-line-2 px-3 py-1.5 text-[10.5px]">
          <button v-if="x.line" type="button" class="cell-id !text-[10px]" @click="nav.openStatement(x.line.statementId)">{{ x.line.statementNumber }}#{{ x.line.lineNo }}</button>
          <span class="cell-date">{{ fmtDay(x.date) }}</span>
          <span class="min-w-0 flex-1 truncate" dir="auto">{{ x.label === 'opening' ? t('الرصيد الافتتاحي', 'Opening balance') : x.line?.reference || x.line?.description || x.label || '—' }}<template v-if="x.line?.partnerName"> · {{ x.line.partnerName }}</template></span>
          <span class="num ltr font-bold" :class="signClass(x.amount)">{{ money(x.amount) }}</span>
        </div>
      </div>

      <div class="card-title mb-1.5 mt-4">{{ t('قيود الدفاتر', 'Ledger entries') }} <span class="card-count num ms-1">{{ m.ledgerLines.length }}</span></div>
      <div class="col !gap-1">
        <div v-for="x in m.ledgerLines" :key="x.itemId" class="row wrap rounded-[10px] border px-3 py-1.5 text-[10.5px]" :class="x.isWriteoff ? 'border-[#d9cff0] bg-[#faf8fe]' : 'border-line-2'">
          <span class="cell-id !text-[10px]">{{ x.document?.number || x.label }}</span>
          <Chip v-if="x.entry" small :map="ENTRY_KIND" :k="x.entry.kind" /><Chip v-else-if="x.isWriteoff" small :map="ENTRY_KIND" k="writeoff" />
          <span class="cell-date">{{ fmtDay(x.date) }}</span>
          <span class="min-w-0 flex-1 truncate text-muted" dir="auto">{{ x.entry?.party ? nameOf(x.entry.party) : x.entry?.description || x.entry?.memo || '' }}<template v-if="x.label && x.label !== x.document?.number"> · <span class="num ltr">{{ x.label }}</span></template></span>
          <span class="num ltr font-bold" :class="signClass(x.amount)">{{ money(x.amount) }}</span>
        </div>
      </div>

      <div v-if="m.writeOff" class="mt-4 rounded-[12px] border border-[#d9cff0] bg-[#faf8fe] px-3 py-2">
        <div class="field-l mb-1">{{ t('تسوية الفرق', 'Write-off') }}</div>
        <div class="kv-grid">
          <KV :k="{ ar: 'المبلغ', en: 'Amount' }" :v="money(m.writeOff.amount)" ltr />
          <KV :k="{ ar: 'القاعدة', en: 'Rule' }"><span dir="auto">{{ m.writeOff.rule ? nameOf(m.writeOff.rule) : '—' }}</span></KV>
          <KV :k="{ ar: 'قيد التسوية', en: 'Journal' }" :v="m.writeOff.journalNumber || '—'" ltr />
          <KV :k="{ ar: 'قيد العكس', en: 'Reversal' }" :v="m.writeOff.reversalJournalNumber || '—'" ltr />
        </div>
      </div>

      <div class="kv-grid mt-4">
        <KV :k="{ ar: 'أكدها', en: 'Confirmed by' }"><span dir="auto">{{ m.createdBy || '—' }}</span> · <span class="num ltr">{{ fmtDate(m.createdAt) }}</span></KV>
        <KV :k="{ ar: 'المصدر', en: 'Origin' }"><Chip small :map="MATCH_ORIGIN" :k="m.origin" /></KV>
        <KV v-if="m.note" :k="{ ar: 'ملاحظة', en: 'Note' }"><span dir="auto">{{ m.note }}</span></KV>
      </div>
    </template>

    <template v-if="m && canUndo" #footer>
      <div class="row"><span class="grow" /><Btn tone="dangerOutline" :label="{ ar: 'فك المطابقة', en: 'Undo the match' }" @click="startUndo" /></div>
    </template>
  </Drawer>

  <Modal :open="undoing" :width="460" :z-index="90" :title="{ ar: `فك المطابقة ${m?.number || ''}`, en: `Undo match ${m?.number || ''}` }" @close="undoing = false">
    <div class="hint !mt-0 mb-3">{{ t('تعود السطور والقيود حرّة للمطابقة من جديد، وتبقى المطابقة في السجل بسبب فكها.', 'The lines and entries are free to be matched again; the match stays on record with the reason it was undone.') }}</div>
    <TextArea v-model="reason" :rows="3" required :label="{ ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (5 characters at least)' }" :error="reasonError" />
    <DateInput v-if="m?.writeOff" v-model="date" field-class="mt-3" :label="{ ar: 'تاريخ قيد عكس التسوية (اختياري)', en: 'Date of the write-off reversal (optional)' }" :hint="{ ar: 'يُترك فارغًا ليكون اليوم — يجب أن تكون فترته مفتوحة', en: 'Empty = today — its accounting period must be open' }" />
    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="danger" class="flex-1" :loading="act.pending.value" :label="{ ar: 'فك المطابقة', en: 'Undo' }" @click="undo" />
        <Btn tone="soft" class="w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="undoing = false" />
      </div>
    </template>
  </Modal>
</template>
