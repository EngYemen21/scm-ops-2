<script setup>
// Picks the customer a credit request is for: GET /credit/customers?q= (at most 20) — each shows its code, city,
// current credit limit and terms, its balance, and the open credit request it already has (a second one is refused
// by the server, so it is said here first).
//   <CustomerPicker v-model="customerObjectOrNull" :error="errors.customerId" />
import { computed, onBeforeUnmount, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, Field, TextInput } from '@/components';
import { t } from '@/i18n';
import { money, nameOf } from './credit';

const props = defineProps({
  /** `{ id, code, nameAr, nameEn, … }` or null */
  modelValue: { type: Object, default: null },
  error: { type: String, default: null },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const typed = ref('');
const term = ref('');
const searching = ref(false);
let timer = null;
function onType(v) { typed.value = v; clearTimeout(timer); timer = setTimeout(() => { term.value = String(v || '').trim(); }, 300); }
onBeforeUnmount(() => clearTimeout(timer));
const q = useGet(() => (searching.value ? '/credit/customers' : null), () => ({ q: term.value || undefined }));
const items = computed(() => q.data.value?.items || []);
function pickOne(c) { emit('update:modelValue', c); searching.value = false; typed.value = ''; term.value = ''; }
function change() { searching.value = true; }
</script>

<template>
  <Field full :label="{ ar: 'العميل', en: 'Customer' }" required :error="error">
    <div v-if="modelValue && !searching" class="row wrap rounded-[12px] border border-line-2 bg-soft px-3 py-2 !gap-2">
      <div class="min-w-0 flex-1">
        <div class="text-[11.5px] font-extrabold text-ink" dir="auto">{{ nameOf(modelValue) }} <span class="num ltr inline-block text-[10px] text-muted">{{ modelValue.code }}</span></div>
        <div class="text-[9.5px] text-muted">
          <template v-if="modelValue.creditLimit != null">{{ t('الحد الحالي', 'Current limit') }} <span class="num ltr inline-block">{{ money(modelValue.creditLimit) }}</span> · </template>
          <template v-if="modelValue.terms">{{ t('الشروط', 'Terms') }} <span class="num ltr inline-block">{{ modelValue.terms }}</span> · </template>
          <span dir="auto">{{ modelValue.city || '' }}</span>
        </div>
        <div v-if="modelValue.openRequest" class="text-[9.5px] font-bold text-warn">{{ t('لديه طلب ائتمان مفتوح', 'Has an open credit request') }} <span class="num ltr inline-block">{{ modelValue.openRequest }}</span></div>
      </div>
      <Btn v-if="!disabled" size="sm" tone="soft" :label="{ ar: 'تغيير', en: 'Change' }" @click="change" />
    </div>
    <template v-else>
      <div class="row !gap-1.5">
        <TextInput small type="search" field-class="flex-1" :model-value="typed" :placeholder="{ ar: 'ابحث باسم العميل أو رمزه…', en: 'Search customer name or code…' }" :disabled="disabled" @update:model-value="onType" @focus="searching = true" />
        <Btn v-if="!searching" size="sm" tone="softPurple" :disabled="disabled" :label="{ ar: 'بحث', en: 'Search' }" @click="change" />
        <Btn v-else-if="modelValue" size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="searching = false" />
      </div>
      <template v-if="searching">
        <ErrorBanner :error="q.error.value" :closable="false" />
        <div v-if="q.isLoading.value" class="skel mt-1.5 h-[90px]" />
        <div v-else class="mt-1.5 max-h-[260px] overflow-auto rounded-[12px] border border-line-2">
          <div v-if="!items.length" class="empty !p-3">{{ term ? t('لا عميل يطابق البحث.', 'No customer matches the search.') : t('اكتب للبحث.', 'Type to search.') }}</div>
          <button v-for="c in items" :key="c.id" type="button" class="block w-full border-b border-line-2 px-3 py-2 text-start last:border-b-0 hover:bg-soft" @click="pickOne(c)">
            <div class="row !gap-2">
              <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink" dir="auto">{{ nameOf(c) }}</span>
              <span class="num ltr text-[10px] text-muted">{{ c.code }}</span>
            </div>
            <div class="row wrap !gap-x-2 text-[9.5px] text-muted">
              <span dir="auto">{{ c.city || '—' }}</span>
              <span>{{ t('الحد', 'Limit') }} <span class="num ltr inline-block">{{ money(c.creditLimit) }}</span></span>
              <span v-if="c.terms">{{ t('الشروط', 'Terms') }} <span class="num ltr inline-block">{{ c.terms }}</span></span>
              <span>{{ t('الرصيد', 'Balance') }} <span class="num ltr inline-block">{{ money(c.balance) }}</span></span>
              <span v-if="c.sourceSystem === 'odoo'" class="font-bold text-violet">{{ t('من أودو', 'from Odoo') }}</span>
              <span v-if="c.openRequest" class="font-bold text-warn">{{ t('طلب مفتوح', 'open request') }} <span class="num ltr inline-block">{{ c.openRequest }}</span></span>
            </div>
          </button>
        </div>
        <div v-if="items.length >= 20" class="mt-1 text-[9px] text-faint">{{ t('تظهر أول 20 نتيجة — ضيّق البحث.', 'The first 20 results are shown — narrow the search.') }}</div>
      </template>
    </template>
  </Field>
</template>
