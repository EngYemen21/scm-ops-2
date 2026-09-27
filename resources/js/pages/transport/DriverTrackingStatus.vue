<script setup>
// "My trips" card: the state of this phone's trip tracking, in plain words, with the one action that fixes it.
import { computed } from 'vue';
import { fmtAgo } from '@/i18n';
import { acceptTracking, openLocationSettings, tracking, withdrawTracking } from '@/composables/driverTracking';

const s = computed(() => tracking.state);
const view = computed(() => {
  if (!tracking.supported) return { tone: 'muted', text: 'تتبع الرحلة من الجوال يعمل من تطبيق B2B ops على الجوال فقط.' };
  if (!s.value) return { tone: 'muted', text: 'جارٍ التحقق من حالة التتبع…' };
  if (s.value.needsConsent) return { tone: 'warn', text: 'رحلتك نشطة — وافق على تتبع الموقع أثناء الرحلة.', action: 'consent' };
  if (tracking.permission === 'denied') return { tone: 'bad', text: 'إذن الموقع مرفوض — افتح الإعدادات واختر «السماح دائمًا».', action: 'settings' };
  if (s.value.tracking && tracking.running) {
    const sent = tracking.lastSentAt ? `آخر إرسال ${fmtAgo(tracking.lastSentAt)}` : 'بانتظار أول موقع';
    return { tone: 'ok', text: `📍 تتبع الرحلة ${s.value.trip?.number || ''} يعمل · ${sent}${tracking.queued ? ` · ${tracking.queued} بانتظار الشبكة` : ''}` };
  }
  if (s.value.consentedAt) return { tone: 'muted', text: 'التتبع يبدأ تلقائيًا عند إرسال رحلتك ويتوقف عند إقفالها.', action: 'withdraw' };
  return { tone: 'muted', text: 'لا رحلة نشطة — لا يتم تتبع موقعك.' };
});
const color = { ok: '#1d7a3e', warn: '#b26a16', bad: '#b23b3b', muted: '#8b90a5' };
</script>

<template>
  <div class="mt-2 rounded-xl bg-white/5 px-3 py-2 text-[10.5px] leading-[1.8]" :style="{ color: color[view.tone] === '#8b90a5' ? '#8b90a5' : '#fff' }">
    <span class="me-1 inline-block h-2 w-2 rounded-full align-middle" :style="{ background: color[view.tone] }" />{{ view.text }}
    <button v-if="view.action === 'consent'" type="button" class="ms-2 cursor-pointer font-extrabold text-brand underline" @click="acceptTracking">موافقة</button>
    <button v-if="view.action === 'settings'" type="button" class="ms-2 cursor-pointer font-extrabold text-brand underline" @click="openLocationSettings">الإعدادات</button>
    <button v-if="view.action === 'withdraw'" type="button" class="ms-2 cursor-pointer text-[9.5px] text-[#8b90a5] underline" @click="withdrawTracking">إيقاف الموافقة</button>
  </div>
</template>
