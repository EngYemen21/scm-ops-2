<script setup>
// Receiving › putaway tasks: open / done pills, optional GRN filter chip (?grn=GRN-…), task list with scan + confirm.
import { ref } from 'vue';
import { Chip, Tabs } from '@/components';
import { t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import PutawayList from './PutawayList.vue';

defineProps({ grn: { type: String, default: null } });
const emit = defineEmits(['clear-grn']);

const wh = useWarehouse();
const status = ref('open');
const STATUS_TABS = [{ k: 'open', label: { ar: 'مفتوحة', en: 'Open' } }, { k: 'done', label: { ar: 'منفَّذة', en: 'Done' } }];
</script>

<template>
  <div class="row wrap mb-0.5">
    <Tabs v-model="status" :tabs="STATUS_TABS" variant="pill" class="!mb-0" />
    <Chip v-if="grn" fg="#1d7a3e" bg="#e6f9ec" :label="`GRN ${grn}`" class="cursor-pointer" :title="t('إزالة الفلتر', 'Clear filter')" @click="emit('clear-grn')" />
  </div>
  <PutawayList :params="{ status, grn: grn || undefined, ...wh.whParams }" />
  <div class="hint">{{ t('تأكيد التخزين يتطلب Scan للموقع (Bin) والمنتج؛ الموقع الخاطئ أو منتج مختلف أو موقع محجور يُرفض فورًا ويُفتح استثناء «موقع خاطئ». التخزين ينقل الكمية من Inbound Staging إلى الموقع ويجعلها متاحة للبيع.', 'Confirming putaway requires scanning the bin and the product; a wrong bin, a different product, or a quarantined bin is rejected immediately and raises a wrong-location exception. Putaway moves the quantity from inbound staging to the bin and makes it available.') }}</div>
</template>
