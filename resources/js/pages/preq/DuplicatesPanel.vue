<script setup>
// What duplicate detection found for a product that is being asked for (GET /product-requests/duplicates):
//   in red    what BLOCKS — the barcode or the code belongs to a product, or another running request carries the barcode;
//   in amber  what ASKS — a product or a running request of the same name: a person says whether it is another product;
//   plain     what only helps — the same name in another pack, similar names ("is it one of these?"), and the requests
//             that were rejected under this name, with the reason they were rejected for.
// A product opens its page and a request opens itself, both in a new tab, so the form being typed is not lost.
//   <DuplicatesPanel :found="found" :loading="checking" />
import { computed } from 'vue';
import { Chip } from '@/components';
import { nm, t } from '@/i18n';
import { MATCHES, STATUS, fmtAt } from './preq';

const props = defineProps({
  /** The answer of the duplicates check, or null before the first one. */
  found: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  /** Say so when nothing was found (the form), or stay silent (the drawer). */
  showClean: { type: Boolean, default: false },
});

const f = computed(() => props.found);
const products = computed(() => f.value?.names?.products || []);
const requests = computed(() => f.value?.names?.requests || []);
const rejected = computed(() => f.value?.names?.rejected || []);
const barcodeRequests = computed(() => f.value?.barcode?.requests || []);
const asking = computed(() => products.value.filter((p) => p.match === 'exact').length + requests.value.filter((r) => r.match === 'exact').length);
const tone = computed(() => (f.value?.blocked ? 'red' : f.value?.needsConfirmation ? 'amber' : 'neutral'));
const boxClass = computed(() => ({ red: 'border-[#F3C4C4] bg-[#FDECEC]', amber: 'border-[#F0DEB8] bg-[#FBF0DD]', neutral: 'border-line bg-soft' }[tone.value]));
const title = computed(() => {
  if (f.value?.blocked) return t('لا يمكن إرسال الطلب بهذه القيم', 'The request cannot be sent with these values');
  if (f.value?.needsConfirmation) return t(`يوجد ${asking.value === 1 ? 'صنف أو طلب' : 'أصناف أو طلبات'} بالاسم نفسه — هل هو صنف آخر؟`, 'The same name already exists — is this another product?');
  return t('قد يكون أحد هذه — تحقق قبل الإرسال', 'It may be one of these — check before sending');
});
const productLink = (sku) => ({ path: `/product/${encodeURIComponent(sku)}` });
const requestLink = (number) => ({ path: '/preq', query: { open: number } });
</script>

<template>
  <div v-if="f && !f.clean" class="rounded-xl border px-3 py-2.5" :class="boxClass" role="status">
    <div class="mb-1.5 text-[11px] font-extrabold" :class="tone === 'red' ? 'text-bad' : tone === 'amber' ? 'text-[#7a5410]' : 'text-ink'">{{ title }}</div>

    <!-- what blocks -->
    <div v-if="f.barcode.product" class="row !items-start mb-1 text-[10.5px] leading-[1.7] text-bad">
      <span class="min-w-0 flex-1">{{ t('الباركود', 'Barcode') }} <b class="num ltr">{{ f.barcode.value }}</b> {{ t('مسجَّل للصنف', 'belongs to product') }}
        <RouterLink :to="productLink(f.barcode.product.sku)" target="_blank" class="num ltr font-bold underline">{{ f.barcode.product.sku }}</RouterLink> — <span dir="auto">{{ nm(f.barcode.product) }}</span></span>
    </div>
    <div v-if="f.sku.product" class="row !items-start mb-1 text-[10.5px] leading-[1.7] text-bad">
      <span class="min-w-0 flex-1">{{ t('رمز الصنف', 'Product code') }} <RouterLink :to="productLink(f.sku.product.sku)" target="_blank" class="num ltr font-bold underline">{{ f.sku.product.sku }}</RouterLink>
        {{ t('مستخدم للصنف', 'is used by') }} «<span dir="auto">{{ nm(f.sku.product) }}</span>»</span>
    </div>
    <div v-for="r in barcodeRequests" :key="`b${r.id}`" class="row !items-start mb-1 text-[10.5px] leading-[1.7] text-bad">
      <span class="min-w-0 flex-1">{{ t('طلب آخر مفتوح بالباركود نفسه:', 'Another open request carries the same barcode:') }}
        <RouterLink :to="requestLink(r.number)" target="_blank" class="num ltr font-bold underline">{{ r.number }}</RouterLink> — <span dir="auto">{{ r.nameAr }}</span></span>
    </div>

    <!-- products and running requests of that name, the closest first -->
    <div v-for="p in products" :key="`p${p.id}`" class="row mb-1 rounded-[9px] bg-white/70 px-2 py-1.5">
      <Chip small :label="MATCHES[p.match] || MATCHES.similar" :fg="(MATCHES[p.match] || MATCHES.similar).fg" :bg="(MATCHES[p.match] || MATCHES.similar).bg" />
      <RouterLink :to="productLink(p.sku)" target="_blank" class="num ltr flex-none text-[10.5px] font-bold text-violet underline">{{ p.sku }}</RouterLink>
      <span class="min-w-0 flex-1 truncate text-[10.5px] font-bold text-ink [text-align:match-parent]" dir="auto" :title="p.nameAr">{{ nm(p) }}</span>
      <span v-if="!p.active" class="flex-none text-[9px] text-bad">{{ t('موقوف', 'inactive') }}</span>
    </div>
    <div v-for="r in requests" :key="`r${r.id}`" class="row mb-1 rounded-[9px] bg-white/70 px-2 py-1.5">
      <Chip small :label="MATCHES[r.match] || MATCHES.similar" :fg="(MATCHES[r.match] || MATCHES.similar).fg" :bg="(MATCHES[r.match] || MATCHES.similar).bg" />
      <RouterLink :to="requestLink(r.number)" target="_blank" class="num ltr flex-none text-[10.5px] font-bold text-violet underline">{{ r.number }}</RouterLink>
      <span class="min-w-0 flex-1 truncate text-[10.5px] font-bold text-ink [text-align:match-parent]" dir="auto" :title="r.nameAr">{{ r.nameAr }}</span>
      <Chip small :map="STATUS" :k="r.status" />
    </div>

    <!-- asked for before, and refused: the reason is what the person asking should read first -->
    <div v-for="r in rejected" :key="`x${r.id}`" class="mt-1 text-[10px] leading-[1.7] text-sec">
      {{ t('سبق رفض طلب بالاسم نفسه', 'A request of the same name was rejected before') }}
      <RouterLink :to="requestLink(r.number)" target="_blank" class="num ltr font-bold text-violet underline">{{ r.number }}</RouterLink>
      <template v-if="r.decidedAt"> · <span class="num ltr">{{ fmtAt(r.decidedAt) }}</span></template>
      <template v-if="r.rejectReason"> — «<span dir="auto">{{ r.rejectReason }}</span>»</template>
    </div>
  </div>
  <div v-else-if="showClean && f && f.clean && !loading" class="text-[9.5px] font-bold text-ok">{{ t('لا صنف ولا طلب مفتوح بهذا الاسم أو الباركود ✓', 'No product and no open request with this name or barcode ✓') }}</div>
  <div v-else-if="showClean && loading && !f" class="text-[9.5px] text-faint">{{ t('جارٍ التحقق من التكرار…', 'Checking for duplicates…') }}</div>
</template>
