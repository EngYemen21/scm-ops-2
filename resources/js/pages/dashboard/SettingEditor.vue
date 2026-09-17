<script setup>
// One business policy (system setting): key, default / overridden marker, description and an editor by value type —
// on/off select, number, text, JSON (validated) or the structured approval-tiers editor. PUT /settings/:key { value }.
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { bi, fmtDate, lang, t } from '@/i18n';
import ApprovalTiersEditor from './ApprovalTiersEditor.vue';
import { APPROVAL_TIERS_KEY, isTierList, settingKind, settingText, validateTiers } from './settings';

const props = defineProps({
  /** Setting key, e.g. `inventory.expiringSoonDays`. */
  k: { type: String, required: true },
  /** { value, group, description, isDefault, updatedAt } */
  row: { type: Object, required: true },
});

const act = useAction();
const kind = computed(() => settingKind(props.row.value));
const original = computed(() => settingText(props.row.value, kind.value));
/** The draft is always text (JSON for structured values), so "dirty" is a plain comparison. */
const draft = ref(original.value);
const invalid = ref(null);
watch(original, (v) => { draft.value = v; invalid.value = null; });
const dirty = computed(() => draft.value !== original.value);

const isTiers = computed(() => props.k === APPROVAL_TIERS_KEY && isTierList(props.row.value));
const tiers = computed(() => { try { const v = JSON.parse(draft.value); return Array.isArray(v) ? v : []; } catch { return []; } });
const setTiers = (v) => { draft.value = JSON.stringify(v, null, 2); invalid.value = null; };
const jsonRows = computed(() => Math.min(10, Math.max(3, draft.value.split('\n').length)));

function undo() { draft.value = original.value; invalid.value = null; }
async function save() {
  let value = draft.value;
  invalid.value = null;
  if (kind.value === 'bool') value = draft.value === 'true';
  else if (kind.value === 'num') {
    value = draft.value.trim() === '' ? NaN : Number(draft.value);
    if (Number.isNaN(value)) { invalid.value = t('رقم غير صالح', 'Invalid number'); return; }
  } else if (kind.value === 'json') {
    try { value = JSON.parse(draft.value); } catch { invalid.value = t('JSON غير صالح', 'Invalid JSON'); return; }
    if (isTiers.value) { const m = validateTiers(value); if (m) { invalid.value = bi(m); return; } }
  }
  await act.run(() => api.put(`/settings/${encodeURIComponent(props.k)}`, { value }), { success: { ar: `تم حفظ ${props.k}`, en: `${props.k} saved` }, invalidate: ['settings', 'dashboard', 'tower'] });
}
</script>

<template>
  <div class="flex items-start gap-2.5 border-t border-[#F7F6FA] py-[9px]">
    <div class="mt-2 h-1.5 w-1.5 flex-none rounded-full" :class="row.isDefault ? 'bg-brand' : 'bg-violet'" :title="row.isDefault ? t('القيمة الافتراضية', 'default value') : t('قيمة مُعدّلة', 'overridden')" />
    <div class="min-w-0 flex-1">
      <div class="row wrap !gap-1.5">
        <span class="num text-[10.5px] !font-extrabold text-ink" dir="ltr">{{ k }}</span>
        <Chip v-if="row.isDefault" small :label="{ ar: 'افتراضي', en: 'Default' }" fg="#0d7f93" bg="#d9f4f9" />
        <Chip v-else small :label="{ ar: 'مُعدّل', en: 'Overridden' }" fg="#654e92" bg="#efeaf8" :title="row.updatedAt ? fmtDate(row.updatedAt) : null" />
      </div>
      <div class="mt-0.5 text-[10px] leading-[1.7] text-muted">{{ row.description }}</div>
      <div class="row mt-1.5 !items-start">
        <SelectInput v-if="kind === 'bool'" v-model="draft" small class="w-[140px]" :options="[['true', { ar: 'مفعّل', en: 'On' }], ['false', { ar: 'معطّل', en: 'Off' }]]" />
        <NumberInput v-else-if="kind === 'num'" small class="w-[140px]" :model-value="draft === '' ? null : Number(draft)" :error="invalid" @update:model-value="(n) => { draft = n == null ? '' : String(n); invalid = null; }" @enter="save" />
        <div v-else-if="kind === 'str'" class="min-w-0 flex-1"><TextInput v-model="draft" small @enter="save" /></div>
        <ApprovalTiersEditor v-else-if="isTiers" :model-value="tiers" @update:model-value="setTiers" />
        <div v-else class="min-w-0 flex-1"><TextArea v-model="draft" :rows="jsonRows" :error="invalid" class="ltr font-num !text-[10.5px]" /></div>
        <Btn :tone="dirty ? 'dark' : 'ghost'" size="sm" :disabled="!dirty" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="save" />
        <Btn v-if="dirty" tone="ghost" size="sm" :label="{ ar: 'تراجع', en: 'Undo' }" @click="undo" />
      </div>
      <div v-if="isTiers && invalid" class="field-err mt-1">{{ invalid }}</div>
      <div v-if="act.error.value" class="field-err mt-1">{{ act.error.value.localized(lang) }}</div>
    </div>
  </div>
</template>
