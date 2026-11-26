<script setup>
// One list of approval requests — the same view for the four scopes of the page: what waits for my decision (the one
// that has waited longest first), my own requests, the ones I am an approver of, and every request (approval.manage).
// GET /approvals/requests?scope=&status=&category=&source=&from=&to=&q=&sort=&order=&page=
// Search, filters, sort and page live in the query string; a row opens the request (`request=<number>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { REQUEST_STATUS, REQUEST_STATUSES, SOURCE_CHIP, TONE, catName, fmtAmount, fmtAt, personName, progressText, useApprovalsNav, useSearchParam, waitingOn } from './approvals';

const props = defineProps({
  /** waiting | mine | involved | all */
  scope: { type: String, required: true },
  /** GET /approvals/summary — the counts on the status pills. */
  summary: { type: Object, default: null },
});

const nav = useApprovalsNav();
const { page, sort, requestOpen } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);

const DAY = /^\d{4}-\d{2}-\d{2}$/;
/** What waits for me is pending by definition: the status filter belongs to the other lists. */
const status = computed(() => (props.scope !== 'waiting' && REQUEST_STATUSES.includes(nav.get('status')) ? nav.get('status') : ''));
const category = computed(() => nav.get('category'));
const source = computed(() => (['odoo', 'ops'].includes(nav.get('source')) ? nav.get('source') : ''));
const from = computed(() => (DAY.test(nav.get('from')) ? nav.get('from') : ''));
const to = computed(() => (DAY.test(nav.get('to')) ? nav.get('to') : ''));
const filtered = computed(() => !!(status.value || category.value || source.value || from.value || to.value || nav.get('q') || sort.value));

const list = useList('/approvals/requests', () => ({
  scope: props.scope, status: status.value, category: category.value, source: source.value, from: from.value, to: to.value, q: nav.get('q'),
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));

// every category, stopped ones too: the history of a category that is no longer used is still filtered by it
const categories = useList('/approvals/categories', { active: 'all', pageSize: 200, sort: 'name', order: 'asc' }, { staleTime: 120_000 });
const categoryOptions = computed(() => (categories.data.value?.items || []).map((c) => ({ v: c.code, l: `${catName(c)}${c.active ? '' : t(' (موقوف)', ' (stopped)')}` })));

const counts = computed(() => (props.scope === 'mine' ? props.summary?.mine : props.scope === 'all' ? props.summary?.all : null));
const withCount = (label, n) => (n == null ? label : { ar: `${label.ar} (${fmtNum(n)})`, en: `${label.en} (${fmtNum(n)})` });
const statusTabs = computed(() => [
  { k: '', label: withCount({ ar: 'الكل', en: 'All' }, counts.value?.total) },
  ...REQUEST_STATUSES.map((k) => ({ k, label: withCount(REQUEST_STATUS[k], counts.value?.[k]) })),
]);
const sourceTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'ops', label: { ar: 'طلبات النظام', en: 'Raised here' } }, { k: 'odoo', label: SOURCE_CHIP }];
const reset = () => nav.go(props.scope);

const columns = computed(() => [
  { key: 'number', header: { ar: 'الطلب', en: 'Request' }, width: 'minmax(220px,1.7fr)', sortable: true },
  { key: 'category', header: { ar: 'النوع', en: 'Category' }, width: '130px', value: (r) => catName(r.category) || '—' },
  { key: 'requester', header: { ar: 'صاحب الطلب', en: 'Requester' }, width: '130px', sortable: true, hidden: props.scope === 'mine', value: (r) => personName(r.requester) },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '110px', kind: 'num', sortable: true, value: (r) => fmtAmount(r.amount, r.currency) },
  { key: 'approvals', header: { ar: 'الاعتمادات', en: 'Approvals' }, width: 'minmax(150px,1fr)' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '128px', bare: true, sortable: true },
  { key: 'createdAt', header: { ar: 'أُنشئ', en: 'Created' }, width: '118px', kind: 'date', sortable: true, value: (r) => fmtAt(r.createdAt) },
]);
const emptyText = computed(() => {
  if (filtered.value) return { ar: 'لا طلبات مطابقة لهذا البحث.', en: 'No request matches this search.' };
  if (props.scope === 'waiting') return { ar: 'لا شيء بانتظار قرارك الآن ✓', en: 'Nothing is waiting for your decision ✓' };
  if (props.scope === 'mine') return { ar: 'لم ترفع طلب اعتماد بعد — ابدأ من «+ طلب جديد».', en: 'You have not raised an approval request yet — start with “+ New request”.' };
  if (props.scope === 'involved') return { ar: 'لم يُطلب قرارك في أي طلب بعد.', en: 'You have not been asked to decide any request yet.' };
  return { ar: 'لا طلبات اعتماد بعد.', en: 'No approval requests yet.' };
});
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="qText" :placeholder="{ ar: 'بحث بالرقم، العنوان، المرجع، الجهة…', en: 'Search number, title, reference, partner…' }" @update:model-value="typeQ" @enter="applyQ" />
    <SelectInput small class="w-[170px]" :model-value="category" :options="categoryOptions" :placeholder="{ ar: 'كل الأنواع', en: 'Every category' }" @update:model-value="(v) => nav.setFilter('category', v)" />
    <DateInput small class="w-[136px]" :model-value="from" :max="to || null" :title="t('أُنشئ من تاريخ', 'Created from')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[136px]" :model-value="to" :min="from || null" :title="t('أُنشئ حتى تاريخ', 'Created until')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="reset" />
  </div>
  <div v-if="scope !== 'waiting' || summary?.hasOdoo" class="row wrap mb-2.5">
    <Tabs v-if="scope !== 'waiting'" :model-value="status" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k)" />
    <Tabs v-if="summary?.hasOdoo" :model-value="source" :tabs="sourceTabs" variant="pillPurple" class="!mb-0" @update:model-value="(k) => nav.setFilter('source', k)" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number" :selected-key="requestOpen" :sort="sort" server-sort :empty-text="emptyText"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.openRequest(r.number)">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="flex min-w-0 items-center gap-1.5">
            <span class="cell-id">{{ row.number }}</span>
            <Chip v-if="row.source === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
            <Chip v-if="row.my.turn" small :label="{ ar: 'دورك', en: 'Your turn' }" :fg="TONE.warn.fg" :bg="TONE.warn.bg" />
          </div>
          <div class="truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto" :title="row.title">{{ row.title }}</div>
          <div v-if="row.partner || row.reference" class="truncate text-[9.5px] text-muted [text-align:match-parent]" dir="auto">{{ [row.partner?.name, row.reference].filter(Boolean).join(' · ') }}</div>
        </div>
      </template>
      <template #cell-approvals="{ row }">
        <span v-if="row.status === 'draft'" class="text-faint">—</span>
        <div v-else class="min-w-0">
          <span class="num ltr text-[10.5px] font-bold text-ink">{{ progressText(row.progress) }}</span>
          <span class="ms-1 text-[9.5px] text-muted">{{ t('اعتماد', 'approved') }}</span>
          <div v-if="waitingOn(row).length" class="truncate text-[9.5px] text-warn" :title="waitingOn(row).join('، ')">{{ t('عند', 'With') }} {{ waitingOn(row).join('، ') }}</div>
        </div>
      </template>
      <template #cell-status="{ row }"><Chip small :map="REQUEST_STATUS" :k="row.status" /></template>
    </DataTable>
  </div>
</template>
