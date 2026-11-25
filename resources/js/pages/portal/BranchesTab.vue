<script setup>
// Customer branches: search, filters (customer · state · origin · linked · delivery site), sort and paging — all on the
// server and all in the query string:   /portal?customer=CUS-1008&state=all&sort=sequence&order=asc&branch=<id>
// GET /portal/branches → { items, total, page, pageSize, pages }. A row opens the branch (`branch=<id>`).
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import BranchDrawer from './BranchDrawer.vue';
import BranchForm from './BranchForm.vue';
import PartyName from './PartyName.vue';
import PortalFilters from './PortalFilters.vue';
import { ACTIVE_LABELS, LINKED_TABS, OWNED_CHIP, STATE_TABS, daysLabel, usePortalNav } from './portal';

const auth = useAuth();
const nav = usePortalNav();
const { page } = nav;
const canManage = computed(() => auth.can('portal.manage'));

const state = computed(() => (['archived', 'all'].includes(nav.get('state')) ? nav.get('state') : ''));
const linked = computed(() => (['yes', 'no'].includes(nav.get('linked')) ? nav.get('linked') : ''));
const site = computed(() => (['yes', 'no'].includes(nav.get('site')) ? nav.get('site') : ''));
const sort = computed(() => nav.sortOf(['name', 'sequence', 'city', 'createdAt']));
const opened = computed(() => nav.get('branch') || null);

const list = useList('/portal/branches', () => ({
  q: nav.get('q'), customer: nav.get('customer'), origin: nav.get('origin'), state: state.value || undefined, linked: linked.value, site: site.value,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const filtered = computed(() => !!(nav.get('q') || nav.get('customer') || nav.get('origin') || state.value || linked.value || site.value));
const empty = computed(() => (filtered.value
  ? { ar: 'لا فروع تطابق البحث والفلاتر.', en: 'No branch matches the search and filters.' }
  : { ar: 'لا فروع بعد — أضف أول فرع من «+ فرع»، أو حمّل فروع أودو من الخادم.', en: 'No branch yet — add the first one with "+ Branch", or load Odoo’s branches from the server.' }));

const SITE_TABS = [{ k: '', label: { ar: 'كل الفروع', en: 'Any branch' } }, { k: 'yes', label: { ar: 'مربوطة بموقع تسليم', en: 'With a delivery site' } }, { k: 'no', label: { ar: 'بلا موقع تسليم', en: 'No delivery site' } }];
const columns = [
  { key: 'name', header: { ar: 'الفرع', en: 'Branch' }, width: 'minmax(150px,1.2fr)', sortable: true },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(160px,1.4fr)' },
  { key: 'city', header: { ar: 'المدينة', en: 'City' }, width: '110px', sortable: true },
  { key: 'contact', header: { ar: 'جهة الاتصال', en: 'Contact' }, width: 'minmax(120px,1fr)' },
  { key: 'window', header: { ar: 'نافذة التسليم', en: 'Delivery window' }, width: '128px' },
  { key: 'site', header: { ar: 'موقع التسليم', en: 'Delivery site' }, width: '128px' },
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '66px', kind: 'num', sortable: true },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '112px', bare: true },
];

/** The form: `true` = a new branch, an object = the branch being edited (the drawer hands it over). */
const form = ref(null);
function onSaved(b) { form.value = null; list.refetch(); if (b?.id) nav.patch({ branch: b.id }); }
</script>

<template>
  <PortalFilters origin :search="{ ar: 'بحث باسم الفرع أو المدينة أو جهة الاتصال…', en: 'Search branch, city or contact…' }">
    <Tabs :model-value="state" :tabs="STATE_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k)" />
    <Tabs :model-value="linked" :tabs="LINKED_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('linked', k)" />
    <Tabs :model-value="site" :tabs="SITE_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('site', k)" />
    <span class="grow" />
    <Btn v-if="canManage" size="sm" tone="primary" :label="{ ar: '+ فرع', en: '+ Branch' }" @click="form = true" />
  </PortalFilters>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="opened" :sort="sort" server-sort :empty-text="empty"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ branch: r.id })">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.name }}</div>
          <div v-if="row.code || row.usersCount" class="cell-sub">{{ row.code }}<template v-if="row.code && row.usersCount"> · </template><template v-if="row.usersCount">{{ t(`${row.usersCount} مستخدم بوابة`, `${row.usersCount} portal user(s)`) }}</template></div>
        </div>
      </template>
      <template #cell-customer="{ row }"><PartyName :party="row.customer" /></template>
      <template #cell-city="{ row }"><span dir="auto">{{ row.city || '—' }}</span><div v-if="row.district" class="text-[9px] text-muted" dir="auto">{{ row.district }}</div></template>
      <template #cell-contact="{ row }">
        <div v-if="row.contactName || row.phone" class="min-w-0">
          <div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ row.contactName || '—' }}</div>
          <div v-if="row.phone" class="num ltr text-[9.5px] text-muted">{{ row.phone }}</div>
        </div>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-window="{ row }">
        <span v-if="row.window" class="num ltr">{{ row.window }}</span><span v-else class="text-faint">—</span>
        <div v-if="row.window || row.deliveryDays" class="text-[9px] text-muted">{{ daysLabel(row.deliveryDays) }}</div>
      </template>
      <template #cell-site="{ row }">
        <span v-if="row.site" class="truncate text-[10.5px] font-bold" dir="auto">{{ row.site.name }}</span>
        <span v-else class="text-[9.5px] text-faint">{{ t('غير مربوط بموقع', 'Not tied to a site') }}</span>
      </template>
      <template #cell-status="{ row }">
        <div class="flex flex-wrap justify-center gap-1">
          <Chip small :map="ACTIVE_LABELS" :k="row.active ? 'active' : 'archived'" />
          <Chip v-if="row.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </div>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الفرع هو الوحدة التي تطلب عبر البوابة. ربطه بموقع التسليم في نظام العمليات هو ما يجعل طلباته وفواتيره تُنسب إليه، ومنه يصدر كشف حساب الفرع.', 'A branch is the unit that orders through the portal. Tying it to its delivery site in OPS is what attributes its orders and invoices to it — and what a branch statement is made from.') }}</div>

  <BranchDrawer :id="opened" @close="nav.patch({ branch: null })" @edit="(b) => (form = b)" @changed="list.refetch()" />
  <BranchForm :open="!!form" :branch="form === true ? null : form" @close="form = null" @done="onSaved" />
</template>
