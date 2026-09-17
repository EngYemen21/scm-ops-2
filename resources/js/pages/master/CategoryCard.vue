<script setup>
// Root category card of the "manage categories" panel: name, "n SKUs", activate / deactivate, edit, sub-categories
// (each with its own toggle + edit) and "+ sub-category". Clicking a name filters the products table by that category.
//   <CategoryCard :c="category" :can-cat="auth.can('category.manage')" @edit="…" @add-sub="…" @filter="(code) => …" />
import { computed } from 'vue';
import { api, useAction, useInvalidate } from '@/api/client';
import { lang, t } from '@/i18n';

const props = defineProps({
  /** @type {import('./_shared').Category} */
  c: { type: Object, required: true },
  canCat: { type: Boolean, default: false },
});
const emit = defineEmits(['edit', 'add-sub', 'filter']);

const act = useAction({ invalidate: ['categories', 'master', 'products'] });
const invalidate = useInvalidate();
const name = (x) => (lang.value === 'ar' ? x.nameAr : x.nameEn || x.nameAr);
const subs = computed(() => props.c.children || []);
const total = computed(() => (props.c._count?.products || 0) + subs.value.reduce((s, x) => s + (x._count?.products || 0), 0));

async function toggle(x) {
  await act.run(() => api.patch(`/categories/${x.id}`, { active: !x.active }), { success: x.active ? { ar: `أُوقف التصنيف ${x.nameAr}`, en: `${x.nameAr} deactivated` } : { ar: `فُعّل التصنيف ${x.nameAr}`, en: `${x.nameAr} activated` } });
  void invalidate(['categories']);
}
</script>

<template>
  <div class="rounded-[13px] border border-line px-3.5 py-3" :class="{ 'opacity-55': !c.active }">
    <div class="row">
      <div class="cursor-pointer text-[12px] font-extrabold" @click="emit('filter', c.code)">{{ name(c) }}</div>
      <div class="num text-[9px] text-faint">{{ total }} {{ t('صنف', 'SKUs') }}</div>
      <span class="grow" />
      <span v-if="canCat" class="cursor-pointer text-[9px] font-extrabold" :class="c.active ? 'text-bad' : 'text-ok'" @click="toggle(c)">{{ c.active ? t('إيقاف', 'Deactivate') : t('تفعيل', 'Activate') }}</span>
      <span v-if="canCat" class="cursor-pointer text-[9px] font-extrabold text-violet" @click="emit('edit', c)">{{ t('تعديل', 'Edit') }}</span>
    </div>
    <div class="col mt-2 !gap-1">
      <div v-for="s in subs" :key="s.id" class="row !gap-[7px] border-t border-dashed border-line-2 py-1 text-[10.5px]">
        <span class="h-1.5 w-1.5 flex-none rounded-full bg-brand" />
        <span class="grow cursor-pointer font-bold text-sec" :class="{ 'line-through': !s.active }" @click="emit('filter', s.code)">{{ name(s) }}</span>
        <span class="num text-[9px] text-faint">{{ s._count?.products || 0 }}</span>
        <span v-if="canCat" class="cursor-pointer text-[8.5px] font-extrabold" :class="s.active ? 'text-bad' : 'text-ok'" @click="toggle(s)">{{ s.active ? t('إيقاف', 'Off') : t('تفعيل', 'On') }}</span>
        <span v-if="canCat" class="cursor-pointer text-[8.5px] font-extrabold text-violet" @click="emit('edit', s)">{{ t('تعديل', 'Edit') }}</span>
      </div>
      <span v-if="canCat" class="mt-1 cursor-pointer text-[9px] font-extrabold text-brand-dark" @click="emit('add-sub', c)">{{ t('+ تصنيف فرعي', '+ Sub-category') }}</span>
    </div>
  </div>
</template>
