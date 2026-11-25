<script setup>
// «كل الحقول» of a document: every value Odoo holds for the record, for the people who need all of it — the name
// Odoo itself gives the field, its technical name, its type in words and the value (drawn by the component the Odoo
// tab of the integration tower uses for a record). This is the only tab of the page where technical names appear.
// The API gives the record as it is in Odoo: `type` is Odoo's own type of the field (char, many2one, selection … —
// null where the copy does not know it), `value` what was copied (a many2one as `[id, "name"]`, a moment as Odoo's
// UTC text, html as html — shown as its text, never as markup — and null or Odoo's `false` where it is empty); a
// choice also comes under the name Odoo gives it (`valueLabel`), which is what people read. An answer that says
// instead how a value is to be shown (`type`: text · list · bool · money · number, with `odooType` and, for a
// many2one, its label with `ref`) is read too.
//   <ArchiveFields :fields="doc.fields" :model="doc.model" :odoo-id="doc.odooId" />
import { computed, ref } from 'vue';
import { Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import OdooValue from '../integration/OdooValue.vue';
import { fieldType, valueKind, valueText } from '../integration/odoo';
import { isId, isObj, labelOf } from './archive';

const props = defineProps({
  /** [{ name, label, type, value, valueLabel? }] */
  fields: { type: Array, default: () => [] },
  /** Technical name of the Odoo model, shown above the list. */
  model: { type: String, default: null },
  odooId: { type: Number, default: null },
});

/** How the API says a value is shown → the Odoo type the value component draws that way. */
const DRAWN_AS = { bool: 'boolean', money: 'monetary', number: 'float', list: 'many2many' };
const ISO_UTC = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d+)?Z$/;
const rows = computed(() => props.fields.filter((f) => isObj(f) && typeof f.name === 'string').map((f) => {
  const shown = typeof f.type === 'string' && f.type ? f.type : null;
  let type = Object.prototype.hasOwnProperty.call(DRAWN_AS, shown) ? DRAWN_AS[shown] : shown;
  let value = f.value ?? null;
  // a many2one comes as its label and the id beside it: drawn as Odoo's own [id, label]
  if (isId(f.ref) && typeof value === 'string') { value = [Number(f.ref), value]; type = 'many2one'; }
  // a moment comes as ISO 8601 UTC: the value component reads Odoo's own spelling of the same instant
  else if (type === 'datetime' && typeof value === 'string') value = value.replace(ISO_UTC, '$1 $2');
  const label = labelOf(f.label);
  // the name Odoo gives a choice, when it says more than the stored value does
  const scalar = typeof value === 'string' || typeof value === 'number';
  const said = scalar && typeof f.valueLabel === 'string' && f.valueLabel.trim() && f.valueLabel.trim() !== String(value) ? f.valueLabel.trim() : '';
  return { name: f.name, label: label === f.name ? '' : label, type, odoo: typeof f.odooType === 'string' && f.odooType ? f.odooType : shown, value, said, empty: valueKind(value, type) === 'empty' };
}));
const filled = computed(() => rows.value.filter((x) => !x.empty));
/** '' = every field, 'filled' = only the ones that hold something. */
const only = ref('filled');
const words = ref('');
const shownRows = computed(() => {
  const f = words.value.trim().toLowerCase();
  return (only.value === 'filled' ? filled.value : rows.value).filter((x) => !f || x.name.toLowerCase().includes(f) || x.label.toLowerCase().includes(f) || x.said.toLowerCase().includes(f) || valueText(x.value).includes(f));
});
const onlyTabs = computed(() => [
  { k: 'filled', label: { ar: `المعبّأة فقط (${fmtNum(filled.value.length)})`, en: `Filled only (${fmtNum(filled.value.length)})` } },
  { k: '', label: { ar: `كل الحقول (${fmtNum(rows.value.length)})`, en: `Every field (${fmtNum(rows.value.length)})` } },
]);
</script>

<template>
  <div v-if="model" class="mb-2.5 text-[10px] text-muted">
    {{ t('السجل كما هو في أودو', 'The record as it is in Odoo') }}: <span class="num ltr inline-block">{{ model }}<template v-if="odooId != null"> · #{{ odooId }}</template></span>
  </div>
  <div class="row wrap">
    <TextInput v-model="words" small type="search" field-class="min-w-[180px] flex-1" :placeholder="{ ar: 'بحث باسم الحقل أو بقيمته…', en: 'Filter by field name or value…' }" />
    <Tabs v-model="only" :tabs="onlyTabs" variant="pill" class="!mb-0" />
  </div>
  <div class="mt-2.5 overflow-hidden rounded-xl border border-line-2">
    <div v-for="x in shownRows" :key="x.name" class="flex flex-wrap items-start gap-x-3 gap-y-1 border-t border-line-2 px-3 py-2 first:border-t-0">
      <div class="w-[38%] min-w-[150px] flex-none">
        <div v-if="x.label" class="text-[10.5px] font-bold text-sec [text-align:match-parent]" dir="auto">{{ x.label }}</div>
        <div class="num ltr break-all text-[9.5px] text-faint">{{ x.name }}</div>
        <div v-if="x.odoo" class="mt-px text-[8.5px] text-faint" :title="x.odoo">{{ fieldType(x.odoo) }}</div>
      </div>
      <div class="min-w-0 flex-1 basis-[180px] text-[10.5px] font-bold text-ink">
        <span v-if="x.said"><span dir="auto">{{ x.said }}</span> <span class="num ltr text-[9.5px] font-normal text-faint" :title="t('القيمة المخزّنة في أودو', 'The value Odoo stores')">{{ x.value }}</span></span>
        <OdooValue v-else :value="x.value" :type="x.type" />
      </div>
    </div>
    <div v-if="!shownRows.length" class="empty !p-3.5">
      {{ rows.length ? t('لا حقول مطابقة. غيّر البحث أو اعرض كل الحقول.', 'No matching field. Change the filter or show every field.') : t('لم تصل حقول هذا السجل. تظهر هنا كل قيمه كما نُسخت من أودو.', 'The fields of this record did not arrive. Every value copied from Odoo appears here.') }}
    </div>
  </div>
</template>
