<script setup>
// The taxpayer and the tax details of customers and suppliers (`view=profile|customer|supplier`):
//   profile    ProfileForm.vue (GET / PATCH /tax/profile)
//   customer / supplier   GET /tax/parties?type=…&state=complete|incomplete|noVat&position=… — search, sort
//              (code · name · vat), paging; a row opens the partner's tax details (`party=<id>`, PartyDrawer.vue)
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import PartyDrawer from './PartyDrawer.vue';
import ProfileForm from './ProfileForm.vue';
import { INVOICE_KIND, TONE, labelOf, nameOf, useSearchParam, useTaxLookups, useTaxNav } from './tax';

const nav = useTaxNav();
const lk = useTaxLookups();
const VIEWS = ['profile', 'customer', 'supplier'];
const view = computed(() => (VIEWS.includes(nav.get('view')) ? nav.get('view') : 'profile'));
const views = [
  { k: 'profile', label: { ar: 'المكلّف', en: 'Taxpayer' } },
  { k: 'customer', label: { ar: 'العملاء', en: 'Customers' } },
  { k: 'supplier', label: { ar: 'الموردون', en: 'Suppliers' } },
];
const setView = (v) => nav.go('parties', v === 'profile' ? {} : { view: v });

const search = useSearchParam(nav);
const SORTS = ['code', 'name', 'vat'];
const sort = nav.sortOf(SORTS);
const list = useList(() => (view.value !== 'profile' ? '/tax/parties' : null), () => ({
  type: view.value, state: nav.get('state') || undefined, position: nav.get('position') || undefined, q: nav.get('q') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const STATES = [{ v: 'complete', l: { ar: 'العنوان مكتمل', en: 'Address complete' } }, { v: 'incomplete', l: { ar: 'العنوان ناقص', en: 'Address incomplete' } }, { v: 'noVat', l: { ar: 'بلا رقم ضريبي', en: 'No VAT number' } }];
const hasFilters = computed(() => ['q', 'state', 'position'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ q: null, state: null, position: null, page: null });
const complete = (r) => !!(r.street && r.city && r.buildingNo && r.postalCode && r.district);
const positionName = (id) => { const p = lk.positions.value.find((x) => x.id === id); return p ? nameOf(p) : '—'; };
const codeName = (id) => lk.codes.value.find((x) => x.id === id)?.code || '—';
const columns = computed(() => [
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '96px', kind: 'id', sortable: true },
  { key: 'name', header: { ar: 'الاسم', en: 'Name' }, width: 'minmax(170px,1.6fr)', sortable: true },
  { key: 'vat', header: { ar: 'الرقم الضريبي', en: 'VAT number' }, width: '130px', sortable: true },
  { key: 'address', header: { ar: 'العنوان الوطني', en: 'National address' }, width: 'minmax(150px,1.3fr)' },
  { key: 'position', header: { ar: 'الموقف / الرمز', en: 'Position / code' }, width: '130px' },
  { key: 'invoiceKind', header: { ar: 'نوع الفاتورة', en: 'Invoice kind' }, width: '120px', hidden: view.value !== 'customer', value: (r) => labelOf(INVOICE_KIND, r.invoiceKind) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '104px', bare: true },
]);
</script>

<template>
  <Tabs :model-value="view" :tabs="views" variant="sm" @update:model-value="setView" />
  <ProfileForm v-if="view === 'profile'" />
  <template v-else>
    <div class="row wrap mb-2 items-end gap-2">
      <TextInput :model-value="search.text.value" small field-class="w-full sm:w-[230px]" :placeholder="{ ar: 'بحث بالرمز أو الاسم أو الرقم الضريبي…', en: 'Search code, name, VAT number…' }" @update:model-value="search.type" @enter="search.apply" />
      <SelectInput :model-value="nav.get('state')" small :options="STATES" :placeholder="{ ar: '— كل الحالات —', en: '— any state —' }" @update:model-value="(v) => nav.setFilter('state', v)" />
      <SelectInput :model-value="nav.get('position')" small :options="lk.positionOptions.value" :placeholder="{ ar: '— كل المواقف —', en: '— any position —' }" @update:model-value="(v) => nav.setFilter('position', v)" />
      <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'مسح الفلاتر', en: 'Clear filters' }" @click="clear" />
    </div>
    <div class="hint !mt-0 mb-2">{{ t('العنوان الوطني والمعرّف يُطبعان على الفاتورة الإلكترونية القياسية؛ الموقف الضريبي والرمز الافتراضي يحددان رمز مستندات الطرف.', 'The national address and identification are printed on the standard e-invoice; the fiscal position and default code decide the code of the partner\'s documents.') }}</div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.partnerId" :selected-key="nav.get('party') || null" :sort="sort" server-sort
                 :empty-text="hasFilters ? { ar: 'لا أطراف تطابق البحث والفلاتر.', en: 'No partner matches the search and filters.' } : { ar: 'لا أطراف.', en: 'No partner.' }"
                 @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ party: r.partnerId })">
        <template #cell-code="{ row }"><span class="num font-bold">{{ row.code || '—' }}</span></template>
        <template #cell-name="{ row }"><span class="cell-name truncate" dir="auto">{{ nameOf(row) }}</span></template>
        <template #cell-vat="{ row }"><span class="num ltr text-[10px]" :class="row.vatNo ? '' : 'text-faint'">{{ row.vatNo || '—' }}</span></template>
        <template #cell-address="{ row }">
          <span class="truncate text-[10px]" dir="auto">{{ [row.buildingNo, row.street, row.district, row.city || row.masterCity, row.postalCode].filter(Boolean).join(' · ') || row.masterAddress || '—' }}</span>
        </template>
        <template #cell-position="{ row }">
          <div class="min-w-0 text-center">
            <div class="truncate text-[10px]">{{ row.fiscalPositionId ? positionName(row.fiscalPositionId) : '—' }}</div>
            <div v-if="row.defaultCodeId" class="cell-sub">{{ codeName(row.defaultCodeId) }}</div>
          </div>
        </template>
        <template #cell-state="{ row }">
          <Chip small :label="complete(row) ? { ar: 'مكتمل', en: 'Complete' } : { ar: 'ناقص', en: 'Incomplete' }" :fg="complete(row) ? TONE.ok.fg : TONE.warn.fg" :bg="complete(row) ? TONE.ok.bg : TONE.warn.bg" />
        </template>
      </DataTable>
    </div>
    <PartyDrawer :type="view" :id="nav.get('party') || null" @close="nav.patch({ party: null })" />
  </template>
</template>
