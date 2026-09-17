<script setup>
// Generic create / action form in a 520px drawer: field grid, required + custom validation, server field errors
// shown inline, footer with Save + Cancel and the audit note.
//
//   <FormDrawer :open="open" :title="{ ar, en }" :fields="fields" :initial="{ city: 'RYD' }"
//               :submit="(values) => api.postIdempotent('/customers', values)" :action="{ success: t('أُضيف', 'Added'), invalidate: ['customers'] }"
//               @close="open = false" @done="(result, values) => …" />
//
// Field: { k, label, type: 'text'|'num'|'area'|'select'|'date'|'datetime'|'scan'|'password', required, opts: Option[] | (values) => Option[],
//          ph, hint, def, full, dir, min, max, step, disabled: bool | (values) => bool, visible: (values) => bool,
//          validate: (value, values) => 'message' | null }
// Custom control for a field: slot `#field-<k>="{ value, set, values, error }"`. Extra content: slots `before` / `after`.
import { computed, reactive, ref, watch } from 'vue';
import { useAction } from '../api/client';
import { bi, lang, t } from '../i18n';
import Drawer from '../layout/Drawer.vue';
import ErrorBanner from '../layout/ErrorBanner.vue';
import { useAuth } from '../stores/auth';
import Btn from './Btn.vue';
import DateInput from './DateInput.vue';
import NumberInput from './NumberInput.vue';
import ScanInput from './ScanInput.vue';
import SelectInput from './SelectInput.vue';
import TextArea from './TextArea.vue';
import TextInput from './TextInput.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  fields: { type: Array, required: true },
  initial: { type: Object, default: null },
  /** Runs the mutation (usually `api.postIdempotent`). Receives the visible, non-empty values. */
  submit: { type: Function, required: true },
  submitLabel: { type: [String, Object], default: null },
  cancelLabel: { type: [String, Object], default: null },
  /** Footer note; `false` hides it. Default: required-fields + audit-trail note. */
  note: { type: [String, Object, Boolean], default: null },
  width: { type: Number, default: 520 },
  /** Toast / invalidation options forwarded to useAction. */
  action: { type: Object, default: null },
  keepOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction();
const values = reactive({});
const errors = ref({});

function reset() {
  Object.keys(values).forEach((k) => delete values[k]);
  props.fields.forEach((f) => { if (f.def !== undefined) values[f.k] = f.def; });
  Object.assign(values, props.initial || {});
  errors.value = {};
  act.clearError();
}
watch(() => props.open, (open) => { if (open) reset(); }, { immediate: true });

const visible = computed(() => props.fields.filter((f) => !f.visible || f.visible(values)));
const set = (k, v) => { values[k] = v; if (errors.value[k]) { const n = { ...errors.value }; delete n[k]; errors.value = n; } };
const optsOf = (f) => (typeof f.opts === 'function' ? f.opts(values) : f.opts || []);
const disabledOf = (f) => (typeof f.disabled === 'function' ? f.disabled(values) : !!f.disabled);
const selectPh = (f) => f.ph ?? (f.required ? { ar: '— اختر —', en: '— select —' } : { ar: '—', en: '—' });

async function doSubmit() {
  const errs = {};
  for (const f of visible.value) {
    const v = values[f.k];
    const empty = v === undefined || v === null || v === '' || (typeof v === 'number' && Number.isNaN(v));
    if (f.required && empty) errs[f.k] = t('هذا الحقل إلزامي', 'Required');
    else if (f.validate) { const m = f.validate(v, values); if (m) errs[f.k] = m; }
  }
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const out = {};
  visible.value.forEach((f) => { if (values[f.k] !== undefined && values[f.k] !== '') out[f.k] = values[f.k]; });
  const res = await act.run(() => props.submit(out), props.action || {});
  if (res !== undefined) { emit('done', res, out); if (!props.keepOpen) emit('close'); }
}
// Server-side validation details land on their fields.
watch(() => act.error.value, (e) => { if (e) { const fe = e.fieldErrors(); if (Object.keys(fe).length) errors.value = { ...errors.value, ...fe }; } });

const userName = computed(() => (auth.user ? (lang.value === 'ar' ? auth.user.nameAr : auth.user.nameEn) : ''));
</script>

<template>
  <Drawer :open="open" :title="title" :sub="sub" :width="width" :z-index="70" @close="emit('close')">
    <slot name="before" :values="values" />
    <form class="form-grid" @submit.prevent="doSubmit">
      <template v-for="f in visible" :key="f.k">
        <div v-if="$slots[`field-${f.k}`]" class="field" :class="{ full: f.full }"><slot :name="`field-${f.k}`" :value="values[f.k]" :set="(v) => set(f.k, v)" :values="values" :error="errors[f.k]" /><div v-if="errors[f.k]" class="field-err">{{ errors[f.k] }}</div></div>
        <NumberInput v-else-if="f.type === 'num'" :model-value="values[f.k]" :label="f.label" :required="f.required" :error="errors[f.k]" :hint="f.hint" :full="f.full" :placeholder="f.ph" :disabled="disabledOf(f)" :min="f.min" :max="f.max" :step="f.step" @update:model-value="set(f.k, $event)" />
        <TextArea v-else-if="f.type === 'area'" :model-value="values[f.k]" :label="f.label" :required="f.required" :error="errors[f.k]" :hint="f.hint" :placeholder="f.ph" :disabled="disabledOf(f)" @update:model-value="set(f.k, $event)" />
        <SelectInput v-else-if="f.type === 'select'" :model-value="values[f.k]" :options="optsOf(f)" :label="f.label" :required="f.required" :error="errors[f.k]" :hint="f.hint" :full="f.full" :placeholder="selectPh(f)" :disabled="disabledOf(f)" @update:model-value="set(f.k, $event)" />
        <DateInput v-else-if="f.type === 'date' || f.type === 'datetime'" :model-value="values[f.k]" :time="f.type === 'datetime'" :label="f.label" :required="f.required" :error="errors[f.k]" :hint="f.hint" :full="f.full" :disabled="disabledOf(f)" @update:model-value="set(f.k, $event)" />
        <ScanInput v-else-if="f.type === 'scan'" :model-value="values[f.k] || ''" :clear-on-submit="false" :label="f.label" :required="f.required" :error="errors[f.k]" :hint="f.hint" :full="f.full" :placeholder="f.ph" :disabled="disabledOf(f)" @update:model-value="set(f.k, $event)" @submit="set(f.k, $event)" />
        <TextInput v-else :model-value="values[f.k]" :type="f.type === 'password' ? 'password' : 'text'" :dir="f.dir" :label="f.label" :required="f.required" :error="errors[f.k]" :hint="f.hint" :full="f.full" :placeholder="f.ph" :disabled="disabledOf(f)" @update:model-value="set(f.k, $event)" @enter="doSubmit" />
      </template>
    </form>
    <slot name="after" :values="values" />

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="submitLabel ?? { ar: 'حفظ', en: 'Save' }" @click="doSubmit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="cancelLabel ?? { ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div v-if="note !== false" class="mt-2 text-[9px] leading-[1.7] text-faint">{{ note ? bi(note) : t(`* حقول إلزامية · يُسجل الإجراء في Audit Trail باسم ${userName}`, `* required fields · the action is recorded in the audit trail as ${userName}`) }}</div>
    </template>
  </Drawer>
</template>
