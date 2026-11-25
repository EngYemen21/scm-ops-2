<script setup>
// The filters the board and the list share — search, salesperson, team, priority, tag, next to-do, origin — and, for
// the list, its state pills (open · won · lost · archived · all) and the stage. Every filter is a key of the query
// string (see `useCrmNav`), so a filtered view is a link and "back" undoes a filter.
//   <CrmFilters />            the board            <CrmFilters list />      the list
import { computed } from 'vue';
import { Btn, SelectInput, Tabs, TextInput } from '@/components';
import { LEAD_STATES, priorityOptions, useCrmLookups, useCrmNav, useSearchParam } from './crm';

defineProps({
  /** The list: adds the state pills and the stage filter (on the board the stages are the columns). */
  list: { type: Boolean, default: false },
});

const nav = useCrmNav();
const { hasLeadFilters } = nav;
const { stageOptions, ownerOptions, teamOptions, tagOptions, follows } = useCrmLookups();
const { text, type, apply } = useSearchParam(nav);

const state = computed(() => (LEAD_STATES.some((s) => s.k === nav.get('state')) ? nav.get('state') : 'open'));
const owners = computed(() => [{ v: 'me', l: { ar: 'فرصي أنا', en: 'My opportunities' } }, ...ownerOptions.value, { v: 'none', l: { ar: 'بلا مندوب', en: 'Unassigned' } }]);
const priorities = priorityOptions();
const ACTIVITY = [
  { v: 'overdue', l: { ar: 'نشاط متأخر', en: 'To-do overdue' } }, { v: 'today', l: { ar: 'نشاط اليوم', en: 'To-do today' } },
  { v: 'upcoming', l: { ar: 'نشاط قادم', en: 'To-do planned' } }, { v: 'none', l: { ar: 'بلا نشاط مخطط', en: 'Nothing planned' } },
];
const ORIGIN = [{ v: 'ops', l: { ar: 'أُنشئت هنا', en: 'Made in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[250px]" :model-value="text" :placeholder="ph('بحث بالعنوان، الشركة، الهاتف، البريد، الرقم…', 'Search title, company, phone, e-mail, number…')" @update:model-value="type" @enter="apply" />
    <Tabs v-if="list" :model-value="state" :tabs="LEAD_STATES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k === 'open' ? null : k)" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput v-if="list" small class="w-[150px]" :model-value="nav.get('stage')" :options="stageOptions" :placeholder="ph('كل المراحل', 'Every stage')" @update:model-value="(v) => nav.setFilter('stage', v)" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('owner')" :options="owners" :placeholder="ph('كل المندوبين', 'Every salesperson')" @update:model-value="(v) => nav.setFilter('owner', v)" />
    <SelectInput v-if="teamOptions.length > 1" small class="w-[140px]" :model-value="nav.get('team')" :options="teamOptions" :placeholder="ph('كل الفرق', 'Every team')" @update:model-value="(v) => nav.setFilter('team', v)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('priority')" :options="priorities" :placeholder="ph('كل الأولويات', 'Any priority')" @update:model-value="(v) => nav.setFilter('priority', v)" />
    <SelectInput v-if="tagOptions.length" small class="w-[130px]" :model-value="nav.get('tag')" :options="tagOptions" :placeholder="ph('كل الوسوم', 'Any tag')" @update:model-value="(v) => nav.setFilter('tag', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('activity')" :options="ACTIVITY" :placeholder="ph('أي نشاط', 'Any to-do')" @update:model-value="(v) => nav.setFilter('activity', v)" />
    <SelectInput v-if="follows" small class="w-[130px]" :model-value="nav.get('origin')" :options="ORIGIN" :placeholder="ph('أي مصدر', 'Any origin')" @update:model-value="(v) => nav.setFilter('origin', v)" />
    <Btn v-if="hasLeadFilters" size="sm" tone="ghost" :label="ph('إزالة الفلاتر', 'Clear filters')" @click="nav.clearLeadFilters()" />
  </div>
</template>
