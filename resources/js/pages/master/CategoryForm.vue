<script setup>
// Category create / edit: nameAr*, nameEn, code (latin), active.
//   mode 'root' → new root category · 'sub' → new child of `parent` · 'edit' → PATCH `category`
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { serverMsg } from './_shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  mode: { type: String, default: 'root' },
  /** @type {import('./_shared').Category} */
  parent: { type: Object, default: null },
  /** @type {import('./_shared').Category} */
  category: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const title = computed(() => (props.mode === 'root' ? { ar: 'تصنيف رئيسي جديد', en: 'New root category' }
  : props.mode === 'sub' ? { ar: `تصنيف فرعي جديد تحت ${props.parent?.nameAr || ''}`, en: `New sub-category under ${props.parent?.nameEn || props.parent?.nameAr || ''}` }
    : { ar: `تعديل تصنيف — ${props.category?.nameAr || ''}`, en: `Edit category — ${props.category?.nameAr || ''}` }));

const fields = computed(() => [
  { k: 'nameAr', label: { ar: 'الاسم (عربي)', en: 'Name (Arabic)' }, required: true }, { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, dir: 'ltr' },
  { k: 'code', label: { ar: 'الرمز (لاتيني)', en: 'Code (latin)' }, required: props.mode !== 'edit', dir: 'ltr', ph: 'dry_food', validate: (x) => (x && !/^[a-z0-9_-]{2,}$/i.test(String(x)) ? t('الرمز حروف لاتينية وأرقام فقط', 'Latin letters / digits only') : null) },
  { k: 'active', label: { ar: 'الحالة', en: 'Status' }, type: 'select', opts: [['1', { ar: 'نشط', en: 'Active' }], ['0', { ar: 'موقوف', en: 'Inactive' }]], def: '1' },
]);
const initial = computed(() => (props.mode === 'edit' && props.category
  ? { nameAr: props.category.nameAr, nameEn: props.category.nameEn || '', code: props.category.code, active: props.category.active ? '1' : '0' }
  : { active: '1' }));

function submit(v) {
  const body = { nameAr: v.nameAr, nameEn: v.nameEn || undefined, code: v.code || undefined, active: v.active !== '0', ...(props.mode === 'sub' ? { parentId: props.parent?.id } : {}) };
  return props.mode === 'edit' ? api.patch(`/categories/${props.category.id}`, body) : api.postIdempotent('/categories', body);
}
</script>

<template>
  <FormDrawer :open="open" :title="title" :fields="fields" :initial="initial" :width="460" :submit="submit"
              :action="{ success: (d) => serverMsg(d, { ar: 'حُفظ التصنيف', en: 'Category saved' }), invalidate: ['categories', 'master', 'products'] }"
              @close="emit('close')" @done="emit('done', $event)" />
</template>
