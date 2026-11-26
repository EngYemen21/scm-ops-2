<script setup>
// The cart of the selling screen: the customer, one line per product (whole-number quantity stepper, line discount
// within the register's limit, remove), the server's totals (POST /pos/quote) and the actions — pay, hold, new sale.
// Stock the warehouse cannot cover is flagged on its line (quote.stockShort, or the sale's POS_STOCK_SHORT).
import { computed } from 'vue';
import { Btn, EmptyState, ErrorBanner, NumberInput } from '@/components';
import { fmtMoney, num, t } from '@/i18n';
import CustomerPick from './CustomerPick.vue';
import { fmtCents, cents, money, nameOf, pct } from './pos';

const props = defineProps({
  cart: { type: Object, required: true },
  quote: { type: Object, default: null },
  quoteError: { type: null, default: null },
  quoting: { type: Boolean, default: false },
  stale: { type: Boolean, default: false },
  register: { type: Object, default: null },
  /** productIds the last refusal named (POS_STOCK_SHORT) and line indexes (POS_DISCOUNT_LIMIT / POS_NO_PRICE). */
  badProducts: { type: Array, default: () => [] },
  badLine: { type: Number, default: -1 },
  busy: { type: Boolean, default: false },
  /** The cart is priced, current and payable (worked out by the selling screen). */
  canPay: { type: Boolean, default: false },
  /** Hide the pay bar (the phone has its own sticky bar). */
  compact: { type: Boolean, default: false },
});
const emit = defineEmits(['pay', 'hold', 'clear', 'held', 'requote']);

const lines = computed(() => props.cart.state.lines);
const maxDisc = computed(() => num(props.register?.maxDiscountPct ?? 0));
const qLine = (i) => { const ql = props.quote?.lines?.[i]; return ql && ql.productId === lines.value[i]?.productId ? ql : null; };
const short = computed(() => Object.fromEntries((props.quote?.stockShort || []).map((s) => [s.productId, s])));
const allowNegative = computed(() => !!props.quote?.allowNegativeStock || !!props.register?.allowNegativeStock);
const quoteErrLine = computed(() => Number(props.quoteError?.details?.line || 0) - 1);
const isBad = (l, i) => props.badProducts.includes(l.productId) || props.badLine === i || quoteErrLine.value === i;
const blockedByStock = computed(() => !allowNegative.value && Object.keys(short.value).length > 0);
const stepQty = (i, by) => props.cart.setQty(i, (lines.value[i]?.qty || 1) + by);
</script>

