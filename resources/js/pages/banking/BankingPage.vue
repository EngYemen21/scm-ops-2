<script setup>
// «البنوك والمطابقة» — bank statements and their reconciliation with the books: where every bank stands, the statements
// (typed or imported from the bank's file), the reconcile workspace (statement lines against ledger entries, with the
// proposals of the server), the matches, the reconciliation statement an auditor asks for, the write-off rules, the
// journals of Odoo and the payment terms. Everything lives in the query string (keys listed at `useBankingNav`):
//   /banking?tab=reconcile&bank=BNK-01          the workspace of one bank      /banking?st=<id>   one statement, on any tab
//   /banking?tab=recon&bank=BNK-01&date=2026-10-31                              /banking?match=<id> · ?line=<id>
// Reading needs page.banking; statements need bankstatement.manage, matching needs bank.reconcile (the server enforces).
import { computed, ref } from 'vue';
import { Btn, ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import AccountsTab from './AccountsTab.vue';
import JournalsTab from './JournalsTab.vue';
import MatchDrawer from './MatchDrawer.vue';
import MatchesTab from './MatchesTab.vue';
import ReconcileTab from './ReconcileTab.vue';
import ReconciliationTab from './ReconciliationTab.vue';
import RulesTab from './RulesTab.vue';
import StatementDrawer from './StatementDrawer.vue';
import StatementForm from './StatementForm.vue';
import StatementsTab from './StatementsTab.vue';
import SuggestionsPanel from './SuggestionsPanel.vue';
import TermsTab from './TermsTab.vue';
import { useBankingLookups, useBankingNav } from './banking';

const auth = useAuth();
const nav = useBankingNav();
const { tab } = nav;
const lk = useBankingLookups();
const follows = lk.follows;
const lookupsError = computed(() => lk.query.error.value);
const canManage = computed(() => auth.can('bankstatement.manage'));

const tabs = computed(() => [
  { k: 'accounts', label: { ar: 'الحسابات', en: 'Accounts' } },
  { k: 'statements', label: { ar: 'الكشوف', en: 'Statements' } },
  { k: 'reconcile', label: { ar: 'المطابقة', en: 'Reconcile' } },
  { k: 'matches', label: { ar: 'المطابقات', en: 'Matches' } },
  { k: 'recon', label: { ar: 'كشف التسوية', en: 'Reconciliation statement' } },
  { k: 'rules', label: { ar: 'قواعد التسوية', en: 'Write-off rules' } },
  { k: 'journals', label: { ar: 'دفاتر أودو', en: 'Odoo journals' } },
  { k: 'terms', label: { ar: 'شروط الدفع', en: 'Payment terms' } },
]);

/** The statement form: `creating` a new one, or `editing` the statement the drawer shows. */
const creating = ref(false);
const editing = ref(null);
function onSaved(s) { creating.value = false; editing.value = null; if (s?.id) nav.openStatement(s.id); }
</script>

<template>
  <PageHead :sub="t('كشوف البنوك ومطابقتها مع الدفاتر: الاستيراد · الاقتراحات · المطابقة · كشف التسوية', 'Bank statements reconciled with the books: import · proposals · matching · the reconciliation statement')">
    <Btn v-if="canManage" tone="primary" :label="{ ar: '+ كشف جديد', en: '+ New statement' }" @click="creating = true" />
  </PageHead>

  <ErrorBanner :error="lookupsError" :closable="false" />
  <div v-if="follows" class="hint purple no-print !mt-0 mb-3">
    {{ t('هذا القسم يتبع أودو حاليًا: الكشوف والقواعد والدفاتر القادمة من أودو تُعرض هنا للقراءة وتُعدَّل هناك. الربط والمطابقة والملاحظات وما يُنشأ هنا يُدار هنا بالكامل.',
      'This section follows Odoo for now: statements, rules and journals that come from Odoo are read here and changed there. Links, matching, notes and whatever is created here are managed here in full.') }}
  </div>

  <div class="no-print"><Tabs :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" /></div>
  <AccountsTab v-if="tab === 'accounts'" />
  <StatementsTab v-else-if="tab === 'statements'" />
  <ReconcileTab v-else-if="tab === 'reconcile'" />
  <MatchesTab v-else-if="tab === 'matches'" />
  <ReconciliationTab v-else-if="tab === 'recon'" />
  <RulesTab v-else-if="tab === 'rules'" />
  <JournalsTab v-else-if="tab === 'journals'" />
  <TermsTab v-else-if="tab === 'terms'" />

  <StatementDrawer :id="nav.get('st') || null" @close="nav.closeStatement()" @edit="(s) => (editing = s)" />
  <MatchDrawer :id="nav.get('match') || null" @close="nav.closeMatch()" />
  <SuggestionsPanel :line-id="nav.get('line') || null" @close="nav.closeLine()" />
  <StatementForm :open="creating || !!editing" :statement="editing" :bank="nav.bank.value" @close="creating = false; editing = null" @done="onSaved" />
</template>
