<script setup>
// Phone warehouse filter: a compact chip (RYD ˅) that opens a sheet — the desktop topbar's row of chips in one tap.
import { ref } from 'vue';
import { lang, t } from '../../i18n';
import { useWarehouse } from '../../stores/warehouse';
import Icon from '../Icon.vue';
import BottomSheet from './BottomSheet.vue';

const store = useWarehouse();
const open = ref(false);
function pick(code) { store.setWh(code); open.value = false; }
</script>

<template>
  <template v-if="store.warehouses.length > 0">
    <button type="button" class="m-wh-chip" :aria-label="t('المستودع', 'Warehouse')" @click="open = true">
      <Icon name="chevronDown" :size="12" color="#7d7990" />
      <span class="num">{{ store.wh === 'all' ? t('الكل', 'All') : store.wh }}</span>
    </button>
    <BottomSheet :open="open" :title="{ ar: 'المستودع', en: 'Warehouse' }" @close="open = false">
      <div class="m-list">
        <button type="button" class="m-list-row" @click="pick('all')">
          <span class="flex-1 text-start text-[13px] font-extrabold">{{ t('كل المستودعات', 'All warehouses') }}</span>
          <Icon v-if="store.wh === 'all'" name="check" :size="16" color="#0d7f93" />
        </button>
        <button v-for="w in store.warehouses" :key="w.code" type="button" class="m-list-row" @click="pick(w.code)">
          <span class="flex-1 text-start"><span class="text-[13px] font-extrabold">{{ lang === 'ar' ? w.nameAr : w.nameEn }}</span> <span class="num ms-1 text-[11px] text-violet">{{ w.code }}</span></span>
          <Icon v-if="store.wh === w.code" name="check" :size="16" color="#0d7f93" />
        </button>
      </div>
    </BottomSheet>
  </template>
</template>
