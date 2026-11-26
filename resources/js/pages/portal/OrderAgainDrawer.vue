<script setup>
// "Order again": a favourite list becomes a DRAFT quotation of the sales module for the list's customer.
//   <OrderAgainDrawer :open="open" :list-id="id" @close="…" @done="…" />
// GET  /portal/favorites/lists/{id}/order-again → { allowed, code, reasonAr, reasonEn, customer, validUntil, lines: [{ sku, nameAr, nameEn, uom, qty,
//        usualQty, price, priceSource: invoice | quotation | null, priceRef, priceAt }], skipped: [{ name, sku?, reason: unlinked | inactive }], more, policy }
// POST the same path { validUntil, notes?, lines: [{ sku, qty, price }] } → { quotation: { number, status, totals }, list } — needs sales.manage as well.
// A price is a starting point, never a decision: it is what the customer was last invoiced or quoted, the person
// making the quotation types over it, and the sales module applies its own rules to what is saved.
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, NumberInput, TextArea } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtMoney, num, t } from '@/i18n';
import { toast } from '@/stores/ui';
import { customerLine, day, nameOf } from './portal';

const props = defineProps({
  open: { type: Boolean, default: false },
  listId: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const router = useRouter();
const path = computed(() => (props.open && props.listId ? `/portal/favorites/lists/${encodeURIComponent(props.listId)}/order-again` : null));
const q = useGet(path, undefined, { staleTime: 0 });
const p = computed(() => (props.open ? q.data.value || null : null));
const act = useAction({ invalidate: ['portal', 'sales'] });

/** The lines being prepared: [{ key, sku, name, uom, qty, price, hint, on }] — `on` = it goes on the quotation. */
const lines = ref([]);
const validUntil = ref('');
const notes = ref('');
watch(p, (v) => {
  if (!v) return;
  lines.value = v.lines.map((x) => ({ key: x.itemId, sku: x.sku, name: nameOf(x), uom: x.uom, usual: x.usualQty, qty: x.qty, price: x.price, source: x.priceSource, ref: x.priceRef, at: x.priceAt, on: true }));
  validUntil.value = v.validUntil;
  notes.value = '';
  act.clearError();
}, { immediate: true });

const chosen = computed(() => lines.value.filter((x) => x.on));
const unpriced = computed(() => chosen.value.filter((x) => !(num(x.price) > 0)).length);
const sub = computed(() => chosen.value.reduce((s, x) => s + num(x.qty) * num(x.price), 0));
const vatPct = computed(() => num(p.value?.policy?.vatPct));
const total = computed(() => Math.round(sub.value * (1 + vatPct.value / 100) * 100) / 100);
const ok = computed(() => !!p.value?.allowed && chosen.value.length > 0 && unpriced.value === 0 && chosen.value.every((x) => num(x.qty) >= 1) && !!validUntil.value);
const upd = (i, patch) => { lines.value = lines.value.map((x, j) => (j === i ? { ...x, ...patch } : x)); };
const all = (on) => { lines.value = lines.value.map((x) => ({ ...x, on })); };
const SOURCE = { pricelist: { ar: 'قائمة أسعار العميل', en: 'the customer price list' }, invoice: { ar: 'آخر فاتورة', en: 'last invoice' }, quotation: { ar: 'آخر عرض سعر', en: 'last quotation' } };
const SKIPPED = { unlinked: { ar: 'غير مربوط بصنف في نظام العمليات', en: 'not linked to an OPS product' }, inactive: { ar: 'صنف موقوف', en: 'inactive product' } };
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);

async function submit() {
  const body = { validUntil: validUntil.value, ...(notes.value.trim() ? { notes: notes.value.trim() } : {}), lines: chosen.value.map((x) => ({ sku: x.sku, qty: Math.round(num(x.qty)), price: num(x.price) })) };
  const r = await act.run(() => api.postIdempotent(path.value, body), { success: false });
  if (!r) return;
  toast.say(t(`أُنشئ عرض السعر ${r.quotation.number} مسودةً في المبيعات`, `Draft quotation ${r.quotation.number} made in Sales`), 3600);
  emit('done', r);
  emit('close');
  router.push(`/sales?tab=qt&q=${encodeURIComponent(r.quotation.number)}`);
}
</script>

<template>
  <Drawer :open="open" :width="720" :z-index="70" :title="{ ar: 'اطلب مجددًا — عرض سعر من القائمة', en: 'Order again — a quotation from the list' }" :sub="p ? `${p.list.name} · ${customerLine(p.customer)}` : null" @close="emit('close')">
    <ErrorBanner :error="q.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="p">
      <!-- it cannot be done: say why, offer nothing else -->
      <div v-if="!p.allowed" class="hint amber !mt-0">{{ t(p.reasonAr, p.reasonEn) }}</div>
      <template v-else>
        <div class="form-grid mb-3">
          <DateInput v-model="validUntil" :label="{ ar: 'صلاحية العرض حتى', en: 'Valid until' }" required />
          <div class="field"><label class="field-l">{{ t('العميل', 'Customer') }}</label><div class="inp flex items-center !bg-soft text-[11px] font-bold" dir="auto">{{ customerLine(p.customer) }}</div></div>
        </div>

        <div class="row mb-1.5 !gap-2">
          <div class="field-l !mb-0">{{ t(`أسطر العرض (${chosen.length} من ${lines.length})`, `Quotation lines (${chosen.length} of ${lines.length})`) }}</div>
          <span class="grow" />
          <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[10px] font-extrabold text-violet" @click="all(true)">{{ t('اختيار الكل', 'All') }}</button>
          <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[10px] font-extrabold text-violet" @click="all(false)">{{ t('لا شيء', 'None') }}</button>
        </div>
        <div class="col !gap-1">
          <div class="le-head grid grid-cols-[26px_minmax(150px,1.6fr)_84px_100px_92px] gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint">
            <div /><div>{{ t('المنتج', 'Product') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('سعر الوحدة', 'Unit price') }}</div><div>{{ t('الصافي', 'Net') }}</div>
          </div>
          <div v-for="(x, i) in lines" :key="x.key" class="le-row grid grid-cols-[26px_minmax(150px,1.6fr)_84px_100px_92px] items-start gap-2 px-2.5 py-1" :class="{ 'opacity-45': !x.on }">
            <input type="checkbox" class="mt-2 h-4 w-4 accent-[#654e92]" :checked="x.on" :aria-label="t('ضمن العرض', 'On the quotation')" @change="upd(i, { on: $event.target.checked })">
            <div class="min-w-0">
              <div class="truncate text-[11px] font-extrabold" dir="auto">{{ x.name }}</div>
              <div class="cell-sub">{{ x.sku }}<template v-if="x.uom"> · {{ x.uom }}</template> · {{ t('المعتاد', 'usual') }} {{ x.usual }}</div>
              <div v-if="x.source" class="text-[8.5px] text-faint">{{ t('السعر من', 'price from') }} {{ t(SOURCE[x.source].ar, SOURCE[x.source].en) }} <span class="num ltr">{{ x.ref }}</span><template v-if="x.at"> · <span class="num ltr">{{ day(x.at) }}</span></template></div>
              <div v-else-if="x.on" class="text-[8.5px] font-extrabold text-warn">{{ t('لا سعر سابق لهذا العميل — أدخل السعر', 'No earlier price for this customer — type one') }}</div>
            </div>
            <NumberInput small :label="cap('الكمية', 'Qty')" :model-value="x.qty" :min="1" :disabled="!x.on" @update:model-value="upd(i, { qty: $event })" />
            <NumberInput small :label="cap('سعر الوحدة', 'Unit price')" :model-value="x.price" :min="0" :disabled="!x.on" @update:model-value="upd(i, { price: $event })" />
            <div class="num pt-2 text-[11.5px]" :data-label="t('الصافي', 'Net')">{{ x.on ? fmtMoney(num(x.qty) * num(x.price)) : '—' }}</div>
          </div>
        </div>
        <div v-if="p.more" class="hint amber">{{ t(`القائمة أطول من ${p.maxLines} منتج: يُسعَّر أول ${p.maxLines}، والباقي في عرض آخر.`, `The list is longer than ${p.maxLines} products: the first ${p.maxLines} are quoted, the rest go on another quotation.`) }}</div>

        <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-[220px] flex-1"><TextArea v-model="notes" :rows="2" :label="{ ar: 'ملاحظات على العرض (اختياري)', en: 'Notes on the quotation (optional)' }" /></div>
          <div class="tile min-w-[190px]">
            <div class="flex items-baseline justify-between gap-4 text-[10.5px]"><span class="text-sec">{{ t('الصافي', 'Net') }}</span><b class="num">{{ fmtMoney(sub) }}</b></div>
            <div class="mt-1 flex items-baseline justify-between gap-4 text-[10.5px]"><span class="text-sec">{{ t('ضريبة', 'VAT') }} {{ vatPct }}%</span><b class="num">{{ fmtMoney(total - sub) }}</b></div>
            <div class="mt-1 flex items-baseline justify-between gap-4 text-[12px] font-extrabold"><span>{{ t('الإجمالي', 'Total') }}</span><span class="num">{{ fmtMoney(total) }}</span></div>
          </div>
        </div>

        <template v-if="p.skipped.length">
          <div class="card-title mb-1 mt-4">{{ t('منتجات لا تدخل العرض', 'Products left out of the quotation') }}</div>
          <div class="overflow-hidden rounded-xl border border-line-2">
            <div v-for="(s, i) in p.skipped" :key="i" class="flex items-center gap-2 border-t border-line-2 px-3 py-1.5 text-[10.5px] first:border-t-0">
              <span class="min-w-0 flex-1 truncate font-bold" dir="auto">{{ s.name }}</span><span class="text-warn">{{ t(SKIPPED[s.reason].ar, SKIPPED[s.reason].en) }}</span>
            </div>
          </div>
        </template>
      </template>
    </template>

    <template #footer>
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!ok" :label="{ ar: 'إنشاء عرض السعر (مسودة)', en: 'Make the draft quotation' }" @click="submit" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div v-if="p && p.allowed && !ok && !act.pending.value" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">
        {{ unpriced ? t(`${unpriced} سطر بلا سعر — أدخل السعر أو ألغِ اختياره`, `${unpriced} line(s) without a price — type a price or untick them`) : t('اختر سطرًا واحدًا على الأقل بكمية صحيحة وتاريخ صلاحية', 'Pick at least one line with a whole quantity and a validity date') }}
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t('يُحفظ العرض مسودة في المبيعات وتُطبَّق عليه قواعدها (حد الخصم، الصلاحية). يُسجَّل في سجل القائمة وفي Audit Trail.', 'The quotation is saved as a draft in Sales, under its rules (discount limit, validity). It is recorded on the list’s history and in the audit trail.') }}</div>
    </template>
  </Drawer>
</template>
