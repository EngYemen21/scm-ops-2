<script setup>
// Create / change a payment method (pos.manage). Every method that moves money is bound to a finance account: cash → a
// cash box, card / transfer → a bank account; "on account" moves no money and has none.
//   POST /pos/payment-methods { code, nameAr, nameEn?, kind, bankAccount?, requiresReference?, sequence? }
//   PATCH /pos/payment-methods/{id} { nameAr?, nameEn?, kind?, bankAccount?, requiresReference?, sequence?, active? }
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { METHOD_KIND, usePosLookups } from './pos';

const props = defineProps({
  open: { type: Boolean, default: false },
  method: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = usePosLookups();
const editing = computed(() => !!props.method);
const YES_NO = [['yes', { ar: 'نعم', en: 'Yes' }], ['no', { ar: 'لا', en: 'No' }]];
const flag = (b) => (b ? 'yes' : 'no');
const kinds = computed(() => (lk.data.value?.kinds || Object.keys(METHOD_KIND)).map((k) => [k, METHOD_KIND[k] ? { ar: METHOD_KIND[k].ar, en: METHOD_KIND[k].en } : k]));
/** Accounts that fit the kind: a cash box for cash, a bank account for card / transfer. */
const accountsFor = (kind) => lk.bankAccounts.value.filter((b) => (kind === 'cash' ? b.kind === 'cash' : b.kind !== 'cash'))
  .map((b) => ({ v: b.id, l: { ar: `${b.code} · ${b.nameAr}`, en: `${b.code} · ${b.nameEn || b.nameAr}` } }));

const fields = computed(() => [
  ...(editing.value ? [] : [{ k: 'code', label: { ar: 'الرمز', en: 'Code' }, required: true, dir: 'ltr', ph: { ar: 'مثال: MADA', en: 'e.g. MADA' }, validate: (v) => (/^[A-Za-z0-9_-]{2,20}$/.test(String(v || '')) ? null : t('حروف لاتينية وأرقام و - _ فقط (2–20)', 'Latin letters, digits, - and _ only (2–20)')) }]),
  { k: 'nameAr', label: { ar: 'الاسم', en: 'Name (AR)' }, required: true },
  { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (EN)' }, dir: 'ltr' },
  { k: 'kind', label: { ar: 'النوع', en: 'Kind' }, type: 'select', required: true, opts: kinds.value, def: 'cash' },
  { k: 'bankAccount', label: { ar: 'الحساب في المالية', en: 'Finance account' }, type: 'select', required: true, opts: (v) => accountsFor(v.kind), visible: (v) => v.kind !== 'account',
    hint: { ar: 'النقد → صندوق نقد · البطاقة والتحويل → حساب بنكي', en: 'Cash → a cash box · card and transfer → a bank account' } },
  { k: 'requiresReference', label: { ar: 'يحتاج مرجعًا؟', en: 'Needs a reference?' }, type: 'select', opts: YES_NO, hint: { ar: 'رقم العملية من جهاز الشبكة أو الحوالة', en: 'The transaction number of the terminal or the transfer' } },
  { k: 'sequence', label: { ar: 'الترتيب', en: 'Order' }, type: 'num', min: 0, max: 1000, step: 1 },
  ...(editing.value ? [{ k: 'active', label: { ar: 'نشطة؟', en: 'Active?' }, type: 'select', opts: YES_NO }] : []),
]);
const initial = computed(() => {
  const m = props.method;
  if (!m) return null;
  return { nameAr: m.nameAr, nameEn: m.nameEn || '', kind: m.kind, bankAccount: m.bankAccountId || '', requiresReference: flag(m.requiresReference), sequence: m.sequence ?? null, active: flag(m.active) };
});
function submit(v) {
  const body = { nameAr: v.nameAr, kind: v.kind };
  if (v.nameEn) body.nameEn = v.nameEn; else if (editing.value) body.nameEn = null;
  if (v.kind !== 'account') body.bankAccount = v.bankAccount;
  if (v.requiresReference) body.requiresReference = v.requiresReference === 'yes';
  if (v.sequence != null) body.sequence = Math.max(0, Math.floor(Number(v.sequence) || 0));
  if (editing.value) {
    body.active = v.active !== 'no';
    return api.patch(`/pos/payment-methods/${encodeURIComponent(props.method.id)}`, body);
  }
  body.code = String(v.code).trim().toUpperCase();
  return api.postIdempotent('/pos/payment-methods', body);
}
const title = computed(() => (editing.value ? { ar: `تعديل ${props.method.code}`, en: `Change ${props.method.code}` } : { ar: 'طريقة دفع جديدة', en: 'New payment method' }));
</script>

<template>
  <FormDrawer :open="open" :title="title" :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: t('حُفظت طريقة الدفع', 'Payment method saved'), invalidate: ['pos'] }" @close="emit('close')" @done="(r) => emit('done', r)" />
</template>
