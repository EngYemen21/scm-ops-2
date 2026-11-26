<script setup>
// Order requests and the log of order-line changes — two views of one tab, switched in the query string:
//   /portal?tab=requests&status=pending&req=<id>             the requests a branch made through the portal
//   /portal?tab=requests&view=changes&type=modified&…        who changed what on which order line, before and after
// GET /portal/requests → { items, total, page, pageSize, pages }. A row opens the request (`req=<id>`).
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import LineChangesList from './LineChangesList.vue';
import PartyName from './PartyName.vue';
import PortalFilters from './PortalFilters.vue';
import RequestDrawer from './RequestDrawer.vue';
import RequestForm from './RequestForm.vue';
import { OWNED_CHIP, REQUEST_STATUS, moment, money, usePortalNav } from './portal';

const auth = useAuth();
const nav = usePortalNav();
const { page } = nav;
const canManage = computed(() => auth.can('portal.manage'));

const view = computed(() => (nav.get('view') === 'changes' ? 'changes' : 'requests'));
const views = [{ k: 'requests', label: { ar: 'طلبات الشراء', en: 'Order requests' } }, { k: 'changes', label: { ar: 'سجل تغييرات أسطر الطلبات', en: 'Order-line change log' } }];
/** Another view is another list: its own filters, from the first page. */
const setView = (k) => nav.go('requests', k === 'changes' ? { view: 'changes' } : {});

const STATUS_TABS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.entries(REQUEST_STATUS).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en } }))];
const status = computed(() => (REQUEST_STATUS[nav.get('status')] ? nav.get('status') : ''));
const branchId = computed(() => nav.get('branchId'));
const sort = computed(() => nav.sortOf(['requestDate', 'number', 'amountTotal', 'status', 'decidedAt']));
const opened = computed(() => nav.get('req') || null);

const list = useList(() => (view.value === 'requests' ? '/portal/requests' : null), () => ({
  q: nav.get('q'), customer: nav.get('customer'), origin: nav.get('origin'), status: status.value, branch: branchId.value,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const filtered = computed(() => !!(nav.get('q') || nav.get('customer') || nav.get('origin') || status.value || branchId.value));
const empty = computed(() => (filtered.value
  ? { ar: 'لا طلبات تطابق البحث والفلاتر.', en: 'No request matches the search and filters.' }
  : { ar: 'لا طلبات شراء من البوابة بعد.', en: 'No portal order request yet.' }));

const columns = [
  { key: 'number', header: { ar: 'الطلب', en: 'Request' }, width: '132px', sortable: true },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(160px,1.4fr)' },
  { key: 'branch', header: { ar: 'الفرع', en: 'Branch' }, width: '120px' },
  { key: 'requestedBy', header: { ar: 'طلبه', en: 'Requested by' }, width: 'minmax(110px,1fr)' },
  { key: 'requestDate', header: { ar: 'تاريخ الطلب', en: 'Requested' }, width: '124px', kind: 'date', sortable: true, value: (r) => moment(r.requestDate) },
  { key: 'linesCount', header: { ar: 'الأسطر', en: 'Lines' }, width: '62px', kind: 'num' },
  { key: 'amountTotal', header: { ar: 'الإجمالي', en: 'Total' }, width: '104px', kind: 'num', sortable: true },
  { key: 'became', header: { ar: 'صار', en: 'Became' }, width: '128px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '128px', sortable: true, bare: true },
];

const creating = ref(false);
function onCreated(r) { creating.value = false; list.refetch(); if (r?.id) nav.patch({ req: r.id }); }
</script>

<template>
  <Tabs :model-value="view" :tabs="views" variant="sm" class="!mb-2.5" @update:model-value="setView" />

  <template v-if="view === 'requests'">
    <PortalFilters origin :search="{ ar: 'بحث برقم الطلب أو طالبه أو العميل…', en: 'Search request, requester or customer…' }">
      <Tabs :model-value="status" :tabs="STATUS_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k)" />
      <button v-if="branchId" type="button" class="pill active" @click="nav.setFilter('branchId', '')">{{ t('فرع واحد ✕', 'One branch ✕') }}</button>
      <span class="grow" />
      <Btn v-if="canManage" size="sm" tone="primary" :label="{ ar: '+ طلب شراء', en: '+ Order request' }" @click="creating = true" />
    </PortalFilters>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="opened" :sort="sort" server-sort :empty-text="empty"
                 @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ req: r.id })">
        <template #cell-number="{ row }"><div><div class="cell-id">{{ row.number }}</div><div v-if="row.externalRef" class="cell-sub">Odoo #{{ row.externalRef }}</div></div></template>
        <template #cell-customer="{ row }"><PartyName :party="row.customer" /></template>
        <template #cell-branch="{ row }"><span v-if="row.branch" class="truncate text-[10.5px] font-bold" dir="auto">{{ row.branch.name }}</span><span v-else class="text-faint">—</span></template>
        <template #cell-requestedBy="{ row }"><span class="truncate" dir="auto">{{ row.requestedBy || '—' }}</span></template>
        <template #cell-amountTotal="{ row }"><span class="num">{{ money(row.amountTotal) }}</span> <span class="text-[9px] text-faint">{{ row.currency }}</span></template>
        <template #cell-became="{ row }">
          <span v-if="row.quotation" class="cell-id">{{ row.quotation.number }}</span>
          <span v-else-if="row.order" class="cell-id" :title="t('أمر البيع في أودو', 'The sale order in Odoo')">{{ row.order.number || `#${row.order.ref}` }}</span>
          <span v-else class="text-faint">—</span>
        </template>
        <template #cell-status="{ row }">
          <div class="flex flex-wrap justify-center gap-1">
            <Chip small :map="REQUEST_STATUS" :k="row.status" />
            <Chip v-if="row.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
          </div>
        </template>
      </DataTable>
    </div>
    <div class="hint">{{ t('طلب الشراء يرفعه فرع العميل من البوابة. اعتماده هنا ينشئ عرض سعر مسودة في المبيعات، وكل تصحيح لأسطره يُكتب في سجل التغييرات: من غيّر، وماذا، وقبل وبعد.', 'An order request is raised by a customer’s branch through the portal. Approving it here makes a draft quotation in Sales, and every correction of its lines goes to the change log: who, what, before and after.') }}</div>
  </template>

  <LineChangesList v-else />

  <RequestDrawer :id="opened" @close="nav.patch({ req: null })" @changed="list.refetch()" />
  <RequestForm :open="creating" @close="creating = false" @done="onCreated" />
</template>
