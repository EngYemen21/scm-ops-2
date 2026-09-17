<script setup>
// Sign-in: centered card, remembers the username, shows API errors through ErrorBanner.
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isApiError } from '../api/client';
import Btn from '../components/Btn.vue';
import TextInput from '../components/TextInput.vue';
import { lang, t, toggleLang } from '../i18n';
import ErrorBanner from '../layout/ErrorBanner.vue';
import Icon from '../layout/Icon.vue';
import { navPath, useAuth } from '../stores/auth';

const LS_USER = 'scm.username';
const auth = useAuth();
const router = useRouter();
const route = useRoute();

const username = ref((() => { try { return localStorage.getItem(LS_USER) || ''; } catch { return ''; } })());
const password = ref('');
const remember = ref(!!username.value);
const error = ref(null);
const busy = ref(false);

async function submit() {
  if (!username.value.trim() || !password.value) { error.value = { message: t('أدخل اسم المستخدم وكلمة المرور', 'Enter username and password') }; return; }
  busy.value = true; error.value = null;
  try {
    const u = await auth.login(username.value.trim(), password.value);
    try { if (remember.value) localStorage.setItem(LS_USER, username.value.trim()); else localStorage.removeItem(LS_USER); } catch { /* ignore */ }
    const from = typeof route.query.from === 'string' && route.query.from !== '/login' ? route.query.from : null;
    router.replace(from || navPath(u.nav[0] || 'dash'));
  } catch (e) { error.value = isApiError(e) ? e : e instanceof Error ? e : new Error(String(e)); } finally { busy.value = false; }
}
</script>

<template>
  <div class="login-wrap">
    <div class="login-card">
      <div class="mb-[22px] flex items-center gap-2.5">
        <div class="flex-1">
          <img src="/logo.png" alt="B2B ops — ERP System" class="mb-1.5 block h-11 w-auto">
          <div class="text-[10px] text-muted">{{ t('عمليات سلسلة الإمداد', 'Supply Chain Operations') }}</div>
        </div>
        <button type="button" class="btn soft sm" @click="toggleLang"><Icon name="globe" color="#7d7990" />{{ lang === 'ar' ? 'EN' : 'عربي' }}</button>
      </div>
      <div class="mb-3.5 text-[13px] font-extrabold">{{ t('تسجيل الدخول', 'Sign in') }}</div>
      <ErrorBanner :error="error" @close="error = null" />
      <form class="col !gap-3" @submit.prevent="submit">
        <TextInput v-model="username" :label="{ ar: 'اسم المستخدم', en: 'Username' }" autocomplete="username" dir="ltr" mono :autofocus="!username" />
        <TextInput v-model="password" :label="{ ar: 'كلمة المرور', en: 'Password' }" type="password" autocomplete="current-password" dir="ltr" :autofocus="!!username" />
        <label class="flex cursor-pointer items-center gap-[7px] text-[10.5px] font-bold text-sec">
          <input v-model="remember" type="checkbox" class="accent-violet">
          {{ t('تذكر اسم المستخدم', 'Remember username') }}
        </label>
        <Btn type="submit" tone="dark" size="lg" block :loading="busy" :label="{ ar: 'دخول', en: 'Sign in' }" />
      </form>
      <div class="mt-4 text-center text-[9px] leading-[1.8] text-faint">{{ t('نظام عمليات سلسلة الإمداد — الوصول محكوم بالأدوار والصلاحيات (RBAC)', 'Supply chain operations system — access is governed by roles & permissions (RBAC)') }}</div>
    </div>
  </div>
</template>
