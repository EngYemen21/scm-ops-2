<script setup>
// Direct sales order drawer: customer (with credit-limit hint), warehouse, delivery date / window / priority and the
// line editor. The server checks availability (no overselling) and the credit limit, then reserves FEFO in one
// transaction; its rejection message is shown as is.
//   <SoForm :open="open" :initial-customer="code" :default-warehouse="wh" @close="…" @done="(so) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, SelectInput } from '@/components';
import { fmtMoney, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import LinesEditor from './LinesEditor.vue';
import { PRIORITIES, WINDOWS, customerOptions, isoDatePlus, linesBody, newLine, useLookups, validateLines, warehouseOptions } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initialCustomer: { type: String, default: null },
  defaultWarehouse: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const lookups = useLookups();
const act = useAction();

const blank = () => ({ customerCode: props.initialCustomer || '', warehouseCode: props.defaultWarehouse || '', dueDate: isoDatePlus(1), window: '09:00–13:00', priority: 'normal' });
const v = reactive(blank());
const lines = ref([newLine()]);
const errors = ref({});

watch(() => [props.open, props.initialCustomer, props.defaultWarehouse], () => {
  if (!props.open) return;
  Object.assign(v, blank()); lines.value = [newLine()]; errors.value = {}; act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const customers = computed(() => customerOptions(lookups.data.value));
const warehouses = computed(() => warehouseOptions(lookups.data.value));
const cust = computed(() => lookups.data.value?.customers.find((c) => c.code === v.customerCode));
const creditHint = computed(() => (cust.value && num(cust.value.creditLimit) > 0
  ? { ar: `الحد الائتماني ${fmtMoney(cust.value.creditLimit)} · الرصيد ${fmtMoney(cust.value.balance)}`, en: `Credit limit ${fmtMoney(cust.value.creditLimit)} · balance ${fmtMoney(cust.value.balance)}` }
  : null));

async function submit() {
  const errs = { ...validateLines(lines.value, false) };
  if (!v.customerCode) errs.customerCode = t('هذا الحقل إلزامي', 'Required');
  if (!v.warehouseCode) errs.warehouseCode = t('هذا الحقل إلزامي', 'Required');
  if (!v.dueDate) errs.dueDate = t('هذا الحقل إلزامي', 'Required');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const r = await act.run(() => api.postIdempotent('/sales/orders', { ...v, lines: linesBody(lines.value, false) }), {
    success: (so) => t(`أُنشئ أمر البيع ${so.number} — حُجز المخزون FEFO`, `Sales order ${so.number} created and reserved`),
    invalidate: ['sales', 'inventory', 'customers'],
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="680" :z-index="70" :title="{ ar: 'أمر بيع مباشر', en: 'Direct sales order' }"
          :sub="{ ar: 'فحص المتاح (لا Overselling) والحد الائتماني — ثم حجز وتخصيص FEFO في معاملة واحدة', en: 'Stock and credit-limit check, then FEFO reservation in one transaction' }" @close="emit('close')">
    <div class="form-grid">
      <SelectInput v-model="v.customerCode" :label="{ ar: 'العميل', en: 'Customer' }" required :options="customers" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errors.customerCode" :hint="creditHint" />
      <SelectInput v-model="v.warehouseCode" :label="{ ar: 'المستودع', en: 'Warehouse' }" required :options="warehouses" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errors.warehouseCode" />
      <DateInput v-model="v.dueDate" :label="{ ar: 'تاريخ التسليم', en: 'Delivery date' }" required :error="errors.dueDate" />
      <SelectInput v-model="v.window" :label="{ ar: 'نافذة التسليم', en: 'Delivery window' }" :options="WINDOWS" />
      <SelectInput v-model="v.priority" :label="{ ar: 'الأولوية', en: 'Priority' }" :options="PRIORITIES" />
    </div>
    <div class="mt-3.5">
      <div class="field-l mb-1.5">{{ t('الأسطر', 'Lines') }} *</div>
      <LinesEditor v-model:lines="lines" :errors="errors" />
    </div>
    <div class="hint amber">{{ t('يُرفض الأمر إذا كان المتاح أقل من المطلوب أو تجاوز الحد الائتماني — الرسالة تأتي من الخادم.', 'The order is rejected when available stock is short or the credit limit is exceeded — the server message is shown.') }}</div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'تأكيد وحجز المخزون', en: 'Confirm & reserve' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] text-faint">{{ t(`* حقول إلزامية · يُسجل الإجراء في Audit Trail باسم ${auth.user?.nameAr || ''}`, `* required · recorded in the Audit Trail as ${auth.user?.nameEn || ''}`) }}</div>
    </template>
  </Drawer>
</template>
