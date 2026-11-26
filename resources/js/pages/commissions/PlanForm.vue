<script setup>
// The form of a commission plan (commission.manage): POST /commissions/plans to create, PATCH /commissions/plans/{code}
// to change — the form always sends the whole plan, the server decides what may still change.
//   who earns      a salesperson plan (each member on his own sales, or every salesperson) · a manager plan (one person on a team's sales, or on every invoice)
//   on what        when: invoiced | fully collected · base: before tax | with tax | the margin · net of returns or not
//   at which rate  rules, in order — the first one that covers an invoice line decides: by product category, by customer,
//                  or "all sales" (always the last); each rule a flat rate or tiers on the month's base
//   when           valid from / to
// With `plan.locked` (a statement of the plan was submitted) the terms are shown, not edited: name, end date, members
// and notes stay open — new terms are a copy of the plan.
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import CustomerPick from './CustomerPick.vue';
import { API, BASE, EARNER, EARN_ON, SCOPE, SELECT_PLACEHOLDER, TIER_MODE, personName, rateText, recordName, scopeText, termsText, useCommissions } from './commissions';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The plan to change; null = a new one. */
  plan: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const { lookups, userOptions } = useCommissions();
const act = useAction({ invalidate: ['commissions'] });
const locked = computed(() => !!props.plan?.locked);

const monthStart = () => `${new Date().toISOString().slice(0, 8)}01`;
const newRule = () => ({ scope: 'all', targets: [], rateType: 'flat', rate: null, tierMode: 'whole', tiers: [{ upTo: null, rate: null }], label: '' });
const blank = () => ({ nameAr: '', nameEn: '', earnerType: 'salesperson', beneficiary: '', everyone: 'no', members: [], earnOn: 'invoiced', base: 'net', netOfReturns: 'yes', validFrom: monthStart(), validTo: '', notes: '', rules: [newRule()] });
function fromPlan(p) {
  return {
    nameAr: p.nameAr || '', nameEn: p.nameEn || '', earnerType: p.earnerType, beneficiary: p.beneficiary?.id || '', everyone: p.everyone ? 'yes' : 'no',
    members: (p.members || []).filter((m) => m.id).map((m) => ({ id: m.id, name: personName(m) })), earnOn: p.earnOn, base: p.base, netOfReturns: p.netOfReturns ? 'yes' : 'no',
    validFrom: p.validFrom || monthStart(), validTo: p.validTo || '', notes: p.notes || '',
    rules: (p.rules || []).map((r) => ({
      scope: r.scope, targets: (r.scopeIds || []).map((id) => ({ id, name: recordName((r.scopeNames || []).find((n) => n.id === id)) })), rateType: r.rateType,
      rate: r.rate ?? null, tierMode: r.tierMode || 'whole', tiers: (r.tiers && r.tiers.length ? r.tiers : [{ upTo: null, rate: null }]).map((x) => ({ upTo: x.upTo ?? null, rate: x.rate ?? null })), label: r.label || '',
    })),
  };
}
const f = ref(blank());
watch(() => props.open, (open) => { if (open) { f.value = props.plan ? fromPlan(props.plan) : blank(); if (!f.value.rules.length) f.value.rules = [newRule()]; act.clearError(); } });

// ---- people
const memberOptions = computed(() => userOptions.value.filter((o) => !f.value.members.some((m) => m.id === o.v)));
function addMember(id) {
  const u = lookups.value.users.find((x) => x.id === id);
  if (u && !f.value.members.some((m) => m.id === id)) f.value.members.push({ id, name: personName(u) });
}
const dropMember = (id) => { f.value.members = f.value.members.filter((m) => m.id !== id); };

