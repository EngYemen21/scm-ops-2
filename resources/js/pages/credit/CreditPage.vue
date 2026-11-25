<script setup>
// «حسابات الائتمان الآجل» — a customer asks for buying on credit; the request goes through the stages of the section
// (review, signature, top management) and, once approved, the granted limit, days and term reach the customer.
// Everything lives in the query string, so every tab, filter and open request is a link (keys at `useCreditNav`):
//   /credit?tab=waiting                         what waits for my decision
//   /credit?tab=requests&state=approved&apply=blocked&sort=updatedAt&order=desc
//   /credit?tab=mine&req=<id>                   one request, on any tab        /credit?tab=setup&set=terms
// What a person may do comes from GET /credit/lookups `can` (request · review · approve · manage); the server checks
// every move again. Where the section follows Odoo the requests that came from Odoo are read-only here.
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, KpiCard, KpiGrid, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import RequestDrawer from './RequestDrawer.vue';
import RequestForm from './RequestForm.vue';
import RequestsList from './RequestsList.vue';
import SettingsTab from './SettingsTab.vue';
import { useCreditLookups, useCreditNav } from './credit';

const nav = useCreditNav();
const lk = useCreditLookups();
const can = lk.can;
const reviewer = computed(() => !!(can.value.review || can.value.approve || can.value.manage));

const summaryQ = useGet('/credit/summary');
const summary = computed(() => summaryQ.data.value || null);

const tabs = computed(() => [
  ...(reviewer.value ? [{ k: 'waiting', label: { ar: 'بانتظاري', en: 'Waiting for me' }, badge: summary.value?.waiting || null }] : []),
  { k: 'requests', label: { ar: 'الطلبات', en: 'Requests' } },
  ...(can.value.request ? [{ k: 'mine', label: { ar: 'طلباتي', en: 'My requests' }, badge: summary.value?.mine || null }] : []),
  ...(can.value.manage ? [{ k: 'setup', label: { ar: 'الإعداد', en: 'Setup' } }] : []),
]);
/** The tab of the link when this person has it, else the first one he has. */
const tab = computed(() => { const want = nav.get('tab'); const keys = tabs.value.map((x) => x.k); return keys.includes(want) ? want : keys[0]; });
const scope = computed(() => ({ waiting: 'waiting', mine: 'mine' }[tab.value] || 'all'));

/** The form: `creating` a new request, `editing` the detail of one (from the drawer). */
const creating = ref(false);
const editing = ref(null);
function onSaved(r) { creating.value = false; editing.value = null; if (r?.id) nav.openReq(r.id); }
const loading = computed(() => summaryQ.isLoading.value);
</script>

<template>
  <PageHead :sub="t('طلبات البيع الآجل: المراحل والمعتمدون · المواعيد والتصعيد · الوثائق والتوقيع · تطبيق الحد على العميل', 'Credit applications: stages and approvers · target times and escalation · papers and signature · applying the limit to the customer')">
    <Btn v-if="can.request" tone="primary" :label="{ ar: '+ طلب جديد', en: '+ New request' }" @click="creating = true" />
  </PageHead>

  <ErrorBanner :error="lk.query.error.value" :closable="false" />
  <div v-if="lk.followsOdoo.value" class="hint purple !mt-0 mb-3">
    {{ t('هذا القسم يتبع أودو حاليًا: الطلبات القادمة من أودو تُعرض هنا وتُدار هناك، ويُعلَّق عليها هنا. ما يُنشأ هنا يُدار هنا بالكامل.',
      'This section follows Odoo for now: requests that come from Odoo are shown here and managed there, and can be commented on here. What is created here is managed here in full.') }}
  </div>

  <KpiGrid compact class="mb-3">
    <KpiCard v-if="reviewer" :value="summary?.waiting" :label="{ ar: 'بانتظاري', en: 'Waiting for me' }" :color="summary?.waiting ? '#b26a16' : '#20242E'" :loading="loading" clickable :active="tab === 'waiting'" @click="nav.go('waiting')" />
    <KpiCard v-if="can.request" :value="summary?.mine" :label="{ ar: 'طلباتي المفتوحة', en: 'My open requests' }" color="#3C79F5" :loading="loading" clickable :active="tab === 'mine'" @click="nav.go('mine')" />
    <KpiCard :value="summary?.inReview" :label="{ ar: 'قيد المراجعة', en: 'In review' }" color="#654e92" :loading="loading" clickable @click="nav.go('requests', { state: 'work' })" />
    <KpiCard :value="summary?.late" :label="{ ar: 'متأخرة عن الموعد', en: 'Late' }" :color="summary?.late ? '#b23b3b' : '#20242E'" :loading="loading" clickable @click="nav.go('requests', { sla: 'late' })" />
    <KpiCard :value="summary?.escalated" :label="{ ar: 'مُصعَّدة', en: 'Escalated' }" :color="summary?.escalated ? '#b23b3b' : '#20242E'" :loading="loading" clickable @click="nav.go('requests', { sla: 'escalated' })" />
    <KpiCard :value="summary?.applyBlocked" :label="{ ar: 'تعذّر تطبيقها', en: 'Apply blocked' }" :color="summary?.applyBlocked ? '#b23b3b' : '#20242E'" :loading="loading" clickable @click="nav.go('requests', { state: 'approved', apply: 'blocked' })" />
  </KpiGrid>
  <ErrorBanner :error="summaryQ.error.value" :closable="false" />

  <template v-if="lk.loaded.value">
    <Tabs :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" />
    <SettingsTab v-if="tab === 'setup'" />
    <RequestsList v-else :key="tab" :scope="scope" />
  </template>
  <div v-else-if="lk.query.isLoading.value" class="skel h-[260px]" />

  <RequestDrawer :request-id="nav.reqOpen.value" @close="nav.closeReq()" @edit="(r) => (editing = r)" />
  <RequestForm :open="creating || !!editing" :request="editing" @close="creating = false; editing = null" @done="onSaved" />
</template>
