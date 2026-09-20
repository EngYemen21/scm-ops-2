<script setup>
// Phone "More" screen: who is signed in, every page of the user's role grouped by domain, and the account actions
// that live in the desktop topbar's user menu (language, password, sign out). On a wide screen it sends you home.
import { computed, watchEffect } from 'vue';
import { useRouter } from 'vue-router';
import { PageHead } from '@/components';
import { isMobile } from '@/composables/viewport';
import { bi, dir, lang, setLang, t } from '@/i18n';
import Icon from '@/layout/Icon.vue';
import { moreGroups } from '@/layout/mobile/nav';
import { ROLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { passwordDialogOpen } from '@/stores/ui';

const auth = useAuth();
const router = useRouter();
watchEffect(() => { if (!isMobile.value) router.replace(auth.homePath); });

const user = computed(() => auth.user);
const userName = computed(() => (lang.value === 'ar' ? user.value?.nameAr : user.value?.nameEn) || '');
const roleName = computed(() => (user.value?.roles || []).map((r) => (ROLE_LABELS[r] ? bi(ROLE_LABELS[r]) : r)).join(' · '));
const groups = computed(() => moreGroups(user.value));
const forward = computed(() => (dir.value === 'rtl' ? 'chevronLeft' : 'chevronRight'));

async function signOut() {
  await auth.logout();
  router.replace('/login');
}
</script>

<template>
  <PageHead hidden />
  <div v-if="user" class="m-more">
    <div class="m-profile">
      <div class="m-avatar">{{ user.initials || userName.slice(0, 1) }}</div>
      <div class="min-w-0 flex-1">
        <div class="ellipsis text-[15px] font-extrabold">{{ userName }}</div>
        <div class="ellipsis mt-0.5 text-[11px] text-muted">{{ roleName }} · <span class="num">{{ user.username }}</span></div>
      </div>
    </div>

    <template v-for="g in groups" :key="g.key">
      <div class="m-sec-head"><span>{{ bi(g.label) }}</span></div>
      <div class="m-list">
        <RouterLink v-for="it in g.items" :key="it.key" :to="it.to" class="m-list-row">
          <span class="m-row-ico" :style="{ background: g.tint[0] }"><Icon :nav="it.key" :size="17" :color="g.tint[1]" /></span>
          <span class="flex-1 text-[13px] font-extrabold text-ink">{{ bi(it.label) }}</span>
          <Icon :name="forward" :size="14" color="#c9c6d4" />
        </RouterLink>
      </div>
    </template>

    <div class="m-sec-head"><span>{{ t('الحساب', 'Account') }}</span></div>
    <div class="m-list">
      <button type="button" class="m-list-row" @click="setLang(lang === 'ar' ? 'en' : 'ar')">
        <span class="m-row-ico" style="background: #f1eff6"><Icon name="globe" :size="17" color="#55506a" /></span>
        <span class="flex-1 text-start text-[13px] font-extrabold">{{ t('اللغة', 'Language') }}</span>
        <span class="text-[11.5px] font-bold text-muted">{{ lang === 'ar' ? 'English' : 'عربي' }}</span>
      </button>
      <button type="button" class="m-list-row" @click="passwordDialogOpen = true">
        <span class="m-row-ico" style="background: #f1eff6"><Icon name="lock" :size="17" color="#55506a" /></span>
        <span class="flex-1 text-start text-[13px] font-extrabold">{{ t('تغيير كلمة المرور', 'Change password') }}</span>
        <Icon :name="forward" :size="14" color="#c9c6d4" />
      </button>
      <button type="button" class="m-list-row" @click="signOut">
        <span class="m-row-ico" style="background: #fdecec"><Icon name="logout" :size="17" color="#b23b3b" /></span>
        <span class="flex-1 text-start text-[13px] font-extrabold text-bad">{{ t('تسجيل الخروج', 'Sign out') }}</span>
      </button>
    </div>

    <div class="mt-5 text-center text-[9.5px] leading-[1.9] text-faint">{{ t('B2B ops · الصلاحيات مفروضة من الخادم (RBAC)', 'B2B ops · permissions enforced by the server (RBAC)') }}</div>
  </div>
</template>
