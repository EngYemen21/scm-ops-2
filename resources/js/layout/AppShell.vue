<script setup>
// Application shell: sticky dark topbar (logo · search · bell · language · user menu · warehouse chips),
// sidebar built from `user.nav`, main area with the page title bar, permission gate and error boundary.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { bi, isBi, lang, setLang, t, toggleLang } from '../i18n';
import { NAV_LABELS, ROLE_LABELS } from '../shared';
import { useAuth } from '../stores/auth';
import { pageHeader } from '../stores/ui';
import { useWarehouse } from '../stores/warehouse';
import ChangePasswordModal from './ChangePasswordModal.vue';
import ErrorBoundary from './ErrorBoundary.vue';
import GlobalSearch from './GlobalSearch.vue';
import Icon from './Icon.vue';
import NotificationBell from './NotificationBell.vue';

const auth = useAuth();
const whStore = useWarehouse();
const route = useRoute();
const router = useRouter();

const sidebarOpen = ref(false);
const menuOpen = ref(false);
const passwordOpen = ref(false);
watch(() => route.path, () => { sidebarOpen.value = false; menuOpen.value = false; });

const user = computed(() => auth.user);
/** Worker / driver screens are task-focused: no global search. */
const isLite = computed(() => (user.value?.roles || []).every((r) => r === 'worker' || r === 'driver'));
const label = (map, k) => (map[k] ? (lang.value === 'ar' ? map[k].ar : map[k].en) : k);
const roleName = computed(() => (user.value?.roles || []).map((r) => label(ROLE_LABELS, r)).join(' · '));
const userName = computed(() => (lang.value === 'ar' ? user.value?.nameAr : user.value?.nameEn) || '');

const defaultTitle = computed(() => (route.meta.title ? bi(route.meta.title) : ''));
const routeParam = computed(() => (route.meta.param ? route.params[route.meta.param] : null));
const title = computed(() => (pageHeader.value.title ? (isBi(pageHeader.value.title) ? bi(pageHeader.value.title) : pageHeader.value.title) : null));
const sub = computed(() => (pageHeader.value.sub ? (isBi(pageHeader.value.sub) ? bi(pageHeader.value.sub) : pageHeader.value.sub) : null));
const allowed = computed(() => !route.meta.permission || auth.can(route.meta.permission));

async function signOut() {
  menuOpen.value = false;
  await auth.logout();
  router.replace('/login');
}
</script>

