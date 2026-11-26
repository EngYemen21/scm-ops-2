<script setup>
// Line editor of the request form for one kind of lines: responsibles · authorised signatories · bank accounts
// (the le-head / le-row pattern of pages/sales/LinesEditor.vue: on a phone the header goes and every line is a card,
// so each input carries its own caption there). A line keeps its `id` (the server keeps the line); a personal field
// (national id, account number, IBAN) starts empty with the saved mask as its placeholder and is sent only when typed.
// Errors arrive keyed `<kind>.<i>.<field>`.
//   <CreditLines v-model:lines="v.responsibles" kind="responsibles" :errors="errors" />
import { computed } from 'vue';
import { Btn, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';

const props = defineProps({
  lines: { type: Array, required: true },
  /** responsibles | signatories | bankLines */
  kind: { type: String, required: true },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:lines']);

/** The fields of each kind: key, caption, LTR, personal (masked) field. */
const FIELDS = {
  responsibles: [
    { k: 'name', ar: 'الاسم', en: 'Name' }, { k: 'position', ar: 'المنصب', en: 'Position' },
    { k: 'mobile', ar: 'الجوال', en: 'Mobile', ltr: true }, { k: 'nationalId', ar: 'رقم الهوية', en: 'National id', ltr: true, personal: true },
  ],
  signatories: [
    { k: 'name', ar: 'الاسم', en: 'Name' }, { k: 'jobTitle', ar: 'المسمى الوظيفي', en: 'Job title' },
    { k: 'mobile', ar: 'الجوال', en: 'Mobile', ltr: true }, { k: 'signatureNote', ar: 'ملاحظة التوقيع', en: 'Signature note' },
  ],
  bankLines: [
    { k: 'bankName', ar: 'البنك', en: 'Bank' }, { k: 'branch', ar: 'الفرع', en: 'Branch' }, { k: 'swift', ar: 'SWIFT', en: 'SWIFT', ltr: true },
    { k: 'accountNumber', ar: 'رقم الحساب', en: 'Account number', ltr: true, personal: true }, { k: 'iban', ar: 'IBAN', en: 'IBAN', ltr: true, personal: true },
  ],
};
const fields = computed(() => FIELDS[props.kind] || []);
const cols = computed(() => `${fields.value.map(() => 'minmax(0,1fr)').join(' ')} 28px`);
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (f) => (isMobile.value ? { ar: f.ar, en: f.en } : null);
const ADD = { responsibles: { ar: '+ مسؤول', en: '+ Responsible' }, signatories: { ar: '+ مفوّض بالتوقيع', en: '+ Signatory' }, bankLines: { ar: '+ حساب بنكي', en: '+ Bank account' } };

let seq = 0;
/** A new empty line of this kind. */
const blankLine = () => ({ key: `n${Date.now()}-${seq++}`, id: null, ...Object.fromEntries(fields.value.map((f) => [f.k, ''])) });
const upd = (i, patch) => emit('update:lines', props.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
const remove = (i) => emit('update:lines', props.lines.filter((_, j) => j !== i));
const add = () => emit('update:lines', [...props.lines, blankLine()]);
/** The placeholder of a personal field: the saved mask of the line, else nothing. */
const ph = (l, f) => (f.personal && l[`${f.k}Mask`] ? { ar: `${l[`${f.k}Mask`]} (اتركه فارغًا ليبقى)`, en: `${l[`${f.k}Mask`]} (leave empty to keep)` } : null);
const err = (i, k) => props.errors[`${props.kind}.${i}.${k}`] || null;
</script>

<template>
  <div class="col !gap-1.5">
    <div v-if="lines.length" class="le-head grid gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint" :style="{ gridTemplateColumns: cols }">
      <div v-for="f in fields" :key="f.k">{{ t(f.ar, f.en) }}</div><div />
    </div>
    <div v-for="(l, i) in lines" :key="l.key" class="le-row grid items-start gap-2 px-2.5 py-1" :style="{ gridTemplateColumns: cols }">
      <TextInput v-for="f in fields" :key="f.k" small :label="cap(f)" :model-value="l[f.k]" :dir="f.ltr ? 'ltr' : null" :placeholder="ph(l, f)" :error="err(i, f.k)" @update:model-value="upd(i, { [f.k]: $event })" />
      <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('حذف السطر', 'Remove the line')" @click="remove(i)">✕</button>
    </div>
    <div v-if="errors[kind]" class="field-err">{{ errors[kind] }}</div>
    <div class="row mt-1"><Btn tone="softPurple" size="sm" :label="ADD[kind]" @click="add" /></div>
  </div>
</template>
