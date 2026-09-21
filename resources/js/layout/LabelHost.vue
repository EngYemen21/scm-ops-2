<script setup>
// Printable label dialog, mounted once in App.vue. Open it from anywhere:
//   showLabel({ type: 'code128', text: bin.code, title: bin.code, sub: 'RYD · A-01' })      shipping / shelf labels
//   showLabel({ type: 'qr', text: appLink(`/trip/${n}`), title: n, sub: 'رحلة' })           runs and customers
// The image comes from GET /api/barcodes/* (picqer CODE128 / endroid QR, as SVG). Printing shows only the label:
// `body.printing-label` + the print rules in app.css hide the application for that one print job.
import { computed, ref, watch } from 'vue';
import { api, isApiError } from '../api/client';
import { Btn, ErrorBanner, Modal } from '../components';
import { bi, isBi, t } from '../i18n';
import { labelState } from '../stores/ui';

const svg = ref('');
const error = ref(null);
const loading = ref(false);
const copies = ref(1);

const spec = computed(() => labelState.value);
const txt = (v) => (v == null ? '' : isBi(v) ? bi(v) : String(v));
const src = computed(() => (svg.value ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg.value)}` : ''));

watch(spec, async (s) => {
  svg.value = ''; error.value = null; copies.value = 1;
  if (!s) return;
  loading.value = true;
  try {
    const r = await api.get(`/barcodes/${s.type === 'qr' ? 'qr' : 'code128'}`, s.type === 'qr' ? { text: s.text, size: 260 } : { text: s.text, height: 72, module: 2 });
    if (labelState.value === s) svg.value = typeof r === 'string' ? r : '';
  } catch (e) {
    if (labelState.value === s) error.value = isApiError(e) ? e : { message: String(e?.message || e) };
  } finally { loading.value = false; }
});

const close = () => { labelState.value = null; };
function print() {
  const done = () => { document.body.classList.remove('printing-label'); window.removeEventListener('afterprint', done); };
  document.body.classList.add('printing-label');
  window.addEventListener('afterprint', done);
  window.print();
  setTimeout(done, 60_000); // browsers that never fire afterprint (some mobile ones)
}
</script>

<template>
  <Modal :open="!!spec" :title="spec?.type === 'qr' ? { ar: 'رمز QR', en: 'QR code' } : { ar: 'ملصق باركود', en: 'Barcode label' }" :sub="txt(spec?.title)" :width="440" :z-index="92" @close="close">
    <ErrorBanner :error="error" :closable="false" />
    <div class="label-preview">
      <div v-if="loading" class="skel h-[120px] w-full" />
      <div v-else-if="src" class="label-card" :class="spec.type">
        <div v-if="spec.title" class="label-title">{{ txt(spec.title) }}</div>
        <img :src="src" :alt="spec.text" class="label-img">
        <div class="label-text num">{{ spec.type === 'qr' ? txt(spec.caption ?? spec.title) : spec.text }}</div>
        <div v-if="spec.sub" class="label-sub">{{ txt(spec.sub) }}</div>
      </div>
    </div>
    <div v-if="spec?.type === 'qr'" class="hint teal !mt-3">{{ t('امسحه بكاميرا الجوال من زر البحث في النظام لفتح المستند مباشرة.', 'Scan it with the phone camera from the system’s search button to open the document directly.') }}</div>
    <template #footer>
      <div class="flex items-center gap-2">
        <label class="row !gap-1.5 text-[11px] font-extrabold text-muted">{{ t('نسخ', 'Copies') }}
          <input v-model.number="copies" type="number" min="1" max="50" class="inp sm !w-[72px] num" dir="ltr">
        </label>
        <Btn tone="dark" class="!h-11 flex-1" :disabled="!src" :label="{ ar: 'طباعة', en: 'Print' }" @click="print" />
        <Btn tone="soft" class="!h-11 w-[96px]" :label="{ ar: 'إغلاق', en: 'Close' }" @click="close" />
      </div>
    </template>
  </Modal>

  <!-- what the printer gets: only the labels, one per page -->
  <Teleport to="body">
    <div v-if="spec && src" class="label-sheet" aria-hidden="true">
      <div v-for="i in Math.max(1, Math.min(50, copies || 1))" :key="i" class="label-card" :class="spec.type">
        <div v-if="spec.title" class="label-title">{{ txt(spec.title) }}</div>
        <img :src="src" alt="" class="label-img">
        <div class="label-text num">{{ spec.type === 'qr' ? txt(spec.caption ?? spec.title) : spec.text }}</div>
        <div v-if="spec.sub" class="label-sub">{{ txt(spec.sub) }}</div>
      </div>
    </div>
  </Teleport>
</template>
