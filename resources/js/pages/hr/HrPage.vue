<script setup>
// «الموظفون والمصروفات» / People & expenses: the staff directory · the org chart with departments and job positions ·
// documents that are due · contracts · expense claims — with a small summary on top. Everything on screen lives in the
// query string (the keys are listed at `useHrNav` in ./shared.js), so every view, filter and open drawer is a link:
//   /hr?tab=directory&department=…&q=…      /hr?tab=claims&scope=review&claim=CLM-2026-00012      /hr?emp=EMP-2026-00007&etab=personal
// The tabs a person sees follow what they may do: the directory needs page.hr; documents hr.manage or hr.private;
// contracts and the personal file hr.private (the API does not return them at all without it); claims expense.claim
// or expense.review. The server enforces every one of them; this page only hides what would be refused.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ClaimDrawer from './ClaimDrawer.vue';
import ClaimsTab from './ClaimsTab.vue';
import ContractDrawer from './ContractDrawer.vue';
import ContractsTab from './ContractsTab.vue';
import DirectoryTab from './DirectoryTab.vue';
import DueTab from './DueTab.vue';
import EmployeeDrawer from './EmployeeDrawer.vue';
import OrgTab from './OrgTab.vue';
import SummaryStrip from './SummaryStrip.vue';
import { useHrNav } from './shared';

const auth = useAuth();
const nav = useHrNav();

const summary = useGet('/hr/summary', undefined, { refetchInterval: 120_000 });
const s = computed(() => summary.data.value || null);

const tabs = computed(() => [
  ...(auth.can('page.hr') ? [{ k: 'directory', label: { ar: 'دليل الموظفين', en: 'Directory' } }, { k: 'org', label: { ar: 'الهيكل والأقسام', en: 'Org & departments' } }] : []),
  ...(auth.can(['hr.manage', 'hr.private']) ? [{ k: 'due', label: { ar: 'وثائق مستحقة', en: 'Documents due' }, badge: s.value?.due ? s.value.due.expired + s.value.due.d7 : null }] : []),
  ...(auth.can('hr.private') ? [{ k: 'contracts', label: { ar: 'العقود', en: 'Contracts' } }] : []),
  ...(auth.can(['expense.claim', 'expense.review']) ? [{ k: 'claims', label: { ar: 'مطالبات المصروفات', en: 'Expense claims' }, badge: s.value?.claims?.review?.waiting ?? null }] : []),
]);
const tab = computed(() => (tabs.value.some((x) => x.k === nav.get('tab')) ? nav.get('tab') : tabs.value[0]?.k || null));
</script>

<template>
  <PageHead :sub="t('دليل الموظفين · الملف الشخصي والعقود · الوثائق وتنبيهات انتهائها · مطالبات المصروفات حتى القيد المحاسبي', 'Staff directory · personal file and contracts · documents and their expiry alerts · expense claims down to the books')" />
  <ErrorBanner :error="summary.error.value" :closable="false" />

  <SummaryStrip :summary="s" :loading="summary.isLoading.value" @go="(k, params) => nav.go(k, params)" />

  <!-- while the section follows Odoo, what came from Odoo is changed there: say so once, at the top -->
  <div v-if="s?.odoo?.follows" class="banner teal">
    <div>{{ t('هذا القسم يتبع أودو حاليًا: السجلات القادمة من أودو تُعرض هنا وتُعدَّل هناك، وما يُنشأ هنا يعمل كاملًا. ما يضيفه نظام العمليات (ربط المستخدم، تواريخ انتهاء الهوية والجواز، الملاحظات، حساب فئة المصروف) يبقى قابلًا للتعديل.', 'This section follows Odoo for now: records that came from Odoo are shown here and changed there, and what is created here works fully. What OPS adds (the login link, the expiry of the identity and passport, notes, the account of an expense category) stays editable.') }}</div>
  </div>

  <Tabs v-if="tabs.length > 1" :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" />
  <div class="mt-1">
    <DirectoryTab v-if="tab === 'directory'" />
    <OrgTab v-else-if="tab === 'org'" />
    <DueTab v-else-if="tab === 'due'" />
    <ContractsTab v-else-if="tab === 'contracts'" />
    <ClaimsTab v-else-if="tab === 'claims'" :summary="s" />
  </div>

  <!-- payroll is said here, not built here -->
  <div v-if="s?.payroll" class="hint">{{ t(s.payroll.ar, s.payroll.en) }}</div>

  <!-- the three drawers open from any tab and from a link -->
  <EmployeeDrawer :code="nav.get('emp') || null" @close="nav.patch({ emp: null, etab: null })" />
  <ContractDrawer :number="nav.get('contract') || null" @close="nav.patch({ contract: null })" />
  <ClaimDrawer :number="nav.get('claim') || null" @close="nav.patch({ claim: null })" />
</template>
