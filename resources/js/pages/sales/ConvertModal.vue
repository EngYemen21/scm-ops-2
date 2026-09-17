<script setup>
// Convert an approved quotation into a sales order (warehouse, delivery date / window, priority).
//   <ConvertModal :qt="quotationOrNull" @close="…" @done="(so) => …" />
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, ErrorBanner, Modal, SelectInput } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { PRIORITIES, WINDOWS, custName, isoDatePlus, useLookups, warehouseOptions } from './shared';

const props = defineProps({
  /** Quotation to convert; null = closed. */
  qt: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lookups = useLookups();
const act = useAction();
const v = reactive({ warehouseCode: '', dueDate: '', window: '09:00–13:00', priority: 'normal' });

watch(() => [props.qt, lookups.data.value], () => {
  if (!props.qt) return;
  Object.assign(v, { warehouseCode: lookups.data.value?.warehouses[0]?.code || '', dueDate: isoDatePlus(1), window: '09:00–13:00', priority: 'normal' });
  act.clearError();
}, { immediate: true });

const warehouses = computed(() => warehouseOptions(lookups.data.value));

async function go() {
  const qt = props.qt;
  const r = await act.run(() => api.postIdempotent(`/sales/quotations/${qt.number}/convert`, { ...v }), {
    success: (so) => t(`حُوّل ${qt.number} إلى أمر البيع ${so.number} — حُجز المخزون FEFO`, `${qt.number} converted to ${so.number}`),
    invalidate: ['sales', 'inventory', 'customers'],
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Modal v-if="qt" open :width="520" :title="{ ar: `تحويل ${qt.number} لأمر بيع`, en: `Convert ${qt.number} to a sales order` }"
         :sub="{ ar: 'يتطلب عرضًا معتمدًا — فحص المتاح والحد الائتماني ثم حجز FEFO', en: 'Requires an approved quotation — stock/credit check then FEFO reservation' }" @close="emit('close')">
    <div class="form-grid">
      <SelectInput v-model="v.warehouseCode" :label="{ ar: 'المستودع', en: 'Warehouse' }" required :options="warehouses" :placeholder="{ ar: '— اختر —', en: '— select —' }" />
      <DateInput v-model="v.dueDate" :label="{ ar: 'تاريخ التسليم', en: 'Delivery date' }" required />
      <SelectInput v-model="v.window" :label="{ ar: 'نافذة التسليم', en: 'Window' }" :options="WINDOWS" />
      <SelectInput v-model="v.priority" :label="{ ar: 'الأولوية', en: 'Priority' }" :options="PRIORITIES" />
    </div>
    <div class="hint teal !mt-3">{{ t(`${qt.lines.length} أسطر · الإجمالي ${fmtMoney(qt.totals.total)} ر.س · العميل ${custName(qt.customer)}`, `${qt.lines.length} lines · total ${fmtMoney(qt.totals.total)} SAR · customer ${custName(qt.customer)}`) }}</div>

    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="purple" class="!h-[42px] flex-1" :loading="act.pending.value" :label="{ ar: 'تحويل لأمر بيع', en: 'Convert to SO' }" @click="go" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