<template>
  <div v-if="user" class="app">
    <div class="topbar">
      <div class="topbar-row">
        <button type="button" class="tb-btn hamburger" aria-label="menu" @click="sidebarOpen = !sidebarOpen"><Icon name="menu" /></button>
        <div class="flex flex-none cursor-pointer items-center gap-[9px]" @click="router.push(auth.homePath)">
          <img src="/logo-white.png" alt="B2B ops — ERP System" class="block h-[30px] w-auto">
          <div class="tb-sub self-end pb-0.5 text-[8.5px] text-[#8b90a5]">{{ t('عمليات سلسلة الإمداد', 'Supply Chain Operations') }}</div>
        </div>
        <div class="flex-1" />
        <GlobalSearch v-if="!isLite" />
        <NotificationBell />
        <button type="button" class="tb-btn pad" :title="t('English', 'عربي')" @click="toggleLang"><Icon name="globe" />{{ lang === 'ar' ? 'EN' : 'عربي' }}</button>
        <div class="relative flex-none">
          <div class="tb-user flex cursor-pointer items-center gap-2" role="button" @click="menuOpen = !menuOpen">
            <div class="avatar">{{ user.initials || userName.slice(0, 1) }}</div>
            <div class="tb-user-text">
              <div class="whitespace-nowrap text-[11px] font-extrabold leading-[1.4] text-white">{{ userName }}</div>
              <div class="whitespace-nowrap text-[8.5px] text-[#8b90a5]">{{ roleName }}</div>
            </div>
          </div>
          <template v-if="menuOpen">
            <div class="fixed inset-0 z-[80]" @click="menuOpen = false" />
            <div class="popover top-[38px] end-0 w-[230px]">
              <div class="border-b border-line-2 px-[13px] py-2.5">
                <div class="text-[11px] font-extrabold">{{ userName }}</div>
                <div class="mt-0.5 text-[9px] text-muted">{{ roleName }} · <span class="num">{{ user.username }}</span></div>
              </div>
              <button type="button" class="menu-item" @click="menuOpen = false; passwordOpen = true"><Icon name="lock" color="#7d7990" />{{ t('تغيير كلمة المرور', 'Change password') }}</button>
              <button type="button" class="menu-item" @click="setLang(lang === 'ar' ? 'en' : 'ar'); menuOpen = false"><Icon name="globe" color="#7d7990" />{{ t('اللغة: English', 'Language: عربي') }}</button>
              <button type="button" class="menu-item danger" @click="signOut"><Icon name="logout" />{{ t('تسجيل الخروج', 'Sign out') }}</button>
            </div>
          </template>
        </div>
      </div>
      <div v-if="whStore.warehouses.length > 0" class="topbar-row2">
        <div class="flex min-w-0 flex-[1_1_300px] flex-wrap items-center gap-[5px]">
          <span class="me-0.5 flex items-center text-[#5b6076]"><Icon name="warehouse" color="#5b6076" /></span>
          <button type="button" class="tb-chip" :class="{ active: whStore.wh === 'all' }" @click="whStore.setWh('all')">{{ t('كل المستودعات', 'All warehouses') }}</button>
          <button v-for="w in whStore.warehouses" :key="w.code" type="button" class="tb-chip" :class="{ active: whStore.wh === w.code }" @click="whStore.setWh(w.code)">{{ lang === 'ar' ? w.nameAr : w.nameEn }} <span class="num ms-[5px] opacity-80">{{ w.code }}</span></button>
        </div>
      </div>
    </div>

    <div class="body-row">
      <div class="sidebar-backdrop" :class="{ open: sidebarOpen }" @click="sidebarOpen = false" />
      <nav class="sidebar" :class="{ open: sidebarOpen }">
        <RouterLink v-for="k in user.nav" :key="k" :to="`/${k}`" class="nav-item" active-class="active">
          <span class="nav-dot" />
          <Icon :nav="k" />
          <span class="flex-1">{{ label(NAV_LABELS, k) }}</span>
        </RouterLink>
        <div class="flex-1" />
        <div class="px-2.5 text-[8.5px] leading-[1.9] text-[#5b6076]">{{ t('B2B ops · الصلاحيات مفروضة من الخادم (RBAC)', 'B2B ops · permissions enforced by the server (RBAC)') }}</div>
      </nav>

      <main class="main">
        <div v-if="!pageHeader.hidden" class="page-head">
          <div>
            <div class="page-title">
              <template v-if="title">{{ title }}</template>
              <template v-else>{{ defaultTitle }} <span v-if="routeParam" class="num text-violet">{{ routeParam }}</span></template>
            </div>
            <div v-if="sub" class="page-sub">{{ sub }}</div>
          </div>
          <div class="flex-1" />
          <div id="page-actions" class="row wrap" />
        </div>
        <div v-else id="page-actions" class="hidden" />

        <ErrorBoundary :reset-key="route.fullPath">
          <RouterView v-if="allowed" />
          <div v-else class="empty dashed mx-auto my-10 max-w-[520px]">
            <div class="text-[13px] font-extrabold text-bad">{{ t('صلاحية غير كافية', 'Insufficient permission') }}</div>
            <div class="mt-1.5">{{ t('لا تملك صلاحية الوصول لهذه الصفحة — RBAC', 'You do not have access to this page — RBAC') }}</div>
          </div>
        </ErrorBoundary>
      </main>
    </div>

    <ChangePasswordModal :open="passwordOpen || user.mustChangePassword" :forced="user.mustChangePassword" @close="passwordOpen = false" />
  </div>
</template>
