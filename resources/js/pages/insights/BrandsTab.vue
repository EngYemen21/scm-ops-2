<script setup>
// «العلامات» — brand owners.
//   outsider (a brand owner's user): his own brand straight away (dashboard, franchisees, alerts) — nothing else.
//   manager (brandowner.manage): view=owners the list (search · state · sort · paging) → a row opens the brand in a
//     drawer (`owner=<id>`); view=rules the alert rules; view=history the cutover day and the copy of Odoo's invoices.
// GET /insights/brands → { items, total, page, pageSize, pages }; POST /insights/brands (OwnerForm).
import { computed, ref } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import AlertRules from './AlertRules.vue';
import BrandDetail from './BrandDetail.vue';
import HistorySettings from './HistorySettings.vue';
import OwnerForm from './OwnerForm.vue';
import { ODOO_CHIP, OWNER_STATE, OWNER_STATES, fmtDay, useInsightsNav, useSearchParam, userName } from './insights';

const props = defineProps({
  /** GET /insights/me */
  me: { type: Object, required: true },
});

const nav = useInsightsNav();
const { page } = nav;
const manager = computed(() => !!props.me.manager);
const mine = computed(() => (props.me.outsider ? props.me.brandOwner : null));

// ---- manager: sub views
const VIEWS = ['owners', 'rules', 'history'];
const view = computed(() => (VIEWS.includes(nav.get('view')) ? nav.get('view') : 'owners'));
const views = [
  { k: 'owners', label: { ar: 'ملاك العلامات', en: 'Brand owners' } },
  { k: 'rules', label: { ar: 'قواعد التنبيهات', en: 'Alert rules' } },
  { k: 'history', label: { ar: 'تاريخ أودو ويوم التحويل', en: 'Odoo history & cutover' } },
];
const setView = (k) => nav.go('brands', k === 'owners' ? {} : { view: k });

