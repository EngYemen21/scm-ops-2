<script setup>
// Chart of accounts (tree with balances, posting roles), cost centers and bank accounts. Roles move between accounts
// here — the posting rules never reference account codes.
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ActionDrawer from '@/pages/procurement/ActionDrawer.vue';
import FilterBar from '@/pages/procurement/FilterBar.vue';
import { ACCOUNT_TYPE_LABELS, ACCOUNT_TYPE_OPTIONS, ROLE_LABELS, pname } from './shared';

const auth = useAuth();
const router = useRouter();
const sub = ref('coa');
const SUBS = [{ k: 'coa', label: { ar: 'دليل الحسابات', en: 'Chart of accounts' } }, { k: 'cc', label: { ar: 'مراكز التكلفة', en: 'Cost centers' } }, { k: 'banks', label: { ar: 'البنوك والصناديق', en: 'Banks & cash' } }];
const q = ref('');
const chartQ = useGet('/finance/accounts');
const ccQ = useGet('/finance/cost-centers', undefined, { enabled: () => sub.value === 'cc' });
const banksQ = useGet('/finance/banks', undefined, { enabled: () => sub.value === 'banks' });
const act = useAction({ invalidate: ['finance'] });
const accounts = computed(() => (chartQ.data.value?.items || []).filter((a) => !q.value || a.code.includes(q.value) || a.nameAr.includes(q.value) || (a.nameEn || '').toLowerCase().includes(q.value.toLowerCase())));
const roleOpts = computed(() => [{ v: '', l: { ar: 'بلا دور', en: 'No role' } }, ...(chartQ.data.value?.roles || []).map((r) => ({ v: r, l: `${r} — ${ROLE_LABELS[r] || r}` }))]);
const headerOpts = computed(() => (chartQ.data.value?.items || []).filter((a) => !a.isPostable).map((a) => ({ v: a.code, l: `${a.code} · ${pname(a)}` })));

