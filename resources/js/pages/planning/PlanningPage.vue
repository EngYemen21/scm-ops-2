<script setup>
// «تخطيط الطلب والشراء» — forecast runs (what to buy, when and why), budget scenarios, purchase plans and the settings
// planning needs a person to give. Everything lives in the query string (keys at `usePlanningNav` in ./planning.js):
//   /planning                               the runs            /planning?tab=runs&run=<id>&rtab=items&item=<id>
//   /planning?tab=plans&plan=<id>           one plan            /planning?tab=settings&set=product
// Reading needs page.planning; changes need forecast.manage / purchaseplan.manage / purchaseplan.approve / report.export
// (buttons hidden without them; the server enforces all of it).
import { computed, ref } from 'vue';
import { Btn, ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import BudgetDrawer from './BudgetDrawer.vue';
import ItemDrawer from './ItemDrawer.vue';
import PlanDrawer from './PlanDrawer.vue';
import PlanningSettings from './PlanningSettings.vue';
import PlansList from './PlansList.vue';
import RunDrawer from './RunDrawer.vue';
import RunForm from './RunForm.vue';
import RunsList from './RunsList.vue';
import { usePlanningLookups, usePlanningNav } from './planning';

const auth = useAuth();
const nav = usePlanningNav();
const { tab } = nav;
const lk = usePlanningLookups();
const lookupsError = computed(() => lk.query.error.value);

const tabs = computed(() => [
  { k: 'runs', label: { ar: 'تشغيلات التوقع', en: 'Forecast runs' } },
  { k: 'plans', label: { ar: 'خطط الشراء', en: 'Purchase plans' } },
  { k: 'settings', label: { ar: 'الإعدادات', en: 'Settings' }, badge: lk.unmapped.value || null },
]);

const runId = computed(() => nav.get('run') || null);
const itemId = computed(() => (runId.value ? nav.get('item') || null : null));
const budgetId = computed(() => nav.get('budget') || null);
const planId = computed(() => nav.get('plan') || null);

/** The run form: `editing` = the run whose parameters are changed; `creating` = a new one. */
const creating = ref(false);
const editing = ref(null);
function onSaved(run) { creating.value = false; editing.value = null; if (run?.id) nav.patch({ tab: null, run: run.id }); }
</script>

<template>
  <PageHead :sub="t('توقع الطلب · احتياج الشراء ومتى يُطلب ولماذا · سيناريوهات الميزانية · خطط الشراء إلى طلبات الشراء', 'Demand forecast · what to buy, when and why · budget scenarios · purchase plans into purchase requests')">
    <Btn v-if="auth.can('forecast.manage')" tone="primary" :label="{ ar: '+ تشغيل جديد', en: '+ New run' }" @click="creating = true" />
  </PageHead>

  <ErrorBanner :error="lookupsError" :closable="false" />
  <div v-if="lk.following.value" class="hint purple !mt-0 mb-3">
    {{ t('هذا القسم يتبع أودو حاليًا: التشغيلات والسيناريوهات والخطط القادمة من أودو تُعرض هنا للقراءة فقط وتُعدَّل هناك. ما يُنشأ هنا يُدار هنا بالكامل.',
      'This section follows Odoo for now: runs, scenarios and plans that came from Odoo are read-only here and are changed there. What is created here is managed here in full.') }}
  </div>
  <div v-if="lk.unmapped.value" class="hint amber !mt-0 mb-3">
    {{ t(`${lk.unmapped.value} من مستودعات أودو غير مربوطة بمستودع في OPS — سجلها يُستبعد من كل تشغيل حتى تُربط من «الإعدادات».`,
      `${lk.unmapped.value} Odoo warehouse(s) are not mapped to an OPS warehouse — their history is left out of every run until mapped in Settings.`) }}
  </div>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="nav.go" />
  <RunsList v-if="tab === 'runs'" />
  <PlansList v-else-if="tab === 'plans'" />
  <PlanningSettings v-else-if="tab === 'settings'" />

  <RunDrawer :run-id="runId" @close="nav.patch({ run: null, item: null, rtab: null })" @edit="(r) => (editing = r)" @copied="(r) => nav.patch({ run: r.id, item: null })" />
  <ItemDrawer :run-id="runId" :item-id="itemId" @close="nav.patch({ item: null })" />
  <BudgetDrawer :budget-id="budgetId" @close="nav.patch({ budget: null, astatus: null, amonth: null, aq: null, asort: null, aorder: null, apage: null })" @plan="(p) => nav.patch({ budget: null, tab: 'plans', run: null, plan: p.id })" />
  <PlanDrawer :plan-id="planId" @close="nav.patch({ plan: null })" />
  <RunForm :open="creating || !!editing" :run="editing" @close="creating = false; editing = null" @done="onSaved" />
</template>
