<script setup>
// The posting map — which account of the chart each kind of payroll amount goes to: GET /payroll/accounts → { items: [{ key,
// side expense|liability, nameAr, nameEn, accountCode, account { code, nameAr, nameEn, type }, ready }] }.
// «حفظ» PUT /payroll/accounts { accounts: { key: code | null } } with the keys that changed. The server checks each account
// (in the chart, postable, active, not a customer / supplier control account, on the right side) and names what it refuses.
// A run is not posted while a key it needs has no account (PAYROLL_ACCOUNT_UNMAPPED).
import { computed, reactive, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, TextInput } from '@/components';
import { t } from '@/i18n';
import { ACCOUNT_SIDE, TONE, pname } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const q = useGet('/payroll/accounts');
const items = computed(() => q.data.value?.items || []);
const codes = reactive({});
watch(items, (rows) => { for (const r of rows) codes[r.key] = r.accountCode || ''; }, { immediate: true });
const changed = computed(() => items.value.filter((r) => (codes[r.key] || '').trim() !== (r.accountCode || '')));
const act = useAction({ invalidate: ['payroll'] });
async function save() {
  if (!changed.value.length) return;
  const accounts = Object.fromEntries(changed.value.map((r) => [r.key, (codes[r.key] || '').trim() || null]));
  await act.run(() => api.put('/payroll/accounts', { accounts }), { success: t('حُفظ التوجيه المحاسبي', 'Posting map saved') });
}
const reset = () => { for (const r of items.value) codes[r.key] = r.accountCode || ''; act.clearError(); };
const READY = { ar: 'جاهز', en: 'Ready', ...TONE.ok };
const NOT_READY = { ar: 'غير جاهز', en: 'Not ready', ...TONE.bad };
</script>

<template>
  <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
  <div v-if="q.isLoading.value" class="skel h-[200px]" />
  <div v-else class="card">
    <div class="bgrid hidden grid-cols-[minmax(180px,1.4fr)_90px_150px_minmax(160px,1.2fr)_90px] gap-3 border-b border-line-2 px-3.5 py-2 text-[10px] font-extrabold text-faint md:grid">
      <span>{{ t('البند', 'Key') }}</span><span>{{ t('الجهة', 'Side') }}</span><span>{{ t('رمز الحساب', 'Account code') }}</span><span>{{ t('الحساب في الدليل', 'Account in the chart') }}</span><span />
    </div>
    <div v-for="r in items" :key="r.key" class="grid items-center gap-3 border-b border-line-2 px-3.5 py-2.5 last:border-b-0 md:grid-cols-[minmax(180px,1.4fr)_90px_150px_minmax(160px,1.2fr)_90px]">
      <div class="min-w-0"><div class="text-[11.5px] font-extrabold" dir="auto">{{ pname(r) }}</div><div class="cell-sub">{{ r.key }}</div></div>
      <div><Chip small :map="ACCOUNT_SIDE" :k="r.side" /></div>
      <TextInput v-model="codes[r.key]" small dir="ltr" :disabled="!canEdit" :placeholder="{ ar: 'رمز الحساب', en: 'Account code' }" :aria-label="pname(r)" />
      <div class="min-w-0 truncate text-[10.5px]" dir="auto">{{ r.account ? pname(r.account) : r.accountCode ? t('غير موجود في الدليل', 'Not in the chart') : '—' }}</div>
      <div><Chip small :label="r.ready ? READY : NOT_READY" :fg="(r.ready ? READY : NOT_READY).fg" :bg="(r.ready ? READY : NOT_READY).bg" /></div>
    </div>
  </div>
  <div v-if="canEdit" class="row mt-2.5">
    <span class="text-[10px] text-muted">{{ changed.length ? t(`${changed.length} بند تغيّر`, `${changed.length} key(s) changed`) : t('لا تغيير', 'No change') }}</span>
    <span class="grow" />
    <Btn size="sm" tone="soft" :disabled="!changed.length" :label="{ ar: 'تراجع', en: 'Undo' }" @click="reset" />
    <Btn size="sm" tone="dark" :disabled="!changed.length" :loading="act.pending.value" :label="{ ar: 'حفظ التوجيه', en: 'Save the map' }" @click="save" />
  </div>
  <div class="hint">{{ t('بنود المصروف تُرحَّل مدينة (الرواتب، البدلات، الإضافي، حصة المنشأة في التأمينات)، وبنود الالتزام دائنة (الرواتب المستحقة، التأمينات المستحقة، الاستقطاعات، تعويضات المصروفات). بند راتب له حساب خاص يتقدّم على هذا التوجيه. اترك الرمز فارغًا لإزالة الربط.', 'Expense keys are posted as debits (salaries, allowances, overtime, the employer’s GOSI share); liability keys as credits (salaries payable, GOSI payable, deductions, reimbursements). A pay component with its own account wins over this map. Leave a code empty to unmap it.') }}</div>
</template>
