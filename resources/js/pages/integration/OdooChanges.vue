<script setup>
// Odoo tab → changes: the feed of what happened in Odoo, in the order OPS learned it (newest first) — a record was
// created, updated (with the names of the values that changed), archived, restored or deleted.
// GET /integration/odoo/changes?model=&kind=&q=&page=. A row opens the record drawer (`rec=<model>:<id>`).
// Filters and page live in the query string:  /itower?tab=odoo&view=changes&model=sale.order&kind=updated
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import OdooFieldChips from './OdooFieldChips.vue';
import { CHANGE_KIND, CHANGE_KINDS, agoAt, fmtAt, useOdooModels, useOdooNav, useSearchParam } from './odoo';

const nav = useOdooNav();
const { page } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const models = useOdooModels();
const labels = models.labels;

const model = computed(() => nav.modelParam());
const kind = computed(() => (CHANGE_KINDS.includes(nav.get('kind')) ? nav.get('kind') : ''));
const list = useList('/integration/odoo/changes', () => ({ model: model.value, kind: kind.value, q: nav.get('q'), page: page.value, pageSize: 30 }));

const modelOptions = computed(() => models.options(model.value));
const kindTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...CHANGE_KINDS.map((k) => ({ k, label: CHANGE_KIND[k] }))];
const filtered = computed(() => !!(model.value || kind.value || nav.get('q')));

const columns = [
  { key: 'seenAt', header: { ar: 'الوقت', en: 'When' }, width: '124px' },
  { key: 'model', header: { ar: 'النموذج', en: 'Model' }, width: 'minmax(150px,1fr)' },
  { key: 'record', header: { ar: 'السجل', en: 'Record' }, width: 'minmax(200px,1.6fr)' },
  { key: 'kind', header: { ar: 'ما حدث', en: 'What happened' }, width: '92px', bare: true },
  { key: 'fields', header: { ar: 'القيم التي تغيّرت', en: 'Values that changed' }, width: 'minmax(200px,1.5fr)' },
];
</script>

<template>
  <div class="row wrap">
    <TextInput small type="search" class="w-[220px]" :model-value="qText" :placeholder="{ ar: 'بحث باسم السجل أو برقمه…', en: 'Search by record name or id…' }" @update:model-value="typeQ" @enter="applyQ" />
    <SelectInput small class="w-[250px]" :model-value="model" :options="modelOptions" :placeholder="{ ar: 'كل النماذج', en: 'Every model' }" @update:model-value="(v) => nav.setFilter('model', v)" />
    <Tabs :model-value="kind" :tabs="kindTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('kind', k)" />
    <span class="grow" />
    <Btn tone="soft" size="sm" :loading="list.isFetching.value" :label="{ ar: 'تحديث', en: 'Refresh' }" @click="list.refetch()" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" class="!mb-0" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" dense
               :empty-text="filtered ? { ar: 'لا تغييرات مطابقة.', en: 'No matching changes.' } : { ar: 'لا تغييرات مرصودة بعد. النسخة الأولى لا تُحسب تغييرات — يظهر هنا ما يتغيّر في أودو بعدها.', en: 'No change seen yet. The first copy is not counted as changes — what changes in Odoo after it appears here.' }"
               @page="nav.setPage" @row-click="(r) => nav.openRecord(r.model, r.odooId)">
      <template #cell-seenAt="{ row }">
        <span class="num ltr">{{ fmtAt(row.seenAt) }}</span>
        <div class="mt-px text-[8.5px] text-faint">{{ agoAt(row.seenAt) }}</div>
      </template>
      <template #cell-model="{ row }">
        <div class="min-w-0">
          <div class="cell-id ltr truncate">{{ row.model }}</div>
          <div v-if="labels[row.model]" class="truncate text-[9px] text-muted [text-align:match-parent]" dir="auto">{{ labels[row.model] }}</div>
        </div>
      </template>
      <template #cell-record="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto">{{ row.displayName || t('بلا اسم', 'No name') }}</div>
          <div class="cell-sub">#{{ row.odooId }}</div>
        </div>
      </template>
      <template #cell-kind="{ row }"><Chip small :map="CHANGE_KIND" :k="row.kind" /></template>
      <template #cell-fields="{ row }"><OdooFieldChips :fields="row.fields" /></template>
    </DataTable>
  </div>
  <div class="hint !mt-0">
    {{ t('كل سطر حدث واحد رُصد في أودو: إنشاء سجل، تعديل قيم فيه (تظهر أسماء القيم التي تغيّرت)، أرشفة، استعادة أو حذف. الحذف يُكشف بمقارنة دورية، فيظهر هنا عند المقارنة التالية لا لحظة وقوعه. افتح السطر لترى السجل كاملًا وتاريخه.', 'Each line is one thing seen in Odoo: a record created, values changed in it (their names are listed), archived, restored or deleted. A deletion is found by a periodic comparison, so it shows up at the next comparison, not the moment it happened. Open a line to see the whole record and its history.') }}
  </div>
</template>
