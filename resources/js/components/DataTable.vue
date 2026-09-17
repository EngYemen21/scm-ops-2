<script setup>
// Grid table: sortable columns (client- or server-side), zebra rows, horizontal scroll, pagination footer.
//
//   <DataTable :columns="cols" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number"
//              :selected-key="sel" :min-width="900" @page="page = $event" @row-click="(r) => sel = r.number">
//     <template #cell-status="{ row }"><Chip :map="SO_LABELS" :k="row.status" /></template>
//   </DataTable>
//
// Column: { key, header: string | {ar,en}, width: '1.4fr' | '90px' | 'minmax(120px,1fr)', kind: 'id'|'name'|'num'|'date'|'muted',
//           ltr, align: 'start'|'center'|'end', sortable, sortValue: (row) => any, value: (row, i) => string, hidden, class }
// Cell content, in order of precedence: slot `#cell-<key>="{ row, index }"` → `column.value(row, index)` → formatted `row[key]`.
// Data: `paged` = server page { items, total, page, pageSize, pages } (emits `page`), or plain `rows` (+ optional `pageSize`).
// Sorting: pass `sort` + listen to `sort` for server-side sorting; otherwise the visible rows are sorted client-side.
import { computed, ref } from 'vue';
import { bi, fmtNum, isBi, t } from '../i18n';
import Icon from '../layout/Icon.vue';
import EmptyState from './EmptyState.vue';

const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: null },
  paged: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  rowKey: { type: Function, default: null },
  selectedKey: { type: [String, Number], default: null },
  /** `@row-click="(row, index) => …"` — declared as a prop so the table knows whether rows are clickable. */
  onRowClick: { type: Function, default: null },
  sort: { type: Object, default: undefined },
  serverSort: { type: Boolean, default: false },
  pageSize: { type: Number, default: null },
  emptyText: { type: [String, Object], default: null },
  minWidth: { type: Number, default: null },
  zebra: { type: Boolean, default: true },
  dense: { type: Boolean, default: false },
  stickyHead: { type: Boolean, default: false },
  maxHeight: { type: [Number, String], default: null },
  rowStyle: { type: Function, default: null },
});
const emit = defineEmits(['sort', 'page']);

const cols = computed(() => props.columns.filter((c) => !c.hidden));
const localSort = ref(null);
const localPage = ref(1);
const effSort = computed(() => (props.sort !== undefined ? props.sort : localSort.value));
const source = computed(() => props.paged?.items ?? props.rows ?? []);

const sorted = computed(() => {
  const s = effSort.value;
  if (props.serverSort || !s) return source.value;
  const col = cols.value.find((c) => c.key === s.key);
  if (!col) return source.value;
  const val = (r) => (col.sortValue ? col.sortValue(r) : r[col.key]);
  const d = s.order === 'asc' ? 1 : -1;
  return [...source.value].sort((a, b) => {
    const x = val(a); const y = val(b);
    if (x == null) return 1;
    if (y == null) return -1;
    return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true })) * d;
  });
});

const visible = computed(() => (!props.paged && props.pageSize ? sorted.value.slice((localPage.value - 1) * props.pageSize, localPage.value * props.pageSize) : sorted.value));
const total = computed(() => props.paged?.total ?? sorted.value.length);
const page = computed(() => props.paged?.page ?? localPage.value);
const pages = computed(() => props.paged?.pages ?? (props.pageSize ? Math.max(1, Math.ceil(sorted.value.length / props.pageSize)) : 1));
const size = computed(() => props.paged?.pageSize ?? props.pageSize ?? total.value);
const showPager = computed(() => pages.value > 1 || !!props.paged);
const rangeFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * size.value + 1));
const rangeTo = computed(() => Math.min(total.value, page.value * size.value));
const isClickable = computed(() => !!props.onRowClick);

function goPage(p) {
  if (p < 1 || p > pages.value) return;
  if (props.paged) emit('page', p); else localPage.value = p;
}
function clickSort(c) {
  if (!c.sortable) return;
  const s = effSort.value;
  const next = s?.key === c.key ? { key: c.key, order: s.order === 'asc' ? 'desc' : 'asc' } : { key: c.key, order: 'asc' };
  if (props.serverSort || props.sort !== undefined) emit('sort', next); else localSort.value = next;
}

