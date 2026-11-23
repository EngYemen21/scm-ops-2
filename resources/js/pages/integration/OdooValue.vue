<script setup>
// One value of an Odoo record, the way a person reads it (the rules are in `valueKind` of ./odoo.js):
//   false / empty → «—» · boolean → نعم / لا · many2one `[id, "label"]` → the label and #id · list of ids → the count
//   and the first few · datetime (Odoo's UTC `Y-m-d H:i:s`) → local date and time · date → the same day · long text
//   and structured data collapsed behind «عرض الكل». Html is shown as its text, never rendered as markup.
//   <OdooValue :value="row.value" :type="row.type" />
import { computed, ref } from 'vue';
import { fmtNum, t } from '@/i18n';
import { fmtAt, fmtDay, fmtValueNum, plainText, valueKind } from './odoo';

const props = defineProps({
  value: { type: null, default: null },
  /** The field's Odoo type (char, many2one, datetime …) when the model's field list knows it. */
  type: { type: String, default: null },
});

/** Ids of a list shown before "+n". */
const FEW = 6;
/** Text longer than this (or with more than three lines) starts collapsed. */
const LONG = 180;

const kind = computed(() => valueKind(props.value, props.type));
const text = computed(() => (kind.value === 'html' ? plainText(props.value) : kind.value === 'text' ? String(props.value) : kind.value === 'json' ? JSON.stringify(props.value, null, 2) : ''));
const long = computed(() => text.value.length > LONG || text.value.split('\n').length > 3);
const firstIds = computed(() => (kind.value === 'ids' ? props.value.slice(0, FEW).map((id) => `#${id}`).join('  ') : ''));
const expanded = ref(false);
</script>

<template>
  <span v-if="kind === 'empty'" class="font-normal text-faint">—</span>
  <span v-else-if="kind === 'bool'">{{ value ? t('نعم', 'Yes') : t('لا', 'No') }}</span>
  <span v-else-if="kind === 'ref'"><span dir="auto">{{ value[1] || t('بلا اسم', 'No name') }}</span> <span class="num ltr text-[9.5px] font-normal text-faint">#{{ value[0] }}</span></span>
  <span v-else-if="kind === 'ids'">
    <span class="font-normal text-sec">{{ t('العدد', 'Count') }}</span> <span class="num ltr">{{ fmtNum(value.length) }}</span>
    <span class="num ltr ms-1.5 text-[9.5px] font-normal text-faint">{{ firstIds }}<template v-if="value.length > FEW">  +{{ fmtNum(value.length - FEW) }}</template></span>
  </span>
  <span v-else-if="kind === 'num'" class="num ltr">{{ fmtValueNum(value, type) }}</span>
  <span v-else-if="kind === 'datetime'" class="num ltr" :title="`${value} UTC`">{{ fmtAt(value) }}</span>
  <span v-else-if="kind === 'date'" class="num ltr">{{ fmtDay(value) }}</span>
  <div v-else class="min-w-0">
    <template v-if="kind === 'json'">
      <pre v-if="expanded" class="num ltr m-0 max-h-[320px] overflow-auto whitespace-pre-wrap break-words rounded-[10px] bg-soft p-2.5 text-[10px] font-normal leading-[1.6]">{{ text }}</pre>
      <span v-else class="font-normal text-sec">{{ t('بيانات مركّبة، عدد عناصرها', 'Structured data, items') }} <span class="num ltr">{{ fmtNum(Object.keys(value).length) }}</span></span>
    </template>
    <div v-else class="whitespace-pre-wrap break-words leading-[1.8]" :class="{ 'line-clamp-3': long && !expanded }"><span dir="auto">{{ text }}</span></div>
    <button v-if="long || kind === 'json'" type="button" class="mt-0.5 cursor-pointer border-0 bg-transparent p-0 text-[9.5px] font-bold text-brand-dark hover:text-violet" :class="{ 'ms-1.5': kind === 'json' && !expanded }" @click="expanded = !expanded">{{ expanded ? t('إخفاء', 'Show less') : t('عرض الكل', 'Show all') }}</button>
  </div>
</template>
