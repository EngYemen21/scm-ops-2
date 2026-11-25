<script setup>
// Picks a customer: search-as-you-type against GET /customers?q (code / name / zone / contact), up to 8 hits.
//   <CustomerPicker v-model="customerOrNull" :label="{ ar, en }" required :error="errors.customerId" />
// The value is `{ id, code, nameAr, nameEn }`; the forms send its id (the API also takes the code).
import { computed, onBeforeUnmount, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, ErrorBanner, Field, TextInput } from '@/components';
import { t } from '@/i18n';
import { nameOf } from './insights';

const props = defineProps({
  modelValue: { type: Object, default: null },
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  error: { type: String, default: null },
  disabled: { type: Boolean, default: false },
  hint: { type: [String, Object], default: null },
});
const emit = defineEmits(['update:modelValue']);

const typed = ref('');
const term = ref('');
const searching = ref(!props.modelValue);
let timer = null;
function onType(v) { typed.value = v; clearTimeout(timer); timer = setTimeout(() => { term.value = String(v || '').trim(); }, 300); }
onBeforeUnmount(() => clearTimeout(timer));
const list = useList(() => (searching.value && term.value.length >= 1 ? '/customers' : null), () => ({ q: term.value, pageSize: 8 }));
const hits = computed(() => list.data.value?.items || []);
function choose(c) { emit('update:modelValue', { id: c.id, code: c.code, nameAr: c.nameAr, nameEn: c.nameEn }); searching.value = false; typed.value = ''; term.value = ''; }
function clear() { emit('update:modelValue', null); searching.value = true; }
</script>

<template>
  <Field full :label="label" :required="required" :error="error" :hint="hint">
    <div v-if="modelValue && !searching" class="row rounded-[12px] border border-line-2 bg-soft px-3 py-2 !gap-2">
      <div class="min-w-0 flex-1 truncate text-[11.5px] font-extrabold text-ink" dir="auto">{{ nameOf(modelValue) }} <span class="num ltr inline-block text-[10px] text-muted">{{ modelValue.code }}</span></div>
      <Btn v-if="!disabled" size="sm" tone="soft" :label="{ ar: 'تغيير', en: 'Change' }" @click="searching = true" />
      <Btn v-if="!disabled && !required" size="sm" tone="ghost" :label="{ ar: 'إزالة', en: 'Remove' }" @click="clear" />
    </div>
    <template v-else>
      <div class="row !gap-1.5">
        <TextInput scan small type="search" field-class="flex-1" :model-value="typed" :disabled="disabled" :placeholder="{ ar: 'ابحث برمز العميل أو اسمه…', en: 'Search customer code or name…' }" @update:model-value="onType" @enter="term = String(typed || '').trim()" />
        <Btn v-if="modelValue" size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="searching = false" />
      </div>
      <template v-if="term">
        <ErrorBanner :error="list.error.value" :closable="false" />
        <div class="mt-1.5 max-h-[240px] overflow-auto rounded-[12px] border border-line-2">
          <div v-if="list.isFetching.value && !hits.length" class="empty !p-3">{{ t('جارٍ البحث…', 'Searching…') }}</div>
          <div v-else-if="!hits.length" class="empty !p-3">{{ t('لا عميل يطابق البحث.', 'No customer matches the search.') }}</div>
          <button v-for="c in hits" :key="c.id" type="button" class="block w-full border-b border-line-2 px-3 py-2 text-start last:border-b-0 hover:bg-soft" @click="choose(c)">
            <div class="row !gap-2">
              <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink" dir="auto">{{ nameOf(c) }}</span>
              <span class="num ltr text-[10px] text-muted">{{ c.code }}</span>
            </div>
            <div class="text-[9.5px] text-muted" dir="auto">{{ [c.zone, c.city].filter(Boolean).join(' · ') || '—' }}</div>
          </button>
        </div>
      </template>
    </template>
  </Field>
</template>
