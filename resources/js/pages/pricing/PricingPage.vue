<script setup>
// «قوائم الأسعار» — the section that replaces Odoo's price lists: the price check, the customer price lists with their
// rules, the list of each customer, the list price of each product and the supplier prices. Everything lives in the
// query string, so every view, filter and open list is a link (the keys are listed at `usePricingNav` in ./pricing.js):
//   /pricing?customer=CUS-1004&product=P00325&qty=12      what a customer pays, and why
//   /pricing?tab=lists&list=PRL-2026-00012                one list, its rules and its customers
//   /pricing?tab=customers&assigned=no                    customers on the default list
//   /pricing?tab=suppliers&product=P00325                 who sells a product, at what price
// Reading needs page.pricing; customer lists, list prices and the list of each customer need pricelist.manage;
// supplier prices need supplierprice.manage — and are shown only to those who may see cost (`lookups.costVisible`).
// Where the section follows Odoo (`lookups.follows`) what came from Odoo is read-only: the server answers
// PRICING_OWNED_BY_SOURCE, and the screens offer «نسخ» for a list and no edit button for what Odoo owns.
import { computed, ref } from 'vue';
import { Btn, ErrorBanner, PageHead, Tabs } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CheckTab from './CheckTab.vue';
import CustomersTab from './CustomersTab.vue';
import ListDrawer from './ListDrawer.vue';
import ListForm from './ListForm.vue';
import ListPricesTab from './ListPricesTab.vue';
import ListsTab from './ListsTab.vue';
import SupplierPricesTab from './SupplierPricesTab.vue';
import { usePricingLookups, usePricingNav, usePricingSummary } from './pricing';

const auth = useAuth();
const nav = usePricingNav();
const { listOpen } = nav;
const lk = usePricingLookups();
const { follows, costVisible } = lk;
const lookupsError = computed(() => lk.query.error.value);
const summary = usePricingSummary();
const s = summary.data;
const canManage = computed(() => auth.can('pricelist.manage'));

const tabs = computed(() => [
  { k: 'check', label: { ar: 'فحص السعر', en: 'Price check' } },
  { k: 'lists', label: { ar: 'قوائم العملاء', en: 'Customer lists' }, badge: s.value?.lists?.active },
  { k: 'customers', label: { ar: 'قائمة كل عميل', en: 'Each customer\'s list' } },
  { k: 'base', label: { ar: 'الأسعار المعلنة', en: 'List prices' }, badge: s.value?.listPrices?.missing || null },
  ...(costVisible.value ? [{ k: 'suppliers', label: { ar: 'أسعار الموردين', en: 'Supplier prices' }, badge: s.value?.supplierPrices?.pending || null }] : []),
]);
// a tab the caller may not see (a link to the supplier prices without the right to see cost) falls back to the price check
const tab = computed(() => (tabs.value.some((x) => x.k === nav.tab.value) ? nav.tab.value : 'check'));

const creating = ref(false);
function created(list) { creating.value = false; if (list?.code) nav.go('lists', { list: list.code }); }
</script>

<template>
  <PageHead :sub="t('سعر كل منتج لكل عميل ولماذا · قوائم العملاء وقواعدها · قائمة كل عميل · الأسعار المعلنة · أسعار الموردين', 'What each product costs each customer, and why · customer lists and their rules · each customer\'s list · list prices · supplier prices')">
    <Btn v-if="canManage" tone="primary" :label="{ ar: '+ قائمة أسعار', en: '+ Price list' }" @click="creating = true" />
  </PageHead>

  <ErrorBanner :error="lookupsError" :closable="false" />
  <div v-if="follows" class="hint purple !mt-0 mb-3">
    {{ t('هذا القسم يتبع أودو حاليًا: القوائم والقواعد والأسعار القادمة من أودو تُعرض هنا ويُسعَّر بها وتُعدَّل هناك، وتصل تعديلاتها بعد كل مزامنة. ما يُنشأ هنا — قائمة جديدة، نسخة من قائمة، سعر مورد — يُدار هنا بالكامل.',
      'This section follows Odoo for now: lists, rules and prices that come from Odoo are shown and used here and changed there; their changes arrive after every synchronisation. What is created here — a new list, a copy of a list, a supplier price — is managed here in full.') }}
    <span v-if="s?.odoo?.waiting" class="font-extrabold"> {{ t(`(${fmtNum(s.odoo.waiting)} تغييرًا في أودو بانتظار المزامنة القادمة)`, `(${fmtNum(s.odoo.waiting)} Odoo changes wait for the next run)`) }}</span>
  </div>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" />
  <CheckTab v-if="tab === 'check'" />
  <ListsTab v-else-if="tab === 'lists'" :summary="s" />
  <CustomersTab v-else-if="tab === 'customers'" />
  <ListPricesTab v-else-if="tab === 'base'" />
  <SupplierPricesTab v-else-if="tab === 'suppliers'" />

  <ListDrawer :code="listOpen" @close="nav.closeList()" />
  <ListForm :open="creating" @close="creating = false" @done="created" />
</template>
