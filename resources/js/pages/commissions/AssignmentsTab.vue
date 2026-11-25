<script setup>
// Whose sale an invoice is (commission.manage). OPS documents carry no salesperson, so a person says it once:
//   customer → salesperson   the invoices of this customer are that user's sales;
//   rep name → user          the representative the sales platform writes on its orders is that OPS user.
// The calculation reads, in this order: the representative on the order, the customer's salesperson, the user who
// entered the order — and an invoice nobody answers for earns no salesperson a commission. Those are listed first here.
//   GET /commissions/unassigned?period=            the invoices of a month that are nobody's sale
//   GET /commissions/assignments?kind=&user=&q=    what was decided · PUT /commissions/assignments { kind, subject, user }
//   POST /commissions/assignments/{id}/remove      takes an answer back (the audit trail keeps what it was)
// It decides for the statements calculated from then on: a statement that is no longer a draft keeps what it recorded.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, PillChoice, SectionCard, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { confirm } from '@/stores/ui';
import CustomerPick from './CustomerPick.vue';
import { API, ASSIGN_KIND, SELECT_PLACEHOLDER, dayText, money, periodLabel, personName, recordName, sar, useCommissions, useSearchBox } from './commissions';

const { nav, lookups, userOptions, periods } = useCommissions();
const { text, type, apply } = useSearchBox(nav);
const act = useAction({ invalidate: ['commissions'] });

// ---- the invoices nobody answers for
const period = computed(() => nav.get('period') || lookups.value.currentPeriod);
const openQ = useGet(`${API}/unassigned`, () => ({ period: period.value }));
const open = computed(() => openQ.data.value || null);
const openColumns = [
  { key: 'number', header: { ar: 'الفاتورة', en: 'Invoice' }, width: '130px', kind: 'id' },
  { key: 'date', header: { ar: 'تاريخها', en: 'Date' }, width: '86px', kind: 'date', value: (i) => dayText(i.date) },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(150px,1.4fr)', kind: 'name', value: (i) => recordName(i.customer) },
  { key: 'rep', header: { ar: 'مندوب الطلب كما ورد', en: 'Rep on the order' }, width: 'minmax(120px,1fr)', class: 'text-[10px]', value: (i) => i.rep || '—' },
  { key: 'net', header: { ar: 'قبل الضريبة', en: 'Net' }, width: '100px', kind: 'num', value: (i) => money(i.net) },
  { key: 'act', header: '', width: '210px', bare: true },
];

// ---- what was decided
const list = useList(`${API}/assignments`, () => ({ q: nav.get('q'), kind: nav.get('kind'), user: nav.get('who'), page: nav.page.value, pageSize: 25, order: 'asc' }));
const d = computed(() => list.data.value || null);
const KINDS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'customer', label: ASSIGN_KIND.customer }, { k: 'rep', label: ASSIGN_KIND.rep }];
const columns = [
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '180px', bare: true },
  { key: 'label', header: { ar: 'العميل / اسم المندوب في المنصة', en: 'Customer / rep name' }, width: 'minmax(200px,1.6fr)', kind: 'name' },
  { key: 'user', header: { ar: 'مبيعاته تُحسب لـ', en: 'His sales count for' }, width: 'minmax(150px,1.2fr)', kind: 'name', value: (a) => personName(a.user) },
  { key: 'createdAt', header: { ar: 'سُجّل', en: 'Recorded' }, width: '150px', class: 'text-[9.5px] text-muted', value: (a) => `${a.createdBy || '—'} · ${fmtDate(a.createdAt)}` },
  { key: 'act', header: '', width: '90px', bare: true },
];
const filtered = computed(() => ['q', 'kind', 'who'].some((k) => !!nav.get(k)));