/** { mode: 'new' | 'edit', account? } | null */
const form = ref(null);
const f = ref({});
watch(form, (v) => {
  if (!v) return;
  const a = v.account;
  f.value = a ? { code: a.code, nameAr: a.nameAr, nameEn: a.nameEn, type: a.type, subtype: a.subtype || '', parent: (chartQ.data.value?.items || []).find((x) => x.id === a.parentId)?.code || '', isPostable: a.isPostable ? 'yes' : 'no', role: a.role || '', active: a.active ? 'yes' : 'no' }
    : { code: '', nameAr: '', nameEn: '', type: 'expense', subtype: '', parent: '', isPostable: 'yes', role: '', active: 'yes' };
  act.clearError();
});
const ok = computed(() => f.value.code?.trim().length >= 3 && f.value.nameAr?.trim().length > 0 && !!f.value.type);
async function save() {
  const v = f.value;
  const body = { nameAr: v.nameAr.trim(), nameEn: v.nameEn || undefined, type: v.type, subtype: v.subtype || null, parent: v.parent || null, isPostable: v.isPostable === 'yes', role: v.role || null };
  const r = await act.run(() => (form.value.mode === 'edit' ? api.patch(`/finance/accounts/${form.value.account.code}`, { ...body, active: v.active === 'yes' }) : api.postIdempotent('/finance/accounts', { code: v.code.trim(), ...body })),
    { success: t('حُفظ الحساب', 'Account saved') });
  if (r) { form.value = null; chartQ.refetch(); }
}
const cc = ref(null);
const ccf = ref({ code: '', nameAr: '', nameEn: '', kind: 'admin', vehicle: '' });
async function saveCc() {
  const r = await act.run(() => api.postIdempotent('/finance/cost-centers', { ...ccf.value, nameEn: ccf.value.nameEn || undefined, vehicle: ccf.value.vehicle || undefined }), { success: t('أُضيف مركز التكلفة', 'Cost center added') });
  if (r) { cc.value = null; ccQ.refetch(); }
}
const bank = ref(null);
const bf = ref({ code: '', nameAr: '', nameEn: '', kind: 'bank', bankName: '', iban: '' });
async function saveBank() {
  const r = await act.run(() => api.postIdempotent('/finance/banks', { ...bf.value, nameEn: bf.value.nameEn || undefined, bankName: bf.value.bankName || undefined, iban: bf.value.iban || undefined }), { success: t('أُضيف الحساب البنكي (وحساب الأستاذ المقابل)', 'Bank account added (with its GL account)') });
  if (r) { bank.value = null; banksQ.refetch(); chartQ.refetch(); }
}
const columns = [
  { key: 'code', header: { ar: 'الكود', en: 'Code' }, width: '80px', kind: 'id' },
  { key: 'name', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(220px,2fr)' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '90px', class: 'text-[10px]', value: (a) => t(ACCOUNT_TYPE_LABELS[a.type]?.ar, ACCOUNT_TYPE_LABELS[a.type]?.en) },
  { key: 'role', header: { ar: 'الدور المحاسبي', en: 'Posting role' }, width: 'minmax(140px,1.2fr)' },
  { key: 'balance', header: { ar: 'الرصيد', en: 'Balance' }, width: '110px', kind: 'num', value: (a) => (a.isPostable ? fmtMoney(a.balance) : '') },
  { key: 'act', header: '', width: '120px' },
];
</script>

<template>
  <Tabs v-model="sub" :tabs="SUBS" variant="pill" class="!mb-2" />
  <ErrorBanner :error="chartQ.error.value || act.error.value" @close="act.clearError()" />

  <template v-if="sub === 'coa'">
    <FilterBar v-model="q"><span class="grow" /><Btn v-if="auth.can('coa.manage')" size="sm" tone="primary" :label="{ ar: '+ حساب', en: '+ Account' }" @click="form = { mode: 'new' }" /></FilterBar>
    <div class="card">
      <DataTable :columns="columns" :rows="accounts" :loading="chartQ.isLoading.value" :row-key="(a) => a.id" :min-width="760" @row-click="(a) => a.isPostable && router.push(`/finance?tab=reports&report=ledger&account=${a.code}`)">
        <template #cell-name="{ row }">
          <div :style="{ paddingInlineStart: `${row.depth * 14}px` }" :class="row.isPostable ? 'text-[10.5px]' : 'text-[11px] font-extrabold'">
            {{ pname(row) }} <Chip v-if="!row.active" small fg="#7d7990" bg="#F1EFF6" :label="{ ar: 'موقوف', en: 'Inactive' }" />
          </div>
        </template>
        <template #cell-role="{ row }"><Chip v-if="row.role" small fg="#654e92" bg="#efeaf8" :label="ROLE_LABELS[row.role] || row.role" /></template>
        <template #cell-act="{ row }"><Btn v-if="auth.can('coa.manage')" size="sm" tone="soft" class="!h-7 !text-[9px]" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="form = { mode: 'edit', account: row }" /></template>
      </DataTable>
    </div>
    <div class="hint">{{ t('قواعد الترحيل لا تذكر أرقام الحسابات: كل قاعدة تطلب «دورًا» (ذمم العملاء، المخزون، ضريبة مخرجات…) والدور مرتبط بحساب واحد في الدليل. لتغيير الحساب الذي تُرحَّل إليه عملية، انقل الدور إلى حساب آخر. الحساب الذي عليه قيود لا يتغير نوعه ولا يصبح رئيسيًا.', 'Posting rules never name account codes: each asks for a role (receivables, inventory, VAT output…) mapped to one account. To change where an event posts, move the role. Posted accounts cannot change type or become headers.') }}</div>
  </template>

  <template v-else-if="sub === 'cc'">
    <FilterBar v-model="q"><span class="grow" /><Btn v-if="auth.can('coa.manage')" size="sm" tone="primary" :label="{ ar: '+ مركز تكلفة', en: '+ Cost center' }" @click="cc = {}; ccf = { code: '', nameAr: '', nameEn: '', kind: 'admin', vehicle: '' }" /></FilterBar>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-2">
      <div v-for="c in ccQ.data.value?.items || []" :key="c.id" class="tile"><div class="text-[11px] font-extrabold">{{ pname(c) }}</div><div class="num text-[9px] text-faint">{{ c.code }} · {{ c.kind }}</div></div>
    </div>
  </template>

  <template v-else>
    <FilterBar v-model="q"><span class="grow" /><Btn v-if="auth.can('bank.manage')" size="sm" tone="primary" :label="{ ar: '+ حساب بنكي / صندوق', en: '+ Bank / cash account' }" @click="bank = {}; bf = { code: '', nameAr: '', nameEn: '', kind: 'bank', bankName: '', iban: '' }" /></FilterBar>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-2">
      <div v-for="b in banksQ.data.value?.items || []" :key="b.id" class="tile" :class="b.kind === 'cash' ? 'green' : 'purple'">
        <div class="row"><span class="text-[11px] font-extrabold">{{ pname(b) }}</span><span class="grow" /><span class="num text-[9px] text-faint">{{ b.code }}</span></div>
        <div class="tile-v mt-1">{{ fmtMoney(b.balance) }} <span class="font-sans text-[9px] text-faint">{{ t('ر.س', 'SAR') }}</span></div>
        <div class="num text-[9px] text-muted">{{ b.account?.code }} · {{ b.bankName || '' }} {{ b.iban || t('(بلا IBAN بعد)', '(no IBAN yet)') }}</div>
      </div>
    </div>
  </template>

  <ActionDrawer :open="!!form" :title="form?.mode === 'edit' ? { ar: `تعديل الحساب ${form.account.code}`, en: `Edit account ${form.account.code}` } : { ar: 'حساب جديد', en: 'New account' }" :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'حفظ', en: 'Save' }" @submit="save" @close="form = null" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.code" :label="{ ar: 'الكود', en: 'Code' }" required dir="ltr" mono :disabled="form?.mode === 'edit'" />
      <SelectInput v-model="f.type" :label="{ ar: 'النوع', en: 'Type' }" required :options="ACCOUNT_TYPE_OPTIONS" />
      <TextInput v-model="f.nameAr" :label="{ ar: 'الاسم (عربي)', en: 'Name (Arabic)' }" required />
      <TextInput v-model="f.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" />
      <SelectInput v-model="f.parent" :label="{ ar: 'الحساب الأب', en: 'Parent' }" :options="headerOpts" :placeholder="{ ar: '—', en: '—' }" />
      <TextInput v-model="f.subtype" :label="{ ar: 'النوع الفرعي (receivable, payable, bank, cash, cogs, opex…)', en: 'Subtype' }" dir="ltr" />
      <SelectInput v-model="f.isPostable" :label="{ ar: 'قابل للترحيل', en: 'Postable' }" :options="[['yes', { ar: 'نعم', en: 'Yes' }], ['no', { ar: 'لا — حساب رئيسي', en: 'No — header' }]]" />
      <SelectInput v-model="f.role" :label="{ ar: 'الدور المحاسبي', en: 'Posting role' }" :options="roleOpts" />
      <SelectInput v-if="form?.mode === 'edit'" v-model="f.active" :label="{ ar: 'الحالة', en: 'Status' }" :options="[['yes', { ar: 'نشط', en: 'Active' }], ['no', { ar: 'موقوف', en: 'Inactive' }]]" />
    </div>
  </ActionDrawer>
  <ActionDrawer :open="!!cc" :title="{ ar: 'مركز تكلفة جديد', en: 'New cost center' }" :pending="act.pending.value" :error="act.error.value" :disabled="!(ccf.code && ccf.nameAr)" @submit="saveCc" @close="cc = null" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="ccf.code" :label="{ ar: 'الكود', en: 'Code' }" required dir="ltr" mono />
      <SelectInput v-model="ccf.kind" :label="{ ar: 'النوع', en: 'Kind' }" :options="[['admin', { ar: 'إدارة', en: 'Admin' }], ['sales', { ar: 'مبيعات', en: 'Sales' }], ['fleet', { ar: 'أسطول', en: 'Fleet' }], ['vehicle', { ar: 'مركبة', en: 'Vehicle' }]]" />
      <TextInput v-model="ccf.nameAr" :label="{ ar: 'الاسم (عربي)', en: 'Name (Arabic)' }" required />
      <TextInput v-model="ccf.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" />
      <TextInput v-if="ccf.kind === 'vehicle'" v-model="ccf.vehicle" :label="{ ar: 'كود المركبة', en: 'Vehicle code' }" dir="ltr" />
    </div>
  </ActionDrawer>
  <ActionDrawer :open="!!bank" :title="{ ar: 'حساب بنكي / صندوق جديد', en: 'New bank / cash account' }" :sub="{ ar: 'يُنشأ له حساب أستاذ خاص تحت الأصول المتداولة', en: 'Gets its own GL account under current assets' }" :pending="act.pending.value" :error="act.error.value" :disabled="!(bf.code && bf.nameAr)" @submit="saveBank" @close="bank = null" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="bf.code" :label="{ ar: 'الكود', en: 'Code' }" required dir="ltr" mono placeholder="RAJHI" />
      <SelectInput v-model="bf.kind" :label="{ ar: 'النوع', en: 'Kind' }" :options="[['bank', { ar: 'بنك', en: 'Bank' }], ['cash', { ar: 'صندوق', en: 'Cash box' }]]" />
      <TextInput v-model="bf.nameAr" :label="{ ar: 'الاسم (عربي)', en: 'Name (Arabic)' }" required />
      <TextInput v-model="bf.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" />
      <TextInput v-if="bf.kind === 'bank'" v-model="bf.bankName" :label="{ ar: 'البنك', en: 'Bank' }" />
      <TextInput v-if="bf.kind === 'bank'" v-model="bf.iban" label="IBAN" dir="ltr" mono />
    </div>
  </ActionDrawer>
</template>
