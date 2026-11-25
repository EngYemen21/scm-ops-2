<script setup>
// The list of one kind of document — any kind: its columns, its filters and what it can be sorted by are the ones
// the catalog declares for it; nothing here knows a kind by name.
// GET /odoo-archive/docs/{kind}?q=&<filters>&sort=&order=&page=&pageSize=30 → the Paging envelope + totals: { amount }.
// The search, every filter, the sort and the page live in the query string under the name of the API parameter:
//   /odoo?kind=sale_order&state=sale&from=2026-01-01&partner=42&q=S00&sort=date&order=desc&page=2
// A row opens the document (`doc=<kind>:<odooId>`) — a row of a list of partners or products opens their view —; a
// partner or a product in a row opens its view. «المحذوفة من أودو» (`deleted=1`) lists what Odoo no longer holds.
// The page is mounted once per kind (keyed by it), so the rows of one kind never show under the columns of another.
// On a phone the rows are cards (DataTable), the filters fold behind one button so the list starts on the first
// screen, and — a card list having no header to click — the sort is picked from a list.
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { bi, fmtNum, t } from '@/i18n';
import { useSearchParam } from '../integration/odoo';
import ArchiveFilter from './ArchiveFilter.vue';
import ArchiveValue from './ArchiveValue.vue';
import { API, ARCHIVED_CHIP, DELETED_CHIP, PRIVATE_CHIP, PRIVATE_HELP, cellOf, defaultSortOf, fmtAmount, isBlank, labelOf, nameIn, sortLabel, sortsOf, tableColumns, totalsOf, useArchive } from './archive';

const props = defineProps({
  /** The catalog entry: { key, label, model, count, private, totals, columns, filters, sorts, defaultSort, section }. */
  kind: { type: Object, required: true },
});

const { nav, list, entityOf, placeOf } = useArchive();
const { filters, values, linked, sort, filtered, deleted } = list;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);

const rows = useList(() => `${API}/docs/${encodeURIComponent(props.kind.key)}`, () => list.params.value);
const d = computed(() => rows.data.value || null);

const sorts = computed(() => sortsOf(props.kind));
const columns = computed(() => tableColumns(props.kind.columns, sorts.value.map((s) => s.key)));
/**
 * The arrow in the header: the sort of the address, else the kind's own order when the catalog states its direction —
 * but the rows of ONE document (`parent=`) come in the order of that document, which is no column's.
 */
const shownSort = computed(() => sort.value || (values.value.parent ? null : defaultSortOf(props.kind)));
// on a phone a table has no header to click, and a sort may be on something that is not a column: a list picks it
const sortPicker = computed(() => sorts.value.length > 0 && (isMobile.value || sorts.value.some((s) => !columns.value.some((c) => c.key === s.key))));
const sortChoices = computed(() => sorts.value.map((s) => [s.key, sortLabel(s, props.kind.columns)]));
const pickSort = (key) => nav.setSort(key ? { key, order: sort.value?.key === key ? sort.value.order : 'desc' } : null);
const flipOrder = () => nav.setSort({ key: sort.value.key, order: sort.value.order === 'asc' ? 'desc' : 'asc' });
// the phone: the filters and the sort open from a button that says how many of them are set
const unfolded = ref(false);
const foldable = computed(() => isMobile.value);
const setCount = computed(() => filters.value.reduce((n, f) => n + (f.type === 'daterange' ? ['from', 'to'].filter((k) => values.value[k]).length : values.value[f.key] ? 1 : 0), 0) + (sort.value ? 1 : 0) + (deleted.value ? 1 : 0));
const foldLabel = computed(() => (setCount.value ? { ar: `التصفية والترتيب (${fmtNum(setCount.value)})`, en: `Filters and sort (${fmtNum(setCount.value)})` } : { ar: 'التصفية والترتيب', en: 'Filters and sort' }));

/** Pills of a section before «+n»: a section of the real catalog may hold a hundred kinds. */
const MANY = 12;
const inSection = computed(() => props.kind.section?.kinds || []);
/**
 * The other kinds of the section, one click away: all of them while they are few; of a long section the first ones
 * somebody described (the catalog lists those first, then the plain models of Odoo), and always this one.
 */
