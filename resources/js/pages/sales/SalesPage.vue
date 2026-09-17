<script setup>
// Sales — tabs quotations · sales orders · customers, header commands + quotation / + direct sales order / + customer.
// Data: /api/sales/* and /api/customers. Deep links: `/sales?tab=qt|so|cust&q=<search>`.
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Btn, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CustomerForm from './CustomerForm.vue';
import CustomersTab from './CustomersTab.vue';
import OrdersTab from './OrdersTab.vue';
import QuotationForm from './QuotationForm.vue';
import QuotationsTab from './QuotationsTab.vue';
import SoDrawer from './SoDrawer.vue';
import SoForm from './SoForm.vue';

const auth = useAuth();
const route = useRoute();
const router = useRouter();

const TABS = [{ k: 'qt', label: { ar: 'عروض الأسعار', en: 'Quotations' } }, { k: 'so', label: { ar: 'أوامر البيع', en: 'Sales Orders' } }, { k: 'cust', label: { ar: 'العملاء', en: 'Customers' } }];
const tab = computed(() => (['qt', 'so', 'cust'].includes(route.query.tab) ? route.query.tab : 'qt'));
const initialQ = computed(() => (typeof route.query.q === 'string' ? route.query.q : ''));
/** Switching tabs keeps the URL in sync and drops the search of the previous tab. */
function setTab(k) { router.replace({ query: { ...route.query, tab: k, q: undefined } }); }

/** Which create form is open: 'quotation' | 'so' | 'customer' | null (+ the customer it was opened for). */
const form = ref(null);
const formCustomer = ref(null);
function openForm(k, customer = null) { formCustomer.value = customer; form.value = k; }

const openSo = ref(null);
function onSoCreated(so) { setTab('so'); openSo.value = so.number; }
</script>

<template>
  <div>
    <PageHead :sub="t('عرض سعر ← أمر بيع ← تجهيز ← توصيل — مرتبط بالمخزون والحد الائتماني', 'Quote → sales order → picking → delivery — linked to stock and credit limit')">
      <Btn v-if="auth.can('sales.manage')" tone="primary" :label="{ ar: '+ عرض سعر', en: '+ Quotation' }" @click="openForm('quotation')" />
      <Btn v-if="auth.can('so.reserve')" :label="{ ar: '+ أمر بيع مباشر', en: '+ Sales order' }" @click="openForm('so')" />
      <Btn v-if="auth.can('customer.manage')" :label="{ ar: '+ عميل', en: '+ Customer' }" @click="openForm('customer')" />
    </PageHead>

    <Tabs :model-value="tab" :tabs="TABS" @update:model-value="setTab" />
    <QuotationsTab v-if="tab === 'qt'" :initial-q="initialQ" @open-so="(n) => (openSo = n)" />
    <OrdersTab v-else-if="tab === 'so'" :initial-q="initialQ" @open-so="(n) => (openSo = n)" />
    <CustomersTab v-else :initial-q="initialQ" @new-quote="(code) => openForm('quotation', code)" @new-so="(code) => openForm('so', code)" />

    <QuotationForm :open="form === 'quotation'" :initial-customer="formCustomer" @close="form = null" @done="setTab('qt')" />
    <SoForm :open="form === 'so'" :initial-customer="formCustomer" @close="form = null" @done="onSoCreated" />
    <CustomerForm :open="form === 'customer'" @close="form = null" />
    <SoDrawer :number="openSo" @close="openSo = null" />
  </div>
</template>
