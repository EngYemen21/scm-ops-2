<script setup>
// Closing a session (its cashier, or a point-of-sale manager): the cash counted by denomination against what should be
// in the drawer now; a difference needs a reason (POS_DIFF_REASON) and is posted to the register's difference account.
// Held sales block the close (POS_HELD_OPEN) unless they are discarded with it.
//   POST /pos/sessions/{id}/close { counts: [{ value, count }], reason?, note?, discardHeld? } → the closed session
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Drawer, ErrorBanner, TextArea } from '@/components';
import { t } from '@/i18n';
import DenominationGrid from './DenominationGrid.vue';
import { cents, countsBody, countsTotal, errCode, fmtCents, usePosLookups } from './pos';

const props = defineProps({
  open: { type: Boolean, default: false },
  sessionId: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = usePosLookups();
const act = useAction();
/** Fresh figures every time the form opens: what the drawer should hold changes with every sale. */
const sess = useGet(() => (props.open && props.sessionId ? `/pos/sessions/${encodeURIComponent(props.sessionId)}` : null));
const s = computed(() => sess.data.value || null);

const counts = ref({});
const reason = ref('');
const note = ref('');
const discardHeld = ref(false);
const tried = ref(false);
watch(() => props.open, (o) => { if (o) { counts.value = {}; reason.value = ''; note.value = ''; discardHeld.value = false; tried.value = false; act.clearError(); } });

const counted = computed(() => countsTotal(counts.value));
const expected = computed(() => cents(s.value?.expectedCashNow ?? 0));
const diff = computed(() => counted.value - expected.value);
const heldOpen = computed(() => (s.value?.held || 0) > 0 || errCode(act.error.value) === 'POS_HELD_OPEN');
const reasonError = computed(() => (tried.value && diff.value !== 0 && reason.value.trim().length < 5 ? t('في النقد فرق — اكتب سببه (5 أحرف على الأقل)', 'The cash differs — explain it (at least 5 characters)') : null));
const canSubmit = computed(() => !!s.value && s.value.state === 'open' && !s.value.history);

async function submit() {
  tried.value = true;
  if (!canSubmit.value || reasonError.value) return;
  if (heldOpen.value && !discardHeld.value) return;
  const body = { counts: countsBody(counts.value), reason: reason.value.trim() || undefined, note: note.value.trim() || undefined, discardHeld: discardHeld.value || undefined };
  const r = await act.run(() => api.postIdempotent(`/pos/sessions/${encodeURIComponent(props.sessionId)}/close`, body), { success: (x) => t(`أُغلقت الجلسة ${x.number}`, `Session ${x.number} closed`), invalidate: ['pos'] });
  if (r) emit('done', r);
}
</script>

<template>
  <Drawer :open="open" :title="{ ar: 'إغلاق الجلسة', en: 'Close the session' }" :sub="s ? s.number : null" :width="560" :z-index="72" @close="emit('close')">
    <ErrorBanner :error="sess.error.value" :closable="false" />
    <div v-if="!s && !sess.error.value" class="skel h-[200px]" />
    <template v-else-if="s">
      <div v-if="!canSubmit" class="hint !mt-0 mb-3">{{ s.history ? t('جلسة من سجل أودو — للقراءة فقط.', 'A session of the Odoo history — read only.') : t('هذه الجلسة مغلقة مسبقًا.', 'This session is already closed.') }}</div>
      <div class="mb-2 text-[11.5px] text-sec">{{ t('عُدّ النقد في الدرج فئةً فئة:', 'Count the cash in the drawer, denomination by denomination:') }}</div>
      <DenominationGrid v-model="counts" :denominations="lk.denominations.value" :disabled="!canSubmit" class="mb-3" />

      <div class="mb-3 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl bg-soft p-2.5">
          <div class="text-[10px] text-muted">{{ t('المتوقع', 'Expected') }}</div>
          <div class="num ltr text-[14px] font-extrabold text-ink">{{ fmtCents(expected) }}</div>
        </div>
        <div class="rounded-xl bg-soft p-2.5">
          <div class="text-[10px] text-muted">{{ t('المعدود', 'Counted') }}</div>
          <div class="num ltr text-[14px] font-extrabold text-ink">{{ fmtCents(counted) }}</div>
        </div>
        <div class="rounded-xl p-2.5" :class="diff === 0 ? 'bg-ok-soft' : diff > 0 ? 'bg-warn-soft' : 'bg-bad-soft'">
          <div class="text-[10px] text-muted">{{ diff === 0 ? t('لا فرق', 'No difference') : diff > 0 ? t('زيادة', 'Over') : t('عجز', 'Short') }}</div>
          <div class="num ltr text-[14px] font-extrabold" :class="diff === 0 ? 'text-ok' : diff > 0 ? 'text-warn' : 'text-bad'">{{ diff > 0 ? '+' : '' }}{{ fmtCents(diff) }}</div>
        </div>
      </div>

      <div class="form-grid">
        <TextArea v-if="diff !== 0" v-model="reason" :rows="2" required :error="reasonError" :label="{ ar: 'سبب الفرق', en: 'Reason for the difference' }" :placeholder="{ ar: 'مثال: خطأ في إرجاع الباقي لعميل', en: 'e.g. wrong change given to a customer' }" />
        <TextArea v-model="note" :rows="2" :label="{ ar: 'ملاحظة الإغلاق', en: 'Closing note' }" :placeholder="{ ar: 'اختياري', en: 'Optional' }" />
      </div>
      <label v-if="heldOpen" class="mt-3 flex cursor-pointer items-start gap-2 rounded-xl border border-line p-2.5 text-[11.5px]" :class="tried && !discardHeld ? 'border-bad' : ''">
        <input v-model="discardHeld" type="checkbox" class="mt-0.5">
        <span>
          <b>{{ t('إلغاء المبيعات المعلّقة مع الإغلاق', 'Discard the held sales with the close') }}</b>
          <span class="block text-muted">{{ t(`في الجلسة ${s.held || ''} بيع معلّق — استرجعه وأكمله، أو ألغِه هنا.`, `The session has ${s.held || ''} held sale(s) — recall and finish them, or discard them here.`) }}</span>
        </span>
      </label>
    </template>
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="danger" class="!h-11 flex-1" :disabled="!canSubmit" :loading="act.pending.value" :label="{ ar: 'إغلاق الجلسة', en: 'Close session' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
