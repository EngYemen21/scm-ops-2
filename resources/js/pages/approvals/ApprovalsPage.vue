<script setup>
// «الاعتمادات» — approval requests, the section that replaces Odoo's Approvals app: what waits for my decision, my own
// requests, the ones I am an approver of, every request (approval.manage) and the categories that rule them.
// Everything on screen lives in the query string, so every view is a link (the keys are listed at `useApprovalsNav`):
//   /approvals?tab=waiting          /approvals?tab=all&status=pending&category=OD-5          /approvals?request=APR-2026-00012
//   /approvals?new=1 (the form of a new request)          /approvals?tab=categories&cat=OD-5
// GET /approvals/summary gives the counts of the page head and the tab badges (refreshed every minute). Opening the
// section needs page.approvals, raising a request approval.request, categories approval.manage; deciding a request is
// the right of its named approvers — the server enforces all of it, the page only hides what it would refuse.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, KpiCard, KpiGrid, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CategoriesTab from './CategoriesTab.vue';
import CategoryDrawer from './CategoryDrawer.vue';
import RequestDrawer from './RequestDrawer.vue';
import RequestForm from './RequestForm.vue';
import RequestsList from './RequestsList.vue';
import { useApprovalsNav } from './approvals';

const auth = useAuth();
const nav = useApprovalsNav();
const { tabParam, requestOpen, categoryOpen, creating, editing } = nav;

const summaryQ = useGet('/approvals/summary', undefined, { refetchInterval: 60_000 });
const summary = computed(() => summaryQ.data.value || null);
const canManage = computed(() => auth.can('approval.manage'));
const canRequest = computed(() => auth.can('approval.request'));

/** The tab of the link; without one: what waits for my decision when something does, otherwise my own requests. */
const tab = computed(() => {
  if (tabParam.value && (tabParam.value !== 'all' || canManage.value)) return tabParam.value;
  if (!summary.value) return summaryQ.error.value ? 'mine' : '';
  return summary.value.waiting > 0 ? 'waiting' : 'mine';
});
const tabs = computed(() => [
  { k: 'waiting', label: { ar: 'بانتظار قراري', en: 'Waiting for me' }, badge: summary.value?.waiting },
  { k: 'mine', label: { ar: 'طلباتي', en: 'My requests' }, badge: summary.value?.mine?.pending },
  { k: 'involved', label: { ar: 'ما أنا معتمد فيه', en: 'I am an approver of' } },
  ...(canManage.value ? [{ k: 'all', label: { ar: 'كل الطلبات', en: 'All requests' }, badge: summary.value?.all?.pending }] : []),
  { k: 'categories', label: { ar: 'أنواع الطلبات', en: 'Categories' } },
]);
/** The request the form edits (`edit=1` beside `request=`), or null for a new one. */
const formNumber = computed(() => (editing.value ? requestOpen.value : null));
const formOpen = computed(() => (creating.value || editing.value) && canRequest.value);
const preset = computed(() => (creating.value ? nav.get('new') : null));
/** Saved (or sent): the form closes and the request itself opens. */
const onFormDone = (number) => nav.patch({ new: null, edit: null, cat: null, request: number });
const onCategorySaved = (code) => nav.patch({ cat: code });
</script>

<template>
  <PageHead :sub="t('طلبات الاعتماد: من يطلب، من يعتمد، وأين وصل كل طلب', 'Approval requests: who asks, who approves, and where each request stands')">
    <Btn v-if="canRequest" tone="primary" :label="{ ar: '+ طلب جديد', en: '+ New request' }" @click="nav.openNew()" />
  </PageHead>

  <ErrorBanner :error="summaryQ.error.value" :closable="false" />
  <KpiGrid compact>
    <KpiCard :value="summary?.waiting" :label="{ ar: 'بانتظار قراري', en: 'Waiting for my decision' }" :color="summary?.waiting ? '#b26a16' : '#20242E'" :loading="summaryQ.isLoading.value" clickable :active="tab === 'waiting'" @click="nav.go('waiting')" />
    <KpiCard :value="summary?.mine?.pending" :label="{ ar: 'طلباتي قيد الاعتماد', en: 'My requests in approval' }" color="#3C79F5" :loading="summaryQ.isLoading.value" clickable @click="nav.go('mine', { status: 'pending' })" />
    <KpiCard :value="summary?.mine?.draft" :label="{ ar: 'مسوداتي', en: 'My drafts' }" color="#55506a" :loading="summaryQ.isLoading.value" clickable @click="nav.go('mine', { status: 'draft' })" />
    <KpiCard :value="summary?.mine?.refused" :label="{ ar: 'طلباتي المرفوضة', en: 'My refused requests' }" :color="summary?.mine?.refused ? '#b23b3b' : '#20242E'" :loading="summaryQ.isLoading.value" clickable @click="nav.go('mine', { status: 'refused' })" />
    <KpiCard v-if="canManage" :value="summary?.all?.pending" :label="{ ar: 'كل الطلبات المعلّقة', en: 'All waiting requests' }" color="#654e92" :loading="summaryQ.isLoading.value" clickable @click="nav.go('all', { status: 'pending' })" />
  </KpiGrid>

  <div v-if="summary?.odooOwns && summary?.hasOdoo" class="hint purple !mt-0 mb-3">
    {{ t('هذا القسم يتابع تطبيق الاعتمادات في أودو: طلبات أودو وأنواعه تظهر هنا وتتحدّث تلقائيًا وتُدار من أودو (تحمل علامة «من أودو»)، ويمكن التعليق عليها وإرفاق ملفات. الطلبات التي تُرفع من هنا تُدار هنا بالكامل.', 'This section follows the Approvals app of Odoo: its requests and categories appear here, follow Odoo by themselves and are managed in Odoo (marked “From Odoo”); comments and files can be added to them. Requests raised here are managed here in full.') }}
  </div>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" />
  <div v-if="!tab" class="skel h-[220px]" />
  <CategoriesTab v-else-if="tab === 'categories'" :can-manage="canManage" :summary="summary" />
  <RequestsList v-else :key="tab" :scope="tab" :summary="summary" />

  <RequestDrawer :number="formOpen && editing ? null : requestOpen" @close="nav.closeRequest()" @edit="nav.openEdit()" />
  <RequestForm :open="formOpen" :number="formNumber" :preset="preset" @close="nav.closeForm()" @done="onFormDone" />
  <CategoryDrawer :code="categoryOpen" :can-manage="canManage" :can-request="canRequest" @close="nav.closeCategory()" @saved="onCategorySaved" @raise="(code) => nav.openNew(code)" />
</template>
