<script setup>
// E-invoices: the summary strip (GET /tax/einvoices/summary — the mode said exactly as the server says it), and three
// views in the query string (`view=`):
//   register  GET /tax/einvoices — filters status · profile · docKind · from · to · current=all · signed · issues=yes,
//             search, sort (icv · issueAt · number · total · status · buyer), paging; a row opens the e-invoice (`doc=`)
//   pending   invoices that have no e-invoice yet and why each waits (EinvoicePending.vue)
//   chain     the proof of the hash chain of a unit (EinvoiceChain.vue)
import { computed } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtDate, fmtNum, t } from '@/i18n';
import EinvoiceChain from './EinvoiceChain.vue';
import EinvoicePending from './EinvoicePending.vue';
import { ANY, DOC_KIND, PROFILE_KIND, labelOf, optionsOf, sar, useSearchParam, useTaxLookups, useTaxNav } from './tax';

const nav = useTaxNav();
const lk = useTaxLookups();
const summary = useGet('/tax/einvoices/summary');
const s = computed(() => summary.data.value || null);

const VIEWS = ['register', 'pending', 'chain'];
const view = computed(() => (VIEWS.includes(nav.get('view')) ? nav.get('view') : 'register'));
const views = computed(() => [
  { k: 'register', label: { ar: 'السجل', en: 'Register' }, badge: s.value?.total || null },
  { k: 'pending', label: { ar: 'بانتظار الإنشاء', en: 'Waiting to be made' }, badge: s.value?.pending || null },
  { k: 'chain', label: { ar: 'سلسلة البصمات', en: 'Hash chain' } },
]);
const setView = (v) => nav.go('einvoices', v === 'register' ? {} : { view: v });

// ---- register
const search = useSearchParam(nav);
const SORTS = ['icv', 'issueAt', 'number', 'total', 'status', 'buyer'];
const sort = nav.sortOf(SORTS);
const FILTERS = ['status', 'profile', 'docKind', 'from', 'to', 'current', 'signed', 'issues'];
const list = useList(() => (view.value === 'register' ? '/tax/einvoices' : null), () => ({
  q: nav.get('q') || undefined, ...Object.fromEntries(FILTERS.map((k) => [k, nav.get(k) || undefined])),
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const hasFilters = computed(() => ['q', ...FILTERS].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ ...Object.fromEntries(['q', ...FILTERS].map((k) => [k, null])), page: null });
const statusTabs = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.entries(lk.einvoiceStates.value).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en }, badge: s.value?.counts?.[k] || null }))]);
const EI_KINDS = { invoice: DOC_KIND.invoice, credit_note: DOC_KIND.credit_note, debit_note: DOC_KIND.debit_note };
const CURRENT = [{ v: 'all', l: { ar: 'كل النسخ (مع المستبدلة)', en: 'All versions (with replaced)' } }];
const SIGNED = [{ v: 'true', l: { ar: 'موقّعة', en: 'Signed' } }, { v: 'false', l: { ar: 'غير موقّعة', en: 'Not signed' } }];
const ISSUES = [{ v: 'yes', l: { ar: 'بملاحظات تحقق', en: 'With validation issues' } }];

const columns = [
  { key: 'icv', header: { ar: 'العدّاد', en: 'ICV' }, width: '64px', kind: 'id', sortable: true },
  { key: 'number', header: { ar: 'المستند', en: 'Document' }, width: 'minmax(130px,1fr)', sortable: true },
  { key: 'buyer', header: { ar: 'المشتري', en: 'Buyer' }, width: 'minmax(140px,1.3fr)', sortable: true },
  { key: 'profile', header: { ar: 'النوع', en: 'Type' }, width: '104px' },
  { key: 'issueAt', header: { ar: 'الإصدار', en: 'Issued' }, width: '118px', kind: 'date', sortable: true, value: (r) => fmtDate(r.issueAt) },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '104px', kind: 'num', sortable: true },
  { key: 'issues', header: { ar: 'ملاحظات', en: 'Issues' }, width: '78px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '128px', sortable: true, bare: true },
];
</script>

