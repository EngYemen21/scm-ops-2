<script setup>
// «الدعم والمشاريع والمستندات» — three small tools under one page: help-desk tickets · projects and tasks · documents.
// The whole state of the page lives in the query string of /workspace:
//   tab=tickets|tasks|docs (default tickets) — switching a tab keeps only `tab`
//   tickets  scope, state, team, stage, type, priority, assignee, overdue, q, sort, order, page · ticket=<number> · new=1 · config=1
//   tasks    view=mine|projects|board|list, project=<id>, state, stage, assignee, tag, priority, due, q, sort, order, page · task=<number> · prj=<id>
//   docs     folder=<id>, deep, state, tag, owner, kind, type, q, sort, order, page · doc=<number>
// Deep links: /workspace?tab=tickets&ticket=TKT-2026-00001 · /workspace?tab=tasks&task=TSK-2026-00003 ·
//   /workspace?tab=tasks&view=board&project=<id> · /workspace?tab=docs&folder=<id> · /workspace?tab=docs&doc=DOC-2026-00002
import { computed } from 'vue';
import { ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import DocumentsTab from './DocumentsTab.vue';
import TasksTab from './TasksTab.vue';
import TicketsTab from './TicketsTab.vue';
import { useSummary, useWsQuery } from './workspace';

const qs = useWsQuery();
const TAB_KEYS = ['tickets', 'tasks', 'docs'];
const tab = computed(() => (TAB_KEYS.includes(qs.get('tab')) ? qs.get('tab') : 'tickets'));
/** The filters and whatever is open belong to the tab that is left: the new tab starts clean. */
const setTab = (k) => { if (k !== tab.value) qs.replace({ tab: k }); };

const summary = useSummary();
const counts = computed(() => summary.data.value || null);
const tabs = computed(() => [
  { k: 'tickets', label: { ar: 'تذاكر الدعم', en: 'Tickets' }, badge: counts.value?.tickets?.mine },
  { k: 'tasks', label: { ar: 'المشاريع والمهام', en: 'Projects & tasks' }, badge: counts.value?.tasks?.mine },
  { k: 'docs', label: { ar: 'المستندات', en: 'Documents' } },
]);
</script>

<template>
  <PageHead :sub="t('تذاكر الدعم · المشاريع والمهام · المستندات — ثلاث أدوات في صفحة واحدة', 'Help-desk tickets · projects and tasks · documents — three tools under one page')" />
  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="setTab" />
  <ErrorBanner :error="summary.error.value" :closable="false" />
  <div v-if="counts?.follows" class="hint teal !mt-0 mb-3">
    {{ t('القسم يتبع أودو حاليًا: ما جاء من أودو يُعرض هنا ويُعدَّل هناك، وتُضاف عليه الردود والتعليقات فقط. ما يُنشأ في نظام العمليات يعمل كاملًا.',
         'The section follows Odoo for now: what came from Odoo is shown here and changed there — only replies and comments are added to it. What is created in OPS works in full.') }}
  </div>
  <TicketsTab v-if="tab === 'tickets'" />
  <TasksTab v-else-if="tab === 'tasks'" />
  <DocumentsTab v-else />
</template>
