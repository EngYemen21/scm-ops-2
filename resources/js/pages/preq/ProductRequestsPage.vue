<script setup>
// «طلبات الأصناف الجديدة» — the way a product enters the catalogue: somebody asks for a product, a reviewer checks
// it, and on approval the product is created in the catalogue and stays linked to its request. The section replaces
// Odoo's two request models and holds their history.
// Everything on screen lives in the query string, so every view is a link (the keys are listed at `usePreqNav`):
//   /preq?tab=board                      the review board: what waits, for whom, and for how long
//   /preq?tab=all&status=waiting&q=…     every request, with search, filters, sort and paging
//   /preq?tab=mine                       my own requests           /preq?open=PREQ-2026-00012   one request
//   /preq?new=1                          the form of a new request /preq?open=…&edit=1          editing the open one
// GET /product-requests/summary gives the figures of the page head and the tab badges (refreshed every minute).
// Opening the section needs page.preq, raising a request preq.create, reviewing preq.review — the server enforces all
// of it, the page only hides what it would refuse.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, KpiCard, KpiGrid, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import RequestDrawer from './RequestDrawer.vue';
import RequestForm from './RequestForm.vue';
import RequestsBoard from './RequestsBoard.vue';
import RequestsList from './RequestsList.vue';
import { API, usePreqNav } from './preq';

const auth = useAuth();
const nav = usePreqNav();
const { tabParam, requestOpen, creating, editing } = nav;

const summaryQ = useGet(`${API}/summary`, undefined, { refetchInterval: 60_000 });
const summary = computed(() => summaryQ.data.value || null);
const canCreate = computed(() => auth.can('preq.create'));
const canReview = computed(() => auth.can('preq.review'));

/** The tab of the link; without one: the board for a reviewer, his own requests for anybody else. */
const tab = computed(() => tabParam.value || (canReview.value ? 'board' : 'mine'));
const tabs = computed(() => [
  { k: 'board', label: { ar: 'لوحة المراجعة', en: 'Review board' }, badge: summary.value?.waiting },
  { k: 'all', label: { ar: 'كل الطلبات', en: 'All requests' } },
  { k: 'mine', label: { ar: 'طلباتي', en: 'My requests' }, badge: summary.value?.mine?.open, hidden: !canCreate.value && !summary.value?.mine?.open },
]);
const oldestColor = computed(() => { const d = summary.value?.oldestWaitingDays; return d == null ? '#20242E' : d >= 7 ? '#b23b3b' : d >= 3 ? '#b26a16' : '#1d7a3e'; });
/** The request the form edits (`edit=1` beside `open=`), or null for a new one. */
const formNumber = computed(() => (editing.value ? requestOpen.value : null));
const formOpen = computed(() => (creating.value && canCreate.value) || editing.value);
/** Saved (or sent): the form closes and the request itself opens. */
const onFormDone = (number) => nav.patch({ new: null, edit: null, open: number });
</script>

<template>
  <PageHead :sub="t('من طلب الصنف إلى دخوله الكتالوج: يُرفع الطلب، يُراجَع، وعند الاعتماد يُنشأ الصنف ويُربط به', 'From asking for a product to the catalogue: a request is raised, reviewed, and on approval the product is created and linked to it')">
    <Btn v-if="canCreate" tone="primary" :label="{ ar: '+ طلب صنف جديد', en: '+ New product request' }" @click="nav.openNew()" />
  </PageHead>

  <ErrorBanner :error="summaryQ.error.value" :closable="false" />
  <KpiGrid compact>
    <KpiCard :value="summary?.byStatus?.submitted" :label="{ ar: 'بانتظار مراجع', en: 'Waiting for a reviewer' }" :color="summary?.byStatus?.submitted ? '#b26a16' : '#20242E'" :loading="summaryQ.isLoading.value" clickable :active="tab === 'board'" @click="nav.go('board')" />
    <KpiCard :value="summary?.byStatus?.in_review" :label="{ ar: 'قيد المراجعة', en: 'In review' }" color="#3C79F5" :loading="summaryQ.isLoading.value" clickable @click="nav.go('all', { status: 'waiting', sort: 'statusAt', order: 'asc' })" />
    <KpiCard :value="summary?.oldestWaitingDays ?? '—'" :unit="summary?.oldestWaitingDays != null ? { ar: 'يوم', en: 'd' } : null" :label="{ ar: 'عمر أقدم طلب ينتظر', en: 'Age of the oldest waiting' }" :color="oldestColor" :loading="summaryQ.isLoading.value" />
    <KpiCard v-if="canCreate || summary?.mine?.returned" :value="summary?.mine?.returned" :label="{ ar: 'أُعيدت إليّ للتصحيح', en: 'Sent back to me' }" :color="summary?.mine?.returned ? '#654e92' : '#20242E'" :loading="summaryQ.isLoading.value" clickable @click="nav.go('mine', { status: 'returned' })" />
    <KpiCard v-if="canCreate || summary?.mine?.draft" :value="summary?.mine?.draft" :label="{ ar: 'مسوداتي', en: 'My drafts' }" color="#55506a" :loading="summaryQ.isLoading.value" clickable @click="nav.go('mine', { status: 'draft' })" />
    <KpiCard :value="summary?.approvedThisMonth" :label="{ ar: 'اعتُمد هذا الشهر', en: 'Approved this month' }" color="#1d7a3e" :loading="summaryQ.isLoading.value" clickable @click="nav.go('all', { status: 'approved', sort: 'decidedAt', order: 'desc' })" />
  </KpiGrid>

  <div v-if="summary?.follows && summary?.fromOdoo > 0" class="hint purple !mt-0 mb-3">
    {{ t('هذا القسم يتابع طلبات الأصناف في أودو: طلبات أودو (الموظفين وبوابة العملاء) تظهر هنا وتتحدّث تلقائيًا وتُراجَع وتُعتمد في أودو (تحمل علامة «من أودو»)، ويمكن التعليق عليها وإضافة صور وربطها بصنف الكتالوج. الطلبات التي تُرفع من هنا تُراجَع وتُعتمد هنا بالكامل.', 'This section follows the product requests of Odoo: its requests (the staff’s and the customer portal’s) appear here, follow Odoo by themselves and are reviewed and approved in Odoo (marked “From Odoo”); comments, pictures and the catalogue product can be added to them. Requests raised here are reviewed and approved here in full.') }}
  </div>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" />
  <RequestsBoard v-if="tab === 'board'" :summary="summary" />
  <RequestsList v-else :key="tab" :scope="tab === 'mine' ? 'mine' : 'all'" :summary="summary" />

  <RequestDrawer :number="formOpen && editing ? null : requestOpen" @close="nav.closeRequest()" @edit="nav.openEdit()" />
  <RequestForm :open="formOpen" :number="formNumber" @close="nav.closeForm()" @done="onFormDone" />
</template>
