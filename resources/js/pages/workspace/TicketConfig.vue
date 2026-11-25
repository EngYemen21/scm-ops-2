<script setup>
// What the help desk is made of — for whoever holds ticket.manage:
//   teams   who works the tickets, who gets a new one nobody was named for, who may read them, and the two targets
//           (hours to the first answer, hours to the closing — the second gives a ticket its due date)
//   stages  the steps a ticket passes through; a ticket standing in a closing stage is closed; a stage is for every
//           team unless teams are named for it
//   types   the kinds of ticket
// POST | PATCH /workspace/ticket-teams · ticket-stages · ticket-types. Nothing is removed: a row is put away
// (`active`). A row that came from Odoo is read-only while the section follows Odoo — except, on a team, what Odoo
// does not hold: the two targets and the default assignee.
//   <TicketConfig :open :desk="desk" :lookups="lookups" @close />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, NumberInput, SelectInput, Tabs, TextArea, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import OdooMark from './OdooMark.vue';
import PeoplePicker from './PeoplePicker.vue';
import PersonName from './PersonName.vue';
import WsFormDrawer from './WsFormDrawer.vue';
import { API, INVALIDATE, OWNED_NOTE, TONE, VISIBILITY, VISIBILITY_OPTIONS, arr, personName, userOptions } from './workspace';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** GET tickets/lookups: every team, stage and type — the ones put away included. */
  desk: { type: Object, default: null },
  /** GET lookups: the users a team is made of. */
  lookups: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const teams = computed(() => arr(props.desk?.teams));
const stages = computed(() => arr(props.desk?.stages));
const types = computed(() => arr(props.desk?.types));
const part = ref('teams');
const parts = [
  { k: 'teams', label: { ar: 'الفرق', en: 'Teams' } },
  { k: 'stages', label: { ar: 'المراحل', en: 'Stages' } },
  { k: 'types', label: { ar: 'الأنواع', en: 'Types' } },
];
const ADD = { teams: { ar: '+ فريق', en: '+ Team' }, stages: { ar: '+ مرحلة', en: '+ Stage' }, types: { ar: '+ نوع', en: '+ Type' } };
const KIND_OF = { teams: 'team', stages: 'stage', types: 'type' };
const OFF = { ar: 'موقوف', en: 'Put away', ...TONE.off };
const ON = { ar: 'فعّال', en: 'Active', ...TONE.ok };
const CLOSING = { ar: 'تُغلق التذكرة', en: 'Closes the ticket', ...TONE.ok };

const hours = (n) => (n == null ? '—' : t(`${fmtNum(n, Number.isInteger(Number(n)) ? 0 : 1)} س`, `${fmtNum(n, Number.isInteger(Number(n)) ? 0 : 1)}h`));
const teamName = (id) => { const x = teams.value.find((r) => r.id === id); return x ? personName(x) : '—'; };
const stageTeams = (s) => (arr(s.teamIds).length ? s.teamIds.map(teamName).join('، ') : t('كل الفرق', 'Every team'));

