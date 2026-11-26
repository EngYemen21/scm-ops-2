<script setup>
// The documents behind a figure of a return — GET /tax/returns/{id}/entries?box=<1–12|corrections|unclassified|outside>
// with filters column / side / docKind / mismatch, search, sort (date · number · base · vat · partner) and paging; the
// totals of the filter come with the page. CSV of the same rows (report.export) through the authenticated download.
// A row opens the tax code of its document (DocumentCodeDrawer).
import { computed, ref, watch } from 'vue';
import { useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, SelectInput, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import DocumentCodeDrawer from './DocumentCodeDrawer.vue';
import { ANY, COLUMN, DOC_KIND, HOW, SIDE, TONE, boxLabel, downloadFile, fmtDay, labelOf, optionsOf, pct, sar, signClass, useLocalSearch, useTaxLookups } from './tax';

const props = defineProps({
  returnId: { type: String, default: null },
  /** `1`–`12`, `corrections`, `unclassified`, `outside` (null = closed). */
  box: { type: String, default: null },
  /** `amount` | `adjustment` (null = both). */
  column: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const lk = useTaxLookups();
const page = ref(1);
const sort = ref(null);
const f = ref({ column: '', side: '', docKind: '', mismatch: '' });
const search = useLocalSearch(() => { page.value = 1; });
watch(() => [props.returnId, props.box, props.column], () => {
  page.value = 1; sort.value = null; search.reset();
  f.value = { column: props.column || '', side: '', docKind: '', mismatch: '' };
}, { immediate: true });
const setF = (k, v) => { f.value = { ...f.value, [k]: v }; page.value = 1; };

const filters = computed(() => ({ box: props.box || undefined, column: f.value.column || undefined, side: f.value.side || undefined, docKind: f.value.docKind || undefined, mismatch: f.value.mismatch || undefined }));
const list = useList(() => (props.returnId && props.box ? `/tax/returns/${encodeURIComponent(props.returnId)}/entries` : null),
  () => ({ ...filters.value, q: search.applied.value || undefined, sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 50 }));
const data = computed(() => list.data.value || null);
const totals = computed(() => data.value?.totals || null);

const SPECIAL = { corrections: { ar: 'التصحيحات (مستندات متأخرة)', en: 'Corrections (late documents)' }, unclassified: { ar: 'مستندات بلا تصنيف', en: 'Unclassified documents' }, outside: { ar: 'مستندات خارج الإقرار', en: 'Documents outside the return' } };
const title = computed(() => (props.box ? (SPECIAL[props.box] ? bi(SPECIAL[props.box]) : `${t('البند', 'Line')} ${boxLabel(lk.boxMap.value, props.box)}`) : ''));
const sub = computed(() => [data.value?.return?.periodCode, props.column ? labelOf(COLUMN, props.column) : null].filter(Boolean).join(' · '));

const dl = useAction({ invalidate: false });
const canExport = computed(() => auth.can('report.export'));
const exportCsv = () => dl.run(() => downloadFile(`/tax/returns/${encodeURIComponent(props.returnId)}/entries/export`, filters.value, `vat-return-${props.box}.csv`), { success: (name) => t(`نُزِّل ${name}`, `Downloaded ${name}`) });

const docOpen = ref(null);
const MISMATCH = [{ v: 'true', l: { ar: 'فرق بين الدفاتر والمستند فقط', en: 'Books ≠ document only' } }];
const columns = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '86px', kind: 'date', sortable: true, value: (r) => fmtDay(r.date) },
  { key: 'number', header: { ar: 'المستند', en: 'Document' }, width: 'minmax(130px,1fr)', sortable: true },
  { key: 'partner', header: { ar: 'الطرف', en: 'Partner' }, width: 'minmax(130px,1.2fr)', sortable: true },
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '120px' },
  { key: 'rate', header: { ar: 'النسبة', en: 'Rate' }, width: '60px', kind: 'num', value: (r) => pct(r.rate) },
  { key: 'base', header: { ar: 'الأساس', en: 'Base' }, width: '100px', kind: 'num', sortable: true },
  { key: 'vat', header: { ar: 'الضريبة', en: 'VAT' }, width: '100px', kind: 'num', sortable: true },
];
</script>