const siblings = computed(() => {
  const all = inSection.value;
  if (all.length <= MANY) return all;
  const described = all.filter((k) => k.plain !== true);
  const few = (described.length ? described : all).slice(0, MANY);
  return few.some((k) => k.key === props.kind.key) ? few : [...few, props.kind];
});
/** The row whose drawer is open: the document of this kind, or — in a list of partners / products — the one whose view is. */
const selectedKey = computed(() => {
  const card = nav.cardOpen.value;
  if (card) return card.type === entityOf(props.kind.key) ? card.id : null;
  return nav.docOpen.value && nav.docOpen.value.kind === props.kind.key ? nav.docOpen.value.id : null;
});
/** A row opens its record: a document its drawer, a partner or a product its view. */
const openRow = (row) => nav.open(placeOf(props.kind.key, row.odooId));
/** Names of the partner / product the list is filtered by, read from the rows on screen: { <filter key>: name }. */
const names = computed(() => {
  const out = {};
  for (const f of filters.value) if ((f.type === 'partner' || f.type === 'product') && values.value[f.key]) out[f.key] = nameIn(d.value?.items, props.kind.columns, f.type, values.value[f.key]);
  for (const x of linked.value) out[x.key] = nameIn(d.value?.items, props.kind.columns, x.entity, x.id);
  return out;
});
const LINK_LABEL = { partner: { ar: 'الشريك', en: 'Partner' }, product: { ar: 'الصنف', en: 'Product' }, parent: { ar: 'تابعة للمستند', en: 'Of document' } };
/** What a link filter is set to, in words: the name the rows carry, else the record's id. */
const linkName = (x) => names.value[x.key] || `#${String(x.id).split(':').pop()}`;

