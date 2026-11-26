<script setup>
// A new favourite list for a customer — or the header of an existing one (name, the branch it is kept for, notes).
// On a list that Odoo owns only the branch and the notes are OPS's own; its name is greyed out.
//   <FavoriteListForm :open="open" :list="headerOrNull" @close="…" @done="(list) => …" />
// POST /portal/favorites/lists · PATCH /portal/favorites/lists/{id}. Products are added from the list itself.
import { computed, ref, watch } from 'vue';
import { api, useList } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import CustomerPicker from './CustomerPicker.vue';
import { customerLine } from './portal';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The list as GET /portal/favorites/lists/{id} gives it; null = a new list. */
  list: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const edit = computed(() => !!props.list);
const owned = computed(() => !!props.list?.owned);
/** The customer whose branches are offered: the list's own, or the one being picked for a new list. */
const customer = ref('');
watch(() => props.open, (o) => { if (o) customer.value = props.list?.customer?.linked ? props.list.customer.code : ''; }, { immediate: true });
const branches = useList('/portal/branches', () => ({ customer: customer.value, pageSize: 200, sort: 'sequence', order: 'asc' }), { enabled: () => props.open && !!customer.value });
const branchOptions = computed(() => (branches.data.value?.items || []).map((b) => ({ v: b.id, l: b.city ? `${b.name} · ${b.city}` : b.name })));

const fields = computed(() => [
  ...(edit.value ? [] : [{ k: 'customerId', label: { ar: 'العميل', en: 'Customer' }, required: true, full: true }]),
  { k: 'name', label: { ar: 'اسم القائمة', en: 'List name' }, required: true, full: true, disabled: owned.value, hint: owned.value ? { ar: 'يُدار من أودو', en: 'Managed in Odoo' } : null },
  { k: 'branchId', label: { ar: 'الفرع الذي تخصه القائمة', en: 'Branch the list is kept for' }, type: 'select', opts: branchOptions.value, ph: { ar: '— كل فروع العميل —', en: '— the whole customer —' }, full: true,
    disabled: edit.value && !props.list.can.assign,
    hint: !customer.value ? { ar: 'اختر العميل لعرض فروعه', en: 'Pick the customer to see its branches' } : !branchOptions.value.length ? { ar: 'لا فروع نشطة مسجَّلة لهذا العميل', en: 'This customer has no active branch on record' } : null },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area' },
]);
const initial = computed(() => (props.list ? { name: props.list.name, branchId: props.list.branch?.id || '', notes: props.list.notes || '' } : null));
const title = computed(() => (edit.value ? { ar: `تعديل القائمة «${props.list.name}»`, en: `Edit list “${props.list.name}”` } : { ar: 'قائمة مفضلة جديدة', en: 'New favourite list' }));
const sub = computed(() => (edit.value ? customerLine(props.list.customer) : { ar: 'تُضاف المنتجات من القائمة بعد إنشائها', en: 'Products are added from the list once it exists' }));

function submit(values) {
  if (!edit.value) return api.postIdempotent('/portal/favorites/lists', values);
  // an emptied field is sent as null, so it is really cleared; what Odoo owns is not sent at all
  const body = { branchId: values.branchId || null, notes: values.notes || null };
  if (!owned.value) body.name = values.name;
  if (!props.list.can.assign) delete body.branchId;
  return api.patch(`/portal/favorites/lists/${props.list.id}`, body);
}
const action = { success: () => t('حُفظت القائمة ✓', 'List saved ✓'), invalidate: ['portal'] };
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit" :action="action" @close="emit('close')" @done="(l) => emit('done', l)">
    <template #field-customerId="{ value, set }">
      <CustomerPicker :value="value || ''" :label="{ ar: 'العميل', en: 'Customer' }" required @pick="(c, code) => { set(c ? c.code : code); customer = c ? c.code : ''; }" />
    </template>
  </FormDrawer>
</template>
