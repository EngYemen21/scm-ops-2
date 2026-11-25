<script setup>
// A list of credit requests — the same component for «بانتظاري» (scope=waiting), «الطلبات» (scope=all) and «طلباتي»
// (scope=mine). Search, filters (state · stage · target time · origin · apply status · date range), sort and paging are
// on the server and in the query string:
//   /credit?tab=requests&state=work&sla=late&sort=slaDeadline&order=asc&page=2
// GET /credit/requests → { items, total, page, pageSize, pages, totals: { requestedCreditLimit, approvedCreditLimit } }.
// A row opens the request (`req=<id>`).
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, TextInput } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { SORTS, fmtDay, money, nameOf, useCreditLookups, useCreditNav, useSearchParam } from './credit';
import { APPLY, ORIGIN, SLA, STATE_GROUPS, applyOptions, originOptions, own, slaOptions, stateChip } from './labels';

const props = defineProps({
  /** waiting | all | mine */
  scope: { type: String, default: 'all' },
});

const nav = useCreditNav();
const { page, reqOpen, hasFilters } = nav;
const lk = useCreditLookups();
const { text, type, apply } = useSearchParam(nav);

/** The waiting list shows what waits, whatever its state; the others start on the open requests. */
const defaultState = computed(() => (props.scope === 'waiting' ? '' : 'open'));
const state = computed(() => nav.get('state') || defaultState.value);
const stateOptions = computed(() => [...STATE_GROUPS, ...lk.stateOptions.value]);
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });

const list = useList('/credit/requests', () => ({
  scope: props.scope,
  q: nav.get('q') || undefined,
  state: state.value || undefined,
  stage: nav.get('stage') || undefined,
  sla: nav.get('sla') || undefined,
  origin: nav.get('origin') || undefined,
  apply: nav.get('apply') || undefined,
  from: nav.get('from') || undefined,
  to: nav.get('to') || undefined,
  sort: sort.value?.key,
  order: sort.value?.order,
  page: page.value,
  pageSize: 25,
}));
const data = computed(() => list.data.value || null);
const totals = computed(() => data.value?.totals || null);

const empty = computed(() => {
  if (hasFilters.value) return { ar: 'لا طلبات تطابق البحث والفلاتر.', en: 'No request matches the search and filters.' };
  if (props.scope === 'waiting') return { ar: 'لا شيء بانتظار قرارك الآن.', en: 'Nothing waits for your decision now.' };
  if (props.scope === 'mine') return { ar: 'لا طلبات مفتوحة باسمك — ابدأ من «+ طلب جديد».', en: 'You have no open request — start with "+ New request".' };
  return { ar: 'لا طلبات ائتمان مفتوحة.', en: 'No open credit request.' };
});

