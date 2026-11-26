<script setup>
// What the pipeline is made of: stages (their order, which one wins, the chance a lead gets on entering), lost
// reasons, tags and sales teams. Everything comes from GET /crm/lookups; adding and changing needs crm.manage:
//   POST | PATCH /crm/stages · /crm/lost-reasons · /crm/tags · /crm/teams
// Nothing is deleted — a record that is no longer wanted is archived and stays on the leads that carry it. A record
// that came from Odoo is read-only while the section follows Odoo (it is changed there).   /crm?view=settings&set=reasons
import { computed, ref, watch } from 'vue';
import { api } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, Tabs, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { OWNED_CHIP, TAG_COLOR_COUNT, TONE, nameOf, pct, tagColor, useCrmLookups, useCrmNav } from './crm';

const auth = useAuth();
const nav = useCrmNav();
const { stages, teams, lostReasons, tags, userOptions, follows, query } = useCrmLookups();
const canManage = computed(() => auth.can('crm.manage'));

const SETS = ['stages', 'reasons', 'tags', 'teams'];
const set = computed(() => (SETS.includes(nav.get('set')) ? nav.get('set') : 'stages'));
const tabs = computed(() => [
  { k: 'stages', label: { ar: 'المراحل', en: 'Stages' }, badge: stages.value.filter((x) => x.active).length },
  { k: 'reasons', label: { ar: 'أسباب الخسارة', en: 'Lost reasons' }, badge: lostReasons.value.filter((x) => x.active).length },
  { k: 'tags', label: { ar: 'الوسوم', en: 'Tags' }, badge: tags.value.filter((x) => x.active).length },
  { k: 'teams', label: { ar: 'فرق المبيعات', en: 'Sales teams' }, badge: teams.value.filter((x) => x.active).length },
]);
/** The records of the set on screen, narrowed by the search box (by either name). */
const search = ref('');
watch(set, () => { search.value = ''; });
const rows = computed(() => {
  const all = { stages: stages.value, reasons: lostReasons.value, tags: tags.value, teams: teams.value }[set.value];
  const s = search.value.trim().toLowerCase();
  return s ? all.filter((x) => [x.name, x.nameAr, x.nameEn].some((n) => String(n || '').toLowerCase().includes(s))) : all;
});
const byName = (x) => x.name || nameOf(x);

const YES_NO = [['yes', { ar: 'نعم', en: 'Yes' }], ['no', { ar: 'لا', en: 'No' }]];
const yes = (v) => v === 'yes';
const flag = (b) => (b ? 'yes' : 'no');
const active = (edit) => (edit ? [{ k: 'active', label: { ar: 'مستخدَم', en: 'In use' }, type: 'select', opts: YES_NO, hint: { ar: '«لا» يؤرشفه: يبقى على الفرص التي تحمله ولا يُعرض للاختيار', en: '"No" archives it: it stays on the leads that carry it and is no longer offered' } }] : []);