const teamColumns = [
  { key: 'name', header: { ar: 'الفريق', en: 'Team' }, width: 'minmax(150px,1.4fr)', kind: 'name' },
  { key: 'visibility', header: { ar: 'من يقرأ تذاكره', en: 'Who reads its tickets' }, width: '130px', value: (r) => (VISIBILITY[r.visibility] ? t(VISIBILITY[r.visibility].ar, VISIBILITY[r.visibility].en) : '—') },
  { key: 'targets', header: { ar: 'أول رد · الحل', en: 'First answer · closing' }, width: '120px', value: (r) => `${hours(r.firstResponseHours)} · ${hours(r.resolutionHours)}` },
  { key: 'assignee', header: { ar: 'المسؤول الافتراضي', en: 'Default assignee' }, width: 'minmax(120px,1fr)' },
  { key: 'members', header: { ar: 'الأعضاء', en: 'Members' }, width: '70px', kind: 'num', value: (r) => fmtNum(arr(r.members).length) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '120px', bare: true },
  { key: 'act', header: '', width: '80px', bare: true },
];
const stageColumns = [
  { key: 'name', header: { ar: 'المرحلة', en: 'Stage' }, width: 'minmax(150px,1.4fr)', kind: 'name' },
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '70px', kind: 'num' },
  { key: 'closing', header: { ar: 'الإغلاق', en: 'Closing' }, width: '120px', bare: true },
  { key: 'teams', header: { ar: 'لأي الفرق', en: 'For which teams' }, width: 'minmax(140px,1.2fr)', value: stageTeams },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '120px', bare: true },
  { key: 'act', header: '', width: '80px', bare: true },
];
const typeColumns = [
  { key: 'name', header: { ar: 'النوع', en: 'Type' }, width: 'minmax(160px,1.6fr)', kind: 'name' },
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '70px', kind: 'num' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '120px', bare: true },
  { key: 'act', header: '', width: '80px', bare: true },
];
const columns = computed(() => ({ teams: teamColumns, stages: stageColumns, types: typeColumns }[part.value]));
const rows = computed(() => ({ teams: teams.value, stages: stages.value, types: types.value }[part.value]));
/** A team of Odoo keeps three values of OPS's own open; a stage or a type of Odoo has none. */
const editable = (row) => !row.owned || part.value === 'teams';

// ---- the form (one drawer for the three kinds)
/** { kind: team | stage | type, row: the row being edited | null, f: the values } */
const form = ref(null);
const act = useAction();
function openForm(kind, row = null) {
  const common = { nameAr: row?.nameAr || '', nameEn: row?.nameEn || '', sequence: row?.sequence ?? (rows.value.length + 1) * 10, active: row ? row.active !== false : true };
  const more = kind === 'team'
    ? { description: row?.description || '', visibility: row?.visibility || 'all', firstResponseHours: row?.firstResponseHours ?? null, resolutionHours: row?.resolutionHours ?? null,
      defaultAssigneeId: row?.defaultAssignee?.id || '', memberKeys: arr(row?.members).map((m) => String(m.key)) }
    : kind === 'stage' ? { isClosing: !!row?.isClosing, teamIds: [...arr(row?.teamIds)] } : {};
  act.clearError();
  form.value = { kind, row, f: { ...common, ...more } };
}
watch(() => props.open, (now) => { if (!now) form.value = null; });

/** Only what Odoo does not hold may change on a team of Odoo. */
const partial = computed(() => !!form.value?.row?.owned);
const people = computed(() => userOptions(props.lookups?.users));
const offeredTeams = computed(() => teams.value.filter((x) => x.active !== false || form.value?.f.teamIds?.includes(x.id)));
function toggleTeam(id) {
  const list = form.value.f.teamIds;
  form.value.f.teamIds = list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
}
const missing = computed(() => (form.value && !partial.value && !form.value.f.nameAr.trim() ? { ar: 'اكتب الاسم بالعربية.', en: 'Type the Arabic name.' } : null));
const TITLE = {
  team: [{ ar: 'فريق جديد', en: 'New team' }, { ar: 'تعديل الفريق', en: 'Edit team' }],
  stage: [{ ar: 'مرحلة جديدة', en: 'New stage' }, { ar: 'تعديل المرحلة', en: 'Edit stage' }],
  type: [{ ar: 'نوع جديد', en: 'New type' }, { ar: 'تعديل النوع', en: 'Edit type' }],
};
const PATH = { team: 'ticket-teams', stage: 'ticket-stages', type: 'ticket-types' };

async function save() {
  if (!form.value || missing.value) return;
  const { kind, row, f } = form.value;
  const named = { nameAr: f.nameAr.trim(), nameEn: f.nameEn.trim() || undefined, sequence: Number(f.sequence) || 0, ...(row ? { active: !!f.active } : {}) };
  const ownOfOps = { firstResponseHours: f.firstResponseHours ?? null, resolutionHours: f.resolutionHours ?? null, defaultAssigneeId: f.defaultAssigneeId || null };
  const body = kind === 'team'
    ? (partial.value ? ownOfOps : { ...named, description: f.description.trim() || null, visibility: f.visibility, ...ownOfOps, memberKeys: f.memberKeys })
    : kind === 'stage' ? { ...named, isClosing: !!f.isClosing, teamIds: f.teamIds } : named;
  const url = `${API}/${PATH[kind]}`;
  const done = await act.run(() => (row ? api.patch(`${url}/${row.id}`, body) : api.postIdempotent(url, body)), { success: t('حُفظت الإعدادات', 'Settings saved'), invalidate: INVALIDATE });
  if (done) form.value = null;
}
</script>

