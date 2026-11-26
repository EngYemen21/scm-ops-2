<script setup>
// A fiscal position (vatreturn.manage) — POST /tax/positions · PATCH /tax/positions/{id} (only what changed), with its
// replacement lines `lines: [{ fromCodeId, toCodeId }]` (the whole list is sent when it changed). A position that came
// from Odoo while the section follows it is read-only here.
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, KV, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { isMobile } from '@/composables/viewport';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { SELECT, changedOnly, codeLabel, textOrNull, useTaxLookups } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The position to edit; null makes a new one. */
  position: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const auth = useAuth();
const lk = useTaxLookups();
const act = useAction({ invalidate: ['tax'] });
const readOnly = computed(() => !auth.can('vatreturn.manage') || !!props.position?.owned);
const YES_NO = [{ v: 'yes', l: { ar: 'نعم', en: 'Yes' } }, { v: 'no', l: { ar: 'لا', en: 'No' } }];
const f = reactive({});
const lines = ref([]);
let before = {};
let keySeq = 0;
const initial = (p) => ({
  nameAr: p?.nameAr || '', nameEn: p?.nameEn || '', sequence: p?.sequence ?? null, autoApply: p?.autoApply ? 'yes' : 'no', vatRequired: p?.vatRequired ? 'yes' : 'no',
  countryCode: p?.countryCode || '', countryGroup: p?.countryGroup || '', zipFrom: p?.zipFrom || '', zipTo: p?.zipTo || '', foreignVat: p?.foreignVat || '', note: p?.note || '', active: p ? (p.active ? 'yes' : 'no') : 'yes',
});
watch(() => [props.open, props.position?.id], () => {
  if (!props.open) return;
  Object.assign(f, initial(props.position));
  lines.value = (props.position?.lines || []).map((l) => ({ key: ++keySeq, fromCodeId: l.from?.id || '', toCodeId: l.to?.id || '' }));
  before = body();
  act.clearError();
}, { immediate: true });

const codeOptions = computed(() => lk.codeOptions());
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
const addLine = () => lines.value.push({ key: ++keySeq, fromCodeId: '', toCodeId: '' });
const removeLine = (i) => lines.value.splice(i, 1);

function body() {
  const out = {
    nameAr: String(f.nameAr || '').trim(), nameEn: textOrNull(f.nameEn), sequence: f.sequence == null ? null : Math.round(f.sequence), autoApply: f.autoApply === 'yes', vatRequired: f.vatRequired === 'yes',
    countryCode: textOrNull(String(f.countryCode || '').toUpperCase()), countryGroup: textOrNull(f.countryGroup), zipFrom: textOrNull(f.zipFrom), zipTo: textOrNull(f.zipTo), foreignVat: textOrNull(f.foreignVat), note: textOrNull(f.note),
    lines: lines.value.filter((l) => l.fromCodeId && l.toCodeId).map((l) => ({ fromCodeId: l.fromCodeId, toCodeId: l.toCodeId })),
  };
  if (props.position) out.active = f.active === 'yes';
  return out;
}
const linesOk = computed(() => lines.value.every((l) => (!l.fromCodeId && !l.toCodeId) || (l.fromCodeId && l.toCodeId && l.fromCodeId !== l.toCodeId)));
const ok = computed(() => String(f.nameAr || '').trim() !== '' && (!f.countryCode || /^[A-Za-z]{2}$/.test(f.countryCode)) && linesOk.value);

async function submit() {
  let saved;
  if (props.position) {
    const b = changedOnly(before, body());
    if ('sequence' in b && b.sequence == null) delete b.sequence;
    if (!Object.keys(b).length) { emit('close'); return; }
    saved = await act.run(() => api.patch(`/tax/positions/${encodeURIComponent(props.position.id)}`, b), { success: t('حُفظ الموقف الضريبي', 'Fiscal position saved') });
  } else {
    const b = body();
    if (b.sequence == null) delete b.sequence;
    saved = await act.run(() => api.postIdempotent('/tax/positions', b), { success: t('أُنشئ الموقف الضريبي', 'Fiscal position created') });
  }
  if (saved) emit('saved', saved);
}
</script>

