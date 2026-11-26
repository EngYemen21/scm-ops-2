<script setup>
// The bank transfer listing of an approved run — the ONE place of the section where bank account numbers are shown
// (payroll.manage; every build is written to the audit trail). GET /payroll/runs/{id}/transfer → { run, rows: [{ payslip,
// employee, method, bankName, bankAccount, amount, currency, ready }], totals { bank, cash, missingBank, nothingToPay } }.
// It is fetched when the drawer is opened by its button, held in this component only while it is open (not in the URL,
// not in the query cache) and dropped when it closes. «تنزيل CSV» fetches GET …/transfer.csv with the bearer token.
//   <TransferDrawer :run="runOrNull" @close />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner } from '@/components';
import { t } from '@/i18n';
import { PAY_METHOD, downloadFile, money, pname } from './payroll';

const props = defineProps({
  run: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: false });
const dl = useAction({ invalidate: false });
const listing = ref(null);
watch(() => props.run?.id, async (id) => {
  listing.value = null;
  act.clearError();
  dl.clearError();
  if (!id) return;
  const r = await act.run(() => api.get(`/payroll/runs/${encodeURIComponent(id)}/transfer`), { success: false });
  if (r && props.run?.id === id) listing.value = r;
}, { immediate: true });
function close() { listing.value = null; emit('close'); }
const download = () => dl.run(() => downloadFile(`/payroll/runs/${encodeURIComponent(props.run.id)}/transfer.csv`, `salaries-${props.run.period}-${props.run.number}.csv`), { success: (name) => t(`نُزِّل ${name}`, `Downloaded ${name}`) });

const tot = computed(() => listing.value?.totals || null);
const columns = [
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(150px,1.4fr)' },
  { key: 'method', header: { ar: 'الطريقة', en: 'Method' }, width: '100px', bare: true },
  { key: 'bank', header: { ar: 'البنك والحساب', en: 'Bank & account' }, width: 'minmax(190px,1.6fr)' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '104px', kind: 'num', value: (r) => money(r.amount) },
];
</script>

<template>
  <Drawer :open="!!run" :width="760" :z-index="70" :title="{ ar: 'ملف التحويل البنكي', en: 'Bank transfer listing' }" :sub="run ? `${run.number} · ${run.period}` : null" @close="close">
    <div class="hint amber !mt-0 mb-3">{{ t('أرقام حسابات الموظفين البنكية: لا تُشارَك ولا تُصوَّر، وكل فتح لهذا الملف يُسجَّل في سجل التدقيق. الملف CSV بسيط يُرفع لبوابة البنك أو يُدخل منه — ليس صيغة ملف الرواتب الخاصة بكل بنك أو نظام حماية الأجور.', 'Employees’ bank account numbers: do not share or photograph them; every opening of this listing is recorded in the audit trail. The file is a plain CSV to upload to the bank’s portal or key from — not the bank’s own salary-file or wage-protection format.') }}</div>
    <ErrorBanner :error="act.error.value || dl.error.value" @close="act.clearError(); dl.clearError()" />
    <div v-if="act.pending.value" class="skel h-[200px]" />
    <template v-if="listing">
      <div class="grid grid-cols-4 gap-2 max-[520px]:grid-cols-2">
        <div class="tile green"><div class="tile-v">{{ money(tot.bank.amount) }}</div><div class="tile-l">{{ t('بالتحويل', 'By transfer') }} · <span class="num">{{ tot.bank.count }}</span></div></div>
        <div class="tile amber"><div class="tile-v">{{ money(tot.cash.amount) }}</div><div class="tile-l">{{ t('نقدًا', 'In cash') }} · <span class="num">{{ tot.cash.count }}</span></div></div>
        <div class="tile red"><div class="tile-v">{{ money(tot.missingBank.amount) }}</div><div class="tile-l">{{ t('بلا حساب بنكي', 'No bank account') }} · <span class="num">{{ tot.missingBank.count }}</span></div></div>
        <div class="tile"><div class="tile-v">{{ tot.nothingToPay }}</div><div class="tile-l">{{ t('لا صافي لصرفه', 'Nothing to pay') }}</div></div>
      </div>
      <div v-if="tot.missingBank.count" class="hint amber">{{ t('من يُصرف له بالتحويل ولا حساب بنكي في ملفه لا يدخل ملف CSV: أضِف حسابه في «الموظفون» أو اجعل صرفه نقدًا من «ملفات الرواتب».', 'Someone paid by transfer with no bank account on file is not in the CSV: add the account in People, or set cash in the pay profiles.') }}</div>
      <div class="card mt-3">
        <DataTable :columns="columns" :rows="listing.rows" :page-size="50" :row-key="(r) => r.payslip" :empty-text="{ ar: 'لا صافي لصرفه في هذا المسير.', en: 'Nothing to pay in this run.' }">
          <template #cell-employee="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }} · {{ row.payslip }}</div></div></template>
          <template #cell-method="{ row }"><Chip small :map="PAY_METHOD" :k="row.method" /></template>
          <template #cell-bank="{ row }">
            <div v-if="row.method === 'bank'" class="min-w-0">
              <div class="truncate text-[10.5px]" dir="auto">{{ row.bankName || '—' }}</div>
              <div v-if="row.bankAccount" class="num ltr truncate text-[10.5px] font-bold">{{ row.bankAccount }}</div>
              <div v-else class="text-[10px] font-bold text-bad">{{ t('لا حساب بنكي', 'No bank account') }}</div>
            </div>
            <span v-else class="text-[10px] text-muted">{{ t('يُسلَّم نقدًا', 'Handed in cash') }}</span>
          </template>
        </DataTable>
      </div>
    </template>
    <template #footer>
      <div class="row wrap">
        <Btn tone="dark" :disabled="!listing || !tot?.bank.count" :loading="dl.pending.value" :label="{ ar: 'تنزيل CSV للتحويل البنكي', en: 'Download the bank CSV' }" @click="download" />
        <span class="grow" />
        <Btn tone="soft" :label="{ ar: 'إغلاق', en: 'Close' }" @click="close" />
      </div>
    </template>
  </Drawer>
</template>
