<script setup>
// Opening a session: the register (one open session per register and per cashier), the opening float — one amount or
// counted by denomination — and a note.   POST /pos/sessions { register, openingCash? | counts?, note? } → the session.
// Refusals the server explains: POS_REGISTER_NOT_READY · POS_SESSION_OPEN · POS_CASHIER_BUSY · POS_WAREHOUSE_SCOPE · POS_OWNED_BY_SOURCE.
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, NumberInput, PillChoice, SectionCard, TextArea } from '@/components';
import { t } from '@/i18n';
import DenominationGrid from './DenominationGrid.vue';
import { NOT_READY, OWNED_CHIP, cents, countsBody, countsTotal, fmtCents, labelOf, lastRegister, nameOf, pct, rememberRegister, usePosLookups } from './pos';

const emit = defineEmits(['opened']);

const lk = usePosLookups();
const registers = computed(() => lk.registers.value);
/** A register can be opened when it is OPS's own, ready and free. */
const usable = (r) => !r.owned && !(r.notReady || []).length && !r.openSession;
const register = ref('');
watch(registers, (list) => {
  if (register.value && list.some((r) => r.id === register.value)) return;
  const last = lastRegister();
  const pick = list.find((r) => r.id === last && usable(r)) || (list.filter(usable).length === 1 ? list.find(usable) : null);
  register.value = pick ? pick.id : '';
}, { immediate: true });
const chosen = computed(() => registers.value.find((r) => r.id === register.value) || null);

const mode = ref('amount');
const MODES = [['amount', { ar: 'مبلغ واحد', en: 'One amount' }], ['counts', { ar: 'عدّ بالفئات', en: 'Count by denomination' }]];
const amount = ref(null);
const counts = ref({});
const note = ref('');
const opening = computed(() => (mode.value === 'counts' ? countsTotal(counts.value) : cents(amount.value ?? 0)));
const invalid = computed(() => !register.value || (mode.value === 'amount' && amount.value != null && amount.value < 0));

const act = useAction();
async function submit() {
  if (invalid.value) return;
  const body = { register: register.value, note: note.value.trim() || undefined };
  if (mode.value === 'counts') body.counts = countsBody(counts.value);
  else body.openingCash = amount.value ?? 0;
  const s = await act.run(() => api.postIdempotent('/pos/sessions', body), { success: (r) => t(`فُتحت الجلسة ${r.number}`, `Session ${r.number} opened`), invalidate: ['pos'] });
  if (s) { rememberRegister(register.value); emit('opened', s); }
}
</script>

<template>
  <div class="mx-auto max-w-[760px]">
    <SectionCard :title="{ ar: 'فتح جلسة بيع', en: 'Open a selling session' }" :sub="{ ar: 'اختر الصندوق وعدّ النقد الافتتاحي في الدرج', en: 'Choose the register and count the opening cash in the drawer' }">
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div v-if="!registers.length" class="hint !mt-0">{{ t('لا صناديق نشطة بعد — يضيفها مدير نقطة البيع من «الإعداد».', 'No active register yet — a point-of-sale manager adds one under "Setup".') }}</div>

      <div class="field-l mb-1.5">{{ t('الصندوق', 'Register') }} <span class="text-bad">*</span></div>
      <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <button v-for="r in registers" :key="r.id" type="button" class="rounded-xl border p-3 text-start transition-colors"
                :class="[register === r.id ? 'border-brand bg-brand-soft' : 'border-line bg-canvas', usable(r) ? 'cursor-pointer hover:border-brand' : 'cursor-not-allowed opacity-70']"
                :disabled="!usable(r)" @click="register = r.id">
          <div class="flex items-center gap-2">
            <span class="num ltr text-[11px] font-extrabold text-violet">{{ r.code }}</span>
            <span class="min-w-0 flex-1 truncate text-[12.5px] font-extrabold text-ink" dir="auto">{{ nameOf(r) }}</span>
            <Chip v-if="r.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
          </div>
          <div class="mt-1 text-[10px] text-muted">{{ r.warehouse ? `${r.warehouse.code} · ${nameOf(r.warehouse)}` : t('بلا مستودع', 'No warehouse') }} · {{ t('أقصى خصم', 'Max discount') }} <span class="num">{{ pct(r.maxDiscountPct) }}</span></div>
          <div v-if="r.openSession" class="mt-1 text-[10px] font-bold text-warn">{{ t('عليه جلسة مفتوحة', 'Has an open session') }} <span class="num">{{ r.openSession.number }}</span> — {{ r.openSession.cashierName }}</div>
          <div v-for="k in r.notReady || []" :key="k" class="mt-1 text-[10px] font-bold text-bad">{{ labelOf(NOT_READY, k) }}</div>
        </button>
      </div>

      <PillChoice v-model="mode" :options="MODES" :label="{ ar: 'النقد الافتتاحي', en: 'Opening cash' }" class="mb-3" />
      <div v-if="mode === 'amount'" class="form-grid mb-3">
        <NumberInput v-model="amount" :min="0" :label="{ ar: 'المبلغ في الدرج', en: 'Amount in the drawer' }" :hint="{ ar: 'فارغ = صفر', en: 'Empty = zero' }" placeholder="0.00" />
      </div>
      <DenominationGrid v-else v-model="counts" class="mb-3" :denominations="lk.denominations.value" />

      <TextArea v-model="note" :rows="2" :label="{ ar: 'ملاحظة', en: 'Note' }" :placeholder="{ ar: 'اختياري', en: 'Optional' }" />

      <div class="mt-3 flex flex-wrap items-center gap-3">
        <div class="flex-1 text-[12px] text-sec">{{ t('يُفتح الصندوق', 'Opening') }} <b class="text-ink">{{ chosen ? chosen.code : '—' }}</b> {{ t('بنقد', 'with') }} <b class="num ltr text-ink">{{ fmtCents(opening) }}</b></div>
        <Btn tone="primary" size="lg" :disabled="invalid" :loading="act.pending.value" :label="{ ar: 'فتح الجلسة', en: 'Open session' }" @click="submit" />
      </div>
    </SectionCard>
  </div>
</template>
