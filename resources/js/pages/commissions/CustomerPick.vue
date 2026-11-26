<script setup>
// Picks one customer by searching the customers master (GET /customers?q=) — for a rule "by customer" and for saying
// whose customer it is. Emits the customer `{ id, code, nameAr, nameEn }` and clears the box; the parent shows what was picked.
//   <CustomerPick :label="{ ar, en }" :exclude="[ids]" @pick="(customer) => …" />
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { TextInput } from '@/components';
import { t } from '@/i18n';
import { recordName } from './commissions';

const props = defineProps({
  label: { type: [String, Object], default: null },
  exclude: { type: Array, default: () => [] },
  required: { type: Boolean, default: false },
});
const emit = defineEmits(['pick']);

const q = ref('');
const term = computed(() => q.value.trim());
const found = useList('/customers', () => ({ q: term.value, pageSize: 8 }), { enabled: () => term.value.length >= 2 });
const rows = computed(() => (term.value.length >= 2 ? (found.data.value?.items || []).filter((c) => !props.exclude.includes(c.id)) : []));
function pick(c) { emit('pick', { id: c.id, code: c.code, nameAr: c.nameAr, nameEn: c.nameEn }); q.value = ''; }
</script>

<template>
  <div class="field full">
    <TextInput v-model="q" type="search" :label="label" :required="required" :placeholder="{ ar: 'ابحث باسم العميل أو رمزه…', en: 'Search a customer by name or code…' }" />
    <div v-if="term.length >= 2" class="overflow-hidden rounded-xl border border-line-2">
      <div v-if="found.isLoading.value" class="p-2"><div class="skel h-4 w-2/3" /></div>
      <div v-else-if="found.error.value" class="p-2.5 text-[10.5px] font-bold text-bad">{{ t('تعذّر البحث في العملاء', 'The customer search failed') }}</div>
      <div v-else-if="rows.length === 0" class="p-2.5 text-[10.5px] text-muted">{{ t('لا عميل بهذا الاسم أو الرمز', 'No customer with that name or code') }}</div>
      <button v-for="c in rows" :key="c.id" type="button" class="flex w-full cursor-pointer items-center gap-2 border-0 border-t border-line-2 bg-white px-3 py-2 text-start text-[11px] first:border-t-0 hover:bg-soft" @click="pick(c)">
        <span class="min-w-0 flex-1 truncate font-bold" dir="auto">{{ recordName(c) }}</span><span class="num ltr text-[9.5px] text-muted">{{ c.code }}</span>
      </button>
    </div>
  </div>
</template>
