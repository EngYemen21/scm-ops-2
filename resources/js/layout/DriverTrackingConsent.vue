<script setup>
// One-time disclosure + consent for driver phone tracking (native app). Shown when the server says a trip is active and
// the driver has not accepted yet. Store rules (Google Play "prominent disclosure", Apple background location) require
// saying what is collected, when, and why BEFORE the OS permission prompt — which only appears after "أوافق".
import { ref } from 'vue';
import { acceptTracking, tracking } from '@/composables/driverTracking';
import Modal from './Modal.vue';

const busy = ref(false);
const later = ref(false);
async function accept() {
  busy.value = true;
  try { await acceptTracking(); } finally { busy.value = false; }
}
</script>

<template>
  <Modal :open="!!tracking.state?.needsConsent && !later" :title="{ ar: 'تتبع موقعك أثناء الرحلة', en: 'Location during your trip' }" :width="460" @close="later = true">
    <div class="text-[13px] leading-[1.9]">
      <p class="mb-2">لديك رحلة نشطة <b class="num">{{ tracking.state?.trip?.number }}</b>. يستخدم التطبيق موقع جوالك لمتابعة الرحلة وسلامة التسليم:</p>
      <ul class="mb-2 list-disc ps-5">
        <li><b>متى:</b> أثناء الرحلات المُسندة لك فقط، ويتوقف تلقائيًا عند إقفال الرحلة.</li>
        <li><b>حتى والتطبيق في الخلفية أو الشاشة مقفلة</b> — يظهر إشعار دائم طوال مدة التتبع.</li>
        <li><b>ماذا:</b> الموقع والسرعة والوقت، ويراه مسؤولو التشغيل في شركتك فقط.</li>
        <li>يمكنك إيقاف الموافقة من شاشة «رحلاتي» في أي وقت.</li>
      </ul>
      <p class="text-[11.5px] text-muted">بعد الموافقة سيطلب النظام إذن الموقع — اختر «السماح دائمًا» ليعمل التتبع والتطبيق في الخلفية.</p>
    </div>
    <template #footer>
      <button type="button" class="btn soft" @click="later = true">لاحقًا</button>
      <span class="flex-1" />
      <button type="button" class="btn primary" :disabled="busy" @click="accept">{{ busy ? '…' : 'أوافق وابدأ' }}</button>
    </template>
  </Modal>
</template>
