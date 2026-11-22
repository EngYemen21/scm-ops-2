<script setup>
// Odoo tab → records: browse the copy, one model at a time — the records Odoo holds now, or (the switch) the ones
// that were deleted from Odoo and are kept here with their last values.
// GET /integration/odoo/records?model=&q=&deleted=1&sort=&order=&page= (the model is required, so nothing is asked
// before one is picked). A row opens the record drawer (`rec=<model>:<id>`).
// Model, search, switch, sort and page live in the query string:  /itower?tab=odoo&view=records&model=res.partner&q=acme
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, EmptyState, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { RECORD_STATE, agoAt, fmtAt, recordState, useOdooModels, useOdooNav, useSearchParam } from './odoo';

const nav = useOdooNav();
const { page, recordOpen } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const models = useOdooModels();
const modelsLoaded = models.loaded;
const quickPicks = computed(() => models.withRecords.value.slice(0, 10));

const model = computed(() => nav.modelParam());
const deleted = computed(() => (nav.get('deleted') === '1' ? '1' : ''));
/** The API sorts by the Odoo id or (default) by the last change in the copy. */
const sort = computed(() => (['odooId', 'changedAt'].includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const list = useList(() => (model.value ? '/integration/odoo/records' : null), () => ({
  model: model.value, q: nav.get('q'), deleted: deleted.value, sort: sort.value?.key === 'odooId' ? 'odooId' : undefined, order: sort.value?.order, page: page.value, pageSize: 30,
}));

const modelOptions = computed(() => models.options(model.value));
/** Another model is another list: the search and the page start again. */
const pickModel = (name) => nav.patch({ model: name, q: null, page: null, sort: null, order: null });
const deletedTabs = [{ k: '', label: { ar: 'الموجودة في أودو', en: 'In Odoo now' } }, { k: '1', label: { ar: 'المحذوفة من أودو', en: 'Deleted from Odoo' } }];
const selectedKey = computed(() => (recordOpen.value && recordOpen.value.model === model.value ? recordOpen.value.id : null));

const columns = computed(() => [
  { key: 'record', header: { ar: 'السجل', en: 'Record' }, width: 'minmax(220px,2fr)' },
  { key: 'odooId', header: { ar: 'رقمه في أودو', en: 'Odoo id' }, width: '104px', sortable: true },
  { key: 'state', header: { ar: 'حالته في أودو', en: 'State in Odoo' }, width: '118px', bare: true },
  { key: 'writeDate', header: { ar: 'آخر تعديل في أودو', en: 'Last written in Odoo' }, width: '136px' },
  { key: 'version', header: { ar: 'الإصدار', en: 'Version' }, width: '72px', kind: 'num' },
  { key: 'changedAt', header: deleted.value ? { ar: 'رُصد حذفه', en: 'Deletion noticed' } : { ar: 'آخر تغيّر في النسخة', en: 'Last change in the copy' }, width: '132px', sortable: true },
]);
</script>

<template>
  <div class="row wrap">
    <SelectInput small class="w-[280px]" :model-value="model" :options="modelOptions" :placeholder="{ ar: '— اختر نموذجًا —', en: '— pick a model —' }" @update:model-value="pickModel" />
    <template v-if="model">
      <TextInput small type="search" class="w-[230px]" :model-value="qText" :placeholder="{ ar: 'بحث بالاسم أو برقم السجل…', en: 'Search by name or record id…' }" @update:model-value="typeQ" @enter="applyQ" />
      <Tabs :model-value="deleted" :tabs="deletedTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('deleted', k)" />
    </template>
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" class="!mb-0" />

  <EmptyState v-if="!model" tone="dashed" :text="modelsLoaded && !quickPicks.length
    ? { ar: 'لا سجلات في النسخة بعد — تظهر النماذج هنا بعد أن تنسخ المزامنة سجلاتها.', en: 'The copy holds no record yet — models appear here once a run has copied their records.' }
    : { ar: 'اختر نموذجًا لتصفّح سجلاته المحفوظة في النسخة.', en: 'Pick a model to browse its records kept in the copy.' }">
    <template v-if="quickPicks.length" #action>
      <div class="flex flex-wrap justify-center gap-1.5">
        <button v-for="m in quickPicks" :key="m.model" type="button" class="pill" @click="pickModel(m.model)">
          <span dir="auto">{{ m.label || m.model }}</span> <span class="num ltr text-faint">{{ fmtNum(m.mirroredCount) }}</span>
        </button>
      </div>
    </template>
  </EmptyState>

  <div v-else class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.odooId" :selected-key="selectedKey" :sort="sort" server-sort dense
               :empty-text="deleted ? { ar: 'لا سجلات محذوفة مطابقة في هذا النموذج.', en: 'No matching deleted record in this model.' } : { ar: 'لا سجلات مطابقة في هذا النموذج.', en: 'No matching record in this model.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openRecord(r.model, r.odooId)">
      <template #cell-record="{ row }"><div class="truncate text-[11px] font-extrabold text-ink [text-align:match-parent]" dir="auto">{{ row.displayName || t('بلا اسم', 'No name') }}</div></template>
      <template #cell-odooId="{ row }"><span class="cell-id">#{{ row.odooId }}</span></template>
      <template #cell-state="{ row }">
        <Chip v-if="recordState(row)" small :map="RECORD_STATE" :k="recordState(row)" />
        <span v-else class="text-faint" :title="t('هذا النموذج بلا خاصية أرشفة', 'This model has no archive flag')">—</span>
      </template>
      <template #cell-writeDate="{ row }"><span class="num ltr">{{ fmtAt(row.writeDate) }}</span></template>
      <template #cell-changedAt="{ row }"><span class="num-mixed" :title="fmtAt(row.changedAt)">{{ agoAt(row.changedAt) }}</span></template>
    </DataTable>
  </div>
</template>
