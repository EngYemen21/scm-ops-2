<script setup>
// A tax code — create (POST /tax/codes) or edit (PATCH /tax/codes/{id}, only what changed); vatreturn.manage.
// The form follows the lookups: the kinds a use allows (`kindsByUse`), the lines of the return a use + kind may feed
// (`allowedBoxes`, `null` = not on the return), and the authority's exemption reasons of the kind's category
// (`exemptions` filtered by `categories[kind]`; VATEX-SA-OOS carries the taxpayer's own text).
// A code that came from Odoo while the section follows it (`owned`) is read-only, except what is OPS's own: the short
// code, the default flag, and the return line / exemption reason when Odoo does not give them (`sourceGives`).
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { KV, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { bi, fmtDate, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { SELECT, USE, boxLabel, changedOnly, optionsOf, textOrNull, useTaxLookups } from './tax';

const props = defineProps({
  /** A code id, `new`, or null (closed). */
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'saved']);

const auth = useAuth();
const lk = useTaxLookups();
const act = useAction({ invalidate: ['tax'] });
const isNew = computed(() => props.id === 'new');
const code = computed(() => (props.id && !isNew.value ? lk.codes.value.find((c) => c.id === props.id || c.code === props.id) || null : null));
const canManage = computed(() => auth.can('vatreturn.manage'));
const owned = computed(() => !!code.value?.owned);
const ownBox = computed(() => !owned.value || !code.value?.sourceGives?.returnBox);
const ownReason = computed(() => !owned.value || !code.value?.sourceGives?.exemptionReason);
/** Fields an owned code keeps read-only. */
const locked = computed(() => owned.value || !canManage.value);

const TAXED = ['standard', 'import', 'reverse_charge', 'withholding'];
const YES_NO = [{ v: 'yes', l: { ar: 'نعم', en: 'Yes' } }, { v: 'no', l: { ar: 'لا', en: 'No' } }];
const SCOPE = [{ v: 'goods', l: { ar: 'سلع', en: 'Goods' } }, { v: 'service', l: { ar: 'خدمات', en: 'Services' } }];
const f = reactive({});
let before = {};
const initial = (c) => ({
  code: c?.code || '', nameAr: c?.nameAr || '', nameEn: c?.nameEn || '', label: c?.label || '', description: c?.description || '', use: c?.use || 'sale', kind: c?.kind || 'standard',
  rate: c ? num(c.rate) : 15, returnBox: c ? (c.returnBox == null ? '' : String(c.returnBox)) : '1', exemptionReasonCode: c?.exemptionReasonCode || '', exemptionReasonAr: c?.exemptionReasonAr || '',
  exemptionReasonEn: c?.exemptionReasonEn || '', scope: c?.scope || '', priceInclude: c?.priceInclude ? 'yes' : 'no', sequence: c?.sequence ?? null, groupId: c?.groupId || '',
  isDefault: c?.isDefault ? 'yes' : 'no', active: c ? (c.active ? 'yes' : 'no') : 'yes',
});
const open = computed(() => isNew.value || !!code.value);
// re-read when another code opens, when the lookups bring it, and after a save changed it
watch(() => [props.id, code.value?.id, code.value?.updatedAt], () => {
  if (!open.value) return;
  Object.assign(f, initial(code.value));
  before = body();
  act.clearError();
}, { immediate: true });

// ---- what the lookups allow
const kindOptions = computed(() => (lk.kindsByUse.value[f.use] || []).map((k) => ({ v: k, l: lk.kinds.value[k] || { ar: k, en: k } })));
const category = computed(() => lk.categories.value[f.kind] || null);
const needsReason = computed(() => ['Z', 'E', 'O'].includes(category.value));
const reasonOptions = computed(() => lk.exemptions.value.filter((e) => e.category === category.value).map((e) => ({ v: e.code, l: { ar: `${e.code} · ${e.ar}`, en: `${e.code} · ${e.en}` } })));
const oos = computed(() => f.exemptionReasonCode === 'VATEX-SA-OOS');
const allowed = computed(() => lk.allowedBoxes.value[f.use]?.[f.kind] || []);
const boxOptions = computed(() => allowed.value.map((b) => (b == null ? { v: '', l: { ar: '— ليس على الإقرار —', en: '— not on the return —' } } : { v: String(b), l: boxLabel(lk.boxMap.value, b) })));
const taxed = computed(() => TAXED.includes(f.kind));

function setUse(v) {
  f.use = v;
  if (!(lk.kindsByUse.value[v] || []).includes(f.kind)) setKind((lk.kindsByUse.value[v] || [])[0] || '');
  else setKind(f.kind);
}
function setKind(v) {
  f.kind = v;
  const first = (lk.allowedBoxes.value[f.use]?.[v] || [])[0];
  f.returnBox = first == null ? '' : String(first);
  if (!TAXED.includes(v)) f.rate = 0; else if (!f.rate) f.rate = v === 'withholding' ? 5 : 15;
  if (!reasonOptions.value.some((o) => o.v === f.exemptionReasonCode)) f.exemptionReasonCode = '';
}

function body() {
  const out = {
    code: String(f.code || '').trim().toUpperCase(), nameAr: String(f.nameAr || '').trim(), nameEn: textOrNull(f.nameEn), label: textOrNull(f.label), description: textOrNull(f.description),
    use: f.use, kind: f.kind, rate: String(num(f.rate)), returnBox: f.returnBox === '' ? null : Number(f.returnBox),
    exemptionReasonCode: needsReason.value ? f.exemptionReasonCode || null : null,
    exemptionReasonAr: needsReason.value && oos.value ? textOrNull(f.exemptionReasonAr) : null, exemptionReasonEn: needsReason.value && oos.value ? textOrNull(f.exemptionReasonEn) : null,
    scope: f.scope || null, priceInclude: f.priceInclude === 'yes', sequence: f.sequence == null ? null : Math.round(f.sequence), groupId: f.groupId || null, isDefault: f.isDefault === 'yes',
  };
  if (!isNew.value) out.active = f.active === 'yes';
  return out;
}
const codeOk = computed(() => /^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/.test(String(f.code || '').trim()));
const ok = computed(() => {
  if (!codeOk.value) return false;
  if (owned.value) return true;
  const r = num(f.rate);
  if (!String(f.nameAr || '').trim() || !f.use || !f.kind || r < 0 || r > 100 || (taxed.value && r === 0) || (!taxed.value && r !== 0)) return false;
  if (needsReason.value && f.use === 'sale' && f.active === 'yes' && !f.exemptionReasonCode) return false;
  if (oos.value && !String(f.exemptionReasonAr || '').trim() && !String(f.exemptionReasonEn || '').trim()) return false;
  return true;
});

async function submit() {
  let saved;
  if (isNew.value) {
    const b = body();
    if (b.sequence == null) delete b.sequence;
    saved = await act.run(() => api.postIdempotent('/tax/codes', b), { success: (c) => t(`أُنشئ الرمز ${c?.code}`, `Code ${c?.code} created`) });
  } else {
    let b = changedOnly(before, body());
    if (owned.value) {
      const keep = ['code', 'isDefault', ...(ownBox.value ? ['returnBox'] : []), ...(ownReason.value ? ['exemptionReasonCode', 'exemptionReasonAr', 'exemptionReasonEn'] : [])];
      b = Object.fromEntries(Object.entries(b).filter(([k]) => keep.includes(k)));
    }
    if ('sequence' in b && b.sequence == null) delete b.sequence;
    if (!Object.keys(b).length) { emit('close'); return; }
    saved = await act.run(() => api.patch(`/tax/codes/${encodeURIComponent(code.value.id)}`, b), { success: t('حُفظ الرمز', 'Code saved') });
  }
  if (saved) emit('saved', saved);
}
const missing = computed(() => (!codeOk.value
  ? { ar: 'الرمز المختصر: حروف لاتينية وأرقام (حتى 30) ويمكن أن يحوي . _ -', en: 'Short code: Latin letters and digits (up to 30), . _ - allowed' }
  : { ar: 'أكمل الاسم والنوع والنسبة (أكبر من صفر للأنواع الخاضعة، صفر لغيرها) وسبب الإعفاء لرمز مبيعات بلا ضريبة', en: 'Complete the name, kind and rate (above zero for taxed kinds, zero otherwise) and the exemption reason of a sales code without VAT' }));
</script>

<template>
  <TaxFormDrawer :open="open" :title="isNew ? { ar: 'رمز ضريبي جديد', en: 'New tax code' } : { ar: `الرمز ${code?.code || ''}`, en: `Code ${code?.code || ''}` }" :sub="code ? bi({ ar: code.nameAr, en: code.nameEn || code.nameAr }) : null"
                 :width="620" :z-index="70" :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="missing" :read-only="!canManage"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="owned" class="banner teal">
      <div>{{ t('هذا الرمز يُدار من أودو ما دام القسم يتبعه: يُعدَّل هناك. هنا يُعدَّل ما يخص العمليات فقط — الرمز المختصر، الرمز الافتراضي، وبند الإقرار أو سبب الإعفاء إن لم يحدّده أودو.', 'This code is owned by Odoo while the section follows it: change it there. Here only what is OPS\'s own is edited — the short code, the default flag, and the return line or exemption reason when Odoo does not give them.') }}</div>
    </div>
    <div class="form-grid">
      <TextInput v-model="f.code" :label="{ ar: 'الرمز المختصر', en: 'Short code' }" required dir="ltr" mono :disabled="!canManage" />
      <PillChoice v-if="canManage" v-model="f.isDefault" :label="{ ar: 'الافتراضي لاستخدامه ونسبته', en: 'Default for its use and rate' }" :options="YES_NO" />
      <TextInput v-model="f.nameAr" :label="{ ar: 'الاسم (عربي)', en: 'Name (Arabic)' }" required dir="rtl" :disabled="locked" />
      <TextInput v-model="f.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" :disabled="locked" />
      <PillChoice v-if="!locked" :model-value="f.use" :label="{ ar: 'الاستخدام', en: 'Use' }" :options="optionsOf(USE)" required @update:model-value="setUse" />
      <KV v-else :k="{ ar: 'الاستخدام', en: 'Use' }" :v="bi(USE[f.use] || { ar: f.use, en: f.use })" />
      <SelectInput :model-value="f.kind" :label="{ ar: 'النوع', en: 'Kind' }" required :options="kindOptions" :placeholder="SELECT" :disabled="locked" @update:model-value="setKind" />
      <NumberInput v-model="f.rate" :label="{ ar: 'النسبة %', en: 'Rate %' }" required :min="0" :max="100" :disabled="locked || !taxed" />
      <SelectInput v-model="f.returnBox" :label="{ ar: 'بند الإقرار', en: 'Return line' }" :options="boxOptions" :disabled="!canManage || (owned && !ownBox) || boxOptions.length < 2"
                   :hint="owned && !ownBox ? { ar: 'يحدّده أودو', en: 'Given by Odoo' } : null" full />
      <template v-if="needsReason">
        <SelectInput v-model="f.exemptionReasonCode" :label="{ ar: 'سبب الإعفاء / الصفر (قائمة الهيئة)', en: 'Exemption / zero-rate reason (authority list)' }" :required="f.use === 'sale'" :options="reasonOptions" :placeholder="SELECT"
                     :disabled="!canManage || (owned && !ownReason)" :hint="owned && !ownReason ? { ar: 'يحدّده أودو', en: 'Given by Odoo' } : { ar: 'تطبعه الفاتورة الإلكترونية', en: 'Printed on the e-invoice' }" full />
        <template v-if="oos">
          <TextInput v-model="f.exemptionReasonAr" :label="{ ar: 'نص السبب (عربي)', en: 'Reason text (Arabic)' }" dir="rtl" :disabled="!canManage || (owned && !ownReason)" />
          <TextInput v-model="f.exemptionReasonEn" :label="{ ar: 'نص السبب (إنجليزي)', en: 'Reason text (English)' }" dir="ltr" :disabled="!canManage || (owned && !ownReason)" />
        </template>
      </template>
      <SelectInput v-model="f.groupId" :label="{ ar: 'المجموعة', en: 'Group' }" :options="lk.groupOptions.value" :placeholder="{ ar: '— بلا مجموعة —', en: '— no group —' }" :disabled="locked" />
      <SelectInput v-model="f.scope" :label="{ ar: 'النطاق', en: 'Scope' }" :options="SCOPE" :placeholder="{ ar: '— الكل —', en: '— both —' }" :disabled="locked" />
      <PillChoice v-if="!locked" v-model="f.priceInclude" :label="{ ar: 'السعر شامل الضريبة', en: 'Price includes VAT' }" :options="YES_NO" />
      <NumberInput v-model="f.sequence" :label="{ ar: 'الترتيب', en: 'Sequence' }" :min="0" :disabled="locked" />
      <PillChoice v-if="!isNew && !locked" v-model="f.active" :label="{ ar: 'فعّال', en: 'Active' }" :options="YES_NO" :hint="{ ar: 'الرمز المؤرشف لا يكون افتراضيًا', en: 'An archived code is nobody\'s default' }" />
      <TextInput v-model="f.label" :label="{ ar: 'التسمية على المستند', en: 'Label on documents' }" :disabled="locked" />
      <TextArea v-model="f.description" :label="{ ar: 'الوصف', en: 'Description' }" :rows="2" :disabled="locked" />
    </div>
    <div v-if="code" class="kv-grid mt-4">
      <KV :k="{ ar: 'فئة الضريبة', en: 'Tax category' }" :v="code.categoryCode || '—'" ltr />
      <KV :k="{ ar: 'المصدر', en: 'Origin' }" :v="code.sourceSystem ? `${code.sourceSystem} #${code.externalRef || ''}` : t('العمليات', 'OPS')" ltr />
      <KV :k="{ ar: 'آخر تعديل', en: 'Last change' }" :v="code.updatedAt ? `${code.updatedBy || '—'} · ${fmtDate(code.updatedAt)}` : '—'" />
    </div>
    <div v-if="!isNew && !owned" class="hint !mt-3">{{ t('رمز عليه مستندات في إقرارات مراجَعة أو مقدَّمة لا يتغيّر نوعه أو نسبته أو بنده — أنشئ رمزًا جديدًا عندها.', 'A code carried by documents of reviewed or filed returns keeps its kind, rate and line — create a new code instead.') }}</div>
  </TaxFormDrawer>
</template>
