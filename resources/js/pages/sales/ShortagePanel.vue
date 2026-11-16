<script setup>
// What an order is short of — read from the server's INSUFFICIENT_AVAILABLE refusal — and the way out: accept the order
// with the rest on ONE linked backorder ("قبول مع تعليق الباقي"), when the policy `sales.allowBackorder` permits it.
// Used by the direct sales-order form and the quotation → order dialog.
//   <ShortagePanel :error="act.error.value" :loading="act.pending.value" @accept="submit(true)" />
import { computed } from 'vue';
import { Btn } from '@/components';
import { fmtNum, lang, t } from '@/i18n';

const props = defineProps({
  /** The ApiError of the refused request (anything else renders nothing). */
  error: { type: null, default: null },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['accept']);

const d = computed(() => (props.error?.code === 'INSUFFICIENT_AVAILABLE' && props.error.details && typeof props.error.details === 'object' ? props.error.details : null));
/** `[{ sku, nameAr, nameEn, available, requested }]` — one row per line that cannot be covered. */
const rows = computed(() => (Array.isArray(d.value?.shortages) && d.value.shortages.length ? d.value.shortages : d.value?.sku ? [d.value] : []));
const name = (r) => (lang.value === 'ar' ? r.nameAr : r.nameEn || r.nameAr) || r.sku;
const GRID = 'bgrid grid grid-cols-[minmax(120px,1.4fr)_70px_70px_80px] gap-2';
</script>

<template>
  <div v-if="d && rows.length" class="mt-3 rounded-xl border border-[#F0D9A8] bg-[#FFFAF0] px-3.5 py-3">
    <div class="text-[11.5px] font-extrabold text-warn">{{ t('المخزون المتاح لا يغطي الطلب كاملًا', 'Available stock does not cover the whole order') }}</div>
    <div class="mt-2 overflow-x-auto"><div class="min-w-[360px]">
      <div :class="GRID" class="px-1 pb-1 text-[9px] font-extrabold text-faint">
        <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('المطلوب', 'Ordered') }}</div><div>{{ t('المتاح', 'Available') }}</div><div>{{ t('يُعلَّق', 'Backorder') }}</div>
      </div>
      <div v-for="r in rows" :key="r.sku" :class="GRID" class="items-center border-t border-[#F3E6C6] px-1 py-1.5">
        <div><div class="text-[10.5px] font-extrabold">{{ name(r) }}</div><div class="cell-sub">{{ r.sku }}</div></div>
        <div class="num text-[11px]">{{ fmtNum(r.requested) }}</div>
        <div class="num text-[11px] text-ok">{{ fmtNum(r.available) }}</div>
        <div class="num text-[11px] text-bad">{{ fmtNum(r.requested - r.available) }}</div>
      </div>
    </div></div>
    <template v-if="d.backorderAllowed">
      <div class="mt-2 text-[10px] leading-[1.7] text-sec">{{ t('يمكن قبول الطلب الآن: يُحجز المتاح ويُشحن، والباقي يصبح طلبًا معلّقًا مرتبطًا بهذا الأمر ينتظر توفر المخزون ويُحجز له تلقائيًا عند وصوله.', 'The order can be accepted now: what is available is reserved and shipped, the rest becomes a backorder linked to this order that waits for stock and is reserved automatically when it arrives.') }}</div>
      <Btn tone="primary" size="sm" class="mt-2" :loading="loading" :label="{ ar: 'قبول مع تعليق الباقي', en: 'Accept with the rest on backorder' }" @click="emit('accept')" />
    </template>
    <div v-else class="mt-2 text-[10px] leading-[1.7] text-sec">{{ t('قبول الطلب مع نقص غير مفعَّل في الإعدادات (sales.allowBackorder) — عدّل الكميات إلى المتاح أو انتظر التوريد.', 'Accepting an order with a shortage is turned off in the settings (sales.allowBackorder) — lower the quantities to what is available or wait for the stock.') }}</div>
  </div>
</template>
