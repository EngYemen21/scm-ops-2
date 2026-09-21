<script setup>
// Application shell: sticky dark topbar (logo · search · bell · language · user menu · warehouse chips),
// sidebar built from `user.nav`, main area with the page title bar, permission gate and error boundary.
//
// On a phone (composables/viewport.js) the same shell becomes the mobile app of the design: compact topbar
// (logo · search · bell), warehouse chip in the title row, bottom tab bar, + quick actions; the sidebar and the
// user menu move to the More screen (pages/MorePage.vue). Pages themselves are shared — app.css adapts them.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { bi, isBi, lang, setLang, t, toggleLang } from '../i18n';
import { NAV_LABELS, ROLE_LABELS } from '../shared';
import { isMobile } from '../composables/viewport';
import { useAuth } from '../stores/auth';
import { deliverScan, pageHeader, passwordDialogOpen, showCustomLabel, toast } from '../stores/ui';
import { useWarehouse } from '../stores/warehouse';
import ScanButton from '../components/ScanButton.vue';
import ChangePasswordModal from './ChangePasswordModal.vue';
import ErrorBoundary from './ErrorBoundary.vue';
import GlobalSearch from './GlobalSearch.vue';
import Icon from './Icon.vue';
import MobileQuickActions from './mobile/MobileQuickActions.vue';
import MobileSearch from './mobile/MobileSearch.vue';
import MobileTabBar from './mobile/MobileTabBar.vue';
import WarehouseChip from './mobile/WarehouseChip.vue';
import NotificationBell from './NotificationBell.vue';

const auth = useAuth();
const whStore = useWarehouse();
const route = useRoute();
const router = useRouter();

const sidebarOpen = ref(false);
const menuOpen = ref(false);
const passwordOpen = passwordDialogOpen;
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
/** The + button belongs to the working screens; it would only cover content on the menu and on document pages. */
const showFab = computed(() => isMobile.value && route.name !== 'more' && !route.meta.param);

/** Topbar scan of the task-focused roles: a QR printed by this system opens its document; any other code goes to the
 *  scan field of the page on screen. */
function onTopbarScan(code) {
  if (code.startsWith(`${window.location.origin}/`)) { router.push(code.slice(window.location.origin.length)); return; }
  if (!deliverScan(code)) toast.say(t(`قُرئ ${code} — لا يوجد حقل مسح في هذه الصفحة`, `Read ${code} — this page has no scan field`), 4000);
}

async function signOut() {
  menuOpen.value = false;
  await auth.logout();
  router.replace('/login');
}
</script>

<template>
  <div v-if="user" class="app" :class="{ 'is-mobile': isMobile }">
    <div v-if="isMobile" class="topbar m-topbar">
      <div class="topbar-row">
        <div class="flex flex-none cursor-pointer items-center" role="link" @click="router.push(auth.homePath)">
          <img src="/logo-white.png" alt="B2B ops — ERP System" class="block h-[30px] w-auto">
        </div>
        <div class="flex-1" />
        <MobileSearch v-if="!isLite" />
        <span v-else class="tb-scan"><ScanButton dark @detected="onTopbarScan" /></span>
        <NotificationBell />
      </div>
    </div>
    <div v-else class="topbar">
      <div class="topbar-row">
        <button type="button" class="tb-btn hamburger" aria-label="menu" @click="sidebarOpen = !sidebarOpen"><Icon name="menu" /></button>
        <div class="flex flex-none cursor-pointer items-center gap-[9px]" @click="router.push(auth.homePath)">
          <img src="/logo-white.png" alt="B2B ops — ERP System" class="block h-[30px] w-auto">
          <div class="tb-sub self-end pb-0.5 text-[8.5px] text-[#8b90a5]">{{ t('عمليات سلسلة الإمداد', 'Supply Chain Operations') }}</div>
        </div>
        <div class="flex-1" />
        <GlobalSearch v-if="!isLite" />
        <span v-else class="tb-scan"><ScanButton dark @detected="onTopbarScan" /></span>
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
              <button type="button" class="menu-item" @click="menuOpen = false; showCustomLabel()"><Icon name="scan" color="#7d7990" />{{ t('طباعة ملصق باركود / QR', 'Print a barcode / QR label') }}</button>
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
      <div v-if="!isMobile" class="sidebar-backdrop" :class="{ open: sidebarOpen }" @click="sidebarOpen = false" />
      <nav v-if="!isMobile" class="sidebar" :class="{ open: sidebarOpen }">
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
              <span id="page-title-slot" />
              <template v-if="pageHeader.titleSlot" />
              <template v-else-if="title">{{ title }}</template>
              <template v-else>{{ defaultTitle }} <span v-if="routeParam" class="num text-violet">{{ routeParam }}</span></template>
            </div>
            <div v-show="sub || pageHeader.subSlot" class="page-sub"><span id="page-sub-slot" /><template v-if="!pageHeader.subSlot">{{ sub }}</template></div>
          </div>
          <div class="flex-1" />
          <WarehouseChip v-if="isMobile && !route.meta.param" />
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

    <template v-if="isMobile">
      <MobileQuickActions v-if="showFab" />
      <MobileTabBar />
    </template>

    <ChangePasswordModal :open="passwordOpen || user.mustChangePassword" :forced="user.mustChangePassword" @close="passwordOpen = false" />
  </div>
</template>
