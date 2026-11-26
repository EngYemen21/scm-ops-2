<script setup>
// The filter row every list of the page starts with: the search box, the customer, where the record came from — and
// whatever the list adds in the default slot (state pills, buttons). All of it is the query string:
//   q · customer=<code | ref:id> · origin=ops|odoo
//   <PortalFilters :search="{ ar: 'بحث…', en: 'Search…' }" origin> <Tabs … /> <span class="grow" /> <Btn … /> </PortalFilters>
import { computed } from 'vue';
import { Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import CustomerPicker from './CustomerPicker.vue';
import { ORIGIN_TABS, usePortalNav, useSearchParam } from './portal';

defineProps({
  search: { type: [String, Object], default: () => ({ ar: 'بحث…', en: 'Search…' }) },
  /** Offer the "where it came from" pills. */
  origin: { type: Boolean, default: false },
  /** Offer the customer filter. */
  customer: { type: Boolean, default: true },
});

const nav = usePortalNav();
const { text, type, apply } = useSearchParam(nav);
const customerValue = computed(() => nav.get('customer'));
const originValue = computed(() => (['ops', 'odoo'].includes(nav.get('origin')) ? nav.get('origin') : ''));

/** A customer is a filter once one is picked; an emptied box removes the filter. */
function onCustomer(c, textValue) {
  if (c) nav.setFilter('customer', c.code);
  else if (!String(textValue || '').trim() && customerValue.value) nav.setFilter('customer', '');
}
</script>

<template>
  <div class="row wrap mb-2.5 !items-start">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="search" @update:model-value="type" @enter="apply" />
    <div v-if="customer" class="w-[210px]">
      <CustomerPicker small inactive :value="customerValue" :placeholder="{ ar: 'العميل: الاسم أو الرمز', en: 'Customer: name or code' }" @pick="onCustomer" />
      <button v-if="customerValue" type="button" class="mt-0.5 cursor-pointer border-0 bg-transparent p-0 text-[9.5px] font-extrabold text-violet" @click="nav.setFilter('customer', '')">{{ t('✕ إزالة فلتر العميل', '✕ Clear the customer filter') }}</button>
    </div>
    <Tabs v-if="origin" :model-value="originValue" :tabs="ORIGIN_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('origin', k)" />
    <slot />
  </div>
</template>
