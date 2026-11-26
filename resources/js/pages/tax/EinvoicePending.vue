<script setup>
// OPS invoices and credit notes that should have an e-invoice and have none — GET /tax/einvoices/pending (search,
// paging) with the reason each one waits (`blocked {code, ar, en}`). einvoice.manage makes one
// (POST /tax/einvoices/make { invoice }) or all of them (POST /tax/einvoices/make-pending → { made, blocked, reasons }).
// "Make all" works only while automatic making is on in the taxpayer profile (the server makes nothing otherwise).
import { computed } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, DataTable, ErrorBanner, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { DOC_KIND, fmtDay, labelOf, nameOf, pct, sar, useSearchParam, useTaxNav } from './tax';

const props = defineProps({
  /** GET /tax/einvoices/summary (for `auto` and `pending`). */
  summary: { type: Object, default: null },
});

const nav = useTaxNav();
const auth = useAuth();
const search = useSearchParam(nav);
const list = useList('/tax/einvoices/pending', () => ({ q: nav.get('q') || undefined, page: nav.page.value, pageSize: 25 }));
const act = useAction({ invalidate: ['tax'] });
const canMake = computed(() => auth.can('einvoice.manage'));
const auto = computed(() => !!props.summary?.auto);

async function make(row) {
  const e = await act.run(() => api.postIdempotent('/tax/einvoices/make', { invoice: row.id }), { success: (x) => t(`أُنشئت الفاتورة الإلكترونية (العداد ${x?.icv})`, `E-invoice made (ICV ${x?.icv})`) });
  if (e?.id) nav.patch({ doc: e.id });
}
async function makeAll() {
  if (!(await confirm({ title: { ar: 'إنشاء الفواتير الإلكترونية لكل ما ينتظر؟', en: 'Make the e-invoices of everything waiting?' }, sub: { ar: 'تُنشأ بالترتيب الأقدم أولًا (حتى 200 في المرة). لا يُرسل شيء إلى الهيئة من هنا.', en: 'Made oldest first (up to 200 at a time). Nothing is sent to the authority from here.' }, tone: 'primary' }))) return;
  await act.run(() => api.postIdempotent('/tax/einvoices/make-pending', {}), {
    success: (r) => t(`أُنشئت ${r?.made ?? 0} · تعذّر ${r?.blocked ?? 0}`, `Made ${r?.made ?? 0} · blocked ${r?.blocked ?? 0}`),
  });
}

const columns = [
  { key: 'number', header: { ar: 'الفاتورة', en: 'Invoice' }, width: 'minmax(130px,1fr)' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(140px,1.2fr)' },
  { key: 'invoiceDate', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDay(r.invoiceDate) },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num' },
  { key: 'blocked', header: { ar: 'سبب الانتظار', en: 'Why it waits' }, width: 'minmax(180px,1.6fr)' },
  { key: 'actions', header: '', width: '110px', bare: true },
];
</script>

<template>
  <div class="row wrap mb-2 items-end gap-2">
    <TextInput :model-value="search.text.value" small field-class="w-full sm:w-[240px]" :placeholder="{ ar: 'بحث برقم الفاتورة أو العميل…', en: 'Search invoice number or customer…' }" @update:model-value="search.type" @enter="search.apply" />
    <div class="grow" />
    <Btn v-if="canMake" tone="primary" :disabled="!auto || !list.data.value?.total" :loading="act.pending.value" :label="{ ar: 'إنشاء كل ما ينتظر', en: 'Make all pending' }" @click="makeAll" />
  </div>
  <div v-if="canMake && summary && !auto" class="hint amber !mt-0 mb-2">{{ t('الإنشاء التلقائي متوقف في بيانات المكلّف، فلا يُنشئ «إنشاء كل ما ينتظر» شيئًا — أنشئ الفواتير واحدة واحدة أو فعّله من «المكلّف والأطراف».', 'Automatic making is off in the taxpayer profile, so “Make all pending” makes nothing — make them one by one or turn it on under “Taxpayer & parties”.') }}</div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :empty-text="{ ar: 'لا فواتير تنتظر فاتورتها الإلكترونية.', en: 'No invoice waits for its e-invoice.' }" @page="nav.setPage">
      <template #cell-number="{ row }">
        <div class="min-w-0">
          <div class="cell-name num truncate">{{ row.number }}</div>
          <div class="truncate text-[9px] text-muted">{{ labelOf(DOC_KIND, row.kind) }} · <span class="num">{{ pct(row.vatPct) }}</span></div>
        </div>
      </template>
      <template #cell-customer="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[10.5px] font-bold" dir="auto">{{ row.customer ? nameOf(row.customer) : '—' }}</div>
          <div v-if="row.customer?.code" class="cell-sub">{{ row.customer.code }}</div>
        </div>
      </template>
      <template #cell-total="{ row }"><span class="num">{{ sar(row.total) }}</span></template>
      <template #cell-blocked="{ row }">
        <div v-if="row.blocked" class="min-w-0">
          <div class="truncate text-[10px] font-bold text-bad" :title="bi(row.blocked)">{{ bi(row.blocked) }}</div>
          <div class="cell-sub">{{ row.blocked.code }}</div>
        </div>
        <span v-else class="text-[10px] font-bold text-ok">{{ t('جاهزة للإنشاء', 'Ready to be made') }}</span>
      </template>
      <template #cell-actions="{ row }">
        <Btn v-if="canMake" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'إنشاء', en: 'Make' }" @click.stop="make(row)" />
        <span v-else class="text-faint">—</span>
      </template>
    </DataTable>
  </div>
  <div v-if="list.data.value" class="mt-2 text-[9.5px] text-faint">{{ t('المجموع', 'Total') }}: <span class="num">{{ fmtNum(list.data.value.total) }}</span></div>
</template>