/** What each set is: its endpoint, its words, its form (fields · initial values of a record · the body sent). */
const DEFS = computed(() => ({
  stages: {
    path: '/crm/stages', one: { ar: 'مرحلة', en: 'stage' },
    fields: (edit) => [
      { k: 'nameAr', label: { ar: 'اسم المرحلة', en: 'Stage name (AR)' }, required: true },
      { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (EN)' }, dir: 'ltr' },
      { k: 'sequence', label: { ar: 'ترتيبها على اللوحة', en: 'Order on the board' }, type: 'num', min: 0, hint: { ar: 'الأصغر أولًا؛ تُترك فارغة لتأتي في آخر المسار', en: 'Smallest first; empty puts it at the end' } },
      { k: 'probability', label: { ar: 'احتمال الكسب عند دخولها %', en: 'Chance on entering %' }, type: 'num', min: 0, max: 100, hint: { ar: 'فارغ = يبقى احتمال الفرصة كما هو', en: 'Empty = the opportunity keeps its chance' }, visible: (v) => !yes(v.isWon) },
      { k: 'isWon', label: { ar: 'مرحلة كسب؟', en: 'A won stage?' }, type: 'select', opts: YES_NO, def: 'no', hint: { ar: 'دخول الفرصة إليها يسجّل كسبها', en: 'Entering it wins the opportunity' } },
      { k: 'fold', label: { ar: 'مطوية على اللوحة؟', en: 'Folded on the board?' }, type: 'select', opts: YES_NO, def: 'no' },
      { k: 'requirements', label: { ar: 'شروط دخولها', en: 'What entering it requires' }, type: 'area' },
      ...active(edit),
    ],
    initial: (x) => ({ nameAr: x.nameAr, nameEn: x.nameEn, sequence: x.sequence, probability: x.probability ?? undefined, isWon: flag(x.isWon), fold: flag(x.fold), requirements: x.requirements || '', active: flag(x.active) }),
    body: (v, edit) => ({ nameAr: v.nameAr, nameEn: v.nameEn ?? null, ...(v.sequence != null ? { sequence: v.sequence } : {}), isWon: yes(v.isWon), fold: yes(v.fold), probability: v.probability ?? null, requirements: v.requirements ?? null, ...(edit ? { active: v.active !== 'no' } : {}) }),
  },
  reasons: {
    path: '/crm/lost-reasons', one: { ar: 'سبب خسارة', en: 'lost reason' },
    fields: (edit) => [{ k: 'nameAr', label: { ar: 'السبب', en: 'Reason (AR)' }, required: true, full: true }, { k: 'nameEn', label: { ar: 'بالإنجليزية', en: 'Reason (EN)' }, dir: 'ltr', full: true }, ...active(edit)],
    initial: (x) => ({ nameAr: x.nameAr, nameEn: x.nameEn, active: flag(x.active) }),
    body: (v, edit) => ({ nameAr: v.nameAr, nameEn: v.nameEn ?? null, ...(edit ? { active: v.active !== 'no' } : {}) }),
  },
  tags: {
    path: '/crm/tags', one: { ar: 'وسم', en: 'tag' },
    fields: (edit) => [{ k: 'name', label: { ar: 'الوسم', en: 'Tag' }, required: true, full: true, ph: { ar: 'مطعم، كافيه، فندق، تموين…', en: 'Restaurant, café, hotel, catering…' } }, { k: 'color', label: { ar: 'اللون', en: 'Colour' }, full: true, def: 0 }, ...active(edit)],
    initial: (x) => ({ name: x.name, color: x.color, active: flag(x.active) }),
    body: (v, edit) => ({ name: v.name, color: Number(v.color) || 0, ...(edit ? { active: v.active !== 'no' } : {}) }),
  },
  teams: {
    path: '/crm/teams', one: { ar: 'فريق', en: 'team' },
    fields: (edit) => [
      { k: 'nameAr', label: { ar: 'اسم الفريق', en: 'Team name (AR)' }, required: true },
      { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (EN)' }, dir: 'ltr' },
      { k: 'leaderId', label: { ar: 'قائد الفريق', en: 'Team leader' }, type: 'select', opts: userOptions.value, ph: { ar: '— بلا قائد —', en: '— no leader —' } },
      ...active(edit),
    ],
    initial: (x) => ({ nameAr: x.nameAr, nameEn: x.nameEn, leaderId: x.leader?.id || '', active: flag(x.active) }),
    // a leader kept by name from Odoo stays until another person is picked
    body: (v, edit, x) => ({ nameAr: v.nameAr, nameEn: v.nameEn ?? null, ...(v.leaderId || (edit && x?.leader?.id) ? { leaderId: v.leaderId ?? null } : {}), ...(edit ? { active: v.active !== 'no' } : {}) }),
  },
}));
const def = computed(() => DEFS.value[set.value]);

/** The form: `null` closed · `{}` a new record · a record being changed. */
const form = ref(null);
const editing = computed(() => (form.value && form.value.id ? form.value : null));
const fields = computed(() => (form.value ? def.value.fields(!!editing.value) : []));
const initial = computed(() => (editing.value ? def.value.initial(editing.value) : null));
const title = computed(() => (editing.value ? { ar: `تعديل ${def.value.one.ar}`, en: `Change the ${def.value.one.en}` } : { ar: `إضافة ${def.value.one.ar}`, en: `Add a ${def.value.one.en}` }));
function submit(values) {
  const body = def.value.body(values, !!editing.value, editing.value);
  return editing.value ? api.patch(`${def.value.path}/${editing.value.id}`, body) : api.postIdempotent(def.value.path, body);
}
const action = computed(() => ({ success: t('تم الحفظ', 'Saved'), invalidate: ['crm'] }));

const columns = computed(() => ({
  stages: [
    { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '80px', kind: 'num', sortable: true },
    { key: 'name', header: { ar: 'المرحلة', en: 'Stage' }, width: 'minmax(180px,1.5fr)', sortable: true, sortValue: byName },
    { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '110px', bare: true },
    { key: 'probability', header: { ar: 'الاحتمال عند الدخول', en: 'Chance on entering' }, width: '140px', kind: 'num', sortable: true, value: (x) => (x.probability == null ? '—' : pct(x.probability)) },
    { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', bare: true },
    { key: 'act', header: '', width: '90px', bare: true, hidden: !canManage.value },
  ],
  reasons: [
    { key: 'name', header: { ar: 'سبب الخسارة', en: 'Lost reason' }, width: 'minmax(220px,2fr)', sortable: true, sortValue: byName },
    { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', bare: true },
    { key: 'act', header: '', width: '90px', bare: true, hidden: !canManage.value },
  ],
  tags: [
    { key: 'name', header: { ar: 'الوسم', en: 'Tag' }, width: 'minmax(220px,2fr)', sortable: true, sortValue: byName },
    { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', bare: true },
    { key: 'act', header: '', width: '90px', bare: true, hidden: !canManage.value },
  ],
  teams: [
    { key: 'name', header: { ar: 'الفريق', en: 'Team' }, width: 'minmax(180px,1.5fr)', sortable: true, sortValue: byName },
    { key: 'leader', header: { ar: 'القائد', en: 'Leader' }, width: 'minmax(140px,1fr)' },
    { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', bare: true },
    { key: 'act', header: '', width: '90px', bare: true, hidden: !canManage.value },
  ],
}[set.value]));
const emptyText = computed(() => (search.value.trim() ? { ar: 'لا نتائج مطابقة للبحث.', en: 'Nothing matches the search.' } : { ar: 'لا شيء هنا بعد.', en: 'Nothing here yet.' }));
const WON = { ar: 'مرحلة كسب', en: 'Won stage', ...TONE.ok };
const OPEN = { ar: 'مفتوحة', en: 'Open', ...TONE.blue };
const IN_USE = { ar: 'مستخدَم', en: 'In use', ...TONE.ok };
const ARCHIVED = { ar: 'مؤرشف', en: 'Archived', ...TONE.neutral };
const swatches = Array.from({ length: TAG_COLOR_COUNT }, (_, i) => i);
</script>

<template>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="set" :tabs="tabs" variant="sm" class="!mb-0" @update:model-value="(k) => nav.patch({ set: k === 'stages' ? null : k })" />
    <TextInput v-model="search" small type="search" class="w-[200px]" :placeholder="{ ar: 'بحث بالاسم…', en: 'Search by name…' }" />
    <span class="grow" />
    <Btn v-if="canManage" size="sm" tone="primary" :label="{ ar: `+ ${def.one.ar}`, en: `+ ${def.one.en}` }" @click="form = {}" />
  </div>
  <ErrorBanner :error="query.error.value" :closable="false" />
  <div v-if="follows" class="hint purple !mt-0 mb-2.5">{{ t('ما يحمل شارة «من أودو» يُعدَّل في أودو ما دام القسم يتبعه. يمكنك إضافة مراحل وأسباب ووسوم وفرق خاصة بالعمليات بجانبها.', 'What carries the "From Odoo" chip is changed in Odoo while the section follows it. You can add stages, reasons, tags and teams of OPS beside them.') }}</div>

  <div class="card">
    <DataTable :columns="columns" :rows="rows" :page-size="25" :loading="query.isLoading.value" :row-key="(x) => x.id" :empty-text="emptyText">
      <template #cell-name="{ row }">
        <Chip v-if="set === 'tags'" :label="row.name" :fg="tagColor(row.color).fg" :bg="tagColor(row.color).bg" />
        <div v-else class="min-w-0">
          <div class="cell-name truncate" :class="{ 'opacity-50': !row.active }" dir="auto">{{ nameOf(row) }}</div>
          <div v-if="row.nameEn && row.nameEn !== row.nameAr" class="cell-sub !font-sans" dir="auto">{{ bi({ ar: row.nameEn, en: row.nameAr }) }}</div>
        </div>
      </template>
      <template #cell-kind="{ row }"><Chip small :label="row.isWon ? WON : OPEN" :fg="(row.isWon ? WON : OPEN).fg" :bg="(row.isWon ? WON : OPEN).bg" /></template>
      <template #cell-leader="{ row }"><span class="text-[10.5px] text-sec" dir="auto">{{ row.leader ? nameOf(row.leader) : '—' }}</span><span v-if="row.leader?.external" class="text-[8.5px] text-faint"> · {{ t('بالاسم من أودو', 'by name, from Odoo') }}</span></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :label="row.active ? IN_USE : ARCHIVED" :fg="(row.active ? IN_USE : ARCHIVED).fg" :bg="(row.active ? IN_USE : ARCHIVED).bg" />
          <Chip v-if="row.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </span>
      </template>
      <template #cell-act="{ row }">
        <Btn v-if="!row.owned" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="form = row" />
        <span v-else class="text-[9px] text-faint" :title="t('يُعدَّل في أودو', 'Changed in Odoo')">{{ t('في أودو', 'in Odoo') }}</span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('لا يُحذف شيء من المسار: ما لم يعد مستخدمًا يُؤرشف. المرحلة التي فيها فرص مفتوحة لا تُؤرشف قبل نقل فرصها، ويبقى في المسار دائمًا مرحلة مفتوحة واحدة على الأقل.', 'Nothing of the pipeline is deleted: what is no longer used is archived. A stage that holds open opportunities is not archived before they are moved, and the pipeline always keeps at least one open stage.') }}</div>

  <FormDrawer :open="!!form" :title="title" :fields="fields" :initial="initial" :submit="submit" :action="action" @close="form = null">
    <template #field-color="{ value, set: pick }">
      <div class="field-l">{{ t('اللون', 'Colour') }}</div>
      <div class="row wrap !gap-1.5">
        <button v-for="i in swatches" :key="i" type="button" class="h-7 w-7 cursor-pointer rounded-full border-2" :style="{ background: tagColor(i).bg, borderColor: Number(value) === i ? tagColor(i).fg : 'transparent' }" :aria-pressed="Number(value) === i" :aria-label="`${t('لون', 'colour')} ${i + 1}`" @click="pick(i)" />
      </div>
    </template>
  </FormDrawer>
</template>
