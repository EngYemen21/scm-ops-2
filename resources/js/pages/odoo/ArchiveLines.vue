<script setup>
// One child list of a document — its lines — as a table whose columns are the ones the API declares for it (a state
// column carries the wording of its values). The document arrives with the first 200 rows; when it says there are
// more, «عرض كل الأسطر» reads them page by page:
// GET /odoo-archive/docs/{child kind}?parent=<model>:<odooId>&page=&pageSize=50 — the `query` the section carries,
// else built from the document — with `lines=<section key>` and `lp=<page>` in the query string.
// A row opens the line as a record of its own when its kind is in the user's catalog; a product or a partner in a
// row opens its view.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, DataTable, ErrorBanner } from '@/components';
import { fmtNum, t } from '@/i18n';
import ArchiveValue from './ArchiveValue.vue';
import { API, LINES_PAGE_SIZE, arr, cellOf, isId, isKindKey, isObj, labelOf, tableColumns, useArchive } from './archive';

const props = defineProps({
  /** The document the lines belong to: { model, odooId }. */
  parent: { type: Object, required: true },
  /** { key, label, kind, columns, rows: [{ odooId, cells }], total, more, query?: { parent } } */
  section: { type: Object, required: true },
});

const { nav, kindOf, placeOf } = useArchive();
/** Key of the kind the lines are records of; null when the API did not name one. */
const childKey = computed(() => (isKindKey(props.section.kind) ? props.section.kind : null));
const child = computed(() => kindOf(childKey.value));
const embedded = computed(() => arr(props.section.rows).filter(isObj));
const count = computed(() => { const n = Number(props.section.total); return props.section.total != null && Number.isFinite(n) ? n : embedded.value.length; });
/** What asks the list of the child kind for the lines of this document. */
const scope = computed(() => {
  if (isObj(props.section.query) && typeof props.section.query.parent === 'string') return props.section.query;
  return typeof props.parent.model === 'string' && isId(props.parent.odooId) ? { parent: `${props.parent.model}:${props.parent.odooId}` } : null;
});
/** Every line can be read only when the API named the child kind and the document the lines hang on. */
const readable = computed(() => !!props.section.more && props.section.key != null && !!childKey.value && !!scope.value);
const full = computed(() => readable.value && nav.get('lines') === String(props.section.key));
const all = useList(() => (full.value ? `${API}/docs/${encodeURIComponent(childKey.value)}` : null), () => ({ ...scope.value, page: nav.linesPage.value, pageSize: LINES_PAGE_SIZE }));
/** The paged list answers in the columns of the child kind's own list; the embedded rows in the section's. */
const columns = computed(() => tableColumns(full.value && child.value ? child.value.columns : props.section.columns));

const toggle = () => nav.patch({ lines: full.value ? null : props.section.key, lp: null });
const setPage = (p) => nav.patch({ lp: p > 1 ? p : null });
const openLine = (row) => { if (isId(row.odooId)) nav.open(placeOf(childKey.value, row.odooId)); };
const emptyText = { ar: 'لا أسطر في هذه القائمة لهذا المستند.', en: 'This document has no line in this list.' };
</script>

<template>
  <div>
    <div class="mb-1.5 flex flex-wrap items-center gap-2">
      <div class="text-[12px] font-extrabold" dir="auto">{{ labelOf(section) || t('الأسطر', 'Lines') }}</div>
      <span class="num ltr text-[9.5px] font-bold text-faint">{{ fmtNum(count) }}</span>
      <span class="flex-1" />
      <Btn v-if="readable" tone="ghost" size="sm" :label="full ? { ar: 'عرض مختصر', en: 'Short view' } : { ar: `عرض كل الأسطر (${fmtNum(count)})`, en: `Show every line (${fmtNum(count)})` }" @click="toggle" />
    </div>
    <ErrorBanner v-if="full" :error="all.error.value" :closable="false" />
    <!-- an amount of a line shows a currency only when the row itself carries one: the lines of a document are not always in its currency (journal items) -->
    <div class="overflow-hidden rounded-xl border border-line-2">
      <DataTable v-if="full" :columns="columns" :paged="all.data.value" :loading="all.isFetching.value" :row-key="(r) => r.odooId" dense :compact="columns.length > 8" :empty-text="emptyText"
                 :on-row-click="child ? openLine : null" @page="setPage">
        <template v-for="(c, ci) in columns" #[`cell-${c.key}`]="{ row }"><ArchiveValue :lead="ci === 0" :type="c.type" :value="cellOf(row, c.key)" :currency="row.currency" :kind="childKey" :col="c.key" :options="c.options" /></template>
      </DataTable>
      <DataTable v-else :columns="columns" :rows="embedded" :row-key="(r, i) => (r.odooId == null ? i : r.odooId)" dense :compact="columns.length > 8" :empty-text="emptyText" :on-row-click="child ? openLine : null">
        <template v-for="(c, ci) in columns" #[`cell-${c.key}`]="{ row }"><ArchiveValue :lead="ci === 0" :type="c.type" :value="cellOf(row, c.key)" :currency="row.currency" :kind="childKey" :col="c.key" :options="c.options" /></template>
      </DataTable>
    </div>
    <div v-if="section.more && !full" class="mt-1 text-[9.5px] text-faint">
      {{ t(`المعروض هنا أول ${fmtNum(embedded.length)} من ${fmtNum(count)}.`, `The first ${fmtNum(embedded.length)} of ${fmtNum(count)} are shown here.`) }}
    </div>
  </div>
</template>
