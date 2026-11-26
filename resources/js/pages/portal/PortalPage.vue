<script setup>
// «بوابة العملاء» — the staff side of what customers do through the portal: their branches, their favourite lists,
// their onboarding applications with documents, the orders they request and the log of order-line changes, and their
// statements. Everything lives in the query string, so every tab, filter and open record is a link (the keys are
// listed at `usePortalNav` in ./portal.js):
//   /portal                                          customer branches
//   /portal?tab=favorites&customer=CUS-1008&list=…   one favourite list of a customer
//   /portal?tab=applications&status=review           applications waiting for a review
//   /portal?tab=requests&view=changes&type=removed   the log of order-line changes
//   /portal?tab=statements&stmt=…                    one statement
// Reading needs page.portal; every change needs portal.manage (hidden without it; the server enforces both).
// Where the section follows Odoo (`summary.following`) the records that came from Odoo are read-only: the server
// answers PORTAL_OWNED_BY_SOURCE and a record offers only what is OPS's own.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner, KpiCard, KpiGrid, PageHead, Tabs } from '@/components';
import { fmtNum, t } from '@/i18n';
import ApplicationsTab from './ApplicationsTab.vue';
import BranchesTab from './BranchesTab.vue';
import FavoritesTab from './FavoritesTab.vue';
import RequestsTab from './RequestsTab.vue';
import StatementsTab from './StatementsTab.vue';
import { TONE, usePortalNav } from './portal';

const nav = usePortalNav();
const { tab } = nav;

const summaryQ = useGet('/portal/summary', undefined, { refetchInterval: 120_000 });
const s = computed(() => summaryQ.data.value || null);

const tabs = computed(() => [
  { k: 'branches', label: { ar: 'فروع العملاء', en: 'Customer branches' } },
  { k: 'favorites', label: { ar: 'قوائم المفضلة', en: 'Favourite lists' } },
  { k: 'applications', label: { ar: 'طلبات فتح الحساب', en: 'Onboarding applications' }, badge: s.value?.applications.waiting || null },
  { k: 'requests', label: { ar: 'طلبات الشراء وسجل التغييرات', en: 'Order requests & line changes' }, badge: s.value?.requests.pending || null },
  { k: 'statements', label: { ar: 'كشوف الحساب', en: 'Statements' } },
]);
</script>

<template>
  <PageHead :sub="t('فروع العملاء · قوائم المفضلة · طلبات فتح الحساب · طلبات الشراء وسجل تغييرات الأسطر · كشوف الحساب', 'Customer branches · favourite lists · onboarding applications · order requests and the line-change log · statements')" />

  <ErrorBanner :error="summaryQ.error.value" :closable="false" />
  <KpiGrid compact class="!mb-3">
    <KpiCard compact clickable :active="tab === 'branches'" :value="s?.branches.active" :label="{ ar: 'فروع نشطة', en: 'Active branches' }" :loading="!s" @click="nav.go('branches')" />
    <KpiCard compact clickable :active="tab === 'favorites'" :value="s?.favorites.active" :label="{ ar: 'قوائم مفضلة', en: 'Favourite lists' }" color="#654e92" :loading="!s" @click="nav.go('favorites')">
      <template v-if="s" #sub><span class="num">{{ fmtNum(s.favorites.items) }}</span> {{ t('منتج في القوائم', 'products on them') }}</template>
    </KpiCard>
    <KpiCard compact clickable :value="s?.applications.waiting" :label="{ ar: 'طلبات فتح حساب بانتظار المراجعة', en: 'Applications to review' }" :color="s?.applications.waiting ? TONE.warn.fg : TONE.ok.fg" :loading="!s"
             @click="nav.go('applications', { status: 'review' })" />
    <KpiCard compact clickable :value="s?.requests.pending" :label="{ ar: 'طلبات شراء بانتظار القرار', en: 'Order requests pending' }" :color="s?.requests.pending ? TONE.warn.fg : TONE.ok.fg" :loading="!s"
             @click="nav.go('requests', { status: 'pending' })" />
    <KpiCard compact clickable :value="s?.lineChanges.total" :label="{ ar: 'تغييرات مسجَّلة على أسطر الطلبات', en: 'Logged order-line changes' }" color="#3C79F5" :loading="!s" @click="nav.go('requests', { view: 'changes' })" />
    <KpiCard compact clickable :active="tab === 'statements'" :value="s?.statements.issued" :label="{ ar: 'كشوف حساب صادرة', en: 'Statements issued' }" :loading="!s" @click="nav.go('statements')">
      <template v-if="s" #sub>{{ t(`منها ${s.statements.ops} من نظام العمليات`, `${s.statements.ops} of them issued in OPS`) }}</template>
    </KpiCard>
  </KpiGrid>

  <div v-if="s?.following" class="hint purple !mt-0 mb-3">
    {{ t('هذا القسم يتبع أودو حاليًا: ما جاء من أودو يُعرض هنا ويُعدَّل هناك، ويُضاف إليه هنا ما يخص نظام العمليات — عنوان الفرع وجهة الاتصال ونافذة التسليم، فرع القائمة، الملاحظات والمستندات، و«اطلب مجددًا». ما يُنشأ هنا يُدار هنا بالكامل.',
      'This section follows Odoo for now: what came from Odoo is shown here and changed there, and OPS adds what is its own — a branch’s address, contact and delivery window, the branch a list is kept for, notes and documents, and “order again”. What is created here is managed here in full.') }}
    <template v-if="s.favorites.unlinked || s.branches.unlinked">
      {{ t(`${s.favorites.unlinked} قائمة و${s.branches.unlinked} فرع تخص عملاء في أودو لم يصبحوا عملاء في نظام العمليات بعد: تبقى باسمها في أودو وتُربط تلقائيًا عند ربط العميل.`,
        `${s.favorites.unlinked} list(s) and ${s.branches.unlinked} branch(es) belong to Odoo customers that are not OPS customers yet: they keep the name Odoo gives and are linked by themselves once the customer is.`) }}
    </template>
  </div>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" />
  <BranchesTab v-if="tab === 'branches'" />
  <FavoritesTab v-else-if="tab === 'favorites'" />
  <ApplicationsTab v-else-if="tab === 'applications'" />
  <RequestsTab v-else-if="tab === 'requests'" />
  <StatementsTab v-else-if="tab === 'statements'" />
</template>