// ---- rules
const categoryOptions = (rule) => lookups.value.categories.filter((c) => !rule.targets.some((x) => x.id === c.id)).map((c) => ({ v: c.id, l: recordName(c) }));
function addCategory(rule, id) {
  const c = lookups.value.categories.find((x) => x.id === id);
  if (c && !rule.targets.some((x) => x.id === id)) rule.targets.push({ id, name: recordName(c) });
}
const addCustomer = (rule, c) => { if (!rule.targets.some((x) => x.id === c.id)) rule.targets.push({ id: c.id, name: `${recordName(c)} · ${c.code}` }); };
const dropTarget = (rule, id) => { rule.targets = rule.targets.filter((x) => x.id !== id); };
const setScope = (rule, scope) => { if (rule.scope !== scope) { rule.scope = scope; rule.targets = []; } };
const addRule = () => { const all = f.value.rules.findIndex((r) => r.scope === 'all'); f.value.rules.splice(all >= 0 ? all : f.value.rules.length, 0, { ...newRule(), scope: all >= 0 ? 'category' : 'all' }); };
const dropRule = (i) => { f.value.rules.splice(i, 1); };
function move(i, by) { const j = i + by; if (j < 0 || j >= f.value.rules.length) return; const rules = f.value.rules; [rules[i], rules[j]] = [rules[j], rules[i]]; }
const addTier = (rule) => { rule.tiers.splice(Math.max(0, rule.tiers.length - 1), 0, { upTo: null, rate: null }); };
const dropTier = (rule, i) => { rule.tiers.splice(i, 1); };

const SCOPES = Object.entries(SCOPE).map(([v, l]) => [v, l]);
const YES_NO = (yes, no) => [['yes', yes], ['no', no]];
const isRate = (v) => v !== null && v !== '' && !Number.isNaN(Number(v)) && Number(v) >= 0 && Number(v) <= 100;

/** What still keeps the form from being sent, in the order of the form — the first one is shown beside the button. */
const problems = computed(() => {
  const v = f.value;
  const out = [];
  if (v.nameAr.trim().length < 2) out.push(t('اكتب اسم الخطة', 'Type the plan’s name'));
  if (!v.validFrom) out.push(t('حدّد تاريخ بدء السريان', 'Set the day it starts'));
  if (v.validTo && v.validTo < v.validFrom) out.push(t('تاريخ النهاية قبل البداية', 'It ends before it starts'));
  if (locked.value) return out;
  if (v.earnerType === 'manager' && !v.beneficiary) out.push(t('اختر المدير المستحق للعمولة', 'Pick the manager who earns'));
  if (v.everyone === 'no' && !v.members.length) out.push(v.earnerType === 'manager' ? t('اختر مندوبي الفريق أو اجعلها على كل الفواتير', 'Pick the team or make it cover every invoice') : t('اختر المندوبين أو اجعلها لكل المندوبين', 'Pick the salespeople or make it the plan of everyone'));
  if (!v.rules.length) out.push(t('أضف قاعدة نسبة واحدة على الأقل', 'Add at least one rate'));
  v.rules.forEach((r, i) => {
    const n = i + 1;
    if (r.scope === 'all' && i < v.rules.length - 1) out.push(t(`القاعدة ${n}: «كل المبيعات» تكون الأخيرة`, `Rule ${n}: “all sales” must be the last`));
    if (r.scope !== 'all' && !r.targets.length) out.push(t(`القاعدة ${n}: اختر ${r.scope === 'category' ? 'فئة' : 'عميلًا'} واحدًا على الأقل`, `Rule ${n}: pick at least one ${r.scope === 'category' ? 'category' : 'customer'}`));
    if (r.rateType === 'flat') { if (!isRate(r.rate)) out.push(t(`القاعدة ${n}: اكتب النسبة (0 – 100)`, `Rule ${n}: type the rate (0 – 100)`)); return; }
    if (r.tiers.some((x) => !isRate(x.rate))) out.push(t(`القاعدة ${n}: لكل شريحة نسبة (0 – 100)`, `Rule ${n}: every tier needs a rate (0 – 100)`));
    if (r.tiers.slice(0, -1).some((x) => !(Number(x.upTo) > 0))) out.push(t(`القاعدة ${n}: لكل شريحة سقف عدا الأخيرة`, `Rule ${n}: every tier but the last needs a ceiling`));
    if (r.tiers.length && r.tiers[r.tiers.length - 1].upTo != null && r.tiers[r.tiers.length - 1].upTo !== '') out.push(t(`القاعدة ${n}: الشريحة الأخيرة بلا سقف`, `Rule ${n}: the last tier has no ceiling`));
  });
  return out;
});

