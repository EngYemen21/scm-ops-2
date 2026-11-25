<script setup>
// «قواعد التسوية» — where a difference between the bank and the books may go, and up to how much (GET /banking/rules):
// active, origin, kind, nature, bank, search, sort and paging in the query string. A rule is never deleted, it is
// switched off. Each row says whether the rule can post right now (`usable`) or why not (`unusable`).
// bank.reconcile adds and edits rules (RuleForm); a rule of Odoo (`owned`) may only have its OPS account changed.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import RuleForm from './RuleForm.vue';
import { NATURE, OWNED_CHIP, RULE_KIND, money, nameOf, optionsOf, useBankingLookups, useBankingNav, useSearchParam } from './banking';

const auth = useAuth();
const nav = useBankingNav();
const lk = useBankingLookups();
const canReconcile = computed(() => auth.can('bank.reconcile'));
const { text, type, apply } = useSearchParam(nav);

const ACTIVE = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: '1', label: { ar: 'الفعّالة', en: 'Active' } }, { k: '0', label: { ar: 'الموقوفة', en: 'Switched off' } }];
const active = computed(() => (['0', '1'].includes(nav.get('active')) ? nav.get('active') : ''));
const ORIGIN = [{ v: 'ops', l: { ar: 'أُنشئت هنا', en: 'Made in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const SORTS = ['name', 'limit', 'created'];
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'desc' ? 'desc' : 'asc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const list = useList('/banking/rules', () => ({
  active: active.value || undefined, origin: nav.get('origin') || undefined, kind: RULE_KIND[nav.get('kind')] ? nav.get('kind') : undefined, nature: NATURE[nav.get('nature')] ? nav.get('nature') : undefined,
  bank: nav.bank.value || undefined, q: nav.get('q') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['active', 'origin', 'kind', 'nature', 'bank', 'q'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ active: null, origin: null, kind: null, nature: null, bank: null, q: null, page: null });

const columns = [
  { key: 'name', header: { ar: 'القاعدة', en: 'Rule' }, width: 'minmax(170px,1.6fr)', sortable: true },
  { key: 'nature', header: { ar: 'الاتجاه', en: 'Direction' }, width: '130px', bare: true },
  { key: 'account', header: { ar: 'حساب الترحيل', en: 'Posts to' }, width: 'minmax(130px,1.1fr)' },
  { key: 'limit', header: { ar: 'الحد الأقصى', en: 'Limit' }, width: '96px', kind: 'num', sortable: true },
  { key: 'bank', header: { ar: 'الحساب البنكي', en: 'Bank account' }, width: '110px' },
  { key: 'words', header: { ar: 'كلمات السطر', en: 'Line words' }, width: 'minmax(110px,1fr)' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', bare: true },
];

/** The form: `editing` = the rule shown, `creating` = a new one. */
const editing = ref(null);
const creating = ref(false);
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'بحث بالاسم، البيان، الكلمات…', en: 'Search name, label, words…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="active" :tabs="ACTIVE" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('active', k)" />
    <span class="grow" />
    <Btn v-if="canReconcile" tone="primary" size="sm" :label="{ ar: '+ قاعدة تسوية', en: '+ Write-off rule' }" @click="creating = true" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[150px]" :model-value="nav.get('nature')" :options="optionsOf(NATURE)" :placeholder="{ ar: 'أي اتجاه', en: 'Any direction' }" @update:model-value="(v) => nav.setFilter('nature', v)" />
    <SelectInput small class="w-[140px]" :model-value="nav.get('kind')" :options="optionsOf(RULE_KIND)" :placeholder="{ ar: 'كل الأنواع', en: 'Every kind' }" @update:model-value="(v) => nav.setFilter('kind', v)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('origin')" :options="ORIGIN" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('origin', v)" />
    <SelectInput small class="w-[220px]" :model-value="nav.bank.value" :options="lk.bankOptions.value" :placeholder="{ ar: 'كل الحسابات البنكية', en: 'Every bank account' }" @update:model-value="(v) => nav.setFilter('bank', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <div class="hint !mt-0 mb-2.5">{{ t('القاعدة ترحّل الفرق بنقرة، فهي ضابط: حساب يقبل الترحيل (ليس حساب عملاء أو موردين ولا حساب بنك)، واتجاه، وحد أقصى. القاعدة لا تُحذف بل تُوقف.', 'A rule posts a difference with one click, so it is a control: a postable account (not a customers / suppliers account, not a bank’s), a direction and a limit. A rule is never deleted — it is switched off.') }}</div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="editing?.id || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا قواعد تطابق الفلاتر.', en: 'No rule fits the filters.' } : { ar: 'لا قواعد تسوية بعد.', en: 'No write-off rule yet.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => (editing = r)">
      <template #cell-name="{ row }">
        <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div><div class="cell-sub truncate" dir="auto">{{ row.label || '' }}<template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template> · {{ t('ترتيب', 'seq.') }} {{ row.sequence }}</div></div>
      </template>
      <template #cell-nature="{ row }"><Chip small :map="NATURE" :k="row.nature" /></template>
      <template #cell-account="{ row }"><span v-if="row.account" class="truncate text-[10px]"><span class="num ltr">{{ row.account.code }}</span> <span dir="auto">{{ nameOf(row.account) }}</span></span><span v-else class="text-[10px] font-bold text-bad">{{ t('بلا حساب', 'No account') }}</span></template>
      <template #cell-limit="{ row }"><span class="cell-num">{{ money(row.maxAmount) }}</span></template>
      <template #cell-bank="{ row }"><span class="text-[10px]" :class="row.bank ? '' : 'text-faint'">{{ row.bank ? row.bank.code : t('كل الحسابات', 'Any') }}</span></template>
      <template #cell-words="{ row }"><span class="truncate text-[10px] text-muted" dir="auto" :title="row.matchText || ''">{{ row.matchText || '—' }}</span></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-col items-center gap-0.5">
          <span class="inline-flex flex-wrap justify-center gap-1">
            <Chip v-if="row.usable" small :label="{ ar: 'صالحة للترحيل', en: 'Can post' }" fg="#1d7a3e" bg="#e6f9ec" />
            <Chip v-else small :label="{ ar: 'لا ترحّل', en: 'Cannot post' }" fg="#b26a16" bg="#fbf0dd" />
            <Chip v-if="row.kind !== 'writeoff'" small :map="RULE_KIND" :k="row.kind" />
            <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
          </span>
          <span v-if="row.unusable" class="text-[8.5px] text-warn" dir="auto">{{ t(row.unusable.ar, row.unusable.en) }}</span>
        </span>
      </template>
    </DataTable>
  </div>

  <RuleForm :open="creating || !!editing" :rule="editing" @close="creating = false; editing = null" />
</template>
