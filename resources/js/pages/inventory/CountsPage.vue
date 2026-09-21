<script setup>
// Cycle counts (nav `counts`): schedule form, count card with sheet entry (blind mode hides the system qty),
// start → enter → complete → approve (inventory.adjust) → close flow, and the counts list / history.
// Deep link: ?id=CNT-… selects a count.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, NumberInput, PageHead, ProgressBar, ScanInput, SectionCard, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import Hint from './Hint.vue';
import ProductCell from './ProductCell.vue';
import ScheduleDrawer from './ScheduleDrawer.vue';
import StatusTimeline from './StatusTimeline.vue';
import { COUNT_LABELS, COUNT_SCOPES, COUNT_TYPES, dictLabel, fmtSigned, pillTabs, pname, signColor, useDebounced } from './shared';

/**
 * @typedef {{ id: string, systemQty: number|null, countedQty: number|null, variance?: number|null, product: { sku: string, nameAr: string, nameEn: string },
 *   bin: { code: string, zone: { code: string, type: string } }, batch: { batchNo: string, expiryDate: string|null }|null }} CountLine
 * @typedef {{ id: string, number: string, status: 'open'|'counting'|'variance'|'adjusted'|'closed', type: string, scope: string|null, blind: boolean, freeze: boolean,
 *   date: string, counter: string|null, warehouse: { code: string, nameAr: string, nameEn: string }, zone: { code: string, nameAr: string, nameEn: string, type: string }|null,
 *   lines: CountLine[], progress: { total: number, counted: number, variances?: number }, allowed: string[],
 *   history?: { id: string, toStatus: string, username: string|null, note: string|null, at: string }[],
 *   movements?: { number: string, type: string, qty: number, dstBinId: string|null }[] }} Count
 */
const STATUS_PILLS = pillTabs([{ k: '', ar: 'الكل', en: 'All' }, { k: 'open', ar: 'مجدول', en: 'Scheduled' }, { k: 'counting', ar: 'قيد العد', en: 'Counting' }, { k: 'variance', ar: 'فروقات', en: 'Variance' }, { k: 'adjusted', ar: 'مسوّى', en: 'Adjusted' }, { k: 'closed', ar: 'مُغلق', en: 'Closed' }]);

const auth = useAuth();
const wh = useWarehouse();
const route = useRoute();
const router = useRouter();

// ---- selection lives in the URL (?id=) ----
const sel = computed(() => (typeof route.query.id === 'string' && route.query.id ? route.query.id : null));
function setSel(id) {
  const n = { ...route.query };
  if (id) n.id = id; else delete n.id;
  router.replace({ query: n });
}

const status = ref('');
const q = ref('');
const dq = useDebounced(q);
const page = ref(1);
const schedOpen = ref(false);
watch([() => wh.wh, status, dq], () => { page.value = 1; });

const list = useList('/inventory/counts', () => ({ ...wh.whParams, status: status.value, q: dq.value, page: page.value, pageSize: 25 }));
// Nothing selected yet → open the first count of the list.
watch([sel, () => list.data.value], () => { if (!sel.value && list.data.value?.items.length) setSel(list.data.value.items[0].number); }, { immediate: true });
const detail = useGet(() => (sel.value ? `/inventory/counts/${encodeURIComponent(sel.value)}` : null));
/** @type {import('vue').ComputedRef<Count|undefined>} */
const c = computed(() => detail.data.value);
const act = useAction({ invalidate: ['inventory'] });

// ---- count sheet entries (local until saved): { [lineId]: number | null } ----
const entries = ref({});
// Re-seeded only when another count is opened or its status changes — a background refetch never wipes unsaved typing.
watch(() => (c.value ? `${c.value.id}|${c.value.status}` : ''), () => { if (c.value) entries.value = Object.fromEntries(c.value.lines.map((l) => [l.id, l.countedQty])); }, { immediate: true });
const dirty = computed(() => !!c.value && c.value.lines.some((l) => (entries.value[l.id] ?? null) !== (l.countedQty ?? null)));
const canEnter = computed(() => !!c.value && c.value.status === 'counting' && auth.can('inventory.count'));
/** Blind count: the system quantity stays hidden from the counter until the count is completed. */
const hide = computed(() => !!c.value && c.value.blind && ['open', 'counting'].includes(c.value.status) && !auth.can('inventory.adjust'));
const setEntry = (id, v) => { entries.value = { ...entries.value, [id]: v }; };
function lineVariance(l) {
  if (hide.value) return null;
  const e = entries.value[l.id];
  return canEnter.value && e != null && l.systemQty != null ? e - l.systemQty : l.variance ?? null;
}

