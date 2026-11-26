<script setup>
// «العمولات» / Sales commissions (docs/odoo/WAVE3.md, team P2): plans, the calculation of a month from the finance
// module's invoices, a statement per earner and month (draft → submitted → approved → paid), adjustments with a reason,
// the payout through the books — and Odoo's own history of commissions in the same lists.
//   statements   every statement the user may see (his own; all of them with commission.manage / commission.approve)   StatementsTab.vue
//   lines        "was this invoice paid a commission for — to whom, in which statement?"                                LinesTab.vue
//   summary      pending / approved / paid, month by month and earner by earner                                        SummaryTab.vue
//   plans        who earns, on what, at which rate (manage or approve)                                                 PlansTab.vue
//   assignments  whose sale an invoice is, and the invoices nobody answers for (manage)                                AssignmentsTab.vue
// One statement opens in a drawer from any tab (StatementDrawer.vue), one plan in another (PlanDrawer.vue).
// Everything lives in the query string, so every view is a link (the keys are listed at `useNav` in ./commissions.js):
//   /commissions?status=submitted&from=2026-09&to=2026-11&statement=CMS-2026-00012&view=adjustments
//   /commissions?tab=plans&status=active&plan=CPL-2026-00003        /commissions?tab=lines&q=CI-2026-00451
import { computed, ref } from 'vue';
import { Btn, ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import AssignmentsTab from './AssignmentsTab.vue';
import CalculateDrawer from './CalculateDrawer.vue';
import LinesTab from './LinesTab.vue';
import PlanDrawer from './PlanDrawer.vue';
import PlansTab from './PlansTab.vue';
import StatementDrawer from './StatementDrawer.vue';
import StatementsTab from './StatementsTab.vue';
import SummaryTab from './SummaryTab.vue';
import { provideCommissions } from './commissions';

const { nav, lookupsQ, lookups, can, seesAll } = provideCommissions();

const tabs = computed(() => [
  { k: 'statements', label: seesAll.value ? { ar: 'الكشوف', en: 'Statements' } : { ar: 'كشوفي', en: 'My statements' } },
  { k: 'lines', label: { ar: 'الفواتير المحتسبة', en: 'Commissioned invoices' } },
  { k: 'summary', label: { ar: 'الملخص', en: 'Summary' } },
  ...(seesAll.value ? [{ k: 'plans', label: { ar: 'خطط العمولات', en: 'Plans' } }] : []),
  ...(can.value.manage ? [{ k: 'assignments', label: { ar: 'إسناد المبيعات', en: 'Whose sale' } }] : []),
]);
const tab = computed(() => { const k = nav.get('tab'); return tabs.value.some((x) => x.k === k) ? k : 'statements'; });
const calculating = ref(false);
</script>

<template>
  <PageHead :sub="seesAll
    ? t('خطة تحدد من يستحق وعلى ماذا وبأي نسبة ← احتساب الشهر من فواتير المالية ← كشف لكل مستحق ← اعتماد ← صرف بسند مصروف', 'A plan says who earns, on what and at which rate → the month is calculated from the finance invoices → a statement per earner → approval → payout through an expense voucher')
    : t('كشوف عمولتك شهرًا بشهر: الفواتير التي احتُسبت، الأساس والنسبة، التسويات، وحالة الاعتماد والصرف', 'Your commission statements month by month: the invoices counted, the base and the rate, adjustments, approval and payout')">
    <Btn v-if="can.manage" tone="primary" :label="{ ar: 'احتساب شهر', en: 'Calculate a month' }" @click="calculating = true" />
  </PageHead>

  <ErrorBanner :error="lookupsQ.error.value" :closable="false" />
  <div v-if="lookups.following && seesAll" class="hint purple !mt-0 mb-3">
    <b>{{ t('قسم العمولات يتبع أودو حاليًا.', 'The commissions section follows Odoo for now.') }}</b>
    {{ t('ما جاء من أودو — كشوفه وسطوره وخطته — يُعرض هنا للاطلاع ولا يُعدَّل. ما يُنشأ في نظام العمليات (الخطط، الاحتساب، الكشوف) يُدار هنا كاملًا.', 'What came from Odoo — its statements, lines and plan — is shown here to read and is not edited. What is made in OPS (plans, calculation, statements) is fully managed here.') }}
  </div>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="nav.setTab" />
  <StatementsTab v-if="tab === 'statements'" />
  <LinesTab v-else-if="tab === 'lines'" />
  <SummaryTab v-else-if="tab === 'summary'" />
  <PlansTab v-else-if="tab === 'plans'" />
  <AssignmentsTab v-else-if="tab === 'assignments'" />

  <StatementDrawer />
  <PlanDrawer />
  <CalculateDrawer :open="calculating" @close="calculating = false" />
</template>
