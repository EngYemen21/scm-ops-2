<script setup>
// Customer picker: search-as-you-type against /customers (name / code), a popover with up to 8 hits.
//   <CustomerPicker :value="f.customer" :label="{ ar, en }" required :error="…" @pick="(customer, code) => …" />
// `pick` fires with `(null, text)` while typing and with `(customer, code)` when a hit is chosen. `name` is what is
// shown under the field for a value that was set from outside (a deep link, an edit).
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { TextInput } from '@/components';
import { t } from '@/i18n';
import { nameOf } from './portal';

const props = defineProps({
  value: { type: String, default: '' },
  name: { type: String, default: null },
  label: { type: [String, Object], default: null },
  required: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  error: { type: String, default: null },
  /** Offer customers that are stopped as well (a filter looks for any customer; a form wants one it can use). */
  inactive: { type: Boolean, default: false },
  placeholder: { type: [String, Object], default: () => ({ ar: 'اسم العميل أو رمزه', en: 'Customer name or code' }) },
});
const emit = defineEmits(['pick']);

const q = ref(props.value);
const open = ref(false);
const picked = ref(null);
watch(() => props.value, (v) => { if (v !== q.value) { q.value = v; picked.value = null; } });

const term = computed(() => String(q.value || '').trim());
const list = useList('/customers', () => ({ q: term.value, pageSize: 8, ...(props.inactive ? {} : { active: 'true' }) }), { enabled: () => open.value && term.value.length >= 2 });
const hits = computed(() => list.data.value?.items || []);
const shownName = computed(() => (picked.value ? nameOf(picked.value) : props.value && props.value === q.value ? props.name : null));

function onType(v) { q.value = v; open.value = true; picked.value = null; emit('pick', null, v); }
function choose(c) { picked.value = c; q.value = c.code; open.value = false; emit('pick', c, c.code); }
</script>

<template>
  <div class="relative">
    <TextInput :model-value="q" :label="label" :required="required" :small="small" :disabled="disabled" :error="error" :placeholder="placeholder" autocomplete="off" @update:model-value="onType" @enter="open = true" />
    <div v-if="shownName" class="mt-0.5 truncate text-[9.5px] font-bold text-sec" dir="auto">{{ shownName }}</div>
    <template v-if="open && term.length >= 2">
      <div class="fixed inset-0 z-[5]" @click="open = false" />
      <div class="popover !z-[6] start-0 top-full mt-1 max-h-60 w-[min(360px,90vw)] !overflow-y-auto !rounded-xl !shadow-[0_12px_30px_rgba(30,33,48,.15)]">
        <div v-if="list.isFetching.value && hits.length === 0" class="empty !p-3">{{ t('جارٍ البحث…', 'Searching…') }}</div>
        <div v-else-if="hits.length === 0" class="empty !p-3">{{ t('لا عملاء مطابقون', 'No matching customers') }}</div>
        <template v-else>
          <div v-for="c in hits" :key="c.code" class="flex cursor-pointer items-center gap-2.5 border-t border-[#F7F6FA] px-3 py-2 hover:bg-soft" @click="choose(c)">
            <span class="cell-id min-w-[84px]">{{ c.code }}</span>
            <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold" dir="auto">{{ nameOf(c) }}</span>
            <span v-if="c.city" class="text-[9px] text-faint">{{ c.city }}</span>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
