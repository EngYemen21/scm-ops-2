<script setup>
// Enter an order request for a branch of a customer — or correct the lines of one that still waits for its decision.
//   <RequestForm :open="open" :request="detailOrNull" @close="…" @done="(request) => …" />
// POST /portal/requests { customerId, branchId?, requestedBy?, note?, lines: [{ sku, qty, price? }] }
// PATCH /portal/requests/{id}/lines { lines } — the complete lines as they should be now; what differs from the lines it
// had goes to the log of order-line changes (who, which product, before and after).
// Quantities are whole units; a price may still be missing while the request waits (it is needed to approve it).
import { computed, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Drawer, ErrorBanner, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtMoney, num, t } from '@/i18n';
import CustomerPicker from './CustomerPicker.vue';
import ProductPick from './ProductPick.vue';
import { customerLine, nameOf } from './portal';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The request as GET /portal/requests/{id} gives it — then only its lines are edited; null = a new request. */
  request: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const edit = computed(() => !!props.request);
const act = useAction({ invalidate: ['portal'] });
let seq = 0;
const newLine = () => ({ key: ++seq, sku: '', name: '', qty: 1, price: null });

const f = ref({ customer: '', branchId: '', requestedBy: '', note: '' });
const lines = ref([newLine()]);
const errors = ref({});
watch(() => props.open, (o) => {
  if (!o) return;
  const r = props.request;
  f.value = { customer: r?.customer?.code || '', branchId: r?.branch?.id || '', requestedBy: r?.requestedBy || '', note: r?.note || '' };
  lines.value = r ? r.lines.filter((l) => l.product?.linked).map((l) => ({ key: ++seq, sku: l.product.sku, name: nameOf(l.product), qty: Math.round(num(l.qty)) || 1, price: num(l.price) || null })) : [newLine()];
  if (!lines.value.length) lines.value = [newLine()];
  errors.value = {};
  act.clearError();
}, { immediate: true });

const branches = useList('/portal/branches', () => ({ customer: f.value.customer, pageSize: 200, sort: 'sequence', order: 'asc' }), { enabled: () => props.open && !edit.value && !!f.value.customer });
const branchOptions = computed(() => (branches.data.value?.items || []).map((b) => ({ v: b.id, l: b.city ? `${b.name} · ${b.city}` : b.name })));
/** Lines of the request that are not OPS products (they came from Odoo unlinked): they cannot be sent again and go when the lines are saved. */
const foreign = computed(() => (props.request?.lines || []).filter((l) => !l.product?.linked));

const upd = (i, patch) => { lines.value = lines.value.map((l, j) => (j === i ? { ...l, ...patch } : l)); };
const remove = (i) => { lines.value = lines.value.length > 1 ? lines.value.filter((_, j) => j !== i) : [newLine()]; };
const total = computed(() => lines.value.reduce((s, l) => s + num(l.qty) * num(l.price), 0));
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);

function validate() {
  const e = {};
  if (!edit.value && !f.value.customer) e.customer = t('اختر العميل', 'Pick the customer');
  const seen = new Set();
  lines.value.forEach((l, i) => {
    const sku = l.sku.trim().toUpperCase();
    if (!sku) e[`lines.${i}.sku`] = t('اختر المنتج', 'Pick a product');
    else if (seen.has(sku)) e[`lines.${i}.sku`] = t('المنتج مكرر', 'Listed twice');
    seen.add(sku);
    if (!(num(l.qty) >= 1) || !Number.isInteger(num(l.qty))) e[`lines.${i}.qty`] = t('عدد صحيح ≥ 1', 'A whole number ≥ 1');
    if (num(l.price) < 0) e[`lines.${i}.price`] = t('سعر غير صالح', 'Not a valid price');
  });
  errors.value = e;
  return !Object.keys(e).length;
}
// what the server refuses lands on its line too
watch(() => act.error.value, (err) => { if (err) { const fe = err.fieldErrors(); if (Object.keys(fe).length) errors.value = { ...errors.value, ...fe }; } });