// ---- actions ----
const post = (action, body, success) => act.run(() => api.postIdempotent(`/inventory/counts/${c.value.id}/${action}`, body), { success });
async function saveEntries() {
  const lines = c.value.lines.filter((l) => entries.value[l.id] != null && entries.value[l.id] !== l.countedQty).map((l) => ({ lineId: l.id, countedQty: entries.value[l.id] }));
  if (!lines.length) return true;
  return (await post('enter', { lines }, { ar: `حُفظت ${lines.length} كمية معدودة`, en: `${lines.length} counted quantities saved` })) !== undefined;
}
async function onStart() {
  const sub = c.value.freeze ? t('ستُجمد صفوف النطاق — لا حركات على هذه المواقع حتى اعتماد التسوية', 'Rows in scope will be frozen until the adjustment is approved') : t('سيُعاد أخذ لقطة رصيد النظام قبل العد', 'System quantities are re-snapshotted before counting');
  if (await confirm({ title: t('بدء العد؟', 'Start counting?'), sub, tone: 'primary', okLabel: t('بدء العد', 'Start') })) await post('start', undefined, { ar: 'بدأ العد', en: 'Counting started' });
}
async function onComplete() {
  const missing = c.value.lines.filter((l) => (entries.value[l.id] ?? null) == null).length;
  if (missing) { await confirm({ title: t(`${missing} سطر لم يُعد بعد`, `${missing} line(s) not counted yet`), sub: t('أدخل كل الكميات قبل إنهاء العد', 'Enter every quantity before completing'), okLabel: t('حسنًا', 'OK'), tone: 'dark' }); return; }
  if (!(await saveEntries())) return;
  if (await confirm({ title: t('إنهاء العد وعرض الفروقات؟', 'Complete count and show variances?'), sub: t('لن يمكن تعديل الكميات بعد الإنهاء', 'Quantities cannot be edited afterwards'), tone: 'primary', okLabel: t('إنهاء العد', 'Complete') })) await post('complete', undefined, { ar: 'اكتمل العد — راجع الفروقات', en: 'Count completed — review variances' });
}
async function onApprove() {
  const v = c.value.progress.variances || 0;
  const sub = v ? t(`ستُسجل ${v} حركة تسوية (adj) في السجل ويُرفع التجميد`, `${v} adj movement(s) will be posted and rows unfrozen`) : t('لا فروقات — سيُرفع التجميد فقط', 'No variances — rows are unfrozen only');
  if (await confirm({ title: t('اعتماد التسوية؟', 'Approve adjustment?'), sub, tone: 'dark', okLabel: t('اعتماد', 'Approve') })) await post('approve', undefined, { ar: 'اعتُمدت التسوية وسُجلت الحركات', en: 'Adjustment approved and posted' });
}
async function onCloseCount() {
  if (await confirm({ title: t('إقفال الجرد؟', 'Close count?'), tone: 'dark', okLabel: t('إقفال', 'Close') })) await post('close', undefined, { ar: 'أُقفل الجرد', en: 'Count closed' });
}

// ---- presentation ----
/** Count entry: a scanned (or typed) bin / SKU / batch narrows the sheet to its lines. */
const lineScan = ref('');
const shownLines = computed(() => {
  const s = lineScan.value.trim().toLowerCase();
  const all = c.value?.lines || [];
  return s ? all.filter((l) => [l.bin?.code, l.product?.sku, l.batch?.batchNo, ...(l.product?.barcodes || []).map((b) => b.barcode)].some((x) => String(x || '').toLowerCase() === s)) : all;
});

