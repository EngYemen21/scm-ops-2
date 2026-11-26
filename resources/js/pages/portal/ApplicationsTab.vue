<script setup>
// Onboarding applications: search, filters (state · origin · the customer it became), sort and paging — all on the
// server and all in the query string:   /portal?tab=applications&status=review&sort=submittedAt&order=asc&app=<id>
// GET /portal/applications → { items, total, page, pageSize, pages }. A row opens the application (`app=<id>`).
import { computed, ref } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ApplicationDrawer from './ApplicationDrawer.vue';
import ApplicationForm from './ApplicationForm.vue';
import PartyName from './PartyName.vue';
import PortalFilters from './PortalFilters.vue';
import { APPLICATION_FILTERS, APPLICATION_STATUS, ODOO_APPLICATION_STATE, OWNED_CHIP, labelOf, usePortalNav } from './portal';

const auth = useAuth();
const nav = usePortalNav();
const { page } = nav;
const canManage = computed(() => auth.can('portal.manage'));

const status = computed(() => (APPLICATION_FILTERS.some((f) => f.k === nav.get('status')) ? nav.get('status') : 'all'));
const sort = computed(() => nav.sortOf(['createdAt', 'number', 'companyNameAr', 'submittedAt', 'decidedAt', 'status']));
const opened = computed(() => nav.get('app') || null);

const list = useList('/portal/applications', () => ({
  q: nav.get('q'), customer: nav.get('customer'), origin: nav.get('origin'), status: status.value === 'all' ? undefined : status.value,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
// the figure on the "to review" pill is the page's own summary (one request, shared with the head through the cache)
const summary = useGet('/portal/summary', undefined, { staleTime: 30_000 });
const pills = computed(() => APPLICATION_FILTERS.map((f) => (f.k === 'review' ? { ...f, badge: summary.data.value?.applications.waiting || null } : f)));
const filtered = computed(() => !!(nav.get('q') || nav.get('customer') || nav.get('origin') || status.value !== 'all'));
const empty = computed(() => (status.value === 'review' && !nav.get('q')
  ? { ar: 'لا طلبات بانتظار المراجعة ✓', en: 'No application is waiting for a review ✓' }
  : filtered.value ? { ar: 'لا طلبات تطابق البحث والفلاتر.', en: 'No application matches the search and filters.' }
    : { ar: 'لا طلبات فتح حساب بعد — سجّل طلبًا من «+ طلب فتح حساب».', en: 'No onboarding application yet — enter one with "+ Application".' }));

const columns = [
  { key: 'number', header: { ar: 'الطلب', en: 'Application' }, width: '128px', sortable: true },
  { key: 'companyNameAr', header: { ar: 'المنشأة', en: 'Company' }, width: 'minmax(170px,1.5fr)', sortable: true },
  { key: 'ids', header: { ar: 'السجل · الضريبي', en: 'CR · VAT' }, width: '136px' },
  { key: 'contact', header: { ar: 'التواصل', en: 'Contact' }, width: 'minmax(120px,1fr)' },
  { key: 'documentsCount', header: { ar: 'المستندات', en: 'Papers' }, width: '74px', kind: 'num' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(130px,1fr)' },
  { key: 'createdAt', header: { ar: 'أُنشئ', en: 'Created' }, width: '88px', kind: 'date', sortable: true, value: (r) => fmtDateOnly(r.createdAt) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px', sortable: true, bare: true },
];

const creating = ref(false);
function onCreated(a) { creating.value = false; list.refetch(); if (a?.id) nav.patch({ app: a.id }); }
</script>

<template>
  <PortalFilters origin :search="{ ar: 'بحث بالرقم أو اسم المنشأة أو السجل أو الجوال…', en: 'Search number, company, CR or mobile…' }">
    <Tabs :model-value="status" :tabs="pills" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k === 'all' ? '' : k)" />
    <span class="grow" />
    <Btn v-if="canManage" size="sm" tone="primary" :label="{ ar: '+ طلب فتح حساب', en: '+ Application' }" @click="creating = true" />
  </PortalFilters>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="opened" :sort="sort" server-sort :empty-text="empty"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ app: r.id })">
      <template #cell-number="{ row }"><div><div class="cell-id">{{ row.number }}</div><div v-if="row.externalRef" class="cell-sub">Odoo #{{ row.externalRef }}</div></div></template>
      <template #cell-companyNameAr="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.companyNameAr || row.companyNameEn || t('بلا اسم بعد', 'No name yet') }}</div>
          <div v-if="row.city" class="text-[9px] text-muted" dir="auto">{{ row.city }}</div>
        </div>
      </template>
      <template #cell-ids="{ row }"><div class="num ltr text-[10px]">{{ row.cr || '—' }}</div><div class="num ltr text-[9.5px] text-muted">{{ row.vatNo || '—' }}</div></template>
      <template #cell-contact="{ row }">
        <div v-if="row.mobile || row.email" class="min-w-0"><div class="num ltr text-[10.5px]">{{ row.mobile || '—' }}</div><div v-if="row.email" class="num ltr truncate text-[9.5px] text-muted">{{ row.email }}</div></div>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-customer="{ row }"><PartyName v-if="row.customer" :party="row.customer" /><span v-else class="text-faint">—</span></template>
      <template #cell-status="{ row }">
        <div class="flex flex-wrap justify-center gap-1">
          <Chip small :map="APPLICATION_STATUS" :k="row.status" />
          <Chip v-if="row.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" :title="row.sourceState ? `Odoo: ${labelOf(ODOO_APPLICATION_STATE, row.sourceState)}` : null" />
        </div>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('اعتماد الطلب يجعل المنشأة عميلًا في نظام العمليات: إن كان رقمها الضريبي أو سجلها التجاري لعميل موجود رُبط الطلب به، وإلا يحدّد المراجع العميل الموجود أو يؤكد أنه جديد — لا يُنشأ عميل مرتين.', 'Approving an application makes the company an OPS customer: when its VAT or registration number belongs to an existing customer the application is linked to it; otherwise the reviewer names the existing customer or confirms a new one — a customer is never made twice.') }}</div>

  <ApplicationDrawer :id="opened" @close="nav.patch({ app: null })" @changed="list.refetch(); summary.refetch()" />
  <ApplicationForm :open="creating" @close="creating = false" @done="onCreated" />
</template>