function body() {
  const v = f.value;
  return {
    nameAr: v.nameAr.trim(), nameEn: v.nameEn.trim() || undefined, earnerType: v.earnerType, beneficiary: v.earnerType === 'manager' ? v.beneficiary : undefined,
    everyone: v.everyone === 'yes', members: v.everyone === 'yes' ? [] : v.members.map((m) => m.id), earnOn: v.earnOn, base: v.base, netOfReturns: v.netOfReturns === 'yes',
    validFrom: v.validFrom, validTo: v.validTo || null, notes: v.notes.trim() || undefined,
    rules: v.rules.map((r) => ({
      scope: r.scope, scopeIds: r.scope === 'all' ? [] : r.targets.map((x) => x.id), rateType: r.rateType, rate: r.rateType === 'flat' ? Number(r.rate) : null,
      tierMode: r.rateType === 'tiered' ? r.tierMode : null, tiers: r.rateType === 'tiered' ? r.tiers.map((x, i) => ({ upTo: i === r.tiers.length - 1 ? null : Number(x.upTo), rate: Number(x.rate) })) : null, label: r.label.trim() || undefined,
    })),
  };
}
async function submit() {
  const p = props.plan;
  const r = await act.run(() => (p ? api.patch(`${API}/plans/${encodeURIComponent(p.code)}`, body()) : api.postIdempotent(`${API}/plans`, body())),
    { success: (x) => (p ? t(`حُفظت الخطة ${x.code}`, `Plan ${x.code} saved`) : t(`أُنشئت الخطة ${x.code} مسودةً — فعّلها لتبدأ`, `Plan ${x.code} created as a draft — activate it to start`)) });
  if (r) { emit('done', r); emit('close'); }
}
const cap = (ar, en) => ({ ar, en });
</script>

