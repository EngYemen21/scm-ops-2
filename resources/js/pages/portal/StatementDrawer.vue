<script setup>
// One statement of account: its period, the balance it opened and closed with, and its lines — read a page at a time,
// by kind, and branch by branch when its documents name branches. Export (CSV), print, and void with a reason.
//   <StatementDrawer :id="idOrNull" @close="…" @changed="…" />
// GET /portal/statements/{id} → the statement + { branches, events, can };  GET …/lines?page&kind&branch → the page of
// lines + { totals: { debit, credit } } of what is filtered. The page and the filters of the lines live in the query
// string (`lpage`, `lkind`, `lbranch`).
import { computed } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, SelectInput, Tabs, Timeline } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import PartyName from './PartyName.vue';
import { LINE_KIND, OWNED_CHIP, STATEMENT_STATUS, customerLine, day, eventItem, fetchBlob, moment, money, saveBlob, usePortalNav } from './portal';

const props = defineProps({
  /** Id (or OPS number) of the statement; null closes the drawer. */
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'changed']);

const auth = useAuth();
const nav = usePortalNav();
const base = computed(() => (props.id ? `/portal/statements/${encodeURIComponent(props.id)}` : null));
const q = useGet(base);
const s = computed(() => (props.id ? q.data.value || null : null));
const act = useAction({ invalidate: ['portal'] });
const events = computed(() => (s.value?.events || []).map(eventItem));

// ---- the lines, a page at a time
const lpage = computed(() => Math.max(1, Number(nav.get('lpage')) || 1));
const lkind = computed(() => (LINE_KIND[nav.get('lkind')] ? nav.get('lkind') : ''));
const lbranch = computed(() => nav.get('lbranch'));
const lines = useList(() => (base.value ? `${base.value}/lines` : null), () => ({ page: lpage.value, pageSize: 100, kind: lkind.value, branch: lbranch.value }));
const KIND_TABS = [{ k: '', label: { ar: 'كل الحركات', en: 'Every line' } }, ...Object.entries(LINE_KIND).filter(([k]) => k !== 'other').map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en } }))];
const branchOptions = computed(() => [['none', { ar: 'بلا فرع', en: 'No branch' }], ...(s.value?.branches || []).map((b) => [b.id, b.name])]);
const partial = computed(() => !!(lkind.value || lbranch.value));
const columns = computed(() => [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date', value: (l) => day(l.date) },
  { key: 'kind', header: { ar: 'النوع', en: 'Type' }, width: '98px', bare: true },
  { key: 'reference', header: { ar: 'المرجع', en: 'Reference' }, width: '140px', kind: 'id' },
  { key: 'description', header: { ar: 'البيان', en: 'Description' }, width: 'minmax(150px,1.6fr)' },
  { key: 'debit', header: { ar: 'مدين', en: 'Debit' }, width: '92px', kind: 'num' },
  { key: 'credit', header: { ar: 'دائن', en: 'Credit' }, width: '92px', kind: 'num' },
  // a running balance only reads right on the whole statement, in its order
  { key: 'balance', header: { ar: 'الرصيد', en: 'Balance' }, width: '100px', kind: 'num', hidden: partial.value },
]);

async function voidIt() {
  const reason = await ask({ title: { ar: `إلغاء كشف الحساب ${s.value.number}؟`, en: `Void statement ${s.value.number}?` }, sub: { ar: 'يبقى الكشف في القائمة ملغى بسببه، ويمكن إصدار كشف جديد للفترة نفسها.', en: 'The statement stays in the list as void with its reason, and a new one can be issued for the same period.' },
    label: { ar: 'سبب الإلغاء', en: 'Reason' }, required: true, okLabel: { ar: 'إلغاء الكشف', en: 'Void the statement' }, tone: 'danger' });
  if (reason === null) return;
  const r = await act.run(() => api.postIdempotent(`${base.value}/void`, { reason }), { success: t('أُلغي الكشف', 'Statement voided') });
  if (r) { q.refetch(); emit('changed'); }
}
async function exportCsv() {
  const blob = await act.run(() => fetchBlob(`${base.value}/export`), { success: false, invalidate: false });
  if (blob) saveBlob(blob, `statement-${String(s.value.number).replace(/[^A-Za-z0-9_-]+/g, '-')}.csv`);
}
const print = () => globalThis.print();
</script>

