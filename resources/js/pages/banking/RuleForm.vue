<script setup>
// A write-off rule — add (POST /banking/rules) or edit (PATCH /banking/rules/{id}, only what changed): its names, the
// direction of the difference it absorbs (in / out / both), the ledger account it posts to (lookups.accounts: postable,
// not a control account, not a bank's), its label on the journal, its limit, the words that make it propose itself for
// a whole line (a bank charge), the bank it belongs to (empty = every bank), its order, and whether it is on.
// A rule of Odoo (`owned`) may only have its OPS account changed. Without bank.reconcile the form is read-only.
//   <RuleForm :open="open" :rule="ruleOrNull" @close="…" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, NumberInput, PillChoice, SelectInput, TextInput } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { NATURE, OWNED_CHIP, OWNED_HELP, isMoney, nameOf, optionsOf, toCents, useBankingLookups } from './banking';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The rule to edit (a row of GET /banking/rules); null = a new one. */
  rule: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const lk = useBankingLookups();
const act = useAction({ invalidate: ['banking'] });
const edit = computed(() => !!props.rule);
const owned = computed(() => !!props.rule?.owned);
const canEdit = computed(() => auth.can('bank.reconcile'));
/** Everything but the account is locked on a rule Odoo owns, and all of it without the permission. */
const locked = computed(() => !canEdit.value || owned.value);