<template>
  <Drawer :open="open" :width="660" :z-index="70" :title="plan ? { ar: `تعديل الخطة ${plan.code}`, en: `Edit plan ${plan.code}` } : { ar: 'خطة عمولات جديدة', en: 'New commission plan' }"
          :sub="{ ar: 'من يستحق · على ماذا · بأي نسبة · من متى إلى متى', en: 'Who earns · on what · at which rate · from when to when' }" @close="emit('close')">
    <div v-if="locked" class="hint amber !mt-0 mb-3">{{ t('كشوف صادرة عن هذه الخطة تجاوزت المسودة: شروطها تُعرض ولا تُعدَّل. يبقى مفتوحًا: الاسم، تاريخ النهاية، المندوبون، الملاحظات. لشروط جديدة أنهِ الخطة وانسخها.', 'Statements of this plan have left the draft state: its terms are shown, not edited. Still open: the name, the end date, the salespeople, the notes. For new terms, end the plan and duplicate it.') }}</div>

    <div class="form-grid">
      <TextInput v-model="f.nameAr" required :label="{ ar: 'اسم الخطة', en: 'Plan name (Arabic)' }" />
      <TextInput v-model="f.nameEn" dir="ltr" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" />
      <DateInput v-model="f.validFrom" required :disabled="locked" :label="{ ar: 'تسري من', en: 'Valid from' }" />
      <DateInput v-model="f.validTo" :min="f.validFrom" :label="{ ar: 'إلى (فارغ = مفتوحة)', en: 'To (empty = open-ended)' }" />
    </div>

    <!-- who earns -->
    <div class="card-title mt-4 mb-2">{{ t('من يستحق', 'Who earns') }}</div>
    <div v-if="locked" class="hint teal !mt-0 mb-2">{{ t(EARNER[f.earnerType].ar, EARNER[f.earnerType].en) }}<template v-if="plan && plan.beneficiary"> — {{ personName(plan.beneficiary) }}</template></div>
    <div v-else class="form-grid">
      <PillChoice v-model="f.earnerType" full :options="Object.entries(EARNER)" :label="{ ar: 'نوع الخطة', en: 'Kind of plan' }" />
      <SelectInput v-if="f.earnerType === 'manager'" v-model="f.beneficiary" full required :label="{ ar: 'المدير المستحق للعمولة', en: 'The manager who earns' }" :options="userOptions" :placeholder="SELECT_PLACEHOLDER" />
      <PillChoice v-model="f.everyone" full :label="f.earnerType === 'manager' ? { ar: 'على مبيعات من', en: 'On whose sales' } : { ar: 'تشمل من', en: 'Who is on it' }"
                  :options="f.earnerType === 'manager' ? YES_NO({ ar: 'كل الفواتير', en: 'Every invoice' }, { ar: 'فريق محدد', en: 'A named team' }) : YES_NO({ ar: 'كل المندوبين', en: 'Every salesperson' }, { ar: 'مندوبون محددون', en: 'Named salespeople' })" />
    </div>
    <div v-if="f.everyone === 'no'" class="field full mt-2">
      <div class="field-l">{{ f.earnerType === 'manager' ? t('مندوبو الفريق', 'The team’s salespeople') : t('المندوبون المشمولون', 'Salespeople on the plan') }}<span class="text-bad"> *</span></div>
      <div v-if="f.members.length" class="flex flex-wrap gap-1.5">
        <span v-for="m in f.members" :key="m.id" class="pill active max-w-full"><span class="min-w-0 truncate" dir="auto">{{ m.name }}</span><button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-inherit" :aria-label="t('إزالة', 'Remove')" @click="dropMember(m.id)">✕</button></span>
      </div>
      <SelectInput model-value="" :options="memberOptions" :placeholder="{ ar: '+ إضافة مندوب', en: '+ Add a salesperson' }" @update:model-value="addMember" />
    </div>

    <!-- on what -->
    <div class="card-title mt-4 mb-2">{{ t('على ماذا', 'On what') }}</div>
    <div v-if="locked && plan" class="hint teal !mt-0">{{ termsText(plan) }}</div>
    <div v-else class="form-grid">
      <PillChoice v-model="f.earnOn" full :options="Object.entries(EARN_ON)" :label="{ ar: 'متى تُستحق', en: 'When it is earned' }"
                  :hint="f.earnOn === 'collected' ? { ar: 'الفاتورة تُحتسب يوم سدادها كاملًا؛ المسدَّدة جزئيًا لا تستحق شيئًا بعد', en: 'An invoice counts the day it is fully paid; a partly paid one has earned nothing yet' } : null" />
      <PillChoice v-model="f.base" full :options="Object.entries(BASE)" :label="{ ar: 'أساس النسبة', en: 'Base of the rate' }" :hint="f.base === 'margin' ? { ar: 'الصافي قبل الضريبة ناقص تكلفة البضاعة المباعة', en: 'Net before tax less the cost of the goods sold' } : null" />
      <PillChoice v-model="f.netOfReturns" full :options="YES_NO({ ar: 'نعم — الإشعار الدائن يخصم حصته', en: 'Yes — a credit note takes its share back' }, { ar: 'لا', en: 'No' })" :label="{ ar: 'صافية من المرتجعات', en: 'Net of returns' }" />
    </div>

    <!-- at which rate -->
    <div class="mt-4 mb-2 flex items-center gap-2"><div class="card-title">{{ t('بأي نسبة', 'At which rate') }}</div><Btn v-if="!locked" size="sm" tone="softPurple" :label="{ ar: '+ قاعدة', en: '+ Rule' }" @click="addRule" /></div>
    <div v-if="locked && plan" class="overflow-hidden rounded-xl border border-line-2">
      <div v-for="(r, i) in plan.rules" :key="r.id" class="flex flex-wrap items-baseline gap-x-2 border-t border-line-2 px-3 py-2 text-[11px] first:border-t-0"><span class="num text-muted">{{ i + 1 }}</span><span class="min-w-0 flex-1" dir="auto">{{ scopeText(r) }}</span><b class="num ltr">{{ rateText(r) }}</b></div>
    </div>
    <template v-else>
      <div v-for="(r, i) in f.rules" :key="i" class="mb-2 rounded-2xl border border-line-2 bg-canvas p-3">
        <div class="mb-2 flex flex-wrap items-center gap-1.5">
          <span class="num flex h-6 w-6 flex-none items-center justify-center rounded-full bg-night text-[10px] font-bold text-white">{{ i + 1 }}</span>
          <span class="min-w-0 flex-1 text-[10.5px] font-bold text-sec">{{ i === 0 ? t('تُطبَّق أولًا', 'Applied first') : t('إن لم تنطبق قاعدة قبلها', 'When no rule above applies') }}</span>
          <Btn size="sm" tone="ghost" class="!h-7 !px-2" :disabled="i === 0" :title="t('تقديم', 'Move up')" @click="move(i, -1)">↑</Btn>
          <Btn size="sm" tone="ghost" class="!h-7 !px-2" :disabled="i === f.rules.length - 1" :title="t('تأخير', 'Move down')" @click="move(i, 1)">↓</Btn>
          <Btn size="sm" tone="dangerOutline" class="!h-7 !text-[9px]" :disabled="f.rules.length === 1" :label="{ ar: 'حذف', en: 'Remove' }" @click="dropRule(i)" />
        </div>
        <div class="form-grid">
          <SelectInput :model-value="r.scope" :label="cap('تشمل', 'Covers')" :options="SCOPES" @update:model-value="(v) => setScope(r, v)" />
          <TextInput v-model="r.label" :label="cap('وصف يظهر في الكشف', 'Label shown on statements')" :placeholder="{ ar: 'اختياري', en: 'Optional' }" />
          <div v-if="r.scope !== 'all'" class="field full">
            <div v-if="r.targets.length" class="flex flex-wrap gap-1.5">
              <span v-for="x in r.targets" :key="x.id" class="pill active max-w-full"><span class="min-w-0 truncate" dir="auto">{{ x.name }}</span><button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-inherit" :aria-label="t('إزالة', 'Remove')" @click="dropTarget(r, x.id)">✕</button></span>
            </div>
            <SelectInput v-if="r.scope === 'category'" model-value="" :options="categoryOptions(r)" :label="cap('فئات المنتجات (تشمل فئاتها الفرعية)', 'Product categories (sub-categories included)')" :placeholder="{ ar: '+ إضافة فئة', en: '+ Add a category' }" @update:model-value="(id) => addCategory(r, id)" />
            <CustomerPick v-else :label="cap('العملاء', 'Customers')" :exclude="r.targets.map((x) => x.id)" @pick="(c) => addCustomer(r, c)" />
          </div>
          <PillChoice v-model="r.rateType" full :label="cap('النسبة', 'Rate')" :options="[['flat', { ar: 'نسبة ثابتة', en: 'Flat rate' }], ['tiered', { ar: 'شرائح على أساس الشهر', en: 'Tiers on the month’s base' }]]" />
          <NumberInput v-if="r.rateType === 'flat'" v-model="r.rate" required :min="0" :max="100" :label="cap('النسبة %', 'Rate %')" />
          <template v-else>
            <div class="field full">
              <div v-for="(x, ti) in r.tiers" :key="ti" class="mb-1.5 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_34px] items-end gap-2">
                <NumberInput v-if="ti < r.tiers.length - 1" v-model="x.upTo" :min="0" :label="cap('حتى مبلغ', 'Up to')" />
                <div v-else class="field"><div class="field-l">{{ t('حتى', 'Up to') }}</div><div class="inp flex items-center text-[11px] text-muted">{{ r.tiers.length > 1 ? t('ما فوق ذلك', 'Above that') : t('أي مبلغ', 'Any amount') }}</div></div>
                <NumberInput v-model="x.rate" :min="0" :max="100" :label="cap('النسبة %', 'Rate %')" />
                <button v-if="ti < r.tiers.length - 1" type="button" class="x-btn mb-px" :aria-label="t('حذف الشريحة', 'Remove the tier')" @click="dropTier(r, ti)">✕</button><span v-else />
              </div>
              <Btn size="sm" tone="soft" class="!h-7 self-start !text-[9.5px]" :label="{ ar: '+ شريحة', en: '+ Tier' }" @click="addTier(r)" />
            </div>
            <SelectInput v-model="r.tierMode" full :label="cap('طريقة الشرائح', 'How tiers pay')" :options="Object.entries(TIER_MODE)" />
          </template>
        </div>
      </div>
    </template>

    <TextArea v-model="f.notes" field-class="mt-3" :rows="2" :label="{ ar: 'ملاحظات', en: 'Notes' }" />

    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="problems.length > 0" :label="plan ? { ar: 'حفظ الخطة', en: 'Save the plan' } : { ar: 'إنشاء الخطة (مسودة)', en: 'Create the plan (draft)' }" @click="submit" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div v-if="problems.length && !act.pending.value" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">{{ problems[0] }}<span v-if="problems.length > 1" class="font-normal"> {{ t(`(و${problems.length - 1} غيرها)`, `(and ${problems.length - 1} more)`) }}</span></div>
    </template>
  </Drawer>
</template>
