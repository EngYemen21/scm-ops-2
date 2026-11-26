<script setup>
// The dictionary: tax codes and tax groups (`view=codes|groups`).
//   codes   GET /tax/codes — filters use · kind · active · origin (odoo|ops) · box · group, search, sort (code · name ·
//           rate · box · kind · updated), paging; a row opens the code (`code=<id>`, `code=new` for a new one)
//   groups  from the lookups (GET /tax/lookups → groups); create / edit / archive (GroupForm.vue)
// Changes need vatreturn.manage. A record that came from Odoo while the section follows Odoo is read-only (`owned`).
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CodeDrawer from './CodeDrawer.vue';
import GroupForm from './GroupForm.vue';
import { ARCHIVED_CHIP, DEFAULT_CHIP, OWNED_CHIP, TONE, USE, boxLabel, labelOf, nameOf, optionsOf, pct, useSearchParam, useTaxLookups, useTaxNav } from './tax';

const nav = useTaxNav();
const auth = useAuth();
const lk = useTaxLookups();
const canManage = computed(() => auth.can('vatreturn.manage'));
const view = computed(() => (nav.get('view') === 'groups' ? 'groups' : 'codes'));
const views = computed(() => [
  { k: 'codes', label: { ar: 'الرموز الضريبية', en: 'Tax codes' }, badge: lk.codes.value.length || null },
  { k: 'groups', label: { ar: 'المجموعات', en: 'Groups' }, badge: lk.groups.value.length || null },
]);
const setView = (v) => nav.go('codes', v === 'codes' ? {} : { view: v });

// ---- codes
const search = useSearchParam(nav);
const SORTS = ['code', 'name', 'rate', 'box', 'kind', 'updated'];
const sort = nav.sortOf(SORTS);
const FILTERS = ['use', 'kind', 'active', 'origin', 'box', 'group'];
const list = useList(() => (view.value === 'codes' ? '/tax/codes' : null), () => ({
  q: nav.get('q') || undefined, ...Object.fromEntries(FILTERS.map((k) => [k, nav.get(k) || undefined])),
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 50,
}));
const hasFilters = computed(() => ['q', ...FILTERS].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ ...Object.fromEntries(['q', ...FILTERS].map((k) => [k, null])), page: null });
const kindOptions = computed(() => Object.entries(lk.kinds.value).map(([v, l]) => ({ v, l })));
const ACTIVE = [{ v: 'true', l: { ar: 'الفعّالة', en: 'Active' } }, { v: 'false', l: { ar: 'المؤرشفة', en: 'Archived' } }, { v: 'all', l: { ar: 'الفعّالة والمؤرشفة', en: 'Active and archived' } }];
const ORIGIN = [{ v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }, { v: 'ops', l: { ar: 'من العمليات', en: 'Made in OPS' } }];
const boxOptions = computed(() => [{ v: 'none', l: { ar: 'بلا بند', en: 'No line' } }, ...lk.boxes.value.filter((b) => !b.sum).map((b) => ({ v: String(b.box), l: boxLabel(lk.boxMap.value, b.box) }))]);
const columns = [
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '104px', kind: 'id', sortable: true },
  { key: 'name', header: { ar: 'الاسم', en: 'Name' }, width: 'minmax(180px,1.6fr)', sortable: true },
  { key: 'use', header: { ar: 'الاستخدام', en: 'Use' }, width: '84px', bare: true },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '120px', sortable: true, value: (r) => labelOf(lk.kinds.value, r.kind) },
  { key: 'rate', header: { ar: 'النسبة', en: 'Rate' }, width: '64px', kind: 'num', sortable: true, value: (r) => pct(r.rate) },
  { key: 'box', header: { ar: 'بند الإقرار', en: 'Return line' }, width: 'minmax(140px,1fr)', sortable: true },
  { key: 'group', header: { ar: 'المجموعة', en: 'Group' }, width: '110px', value: (r) => (r.group ? nameOf(r.group) : '—') },
  { key: 'flags', header: '', width: '124px', bare: true },
];
const openCode = (id) => nav.patch({ code: id });

// ---- groups
const gSearch = ref('');
const groups = computed(() => {
  const s = gSearch.value.trim().toLowerCase();
  return s ? lk.groups.value.filter((g) => [g.nameAr, g.nameEn].some((x) => String(x || '').toLowerCase().includes(s))) : lk.groups.value;
});
const codesPerGroup = computed(() => lk.codes.value.reduce((m, c) => { if (c.groupId) m[c.groupId] = (m[c.groupId] || 0) + 1; return m; }, {}));
const groupEditing = ref(null);
const groupCreating = ref(false);
const gAct = useAction({ invalidate: ['tax'] });
const setGroupActive = (g, active) => gAct.run(() => api.patch(`/tax/groups/${encodeURIComponent(g.id)}`, { active }), { success: active ? t('استُعيدت المجموعة', 'Group restored') : t('أُرشفت المجموعة', 'Group archived') });
const groupCols = [
  { key: 'name', header: { ar: 'المجموعة', en: 'Group' }, width: 'minmax(180px,2fr)' },
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Sequence' }, width: '80px', kind: 'num' },
  { key: 'codes', header: { ar: 'الرموز', en: 'Codes' }, width: '72px', kind: 'num', value: (r) => fmtNum(codesPerGroup.value[r.id] || 0) },
  { key: 'flags', header: '', width: '120px', bare: true },
  { key: 'actions', header: '', width: '150px', bare: true },
];
</script>