const lineCols = [
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(180px,1.5fr)' },
  { key: 'bin', header: { ar: 'الموقع Bin', en: 'Bin' }, width: '110px' },
  { key: 'sys', header: { ar: 'رصيد النظام', en: 'System qty' }, width: '110px', kind: 'num' },
  { key: 'cnt', header: { ar: 'المعدود', en: 'Counted' }, width: '120px' },
  { key: 'dv', header: { ar: 'الفرق', en: 'Variance' }, width: '90px', kind: 'num' },
];
const listCols = [
  { key: 'number', header: { ar: 'الجرد', en: 'Count' }, width: '118px', kind: 'id' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '120px' },
  { key: 'scope', header: { ar: 'المستودع · النطاق', en: 'Warehouse · scope' }, width: 'minmax(150px,1.2fr)' },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.date) },
  { key: 'counter', header: { ar: 'العدّاد', en: 'Counter' }, width: '100px', kind: 'muted', value: (r) => r.counter || '—' },
  { key: 'progress', header: { ar: 'التقدم', en: 'Progress' }, width: '120px' },
  { key: 'flags', header: '', width: '90px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px' },
];
const pct = (p) => (p.total ? (p.counted / p.total) * 100 : 0);
const scopeText = (r) => (r.zone ? `${r.zone.code} ${pname(r.zone)}` : r.scope && COUNT_SCOPES[r.scope] ? dictLabel(COUNT_SCOPES, r.scope) : '—');
const metaLine = computed(() => {
  const x = c.value;
  if (!x) return '';
  const scope = x.zone ? ` · ${t('منطقة', 'Zone')} ${x.zone.code} ${pname(x.zone)}` : x.scope && COUNT_SCOPES[x.scope] ? ` · ${dictLabel(COUNT_SCOPES, x.scope)}` : '';
  return `${x.warehouse.code} · ${lang.value === 'ar' ? x.warehouse.nameAr : x.warehouse.nameEn}${scope} · ${fmtDateOnly(x.date)}${x.counter ? ` · ${t('العدّاد', 'Counter')}: ${x.counter}` : ''}`;
});
const lineRowStyle = (l) => (!hide.value && l.countedQty != null && l.systemQty != null && l.countedQty !== l.systemQty ? { background: '#FFF8F8' } : null);
const hint = computed(() => {
  const x = c.value;
  if (!x) return '';
  if (x.status === 'open') return t('بدء العد يُعيد لقطة رصيد النظام ويجمد صفوف النطاق (إن اختير التجميد) — لا حركات على هذه المواقع أثناء العد.', 'Starting re-snapshots system quantities and freezes the rows in scope (if freeze is on) — no movements on those bins while counting.');
  if (x.status === 'counting') return x.blind ? t('عد أعمى — رصيد النظام مخفي عن العدّاد. أدخل الكميات الفعلية ثم «إنهاء العد» لكشف الفروقات.', 'Blind count — system quantity hidden from the counter. Enter physical quantities, then Complete to reveal variances.') : t('أدخل الكميات المعدودة لكل سطر (تُحفظ على دفعات) ثم أنهِ العد لعرض الفروقات.', 'Enter counted quantities per line (saved in batches), then complete to see variances.');
  if (x.status === 'variance') return t('اعتماد التسوية يُسجل حركة adj لكل فرق في سجل الحركات ويرفع التجميد — يتطلب صلاحية inventory.adjust.', 'Approving posts an adj movement per variance to the ledger and unfreezes the rows — requires inventory.adjust.');
  if (x.status === 'adjusted') return t('تمت التسوية وسُجلت الحركات — أقفل الجرد لإنهاء الدورة.', 'Adjusted and posted — close the count to finish the cycle.');
  return t('دورة جرد مقفلة — للمراجعة فقط.', 'Closed count — read-only.');
});
const defaultWh = computed(() => (wh.isAll ? wh.warehouses[0]?.code || '' : wh.wh));
</script>

