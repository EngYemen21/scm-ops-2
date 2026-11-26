<script setup>
// «نقطة البيع» — the counter (sell in an open session: scan, cart, split payment, receipt), its sessions and end-of-day
// reports, its orders and refunds, and its setup (registers, payment methods, cash denominations). Everything lives in
// the query string, so every view, filter and open record is a link (the keys are listed at `usePosNav` in ./pos.js):
//   /pos                                  the counter            /pos?view=sessions&state=open
//   /pos?view=orders&order=<id>           one order              /pos?view=sessions&report=<session id>   a report
// Reading needs page.pos; selling, sessions, held sales and refunds need pos.sell; setup and issuing owed stock need
// pos.manage (hidden without them; the server enforces all of it).
import { computed } from 'vue';
import { ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import OrderDrawer from './OrderDrawer.vue';
import OrdersList from './OrdersList.vue';
import SellScreen from './SellScreen.vue';
import SessionDrawer from './SessionDrawer.vue';
import SessionReport from './SessionReport.vue';
import SessionsList from './SessionsList.vue';
import SetupTab from './SetupTab.vue';
import { usePosLookups, usePosNav } from './pos';

const auth = useAuth();
const canSell = computed(() => auth.can('pos.sell'));
const nav = usePosNav(() => (canSell.value ? 'sell' : 'sessions'));
const { view, sessionOpen, orderOpen, reportOpen } = nav;
const lk = usePosLookups();

const views = computed(() => [
  { k: 'sell', label: { ar: 'البيع', en: 'Sell' }, hidden: !canSell.value },
  { k: 'sessions', label: { ar: 'الجلسات', en: 'Sessions' } },
  { k: 'orders', label: { ar: 'الطلبات', en: 'Orders' } },
  { k: 'setup', label: { ar: 'الإعداد', en: 'Setup' } },
]);
/** `view=sell` without pos.sell falls back to the sessions. */
const shown = computed(() => (view.value === 'sell' && !canSell.value ? 'sessions' : view.value));
const sub = computed(() => {
  const s = lk.session.value;
  return s
    ? t(`جلستك المفتوحة ${s.number} · ${s.register?.code || ''}`, `Your open session ${s.number} · ${s.register?.code || ''}`)
    : t('البيع المباشر: مسح · سلة · دفع مقسّم · إيصال · جلسات ونهاية اليوم · مرتجعات', 'Counter sales: scan · cart · split payment · receipt · sessions and end of day · refunds');
});
</script>

<template>
  <PageHead :sub="sub" />

  <div class="no-print">
    <ErrorBanner :error="lk.query.error.value" :closable="false" />
    <div v-if="lk.follows.value && shown !== 'sell'" class="hint purple !mt-0 mb-3">
      {{ t('هذا القسم يتبع أودو حاليًا: الصناديق والجلسات والطلبات القادمة من أودو تُعرض هنا سجلًّا للقراءة فقط وتُدار هناك. ما يُنشأ هنا يُدار هنا بالكامل.',
        'This section follows Odoo for now: registers, sessions and orders that come from Odoo are shown here as read-only history and are managed there. What is created here is managed here in full.') }}
    </div>
    <Tabs :model-value="shown" :tabs="views" @update:model-value="(k) => nav.go(k)" />
  </div>

  <SessionReport v-if="reportOpen" :id="reportOpen" @back="nav.closeReport()" />
  <template v-else>
    <SellScreen v-if="shown === 'sell'" />
    <SessionsList v-else-if="shown === 'sessions'" />
    <OrdersList v-else-if="shown === 'orders'" />
    <SetupTab v-else-if="shown === 'setup'" />
  </template>

  <SessionDrawer :id="sessionOpen" @close="nav.closeSession()" />
  <OrderDrawer :id="orderOpen" @close="nav.closeOrder()" />
</template>