/** The totals line is for kinds that carry an amount (the catalog's `totals`, or a money column). */
const totalled = computed(() => props.kind.totals === true || columns.value.some((c) => c.type === 'money'));
const totals = computed(() => (totalled.value ? totalsOf(d.value) : []));
/** The API gives no total when the matching documents are in more than one currency: the line says so instead of staying silent. */
const noSingleTotal = computed(() => props.kind.totals === true && !!d.value && Number(d.value.total) > 0 && isBlank(d.value.totals?.amount));
const emptyText = computed(() => (deleted.value
  ? { ar: `لا سجلات محذوفة من أودو في «${labelOf(props.kind)}» تطابق ما طلبت. تظهر هنا السجلات التي حُذفت من أودو، بآخر قيمها المحفوظة في الأرشيف.`, en: `No record deleted from Odoo in “${labelOf(props.kind)}” matches. The records deleted from Odoo appear here, with their last values kept in the archive.` }
  : filtered.value
    ? { ar: `لا شيء في «${labelOf(props.kind)}» يطابق هذا البحث أو هذه التصفية. غيّرها أو أزلها لترى بقية السجلات.`, en: `Nothing in “${labelOf(props.kind)}” matches this search or these filters. Change or clear them to see the other records.` }
    : { ar: `لا سجلات في «${labelOf(props.kind)}» بعد. تظهر هنا سجلات أودو من هذا النوع بعد نسخها وفهرستها.`, en: `No record in “${labelOf(props.kind)}” yet. Odoo’s records of this kind appear here once they are copied and indexed.` }));
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
    <RouterLink :to="nav.homeTo()" class="btn soft sm">{{ t('كل الأقسام', 'All sections') }}</RouterLink>
    <div class="min-w-0 flex-1 basis-[200px]">
      <div class="flex min-w-0 flex-wrap items-center gap-2">
        <span class="min-w-0 truncate text-[14px] font-extrabold" dir="auto">{{ labelOf(kind) }}</span>
        <Chip v-if="kind.private" small :label="PRIVATE_CHIP" :fg="PRIVATE_CHIP.fg" :bg="PRIVATE_CHIP.bg" :title="bi(PRIVATE_HELP)" />
      </div>
      <div class="mt-px text-[10px] text-muted">
        <span dir="auto">{{ labelOf(kind.section) }}</span> · {{ t('السجلات في الأرشيف', 'Records in the archive') }} <span class="num ltr">{{ fmtNum(kind.count) }}</span>
      </div>
    </div>
  </div>

  <div v-if="siblings.length > 1" class="pill-bar !mb-0">
    <RouterLink v-for="k in siblings" :key="k.key" :to="nav.kindTo(k.key)" class="pill" :class="{ active: k.key === kind.key }">
      <span dir="auto">{{ labelOf(k) }}</span><span class="num ltr font-normal" :class="k.key === kind.key ? 'opacity-70' : 'text-faint'">{{ fmtNum(k.count) }}</span>
    </RouterLink>
    <!-- the rest of a long section is on the home, under its card -->
    <RouterLink v-if="inSection.length > siblings.length" :to="nav.homeTo()" class="pill" :title="t('بقية أنواع هذا القسم في صفحة الأقسام', 'The other kinds of this section are on the sections page')">
      <span class="num ltr">+{{ fmtNum(inSection.length - siblings.length) }}</span>
    </RouterLink>
  </div>

  <div class="row wrap !items-end">
    <TextInput small type="search" class="w-[240px]" :model-value="qText" :placeholder="{ ar: `بحث في ${labelOf(kind)}…`, en: `Search ${labelOf(kind)}…` }" @update:model-value="typeQ" @enter="applyQ" />
    <!-- set by a link from a partner view, a product view or a document: always in sight, it says why the list is short -->
    <button v-for="x in linked" :key="x.key" type="button" class="pill active max-w-full" :title="t('إزالة هذه التصفية', 'Remove this filter')" @click="nav.setFilter(x.key, null)">
      <span class="min-w-0 truncate" dir="auto">{{ bi(LINK_LABEL[x.entity]) }}: {{ linkName(x) }}</span><span aria-hidden="true">✕</span>
    </button>
    <Btn v-if="foldable" tone="soft" size="sm" :aria-expanded="unfolded" :label="foldLabel" @click="unfolded = !unfolded" />
    <template v-if="!foldable || unfolded">
      <ArchiveFilter v-for="f in filters" :key="f.key" :filter="f" :name="names[f.key] || ''" />
      <template v-if="sortPicker">
        <SelectInput small :label="{ ar: 'الترتيب', en: 'Sort by' }" field-class="w-[170px]" :model-value="sort ? sort.key : ''" :options="sortChoices" :placeholder="{ ar: 'الترتيب الأصلي', en: 'Default order' }" @update:model-value="pickSort" />
        <button v-if="sort" type="button" class="pill" @click="flipOrder">{{ sort.order === 'asc' ? t('تصاعدي', 'Ascending') : t('تنازلي', 'Descending') }}</button>
      </template>
      <!-- what Odoo no longer holds is kept by the archive with its last values: the other half of the list -->
      <button type="button" class="pill" :class="{ active: deleted }" :aria-pressed="deleted" :title="t('السجلات التي حُذفت من أودو وبقيت في الأرشيف بآخر قيمها', 'Records deleted from Odoo, kept in the archive with their last values')" @click="nav.setFilter('deleted', deleted ? null : 1)">{{ t('المحذوفة من أودو', 'Deleted from Odoo') }}</button>
    </template>
    <Btn v-if="filtered || sort" tone="ghost" size="sm" :label="{ ar: 'إزالة التصفية', en: 'Clear filters' }" @click="nav.clearFilters()" />
  </div>

  <ErrorBanner :error="rows.error.value" :closable="false" class="!mb-0" />
  <div v-if="d || !rows.error.value" class="card">
    <DataTable :columns="columns" :paged="d" :loading="rows.isFetching.value" :row-key="(r) => r.odooId" :selected-key="selectedKey" :sort="shownSort" server-sort dense :compact="columns.length > 9" :empty-text="emptyText"
               @sort="nav.setSort" @page="nav.setPage" @row-click="openRow">
      <template v-for="(c, ci) in columns" #[`cell-${c.key}`]="{ row }">
        <div v-if="ci === 0 && (row.active === false || row.deleted === true)" class="flex min-w-0 items-center gap-1.5">
          <ArchiveValue class="min-w-0" lead :type="c.type" :value="cellOf(row, c.key)" :currency="row.currency" :kind="kind.key" :col="c.key" :options="c.options" />
          <Chip v-if="row.deleted === true" small :label="DELETED_CHIP" :fg="DELETED_CHIP.fg" :bg="DELETED_CHIP.bg" />
          <Chip v-else small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        </div>
        <ArchiveValue v-else :lead="ci === 0" :type="c.type" :value="cellOf(row, c.key)" :currency="row.currency" :kind="kind.key" :col="c.key" :options="c.options" />
      </template>
      <template v-if="totals.length || noSingleTotal" #footer>
        <span v-if="totals.length" class="flex flex-wrap items-baseline gap-x-2 font-bold text-sec">
          {{ t('إجمالي المبلغ لكل النتائج المطابقة', 'Total amount of every matching result') }}
          <span v-for="(x, i) in totals" :key="i" class="num ltr text-[11.5px] text-ink">{{ fmtAmount(x.n) }}<span v-if="x.currency" class="ms-1 text-[9px] font-normal text-muted">{{ x.currency }}</span></span>
          <span v-if="totals.length === 1 && totals[0].mixed" class="font-normal text-faint">{{ t('(المستندات بعملات مختلفة)', '(the documents are in several currencies)') }}</span>
        </span>
        <span v-else class="text-faint">{{ t('لا إجمالي واحد: النتائج المطابقة بأكثر من عملة.', 'No single total: the matching results are in more than one currency.') }}</span>
      </template>
    </DataTable>
  </div>
  <div class="hint !mt-0">
    {{ t('هذه بيانات أودو كما نُسخت، للاطلاع والبحث فقط: لا شيء هنا يُعدَّل ولا يُكتب شيء في أودو. المبالغ بعملة أودو وأرقامه، دون إعادة حساب.', 'This is Odoo’s data as copied, to look at and search only: nothing here is edited and nothing is written to Odoo. Amounts keep Odoo’s own figures and currency; nothing is recomputed.') }}
  </div>
</template>