async function submit() {
  if (!validate()) return;
  const body = { lines: lines.value.map((l) => ({ sku: l.sku.trim(), qty: num(l.qty), ...(num(l.price) > 0 ? { price: num(l.price) } : {}) })) };
  const r = edit.value
    ? await act.run(() => api.patch(`/portal/requests/${props.request.id}/lines`, body), { success: t('حُفظت الأسطر وسُجّلت التغييرات', 'Lines saved and the changes logged') })
    : await act.run(() => api.postIdempotent('/portal/requests', { ...body, customerId: f.value.customer, ...(f.value.branchId ? { branchId: f.value.branchId } : {}), ...(f.value.requestedBy.trim() ? { requestedBy: f.value.requestedBy.trim() } : {}),
      ...(f.value.note.trim() ? { note: f.value.note.trim() } : {}) }), { success: (x) => t(`سُجّل طلب الشراء ${x.number}`, `Order request ${x.number} entered`) });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="680" :z-index="70" :title="edit ? { ar: `تصحيح أسطر الطلب ${request.number}`, en: `Correct the lines of ${request.number}` } : { ar: 'طلب شراء من البوابة', en: 'Portal order request' }"
          :sub="edit ? customerLine(request.customer) : { ar: 'يُسجَّل بانتظار القرار، ثم يُعتمد إلى عرض سعر', en: 'Entered as pending; approved, it becomes a quotation' }" @close="emit('close')">
    <div v-if="!edit" class="form-grid mb-3">
      <CustomerPicker :value="f.customer" :label="{ ar: 'العميل', en: 'Customer' }" required :error="errors.customer" @pick="(c, code) => { f = { ...f, customer: c ? c.code : '', branchId: '' }; if (c || !code) errors = { ...errors, customer: null }; }" />
      <SelectInput v-model="f.branchId" :label="{ ar: 'الفرع الطالب', en: 'Requesting branch' }" :options="branchOptions" :placeholder="{ ar: '— بلا فرع محدد —', en: '— no branch —' }" :disabled="!f.customer"
                   :hint="f.customer && !branchOptions.length ? { ar: 'لا فروع نشطة مسجَّلة لهذا العميل', en: 'This customer has no active branch on record' } : null" />
      <TextInput v-model="f.requestedBy" :label="{ ar: 'اسم الطالب لدى العميل', en: 'Who asked, at the customer' }" :hint="{ ar: 'يُترك فارغًا إن سجّلته أنت', en: 'Leave empty when you enter it yourself' }" />
      <TextArea v-model="f.note" :rows="2" :label="{ ar: 'ملاحظة', en: 'Note' }" />
    </div>

    <div v-if="foreign.length" class="hint amber !mt-0 mb-3">{{ t(`${foreign.length} سطر في هذا الطلب لصنف غير مربوط بأصناف نظام العمليات: لا يمكن إعادة إرساله، ويُحذف عند حفظ الأسطر (ويُكتب حذفه في سجل التغييرات).`, `${foreign.length} line(s) of this request are for a product that is not an OPS product: they cannot be sent again and are removed when the lines are saved (the removal is logged).`) }}</div>

    <div class="col !gap-1.5">
      <div class="le-head grid grid-cols-[minmax(170px,1.6fr)_84px_100px_92px_28px] gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint">
        <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('سعر الوحدة', 'Unit price') }}</div><div>{{ t('الصافي', 'Net') }}</div><div />
      </div>
      <div v-for="(l, i) in lines" :key="l.key" class="le-row grid grid-cols-[minmax(170px,1.6fr)_84px_100px_92px_28px] items-start gap-2 px-2.5 py-1">
        <div>
          <ProductPick small :value="l.sku" @pick="(p, sku) => upd(i, { sku, name: p ? nameOf(p) : '' })" />
          <div v-if="l.name" class="mt-0.5 truncate text-[8.5px] text-faint" dir="auto">{{ l.name }}</div>
          <div v-if="errors[`lines.${i}.sku`]" class="field-err">{{ errors[`lines.${i}.sku`] }}</div>
        </div>
        <NumberInput small :label="cap('الكمية', 'Qty')" :model-value="l.qty" :min="1" :error="errors[`lines.${i}.qty`]" @update:model-value="upd(i, { qty: $event })" />
        <NumberInput small :label="cap('سعر الوحدة', 'Unit price')" :model-value="l.price" :min="0" :error="errors[`lines.${i}.price`]" :placeholder="{ ar: 'لاحقًا', en: 'later' }" @update:model-value="upd(i, { price: $event })" />
        <div class="num pt-2 text-[11.5px]" :data-label="t('الصافي', 'Net')">{{ fmtMoney(num(l.qty) * num(l.price)) }}</div>
        <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('حذف السطر', 'Remove the line')" @click="remove(i)">✕</button>
      </div>
      <div class="row mt-1 flex-wrap justify-between !gap-2.5">
        <Btn tone="softPurple" size="sm" :label="{ ar: '+ سطر', en: '+ Line' }" @click="lines = [...lines, newLine()]" />
        <div class="text-[11.5px] font-extrabold">{{ t('الإجمالي قبل الضريبة', 'Total before VAT') }}: <span class="num">{{ fmtMoney(total) }}</span></div>
      </div>
    </div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="edit ? { ar: 'حفظ الأسطر', en: 'Save the lines' } : { ar: 'تسجيل الطلب', en: 'Enter the request' }" @click="submit" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ edit ? t('كل فرق عن الأسطر السابقة يُكتب في سجل تغييرات الأسطر باسمك: المنتج، وقبل وبعد.', 'Every difference from the earlier lines goes to the line-change log under your name: the product, before and after.') : t('* حقول إلزامية · يُسجل الإجراء في Audit Trail', '* required fields · the action is recorded in the audit trail') }}</div>
    </template>
  </Drawer>
</template>