<template>
  <PageHead :sub="t('إنشاء ← تجميد ← عد ← فروقات ← اعتماد ← تسوية Transaction', 'Schedule → freeze → count → variances → approve → adjustment transaction')">
    <Btn v-if="auth.can('inventory.count')" tone="dark" size="sm" :label="{ ar: '+ جدولة جرد', en: '+ Schedule count' }" @click="schedOpen = true" />
  </PageHead>

  <SectionCard>
    <div>
      <ErrorBanner :error="detail.error.value || act.error.value" :closable="!!act.error.value" @close="act.clearError()" />
      <div v-if="!sel && !list.isLoading.value && !list.data.value?.items.length" class="empty dashed">{{ t('لا جرود بعد — ابدأ بجدولة جرد دوري', 'No counts yet — schedule a cycle count') }}</div>
      <div v-if="detail.isLoading.value" class="skel h-20" />
      <template v-if="c">
        <div class="row wrap !gap-2.5">
          <div>
            <div class="text-[14px] font-extrabold"><span class="num">{{ c.number }}</span> <span class="text-[10px] text-faint">· {{ dictLabel(COUNT_TYPES, c.type) }}</span></div>
            <div class="mt-[3px] text-[10px] text-muted">{{ metaLine }}</div>
          </div>
          <Chip :map="COUNT_LABELS" :k="c.status" />
          <Chip v-if="c.blind" small :label="{ ar: 'عد أعمى', en: 'Blind' }" fg="#654e92" bg="#efeaf8" />
          <Chip v-if="c.freeze" small :label="{ ar: 'تجميد الحركات', en: 'Freeze' }" fg="#0d7f93" bg="#d9f4f9" />
          <div class="flex-1" />
          <Btn v-if="c.status === 'open' && auth.can('inventory.count')" tone="primary" :loading="act.pending.value" :label="{ ar: 'بدء العد وتجميد الحركات', en: 'Start count & freeze' }" @click="onStart" />
          <Btn v-if="canEnter" tone="soft" :loading="act.pending.value" :disabled="!dirty" :label="{ ar: 'حفظ المدخلات', en: 'Save entries' }" @click="saveEntries" />
          <Btn v-if="canEnter" tone="primary" :loading="act.pending.value" :label="{ ar: 'إنهاء العد', en: 'Complete count' }" @click="onComplete" />
          <Btn v-if="c.status === 'variance' && auth.can('inventory.adjust')" tone="primary" :loading="act.pending.value" :label="{ ar: 'اعتماد التسوية', en: 'Approve adjustment' }" @click="onApprove" />
          <Btn v-if="c.status === 'adjusted' && auth.can('inventory.count')" tone="dark" :loading="act.pending.value" :label="{ ar: 'إقفال الجرد', en: 'Close count' }" @click="onCloseCount" />
        </div>
        <div class="row mt-3 !gap-3.5">
          <div class="flex-1"><ProgressBar :pct="pct(c.progress)" :label="{ ar: `المعدود ${fmtNum(c.progress.counted)} / ${fmtNum(c.progress.total)}`, en: `Counted ${fmtNum(c.progress.counted)} / ${fmtNum(c.progress.total)}` }" show-pct /></div>
          <Chip v-if="c.progress.variances != null" :label="{ ar: `فروقات: ${fmtNum(c.progress.variances)}`, en: `Variances: ${fmtNum(c.progress.variances)}` }" :fg="c.progress.variances ? '#b23b3b' : '#1d7a3e'" :bg="c.progress.variances ? '#fdecec' : '#e6f9ec'" />
        </div>
        <div v-if="canEnter" class="row mt-3.5 !items-end">
          <ScanInput v-model="lineScan" small :clear-on-submit="false" field-class="min-w-[220px] flex-1" :placeholder="{ ar: 'امسح الموقع أو الصنف أو الدفعة لإظهار سطره فقط', en: 'Scan a bin, SKU or batch to show only its lines' }" />
          <Btn v-if="lineScan" size="sm" tone="ghost" :label="{ ar: 'عرض كل الأسطر', en: 'Show all lines' }" @click="lineScan = ''" />
          <span v-if="lineScan" class="text-[10.5px] font-extrabold text-muted"><span class="num">{{ shownLines.length }}</span> / <span class="num">{{ c.lines.length }}</span></span>
        </div>
        <div class="mt-3.5">
          <DataTable :columns="lineCols" :rows="shownLines" dense :min-width="640" :row-key="(l) => l.id" :page-size="50" :row-style="lineRowStyle">
            <template #cell-product="{ row }"><ProductCell :p="row.product">{{ row.product.sku }}{{ row.batch ? ` · ${row.batch.batchNo}` : '' }}</ProductCell></template>
            <template #cell-bin="{ row }"><span class="cell-id">{{ row.bin.code }}<span class="muted text-[8.5px]"> · {{ row.bin.zone.code }}</span></span></template>
            <template #cell-sys="{ row }">
              <span v-if="hide || row.systemQty == null" class="muted" :title="t('عد أعمى — مخفي حتى إنهاء العد', 'Blind count — hidden until completed')">🔒 —</span>
              <span v-else class="text-muted">{{ fmtNum(row.systemQty) }}</span>
            </template>
            <template #cell-cnt="{ row }">
              <NumberInput v-if="canEnter" small center class="!h-[30px] !w-[90px]" :model-value="entries[row.id]" :min="0" :step="1" placeholder="—" @update:model-value="setEntry(row.id, $event)" />
              <span v-else class="cell-num">{{ row.countedQty == null ? '—' : fmtNum(row.countedQty) }}</span>
            </template>
            <template #cell-dv="{ row }">
              <span v-if="lineVariance(row) == null" class="muted">—</span>
              <span v-else :style="{ color: signColor(lineVariance(row)) }">{{ lineVariance(row) === 0 ? '0' : fmtSigned(lineVariance(row)) }}</span>
            </template>
          </DataTable>
        </div>
        <Hint tone="teal" class="!mt-3">{{ hint }}</Hint>
        <template v-if="c.movements?.length">
          <div class="mx-0.5 mb-2 mt-4 text-[11px] font-extrabold text-muted">{{ t('حركات التسوية المسجلة', 'Posted adjustment movements') }} <span class="card-count num">{{ c.movements.length }}</span></div>
          <div class="row wrap !gap-1.5">
            <Chip v-for="m in c.movements" :key="m.number" small class="cursor-pointer" :map="MOVEMENT_LABELS" :k="m.type" @click="router.push(`/ledger?referenceNumber=${encodeURIComponent(c.number)}`)"><span class="num ltr">{{ m.number }} · {{ m.dstBinId ? '+' : '−' }}{{ fmtNum(m.qty) }}</span></Chip>
          </div>
        </template>
        <template v-if="c.history?.length">
          <div class="mx-0.5 mb-1 mt-4 text-[11px] font-extrabold text-muted">{{ t('الخط الزمني', 'Timeline') }}</div>
          <StatusTimeline :history="c.history" :map="COUNT_LABELS" />
        </template>
      </template>
    </div>
  </SectionCard>

  <div class="row wrap mt-3.5">
    <Tabs v-model="status" :tabs="STATUS_PILLS" variant="pill" class="!mb-0" />
    <div class="flex-1" />
    <TextInput v-model="q" small type="search" class="min-w-[220px]" :placeholder="{ ar: 'بحث: رقم / منطقة / عدّاد', en: 'Search: number / zone / counter' }" />
  </div>
  <SectionCard class="mt-2.5" :padded="false" :title="{ ar: 'الجرود — الحالية والسابقة', en: 'Counts — current & history' }" :count="list.data.value?.total">
    <ErrorBanner :error="list.error.value" :closable="false" class="m-3" />
    <DataTable :columns="listCols" :paged="list.data.value" :loading="list.isFetching.value" :min-width="900" :row-key="(r) => r.id" :selected-key="c?.id ?? null" :empty-text="{ ar: 'لا جرود مطابقة.', en: 'No matching counts.' }"
               @row-click="(r) => setSel(r.number)" @page="page = $event">
      <template #cell-type="{ row }"><span class="text-[10.5px] font-extrabold">{{ dictLabel(COUNT_TYPES, row.type) }}</span></template>
      <template #cell-scope="{ row }"><span class="text-[10.5px]"><b>{{ row.warehouse.code }}</b> · {{ scopeText(row) }}</span></template>
      <template #cell-progress="{ row }">
        <div><ProgressBar :pct="pct(row.progress)" :height="6" /><div class="cell-sub">{{ fmtNum(row.progress.counted) }} / {{ fmtNum(row.progress.total) }}{{ row.progress.variances != null ? ` · ${t('فروقات', 'var.')} ${fmtNum(row.progress.variances)}` : '' }}</div></div>
      </template>
      <template #cell-flags="{ row }">
        <div class="row wrap !gap-1"><Chip v-if="row.blind" small :label="{ ar: 'أعمى', en: 'Blind' }" fg="#654e92" bg="#efeaf8" /><Chip v-if="row.freeze" small :label="{ ar: 'تجميد', en: 'Freeze' }" fg="#0d7f93" bg="#d9f4f9" /></div>
      </template>
      <template #cell-status="{ row }"><Chip small :map="COUNT_LABELS" :k="row.status" /></template>
    </DataTable>
  </SectionCard>

  <ScheduleDrawer :open="schedOpen" :default-wh="defaultWh" @close="schedOpen = false" @done="(r) => setSel(r.number)" />
</template>
