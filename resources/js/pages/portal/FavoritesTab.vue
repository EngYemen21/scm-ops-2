<script setup>
// Favourite lists: search, filters (customer · branch · state · origin · linked · a product), sort and paging — all on
// the server and all in the query string:   /portal?tab=favorites&customer=CUS-1008&product=SKU-12&list=<id>
// GET /portal/favorites/lists → { items, total, page, pageSize, pages }. A row opens the list (`list=<id>`): its header
// here, its items a page at a time in the drawer — a customer's favourites are never loaded as a whole.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import FavoriteListDrawer from './FavoriteListDrawer.vue';
import FavoriteListForm from './FavoriteListForm.vue';
import PartyName from './PartyName.vue';
import PortalFilters from './PortalFilters.vue';
import ProductPick from './ProductPick.vue';
import { ACTIVE_LABELS, LINKED_TABS, OWNED_CHIP, STATE_TABS, usePortalNav } from './portal';

const auth = useAuth();
const nav = usePortalNav();
const { page } = nav;
const canManage = computed(() => auth.can('portal.manage'));

const state = computed(() => (['archived', 'all'].includes(nav.get('state')) ? nav.get('state') : ''));
const linked = computed(() => (['yes', 'no'].includes(nav.get('linked')) ? nav.get('linked') : ''));
const product = computed(() => nav.get('product'));
const branchId = computed(() => nav.get('branchId'));
const sort = computed(() => nav.sortOf(['name', 'itemsCount', 'sequence', 'createdAt', 'updatedAt']));
const opened = computed(() => nav.get('list') || null);

const list = useList('/portal/favorites/lists', () => ({
  q: nav.get('q'), customer: nav.get('customer'), origin: nav.get('origin'), state: state.value || undefined, linked: linked.value, product: product.value, branch: branchId.value,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const filtered = computed(() => !!(nav.get('q') || nav.get('customer') || nav.get('origin') || state.value || linked.value || product.value || branchId.value));
const empty = computed(() => (filtered.value
  ? { ar: 'لا قوائم تطابق البحث والفلاتر.', en: 'No list matches the search and filters.' }
  : { ar: 'لا قوائم مفضلة بعد — أنشئ أول قائمة من «+ قائمة»، أو حمّل قوائم أودو من الخادم.', en: 'No favourite list yet — start one with "+ List", or load Odoo’s lists from the server.' }));

const columns = [
  { key: 'name', header: { ar: 'القائمة', en: 'List' }, width: 'minmax(170px,1.3fr)', sortable: true },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(170px,1.4fr)' },
  { key: 'branch', header: { ar: 'الفرع', en: 'Branch' }, width: '130px' },
  { key: 'itemsCount', header: { ar: 'المنتجات', en: 'Products' }, width: '82px', kind: 'num', sortable: true, value: (r) => fmtNum(r.itemsCount) },
  { key: 'createdAt', header: { ar: 'أُنشئت', en: 'Created' }, width: '90px', kind: 'date', sortable: true, value: (r) => fmtDateOnly(r.createdAt) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '112px', bare: true },
];

/** A product picked in the filter: the lists it is on. An emptied box removes the filter. */
function onProduct(p, text) {
  if (p) nav.setFilter('product', p.sku);
  else if (!String(text || '').trim() && product.value) nav.setFilter('product', '');
}
const creating = ref(false);
function onCreated(l) { creating.value = false; list.refetch(); if (l?.id) nav.patch({ list: l.id }); }
</script>

<template>
  <PortalFilters origin :search="{ ar: 'بحث باسم القائمة أو العميل…', en: 'Search list or customer…' }">
    <div class="w-[200px]"><ProductPick small :value="product" @pick="onProduct" /></div>
    <Tabs :model-value="state" :tabs="STATE_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k)" />
    <Tabs :model-value="linked" :tabs="LINKED_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('linked', k)" />
    <button v-if="branchId" type="button" class="pill active" @click="nav.setFilter('branchId', '')">{{ t('فرع واحد ✕', 'One branch ✕') }}</button>
    <span class="grow" />
    <Btn v-if="canManage" size="sm" tone="primary" :label="{ ar: '+ قائمة', en: '+ List' }" @click="creating = true" />
  </PortalFilters>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="opened" :sort="sort" server-sort :empty-text="empty"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ list: r.id, ipage: null, iq: null })">
      <template #cell-name="{ row }"><div class="cell-name truncate" dir="auto">{{ row.name }}</div></template>
      <template #cell-customer="{ row }"><PartyName :party="row.customer" /></template>
      <template #cell-branch="{ row }"><span v-if="row.branch" class="truncate text-[10.5px] font-bold" dir="auto">{{ row.branch.name }}</span><span v-else class="text-faint">—</span></template>
      <template #cell-status="{ row }">
        <div class="flex flex-wrap justify-center gap-1">
          <Chip small :map="ACTIVE_LABELS" :k="row.active ? 'active' : 'archived'" />
          <Chip v-if="row.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </div>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('قائمة المفضلة هي ما يطلبه العميل مرة بعد مرة، بالكمية المعتادة لكل منتج. «اطلب مجددًا» يحوّل القائمة إلى عرض سعر مسودة في المبيعات لعميلها.', 'A favourite list is what a customer orders again and again, with the usual quantity of each product. “Order again” turns a list into a draft quotation in Sales for its customer.') }}</div>

  <FavoriteListDrawer :id="opened" @close="nav.patch({ list: null, ipage: null, iq: null })" @changed="list.refetch()" />
  <FavoriteListForm :open="creating" @close="creating = false" @done="onCreated" />
</template>