const template = computed(() => cols.value.map((c) => c.width || '1fr').join(' '));
const kindClass = (c) => ({ id: 'cell-id', name: 'cell-name', num: 'cell-num', date: 'cell-date', muted: 'muted' }[c.kind] || '');
const cellClass = (c) => ['gt-cell', { ltr: c.ltr || ['num', 'date', 'id'].includes(c.kind), c: c.align === 'center', e: c.align === 'end' }, kindClass(c), c.class];
function cellValue(c, r, i) {
  if (c.value) return c.value(r, i);
  const v = r[c.key];
  if (v == null || v === '') return '—';
  if (typeof v === 'number') return fmtNum(v, Number.isInteger(v) ? 0 : 2);
  if (typeof v === 'boolean') return v ? '✓' : '—';
  return String(v);
}
const keyOf = (r, i) => (props.rowKey ? props.rowKey(r, i) : i);

const pageWindow = computed(() => {
  const p = page.value; const n = pages.value;
  if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1);
  const arr = [...new Set([1, n, p - 1, p, p + 1].filter((x) => x >= 1 && x <= n))].sort((a, b) => a - b);
  const out = [];
  arr.forEach((x, i) => { if (i > 0 && x - arr[i - 1] > 1) out.push(0); out.push(x); });
  return out;
});
</script>

<template>
  <div class="gt-wrap" :style="{ maxHeight: typeof maxHeight === 'number' ? maxHeight + 'px' : maxHeight, overflowY: maxHeight ? 'auto' : null }">
    <div class="gt" :style="{ '--gt-cols': template, '--gt-min': minWidth ? minWidth + 'px' : null }">
      <div class="gt-head" :style="{ position: stickyHead ? 'sticky' : 'static', padding: dense ? '7px 14px' : null }">
        <div v-for="c in cols" :key="c.key" class="gt-cell" :class="{ c: c.align === 'center', e: c.align === 'end' }">
          <span v-if="c.sortable" class="sortable" @click="clickSort(c)"><slot :name="`head-${c.key}`">{{ isBi(c.header) ? bi(c.header) : c.header }}</slot><Icon name="sort" :dir="effSort?.key === c.key ? effSort.order : null" /></span>
          <slot v-else :name="`head-${c.key}`">{{ isBi(c.header) ? bi(c.header) : c.header }}</slot>
        </div>
      </div>

      <template v-if="loading && source.length === 0">
        <div v-for="i in 6" :key="i" class="gt-row" :style="{ padding: dense ? '8px 14px' : null }">
          <div v-for="c in cols" :key="c.key" class="gt-cell"><div class="skel h-3" :style="{ width: `${55 + ((i * 17 + c.key.length * 7) % 40)}%` }" /></div>
        </div>
      </template>
      <slot v-else-if="visible.length === 0" name="empty"><div class="gt-empty"><EmptyState :text="emptyText" class="!p-0" /></div></slot>
      <template v-else>
        <div v-for="(r, i) in visible" :key="keyOf(r, i)" class="gt-row" :class="{ zebra, click: isClickable, sel: selectedKey != null && keyOf(r, i) === selectedKey }"
             :style="[{ padding: dense ? '8px 14px' : null, opacity: loading ? 0.6 : 1 }, rowStyle ? rowStyle(r, i) : null]" @click="onRowClick && onRowClick(r, i)">
          <div v-for="c in cols" :key="c.key" :class="cellClass(c)"><slot :name="`cell-${c.key}`" :row="r" :index="i">{{ cellValue(c, r, i) }}</slot></div>
        </div>
      </template>
    </div>

    <div v-if="showPager || $slots.footer" class="gt-foot">
      <slot name="footer" />
      <span class="flex-1">{{ t('عرض', 'Showing') }} <b class="num">{{ fmtNum(rangeFrom) }}–{{ fmtNum(rangeTo) }}</b> {{ t('من', 'of') }} <b class="num">{{ fmtNum(total) }}</b></span>
      <div v-if="showPager" class="row !gap-1">
        <button type="button" class="pg-btn" :disabled="page <= 1" @click="goPage(page - 1)">‹</button>
        <template v-for="(p, i) in pageWindow" :key="`${p}-${i}`">
          <span v-if="p === 0" class="px-1">…</span>
          <button v-else type="button" class="pg-btn" :class="{ active: p === page }" @click="goPage(p)">{{ p }}</button>
        </template>
        <button type="button" class="pg-btn" :disabled="page >= pages" @click="goPage(page + 1)">›</button>
      </div>
    </div>
  </div>
</template>