// ---- manager: the list
const { text, type, apply } = useSearchParam(nav);
const SORTS = ['name', 'state', 'brand', 'createdAt'];
const sort = nav.sortOf(SORTS, 'asc');
const state = computed(() => (OWNER_STATES.includes(nav.get('state')) ? nav.get('state') : ''));
const list = useList(() => (manager.value && view.value === 'owners' ? '/insights/brands' : null), () => ({
  q: nav.get('q') || undefined, state: state.value || undefined, sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const stateTabs = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...OWNER_STATES.map((k) => ({ k, label: { ar: OWNER_STATE[k].ar, en: OWNER_STATE[k].en } }))]);
const hasFilters = computed(() => !!nav.get('q') || !!state.value);
const empty = computed(() => (hasFilters.value
  ? { ar: 'لا مالك علامة يطابق البحث والفلتر.', en: 'No brand owner matches the search and filter.' }
  : { ar: 'لا ملاك علامات بعد — أضف أول مالك من «+ مالك علامة».', en: 'No brand owner yet — add the first one with "+ Brand owner".' }));

const columns = [
  { key: 'name', header: { ar: 'المالك', en: 'Owner' }, width: 'minmax(200px,1.6fr)', sortable: true },
  { key: 'brand', header: { ar: 'العلامة', en: 'Brand' }, width: 'minmax(120px,1fr)', sortable: true },
  { key: 'user', header: { ar: 'المستخدم المرتبط', en: 'Linked user' }, width: '140px' },
  { key: 'franchisees', header: { ar: 'فروع تشتري', en: 'Buying franchisees' }, width: '96px', kind: 'num' },
  { key: 'unreadAlerts', header: { ar: 'تنبيهات غير مقروءة', en: 'Unread alerts' }, width: '100px', kind: 'num' },
  { key: 'access', header: { ar: 'ما يراه', en: 'May see' }, width: '150px', bare: true },
  { key: 'createdAt', header: { ar: 'أُنشئ', en: 'Created' }, width: '90px', kind: 'date', sortable: true, value: (r) => fmtDay(r.createdAt) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '110px', sortable: true, bare: true },
];
const accessChips = (r) => [
  r.allowInvoice && { k: 'i', ar: 'الفواتير', en: 'Invoices' }, r.allowReturn && { k: 'r', ar: 'المرتجعات', en: 'Returns' },
  r.allowPayment && { k: 'p', ar: 'المدفوعات', en: 'Payments' }, r.allowMargin && { k: 'm', ar: 'الهامش', en: 'Margin' },
].filter(Boolean);

// ---- the open brand (drawer, manager)
const ownerOpen = computed(() => (manager.value ? nav.get('owner') || null : null));
const detail = useGet(() => (ownerOpen.value ? `/insights/brands/${encodeURIComponent(ownerOpen.value)}` : null));
const drawerTitle = computed(() => detail.data.value?.name || t('مالك العلامة', 'Brand owner'));
const drawerSub = computed(() => (detail.data.value?.brandName ? `${t('العلامة', 'Brand')}: ${detail.data.value.brandName}` : null));
const openOwner = (id) => nav.patch({ owner: id, sec: null, fig: null, figCustomer: null, figMonth: null, figProduct: null, figKind: null, figSource: null });
const closeOwner = () => nav.patch({ owner: null, sec: null, from: null, to: null, fig: null, figCustomer: null, figMonth: null, figProduct: null, figKind: null, figSource: null });

// ---- create / edit
const creating = ref(false);
const editing = ref(null);
function onSaved(o) { creating.value = false; editing.value = null; if (o?.id) openOwner(o.id); }
</script>

<template>
  <!-- outsider: his own brand only -->
  <template v-if="mine">
    <div v-if="mine.state !== 'active'" class="banner amber">
      {{ t(`حساب العلامة «${mine.name}» غير نشط حاليًا — تُفتح اللوحة عند تفعيله من الإدارة.`, `The account of the brand "${mine.name}" is not active — the dashboard opens once the company activates it.`) }}
    </div>
    <BrandDetail v-else :owner-id="mine.id" :me="me" inline />
  </template>

  <template v-else-if="manager">
    <Tabs :model-value="view" :tabs="views" variant="sm" @update:model-value="setView" />

    <template v-if="view === 'owners'">
      <div class="row wrap mb-2">
        <TextInput small type="search" class="w-[250px]" :model-value="text" :placeholder="{ ar: 'بحث بالمالك، العلامة، المستخدم…', en: 'Search owner, brand, user…' }" @update:model-value="type" @enter="apply" />
        <Tabs :model-value="state" :tabs="stateTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k)" />
        <div class="grow" />
        <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="nav.patch({ q: null, state: null, page: null })" />
        <Btn tone="primary" size="sm" :label="{ ar: '+ مالك علامة', en: '+ Brand owner' }" @click="creating = true" />
      </div>
      <ErrorBanner :error="list.error.value" :closable="false" />
      <div class="card">
        <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="ownerOpen" :sort="sort" server-sort :empty-text="empty"
                   @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => openOwner(r.id)">
          <template #cell-name="{ row }">
            <div class="min-w-0">
              <div class="cell-name truncate" dir="auto">{{ row.name }}</div>
              <div v-if="row.ownedBySource" class="cell-sub">{{ t('من أودو', 'From Odoo') }}<template v-if="row.externalRef"> · <span class="num ltr inline-block">#{{ row.externalRef }}</span></template></div>
            </div>
          </template>
          <template #cell-brand="{ row }"><span class="truncate text-[10.5px] font-bold text-violet" dir="auto">{{ row.brandName || '—' }}</span></template>
          <template #cell-user="{ row }">
            <span v-if="row.user" class="truncate text-[10px] text-sec" dir="auto">{{ userName(row.user) }} <span class="num ltr inline-block text-muted">{{ row.user.username }}</span></span>
            <span v-else-if="row.userName" class="truncate text-[10px] text-muted" dir="auto">{{ row.userName }}</span>
            <span v-else class="text-[10px] font-bold text-warn">{{ t('بلا مستخدم', 'No user') }}</span>
          </template>
          <template #cell-franchisees="{ row }">{{ fmtNum(row.franchisees) }}</template>
          <template #cell-unreadAlerts="{ row }"><span :class="row.unreadAlerts ? 'font-extrabold text-bad' : 'text-faint'">{{ fmtNum(row.unreadAlerts) }}</span></template>
          <template #cell-access="{ row }">
            <span class="inline-flex flex-wrap justify-center gap-1">
              <span class="text-[9px] font-bold text-sec">{{ t('المشتريات', 'Purchases') }}</span>
              <span v-for="c in accessChips(row)" :key="c.k" class="text-[9px] font-bold text-sec">· {{ t(c.ar, c.en) }}</span>
            </span>
          </template>
          <template #cell-state="{ row }">
            <span class="inline-flex flex-wrap items-center justify-center gap-1">
              <Chip small :map="OWNER_STATE" :k="row.state" />
              <Chip v-if="row.ownedBySource" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" />
            </span>
          </template>
        </DataTable>
      </div>
    </template>
    <AlertRules v-else-if="view === 'rules'" />
    <HistorySettings v-else-if="view === 'history'" />

    <Drawer :open="!!ownerOpen" :title="drawerTitle" :sub="drawerSub" :width="1120" @close="closeOwner">
      <ErrorBanner :error="detail.error.value" :closable="false" />
      <BrandDetail v-if="ownerOpen && !detail.error.value" :owner-id="ownerOpen" :me="me" @edit="(o) => (editing = o)" />
    </Drawer>
    <OwnerForm :open="creating || !!editing" :owner="editing" @close="creating = false; editing = null" @done="onSaved" />
  </template>

  <div v-else class="empty dashed">{{ t('العلامات متاحة لمدير العلامات ولحساب مالك العلامة فقط.', 'Brands are open to the brand manager and to a brand owner\'s account only.') }}</div>
</template>
