<script setup>
// Printable label dialog, mounted once in App.vue. Open it from anywhere (stores/ui.js):
//   showLabel({ type: 'code128', text: bin.code, title: bin.code, sub: 'RYD · A-01' })      one label
//   showLabels(bins.map((b) => ({ type: 'code128', text: b.code, title: b.code })))          a whole rack / zone at once
//   showCustomLabel()                                                                        free text, CODE128 or QR
// CODE128 (picqer) = shipping and shelf labels; QR (endroid) = runs and customers. Images come from
// GET /api/barcodes/* as SVG. Printing shows only the labels, one per page: `body.printing-label` + the print rules
// in app.css hide the application for that print job.
import { computed, ref, watch } from 'vue';
import { api, isApiError } from '../api/client';
import { Btn, ErrorBanner, Modal, PillChoice, TextInput } from '../components';
import { bi, fmtNum, isBi, t } from '../i18n';
import { labelState } from '../stores/ui';

const MAX = 300; // labels per print job
const images = ref({}); // `${type}|${text}` -> data URL
const error = ref(null);
const loading = ref(false);
const copies = ref(1);
const custom = ref({ type: 'code128', text: '', title: '' });

const state = computed(() => labelState.value);
const isCustom = computed(() => !!state.value?.custom);
const items = computed(() => {
  if (!state.value) return [];
  if (isCustom.value) { const c = custom.value; return c.text.trim() ? [{ type: c.type, text: c.text.trim(), title: c.title.trim() || null }] : []; }
  return (state.value.items || []).slice(0, MAX);
});
const txt = (v) => (v == null ? '' : isBi(v) ? bi(v) : String(v));
const keyOf = (it) => `${it.type}|${it.text}`;
const srcOf = (it) => images.value[keyOf(it)] || '';
const ready = computed(() => items.value.length > 0 && items.value.every((it) => srcOf(it)));
const first = computed(() => items.value[0] || null);
const sheet = computed(() => items.value.flatMap((it) => Array.from({ length: Math.max(1, Math.min(50, copies.value || 1)) }, () => it)));

