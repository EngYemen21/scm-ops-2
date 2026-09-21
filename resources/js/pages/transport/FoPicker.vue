<script setup>
// Packed fulfillment-order picker of the new-trip form: searchable checkbox list of GET /fulfillment/orders?status=packed
// for the chosen warehouse.   <FoPicker :model-value="numbers" :warehouse="code" :error="msg" @update:model-value="set" />
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';

const props = defineProps({
  /** Selected FO numbers. */
  modelValue: { type: Array, default: () => [] },
  warehouse: { type: String, default: null },
  error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const q = ref('');
const fos = useList('/fulfillment/orders', () => ({ status: 'packed', warehouse: props.warehouse || undefined, q: q.value || undefined, pageSize: 50 }));
const items = computed(() => fos.data.value?.items || []);
/** Selected orders that are not on the visible page (filtered out by the search) are listed below the box. */
const hiddenSelected = computed(() => props.modelValue.filter((n) => !items.value.some((f) => f.number === n)));
const toggle = (n) => emit('update:modelValue', props.modelValue.includes(n) ? props.modelValue.filter((x) => x !== n) : [...props.modelValue, n]);
</script>

<template>
  <div>
    <div class="field-l">{{ t('الطلبات المجهزة (Packed)', 'Packed orders') }} <span class="text-bad">*</span> <span class="num text-violet">{{ modelValue.length }}</span></div>
    <TextInput scan v-model="q" small :placeholder="{ ar: 'بحث برقم الطلب / العميل', en: 'Search FO / customer' }" />
    <div class="mt-1.5 max-h-[220px] overflow-y-auto rounded-[10px] border border-line">
      <div v-if="fos.isLoading.value" class="skel m-2 h-10" />
      <div v-else-if="items.length === 0" class="empty !p-3.5">{{ t('لا طلبات مجهزة في هذا المستودع', 'No packed orders in this warehouse') }}</div>
      <label v-for="f in items" :key="f.number" class="row cursor-pointer border-b border-[#F7F6FA] px-2.5 py-[7px] text-[10.5px]">
        <input type="checkbox" :checked="modelValue.includes(f.number)" @change="toggle(f.number)">
        <span class="num min-w-[90px] font-bold text-violet">{{ f.number }}</span>
        <span class="flex-1 font-bold">{{ f.customer?.nameAr || '—' }}</span>
        <span class="num text-[9px] text-muted">{{ fmtNum(f.cartons) }} {{ t('كرتون', 'ctn') }} · {{ fmtNum(f.weightKg, 1) }} {{ t('كجم', 'kg') }}</span>
      </label>
    </div>
    <div v-if="hiddenSelected.length > 0" class="mt-1 text-[9px] text-muted">{{ t('محدد', 'Selected') }}: <span class="num">{{ modelValue.join(', ') }}</span></div>
    <div v-if="error" class="field-err mt-1">{{ error }}</div>
  </div>
</template>
