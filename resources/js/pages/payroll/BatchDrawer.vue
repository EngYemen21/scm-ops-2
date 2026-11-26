<script setup>
// One batch of sheets: GET /payroll/sheet-batches/{id|number} → the row + notes, skipped [{ employee, reason }] and
// `sheets` (rows as the sheet list shows them). The one next step is the row's `next`:
//   generate  POST …/generate — a sheet for everyone at work with a contract in the period and no sheet yet (attendance.manage)
//   submit    POST …/submit   — every draft sheet confirmed, all or none (attendance.manage)
//   close     POST …/close    — every confirmed sheet approved (payroll people)
// A sheet row opens the sheet above the batch.
//   <BatchDrawer :number="numberOrNull" @close="…" />
import { computed, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { BATCH_STATE, SHEET_STATE, fmtAt, fmtDay, hm, pname, skipText, usePayrollNav, usePayrollPerms } from './payroll';

const props = defineProps({
  number: { type: String, default: null },
});
const emit = defineEmits(['close']);

const nav = usePayrollNav();
const perms = usePayrollPerms();
const q = useGet(() => (props.number ? `/payroll/sheet-batches/${encodeURIComponent(props.number)}` : null));
const batch = computed(() => (props.number ? q.data.value || null : null));
const act = useAction({ invalidate: ['payroll'] });
watch(() => props.number, () => act.clearError());

const NEXT = {
  generate: { label: { ar: 'إنشاء الكشوف', en: 'Generate the sheets' }, ask: { ar: 'إنشاء كشف لكل موظف له عقد سارٍ في المدة ولا كشف له فيها؟', en: 'Make a sheet for everyone with a contract in the period and no sheet in it?' }, done: ['أُنشئت كشوف الدفعة', 'Sheets generated'] },
  submit: { label: { ar: 'تأكيد كل الكشوف', en: 'Confirm every sheet' }, ask: { ar: 'تأكيد كل كشوف الدفعة المسودة؟ تُؤكَّد كلها أو لا شيء', en: 'Confirm every draft sheet of the batch? All of them or none' }, done: ['أُكِّدت كشوف الدفعة', 'Sheets confirmed'] },
  close: { label: { ar: 'اعتماد الكشوف وإغلاق الدفعة', en: 'Approve the sheets and close' }, ask: { ar: 'اعتماد كل كشوف الدفعة المؤكَّدة وإغلاقها؟', en: 'Approve every confirmed sheet and close the batch?' }, done: ['أُغلقت الدفعة واعتُمدت كشوفها', 'Batch closed, sheets approved'] },
};
const next = computed(() => {
  const b = batch.value;
  if (!b || b.locked || !b.next) return null;
  const allowed = b.next === 'close' ? perms.value.pay : perms.value.attendanceManage;
  return allowed ? { key: b.next, ...NEXT[b.next] } : null;
});
async function advance() {
  const n = next.value;
  if (!(await confirm({ title: n.ask, tone: n.key === 'close' ? 'primary' : 'dark', okLabel: n.label }))) return;
  await act.run(() => api.postIdempotent(`/payroll/sheet-batches/${encodeURIComponent(batch.value.id)}/${n.key}`), { success: t(n.done[0], n.done[1]) });
}
const columns = [
  { key: 'number', header: { ar: 'الكشف', en: 'Sheet' }, width: '112px', kind: 'id' },
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(140px,1.5fr)', value: (r) => pname(r.employee) },
  { key: 'late', header: { ar: 'تأخير', en: 'Late' }, width: '60px', kind: 'num', value: (r) => hm(r.totals?.lateMinutes) },
  { key: 'overtime', header: { ar: 'إضافي', en: 'Overtime' }, width: '64px', kind: 'num', value: (r) => hm(r.totals?.overtimeMinutes) },
  { key: 'absence', header: { ar: 'غياب', en: 'Absent' }, width: '56px', kind: 'num', value: (r) => r.totals?.absenceCount || '—' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '104px', bare: true },
];
</script>

<template>
  <Drawer :open="!!number" :width="720" @close="emit('close')">
    <template #title><span dir="auto">{{ batch ? batch.name || batch.number : number }}</span></template>
    <template #sub><span v-if="batch"><span class="num ltr inline-block">{{ batch.number }}</span> · {{ batch.department ? pname(batch.department) : t('كل الأقسام', 'Every department') }} · <span class="num ltr inline-block">{{ fmtDay(batch.dateFrom) }} – {{ fmtDay(batch.dateTo) }}</span></span></template>
    <template v-if="batch" #headExtra><Chip :map="BATCH_STATE" :k="batch.state" /></template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="batch">
      <div class="grid grid-cols-4 gap-2 max-[520px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ batch.sheetCount }}</div><div class="tile-l">{{ t('كشوف', 'Sheets') }}</div></div>
        <div class="tile"><div class="tile-v">{{ batch.sheetsByState.draft }}</div><div class="tile-l">{{ t('مسودة', 'Draft') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ batch.sheetsByState.confirmed }}</div><div class="tile-l">{{ t('مؤكَّدة', 'Confirmed') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ batch.sheetsByState.approved }}</div><div class="tile-l">{{ t('معتمدة', 'Approved') }}</div></div>
      </div>
      <div class="kv-grid mt-3">
        <KV :k="{ ar: 'أنشأها', en: 'Made by' }"><span dir="auto">{{ batch.createdBy || '—' }}</span> · <span class="num ltr">{{ fmtAt(batch.createdAt) }}</span></KV>
        <KV v-if="batch.notes" :k="{ ar: 'ملاحظات', en: 'Notes' }"><span dir="auto">{{ batch.notes }}</span></KV>
      </div>
      <div v-if="batch.skipped?.length" class="hint amber">
        <b>{{ t(`لم يُنشأ كشف لـ ${batch.skipped.length}`, `${batch.skipped.length} got no sheet`) }}:</b>
        <ul class="mt-1 list-disc ps-5">
          <li v-for="(s, i) in batch.skipped" :key="i"><span class="num ltr inline-block">{{ s.employee }}</span> — {{ skipText(s.reason) }}</li>
        </ul>
      </div>
      <div class="card-title mb-1.5 mt-4">{{ t('كشوف الدفعة', 'Sheets of the batch') }}</div>
      <div class="card">
        <DataTable :columns="columns" :rows="batch.sheets" :page-size="25" :row-key="(r) => r.number" :selected-key="nav.get('sheet') || null"
                   :empty-text="batch.state === 'draft' ? { ar: 'لم تُنشأ الكشوف بعد — «إنشاء الكشوف».', en: 'No sheet yet — “Generate the sheets”.' } : { ar: 'لا كشوف.', en: 'No sheet.' }" @row-click="(r) => nav.open('sheet', r.number)">
          <template #cell-state="{ row }"><Chip small :map="SHEET_STATE" :k="row.state" /></template>
        </DataTable>
      </div>
    </template>

    <template v-if="next" #footer>
      <div class="row wrap"><span class="grow" /><Btn tone="primary" :loading="act.pending.value" :label="next.label" @click="advance" /></div>
    </template>
  </Drawer>
</template>
