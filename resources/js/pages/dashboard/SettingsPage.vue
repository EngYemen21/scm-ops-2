<script setup>
// Settings — "every business rule lives here": users (`user.manage`) · roles × permissions matrix (`user.manage`) ·
// business policies (`settings.manage`) · integrations status + outbox. One component per tab; the tab is in the URL
// (`/settings?tab=integrations`). Tabs the user may not use are hidden; the API enforces the same permissions.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { EmptyState, ErrorBanner, PageHead, SectionCard, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import SettingsIntegrationsTab from './SettingsIntegrationsTab.vue';
import SettingsPoliciesTab from './SettingsPoliciesTab.vue';
import SettingsRolesTab from './SettingsRolesTab.vue';
import SettingsUsersTab from './SettingsUsersTab.vue';
import { useQueryState } from './shared';

const NONE = [];

const auth = useAuth();
const qs = useQueryState();
const canUsers = auth.can('user.manage');
const canSettings = auth.can('settings.manage');

const tabs = [
  { k: 'users', label: { ar: 'المستخدمون', en: 'Users' }, hidden: !canUsers },
  { k: 'roles', label: { ar: 'الأدوار والصلاحيات', en: 'Roles & permissions' }, hidden: !canUsers },
  { k: 'settings', label: { ar: 'إعدادات النظام', en: 'System settings' }, hidden: !canSettings },
  { k: 'integrations', label: { ar: 'التكاملات والـ Outbox', en: 'Integrations & outbox' } },
];
const visible = tabs.filter((x) => !x.hidden);
const tab = computed(() => { const r = qs.get('tab'); return r && visible.some((x) => x.k === r) ? r : visible[0]?.k || 'integrations'; });
const setTab = (k) => qs.replace({ ...qs.route.query, tab: k });

// Shared by the users and roles tabs.
const roles = useGet(canUsers ? '/users/roles' : null);
const perms = useGet(canUsers ? '/users/permissions' : null);
const whs = useGet(canUsers ? '/warehouses' : null, { pageSize: 200 });
const warehouses = computed(() => (Array.isArray(whs.data.value) ? whs.data.value : whs.data.value?.items || NONE));
</script>

<template>
  <PageHead :sub="t('كل Business Rules من هنا — لا شيء Hard-coded', 'Every business rule lives here — nothing is hard-coded')" />
  <Tabs :model-value="tab" :tabs="tabs" class="!mb-3" @update:model-value="setTab" />
  <ErrorBanner :error="roles.error.value || perms.error.value" :closable="false" />

  <SettingsUsersTab v-if="tab === 'users' && canUsers" :roles="roles.data.value || NONE" :warehouses="warehouses" />
  <SettingsRolesTab v-else-if="tab === 'roles' && canUsers" :roles="roles.data.value || NONE" :permissions="perms.data.value || NONE" />
  <SettingsPoliciesTab v-else-if="tab === 'settings' && canSettings" />
  <SettingsIntegrationsTab v-else-if="tab === 'integrations'" />
  <SectionCard v-else-if="tab === 'settings'"><EmptyState :text="{ ar: 'صلاحية غير كافية', en: 'Insufficient permission' }" /></SectionCard>
</template>
