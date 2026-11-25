<script setup>
// The customer of the sale: the register's walk-in customer by default, or a named one found through the customers
// list (GET /customers?q — the same list the sales pages use; read only) or typed by its code. The quote checks it.
//   <CustomerPick :model-value="cart.state.customer" :walk-in="register.customer" @update:model-value="cart.setCustomer" />
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, ErrorBanner, TextInput } from '@/components';
import { t } from '@/i18n';
import { codeName } from './pos';

const props = defineProps({
  modelValue: { type: Object, default: null },
  walkIn: { type: Object, default: null },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const q = ref('');
const term = computed(() => q.value.trim());
const list = useList('/customers', () => ({ q: term.value, pageSize: 8 }), { enabled: () => open.value && term.value.length >= 2 });
const items = computed(() => (term.value.length >= 2 ? list.data.value?.items || [] : []));
function pick(c) { emit('update:modelValue', { code: c.code, nameAr: c.nameAr, nameEn: c.nameEn }); open.value = false; q.value = ''; }
function useCode() { if (term.value) pick({ code: term.value, nameAr: '', nameEn: '' }); }
function toWalkIn() { emit('update:modelValue', null); open.value = false; q.value = ''; }
</script>

<template>
  <div>
    <div class="flex items-center gap-2">
      <div class="min-w-0 flex-1">
        <div class="text-[9.5px] font-bold text-muted">{{ t('العميل', 'Customer') }}</div>
        <div v-if="modelValue" class="truncate text-[12px] font-extrabold text-ink" dir="auto">{{ codeName(modelValue) }}</div>
        <div v-else class="truncate text-[12px] font-bold text-sec" dir="auto">{{ walkIn ? codeName(walkIn) : t('عميل نقدي', 'Walk-in customer') }} <span class="text-[10px] text-muted">· {{ t('افتراضي', 'default') }}</span></div>
      </div>
      <Btn v-if="modelValue" tone="ghost" size="sm" :disabled="disabled" :label="{ ar: 'نقدي', en: 'Walk-in' }" @click="toWalkIn" />
      <Btn tone="softPurple" size="sm" :disabled="disabled" :label="open ? { ar: 'إغلاق', en: 'Close' } : { ar: 'اختيار عميل', en: 'Choose' }" @click="open = !open" />
    </div>
    <div v-if="open" class="mt-2 rounded-xl border border-line bg-canvas p-2">
      <TextInput v-model="q" small autofocus field-class="!mb-1" :placeholder="{ ar: 'اسم العميل أو رمزه أو هاتفه…', en: 'Customer name, code or phone…' }" @enter="items.length === 1 ? pick(items[0]) : useCode()" />
      <ErrorBanner :error="list.error.value" :closable="false" />
      <div v-if="term.length < 2" class="px-1 text-[10.5px] text-muted">{{ t('اكتب حرفين على الأقل', 'Type at least two characters') }}</div>
      <div v-else-if="list.isFetching.value && !items.length" class="skel h-8" />
      <div v-else class="max-h-[220px] overflow-y-auto">
        <button v-for="c in items" :key="c.code" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start hover:bg-soft" :disabled="c.active === false" @click="pick(c)">
          <span class="num ltr text-[10.5px] font-bold text-violet">{{ c.code }}</span>
          <span class="min-w-0 flex-1 truncate text-[11.5px] font-bold text-ink" dir="auto">{{ c.nameAr || c.nameEn }}</span>
          <span v-if="c.active === false" class="text-[9.5px] text-bad">{{ t('موقوف', 'inactive') }}</span>
        </button>
        <div v-if="!items.length" class="px-1 py-1 text-[10.5px] text-muted">{{ t('لا عميل مطابق.', 'No matching customer.') }}</div>
      </div>
      <div class="mt-1.5 flex justify-end">
        <Btn tone="ghost" size="sm" :disabled="!term" :label="{ ar: 'استخدام الرمز كما كُتب', en: 'Use the code as typed' }" @click="useCode" />
      </div>
    </div>
  </div>
</template>