<template>
  <Drawer :open="!!returnId && !!box" :title="title" :sub="sub" :width="980" :z-index="70" @close="emit('close')">
    <div class="row wrap mb-2 items-end gap-2">
      <TextInput :model-value="search.text.value" small field-class="w-full sm:w-[210px]" :placeholder="{ ar: 'بحث بالمستند أو الطرف أو الرمز…', en: 'Search document, partner, code…' }" @update:model-value="search.type" @enter="search.apply" />
      <SelectInput :model-value="f.column" small :options="optionsOf(COLUMN)" :placeholder="{ ar: '— المبلغ والتعديل —', en: '— amount & adjustment —' }" @update:model-value="(v) => setF('column', v)" />
      <SelectInput :model-value="f.side" small :options="[{ v: 'sale', l: SIDE.sale }, { v: 'purchase', l: SIDE.purchase }]" :placeholder="ANY" @update:model-value="(v) => setF('side', v)" />
      <SelectInput :model-value="f.docKind" small :options="optionsOf(DOC_KIND)" :placeholder="{ ar: '— كل المستندات —', en: '— all documents —' }" @update:model-value="(v) => setF('docKind', v)" />
      <SelectInput :model-value="f.mismatch" small :options="MISMATCH" :placeholder="ANY" @update:model-value="(v) => setF('mismatch', v)" />
      <div class="grow" />
      <Btn v-if="canExport" size="sm" tone="soft" :loading="dl.pending.value" :label="{ ar: 'تصدير CSV', en: 'Export CSV' }" @click="exportCsv" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <ErrorBanner :error="dl.error.value" @close="dl.clearError()" />
    <div v-if="totals" class="mb-2 flex flex-wrap gap-2 text-[10.5px]">
      <span class="tile !py-1.5">{{ t('مستندات', 'Documents') }}: <b class="num">{{ fmtNum(totals.count) }}</b></span>
      <span class="tile !py-1.5">{{ t('الأساس', 'Base') }}: <b class="num" :class="signClass(totals.base)">{{ sar(totals.base) }}</b></span>
      <span class="tile !py-1.5">{{ t('الضريبة', 'VAT') }}: <b class="num" :class="signClass(totals.vat)">{{ sar(totals.vat) }}</b></span>
    </div>
    <DataTable :columns="columns" :paged="data" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
               :empty-text="{ ar: 'لا مستندات خلف هذا الرقم', en: 'No document behind this figure' }"
               @sort="(s) => { sort = s; page = 1; }" @page="(p) => (page = p)" @row-click="(r) => (docOpen = r)">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-name num truncate">{{ row.docNumber }}</div>
          <div class="truncate text-[9px] text-muted">
            {{ labelOf(DOC_KIND, row.docKind) }}<template v-if="row.reversal"> · {{ t('عكس', 'reversal') }}</template>
            <template v-if="row.late"> · {{ t('متأخر من', 'late of') }} <span class="num">{{ row.lateOfPeriod }}</span></template>
            <template v-if="row.journalNumber"> · <span class="num ltr">{{ row.journalNumber }}</span></template>
          </div>
        </div>
      </template>
      <template #cell-partner="{ row }"><span class="truncate text-[10.5px]" dir="auto">{{ row.partner?.name || '—' }}</span></template>
      <template #cell-code="{ row }">
        <div class="min-w-0 text-center">
          <div class="num text-[10px] font-bold">{{ row.code || '—' }}</div>
          <Chip small :map="HOW" :k="row.classified" />
        </div>
      </template>
      <template #cell-base="{ row }"><span class="num" :class="signClass(row.base)">{{ sar(row.base) }}</span></template>
      <template #cell-vat="{ row }">
        <span class="num" :class="[signClass(row.vat), row.mismatch ? 'font-extrabold' : '']" :style="row.mismatch ? { color: TONE.warn.fg } : null"
              :title="row.mismatch ? t(`على المستند ${sar(row.docVat)}`, `On the document ${sar(row.docVat)}`) : null">{{ sar(row.vat) }}<template v-if="row.notional"> *</template></span>
      </template>
    </DataTable>
    <div class="mt-2 text-[9.5px] text-faint">{{ t('* ضريبة احتساب عكسي ليست في الدفاتر. الرقم بلون التنبيه: ضريبة الدفاتر تختلف عن المكتوبة على المستند (مرّر المؤشر لرؤيتها). اضغط مستندًا لرؤية رمزه الضريبي وتغييره.', '* reverse-charge VAT not in the books. A figure in amber: the books\' VAT differs from the document\'s (hover to see it). Click a document to see and change its tax code.') }}</div>
  </Drawer>

  <DocumentCodeDrawer :doc-type="docOpen?.docType || null" :doc-id="docOpen?.docId || null" @close="docOpen = null" />
</template>