async function load(list) {
  error.value = null;
  const missing = list.filter((it) => !images.value[keyOf(it)]);
  if (!missing.length) return;
  loading.value = true;
  try {
    // the whole job in ONE request (a zone can hold hundreds of bins), identical texts drawn once
    const unique = [...new Map(missing.map((it) => [keyOf(it), it])).values()];
    const r = await api.post('/barcodes/batch', { items: unique.map((it) => ({ type: it.type === 'qr' ? 'qr' : 'code128', text: it.text })) });
    const next = { ...images.value };
    unique.forEach((it, j) => { const svg = r?.items?.[j]; if (typeof svg === 'string') next[keyOf(it)] = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`; });
    images.value = next;
  } catch (e) {
    error.value = isApiError(e) ? e : { message: String(e?.message || e) };
  } finally { loading.value = false; }
}

watch(state, (s) => { copies.value = 1; error.value = null; if (s?.custom) custom.value = { type: 'code128', text: '', title: '' }; else if (s) load(items.value); });
let typing;
watch(custom, () => { clearTimeout(typing); if (isCustom.value) typing = setTimeout(() => load(items.value), 350); }, { deep: true });

const close = () => { labelState.value = null; };
function print() {
  const done = () => { document.body.classList.remove('printing-label'); window.removeEventListener('afterprint', done); };
  document.body.classList.add('printing-label');
  window.addEventListener('afterprint', done);
  window.print();
  setTimeout(done, 60_000); // browsers that never fire afterprint (some mobile ones)
}
const heading = computed(() => (isCustom.value ? { ar: 'ملصق مخصص', en: 'Custom label' } : items.value.length > 1 ? { ar: `طباعة ${fmtNum(items.value.length)} ملصقًا`, en: `Print ${fmtNum(items.value.length)} labels` } : first.value?.type === 'qr' ? { ar: 'رمز QR', en: 'QR code' } : { ar: 'ملصق باركود', en: 'Barcode label' }));
</script>

<template>
  <Modal :open="!!state" :title="heading" :sub="isCustom ? null : items.length === 1 ? txt(first?.title) : txt(state?.caption)" :width="460" :z-index="92" @close="close">
    <div v-if="isCustom" class="form-grid mb-3">
      <PillChoice v-model="custom.type" full :label="{ ar: 'النوع', en: 'Type' }" :options="[{ v: 'code128', l: { ar: 'باركود CODE128 — شحن ورفوف', en: 'CODE128 — shipping & shelves' } }, { v: 'qr', l: { ar: 'رمز QR — تشغيلات وعملاء', en: 'QR — runs & customers' } }]" />
      <TextInput v-model="custom.text" full scan required :dir="custom.type === 'qr' ? null : 'ltr'" :mono="custom.type !== 'qr'" :label="{ ar: 'النص الذي سيقرؤه الماسح', en: 'Text the scanner will read' }"
                 :hint="custom.type === 'qr' ? { ar: 'يقبل العربية والروابط — حتى 600 حرف', en: 'Arabic and links allowed — up to 600 characters' } : { ar: 'أحرف وأرقام لاتينية فقط — حتى 48 حرفًا', en: 'Latin letters and digits only — up to 48 characters' }" />
      <TextInput v-model="custom.title" full :label="{ ar: 'عنوان يُطبع فوق الرمز (اختياري)', en: 'Title printed above the code (optional)' }" />
    </div>

    <ErrorBanner :error="error" :closable="false" />
    <div class="label-preview">
      <div v-if="loading && !first" class="skel h-[120px] w-full" />
      <div v-else-if="first && srcOf(first)" class="label-card" :class="first.type">
        <div v-if="first.title" class="label-title">{{ txt(first.title) }}</div>
        <img :src="srcOf(first)" :alt="first.text" class="label-img">
        <div class="label-text num">{{ first.type === 'qr' ? txt(first.caption ?? first.title) : first.text }}</div>
        <div v-if="first.sub" class="label-sub">{{ txt(first.sub) }}</div>
      </div>
      <div v-else-if="isCustom" class="empty dashed !w-full !rounded-xl !p-6">{{ t('اكتب النص لتظهر المعاينة', 'Type the text to see the preview') }}</div>
    </div>
    <div v-if="items.length > 1" class="hint !mt-3">
      {{ t(`المعاينة لأول ملصق. سيُطبع ${fmtNum(items.length)} ملصقًا — كل ملصق في صفحة.`, `Preview of the first label. ${fmtNum(items.length)} labels will print — one per page.`) }}
      <span v-if="loading"> · <span class="pulse">{{ t('جارٍ التجهيز…', 'Preparing…') }}</span></span>
      <span v-if="(state?.items?.length || 0) > MAX" class="text-bad"> · {{ t(`الحد ${MAX} ملصق في المرة الواحدة`, `Limit: ${MAX} labels per job`) }}</span>
    </div>
    <div v-else-if="first?.type === 'qr' && !isCustom" class="hint teal !mt-3">{{ t('امسحه بزر المسح في البحث لفتح المستند مباشرة.', 'Scan it with the scan button in the search to open the document directly.') }}</div>

    <template #footer>
      <div class="flex items-center gap-2">
        <label class="row !gap-1.5 text-[11px] font-extrabold text-muted">{{ t('نسخ', 'Copies') }}
          <input v-model.number="copies" type="number" min="1" max="50" class="inp sm num !w-[72px]" dir="ltr">
        </label>
        <Btn tone="dark" class="!h-11 flex-1" :disabled="!ready" :loading="loading && items.length > 1" :label="{ ar: 'طباعة', en: 'Print' }" @click="print" />
        <Btn tone="soft" class="!h-11 w-[96px]" :label="{ ar: 'إغلاق', en: 'Close' }" @click="close" />
      </div>
    </template>
  </Modal>

  <!-- what the printer gets: only the labels, one per page -->
  <Teleport to="body">
    <div v-if="state && ready" class="label-sheet" aria-hidden="true">
      <div v-for="(it, i) in sheet" :key="i" class="label-card" :class="it.type">
        <div v-if="it.title" class="label-title">{{ txt(it.title) }}</div>
        <img :src="srcOf(it)" alt="" class="label-img">
        <div class="label-text num">{{ it.type === 'qr' ? txt(it.caption ?? it.title) : it.text }}</div>
        <div v-if="it.sub" class="label-sub">{{ txt(it.sub) }}</div>
      </div>
    </div>
  </Teleport>
</template>
