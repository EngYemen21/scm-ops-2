<script setup>
// Pick & Pack › packing station tab: picked orders awaiting packing (PackRow with inputs) and the packed orders that
// are ready to load.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, ErrorBanner, TextInput } from '@/components';
import { t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import PackRow from './PackRow.vue';

const wh = useWarehouse();
const page = ref(1);
const q = ref('');
const list = useList('/fulfillment/orders', () => ({ status: 'picked', q: q.value.trim() || undefined, ...wh.whParams, page: page.value, pageSize: 25 }));
const packed = useList('/fulfillment/orders', () => ({ status: 'packed', ...wh.whParams, pageSize: 10 }));
const items = computed(() => list.data.value?.items || []);
const packedItems = computed(() => packed.data.value?.items || []);
const pages = computed(() => list.data.value?.pages || 1);
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput scan v-model="q" small class="w-[260px]" :placeholder="{ ar: 'بحث برقم الأمر / العميل…', en: 'Search FO / customer…' }" @update:model-value="page = 1" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <div class="px-[18px] pb-[9px] pt-[13px] text-[13px] font-extrabold">{{ t('محطة التعبئة — أوامر مُجهزة بانتظار التعبئة', 'Packing station — picked orders awaiting packing') }} <span class="num muted text-[10px]">({{ list.data.value?.total ?? 0 }})</span></div>
      <div v-if="list.isLoading.value && items.length === 0" class="skel m-[18px] h-20" />
      <div v-if="!list.isLoading.value && items.length === 0" class="empty !p-5">{{ t('لا أوامر بانتظار التعبئة', 'Nothing to pack') }}</div>
      <PackRow v-for="fo in items" :key="fo.number" :fo="fo" @changed="list.refetch()" />
      <div v-if="pages > 1" class="row border-t border-line-2 px-[18px] py-2.5">
        <Btn tone="soft" size="sm" :disabled="page <= 1" :label="{ ar: 'السابق', en: 'Prev' }" @click="page--" />
        <span class="num muted text-[10px]">{{ page }} / {{ pages }}</span>
        <Btn tone="soft" size="sm" :disabled="page >= pages" :label="{ ar: 'التالي', en: 'Next' }" @click="page++" />
      </div>
    </div>
    <div class="card mt-3.5">
      <div class="px-[18px] pb-[9px] pt-[13px] text-[13px] font-extrabold">{{ t('معبأ — جاهز للتحميل (Outbound Staging)', 'Packed — ready to load (outbound staging)') }}</div>
      <div v-if="packedItems.length === 0" class="empty !p-4">{{ t('لا أوامر معبأة', 'No packed orders') }}</div>
      <PackRow v-for="fo in packedItems" :key="fo.number" :fo="fo" />
    </div>
    <div class="hint">{{ t('التعبئة تنقل الكميات من PACK إلى STG-OUT وتُنشئ طردًا برقم وملصق (Packing Slip + Shipping Label) — ثم تظهر في خطة التحميل بالرحلة.', 'Packing moves stock from PACK to STG-OUT and creates a numbered package with labels — the order then appears in the trip loading plan.') }}</div>
  </div>
</template>
