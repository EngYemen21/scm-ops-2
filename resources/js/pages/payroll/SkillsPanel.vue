<script setup>
// The skills and résumé of one employee — `me` (the caller's own) or an employee id:
//   GET /payroll/skills/employees/{id|me} → { employee, department, jobTitle, mine, can { edit }, skills: [{ id, type, skill,
//   level { progress }, source, editable }], resume: [{ id, type, title, dateStart, dateEnd, description, source, editable }] }
//   + skill:  POST …/skills { skillId, levelId? } (the same skill again changes its level) · − skill: DELETE …/skills/{id}
//   résumé:   POST …/resume · PATCH …/resume/{line} · DELETE …/resume/{line}
// The person keeps their own; payroll.manage keeps everyone's. What came from Odoo is Odoo's (`editable: false`).
// The catalogue (types with their skills and levels, résumé kinds) is GET /payroll/skills/catalog.
//   <SkillsPanel employee="me" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, ProgressBar, SectionCard, SelectInput } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import ResumeForm from './ResumeForm.vue';
import { FROM_ODOO, SELECT, fmtDay, pname } from './payroll';

const props = defineProps({
  employee: { type: String, required: true },
});

const q = useGet(() => `/payroll/skills/employees/${encodeURIComponent(props.employee)}`);
const data = computed(() => q.data.value || null);
const canEdit = computed(() => !!data.value?.can?.edit);
const catalog = useGet('/payroll/skills/catalog', undefined, { staleTime: 60_000, enabled: canEdit });
const types = computed(() => catalog.data.value?.types || []);
const resumeTypes = computed(() => catalog.data.value?.resumeTypes || []);
const act = useAction({ invalidate: ['payroll'] });
const base = computed(() => `/payroll/skills/employees/${encodeURIComponent(data.value?.employee?.id || props.employee)}`);

// skills grouped by their type
const grouped = computed(() => {
  const out = new Map();
  for (const s of data.value?.skills || []) {
    const k = s.type?.id || '-';
    if (!out.has(k)) out.set(k, { type: s.type, rows: [] });
    out.get(k).rows.push(s);
  }
  return [...out.values()];
});
const levelsOf = (typeId) => (types.value.find((x) => x.id === typeId)?.levels || []).map((l) => [l.id, `${pname(l)} · ${l.progress}%`]);

// ---- add a skill
const add = reactive({ type: '', skill: '', level: '' });
const adding = ref(false);
watch(() => props.employee, () => { adding.value = false; act.clearError(); });
watch(() => add.type, () => {
  add.skill = '';
  const def = types.value.find((x) => x.id === add.type)?.levels.find((l) => l.isDefault);
  add.level = def?.id || '';
});
const typeOptions = computed(() => types.value.filter((x) => x.skills.length).map((x) => [x.id, pname(x)]));
const skillOptions = computed(() => (types.value.find((x) => x.id === add.type)?.skills || []).map((s) => [s.id, pname(s)]));
async function addSkill() {
  if (!add.skill) return;
  const r = await act.run(() => api.postIdempotent(`${base.value}/skills`, { skillId: add.skill, levelId: add.level || null }), { success: t('أُضيفت المهارة', 'Skill added') });
  if (r) { Object.assign(add, { type: '', skill: '', level: '' }); adding.value = false; }
}
async function setLevel(row, levelId) {
  await act.run(() => api.postIdempotent(`${base.value}/skills`, { skillId: row.skill.id, levelId: levelId || null }), { success: t('غُيِّر المستوى', 'Level changed') });
}
async function removeSkill(row) {
  if (!(await confirm({ title: { ar: `إزالة مهارة «${pname(row.skill)}»؟`, en: `Remove the skill “${pname(row.skill)}”?` }, tone: 'danger', okLabel: { ar: 'إزالة', en: 'Remove' } }))) return;
  await act.run(() => api.del(`${base.value}/skills/${encodeURIComponent(row.id)}`), { success: t('أُزيلت المهارة', 'Skill removed') });
}

// ---- résumé
const resumeOpen = ref(false);
const resumeLine = ref(null);
const editLine = (l) => { resumeLine.value = l; resumeOpen.value = true; };
async function removeLine(l) {
  if (!(await confirm({ title: { ar: `حذف سطر «${l.title}» من السيرة؟`, en: `Delete “${l.title}” from the résumé?` }, tone: 'danger', okLabel: { ar: 'حذف', en: 'Delete' } }))) return;
  await act.run(() => api.del(`${base.value}/resume/${encodeURIComponent(l.id)}`), { success: t('حُذف السطر', 'Line deleted') });
}
</script>