<template>
  <Drawer :open="open" :width="860" :title="{ ar: 'إعدادات مكتب الدعم', en: 'Help-desk settings' }" :sub="{ ar: 'الفرق والمراحل والأنواع — لا يُحذف شيء: ما لم يعد مستعملًا يُوقَف', en: 'Teams, stages and types — nothing is removed: what is no longer used is put away' }" @close="emit('close')">
    <div class="row wrap mb-2.5">
      <Tabs v-model="part" :tabs="parts" variant="sm" class="!mb-0" />
      <span class="grow" />
      <Btn tone="primary" size="sm" :label="ADD[part]" @click="openForm(KIND_OF[part])" />
    </div>
    <div class="card">
      <DataTable :columns="columns" :rows="rows" :loading="loading" :row-key="(r) => r.id" :empty-text="{ ar: 'لا شيء معرَّف بعد.', en: 'Nothing defined yet.' }">
        <template #cell-name="{ row }">
          <div class="cell-name" dir="auto">{{ row.nameAr }}</div>
          <div v-if="row.nameEn && row.nameEn !== row.nameAr" class="cell-sub">{{ row.nameEn }}</div>
        </template>
        <template #cell-assignee="{ row }"><PersonName :person="row.defaultAssignee" :empty="{ ar: 'بلا', en: 'None' }" /></template>
        <template #cell-closing="{ row }"><Chip v-if="row.isClosing" small :label="CLOSING" :fg="CLOSING.fg" :bg="CLOSING.bg" /><span v-else class="text-faint">—</span></template>
        <template #cell-state="{ row }">
          <span class="inline-flex flex-wrap items-center gap-1">
            <Chip small :label="row.active === false ? OFF : ON" :fg="(row.active === false ? OFF : ON).fg" :bg="(row.active === false ? OFF : ON).bg" />
            <OdooMark :row="row" />
          </span>
        </template>
        <template #cell-act="{ row }"><Btn v-if="editable(row)" tone="outline" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="openForm(KIND_OF[part], row)" /></template>
      </DataTable>
    </div>
    <div class="hint">
      {{ part === 'teams' ? t('هدف «الحل» بالساعات يعطي كل تذكرة جديدة للفريق تاريخ استحقاقها، وهدف «أول رد» يُقاس حتى أول رد من غير صاحب الطلب. الساعات تقويمية (لا تُستثنى العطل).', 'The “closing” target, in hours, gives every new ticket of the team its due date; the “first answer” target runs until the first reply of somebody who is not the requester. Calendar hours (days off are not left out).')
        : part === 'stages' ? t('التذكرة تدخل مرحلة الإغلاق عبر «إغلاق» مع كتابة الحل. المرحلة بلا فرق محددة تصلح لكل الفرق.', 'A ticket enters a closing stage through “Close”, with its resolution. A stage with no team named is for every team.')
          : t('النوع يصنّف التذكرة: استفسار، مشكلة، طلب خدمة…', 'The type says what a ticket is: a question, an issue, a service request…') }}
    </div>

    <WsFormDrawer :open="!!form" :title="form ? TITLE[form.kind][form.row ? 1 : 0] : null" :sub="form?.row ? personName(form.row) : null" :pending="act.pending.value" :error="act.error.value"
                  :disabled="!!missing" :why="missing" @submit="save" @close="form = null" @clear-error="act.clearError()">
      <template v-if="form">
        <div v-if="partial" class="banner teal">{{ t(OWNED_NOTE.ar, OWNED_NOTE.en) }} {{ t('يُعدَّل هنا ما لا يحمله أودو: الهدفان والمسؤول الافتراضي.', 'What Odoo does not hold is set here: the two targets and the default assignee.') }}</div>
        <div class="form-grid">
          <TextInput v-model="form.f.nameAr" :label="{ ar: 'الاسم بالعربية', en: 'Arabic name' }" required :disabled="partial" maxlength="120" />
          <TextInput v-model="form.f.nameEn" :label="{ ar: 'الاسم بالإنجليزية', en: 'English name' }" dir="ltr" :disabled="partial" maxlength="120" />

          <template v-if="form.kind === 'team'">
            <TextArea v-model="form.f.description" :label="{ ar: 'الوصف', en: 'Description' }" :rows="2" :disabled="partial" />
            <SelectInput v-model="form.f.visibility" :label="{ ar: 'من يقرأ تذاكر الفريق', en: 'Who reads the team tickets' }" :options="VISIBILITY_OPTIONS" :disabled="partial" full
                         :hint="{ ar: '«الأعضاء فقط»: يراها أعضاء الفريق والمسؤول وصاحب الطلب ومدير الدعم.', en: '“Members only”: its members, the assignee, the requester and the help-desk manager.' }" />
            <NumberInput v-model="form.f.firstResponseHours" :label="{ ar: 'هدف أول رد (ساعات)', en: 'First answer target (hours)' }" :min="0" :max="9999" :placeholder="{ ar: 'بلا هدف', en: 'No target' }" />
            <NumberInput v-model="form.f.resolutionHours" :label="{ ar: 'هدف الحل (ساعات)', en: 'Closing target (hours)' }" :min="0" :max="9999" :placeholder="{ ar: 'بلا هدف', en: 'No target' }" />
            <SelectInput v-model="form.f.defaultAssigneeId" :label="{ ar: 'المسؤول الافتراضي', en: 'Default assignee' }" :options="people" :placeholder="{ ar: '— بلا —', en: '— none —' }" full
                         :hint="{ ar: 'يستلم كل تذكرة جديدة لم يُسمَّ لها مسؤول.', en: 'Gets every new ticket nobody was named for.' }" />
            <PeoplePicker v-model="form.f.memberKeys" :users="lookups?.users" :known="form.row?.members" :label="{ ar: 'الأعضاء', en: 'Members' }" :disabled="partial" />
          </template>

          <template v-else-if="form.kind === 'stage'">
            <label class="field full row cursor-pointer !gap-2 text-[10.5px] font-extrabold"><input v-model="form.f.isClosing" type="checkbox">{{ t('مرحلة إغلاق — التذكرة التي تقف فيها مغلقة', 'A closing stage — a ticket standing in it is closed') }}</label>
            <div class="field full">
              <div class="field-l">{{ t('الفرق التي تستعمل المرحلة', 'Teams that use the stage') }}</div>
              <div class="row wrap !gap-1.5">
                <button v-for="x in offeredTeams" :key="x.id" type="button" class="pill purple" :class="{ active: form.f.teamIds.includes(x.id) }" :aria-pressed="form.f.teamIds.includes(x.id)" @click="toggleTeam(x.id)">{{ personName(x) }}</button>
                <span v-if="!offeredTeams.length" class="text-[10.5px] text-faint">{{ t('لا فرق معرَّفة.', 'No teams defined.') }}</span>
              </div>
              <div class="field-hint">{{ form.f.teamIds.length ? t('للفرق المختارة فقط.', 'For the chosen teams only.') : t('لم يُختر فريق: المرحلة لكل الفرق.', 'No team chosen: the stage is for every team.') }}</div>
            </div>
          </template>

          <NumberInput v-if="!partial" v-model="form.f.sequence" :label="{ ar: 'الترتيب', en: 'Order' }" :min="0" :max="100000" :hint="{ ar: 'الأصغر أولًا.', en: 'Smallest first.' }" />
          <label v-if="form.row && !partial" class="field row cursor-pointer !gap-2 self-end pb-2.5 text-[10.5px] font-extrabold"><input v-model="form.f.active" type="checkbox">{{ t('فعّال (يُعرض في النماذج)', 'Active (offered in the forms)') }}</label>
        </div>
      </template>
    </WsFormDrawer>
  </Drawer>
</template>