// ---- the form
const form = ref(null);   // { kind, customer?: { id, code, name }, rep: '', user: '' } | null
const start = (seed = {}) => { form.value = { kind: 'customer', customer: null, rep: '', user: '', ...seed }; act.clearError(); };
const fromInvoice = (i, kind) => start(kind === 'rep' ? { kind: 'rep', rep: i.rep || '' } : { kind: 'customer', customer: i.customer ? { id: i.customer.id, code: i.customer.code, name: recordName(i.customer) } : null });
watch(() => form.value?.kind, (kind, before) => { if (form.value && before && kind !== before) act.clearError(); });
const ok = computed(() => !!form.value && !!form.value.user && (form.value.kind === 'customer' ? !!form.value.customer : form.value.rep.trim().length > 0));
async function save() {
  const v = form.value;
  const r = await act.run(() => api.put(`${API}/assignments`, { kind: v.kind, subject: v.kind === 'customer' ? v.customer.id : v.rep.trim(), user: v.user }),
    { success: (a) => t(`سُجّل: ${a.label} ← ${personName(a.user)}`, `Recorded: ${a.label} → ${personName(a.user)}`) });
  if (r) form.value = null;
}
async function remove(a) {
  if (!(await confirm({ title: { ar: 'إزالة هذا الإسناد؟', en: 'Remove this answer?' }, sub: { ar: `${a.label} ← ${personName(a.user)} — الفواتير القادمة تعود لما سواه (مندوب الطلب أو مُدخله)، والكشوف المقفلة لا تتغير.`, en: `${a.label} → ${personName(a.user)} — coming invoices fall back to what else names their salesperson; closed statements do not change.` }, tone: 'danger', okLabel: { ar: 'إزالة', en: 'Remove' } }))) return;
  await act.run(() => api.postIdempotent(`${API}/assignments/${a.id}/remove`), { success: t('أُزيل الإسناد', 'Removed') });
}
const KIND_CHOICES = [['customer', { ar: 'عميل ← مندوب', en: 'Customer → salesperson' }], ['rep', { ar: 'اسم مندوب المنصة ← مستخدم', en: 'Platform rep name → user' }]];
</script>

