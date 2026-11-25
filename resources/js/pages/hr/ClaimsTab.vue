<script setup>
// Expense claims: GET /hr/claims?scope=mine|review|all&state=&q=&from=&to=&source=&sort=&order=&page=
//   mine    the person's own claims (their expenses, or entered by them)             expense.claim
//   review  what waits for a person here: a decision, the posting, the payment        expense.review
//   all     every claim, Odoo's history included                                      expense.review
// Scope, state, dates, search, sort and page live in the query string; a row opens the claim (`claim=<number>`).
// «+ مطالبة» writes a claim (a reviewer may enter one for an employee who has no login); the reviewers keep the
// expense categories and the account each one is charged to.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CategoriesDrawer from './CategoriesDrawer.vue';
import ClaimForm from './ClaimForm.vue';
import { CLAIM_STATE, CLAIM_STATES, FROM_ODOO, claimOwner, claimOwnerCode, fmtDay, money, optionsOf, useHrNav, useSearchParam } from './shared';

const props = defineProps({
  /** GET /hr/summary — the counts on the scope pills. */
  summary: { type: Object, default: null },
});

const auth = useAuth();
const nav = useHrNav();
const { page } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const canClaim = computed(() => auth.can('expense.claim'));
const canReview = computed(() => auth.can('expense.review'));

const scopes = computed(() => [
  ...(canClaim.value || canReview.value ? [{ k: 'mine', label: { ar: 'مطالباتي', en: 'My claims' } }] : []),
  ...(canReview.value ? [{ k: 'review', label: { ar: 'تنتظر إجراءً', en: 'Waiting for action' }, badge: props.summary?.claims?.review ? props.summary.claims.review.waiting + props.summary.claims.review.toPost + props.summary.claims.review.toPay : null },
    { k: 'all', label: { ar: 'كل المطالبات', en: 'All claims' } }] : []),
]);
const scope = computed(() => (scopes.value.some((s) => s.k === nav.get('scope')) ? nav.get('scope') : scopes.value[0]?.k || 'mine'));
const state = computed(() => (CLAIM_STATES.includes(nav.get('state')) ? nav.get('state') : ''));
const source = computed(() => (['odoo', 'ops'].includes(nav.get('source')) ? nav.get('source') : ''));
const sort = computed(() => (['total', 'number', 'created'].includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : { key: 'created', order: 'desc' }));
const list = useList('/hr/claims', () => ({
  scope: scope.value, state: state.value || undefined, q: nav.get('q'), from: nav.get('from') || undefined, to: nav.get('to') || undefined, source: scope.value === 'all' ? source.value || undefined : undefined,
  sort: sort.value.key, order: sort.value.order, page: page.value, pageSize: 25,
}));
/** The states that make sense in each scope. */
const stateOptions = computed(() => optionsOf(CLAIM_STATE).filter(([k]) => scope.value !== 'review' || ['submitted', 'approved', 'posted'].includes(k)));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const columns = computed(() => [
  { key: 'number', header: { ar: 'المطالبة', en: 'Claim' }, width: '128px', kind: 'id', sortable: true },
  { key: 'title', header: { ar: 'الموضوع', en: 'Subject' }, width: 'minmax(200px,2fr)' },
  ...(scope.value === 'mine' ? [] : [{ key: 'owner', header: { ar: 'صاحب المصروف', en: 'Whose expenses' }, width: 'minmax(150px,1.2fr)' }]),
  { key: 'created', header: { ar: 'التاريخ', en: 'Date' }, width: '92px', kind: 'date', sortable: true, value: (r) => fmtDay(r.createdAt) },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '104px', kind: 'num', sortable: true, value: (r) => money(r.total) },
  { key: 'employeeDue', header: { ar: 'مستحق للموظف', en: 'Due to employee' }, width: '110px', kind: 'num', value: (r) => money(r.employeeDue) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '168px', bare: true },
]);
const filtered = computed(() => !!(nav.get('q') || state.value || nav.get('from') || nav.get('to') || source.value));

const creating = ref(false);
const categories = ref(false);
</script>

