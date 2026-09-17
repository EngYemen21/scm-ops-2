<script setup>
// Change password. Forced (locked) while `user.mustChangePassword`; otherwise opened from the user menu.
// The API revokes every refresh token on change, so we sign in again silently with the new password.
import { ref } from 'vue';
import { api, isApiError } from '../api/client';
import Btn from '../components/Btn.vue';
import TextInput from '../components/TextInput.vue';
import { t } from '../i18n';
import { useAuth } from '../stores/auth';
import { toast } from '../stores/ui';
import ErrorBanner from './ErrorBanner.vue';
import Modal from './Modal.vue';

defineProps({ open: Boolean, forced: Boolean });
const emit = defineEmits(['close']);

const auth = useAuth();
const current = ref(''); const next = ref(''); const again = ref('');
const error = ref(null); const busy = ref(false);

async function submit() {
  if (next.value.length < 8) { error.value = { message: t('كلمة المرور 8 أحرف على الأقل', 'Password must be at least 8 characters') }; return; }
  if (next.value !== again.value) { error.value = { message: t('كلمتا المرور غير متطابقتين', 'Passwords do not match') }; return; }
  busy.value = true; error.value = null;
  try {
    await api.post('/auth/change-password', { currentPassword: current.value, newPassword: next.value });
    if (auth.user) { try { await auth.login(auth.user.username, next.value); } catch { auth.user = { ...auth.user, mustChangePassword: false }; } }
    toast.say({ ar: 'تم تغيير كلمة المرور ✓', en: 'Password changed ✓' });
    current.value = ''; next.value = ''; again.value = '';
    emit('close');
  } catch (e) { error.value = isApiError(e) ? e : e instanceof Error ? e : new Error(String(e)); } finally { busy.value = false; }
}
</script>

<template>
  <Modal :open="open" :locked="forced" :width="440" :title="{ ar: 'تغيير كلمة المرور', en: 'Change password' }"
         :sub="forced ? { ar: 'يجب تغيير كلمة المرور قبل المتابعة', en: 'You must change your password before continuing' } : null" @close="emit('close')">
    <ErrorBanner :error="error" @close="error = null" />
    <form class="col !gap-3" @submit.prevent="submit">
      <TextInput v-model="current" :label="{ ar: 'كلمة المرور الحالية', en: 'Current password' }" type="password" autocomplete="current-password" dir="ltr" autofocus />
      <TextInput v-model="next" :label="{ ar: 'كلمة المرور الجديدة', en: 'New password' }" type="password" autocomplete="new-password" dir="ltr" :hint="{ ar: '8 أحرف على الأقل', en: 'At least 8 characters' }" />
      <TextInput v-model="again" :label="{ ar: 'تأكيد كلمة المرور', en: 'Confirm password' }" type="password" autocomplete="new-password" dir="ltr" @enter="submit" />
    </form>
    <template #footer>
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-[42px] flex-1" :loading="busy" :label="{ ar: 'حفظ', en: 'Save' }" @click="submit" />
        <Btn v-if="forced" tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'خروج', en: 'Sign out' }" @click="auth.logout()" />
        <Btn v-else tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