<template>
  <ErrorBanner v-if="!form" :error="act.error.value" @close="act.clearError()" />

  <SectionCard :padded="false" class="mb-3" :title="{ ar: 'فواتير بلا مندوب معروف', en: 'Invoices with no known salesperson' }" :count="open ? open.count : null"
               :sub="open ? t(`${periodLabel(open.period)} — ${open.count} من ${open.invoices} فاتورة · ${money(open.net)} ${sar()} قبل الضريبة`, `${periodLabel(open.period)} — ${open.count} of ${open.invoices} invoices · ${money(open.net)} ${sar()} before tax`) : null">
    <template #actions>
      <SelectInput small field-class="w-[140px]" :model-value="period" :options="periods" @update:model-value="(v) => nav.set('period', v)" />
    </template>
    <ErrorBanner :error="openQ.error.value" :closable="false" class="mx-4" />
    <DataTable :columns="openColumns" :rows="open ? open.items : []" :loading="openQ.isLoading.value" :row-key="(i) => i.id" :page-size="8" dense
               :empty-text="{ ar: 'كل فواتير هذا الشهر لها مندوب معروف ✓', en: 'Every invoice of this month has a known salesperson ✓' }">
      <template #cell-act="{ row }">
        <div class="flex flex-wrap justify-center gap-1">
          <Btn v-if="row.customer" size="sm" tone="softBlue" class="!h-7 !text-[9px]" :label="{ ar: 'إسناد العميل', en: 'Assign the customer' }" @click="fromInvoice(row, 'customer')" />
          <Btn v-if="row.rep" size="sm" tone="softPurple" class="!h-7 !text-[9px]" :label="{ ar: 'ربط اسم المندوب', en: 'Link the rep name' }" @click="fromInvoice(row, 'rep')" />
        </div>
      </template>
    </DataTable>
  </SectionCard>

  <div class="row wrap mb-2.5 !items-end">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="{ ar: 'بحث بالعميل أو الاسم أو المندوب…', en: 'Search customer, name or salesperson…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="nav.get('kind')" :tabs="KINDS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.set('kind', k)" />
    <SelectInput small field-class="w-[200px]" :label="{ ar: 'المندوب', en: 'Salesperson' }" :model-value="nav.get('who')" :options="userOptions" :placeholder="{ ar: 'كل المندوبين', en: 'Every salesperson' }" @update:model-value="(v) => nav.set('who', v)" />
    <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة التصفية', en: 'Clear filters' }" @click="nav.patch({ q: null, kind: null, who: null })" />
    <span class="grow" />
    <Btn size="sm" tone="primary" :label="{ ar: '+ إسناد', en: '+ Assignment' }" @click="start()" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div v-if="d || !list.error.value" class="card">
    <DataTable :columns="columns" :paged="d" :loading="list.isFetching.value" :row-key="(a) => a.id"
               :empty-text="filtered ? { ar: 'لا إسناد يطابق هذا البحث.', en: 'No assignment matches.' } : { ar: 'لم يُسنَد عميل ولا اسم مندوب بعد. بدون ذلك تُحسب الفاتورة لمن أدخل طلبها في النظام فقط.', en: 'No customer or rep name assigned yet. Without it an invoice counts only for the user who entered its order.' }"
               @page="nav.setPage">
      <template #cell-kind="{ row }"><Chip :map="ASSIGN_KIND" :k="row.kind" small /></template>
      <template #cell-label="{ row }"><span class="cell-name" dir="auto">{{ row.label }}</span></template>
      <template #cell-act="{ row }"><Btn size="sm" tone="dangerOutline" class="!h-7 !text-[9px]" :loading="act.pending.value" :label="{ ar: 'إزالة', en: 'Remove' }" @click.stop="remove(row)" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('ترتيب المعرفة: مندوب الطلب كما يرد من منصة المبيعات (اسمٌ ربطته هنا بمستخدم، أو اسم دخول المستخدم نفسه) ← مندوب العميل ← مُدخل الطلب في النظام. تغيير الإسناد ينقل الفاتورة بين المسودات عند الاحتساب التالي؛ الكشف المقفل يحتفظ بما سجّله، ولا تُحتسب الفاتورة لمندوبَين.', 'Order of knowing: the representative on the order as the sales platform sends it (a name linked here to a user, or the user’s own login) → the customer’s salesperson → the user who entered the order. Changing an assignment moves the invoice between drafts at the next calculation; a closed statement keeps what it recorded, and an invoice is never counted for two salespeople.') }}</div>

  <Drawer :open="!!form" :width="500" :z-index="70" :title="{ ar: 'مبيعات من؟', en: 'Whose sales?' }" :sub="{ ar: 'يُسجَّل في سجل التدقيق؛ التسجيل مرة ثانية للعميل نفسه يستبدل السابق', en: 'Recorded in the audit trail; recording the same customer again replaces the earlier answer' }" @close="form = null">
    <div v-if="form" class="form-grid">
      <PillChoice v-model="form.kind" full :options="KIND_CHOICES" :label="{ ar: 'ما الذي تُسنده', en: 'What is assigned' }" />
      <template v-if="form.kind === 'customer'">
        <div v-if="form.customer" class="field full">
          <div class="field-l">{{ t('العميل', 'Customer') }}<span class="text-bad"> *</span></div>
          <span class="pill active max-w-full self-start"><span class="min-w-0 truncate" dir="auto">{{ form.customer.name }} · <span class="num ltr">{{ form.customer.code }}</span></span><button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-inherit" :aria-label="t('تغيير', 'Change')" @click="form.customer = null">✕</button></span>
        </div>
        <CustomerPick v-else required :label="{ ar: 'العميل', en: 'Customer' }" @pick="(c) => (form.customer = { id: c.id, code: c.code, name: recordName(c) })" />
      </template>
      <TextInput v-else v-model="form.rep" full required :label="{ ar: 'اسم المندوب كما يرد من منصة المبيعات', en: 'The rep’s name as the sales platform writes it' }" :hint="{ ar: 'يُقارَن دون اعتبار للمسافات الزائدة وحالة الأحرف', en: 'Compared without regard to extra spaces and letter case' }" />
      <SelectInput v-model="form.user" full required :label="{ ar: 'مبيعاته تُحسب للمستخدم', en: 'These sales count for the user' }" :options="userOptions" :placeholder="SELECT_PLACEHOLDER" />
    </div>
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!ok" :label="{ ar: 'تسجيل الإسناد', en: 'Record it' }" @click="save" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="form = null" />
      </div>
      <div v-if="!ok && !act.pending.value" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">{{ t('اختر العميل (أو اكتب اسم المندوب) والمستخدم لتفعيل الزر', 'Pick the customer (or type the rep name) and the user to enable the button') }}</div>
    </template>
  </Drawer>
</template>
