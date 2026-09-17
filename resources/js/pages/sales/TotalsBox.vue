<script setup>
// Subtotal / VAT / total box shown under line tables and the line editor.
//   <TotalsBox :totals="{ sub, vatPct, vat, total }" wide currency />
import { fmtMoney, t } from '@/i18n';

defineProps({
  totals: { type: Object, required: true },
  /** Detail cards use the wider box (260px); the line editor the compact one (230px). */
  wide: { type: Boolean, default: false },
  /** Append the currency label to the total. */
  currency: { type: Boolean, default: false },
});
</script>

<template>
  <div class="rounded-xl border border-line-2 bg-soft text-[10.5px]" :class="wide ? 'min-w-[260px] px-4 py-2.5' : 'min-w-[230px] px-3.5 py-2'">
    <div class="row justify-between"><span class="muted font-extrabold">{{ t('المجموع', 'Subtotal') }}</span><span class="num">{{ fmtMoney(totals.sub) }}</span></div>
    <div class="row justify-between"><span class="muted font-extrabold">{{ t(`ضريبة ${totals.vatPct}%`, `VAT ${totals.vatPct}%`) }}</span><span class="num">{{ fmtMoney(totals.vat) }}</span></div>
    <div class="row justify-between border-t border-line" :class="wide ? 'mt-[5px] pt-[5px]' : 'mt-1 pt-1'">
      <span class="font-extrabold">{{ t('الإجمالي', 'Total') }}</span>
      <span class="num text-[13px] text-violet">{{ fmtMoney(totals.total) }}<span v-if="currency" class="font-sans text-[9px] text-faint"> {{ t('ر.س', 'SAR') }}</span></span>
    </div>
  </div>
</template>
