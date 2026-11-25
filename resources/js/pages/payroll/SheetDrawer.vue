<script setup>
// One attendance sheet: GET /payroll/sheets/{id|number} → the row (totals, payslip, can) + policy, confirmedBy/At,
// approvedBy/At, notes and `lines` [{ id, date, weekday, status, plannedIn/Out, actualIn/Out, plannedMinutes, workedMinutes,
// lateActual, late, earlyActual, early, overtimeActual, overtime, absenceMinutes, corrected, note, changes[] }].
//   draft ──confirm──▶ confirmed ──approve──▶ approved      reopen → draft, with a reason (never once its payslip is approved)
// «إعادة الحساب» POST …/recompute · «تأكيد» …/confirm · «اعتماد» …/approve (payroll people) · «إعادة فتح» …/reopen { reason }
// A day is picked in the table; a draft's day is corrected in SheetLineForm.
//   <SheetDrawer :number="numberOrNull" @close="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV } from '@/components';
import { t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import SheetLineForm from './SheetLineForm.vue';
import { CORRECTED_CHIP, FROM_ODOO, LINE_STATUS, RUN_STATE, SHEET_STATE, days, fmtAt, fmtDay, fmtPeriod, hm, lineStatus, pname, usePayrollNav, weekdayName } from './payroll';

const props = defineProps({
  number: { type: String, default: null },
});
const emit = defineEmits(['close']);

const nav = usePayrollNav();
const q = useGet(() => (props.number ? `/payroll/sheets/${encodeURIComponent(props.number)}` : null));
const sheet = computed(() => (props.number ? q.data.value || null : null));
const can = computed(() => sheet.value?.can || {});
const tot = computed(() => sheet.value?.totals || {});
const act = useAction({ invalidate: ['payroll'] });
const picked = ref(null);
const correcting = ref(null);
watch(() => props.number, () => { picked.value = null; correcting.value = null; act.clearError(); });
const line = computed(() => (sheet.value?.lines || []).find((l) => l.id === picked.value) || null);

const columns = [
  { key: 'date', header: { ar: 'اليوم', en: 'Day' }, width: '112px' },
  { key: 'status', header: { ar: 'النوع', en: 'Kind' }, width: '96px', bare: true },
  { key: 'planned', header: { ar: 'المخطط', en: 'Planned' }, width: '96px', kind: 'num', value: (l) => (l.plannedIn ? `${l.plannedIn}–${l.plannedOut}` : '—') },
  { key: 'actual', header: { ar: 'الفعلي', en: 'Actual' }, width: '96px', kind: 'num', value: (l) => (l.actualIn ? `${l.actualIn}–${l.actualOut || '…'}` : '—') },
  { key: 'worked', header: { ar: 'المدة', en: 'Worked' }, width: '60px', kind: 'num', value: (l) => hm(l.workedMinutes) },
  { key: 'late', header: { ar: 'تأخير', en: 'Late' }, width: '74px', kind: 'num' },
  { key: 'early', header: { ar: 'مبكر', en: 'Early' }, width: '74px', kind: 'num' },
  { key: 'overtime', header: { ar: 'إضافي', en: 'Overtime' }, width: '74px', kind: 'num' },
  { key: 'absence', header: { ar: 'غياب', en: 'Absent' }, width: '60px', kind: 'num', value: (l) => hm(l.absenceMinutes) },
];
/** "actual → counted" when the policy (or a person) changed the figure. */
const pair = (actual, counted) => (Number(actual || 0) === Number(counted || 0) ? hm(counted) : `${hm(actual, false)}→${hm(counted, false)}`);

async function step(what) {
  const s = sheet.value;
  const texts = {
    recompute: [{ ar: `إعادة حساب الكشف ${s.number} من السجلات الآن؟`, en: `Recompute ${s.number} from the records as they are now?` }, { ar: 'الأيام المصحَّحة تحتفظ بتصحيحها', en: 'Corrected days keep their correction' }, t('أُعيد حساب الكشف', 'Sheet recomputed')],
    confirm: [{ ar: `تأكيد الكشف ${s.number}؟`, en: `Confirm ${s.number}?` }, { ar: 'بعد التأكيد لا تُصحَّح سجلات هذه المدة إلا بإعادة فتحه', en: 'After that, the records of this period are corrected only by reopening it' }, t('أُكِّد الكشف', 'Sheet confirmed')],
    approve: [{ ar: `اعتماد الكشف ${s.number}؟`, en: `Approve ${s.number}?` }, { ar: 'أرقامه المعتمدة تدخل قسيمة راتب الشهر', en: 'Its approved figures go into the month’s payslip' }, t('اعتُمد الكشف', 'Sheet approved')],
  }[what];
  if (!(await confirm({ title: texts[0], sub: texts[1], tone: what === 'approve' ? 'primary' : 'dark' }))) return;
  await act.run(() => api.postIdempotent(`/payroll/sheets/${encodeURIComponent(s.id)}/${what}`), { success: texts[2] });
}
async function reopen() {
  const reason = await ask({ title: { ar: `إعادة فتح الكشف ${sheet.value.number}`, en: `Reopen ${sheet.value.number}` }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, okLabel: { ar: 'إعادة فتح', en: 'Reopen' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/payroll/sheets/${encodeURIComponent(sheet.value.id)}/reopen`, { reason }), { success: t('أُعيد فتح الكشف مسودة', 'Sheet reopened as a draft') });
}
const FIELD = { late: ['التأخير', 'Lateness'], early: ['الخروج المبكر', 'Early leave'], overtime: ['الإضافي', 'Overtime'] };
const fieldName = (f) => (FIELD[f] ? t(FIELD[f][0], FIELD[f][1]) : f);
</script>

<template>
  <Drawer :open="!!number" :width="860" :z-index="62" @close="emit('close')">
    <template #title><span dir="auto">{{ sheet ? `${t('كشف حضور', 'Attendance sheet')} — ${pname(sheet.employee)}` : number }}</span></template>
    <template #sub><span v-if="sheet"><span class="num ltr inline-block">{{ sheet.number }}</span> · <span class="num ltr inline-block">{{ fmtDay(sheet.dateFrom) }} – {{ fmtDay(sheet.dateTo) }}</span> · {{ fmtPeriod(sheet.period) }}</span></template>
    <template v-if="sheet" #headExtra><Chip :map="SHEET_STATE" :k="sheet.state" /></template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[260px]" />
    <template v-if="sheet">
      <div v-if="sheet.locked" class="hint purple !mt-0 mb-3">{{ t('هذا الكشف من أودو والقسم يتبعه: يُدار هناك.', 'This sheet came from Odoo and the section follows it: it is managed there.') }}</div>
      <div class="grid grid-cols-5 gap-2 max-[767px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ hm(tot.workedMinutes, false) }}</div><div class="tile-l">{{ t('عمل / مخطط', 'Worked / planned') }} <span class="num">{{ hm(tot.plannedMinutes, false) }}</span></div></div>
        <div class="tile amber"><div class="tile-v">{{ hm(tot.lateMinutes, false) }}</div><div class="tile-l">{{ t('تأخير يُحتسب', 'Late counted') }} · <span class="num">{{ tot.lateCount || 0 }}</span>×</div></div>
        <div class="tile amber"><div class="tile-v">{{ hm(tot.earlyMinutes, false) }}</div><div class="tile-l">{{ t('خروج مبكر يُحتسب', 'Early counted') }} · <span class="num">{{ tot.earlyCount || 0 }}</span>×</div></div>
        <div class="tile green"><div class="tile-v">{{ hm(tot.overtimeMinutes, false) }}</div><div class="tile-l">{{ t('إضافي يُحتسب', 'Overtime counted') }} · <span class="num">{{ tot.overtimeCount || 0 }}</span>×</div></div>
        <div class="tile red"><div class="tile-v">{{ tot.absenceCount || 0 }}</div><div class="tile-l">{{ t('أيام غياب', 'Days absent') }} · <span class="num">{{ hm(tot.absenceMinutes, false) }}</span></div></div>
      </div>
      <div class="mt-2 text-[10px] text-muted">
        {{ t('فعلي قبل السياسة', 'Actual before the policy') }}: {{ t('تأخير', 'late') }} <span class="num">{{ hm(tot.lateActualMinutes, false) }}</span> · {{ t('مبكر', 'early') }} <span class="num">{{ hm(tot.earlyActualMinutes, false) }}</span> · {{ t('إضافي', 'overtime') }} <span class="num">{{ hm(tot.overtimeActualMinutes, false) }}</span>
        · {{ t('إجازة', 'leave') }} <span class="num">{{ days(tot.leaveDays) }}</span> · {{ t('عطلة رسمية', 'holiday') }} <span class="num">{{ tot.holidayDays || 0 }}</span> · {{ t('عطلة أسبوعية', 'weekend') }} <span class="num">{{ tot.weekendDays || 0 }}</span>
      </div>

      <div class="kv-grid mt-3">
        <KV :k="{ ar: 'سياسة الحضور', en: 'Attendance policy' }"><span dir="auto">{{ sheet.policy ? pname(sheet.policy) : t('لا سياسة — الوقت الفعلي كما هو', 'No policy — actual time as it is') }}</span><span v-if="sheet.policy" class="text-[9.5px] text-muted"> · {{ t('سماح', 'grace') }} <span class="num">{{ sheet.policy.lateGraceMinutes }}/{{ sheet.policy.earlyGraceMinutes }}</span> {{ t('د', 'min') }}</span></KV>
        <KV :k="{ ar: 'الدفعة', en: 'Batch' }"><button v-if="sheet.batch" type="button" class="cell-id" @click="nav.open('batch', sheet.batch.number)">{{ sheet.batch.number }}</button><template v-else>—</template></KV>
        <KV :k="{ ar: 'القسيمة', en: 'Payslip' }"><button v-if="sheet.payslip" type="button" class="cell-id" @click="nav.open('slip', sheet.payslip.number)">{{ sheet.payslip.number }}</button><Chip v-if="sheet.payslip" small class="ms-1" :map="RUN_STATE" :k="sheet.payslip.state" /><template v-if="!sheet.payslip">—</template></KV>
        <KV v-if="sheet.confirmedBy" :k="{ ar: 'أكّده', en: 'Confirmed by' }"><span dir="auto">{{ sheet.confirmedBy }}</span> · <span class="num ltr">{{ fmtAt(sheet.confirmedAt) }}</span></KV>
        <KV v-if="sheet.approvedBy" :k="{ ar: 'اعتمده', en: 'Approved by' }"><span dir="auto">{{ sheet.approvedBy }}</span> · <span class="num ltr">{{ fmtAt(sheet.approvedAt) }}</span></KV>
        <KV v-if="sheet.source === 'odoo'" :k="{ ar: 'المصدر', en: 'Origin' }"><Chip small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" /></KV>
      </div>

      <div class="card-title mb-1.5 mt-4">{{ t('أيام الكشف', 'Days of the sheet') }} <span class="text-[10px] font-normal text-muted">— {{ t('اختر يومًا لرؤية تفاصيله وتصحيحه', 'pick a day for its details and correction') }}</span></div>
      <div class="card">
        <DataTable :columns="columns" :rows="sheet.lines" :row-key="(l) => l.id" :selected-key="picked" dense :zebra="false" :empty-text="{ ar: 'لا أيام في الكشف.', en: 'No day in the sheet.' }" @row-click="(l) => (picked = l.id)">
          <template #cell-date="{ row }"><span class="text-[10.5px]"><b>{{ weekdayName(row.weekday) }}</b> <span class="num ltr inline-block text-muted">{{ fmtDay(row.date) }}</span><span v-if="row.corrected" class="ms-1 font-bold text-violet" :title="t('مُصحَّح', 'Corrected')">✎</span></span></template>
          <template #cell-status="{ row }"><Chip small :map="LINE_STATUS" :k="lineStatus(row)" /></template>
          <template #cell-late="{ row }"><span class="num" :class="row.late ? 'font-bold text-warn' : ''">{{ pair(row.lateActual, row.late) }}</span></template>
          <template #cell-early="{ row }"><span class="num" :class="row.early ? 'font-bold text-warn' : ''">{{ pair(row.earlyActual, row.early) }}</span></template>
          <template #cell-overtime="{ row }"><span class="num" :class="row.overtime ? 'font-bold text-ok' : ''">{{ pair(row.overtimeActual, row.overtime) }}</span></template>
        </DataTable>
      </div>

      <div v-if="line" class="mt-3 rounded-[14px] border border-line-2 px-3.5 py-3">
        <div class="row wrap">
          <b class="text-[12px]">{{ weekdayName(line.weekday) }} <span class="num ltr inline-block">{{ fmtDay(line.date) }}</span></b>
          <Chip small :map="LINE_STATUS" :k="lineStatus(line)" />
          <Chip v-if="line.corrected" small :label="CORRECTED_CHIP" :fg="CORRECTED_CHIP.fg" :bg="CORRECTED_CHIP.bg" />
          <span class="grow" />
          <Btn v-if="can.correct" size="sm" tone="dark" :label="{ ar: 'تصحيح هذا اليوم', en: 'Correct this day' }" @click="correcting = line" />
        </div>
        <div v-if="line.note" class="mt-1.5 text-[10.5px] text-sec" dir="auto">{{ line.note }}</div>
        <div v-if="line.changes?.length" class="mt-2 col !gap-1">
          <div v-for="(c, i) in line.changes" :key="i" class="text-[10px] leading-[1.7]">
            <b>{{ fieldName(c.field) }}</b>: <span class="num ltr inline-block">{{ hm(c.old, false) }} → {{ hm(c.new, false) }}</span> — <span dir="auto">{{ c.reason }}</span>
            <span class="text-muted"> · <span dir="auto">{{ c.by }}</span> · <span class="num ltr inline-block">{{ fmtAt(c.at) }}</span></span>
          </div>
        </div>
        <div v-else class="mt-1.5 text-[10px] text-faint">{{ t('لم يُصحَّح هذا اليوم.', 'This day was never corrected.') }}</div>
      </div>
    </template>

    <template v-if="sheet && (can.recompute || can.confirm || can.approve || can.reopen)" #footer>
      <div class="row wrap">
        <Btn v-if="can.approve" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="step('approve')" />
        <Btn v-if="can.confirm" tone="primary" :loading="act.pending.value" :label="{ ar: 'تأكيد الكشف', en: 'Confirm' }" @click="step('confirm')" />
        <Btn v-if="can.recompute" tone="soft" :loading="act.pending.value" :label="{ ar: 'إعادة الحساب', en: 'Recompute' }" @click="step('recompute')" />
        <span class="grow" />
        <Btn v-if="can.reopen" tone="softAmber" :label="{ ar: 'إعادة فتح', en: 'Reopen' }" @click="reopen" />
      </div>
    </template>
  </Drawer>

  <SheetLineForm :sheet="sheet" :line="correcting" @close="correcting = null" />
</template>