<template>
  <div class="card flex min-h-0 flex-col">
    <div class="border-b border-line p-3">
      <CustomerPick :model-value="cart.state.customer" :walk-in="register?.customer || (!cart.state.customer && quote ? quote.customer : null) || null" :disabled="busy" @update:model-value="cart.setCustomer" />
    </div>

    <div class="flex items-center gap-2 px-3 pt-2.5">
      <div class="card-title flex-1">{{ t('السلة', 'Cart') }} <span class="card-count num ms-1">{{ cart.count.value }}</span></div>
      <span class="text-[10px] text-muted">{{ t('أقصى خصم للسطر', 'Max line discount') }} <b class="num">{{ pct(maxDisc) }}</b></span>
    </div>

    <div class="min-h-[120px] flex-1 overflow-y-auto px-3 py-2">
      <EmptyState v-if="!lines.length" tone="dashed" :text="{ ar: 'السلة فارغة — امسح الباركود أو اختر صنفًا', en: 'The cart is empty — scan a barcode or pick a product' }" />
      <div v-for="(l, i) in lines" :key="l.productId" class="mb-2 rounded-xl border p-2.5" :class="isBad(l, i) ? 'border-bad bg-bad-soft' : short[l.productId] ? 'border-warn' : 'border-line'">
        <div class="flex items-start gap-2">
          <div class="min-w-0 flex-1">
            <div class="truncate text-[12px] font-extrabold text-ink" dir="auto">{{ nameOf(l) !== '—' ? nameOf(l) : (qLine(i) ? nameOf(qLine(i)) : l.productId) }}</div>
            <div class="text-[10px] text-muted"><span class="num ltr">{{ l.sku || qLine(i)?.sku || '' }}</span> · {{ t('سعر الوحدة', 'Unit') }} <span class="num ltr">{{ fmtMoney(num(qLine(i)?.unitPrice ?? l.price)) }}</span></div>
          </div>
          <button type="button" class="x-btn !h-7 !w-7 text-bad" :aria-label="t('حذف السطر', 'Remove line')" :disabled="busy" @click="cart.remove(i)">✕</button>
        </div>
        <div class="mt-2 flex flex-wrap items-end gap-2">
          <div>
            <div class="mb-0.5 text-[9.5px] font-bold text-muted">{{ t('الكمية', 'Qty') }}</div>
            <div class="flex items-center gap-1">
              <button type="button" class="btn soft sm !w-8 !px-0" :disabled="busy || l.qty <= 1" @click="stepQty(i, -1)">−</button>
              <NumberInput small center class="!w-[64px]" field-class="!mb-0" :model-value="l.qty" :min="1" :step="1" :disabled="busy" @update:model-value="(v) => cart.setQty(i, v ?? 1)" />
              <button type="button" class="btn soft sm !w-8 !px-0" :disabled="busy" @click="stepQty(i, 1)">+</button>
            </div>
          </div>
          <div>
            <div class="mb-0.5 text-[9.5px] font-bold text-muted">{{ t('خصم %', 'Disc. %') }}</div>
            <NumberInput small center class="!w-[70px]" field-class="!mb-0" :model-value="l.discountPct || null" :min="0" :max="maxDisc" :disabled="busy || maxDisc <= 0" placeholder="0"
                         :title="t(`حتى ${maxDisc}%`, `Up to ${maxDisc}%`)" @update:model-value="(v) => cart.setDiscount(i, v ?? 0, maxDisc)" />
          </div>
          <div class="ms-auto text-end">
            <div class="text-[9.5px] font-bold text-muted">{{ t('الصافي', 'Net') }}</div>
            <div class="num ltr text-[13px] font-extrabold" :class="stale ? 'text-faint' : 'text-ink'">{{ qLine(i) ? fmtMoney(num(qLine(i).lineTotal)) : '…' }}</div>
          </div>
        </div>
        <div v-if="short[l.productId]" class="mt-1.5 text-[10.5px] font-bold" :class="allowNegative ? 'text-warn' : 'text-bad'">
          {{ t(`المتاح ${short[l.productId].available} من ${short[l.productId].requested}`, `Available ${short[l.productId].available} of ${short[l.productId].requested}`) }} —
          {{ allowNegative ? t('يُباع ويُسجّل الباقي دَينًا على المخزون', 'it sells; the rest is owed by stock') : t('هذا الصندوق لا يبيع بأكثر من الرصيد', 'this register does not sell beyond stock') }}
        </div>
      </div>
    </div>

    <div class="border-t border-line p-3">
      <ErrorBanner :error="quoteError" :closable="false" />
      <div v-if="quoteError" class="mb-2 flex justify-end"><Btn tone="ghost" size="sm" :label="{ ar: 'إعادة التسعير', en: 'Re-price' }" @click="emit('requote')" /></div>
      <div class="grid grid-cols-2 gap-y-1 text-[11.5px]" :class="{ 'opacity-60': stale || quoting }">
        <span class="text-sec">{{ t('الإجمالي قبل الخصم', 'Subtotal') }}</span><span class="num ltr text-end">{{ quote ? money(num(quote.subtotal) + num(quote.discountTotal)) : '—' }}</span>
        <span class="text-sec">{{ t('الخصم', 'Discount') }}</span><span class="num ltr text-end">{{ quote ? money(quote.discountTotal) : '—' }}</span>
        <span class="text-sec">{{ t('الصافي قبل الضريبة', 'Net before VAT') }}</span><span class="num ltr text-end">{{ quote ? money(quote.subtotal) : '—' }}</span>
        <span class="text-sec">{{ t('ضريبة القيمة المضافة', 'VAT') }} <span v-if="quote" class="num">{{ pct(quote.vatPct) }}</span></span><span class="num ltr text-end">{{ quote ? money(quote.vatAmount) : '—' }}</span>
      </div>
      <div class="mt-2 flex items-center justify-between">
        <span class="text-[13px] font-extrabold text-ink">{{ t('الإجمالي', 'Total') }}</span>
        <span class="num ltr text-[22px] font-extrabold text-ink">{{ quote ? fmtCents(cents(quote.total)) : '—' }}<span v-if="quoting" class="spinner ms-2 inline-block align-middle" /></span>
      </div>
      <div v-if="blockedByStock" class="mt-1 text-[10.5px] font-bold text-bad">{{ t('المخزون لا يغطي بعض الأسطر — عدّل الكمية أو احذفها.', 'Stock does not cover some lines — change or remove them.') }}</div>

      <div v-if="!compact" class="mt-3 flex flex-wrap gap-2">
        <Btn tone="success" class="!h-12 min-w-[160px] flex-1 !text-[14px]" :disabled="!canPay || busy" @click="emit('pay')">{{ t('الدفع', 'Pay') }}</Btn>
      </div>
      <div class="mt-2 flex flex-wrap gap-2">
        <Btn tone="softAmber" size="sm" :disabled="!lines.length || busy" :label="{ ar: 'تعليق البيع', en: 'Hold' }" @click="emit('hold')" />
        <Btn tone="soft" size="sm" :disabled="busy" :label="{ ar: 'المعلّقة', en: 'Held sales' }" @click="emit('held')" />
        <div class="grow" />
        <Btn tone="ghost" size="sm" :disabled="!lines.length || busy" :label="{ ar: 'بيع جديد', en: 'New sale' }" @click="emit('clear')" />
      </div>
    </div>
  </div>
</template>