<template>
  <TaxFormDrawer :open="open" :title="position ? { ar: position.nameAr, en: position.nameEn || position.nameAr } : { ar: 'موقف ضريبي جديد', en: 'New fiscal position' }"
                 :sub="{ ar: 'موقف ضريبي', en: 'Fiscal position' }" :width="640" :z-index="70" :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :read-only="readOnly"
                 :missing="{ ar: 'الاسم العربي إلزامي، رمز الدولة حرفان، وكل سطر برمزين مختلفين', en: 'The Arabic name is required, the country code is 2 letters, and each line has two different codes' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="position?.owned" class="banner teal"><div>{{ t('هذا الموقف يُدار من أودو ما دام القسم يتبعه — يُعدَّل هناك.', 'This position is owned by Odoo while the section follows it — change it there.') }}</div></div>
    <div class="form-grid">
      <TextInput v-model="f.nameAr" :label="{ ar: 'الاسم (عربي)', en: 'Name (Arabic)' }" required dir="rtl" :disabled="readOnly" />
      <TextInput v-model="f.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" :disabled="readOnly" />
      <PillChoice v-if="!readOnly" v-model="f.autoApply" :label="{ ar: 'يُطبَّق تلقائيًا', en: 'Applied automatically' }" :options="YES_NO" />
      <PillChoice v-if="!readOnly" v-model="f.vatRequired" :label="{ ar: 'يتطلب رقمًا ضريبيًا', en: 'VAT number required' }" :options="YES_NO" />
      <TextInput v-model="f.countryCode" :label="{ ar: 'رمز الدولة', en: 'Country code' }" dir="ltr" mono :disabled="readOnly" :hint="{ ar: 'حرفان، مثل SA', en: '2 letters, e.g. SA' }" />
      <TextInput v-model="f.countryGroup" :label="{ ar: 'مجموعة الدول', en: 'Country group' }" :disabled="readOnly" />
      <TextInput v-model="f.zipFrom" :label="{ ar: 'الرمز البريدي من', en: 'Postal code from' }" dir="ltr" :disabled="readOnly" />
      <TextInput v-model="f.zipTo" :label="{ ar: 'الرمز البريدي إلى', en: 'Postal code to' }" dir="ltr" :disabled="readOnly" />
      <TextInput v-model="f.foreignVat" :label="{ ar: 'رقم ضريبي أجنبي', en: 'Foreign VAT number' }" dir="ltr" :disabled="readOnly" />
      <NumberInput v-model="f.sequence" :label="{ ar: 'الترتيب', en: 'Sequence' }" :min="0" :disabled="readOnly" />
      <PillChoice v-if="position && !readOnly" v-model="f.active" :label="{ ar: 'فعّال', en: 'Active' }" :options="YES_NO" />
      <TextArea v-model="f.note" :label="{ ar: 'ملاحظة', en: 'Note' }" :rows="2" :disabled="readOnly" />
    </div>

    <div class="card-title mb-1 mt-4">{{ t('استبدال الرموز', 'Code replacements') }}</div>
    <div class="mb-2 text-[10px] text-muted">{{ t('للأطراف الذين ينطبق عليهم هذا الموقف: الرمز في العمود الأول يُستبدل بالرمز في العمود الثاني.', 'For the partners this position applies to: the code of the first column is replaced by the code of the second.') }}</div>
    <template v-if="!readOnly">
      <div v-if="lines.length" class="le-head mb-1 grid grid-cols-[1fr_1fr_40px] gap-2 text-[10px] font-extrabold text-muted">
        <span>{{ t('من الرمز', 'From code') }}</span><span>{{ t('إلى الرمز', 'To code') }}</span><span />
      </div>
      <div v-for="(l, i) in lines" :key="l.key" class="le-row mb-1.5 grid grid-cols-[1fr_1fr_40px] items-start gap-2">
        <SelectInput v-model="l.fromCodeId" :label="cap('من الرمز', 'From code')" :options="codeOptions" :placeholder="SELECT" />
        <SelectInput v-model="l.toCodeId" :label="cap('إلى الرمز', 'To code')" :options="codeOptions" :placeholder="SELECT" :error="l.fromCodeId && l.fromCodeId === l.toCodeId ? bi({ ar: 'الرمز نفسه', en: 'Same code' }) : null" />
        <button type="button" class="x-btn" :aria-label="t('حذف السطر', 'Remove line')" @click="removeLine(i)">✕</button>
      </div>
      <Btn size="sm" tone="soft" :label="{ ar: '+ سطر استبدال', en: '+ Replacement line' }" @click="addLine" />
    </template>
    <template v-else>
      <div v-if="!position?.lines?.length" class="text-[10px] text-muted">{{ t('لا استبدالات', 'No replacement') }}</div>
      <div v-for="l in position?.lines || []" :key="l.id" class="tile mb-1 flex items-center gap-2 text-[10.5px]">
        <span class="flex-1 truncate">{{ codeLabel(l.from) }}</span><span class="text-muted">{{ t('←', '→') }}</span><span class="flex-1 truncate">{{ codeLabel(l.to) }}</span>
      </div>
    </template>
    <div v-if="position" class="kv-grid mt-4">
      <KV :k="{ ar: 'الأطراف المرتبطة', en: 'Partners linked' }" :v="position.partners ?? 0" ltr />
      <KV :k="{ ar: 'المصدر', en: 'Origin' }" :v="position.sourceSystem ? `${position.sourceSystem} #${position.externalRef || ''}` : t('العمليات', 'OPS')" ltr />    </div>
  </TaxFormDrawer>
</template>
