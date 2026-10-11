<script setup>
// Finance — the CFO board and the ledger's documents in one page: board · journals · customer invoices · payments ·
// expenses · fixed assets · chart of accounts · periods · reports. Deep links: `?tab=…`, `?journal=JV-…`, `?invoice=CI-…`,
// `?payment=RCT-…`, `?expense=EXP-…`, `?asset=FA-…`, `?report=trial-balance`.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { qs } from '@/pages/procurement/shared';
import AccountsTab from './AccountsTab.vue';
import AssetsTab from './AssetsTab.vue';
import BoardTab from './BoardTab.vue';
import ExpensesTab from './ExpensesTab.vue';
import InvoicesTab from './InvoicesTab.vue';
import JournalsTab from './JournalsTab.vue';
import PaymentsTab from './PaymentsTab.vue';
import PeriodsTab from './PeriodsTab.vue';
import ReportsTab from './ReportsTab.vue';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const TAB_KEYS = ['board', 'journals', 'invoices', 'payments', 'expenses', 'assets', 'accounts', 'periods', 'reports'];
const tab = computed(() => { const k = qs(route.query.tab); return TAB_KEYS.includes(k) ? k : 'board'; });
const setTab = (k) => router.replace({ query: { tab: k } });

const boardQ = useGet('/finance/board', undefined, { refetchInterval: 120_000 });
const board = computed(() => boardQ.data.value);
const tabs = computed(() => [
  { k: 'board', label: { ar: 'اللوحة المالية', en: 'Board' } },
  { k: 'journals', label: { ar: 'القيود', en: 'Journals' }, badge: board.value?.period?.draftJournals },
  { k: 'invoices', label: { ar: 'فواتير العملاء', en: 'Customer invoices' }, badge: board.value?.receivables?.overdue?.count },
  { k: 'payments', label: { ar: 'المقبوضات والمدفوعات', en: 'Receipts & payments' } },
  { k: 'expenses', label: { ar: 'المصروفات', en: 'Expenses' } },
  { k: 'assets', label: { ar: 'الأصول الثابتة', en: 'Fixed assets' } },
  { k: 'accounts', label: { ar: 'دليل الحسابات', en: 'Chart of accounts' } },
  { k: 'periods', label: { ar: 'الفترات المالية', en: 'Periods' }, badge: board.value?.period?.earlierOpen?.length },
  ...(auth.can('fin.report') ? [{ k: 'reports', label: { ar: 'التقارير المالية', en: 'Reports' } }] : []),
]);
</script>

<template>
  <PageHead :sub="t('قيد مزدوج يُغذَّى من أحداث التشغيل: استلام · تحميل · تسليم · فواتير · مدفوعات · مخزون · أسطول', 'Double-entry ledger fed by operations: receiving · loading · delivery · invoices · payments · inventory · fleet')" />
  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="setTab" />
  <div class="mt-3">
    <BoardTab v-if="tab === 'board'" :board="board" :loading="boardQ.isLoading.value" @go="setTab" />
    <JournalsTab v-else-if="tab === 'journals'" />
    <InvoicesTab v-else-if="tab === 'invoices'" />
    <PaymentsTab v-else-if="tab === 'payments'" />
    <ExpensesTab v-else-if="tab === 'expenses'" />
    <AssetsTab v-else-if="tab === 'assets'" />
    <AccountsTab v-else-if="tab === 'accounts'" />
    <PeriodsTab v-else-if="tab === 'periods'" />
    <ReportsTab v-else-if="tab === 'reports'" />
  </div>
</template>