<template>
  <!-- summary strip -->
  <ErrorBanner :error="summary.error.value" :closable="false" />
  <div v-if="s" class="banner !mb-2.5" :class="s.mode === 'sending' ? 'green' : 'amber'" role="status">
    <div class="flex-1">
      <b>{{ s.mode === 'not_onboarded' ? t('بانتظار الربط مع الهيئة (Integration Pending)', 'Integration Pending') : s.mode === 'paused' ? t('الإرسال موقوف', 'Sending paused') : t('يُرسل إلى الهيئة', 'Sending to the authority') }}:</b>
      {{ bi(s.modeText) }}
    </div>
  </div>
  <div v-else-if="summary.isLoading.value" class="skel mb-2.5 h-[44px]" />
  <div v-if="s" class="mb-2.5 flex flex-wrap gap-2 text-[10.5px]">
    <span class="tile !py-1.5">{{ t('سارية', 'Current') }}: <b class="num">{{ fmtNum(s.total) }}</b></span>
    <button type="button" class="tile !py-1.5" :class="s.pending ? 'amber' : ''" @click="setView('pending')">{{ t('بلا فاتورة إلكترونية', 'Without an e-invoice') }}: <b class="num">{{ fmtNum(s.pending) }}</b></button>
    <button type="button" class="tile !py-1.5" :class="s.withIssues ? 'red' : ''" @click="nav.go('einvoices', { issues: 'yes' })">{{ t('بملاحظات', 'With issues') }}: <b class="num">{{ fmtNum(s.withIssues) }}</b></button>
    <span class="tile !py-1.5" :class="s.overdue ? 'red' : ''">{{ t('مبسطة متأخرة', 'Simplified overdue') }}: <b class="num">{{ fmtNum(s.overdue) }}</b></span>
    <button type="button" class="tile !py-1.5" :class="s.stuck ? 'red' : ''" @click="nav.go('einvoices', { status: 'queued' })">{{ t('عالقة', 'Stuck') }}: <b class="num">{{ fmtNum(s.stuck) }}</b></button>
    <span class="tile !py-1.5" :class="s.cancelledAfterAcceptance ? 'red' : ''">{{ t('أُلغيت بعد القبول', 'Cancelled after acceptance') }}: <b class="num">{{ fmtNum(s.cancelledAfterAcceptance) }}</b></span>
    <span v-if="!s.profileReady" class="tile amber !py-1.5">{{ t('بيانات المكلّف ناقصة', 'Taxpayer data incomplete') }}: <b class="num">{{ fmtNum(s.profileMissing?.length || 0) }}</b></span>
  </div>

  <Tabs :model-value="view" :tabs="views" variant="sm" @update:model-value="setView" />

  <template v-if="view === 'register'">
    <div class="row wrap mb-2 items-end gap-2">
      <TextInput :model-value="search.text.value" small field-class="w-full sm:w-[230px]" :placeholder="{ ar: 'بحث بالرقم أو المشتري أو UUID أو العداد…', en: 'Search number, buyer, UUID, ICV…' }" @update:model-value="search.type" @enter="search.apply" />
      <SelectInput :model-value="nav.get('profile')" small :options="optionsOf(PROFILE_KIND)" :placeholder="{ ar: '— قياسية ومبسطة —', en: '— standard & simplified —' }" @update:model-value="(v) => nav.setFilter('profile', v)" />
      <SelectInput :model-value="nav.get('docKind')" small :options="optionsOf(EI_KINDS)" :placeholder="{ ar: '— كل المستندات —', en: '— all documents —' }" @update:model-value="(v) => nav.setFilter('docKind', v)" />
      <DateInput :model-value="nav.get('from')" small :label="{ ar: 'من', en: 'From' }" @update:model-value="(v) => nav.setFilter('from', v)" />
      <DateInput :model-value="nav.get('to')" small :label="{ ar: 'إلى', en: 'To' }" :min="nav.get('from') || null" @update:model-value="(v) => nav.setFilter('to', v)" />
      <SelectInput :model-value="nav.get('current')" small :options="CURRENT" :placeholder="{ ar: '— السارية فقط —', en: '— current only —' }" @update:model-value="(v) => nav.setFilter('current', v)" />
      <SelectInput :model-value="nav.get('signed')" small :options="SIGNED" :placeholder="ANY" @update:model-value="(v) => nav.setFilter('signed', v)" />
      <SelectInput :model-value="nav.get('issues')" small :options="ISSUES" :placeholder="ANY" @update:model-value="(v) => nav.setFilter('issues', v)" />
      <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'مسح الفلاتر', en: 'Clear filters' }" @click="clear" />
    </div>
    <Tabs :model-value="nav.get('status')" :tabs="statusTabs" variant="pill" class="mb-2.5" @update:model-value="(v) => nav.setFilter('status', v)" />
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('doc') || null" :sort="sort" server-sort
                 :empty-text="hasFilters ? { ar: 'لا فواتير إلكترونية تطابق البحث والفلاتر.', en: 'No e-invoice matches the search and filters.' } : { ar: 'لا فواتير إلكترونية بعد.', en: 'No e-invoice yet.' }"
                 @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.patch({ doc: r.id })">
        <template #cell-icv="{ row }"><span class="num font-extrabold">{{ row.icv }}</span></template>
        <template #cell-number="{ row }">
          <div class="min-w-0">
            <div class="cell-name num truncate">{{ row.docNumber }}</div>
            <div class="truncate text-[9px] text-muted">{{ labelOf(DOC_KIND, row.docKind) }}<template v-if="!row.isCurrent"> · {{ t('مستبدلة', 'replaced') }}</template><template v-if="row.attemptNo > 1"> · {{ t('النسخة', 'version') }} <span class="num">{{ row.attemptNo }}</span></template></div>
          </div>
        </template>
        <template #cell-buyer="{ row }">
          <div class="min-w-0">
            <div class="truncate text-[10.5px] font-bold" dir="auto">{{ row.buyerName || '—' }}</div>
            <div v-if="row.buyerVat" class="cell-sub truncate">{{ row.buyerVat }}</div>
          </div>
        </template>
        <template #cell-profile="{ row }"><Chip small :map="PROFILE_KIND" :k="row.profile" /></template>
        <template #cell-total="{ row }"><span class="num">{{ sar(row.total) }}</span></template>
        <template #cell-issues="{ row }">
          <span v-if="row.issueCount?.errors" class="num font-extrabold text-bad" :title="t('أخطاء', 'Errors')">{{ row.issueCount.errors }}✕</span>
          <span v-if="row.issueCount?.warnings" class="num ms-1 font-bold text-warn" :title="t('تحذيرات', 'Warnings')">{{ row.issueCount.warnings }}!</span>
          <span v-if="!row.issueCount?.errors && !row.issueCount?.warnings" class="text-faint">—</span>
        </template>
        <template #cell-status="{ row }">
          <div class="text-center">
            <Chip small :map="lk.einvoiceStates.value" :k="row.status" />
            <div v-if="row.signed" class="mt-0.5 text-[8.5px] text-muted">{{ t('موقّعة', 'signed') }}</div>
          </div>
        </template>
      </DataTable>
    </div>
  </template>
  <EinvoicePending v-else-if="view === 'pending'" :summary="s" />
  <EinvoiceChain v-else />
</template>