<template>
  <Drawer :open="!!id" :width="820" @close="emit('close')">
    <template #title><span class="num ltr">{{ s?.number || t('كشف حساب', 'Statement') }}</span></template>
    <template v-if="s" #sub><span dir="auto">{{ customerLine(s.customer) }}<template v-if="s.branch"> · {{ t('فرع', 'branch') }} {{ s.branch.name }}</template></span> · <span class="num ltr">{{ day(s.dateFrom) }} – {{ day(s.dateTo) }}</span></template>
    <template v-if="s" #headExtra>
      <div class="row !gap-1">
        <Chip small :map="STATEMENT_STATUS" :k="s.status" />
        <Chip v-if="s.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value || lines.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="s">
      <div v-if="s.status === 'void'" class="hint amber !mt-0 mb-3"><b>{{ t('كشف ملغى:', 'Void:') }}</b> {{ s.voidReason }} <span class="text-faint">· {{ s.voidedBy }} · <span class="num ltr">{{ moment(s.voidedAt) }}</span></span></div>
      <div v-if="s.sourceSystem === 'odoo'" class="hint purple !mt-0 mb-3">{{ t('كشف صادر من أودو: أرقامه وأسطره وأرصدته كما أصدرها أودو، لا يُعاد حسابها هنا.', 'A statement issued by Odoo: its figures, lines and balances are as Odoo issued them; nothing is recomputed here.') }}</div>
      <div v-if="s.booksClosing != null && Number(s.booksClosing) !== Number(s.closingBalance)" class="hint amber !mt-0 mb-3">
        {{ t(`عند الإصدار كان رصيد العميل في الأستاذ العام ${money(s.booksClosing)} — الفرق عن الكشف حركة في الدفاتر ليست فاتورة ولا سند قبض (قيد افتتاحي أو يدوي).`, `When it was issued the customer’s balance in the general ledger was ${money(s.booksClosing)} — the difference is an entry of the books that is neither an invoice nor a receipt (an opening or a manual journal).`) }}
      </div>

      <div class="kpi-grid compact !mb-3">
        <div class="tile"><div class="tile-v">{{ money(s.openingBalance) }}</div><div class="tile-l">{{ t('رصيد افتتاحي', 'Opening balance') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(s.totalDebit) }}</div><div class="tile-l">{{ t('مدين (فواتير)', 'Debit (invoices)') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(s.totalCredit) }}</div><div class="tile-l">{{ t('دائن (سداد وإشعارات)', 'Credit (payments, notes)') }}</div></div>
        <div class="tile" :class="Number(s.closingBalance) > 0 ? 'amber' : 'green'"><div class="tile-v">{{ money(s.closingBalance) }}</div><div class="tile-l">{{ t('رصيد ختامي', 'Closing balance') }} {{ s.currency }}</div></div>
      </div>
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'العميل', en: 'Customer' }"><PartyName :party="s.customer" /></KV>
        <KV :k="{ ar: 'الفرع', en: 'Branch' }" :v="s.branch ? s.branch.name : t('العميل كله', 'The whole customer')" />
        <KV :k="{ ar: 'أصدره', en: 'Issued by' }"><span v-if="s.issuedAt"><span dir="auto">{{ s.issuedBy || '—' }}</span> · <span class="num ltr">{{ moment(s.issuedAt) }}</span></span><span v-else class="text-faint">—</span></KV>
        <KV :k="{ ar: 'عدد الحركات', en: 'Lines' }" :v="fmtNum(s.linesCount)" ltr />
        <KV v-if="s.note" class="col-span-full" :k="{ ar: 'ملاحظة', en: 'Note' }" :v="s.note" />
      </div>

      <div class="row wrap mb-2">
        <Tabs :model-value="lkind" :tabs="KIND_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.patch({ lkind: k, lpage: null })" />
        <SelectInput v-if="s.branches.length" small field-class="w-[180px]" :model-value="lbranch" :options="branchOptions" :placeholder="{ ar: 'كل الفروع', en: 'Every branch' }" @update:model-value="(v) => nav.patch({ lbranch: v, lpage: null })" />
      </div>
      <div class="overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="columns" :paged="lines.data.value" :loading="lines.isFetching.value" :row-key="(l) => l.id" dense :empty-text="partial ? { ar: 'لا حركات مطابقة في هذا الكشف.', en: 'No matching line on this statement.' } : { ar: 'لا حركات في فترة هذا الكشف.', en: 'No movement in the period of this statement.' }"
                   @page="(p) => nav.patch({ lpage: p > 1 ? p : null })">
          <template #cell-kind="{ row }"><Chip small :map="LINE_KIND" :k="row.kind" /></template>
          <template #cell-description="{ row }"><span class="truncate" dir="auto" :title="row.description">{{ row.description || '—' }}</span><div v-if="row.branch && !s.branch" class="text-[9px] font-bold text-violet" dir="auto">{{ t('فرع', 'Branch') }} {{ row.branch.name }}</div></template>
          <template #cell-debit="{ row }"><span v-if="Number(row.debit)" class="num">{{ money(row.debit) }}</span><span v-else class="text-faint">—</span></template>
          <template #cell-credit="{ row }"><span v-if="Number(row.credit)" class="num text-ok">{{ money(row.credit) }}</span><span v-else class="text-faint">—</span></template>
          <template #cell-balance="{ row }"><span class="num font-extrabold">{{ money(row.balance) }}</span></template>
          <template v-if="lines.data.value" #footer><span class="me-3 text-[10px] font-bold">{{ partial ? t('مجموع المعروض', 'Total of what is shown') : t('مجموع الكشف', 'Statement total') }}: {{ t('مدين', 'debit') }} <span class="num">{{ money(lines.data.value.totals.debit) }}</span> · {{ t('دائن', 'credit') }} <span class="num">{{ money(lines.data.value.totals.credit) }}</span></span></template>
        </DataTable>
      </div>

      <template v-if="events.length">
        <div class="card-title mb-1 mt-4">{{ t('السجل', 'History') }}</div>
        <Timeline :items="events" />
      </template>
    </template>

    <template v-if="s" #footer>
      <div class="row wrap !gap-1.5">
        <Btn tone="soft" :label="{ ar: 'طباعة', en: 'Print' }" @click="print" />
        <Btn v-if="auth.can('report.export')" tone="soft" :loading="act.pending.value" :label="{ ar: 'تصدير CSV', en: 'Export CSV' }" @click="exportCsv" />
        <Btn v-if="auth.can('portal.manage') && s.can.void" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء الكشف', en: 'Void the statement' }" @click="voidIt" />
      </div>
    </template>
  </Drawer>
</template>