<template>
  <div>
    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <div v-else-if="data" class="grid-eq">
      <SectionCard :title="{ ar: 'المهارات', en: 'Skills' }" :count="data.skills.length">
        <template v-if="canEdit" #actions><Btn size="sm" tone="softPurple" :label="adding ? { ar: 'إغلاق', en: 'Close' } : { ar: '+ مهارة', en: '+ Skill' }" @click="adding = !adding" /></template>
        <div v-if="adding" class="mb-3 rounded-[12px] bg-soft p-2.5">
          <div v-if="catalog.isLoading.value" class="skel h-[40px]" />
          <div v-else-if="!typeOptions.length" class="text-[10.5px] text-muted">{{ t('دليل المهارات فارغ — يُضاف من «المهارات ◂ دليل المهارات».', 'The skills catalogue is empty — it is filled in “Skills ▸ Catalogue”.') }}</div>
          <div v-else class="form-grid">
            <SelectInput v-model="add.type" small :label="{ ar: 'النوع', en: 'Type' }" :options="typeOptions" :placeholder="SELECT" />
            <SelectInput v-model="add.skill" small :label="{ ar: 'المهارة', en: 'Skill' }" :options="skillOptions" :placeholder="SELECT" :disabled="!add.type" />
            <SelectInput v-model="add.level" small :label="{ ar: 'المستوى', en: 'Level' }" :options="levelsOf(add.type)" :placeholder="{ ar: '— بلا مستوى —', en: '— no level —' }" :disabled="!add.type" />
            <div class="flex items-end"><Btn size="sm" tone="dark" :disabled="!add.skill" :loading="act.pending.value" :label="{ ar: 'إضافة', en: 'Add' }" @click="addSkill" /></div>
          </div>
        </div>
        <div v-if="!data.skills.length" class="empty !p-3">{{ t('لا مهارات مسجّلة.', 'No skill on record.') }}</div>
        <div v-for="g in grouped" :key="g.type?.id || '-'" class="mb-2.5">
          <div class="field-l mb-1" dir="auto">{{ pname(g.type) }}</div>
          <div v-for="s in g.rows" :key="s.id" class="flex flex-wrap items-center gap-2 border-b border-line-2 py-1.5 last:border-b-0">
            <span class="min-w-[120px] flex-1 text-[11px] font-bold" dir="auto">{{ pname(s.skill) }}</span>
            <Chip v-if="s.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
            <template v-if="canEdit && s.editable && levelsOf(s.type?.id).length">
              <SelectInput small class="w-[150px]" :model-value="s.level?.id || ''" :options="levelsOf(s.type?.id)" :placeholder="{ ar: '— بلا مستوى —', en: '— no level —' }" :aria-label="t('المستوى', 'Level')" @update:model-value="(v) => setLevel(s, v)" />
            </template>
            <span v-else class="text-[10px] text-muted" dir="auto">{{ s.level ? pname(s.level) : '—' }}</span>
            <div v-if="s.level" class="w-[70px]"><ProgressBar :pct="Number(s.level.progress) || 0" color="#654e92" :height="6" /></div>
            <Btn v-if="canEdit && s.editable" size="sm" tone="ghost" :label="{ ar: 'إزالة', en: 'Remove' }" @click="removeSkill(s)" />
          </div>
        </div>
      </SectionCard>

      <SectionCard :title="{ ar: 'السيرة الذاتية', en: 'Résumé' }" :count="data.resume.length">
        <template v-if="canEdit" #actions><Btn size="sm" tone="softPurple" :label="{ ar: '+ سطر', en: '+ Line' }" @click="editLine(null)" /></template>
        <div v-if="!data.resume.length" class="empty !p-3">{{ t('لا سطور في السيرة.', 'No line in the résumé.') }}</div>
        <div v-for="l in data.resume" :key="l.id" class="border-b border-line-2 py-2 last:border-b-0">
          <div class="flex flex-wrap items-center gap-2">
            <b class="min-w-0 flex-1 text-[11.5px]" dir="auto">{{ l.title }}</b>
            <span v-if="l.type" class="text-[9.5px] text-violet" dir="auto">{{ pname(l.type) }}</span>
            <Chip v-if="l.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
          </div>
          <div class="num ltr mt-0.5 text-[10px] text-muted" style="text-align: start">{{ l.dateStart ? fmtDay(l.dateStart) : '—' }} – {{ l.dateEnd ? fmtDay(l.dateEnd) : t('حتى الآن', 'to date') }}</div>
          <div v-if="l.description" class="mt-1 whitespace-pre-wrap text-[10.5px] leading-[1.8] text-sec [overflow-wrap:anywhere]" dir="auto">{{ l.description }}</div>
          <div v-if="canEdit && l.editable" class="row mt-1 !gap-1.5"><Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editLine(l)" /><Btn size="sm" tone="ghost" :label="{ ar: 'حذف', en: 'Delete' }" @click="removeLine(l)" /></div>
        </div>
      </SectionCard>
    </div>

    <ResumeForm :open="resumeOpen" :employee-id="data?.employee?.id || employee" :line="resumeLine" :types="resumeTypes" @close="resumeOpen = false; resumeLine = null" />
  </div>
</template>
