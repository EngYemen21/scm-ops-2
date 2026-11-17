<script setup>
// Lines picker of a return TAKEN FROM STOCK (return to a supplier / damaged goods): the balance rows it may come from
// (GET /api/returns/stock-rows) → checked rows become lines { key, sku, name, fromBin, batchNo, available, qty, unitPrice }.
// With a supplier the rows are what that supplier delivered (optionally one PO / GRN); "كل رصيد المستودع" lists every
// eligible row (stock from before receipts were kept). Emits `receipts` = the supplier's POs / GRNs for the form selects.
import { computed, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner, NumberInput, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import { pname } from './shared';

const props = defineProps({
  /** Warehouse code (the picker is disabled until one is chosen). */
  warehouse: { type: String, default: null },
  /** Supplier code — required for a purchase return, absent for damaged goods. */
  supplier: { type: String, default: null },
  /** A supplier must be chosen before rows are offered. */
  needsSupplier: { type: Boolean, default: false },
  po: { type: String, default: null },
  grn: { type: String, default: null },
  /** Show the unit price column (purchase return: the price the supplier is claimed for). */
  priced: { type: Boolean, default: false },
  modelValue: { type: Array, default: () => [] },
  error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue', 'receipts']);

const q = ref('');
const everything = ref(false);
const ready = computed(() => !!props.warehouse && (!props.needsSupplier || !!props.supplier));
const src = useGet(() => (ready.value ? '/returns/stock-rows' : null), () => ({
  warehouse: props.warehouse, supplier: props.supplier || undefined, po: props.po || undefined, grn: props.grn || undefined,
  q: q.value.trim() || undefined, scope: everything.value ? 'all' : undefined,
}));
const items = computed(() => src.data.value?.rows || []);
watch(() => src.data.value?.receipts, (v) => { if (v) emit('receipts', v); }, { immediate: true });
// another warehouse / supplier: rows picked from the previous one no longer apply
watch(() => [props.warehouse, props.supplier], () => { if (props.modelValue.length) emit('update:modelValue', []); });

const ZONE = { quarantine: { ar: 'حجر', en: 'quarantine' }, damaged: { ar: 'تالف', en: 'damaged' } };
const isSel = (b) => props.modelValue.some((l) => l.key === b.key);
function toggle(b) {
  if (isSel(b)) emit('update:modelValue', props.modelValue.filter((l) => l.key !== b.key));
  else if (b.available > 0) emit('update:modelValue', [...props.modelValue, { key: b.key, sku: b.sku, name: pname(b), fromBin: b.bin, batchNo: b.batchNo || undefined, available: b.available, qty: b.available, unitPrice: b.price ?? null, priceSource: b.priceSource }]);
}
const set = (k, patch) => emit('update:modelValue', props.modelValue.map((l) => (l.key === k ? { ...l, ...patch } : l)));
const remove = (k) => emit('update:modelValue', props.modelValue.filter((x) => x.key !== k));
const GRID = computed(() => (props.priced ? 'grid grid-cols-[minmax(120px,1.4fr)_64px_72px_90px_32px] gap-1.5' : 'grid grid-cols-[minmax(120px,1.4fr)_64px_72px_32px] gap-1.5'));
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
</script>

<template>
  <div>
    <div class="field-l">{{ t('الأصناف من رصيد المستودع', 'Lines from warehouse stock') }} <span class="text-bad">*</span> <span class="num text-violet">{{ modelValue.length }}</span></div>
    <div v-if="!warehouse" class="hint amber !mt-0">{{ t('اختر المستودع أولًا', 'Pick the warehouse first') }}</div>
    <div v-else-if="needsSupplier && !supplier" class="hint amber !mt-0">{{ t('اختر المورد أولًا — تُعرض أرصدة ما ورّده', 'Pick the supplier first — the stock they delivered is listed') }}</div>
    <template v-else>
      <div class="row wrap">
        <TextInput v-model="q" scan small field-class="grow" :placeholder="{ ar: 'بحث SKU / اسم / موقع / دفعة', en: 'Search SKU / name / bin / batch' }" />
        <label v-if="supplier" class="row cursor-pointer !gap-1 text-[10px] font-bold text-sec"><input v-model="everything" type="checkbox">{{ t('كل رصيد المستودع', 'All warehouse stock') }}</label>
      </div>
      <div class="mt-1.5 max-h-[210px] overflow-y-auto rounded-[10px] border border-line">
        <div v-if="src.isLoading.value" class="skel m-2 h-10" />
        <ErrorBanner :error="src.error.value" :closable="false" class="m-2" />
        <div v-if="!src.isLoading.value && !src.error.value && items.length === 0" class="empty !p-3.5">
          {{ supplier && !everything ? t('لا رصيد من استلامات هذا المورد في المستودع — فعّل «كل رصيد المستودع» إن كانت البضاعة أقدم من الاستلامات المسجلة.', 'No stock from the receipts of this supplier here — tick All warehouse stock if the goods predate the recorded receipts.') : t('لا رصيد مطابق في هذا المستودع', 'No matching stock in this warehouse') }}
        </div>
        <label v-for="b in items" :key="b.key" class="row border-b border-[#F7F6FA] px-2.5 py-1.5 text-[10.5px]" :class="b.available > 0 ? 'cursor-pointer' : 'opacity-60'">
          <input type="checkbox" :checked="isSel(b)" :disabled="b.available <= 0 && !isSel(b)" @change="toggle(b)">
          <span class="num min-w-20 text-violet">{{ b.sku }}</span>
          <span class="ellipsis flex-1 font-bold">{{ pname(b) }}</span>
          <span class="num text-[9px] text-muted">{{ b.bin }}{{ b.batchNo ? ` · ${b.batchNo}` : '' }}{{ b.expiryDate ? ` · ${fmtDateOnly(b.expiryDate)}` : '' }}</span>
          <span v-if="ZONE[b.zoneType]" class="text-[9px] font-bold text-warn">{{ t(ZONE[b.zoneType].ar, ZONE[b.zoneType].en) }}</span>
          <span v-if="b.blocked" class="text-[9px] font-bold text-bad">{{ t('مجمّد للجرد', 'count freeze') }}</span>
          <span class="num text-[10px]" :class="b.available > 0 ? 'text-ok' : 'text-bad'" :title="t(`الرصيد ${b.onHand} · محجوز ${b.reserved}`, `on hand ${b.onHand} · reserved ${b.reserved}`)">{{ fmtNum(b.available) }}</span>
        </label>
      </div>
      <div v-if="src.data.value?.truncated" class="mt-1 text-[9px] text-faint">{{ t('تُعرض أول 80 صفًا — ضيّق البحث', 'Showing the first 80 rows — narrow the search') }}</div>
    </template>

    <div v-if="modelValue.length > 0" class="mt-2 overflow-hidden rounded-[10px] border border-line-2">
      <div :class="GRID" class="le-head bg-soft px-2.5 py-1.5 text-[9px] font-extrabold text-faint">
        <div>{{ t('الصنف · من موقع', 'Item · from bin') }}</div><div>{{ t('المتاح', 'Avail.') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div v-if="priced">{{ t('سعر الوحدة', 'Unit price') }}</div><div />
      </div>
      <div v-for="l in modelValue" :key="l.key" :class="GRID" class="le-row items-center border-t border-[#F7F6FA] px-2.5 py-[5px]">
        <div class="min-w-0"><div class="ellipsis text-[10.5px] font-extrabold">{{ l.name }}</div><div class="cell-sub num">{{ l.sku }} · {{ l.fromBin }}{{ l.batchNo ? ` · ${l.batchNo}` : '' }}</div></div>
        <div class="num text-[10px] text-ok" :data-label="t('المتاح', 'Available')">{{ fmtNum(l.available) }}</div>
        <NumberInput :label="cap('الكمية', 'Qty')" :model-value="l.qty" small :min="1" :max="l.available" :step="1" :error="Number(l.qty) > l.available ? t('يتجاوز المتاح', 'Exceeds available') : null" @update:model-value="set(l.key, { qty: $event })" />
        <NumberInput v-if="priced" :label="cap('سعر الوحدة', 'Unit price')" :model-value="l.unitPrice" small :min="0" :title="l.priceSource === 'po' ? t('سعر آخر أمر شراء من المورد', 'Last PO price from the supplier') : t('متوسط التكلفة — لا أمر شراء من هذا المورد للصنف', 'Average cost — no PO from this supplier for the item')" @update:model-value="set(l.key, { unitPrice: $event })" />
        <button type="button" class="x-btn" aria-label="remove" @click="remove(l.key)">✕</button>
      </div>
      <div v-if="priced" class="bg-soft px-2.5 py-1.5 text-[9.5px] text-muted">{{ t('السعر هو ما يُطالَب به المورد في إشعار المدين: سعر أمر الشراء افتراضيًا، وعدّله إن اتُّفق على غيره. الإجمالي قبل الضريبة:', 'The price is what the supplier is claimed for on the debit note: the PO price by default — change it if agreed otherwise. Net total:') }} <span class="num font-extrabold text-ink">{{ fmtMoney(modelValue.reduce((a, l) => a + (Number(l.qty) || 0) * (Number(l.unitPrice) || 0), 0)) }}</span></div>
    </div>
    <div v-if="error" class="field-err mt-1">{{ error }}</div>
  </div>
</template>
