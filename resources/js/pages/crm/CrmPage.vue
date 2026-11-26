<script setup>
// «الفرص والعملاء المحتملون» — the sales pipeline (the section that replaces Odoo's CRM): the board by stage, the list,
// the to-dos, the funnel and what the pipeline is made of. Everything lives in the query string, so every view, filter
// and open lead is a link (the keys are listed at `useCrmNav` in ./crm.js):
//   /crm                                   the board            /crm?view=list&state=won&owner=me&sort=expectedRevenue
//   /crm?view=activities&todo=overdue      my late to-dos       /crm?lead=OPP-2026-00042    one lead, on any view
// Reading needs page.crm; every change needs crm.manage (hidden without it; the server enforces both).
// Where the section follows Odoo (`lookups.follows`) the records that came from Odoo are read-only: the server answers
// CRM_OWNED_BY_SOURCE and the lead offers only what is OPS's own — notes, to-dos, its customer, its quotations.
import { computed, ref } from 'vue';
import { Btn, ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CrmActivities from './CrmActivities.vue';
import CrmBoard from './CrmBoard.vue';
import CrmList from './CrmList.vue';
import CrmReport from './CrmReport.vue';
import CrmSettings from './CrmSettings.vue';
import LeadDrawer from './LeadDrawer.vue';
import LeadForm from './LeadForm.vue';
import { useCrmLookups, useCrmNav } from './crm';

const auth = useAuth();
const nav = useCrmNav();
const { view, leadOpen } = nav;
const lk = useCrmLookups();
const follows = lk.follows;
const lookupsError = computed(() => lk.query.error.value);
const canManage = computed(() => auth.can('crm.manage'));

const views = computed(() => [
  { k: 'board', label: { ar: 'اللوحة', en: 'Board' } },
  { k: 'list', label: { ar: 'القائمة', en: 'List' } },
  { k: 'activities', label: { ar: 'الأنشطة', en: 'To-dos' } },
  { k: 'report', label: { ar: 'تقرير المسار', en: 'Funnel' } },
  { k: 'settings', label: { ar: 'إعدادات المسار', en: 'Pipeline setup' } },
]);

/** The create form; `editing` = the lead being edited (the drawer opens it). */
const creating = ref(false);
const editing = ref(null);
function onSaved(lead) { creating.value = false; editing.value = null; if (lead?.number) nav.openLead(lead.number); }
</script>

<template>
  <PageHead :sub="t('من أول اتصال إلى العميل: المراحل · الأنشطة · الكسب والخسارة · التحويل إلى عميل وعرض سعر', 'From first contact to customer: stages · to-dos · won and lost · conversion to a customer and a quotation')">
    <Btn v-if="canManage" tone="primary" :label="{ ar: '+ فرصة', en: '+ Opportunity' }" @click="creating = true" />
  </PageHead>

  <ErrorBanner :error="lookupsError" :closable="false" />
  <div v-if="follows" class="hint purple !mt-0 mb-3">
    {{ t('هذا القسم يتبع أودو حاليًا: الفرص والمراحل القادمة من أودو تُعرض هنا وتُعدَّل هناك، وتُضاف إليها هنا الملاحظات والأنشطة والعميل وعروض الأسعار. ما يُنشأ هنا يُدار هنا بالكامل.',
      'This section follows Odoo for now: opportunities and stages that come from Odoo are shown here and changed there; notes, to-dos, the customer and quotations are added here. What is created here is managed here in full.') }}
  </div>

  <Tabs :model-value="view" :tabs="views" @update:model-value="(k) => nav.go(k)" />
  <CrmBoard v-if="view === 'board'" />
  <CrmList v-else-if="view === 'list'" />
  <CrmActivities v-else-if="view === 'activities'" />
  <CrmReport v-else-if="view === 'report'" />
  <CrmSettings v-else-if="view === 'settings'" />

  <LeadDrawer :number="leadOpen" @close="nav.closeLead()" @edit="(lead) => (editing = lead)" />
  <LeadForm :open="creating || !!editing" :lead="editing" @close="creating = false; editing = null" @done="onSaved" />
</template>