<template>
  <div class="row wrap mb-2.5">
    <Tabs v-if="scopes.length > 1" :model-value="scope" :tabs="scopes" variant="sm" class="!mb-0" @update:model-value="(k) => nav.go('claims', { scope: k })" />
    <span class="grow" />
    <Btn v-if="canReview" size="sm" tone="soft" :label="{ ar: 'فئات المصروفات', en: 'Expense categories' }" @click="categories = true" />
    <Btn v-if="canClaim || canReview" size="sm" tone="primary" :label="{ ar: '+ مطالبة', en: '+ Claim' }" @click="creating = true" />
  </div>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[220px]" :model-value="qText" :placeholder="{ ar: 'بحث بالرقم، الموضوع أو الموظف…', en: 'Search number, subject or employee…' }" @update:model-value="typeQ" @enter="applyQ" />
    <SelectInput small class="w-[190px]" :model-value="state" :options="stateOptions" :placeholder="{ ar: 'كل الحالات', en: 'Every state' }" @update:model-value="(v) => nav.setFilter('state', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('from')" :title="t('من تاريخ', 'From')" :aria-label="t('من تاريخ', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[140px]" :model-value="nav.get('to')" :title="t('إلى تاريخ', 'To')" :aria-label="t('إلى تاريخ', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <SelectInput v-if="scope === 'all'" small class="w-[140px]" :model-value="source" :options="[['odoo', { ar: 'من أودو', en: 'From Odoo' }], ['ops', { ar: 'أُنشئت هنا', en: 'Created here' }]]"
                 :placeholder="{ ar: 'كل المصادر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('claim') || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا مطالبات مطابقة.', en: 'No matching claim.' } : scope === 'mine' ? { ar: 'لا مطالبات لك بعد — اضغط «+ مطالبة» لتقديم أول مطالبة.', en: 'You have no claims yet — press “+ Claim” to file the first one.' } : scope === 'review' ? { ar: 'لا شيء ينتظر إجراءً ✓', en: 'Nothing waits for action ✓' } : { ar: 'لا مطالبات بعد.', en: 'No claims yet.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openClaim(r.number)">
      <template #cell-title="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.title }}</div>
          <div class="truncate text-[9.5px] text-muted">{{ t(`${row.lineCount} سطرًا`, `${row.lineCount} line(s)`) }}<span v-if="row.linesWithoutReceipt && row.source !== 'odoo'" class="text-warn"> · {{ t(`${row.linesWithoutReceipt} بلا إيصال`, `${row.linesWithoutReceipt} without a receipt`) }}</span></div>
        </div>
      </template>
      <template #cell-owner="{ row }">
        <div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ claimOwner(row) }}</div><div class="num ltr truncate text-[9.5px] text-muted">{{ claimOwnerCode(row) }}</div></div>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="CLAIM_STATE" :k="row.state" />
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template v-if="list.data.value?.totals" #footer>
        <span class="me-3">{{ t('إجمالي المطابق', 'Total of the matches') }}: <b class="num">{{ money(list.data.value.totals.total) }}</b> · {{ t('مستحق للموظفين', 'due to employees') }}: <b class="num">{{ money(list.data.value.totals.employeeDue) }}</b></span>
      </template>
    </DataTable>
  </div>
  <div class="hint">
    {{ t('المسار: مسودة ← إرسال ← مراجعة (اعتماد، رفض بسبب، أو إعادة للتصحيح) ← ترحيل إلى الدفاتر بسندات مصروف من قسم المالية ← سداد. لا يراجع أحد مطالبته ولا مطالبة أدخلها. مطالبات أودو تُعرض بحالتها وأرقامها كما هي ولا تُرحَّل ثانية في دفاتر النظام.', 'The path: draft → submit → review (approve, refuse with a reason, or return for correction) → posted to the books as expense vouchers of the finance section → paid. Nobody decides their own claim, nor one they entered. Odoo’s claims are shown with their own state and figures and are never posted again in these books.') }}
  </div>

  <ClaimForm :open="creating" @close="creating = false" @done="(c) => nav.openClaim(c.number)" />
  <CategoriesDrawer :open="categories" @close="categories = false" />
</template>