const blank = () => ({ nameAr: '', nameEn: '', nature: 'both', account: '', label: '', maxAmount: '', matchText: '', bankAccount: '', sequence: null, active: true });
const fromRule = (r) => ({
  nameAr: r.nameAr || '', nameEn: r.nameEn || '', nature: r.nature || 'both', account: r.account?.code || '', label: r.label || '', maxAmount: r.maxAmount ?? '',
  matchText: r.matchText || '', bankAccount: r.bank?.code || '', sequence: r.sequence ?? null, active: !!r.active,
});
const v = reactive(blank());
let initial = blank();
const errors = ref({});
watch(() => [props.open, props.rule], () => {
  if (!props.open) return;
  initial = props.rule ? fromRule(props.rule) : blank();
  Object.assign(v, { ...initial });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const accountOptions = computed(() => {
  const list = [...lk.accountOptions.value];
  const mine = props.rule?.account;
  if (mine && !list.some((o) => o.v === mine.code)) list.unshift({ v: mine.code, l: `${mine.code} · ${nameOf(mine)} (${t('لا يقبل الترحيل الآن', 'not postable now')})` });
  return list;
});
const natures = optionsOf(NATURE);

function sent(k, x) {
  if (k === 'sequence') return x == null || x === '' ? null : Math.trunc(Number(x));
  if (k === 'active') return !!x;
  if (k === 'maxAmount') return String(x ?? '').trim() || null;
  return typeof x === 'string' ? x.trim() || null : x;
}
function validate() {
  const errs = {};
  const need = t('هذا الحقل إلزامي', 'Required');
  if (!owned.value) {
    if (!v.nameAr.trim()) errs.nameAr = need;
    if (!String(v.maxAmount).trim()) errs.maxAmount = need;
    else if (!isMoney(v.maxAmount) || toCents(v.maxAmount) <= 0) errs.maxAmount = t('مبلغ أكبر من صفر بخانتين عشريتين على الأكثر', 'An amount above zero, two decimals at most');
    if (!v.account) errs.account = need;
  }
  errors.value = errs;
  return !Object.keys(errs).length;
}
async function submit() {
  if (!validate()) return;
  let body;
  if (!edit.value) {
    body = Object.fromEntries(Object.entries(v).filter(([k]) => k !== 'active').map(([k, x]) => [k, sent(k, x)]).filter(([, x]) => x !== null));
  } else {
    const keys = owned.value ? ['account'] : Object.keys(v);
    body = {};
    for (const k of keys) {
      const now = sent(k, v[k]);
      const was = sent(k, initial[k]);
      const same = k === 'maxAmount' ? toCents(now) === toCents(was) && (now === null) === (was === null) : now === was;
      if (!same) body[k] = now;
    }
    if (!Object.keys(body).length) { emit('close'); return; }
  }
  const r = await act.run(() => (edit.value ? api.patch(`/banking/rules/${props.rule.id}`, body) : api.postIdempotent('/banking/rules', body)), {
    success: (x) => (edit.value ? t(`حُفظت القاعدة «${x.nameAr}»`, `Rule “${x.nameEn || x.nameAr}” saved`) : t(`أُضيفت القاعدة «${x.nameAr}»`, `Rule “${x.nameEn || x.nameAr}” added`)),
  });
  if (r) emit('close');
}
const title = computed(() => (edit.value ? { ar: `قاعدة التسوية: ${props.rule.nameAr}`, en: `Write-off rule: ${props.rule.nameEn || props.rule.nameAr}` } : { ar: 'قاعدة تسوية جديدة', en: 'New write-off rule' }));
</script>

<template>
  <Drawer :open="open" :width="600" :z-index="72" :title="title" @close="emit('close')">
    <template v-if="rule?.owned" #headExtra><Chip :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" /></template>
    <div v-if="owned" class="hint purple !mt-0 mb-3">{{ bi(OWNED_HELP) }} {{ t('حساب الأستاذ الذي ترحّل إليه القاعدة في OPS خيار OPS نفسه — يُحدَّد هنا.', 'The OPS ledger account the rule posts to is OPS’s own choice — it is set here.') }}</div>
    <div v-if="rule && !rule.usable && rule.unusable" class="hint amber !mt-0 mb-3">{{ t('لا ترحّل الآن', 'Cannot post now') }}: {{ t(rule.unusable.ar, rule.unusable.en) }}</div>
    <div class="form-grid">
      <TextInput v-model="v.nameAr" required :disabled="locked" :label="{ ar: 'الاسم بالعربية', en: 'Name (Arabic)' }" :placeholder="{ ar: 'مثال: رسوم بنكية', en: 'e.g. رسوم بنكية' }" :error="errors.nameAr" />
      <TextInput v-model="v.nameEn" :disabled="locked" dir="ltr" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" :placeholder="{ ar: 'Bank charges', en: 'Bank charges' }" :error="errors.nameEn" />
      <PillChoice v-model="v.nature" full :options="natures" :label="{ ar: 'الفرق الذي تقبله', en: 'The difference it accepts' }" :class="{ 'pointer-events-none opacity-60': locked }" :error="errors.nature" />
      <SelectInput v-model="v.account" full required :disabled="!canEdit" :options="accountOptions" :label="{ ar: 'حساب الأستاذ الذي يُرحَّل إليه الفرق', en: 'Ledger account the difference is posted to' }" :placeholder="{ ar: '— اختر —', en: '— choose —' }" :error="errors.account" />
      <TextInput v-model="v.maxAmount" required :disabled="locked" dir="ltr" inputmode="decimal" :label="{ ar: 'أقصى مبلغ تسوّيه', en: 'Largest amount it may write off' }" placeholder="0.00" :error="errors.maxAmount" />
      <SelectInput v-model="v.bankAccount" :disabled="locked" :options="lk.bankOptions.value" :label="{ ar: 'الحساب البنكي', en: 'Bank account' }" :placeholder="{ ar: '— كل الحسابات —', en: '— every account —' }" :error="errors.bankAccount" />
      <TextInput v-model="v.label" full :disabled="locked" :label="{ ar: 'بيان القيد (اختياري)', en: 'Journal label (optional)' }" :error="errors.label" />
      <TextInput v-model="v.matchText" full :disabled="locked" :label="{ ar: 'كلمات في نص السطر تجعلها تقترح نفسها (مفصولة بفواصل)', en: 'Words of a line that make it propose itself (comma separated)' }" :placeholder="{ ar: 'رسوم, عمولة, charge, fee', en: 'charge, fee, رسوم' }" :hint="{ ar: 'لسطر كامل بلا مستند في الدفاتر، مثل الرسوم والعمولات', en: 'For a whole line with no document in the books, such as charges and fees' }" :error="errors.matchText" />
      <NumberInput v-model="v.sequence" :disabled="locked" :min="0" :max="100000" :step="10" :label="{ ar: 'الترتيب (الأصغر أولًا)', en: 'Order (lowest first)' }" :hint="edit ? null : { ar: 'يُترك فارغًا ليأتي بعد الموجود', en: 'Empty = after the existing ones' }" :error="errors.sequence" />
      <label v-if="edit" class="row !gap-1.5 self-end pb-2 text-[11px]" :class="{ 'opacity-60': locked }"><input v-model="v.active" type="checkbox" :disabled="locked"> {{ t('فعّالة', 'Active') }}</label>
    </div>
    <div v-if="rule" class="mt-3 text-[9.5px] text-faint">
      <template v-if="rule.createdBy">{{ t('أنشأها', 'Created by') }} <span dir="auto">{{ rule.createdBy }}</span> · <span class="num ltr">{{ fmtDate(rule.createdAt) }}</span></template>
      <template v-if="rule.updatedAt"> · {{ t('آخر تعديل', 'last change') }} <span class="num ltr">{{ fmtDate(rule.updatedAt) }}</span></template>
    </div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn v-if="canEdit" tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="edit ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إضافة القاعدة', en: 'Add rule' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="canEdit ? { ar: 'إلغاء', en: 'Cancel' } : { ar: 'إغلاق', en: 'Close' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