<template>
  <Tabs :model-value="view" :tabs="views" variant="sm" @update:model-value="setView" />

  <template v-if="view === 'codes'">
    <div class="row wrap mb-2 items-end gap-2">
      <TextInput :model-value="search.text.value" small field-class="w-full sm:w-[220px]" :placeholder="{ ar: 'بحث بالرمز أو الاسم…', en: 'Search code or name…' }" @update:model-value="search.type" @enter="search.apply" />
      <SelectInput :model-value="nav.get('use')" small :options="optionsOf(USE)" :placeholder="{ ar: '— المبيعات والمشتريات —', en: '— sales & purchases —' }" @update:model-value="(v) => nav.setFilter('use', v)" />
      <SelectInput :model-value="nav.get('kind')" small :options="kindOptions" :placeholder="{ ar: '— كل الأنواع —', en: '— all kinds —' }" @update:model-value="(v) => nav.setFilter('kind', v)" />
      <SelectInput :model-value="nav.get('box')" small :options="boxOptions" :placeholder="{ ar: '— كل البنود —', en: '— all lines —' }" @update:model-value="(v) => nav.setFilter('box', v)" />
      <SelectInput :model-value="nav.get('group')" small :options="lk.groupOptions.value" :placeholder="{ ar: '— كل المجموعات —', en: '— all groups —' }" @update:model-value="(v) => nav.setFilter('group', v)" />
      <SelectInput :model-value="nav.get('origin')" small :options="ORIGIN" :placeholder="{ ar: '— كل المصادر —', en: '— any origin —' }" @update:model-value="(v) => nav.setFilter('origin', v)" />
      <SelectInput :model-value="nav.get('active')" small :options="ACTIVE" :placeholder="{ ar: '— الحالة —', en: '— state —' }" @update:model-value="(v) => nav.setFilter('active', v)" />
      <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'مسح الفلاتر', en: 'Clear filters' }" @click="clear" />
      <div class="grow" />
      <Btn v-if="canManage" tone="primary" :label="{ ar: '+ رمز ضريبي', en: '+ Tax code' }" @click="openCode('new')" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('code') || null" :sort="sort" server-sort
                 :empty-text="hasFilters ? { ar: 'لا رموز تطابق البحث والفلاتر.', en: 'No code matches the search and filters.' } : { ar: 'لا رموز ضريبية بعد.', en: 'No tax code yet.' }"
                 @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => openCode(r.id)">
        <template #cell-code="{ row }"><span class="num font-extrabold">{{ row.code }}</span></template>
        <template #cell-name="{ row }">
          <div class="min-w-0">
            <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
            <div v-if="row.exemptionReasonCode || row.label" class="cell-sub truncate">{{ row.exemptionReasonCode || row.label }}</div>
          </div>
        </template>
        <template #cell-use="{ row }"><Chip small :map="USE" :k="row.use" /></template>
        <template #cell-box="{ row }"><span class="truncate text-[10px]" :class="row.returnBox == null ? 'text-faint' : ''">{{ row.returnBox == null ? t('ليس على الإقرار', 'Not on the return') : boxLabel(lk.boxMap.value, row.returnBox) }}</span></template>
        <template #cell-flags="{ row }">
          <span class="inline-flex flex-wrap justify-center gap-1">
            <Chip v-if="row.isDefault" small :label="DEFAULT_CHIP" :fg="DEFAULT_CHIP.fg" :bg="DEFAULT_CHIP.bg" />
            <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
            <Chip v-if="!row.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
          </span>
        </template>
      </DataTable>
    </div>
  </template>

  <template v-else>
    <div class="row wrap mb-2 items-end gap-2">
      <TextInput v-model="gSearch" small field-class="w-full sm:w-[220px]" :placeholder="{ ar: 'بحث باسم المجموعة…', en: 'Search group…' }" />
      <div class="grow" />
      <Btn v-if="canManage" tone="primary" :label="{ ar: '+ مجموعة', en: '+ Group' }" @click="groupCreating = true" />
    </div>
    <ErrorBanner :error="lk.query.error.value" :closable="false" />
    <ErrorBanner :error="gAct.error.value" @close="gAct.clearError()" />
    <div class="card">
      <DataTable :columns="groupCols" :rows="groups" :loading="lk.query.isLoading.value" :row-key="(r) => r.id" :page-size="25" :empty-text="{ ar: 'لا مجموعات ضريبية', en: 'No tax group' }">
        <template #cell-name="{ row }"><span class="cell-name truncate" dir="auto">{{ nameOf(row) }}</span></template>
        <template #cell-flags="{ row }">
          <span class="inline-flex flex-wrap justify-center gap-1">
            <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
            <Chip v-if="!row.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
          </span>
        </template>
        <template #cell-actions="{ row }">
          <div v-if="canManage && !row.owned" class="flex justify-center gap-1">
            <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="groupEditing = row" />
            <Btn v-if="row.active" size="sm" tone="softRed" :loading="gAct.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click.stop="setGroupActive(row, false)" />
            <Btn v-else size="sm" tone="softGreen" :loading="gAct.pending.value" :label="{ ar: 'استعادة', en: 'Restore' }" @click.stop="setGroupActive(row, true)" />
          </div>
          <span v-else-if="row.owned" class="text-[9px]" :style="{ color: TONE.violet.fg }">{{ t('يُعدَّل في أودو', 'Edited in Odoo') }}</span>
          <span v-else class="text-faint">—</span>
        </template>
      </DataTable>
    </div>
  </template>

  <CodeDrawer :id="nav.get('code') || null" @close="nav.patch({ code: null })" @saved="(c) => nav.patch({ code: c.id })" />
  <GroupForm :open="groupCreating || !!groupEditing" :group="groupEditing" @close="groupCreating = false; groupEditing = null" />
</template>
