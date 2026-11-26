<script setup>
// A price list: new, changed, or a copy of another one.
//   <ListForm :open="creating" @close @done="(list) => …" />                       POST  /pricing/lists
//   <ListForm :open="!!editing" :list="editing" @close @done />                    PATCH /pricing/lists/{id}
//   <ListForm :open="!!copying" :list="copying" copy @close @done />               POST  /pricing/lists/{id}/duplicate
// A copy takes the active rules of its source into a list of OPS — the way to price differently from a list Odoo owns.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { usePricingLookups } from './pricing';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The list being changed or copied; null = a new one. */
  list: { type: Object, default: null },
  copy: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'done']);

const { currencies } = usePricingLookups();
const YES_NO = [['no', { ar: 'لا', en: 'No' }], ['yes', { ar: 'نعم — قائمة كل عميل بلا قائمة خاصة', en: 'Yes — the list of every customer without one of its own' }]];

const fields = computed(() => (props.copy
  ? [
    { k: 'name', label: { ar: 'اسم النسخة', en: 'Name of the copy' }, required: true, full: true },
    { k: 'note', label: { ar: 'ملاحظة', en: 'Note' }, type: 'area' },
  ]
  : [
    { k: 'name', label: { ar: 'اسم القائمة', en: 'List name' }, required: true, full: true, ph: { ar: 'مثال: مطاعم الرياض 2027', en: 'e.g. Riyadh restaurants 2027' } },
    { k: 'currency', label: { ar: 'العملة', en: 'Currency' }, type: 'select', required: true, opts: currencies.value.map((c) => [c, c]),
      hint: props.list ? { ar: 'لا تتغيّر عملة قائمة فيها قواعد', en: 'A list that has rules keeps its currency' } : null },
    ...(props.list ? [] : [{ k: 'isDefault', label: { ar: 'القائمة الافتراضية؟', en: 'The default list?' }, type: 'select', required: true, opts: YES_NO }]),
    { k: 'validFrom', label: { ar: 'سارية من', en: 'Valid from' }, type: 'date', validate: (v, all) => (v && all.validTo && v > all.validTo ? t('بعد تاريخ النهاية', 'After the end date') : null) },
    { k: 'validTo', label: { ar: 'سارية حتى', en: 'Valid until' }, type: 'date', hint: { ar: 'خارج مدتها لا تُسعِّر القائمة أحدًا', en: 'Outside its validity a list prices nobody' } },
    { k: 'note', label: { ar: 'ملاحظة', en: 'Note' }, type: 'area' },
  ]));

const initial = computed(() => {
  const l = props.list;
  if (props.copy) return { name: l ? `${l.name} — ${t('نسخة', 'copy')}` : '' };
  return l ? { name: l.name, currency: l.currency, validFrom: l.validFrom || '', validTo: l.validTo || '', note: l.note || '' } : { currency: 'SAR', isDefault: 'no' };
});

function submit(v) {
  if (props.copy) return api.postIdempotent(`/pricing/lists/${encodeURIComponent(props.list.id)}/duplicate`, { name: v.name, note: v.note });
  // an emptied date or note is sent as "none" (the form leaves empty fields out)
  const body = { name: v.name, currency: v.currency, validFrom: v.validFrom || null, validTo: v.validTo || null, note: v.note || null };
  return props.list ? api.patch(`/pricing/lists/${encodeURIComponent(props.list.id)}`, body) : api.postIdempotent('/pricing/lists', { ...body, isDefault: v.isDefault === 'yes' });
}
const title = computed(() => (props.copy ? { ar: 'نسخ القائمة', en: 'Copy the list' } : props.list ? { ar: 'تعديل القائمة', en: 'Edit the list' } : { ar: 'قائمة أسعار جديدة', en: 'New price list' }));
const sub = computed(() => (props.copy
  ? { ar: 'تُنسخ القواعد الفعّالة إلى قائمة خاصة بالنظام تُعدَّل هنا بحرية', en: 'The active rules are copied into a list of OPS that is edited here freely' }
  : props.list ? `${props.list.code}` : null));
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit"
              :submit-label="copy ? { ar: 'إنشاء النسخة', en: 'Create the copy' } : null"
              :action="{ success: copy ? { ar: 'أُنشئت النسخة ✓', en: 'Copy created ✓' } : list ? { ar: 'حُفظت القائمة ✓', en: 'List saved ✓' } : { ar: 'أُنشئت القائمة ✓', en: 'List created ✓' }, invalidate: ['pricing'] }"
              @close="emit('close')" @done="(r) => emit('done', r)" />
</template>
