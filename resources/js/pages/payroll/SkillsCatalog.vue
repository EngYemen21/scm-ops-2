<script setup>
// The skills catalogue (payroll.manage): GET /payroll/skills/catalog?all=1 → { types: [{ id, nameAr, nameEn, active, locked,
// skills[], levels[{ progress, isDefault }] }], resumeTypes[] }.
//   add   POST  /payroll/skills/{types|skills|levels|resume-types} { nameAr, nameEn?, typeId (skill, level), sequence?, progress?, isDefault?, active? }
//   edit  PATCH /payroll/skills/{kind}/{id}
// Nothing is deleted: an entry is switched off. What came from Odoo is Odoo's while the section follows it (no edit button).
//   <SkillsCatalog :open @close />
import { computed, ref } from 'vue';
import { api, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, FormDrawer } from '@/components';
import { t } from '@/i18n';
import { FROM_ODOO, INACTIVE_CHIP, YES_NO, bool, pname } from './payroll';

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const q = useGet(() => (props.open ? '/payroll/skills/catalog' : null), { all: 1 });
const types = computed(() => q.data.value?.types || []);
const resumeTypes = computed(() => q.data.value?.resumeTypes || []);

const SEGMENT = { type: 'types', skill: 'skills', level: 'levels', resumeType: 'resume-types' };
const TITLE = { type: ['نوع مهارة', 'Skill type'], skill: ['مهارة', 'Skill'], level: ['مستوى', 'Level'], resumeType: ['نوع سطر السيرة', 'Résumé line kind'] };
/** { kind, row (null = new), typeId } */
const form = ref(null);
const fields = computed(() => {
  const k = form.value?.kind;
  return [
    { k: 'nameAr', label: { ar: 'الاسم بالعربية', en: 'Name (Arabic)' }, required: true },
    { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (English)' }, dir: 'ltr' },
    ...(k === 'level' ? [{ k: 'progress', label: { ar: 'نسبة الإتقان %', en: 'Mastery %' }, type: 'num', min: 0, max: 100 }, { k: 'isDefault', label: { ar: 'المستوى الافتراضي للنوع', en: 'Default level of the type' }, type: 'select', opts: YES_NO }]
      : [{ k: 'sequence', label: { ar: 'الترتيب', en: 'Order' }, type: 'num', min: 0, max: 100000 }]),
    { k: 'active', label: { ar: 'مفعَّل', en: 'Active' }, type: 'select', opts: YES_NO },
  ];
});
const initial = computed(() => {
  const r = form.value?.row;
  return r ? { nameAr: r.nameAr, nameEn: r.nameEn, progress: r.progress ?? null, isDefault: r.isDefault ? '1' : '0', active: r.active === false ? '0' : '1', sequence: r.sequence ?? null } : { active: '1', isDefault: '0' };
});
function submit(v) {
  const f = form.value;
  const body = { nameAr: v.nameAr, nameEn: v.nameEn || null, active: bool(v.active) };
  if (f.kind === 'level') { if (v.progress != null) body.progress = Number(v.progress); body.isDefault = bool(v.isDefault); } else if (v.sequence != null) body.sequence = Number(v.sequence);
  if (!f.row && (f.kind === 'skill' || f.kind === 'level')) body.typeId = f.typeId;
  return f.row ? api.patch(`/payroll/skills/${SEGMENT[f.kind]}/${encodeURIComponent(f.row.id)}`, body) : api.postIdempotent(`/payroll/skills/${SEGMENT[f.kind]}`, body);
}
const edit = (kind, row = null, typeId = null) => { form.value = { kind, row, typeId }; };
const formTitle = computed(() => (form.value ? { ar: `${form.value.row ? 'تعديل' : 'إضافة'} ${TITLE[form.value.kind][0]}`, en: `${form.value.row ? 'Edit' : 'Add'} ${TITLE[form.value.kind][1].toLowerCase()}` } : null));
</script>

<template>
  <Drawer :open="open" :width="720" :title="{ ar: 'دليل المهارات', en: 'Skills catalogue' }" :sub="{ ar: 'أنواع المهارات ومهاراتها ومستوياتها، وأنواع سطور السيرة الذاتية', en: 'Skill types with their skills and levels, and the kinds of résumé line' }" @close="emit('close')">
    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-else>
      <div class="row mb-2"><b class="text-[12px]">{{ t('أنواع المهارات', 'Skill types') }}</b><span class="grow" /><Btn size="sm" tone="primary" :label="{ ar: '+ نوع', en: '+ Type' }" @click="edit('type')" /></div>
      <div v-if="!types.length" class="empty !p-3">{{ t('لا أنواع بعد.', 'No type yet.') }}</div>
      <div v-for="ty in types" :key="ty.id" class="mb-2.5 rounded-[14px] border border-line-2 p-3">
        <div class="row wrap">
          <b class="text-[12px]" dir="auto">{{ pname(ty) }}</b>
          <Chip v-if="!ty.active" small :label="INACTIVE_CHIP" :fg="INACTIVE_CHIP.fg" :bg="INACTIVE_CHIP.bg" />
          <Chip v-if="ty.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
          <span class="grow" />
          <Btn v-if="!ty.locked" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click="edit('type', ty)" />
        </div>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
          <div>
            <div class="row mb-1"><span class="field-l">{{ t('المهارات', 'Skills') }}</span><span class="grow" /><Btn size="sm" tone="softPurple" :label="{ ar: '+ مهارة', en: '+ Skill' }" @click="edit('skill', null, ty.id)" /></div>
            <div v-if="!ty.skills.length" class="text-[10px] text-faint">—</div>
            <div v-for="s in ty.skills" :key="s.id" class="flex items-center gap-1.5 py-0.5 text-[10.5px]">
              <span class="min-w-0 flex-1 truncate" :class="{ 'text-faint line-through': !s.active }" dir="auto">{{ pname(s) }}</span>
              <Chip v-if="s.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
              <button v-else type="button" class="text-[10px] font-bold text-violet" @click="edit('skill', s)">{{ t('تعديل', 'Edit') }}</button>
            </div>
          </div>
          <div>
            <div class="row mb-1"><span class="field-l">{{ t('المستويات', 'Levels') }}</span><span class="grow" /><Btn size="sm" tone="softPurple" :label="{ ar: '+ مستوى', en: '+ Level' }" @click="edit('level', null, ty.id)" /></div>
            <div v-if="!ty.levels.length" class="text-[10px] text-faint">—</div>
            <div v-for="l in ty.levels" :key="l.id" class="flex items-center gap-1.5 py-0.5 text-[10.5px]">
              <span class="min-w-0 flex-1 truncate" :class="{ 'text-faint line-through': !l.active }" dir="auto">{{ pname(l) }} <span class="num text-muted">{{ l.progress }}%</span><span v-if="l.isDefault" class="text-azure"> · {{ t('افتراضي', 'default') }}</span></span>
              <Chip v-if="l.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
              <button v-else type="button" class="text-[10px] font-bold text-violet" @click="edit('level', l)">{{ t('تعديل', 'Edit') }}</button>
            </div>
          </div>
        </div>
      </div>

      <div class="row mb-2 mt-4"><b class="text-[12px]">{{ t('أنواع سطور السيرة', 'Résumé line kinds') }}</b><span class="grow" /><Btn size="sm" tone="primary" :label="{ ar: '+ نوع سطر', en: '+ Kind' }" @click="edit('resumeType')" /></div>
      <div v-if="!resumeTypes.length" class="empty !p-3">{{ t('لا أنواع بعد.', 'No kind yet.') }}</div>
      <div class="row wrap !gap-1.5">
        <span v-for="r in resumeTypes" :key="r.id" class="inline-flex items-center gap-1.5 rounded-full border border-line-2 px-2.5 py-1 text-[10.5px]">
          <span :class="{ 'text-faint line-through': !r.active }" dir="auto">{{ pname(r) }}</span>
          <Chip v-if="r.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
          <button v-else type="button" class="font-bold text-violet" @click="edit('resumeType', r)">{{ t('تعديل', 'Edit') }}</button>
        </span>
      </div>
    </template>
  </Drawer>

  <FormDrawer :open="!!form" :title="formTitle" :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: t('حُفظ الدليل', 'Catalogue saved'), invalidate: ['payroll'] }" @close="form = null" />
</template>
