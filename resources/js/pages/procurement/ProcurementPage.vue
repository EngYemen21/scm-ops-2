<script setup>
// Procurement — tabs: purchase orders · requisitions · RFQ comparison · supplier quotations · suppliers · suggestions.
// Deep links: `?tab=po|pr|rfq|sq|sup|sugg`, `?tab=rfq&rfq=RFQ-…`, `?tab=sup&supplier=SUP-…`, `?q=…` (supplier search).
// The create forms live here so every tab (and the supplier drawer) can open them with initial values.
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, KpiCard, KpiGrid, PageHead, Tabs } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import PoForm from './PoForm.vue';
import PoTab from './PoTab.vue';
import PrForm from './PrForm.vue';
import PrTab from './PrTab.vue';
import RfqForm from './RfqForm.vue';
import RfqTab from './RfqTab.vue';
import SupQuoteForm from './SupQuoteForm.vue';
import SupplierForm from './SupplierForm.vue';
import SupplierQuotesTab from './SupplierQuotesTab.vue';
import SuppliersTab from './SuppliersTab.vue';
import SuggestionsTab from './SuggestionsTab.vue';
import { qs } from './shared';

const auth = useAuth();
const route = useRoute();
const router = useRouter();

const TAB_KEYS = ['po', 'pr', 'rfq', 'sq', 'sup', 'sugg'];
const tab = computed(() => { const k = qs(route.query.tab); return TAB_KEYS.includes(k) ? k : 'po'; });
const setTab = (k) => router.replace({ query: { ...route.query, tab: k, page: undefined } });

/** { pendingPrApprovals, openRfqs, poPendingApproval{ count, total, bySteps[] }, expectedInboundsThisWeek{ count, items[] }, posSentAwaitingConfirmation, urgentSuggestions } */
const dashQ = useGet('/procurement/dashboard', undefined, { refetchInterval: 60_000 });
const dash = computed(() => dashQ.data.value);

/** Open form: { kind: 'po' | 'pr' | 'rfq' | 'sq' | 'supplier', init? } | null */
const form = ref(null);
const editSup = ref(null);
const openForm = (kind, init) => { form.value = { kind, init }; };
const closeForm = () => { form.value = null; };
function newSupplier() { editSup.value = null; openForm('supplier'); }
function editSupplier(s) { editSup.value = s; openForm('supplier'); }

const tabs = computed(() => [
  { k: 'po', label: { ar: 'أوامر الشراء PO', en: 'Purchase Orders' }, badge: dash.value?.poPendingApproval?.count },
  { k: 'pr', label: { ar: 'طلبات الشراء PR', en: 'Requisitions' }, badge: dash.value?.pendingPrApprovals },
  { k: 'rfq', label: { ar: 'مقارنة العروض RFQ', en: 'RFQ Compare' }, badge: dash.value?.openRfqs },
  { k: 'sq', label: { ar: 'عروض الموردين', en: 'Supplier quotations' } },
  { k: 'sup', label: { ar: 'الموردون', en: 'Suppliers' } },
  { k: 'sugg', label: { ar: 'اقتراحات الشراء', en: 'Suggestions' }, badge: dash.value?.urgentSuggestions },
]);
</script>

<template>
  <PageHead :sub="t('Requisition → RFQ → PO → استلام — بمصفوفة اعتماد ديناميكية', 'Requisition → RFQ → PO → Receiving — dynamic approval matrix')">
    <Btn v-if="auth.can('po.create')" tone="primary" :label="{ ar: '+ أمر شراء', en: '+ PO' }" @click="openForm('po')" />
    <Btn v-if="auth.can('pr.create')" :label="{ ar: '+ طلب شراء', en: '+ Requisition' }" @click="openForm('pr')" />
    <Btn v-if="auth.can('rfq.create')" label="+ RFQ" @click="openForm('rfq')" />
    <Btn v-if="auth.can('supquote.create')" :label="{ ar: '+ عرض سعر مورد', en: '+ Supplier quote' }" @click="openForm('sq')" />
    <Btn v-if="auth.can('supplier.manage')" :label="{ ar: '+ مورد', en: '+ Supplier' }" @click="newSupplier" />
  </PageHead>

  <KpiGrid compact>
    <KpiCard compact clickable :value="dash?.poPendingApproval?.count" :label="{ ar: 'PO بانتظار الاعتماد', en: 'POs pending approval' }" :sub="dash ? `${fmtMoney(dash.poPendingApproval?.total)} ${t('ر.س', 'SAR')}` : null" color="#b26a16" :active="tab === 'po'" :loading="!dash" @click="setTab('po')" />
    <KpiCard compact clickable :value="dash?.pendingPrApprovals" :label="{ ar: 'PR بانتظار المراجعة', en: 'PRs pending review' }" color="#0d7f93" :active="tab === 'pr'" :loading="!dash" @click="setTab('pr')" />
    <KpiCard compact clickable :value="dash?.openRfqs" :label="{ ar: 'RFQ مفتوحة', en: 'Open RFQs' }" color="#654e92" :active="tab === 'rfq'" :loading="!dash" @click="setTab('rfq')" />
    <KpiCard compact clickable :value="dash?.posSentAwaitingConfirmation" :label="{ ar: 'مُرسلة بانتظار تأكيد المورد', en: 'Sent — awaiting confirmation' }" color="#3C79F5" :loading="!dash" @click="setTab('po')" />
    <KpiCard compact :value="dash?.expectedInboundsThisWeek?.count" :label="{ ar: 'شحنات متوقعة هذا الأسبوع', en: 'Expected inbound this week' }" color="#1d7a3e" :loading="!dash" />
    <KpiCard compact clickable :value="dash?.urgentSuggestions" :label="{ ar: 'اقتراحات عاجلة (نافد)', en: 'Urgent suggestions' }" color="#b23b3b" :active="tab === 'sugg'" :loading="!dash" @click="setTab('sugg')" />
  </KpiGrid>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="setTab" />
  <div class="mt-3">
    <PoTab v-if="tab === 'po'" @new="openForm('po')" />
    <PrTab v-else-if="tab === 'pr'" @new-rfq="openForm('rfq', $event)" />
    <RfqTab v-else-if="tab === 'rfq'" @quote="openForm('sq', $event)" />
    <SupplierQuotesTab v-else-if="tab === 'sq'" @new="openForm('sq')" />
    <SuppliersTab v-else-if="tab === 'sup'" @new-po="openForm('po', { supplierCode: $event.code })" @edit="editSupplier" @quote="openForm('sq', { supplierCode: $event.code })" />
    <SuggestionsTab v-else-if="tab === 'sugg'" />
  </div>

  <PoForm :open="form?.kind === 'po'" :initial="form?.init" @close="closeForm" @done="setTab('po')" />
  <PrForm :open="form?.kind === 'pr'" :initial="form?.init" @close="closeForm" @done="setTab('pr')" />
  <RfqForm :open="form?.kind === 'rfq'" :initial="form?.init" @close="closeForm" @done="setTab('rfq')" />
  <SupQuoteForm :open="form?.kind === 'sq'" :initial="form?.init" @close="closeForm" />
  <SupplierForm :open="form?.kind === 'supplier'" :supplier="editSup" @close="closeForm" />
</template>
