<script setup>
// Camera scanner (html5-qrcode) for barcodes and QR codes: full-screen, back camera, torch when the phone has one.
//   <BarcodeScanner :open="scanning" @detected="onCode" @close="scanning = false" />
// - The library is loaded only when the scanner opens, so it costs nothing on pages that never scan.
// - 1D codes must be read twice in a row before they are accepted: a single frame of a damaged or tilted label can
//   decode to a wrong number, and a wrong SKU or bin is worse than a half-second wait. QR / DataMatrix carry their
//   own error correction and are accepted at once.
// - The camera needs HTTPS (or localhost) and the user's permission; every failure says what to do about it.
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useScrollLock } from '../composables/scrollLock';
import { bi, dir, t } from '../i18n';
import Icon from '../layout/Icon.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: () => ({ ar: 'مسح الباركود', en: 'Scan barcode' }) },
  hint: { type: [String, Object], default: () => ({ ar: 'وجّه الكاميرا نحو الباركود أو رمز QR', en: 'Point the camera at the barcode or QR code' }) },
});
const emit = defineEmits(['detected', 'close']);

const REGION = 'scm-scanner-region';
const TWO_D = new Set(['QR_CODE', 'DATA_MATRIX', 'AZTEC', 'PDF_417']);
const state = ref('idle'); // idle | starting | scanning | error
const error = ref(null);
const torchAvailable = ref(false);
const torchOn = ref(false);
let engine = null;
let last = { text: '', hits: 0 };

useScrollLock(() => props.open);

function explain(e) {
  const name = e?.name || ''; const msg = String(e?.message || e || '');
  if (!window.isSecureContext) return t('الكاميرا تعمل على رابط آمن (https) فقط.', 'The camera only works on a secure (https) address.');
  if (name === 'NotAllowedError' || /permission|denied/i.test(msg)) return t('لم يُسمح باستخدام الكاميرا. اسمح بها من إعدادات الموقع في المتصفح ثم أعد المحاولة.', 'Camera permission was denied. Allow it in the browser’s site settings and try again.');
  if (name === 'NotFoundError' || /no camera|not found/i.test(msg)) return t('لا توجد كاميرا في هذا الجهاز.', 'This device has no camera.');
  if (name === 'NotReadableError') return t('الكاميرا مستخدمة من تطبيق آخر. أغلقه ثم أعد المحاولة.', 'The camera is in use by another app. Close it and try again.');
  return t('تعذر تشغيل الكاميرا: ', 'Could not start the camera: ') + msg.slice(0, 120);
}

function onRead(text, result) {
  const format = result?.result?.format?.formatName || '';
  const value = String(text || '').trim();
  if (!value) return;
  if (!TWO_D.has(format)) {
    last = last.text === value ? { text: value, hits: last.hits + 1 } : { text: value, hits: 1 };
    if (last.hits < 2) return;
  }
  try { navigator.vibrate?.(60); } catch { /* not supported */ }
  emit('detected', value, format);
}

async function start() {
  state.value = 'starting'; error.value = null; torchOn.value = false; torchAvailable.value = false; last = { text: '', hits: 0 };
  try {
    if (!navigator.mediaDevices?.getUserMedia) throw new Error(t('المتصفح لا يدعم الكاميرا', 'This browser has no camera support'));
    const { Html5Qrcode, Html5QrcodeSupportedFormats: F } = await import('html5-qrcode');
    await nextTick();
    engine = new Html5Qrcode(REGION, {
      verbose: false,
      useBarCodeDetectorIfSupported: true, // the phone's native detector is faster where it exists
      formatsToSupport: [F.CODE_128, F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.CODE_39, F.ITF, F.QR_CODE, F.DATA_MATRIX],
    });
    await engine.start(
      { facingMode: 'environment' },
      { fps: 12, disableFlip: true, qrbox: (w, h) => ({ width: Math.floor(w * 0.88), height: Math.floor(Math.min(h * 0.42, 230)) }) },
      onRead,
      () => { /* no code in this frame — normal */ },
    );
    if (!props.open) { await stop(); return; } // closed while the camera was starting
    state.value = 'scanning';
    try { torchAvailable.value = !!engine.getRunningTrackCapabilities()?.torch; } catch { torchAvailable.value = false; }
  } catch (e) {
    error.value = explain(e); state.value = 'error';
    await stop();
  }
}

async function stop() {
  const e = engine; engine = null;
  if (!e) return;
  try { if (e.isScanning) await e.stop(); } catch { /* already stopped */ }
  try { e.clear(); } catch { /* nothing to clear */ }
}

async function toggleTorch() {
  if (!engine) return;
  try { await engine.applyVideoConstraints({ advanced: [{ torch: !torchOn.value }] }); torchOn.value = !torchOn.value; } catch { torchAvailable.value = false; }
}

watch(() => props.open, (open) => { if (open) start(); else { stop(); state.value = 'idle'; } }, { immediate: true });
onBeforeUnmount(stop);
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="scn" :dir="dir" role="dialog" aria-modal="true" @keydown.esc="emit('close')">
      <div class="scn-bar">
        <div class="min-w-0 flex-1">
          <div class="text-[15px] font-extrabold text-white">{{ bi(title) }}</div>
          <div class="ellipsis mt-0.5 text-[11px] text-[#8b90a5]">{{ bi(hint) }}</div>
        </div>
        <button v-if="torchAvailable" type="button" class="scn-btn" :class="{ on: torchOn }" :aria-pressed="torchOn" :aria-label="t('الإضاءة', 'Torch')" @click="toggleTorch">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3h8l-1 6h3l-8 12 2-9H7l1-9z" /></svg>
        </button>
        <button type="button" class="scn-btn" :aria-label="t('إغلاق', 'Close')" @click="emit('close')"><Icon name="x" :size="17" color="#fff" /></button>
      </div>

      <div class="scn-stage">
        <div :id="REGION" class="scn-video" />
        <div v-if="state === 'starting'" class="scn-note"><span class="pulse">{{ t('جارٍ تشغيل الكاميرا…', 'Starting the camera…') }}</span></div>
        <div v-if="state === 'error'" class="scn-note">
          <div class="text-[13px] font-extrabold text-white">{{ t('الكاميرا غير متاحة', 'Camera unavailable') }}</div>
          <div class="mt-2 leading-[1.9]">{{ error }}</div>
          <button type="button" class="btn primary mt-4" @click="start">{{ t('إعادة المحاولة', 'Try again') }}</button>
        </div>
      </div>

      <div class="scn-foot">{{ t('يدعم: CODE128 · EAN-13 · QR — ويمكنك دائمًا كتابة الرقم يدويًا', 'Supports CODE128 · EAN-13 · QR — you can always type the number instead') }}</div>
    </div>
  </Teleport>
</template>
