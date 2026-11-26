<script setup>
// One attendance record: GET /payroll/attendance/{id} → the row (checkIn, checkOut, originalCheckIn, originalCheckOut,
// corrected, status, void info, inSheet, can { correct, void }) + `changes` — the correction log, oldest first
// [{ field, old, new, reason, by, at }]. «تصحيح» → AttendanceForm (PATCH), «إلغاء السجل» → POST …/void { reason }.
// Nothing is removed: a void record stays, marked, with who voided it and why.
//   <AttendanceDrawer :id="idOrNull" @close="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV } from '@/components';
import { t } from '@/i18n';
import { ask } from '@/stores/ui';
import AttendanceForm from './AttendanceForm.vue';
import { ATT_STATE, CORRECTED_CHIP, FROM_ODOO, IN_SHEET_CHIP, attState, fmtAt, fmtDay, hm, pname } from './payroll';

const props = defineProps({
  id: { type: String, default: null },
});
const emit = defineEmits(['close']);

const q = useGet(() => (props.id ? `/payroll/attendance/${encodeURIComponent(props.id)}` : null));
const rec = computed(() => (props.id ? q.data.value || null : null));
const can = computed(() => rec.value?.can || {});
const act = useAction({ invalidate: ['payroll'] });
const correcting = ref(false);
watch(() => props.id, () => { correcting.value = false; act.clearError(); });

const FIELD = { check_in: ['وقت الحضور', 'Check-in'], check_out: ['وقت الانصراف', 'Check-out'], status: ['الحالة', 'Status'], created: ['إدخال يدوي', 'Entered by hand'] };
const fieldName = (f) => (FIELD[f] ? t(FIELD[f][0], FIELD[f][1]) : f);
const valueText = (c, v) => (v == null || v === '' ? '—' : /^check_/.test(c.field) ? fmtAt(v) : v === 'void' ? t('ملغى', 'void') : v === 'ok' ? t('قائم', 'standing') : v === 'manual' ? t('يدوي', 'manual') : v);

async function voidIt() {
  const reason = await ask({ title: { ar: 'إلغاء سجل الحضور', en: 'Void the attendance record' }, sub: { ar: 'يبقى السجل ظاهرًا موسومًا «ملغى» مع السبب، ولا يُحسب في الكشوف', en: 'The record stays, marked void with the reason, and no sheet counts it' }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, okLabel: { ar: 'إلغاء السجل', en: 'Void' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/payroll/attendance/${encodeURIComponent(rec.value.id)}/void`, { reason }), { success: t('أُلغي السجل', 'Record voided') });
}
</script>

<template>
  <Drawer :open="!!id" :width="520" :z-index="61" @close="emit('close')">
    <template #title><span dir="auto">{{ rec ? pname(rec.employee) : t('سجل حضور', 'Attendance record') }}</span></template>
    <template #sub><span v-if="rec"><span class="num ltr inline-block">{{ fmtDay(rec.workDate) }}</span> · <span class="num ltr inline-block">{{ rec.employee?.code }}</span></span></template>
    <template v-if="rec" #headExtra><Chip :map="ATT_STATE" :k="attState(rec)" /></template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[180px]" />
    <template v-if="rec">
      <div class="row wrap mb-3 !gap-1.5">
        <Chip v-if="rec.corrected" :label="CORRECTED_CHIP" :fg="CORRECTED_CHIP.fg" :bg="CORRECTED_CHIP.bg" />
        <Chip v-if="rec.inSheet" :label="IN_SHEET_CHIP" :fg="IN_SHEET_CHIP.fg" :bg="IN_SHEET_CHIP.bg" />
        <Chip v-if="rec.source === 'odoo'" :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
      </div>
      <div class="grid grid-cols-3 gap-2 max-[520px]:grid-cols-2">
        <div class="tile green"><div class="tile-v !text-[13px]">{{ fmtAt(rec.checkIn) }}</div><div class="tile-l">{{ t('الحضور', 'Check-in') }}</div></div>
        <div class="tile" :class="rec.open ? 'amber' : ''"><div class="tile-v !text-[13px]">{{ rec.checkOut ? fmtAt(rec.checkOut) : t('لم يُسجَّل', 'Not recorded') }}</div><div class="tile-l">{{ t('الانصراف', 'Check-out') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ hm(rec.workedMinutes, false) }}</div><div class="tile-l">{{ t('مدة العمل (ساعة)', 'Worked (h)') }}</div></div>
      </div>

      <div v-if="rec.corrected" class="hint">
        <b>{{ t('قبل أول تصحيح كان السجل', 'Before its first correction the record said') }}:</b>
        <span class="num ltr inline-block">{{ fmtAt(rec.originalCheckIn) }} → {{ fmtAt(rec.originalCheckOut) }}</span>
      </div>
      <div v-if="rec.status === 'void'" class="hint amber"><b>{{ t('أُلغي', 'Voided') }}</b> — <span dir="auto">{{ rec.voidedBy }}</span> · <span class="num ltr inline-block">{{ fmtAt(rec.voidedAt) }}</span><template v-if="rec.voidReason"> — <span dir="auto">{{ rec.voidReason }}</span></template></div>
      <div v-if="rec.inSheet && rec.status !== 'void'" class="hint teal">{{ t('هذا اليوم داخل كشف حضور مؤكَّد أو معتمد: يُعاد فتح الكشف أولًا ثم يُصحَّح السجل.', 'This day is inside a confirmed or approved sheet: reopen the sheet first, then correct the record.') }}</div>

      <div class="kv-grid mt-3">
        <KV :k="{ ar: 'طريقة الحضور', en: 'Check-in by' }" :v="rec.inMode || '—'" ltr />
        <KV :k="{ ar: 'طريقة الانصراف', en: 'Check-out by' }" :v="rec.outMode || '—'" ltr />
        <KV v-if="rec.note" :k="{ ar: 'ملاحظة', en: 'Note' }"><span dir="auto">{{ rec.note }}</span></KV>
      </div>

      <div class="card-title mb-1.5 mt-4">{{ t('سجل التغيير', 'Change log') }}</div>
      <div v-if="!rec.changes?.length" class="empty !p-3">{{ t('لم يُغيَّر هذا السجل.', 'This record was never changed.') }}</div>
      <div class="col !gap-1.5">
        <div v-for="(c, i) in rec.changes" :key="i" class="rounded-[12px] border border-line-2 px-3 py-2">
          <div class="flex flex-wrap items-center gap-2 text-[10.5px]">
            <b>{{ fieldName(c.field) }}</b>
            <span class="num ltr inline-block text-muted">{{ valueText(c, c.old) }}</span><span class="text-faint">→</span><span class="num ltr inline-block font-bold">{{ valueText(c, c.new) }}</span>
          </div>
          <div class="mt-1 text-[10.5px] text-ink" dir="auto">{{ c.reason }}</div>
          <div class="mt-0.5 text-[9.5px] text-muted"><span dir="auto">{{ c.by }}</span> · <span class="num ltr inline-block">{{ fmtAt(c.at) }}</span></div>
        </div>
      </div>
    </template>

    <template v-if="rec && (can.correct || can.void)" #footer>
      <div class="row wrap">
        <Btn v-if="can.correct" tone="dark" :label="{ ar: 'تصحيح', en: 'Correct' }" @click="correcting = true" />
        <span class="grow" />
        <Btn v-if="can.void" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء السجل', en: 'Void' }" @click="voidIt" />
      </div>
    </template>
  </Drawer>

  <AttendanceForm :open="correcting" :record="correcting ? rec : null" @close="correcting = false" />
</template>