const columns = [
  { key: 'number', header: { ar: 'الطلب', en: 'Request' }, width: '118px', sortable: true },
  { key: 'companyName', header: { ar: 'المنشأة / العميل', en: 'Company / customer' }, width: 'minmax(190px,1.6fr)', sortable: true },
  { key: 'state', header: { ar: 'الحالة والمرحلة', en: 'State and stage' }, width: '150px', sortable: true, bare: true },
  { key: 'requestedCreditLimit', header: { ar: 'الحد المطلوب', en: 'Asked limit' }, width: '108px', kind: 'num', sortable: true },
  { key: 'approvedCreditLimit', header: { ar: 'الحد المعتمد', en: 'Granted limit' }, width: '108px', kind: 'num', sortable: true },
  { key: 'slaDeadline', header: { ar: 'الموعد', en: 'Target time' }, width: '118px', sortable: true, bare: true },
  { key: 'requester', header: { ar: 'مقدّم الطلب', en: 'Requester' }, width: 'minmax(110px,0.8fr)' },
  { key: 'updatedAt', header: { ar: 'آخر تحديث', en: 'Updated' }, width: '92px', kind: 'date', sortable: true, value: (r) => fmtDate(r.updatedAt, { time: false }) },
];
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[260px]" :model-value="text" :placeholder="ph('بحث بالرقم، المنشأة، السجل التجاري، العميل…', 'Search number, company, CR, customer…')" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[190px]" :model-value="nav.get('state')" :options="stateOptions" :placeholder="scope === 'waiting' ? ph('كل الحالات', 'Every state') : ph('المفتوحة (غير المنتهية)', 'Open (not closed)')" @update:model-value="(v) => nav.setFilter('state', v === defaultState ? null : v)" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('stage')" :options="lk.stageOptions.value" :placeholder="ph('كل المراحل', 'Every stage')" @update:model-value="(v) => nav.setFilter('stage', v)" />
    <SelectInput small class="w-[140px]" :model-value="nav.get('sla')" :options="slaOptions()" :placeholder="ph('أي موعد', 'Any target time')" @update:model-value="(v) => nav.setFilter('sla', v)" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[130px]" :model-value="nav.get('origin')" :options="originOptions()" :placeholder="ph('أي مصدر', 'Any origin')" @update:model-value="(v) => nav.setFilter('origin', v)" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('apply')" :options="applyOptions()" :placeholder="ph('أي حالة تطبيق', 'Any apply status')" @update:model-value="(v) => nav.setFilter('apply', v)" />
    <DateInput small field-class="w-[150px]" :model-value="nav.get('from')" :label="ph('من تاريخ', 'From')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small field-class="w-[150px]" :model-value="nav.get('to')" :label="ph('إلى تاريخ', 'To')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <Btn v-if="hasFilters" size="sm" tone="ghost" :label="ph('إزالة الفلاتر', 'Clear filters')" @click="nav.clearFilters()" />
  </div>

  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="data" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="reqOpen" :sort="sort" server-sort :empty-text="empty"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openReq(r.id)">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-id truncate">{{ row.number }}</div>
          <div class="cell-sub num">{{ fmtDay(row.requestDate) }}<template v-if="row.round > 1"> · {{ t('جولة', 'round') }} {{ row.round }}</template></div>
        </div>
      </template>
      <template #cell-companyName="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.companyName || row.partnerName || '—' }}</div>
          <div class="cell-sub truncate">
            <template v-if="row.customer"><span class="num ltr inline-block">{{ row.customer.code }}</span> · <span dir="auto">{{ nameOf(row.customer) }}</span></template>
            <template v-else>{{ t('لا عميل مرتبط', 'No customer linked') }}</template>
            <template v-if="row.city"> · <span dir="auto">{{ row.city }}</span></template>
          </div>
        </div>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-col items-center gap-0.5">
          <span class="inline-flex flex-wrap items-center justify-center gap-1">
            <Chip small :label="stateChip(row)" :fg="stateChip(row).fg" :bg="stateChip(row).bg" />
            <Chip v-if="row.origin === 'odoo'" small :label="ORIGIN.odoo" :fg="ORIGIN.odoo.fg" :bg="ORIGIN.odoo.bg" />
          </span>
          <span v-if="row.stage" class="max-w-full truncate text-[9px] font-bold text-violet" dir="auto">{{ nameOf(row.stage) }}</span>
          <span v-if="row.applyStatus && row.applyStatus !== 'none'" class="text-[9px] font-extrabold" :style="{ color: (own(APPLY, row.applyStatus) || APPLY.none).fg }">{{ t((own(APPLY, row.applyStatus) || APPLY.none).ar, (own(APPLY, row.applyStatus) || APPLY.none).en) }}</span>
        </span>
      </template>
      <template #cell-requestedCreditLimit="{ row }">
        <div class="num">{{ money(row.requestedCreditLimit, row.currency) }}<div v-if="row.requestedPaymentDays" class="cell-sub">{{ row.requestedPaymentDays }} {{ t('يوم', 'days') }}</div></div>
      </template>
      <template #cell-approvedCreditLimit="{ row }">
        <div class="num" :class="row.approvedCreditLimit != null ? 'text-ok' : 'text-faint'">{{ money(row.approvedCreditLimit, row.currency) }}<div v-if="row.approvedPaymentDays" class="cell-sub">{{ row.approvedPaymentDays }} {{ t('يوم', 'days') }}</div></div>
      </template>
      <template #cell-slaDeadline="{ row }">
        <span class="inline-flex flex-col items-center gap-0.5">
          <Chip v-if="row.sla && row.sla !== 'none'" small :map="SLA" :k="row.sla" />
          <span v-if="row.slaDeadline" class="cell-date">{{ fmtDate(row.slaDeadline) }}</span>
          <span v-else-if="!row.sla || row.sla === 'none'" class="text-faint">—</span>
        </span>
      </template>
      <template #cell-requester="{ row }"><span class="truncate text-[10px]" :class="row.requester ? 'text-sec' : 'text-faint'" dir="auto">{{ row.requester ? nameOf(row.requester) : '—' }}</span></template>
    </DataTable>
    <div v-if="totals && data?.total" class="row wrap border-t border-line px-3.5 py-2.5 text-[10.5px] text-sec !gap-x-4">
      <span>{{ t('عدد الطلبات', 'Requests') }}: <b class="num">{{ fmtNum(data.total) }}</b></span>
      <span>{{ t('مجموع الحدود المطلوبة', 'Total asked') }}: <b class="num ltr inline-block">{{ money(totals.requestedCreditLimit) }}</b></span>
      <span>{{ t('مجموع الحدود المعتمدة', 'Total granted') }}: <b class="num ltr inline-block text-ok">{{ money(totals.approvedCreditLimit) }}</b></span>
      <span class="text-[9px] text-faint">{{ t('لكل الطلبات المطابقة للفلاتر، لا لهذه الصفحة فقط', 'over every request matching the filters, not only this page') }}</span>
    </div>
  </div>
</template>
