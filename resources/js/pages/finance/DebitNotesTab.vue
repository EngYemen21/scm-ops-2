<script setup>
// Supplier debit notes — the financial document of goods sent back to a supplier (issued by the returns flow: shipping
// a purchase return, or a "to supplier" decision on a customer return). List with status / date filters, drawer with the
// claimed lines, the journals and the links to the return / purchase order / receipt, and cancel with a reason
// (gl.approve): cancelling reverses the note's journal only — the corrected note is issued again from the return.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, Drawer, ErrorBanner, Tabs } from '@/components';
import { bi, fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import FilterBar from '@/pages/procurement/FilterBar.vue';
import ReasonDrawer from '@/pages/procurement/ReasonDrawer.vue';
import { qs } from '@/pages/procurement/shared';
import { DN_LABELS, DN_PRICE_SOURCE_LABELS, JOURNAL_STATUS_LABELS, pname } from './shared';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const sel = computed(() => qs(route.query.note) || null);
const setSel = (n) => router.replace({ query: { ...route.query, note: n || undefined } });

const q = ref('');
const status = ref('');
const from = ref('');
const to = ref('');
const page = ref(1);
const cancelling = ref(false);
watch([q, status, from, to], () => { page.value = 1; });
const list = useList('/finance/debit-notes', () => ({ q: q.value, status: status.value, from: from.value, to: to.value, page: page.value, pageSize: 25 }));
const detailQ = useGet(() => (sel.value ? `/finance/debit-notes/${encodeURIComponent(sel.value)}` : null));
const note = computed(() => (sel.value ? detailQ.data.value : null));
const act = useAction({ invalidate: ['finance', 'returns'] });

// cancel: only a standing note, only with gl.approve — the button is shown disabled with the reason otherwise
const cancelBlock = computed(() => (auth.can('gl.approve') ? null : { ar: 'إلغاء إشعار المدين يحتاج صلاحية اعتماد القيود (gl.approve) — اطلبه من المدير المالي', en: 'Cancelling a debit note needs the journal-approval permission (gl.approve)' }));
async function cancel(reason) {
  const number = note.value.number;
  const r = await act.run(() => api.postIdempotent(`/finance/debit-notes/${encodeURIComponent(number)}/cancel`, { reason }),
    { success: t(`أُلغي ${number} وعُكس قيده — أصدر الإشعار المصحح من صفحة المرتجع`, `${number} cancelled, journal reversed — issue the corrected note from the return`) });
  if (r) { cancelling.value = false; detailQ.refetch(); list.refetch(); }
}
const go = (base, number) => number && router.push(`${base}/${encodeURIComponent(number)}`);
const priceGap = computed(() => (note.value ? Number(note.value.subtotal) - Number(note.value.costTotal) : 0));

const STATUSES = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'issued', label: { ar: 'صادرة', en: 'Issued' } }, { k: 'cancelled', label: { ar: 'ملغاة', en: 'Cancelled' } }];
const columns = [
  { key: 'number', header: { ar: 'الإشعار', en: 'Note' }, width: '130px', kind: 'id' },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(150px,1.3fr)', kind: 'name', value: (r) => pname(r.supplier) },
  { key: 'return', header: { ar: 'المرتجع', en: 'Return' }, width: '130px' },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.date) },
  { key: 'subtotal', header: { ar: 'قبل الضريبة', en: 'Net' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.subtotal) },
  { key: 'vatAmount', header: { ar: 'الضريبة', en: 'VAT' }, width: '90px', kind: 'num', value: (r) => fmtMoney(r.vatAmount) },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.total) },
  { key: 'costTotal', header: { ar: 'تكلفة المخزون', en: 'Stock cost' }, width: '100px', kind: 'num', class: 'muted', value: (r) => fmtMoney(r.costTotal) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '90px', bare: true },
];
const lineCols = [
  { key: 'lineNo', header: '#', width: '30px', kind: 'num', value: (l) => l.lineNo ?? '—' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(150px,1.5fr)', kind: 'name', value: (l) => `${pname(l)} · ${l.sku}` },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '90px', kind: 'id', value: (l) => l.batchNo || '—' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '60px', kind: 'num', value: (l) => fmtNum(l.qty) },
  { key: 'price', header: { ar: 'السعر', en: 'Price' }, width: '80px', kind: 'num', value: (l) => fmtMoney(l.price) },
  { key: 'total', header: { ar: 'القيمة', en: 'Value' }, width: '90px', kind: 'num', value: (l) => fmtMoney(l.total) },
  { key: 'cost', header: { ar: 'تكلفة الخروج', en: 'Cost out' }, width: '90px', kind: 'num', class: 'muted', value: (l) => fmtMoney(l.cost) },
  { key: 'priceSource', header: { ar: 'مصدر السعر', en: 'Price from' }, width: '100px', value: (l) => bi(DN_PRICE_SOURCE_LABELS[l.priceSource]) || '—' },
];
</script>

<template>
  <FilterBar v-model="q">
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <DateInput v-model="from" small class="w-[130px]" /><DateInput v-model="to" small class="w-[130px]" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :empty-text="{ ar: 'لا إشعارات مدينة — يصدر الإشعار تلقائيًا عند شحن مرتجع مورد من المخزون أو عند قرار «للمورد» على مرتجع عميل.', en: 'No debit notes — a note is issued automatically when a purchase return ships or a customer return is decided “to supplier”.' }" @page="page = $event" @row-click="(r) => setSel(r.number)">
      <template #cell-return="{ row }"><span v-if="row.return" class="cell-id cursor-pointer !text-violet" @click.stop="go('/rtn', row.return.number)">{{ row.return.number }}</span><span v-else class="text-faint">—</span></template>
      <template #cell-status="{ row }"><Chip :map="DN_LABELS" :k="row.status" small /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('حركة المخزون «إرجاع للمورد» تخفّض ذمة المورد بتكلفة خروج البضاعة؛ قيد الإشعار يكمل الفرق حتى القيمة الشرائية ويعكس ضريبة المدخلات، فتنخفض الذمة بإجمالي الإشعار بالضبط. إلغاء الإشعار يعكس قيده فقط — البضاعة تبقى مرتجعة — ويُصدر الإشعار المصحح من صفحة المرتجع.', 'The “return to supplier” stock movement lowers the payable by the cost the goods left at; the note’s journal adds the difference up to the purchase value and reverses input VAT, so the payable goes down by exactly the note total. Cancelling reverses the note’s journal only — the goods stay returned — and the corrected note is issued from the return.') }}</div>

  <Drawer :open="!!sel" :width="720" :title="note ? note.number : t('إشعار مدين', 'Debit note')" :sub="note ? `${pname(note.supplier)} · ${fmtDateOnly(note.date)}` : null" @close="setSel(null)">
    <template #headExtra><Chip v-if="note" :map="DN_LABELS" :k="note.status" small /></template>
    <ErrorBanner :error="detailQ.error.value || act.error.value" @close="act.clearError()" />
    <template v-if="note">
      <div v-if="note.status === 'cancelled'" class="banner amber mb-3">
        {{ t(`أُلغي هذا الإشعار${note.cancelledAt ? ' في ' + fmtDateOnly(note.cancelledAt) : ''} وعُكس قيده. البضاعة بقيت مرتجعة وذمة المورد مخفّضة بتكلفتها فقط (${fmtMoney(note.costTotal)}) إلى أن يصدر إشعار مصحح من صفحة المرتجع.`, `This note was cancelled${note.cancelledAt ? ' on ' + fmtDateOnly(note.cancelledAt) : ''} and its journal reversed. The goods stay returned and the payable is lowered by their cost only (${fmtMoney(note.costTotal)}) until a corrected note is issued from the return.`) }}
      </div>
      <div class="kpi-grid compact !mb-3">
        <div class="tile"><div class="tile-v">{{ fmtMoney(note.subtotal) }}</div><div class="tile-l">{{ t('القيمة الشرائية قبل الضريبة', 'Purchase value, net') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(note.vatAmount) }}</div><div class="tile-l">{{ t('ضريبة مدخلات معكوسة', 'Input VAT reversed') }} {{ fmtNum(note.vatPct) }}%</div></div>
        <div class="tile purple"><div class="tile-v">{{ fmtMoney(note.total) }}</div><div class="tile-l">{{ t('إجمالي المطالبة', 'Total claimed') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtMoney(note.costTotal) }}</div><div class="tile-l">{{ t('تكلفة خروج المخزون', 'Stock cost out') }}</div></div>
        <div class="tile" :class="priceGap < 0 ? 'amber' : ''"><div class="tile-v">{{ fmtMoney(priceGap) }}</div><div class="tile-l">{{ t('فرق السعر عن التكلفة', 'Price − cost') }}</div></div>
      </div>
      <div class="kv-grid mb-3">
        <div class="kv"><div class="kv-k">{{ t('المورد', 'Supplier') }}</div><div class="kv-v">{{ pname(note.supplier) }} <span class="num text-faint">{{ note.supplier?.code }}</span></div></div>
        <div v-if="note.supplier?.vatNo" class="kv"><div class="kv-k">{{ t('الرقم الضريبي للمورد', 'Supplier VAT no.') }}</div><div class="kv-v num">{{ note.supplier.vatNo }}</div></div>
        <div v-if="note.return" class="kv"><div class="kv-k">{{ t('المرتجع', 'Return') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="go('/rtn', note.return.number)">{{ note.return.number }}</div></div>
        <div v-if="note.po" class="kv"><div class="kv-k">{{ t('أمر الشراء', 'Purchase order') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="go('/po', note.po.number)">{{ note.po.number }}</div></div>
        <div v-if="note.grn" class="kv"><div class="kv-k">{{ t('سند الاستلام', 'Goods receipt') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="go('/grn', note.grn.number)">{{ note.grn.number }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('أصدره', 'Issued by') }}</div><div class="kv-v">{{ note.createdBy || '—' }}</div></div>
        <div v-if="note.notes" class="kv col-span-full"><div class="kv-k">{{ t('ملاحظات', 'Notes') }}</div><div class="kv-v whitespace-pre-line !font-normal">{{ note.notes }}</div></div>
      </div>
      <div class="field-l mb-1.5">{{ t('القيود', 'Journals') }}</div>
      <div v-if="!note.journals?.length" class="empty">{{ t('بلا قيد — القيمة الشرائية تساوي تكلفة الخروج ولا ضريبة، فحركة المخزون وحدها خفّضت الذمة', 'No journal — the purchase value equals the stock cost and there is no VAT, so the stock movement alone lowered the payable') }}</div>
      <div v-for="j in note.journals || []" :key="j.id" class="row mb-1 cursor-pointer rounded-[11px] border border-line-2 px-[13px] py-[8px] text-[10.5px]" @click="go('/jv', j.number)">
        <span class="num font-extrabold text-violet">{{ j.number }}</span><span class="cell-date ltr">{{ fmtDateOnly(j.journalDate) }}</span><span class="grow truncate text-muted">{{ j.memo }}</span>
        <span class="num">{{ fmtMoney(j.totalDebit) }}</span><Chip :map="JOURNAL_STATUS_LABELS" :k="j.status" small />
      </div>
      <div class="field-l mb-1.5 mt-3">{{ t('الأصناف المطالَب بها', 'Claimed lines') }}</div>
      <div class="overflow-hidden rounded-xl border border-line-2"><DataTable :columns="lineCols" :rows="note.lines || []" :row-key="(l, i) => `${l.movement || l.sku}-${i}`" /></div>
    </template>
    <template v-if="note" #footer>
      <div class="col !gap-1.5">
        <div class="row wrap !gap-1.5">
          <Btn v-if="note.return" tone="soft" :label="{ ar: 'فتح المرتجع', en: 'Open the return' }" @click="go('/rtn', note.return.number)" />
          <Btn v-if="note.status === 'issued'" tone="dangerOutline" :disabled="!!cancelBlock" :title="cancelBlock ? bi(cancelBlock) : null" :label="{ ar: 'إلغاء الإشعار', en: 'Cancel note' }" @click="cancelling = true" />
        </div>
        <div v-if="note.status === 'issued' && cancelBlock" class="text-[10px] text-muted">{{ bi(cancelBlock) }}</div>
      </div>
    </template>
  </Drawer>
  <Teleport v-if="cancelling" to="body">
    <ReasonDrawer open :title="{ ar: `إلغاء ${note?.number}`, en: `Cancel ${note?.number}` }" :sub="{ ar: 'يُعكس قيد الإشعار ويبقى الأصل والعكس في الأستاذ؛ البضاعة تبقى مرتجعة', en: 'The note’s journal is reversed and both stay in the ledger; the goods stay returned' }" :ok-label="{ ar: 'إلغاء الإشعار', en: 'Cancel note' }" :min="5" :pending="act.pending.value" :error="act.error.value" @submit="cancel" @close="cancelling = false" @clear-error="act.clearError()" />
  </Teleport>
</template>
