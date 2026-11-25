<script setup>
// Commission plans (commission.manage or commission.approve to read; writing is the manager's): who earns, on what,
// at which rate, from when to when. GET /commissions/plans?q=&status=&earnerType=&source=&sort=&order=&page=&pageSize=25.
// One plan decides for a person and a month: activating a second plan that names the same person in the same months
// is refused by the server. A row opens the plan (`plan=<code>`); «+ خطة» opens the form.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import PlanForm from './PlanForm.vue';
import { API, BASE, EARNER, EARN_ON, ODOO_CHIP, PAGE_SIZE, PLAN_STATUS, dayText, label, personName, rateText, recordName, useCommissions, useSearchBox } from './commissions';

const { nav, can } = useCommissions();
const { text, type, apply } = useSearchBox(nav);
const list = useList(`${API}/plans`, () => ({ q: nav.get('q'), status: nav.get('status'), earnerType: nav.get('type'), source: nav.get('source'), sort: nav.get('sort'), order: nav.get('order'), page: nav.page.value, pageSize: PAGE_SIZE }));
const d = computed(() => list.data.value || null);
const creating = ref(false);

const STATUSES = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.entries(PLAN_STATUS).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en } }))];
const TYPES = Object.entries(EARNER).map(([v, l]) => [v, l]);
const SOURCES = [['ops', { ar: 'خطط العمليات', en: 'Made in OPS' }], ['odoo', { ar: 'قواعد أودو', en: 'Odoo’s rules' }]];
const columns = [
  { key: 'code', header: { ar: 'الخطة', en: 'Plan' }, width: '128px', sortable: true },
  { key: 'name', header: { ar: 'الاسم', en: 'Name' }, width: 'minmax(180px,1.6fr)', kind: 'name', sortable: true, value: (p) => recordName(p) },
  { key: 'earner', header: { ar: 'من يستحق', en: 'Who earns' }, width: 'minmax(150px,1.2fr)' },
  { key: 'basis', header: { ar: 'على ماذا', en: 'On what' }, width: 'minmax(150px,1.2fr)', class: 'text-[10px]', value: (p) => `${label(BASE, p.base)} · ${label(EARN_ON, p.earnOn)}` },
  { key: 'rate', header: { ar: 'النسبة', en: 'Rate' }, width: '120px', class: 'text-[10px] font-bold', value: (p) => ((p.rules || []).length === 1 ? rateText(p.rules[0]) : t(`${(p.rules || []).length} قواعد`, `${(p.rules || []).length} rules`)) },
  { key: 'validFrom', header: { ar: 'السريان', en: 'Validity' }, width: '170px', kind: 'date', sortable: true, value: (p) => `${dayText(p.validFrom)} – ${p.validTo ? dayText(p.validTo) : '…'}` },
  { key: 'statements', header: { ar: 'كشوف', en: 'Statements' }, width: '64px', kind: 'num', value: (p) => String(p.statements ?? 0) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '96px', sortable: true, bare: true },
];
const shownSort = computed(() => nav.sort.value || null);
const filtered = computed(() => ['q', 'status', 'type', 'source'].some((k) => !!nav.get(k)));
function created(plan) { nav.openPlan(plan.code); }
</script>

<template>
  <div class="row wrap mb-2.5 !items-end">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="{ ar: 'بحث بالرمز أو الاسم أو المستفيد…', en: 'Search code, name or beneficiary…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="nav.get('status')" :tabs="STATUSES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.set('status', k)" />
    <SelectInput small field-class="w-[190px]" :label="{ ar: 'من يستحق', en: 'Who earns' }" :model-value="nav.get('type')" :options="TYPES" :placeholder="{ ar: 'الكل', en: 'All' }" @update:model-value="(v) => nav.set('type', v)" />
    <SelectInput small field-class="w-[140px]" :label="{ ar: 'المصدر', en: 'Source' }" :model-value="nav.get('source')" :options="SOURCES" :placeholder="{ ar: 'الكل', en: 'All' }" @update:model-value="(v) => nav.set('source', v)" />
    <Btn v-if="filtered || nav.sort.value" tone="ghost" size="sm" :label="{ ar: 'إزالة التصفية', en: 'Clear filters' }" @click="nav.patch({ q: null, status: null, type: null, source: null, sort: null, order: null })" />
    <span class="grow" />
    <Btn v-if="can.manage" size="sm" tone="primary" :label="{ ar: '+ خطة عمولات', en: '+ Commission plan' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div v-if="d || !list.error.value" class="card">
    <DataTable :columns="columns" :paged="d" :loading="list.isFetching.value" :row-key="(p) => p.code" :selected-key="nav.get('plan') || null" :sort="shownSort" server-sort
               :empty-text="filtered ? { ar: 'لا خطة تطابق هذا البحث أو هذه التصفية.', en: 'No plan matches this search or these filters.' } : { ar: 'لا خطط عمولات بعد. أنشئ خطة وفعّلها ليبدأ احتساب الشهور التي تغطيها.', en: 'No commission plan yet. Create one and activate it: the months it covers can then be calculated.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(p) => nav.openPlan(p.code)">
      <template #cell-code="{ row }">
        <div class="min-w-0"><div class="cell-id truncate">{{ row.code }}</div><Chip v-if="row.sourceSystem === 'odoo'" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" /></div>
      </template>
      <template #cell-earner="{ row }">
        <div class="min-w-0 text-[10.5px]">
          <div class="truncate font-bold" dir="auto">{{ row.earnerType === 'manager' ? personName(row.beneficiary) : (row.everyone ? t('كل المندوبين', 'Every salesperson') : t(`${(row.members || []).length} مندوب`, `${(row.members || []).length} salespeople`)) }}</div>
          <div class="truncate text-[9.5px] text-muted">{{ row.earnerType === 'manager' ? (row.everyone ? t('مدير — على كل الفواتير', 'Manager — every invoice') : t(`مدير — فريق من ${(row.members || []).length}`, `Manager — a team of ${(row.members || []).length}`)) : t('كلٌّ على مبيعاته', 'Each on his own sales') }}</div>
        </div>
      </template>
      <template #cell-status="{ row }"><Chip :map="PLAN_STATUS" :k="row.status" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('خطة واحدة تحكم المستحق في الشهر: الخطة التي تسمّيه (مديرًا أو عضوًا) تتقدم على خطة «كل المندوبين». شروط الخطة تُقفل بعد أن يُقدَّم كشف صادر عنها — تُنهى بتاريخ نهاية وتُنسخ بالشروط الجديدة.', 'One plan decides for an earner and a month: the plan that names him (as manager or member) comes before the plan for “every salesperson”. A plan’s terms lock once a statement calculated from it is submitted — end it and duplicate it with the new terms.') }}</div>

  <PlanForm :open="creating" @close="creating = false" @done="created" />
</template>
