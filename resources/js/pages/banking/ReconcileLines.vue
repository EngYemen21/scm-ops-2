<script setup>
// The statement side of the reconcile workspace: the unmatched lines of a bank (GET /banking/lines?bank=…&state=unmatched)
// or of one statement (`statement=`), with sign, amount, search and paging in the query string (ls · la · lq · lp).
// A click on a line opens its proposals (`line=<id>`); the check box adds it to the selection.
//   <ReconcileLines :bank="code" :statement="idOrEmpty" :selected="{ [id]: item }" :selectable @toggle="(line) => …" />
import { computed } from 'vue';
import { useList } from '@/api/client';
import { DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { fmtDay, isMoney, money, signClass, useBankingNav, useSearchParam } from './banking';

const props = defineProps({
  bank: { type: String, required: true },
  statement: { type: String, default: '' },
  selected: { type: Object, required: true },
  selectable: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle']);

const nav = useBankingNav();
const search = useSearchParam(nav, 'lq', 'lp');
const amount = useSearchParam(nav, 'la', 'lp');
const page = computed(() => Math.max(1, Number(nav.get('lp')) || 1));
const sign = computed(() => (['in', 'out'].includes(nav.get('ls')) ? nav.get('ls') : ''));
const list = useList('/banking/lines', () => ({
  ...(props.statement ? { statement: props.statement } : { bank: props.bank }), state: 'unmatched', sign: sign.value || undefined,
  q: nav.get('lq') || undefined, amount: isMoney(nav.get('la')) ? nav.get('la') : undefined, sort: 'date', order: 'asc', page: page.value, pageSize: 25,
}));
const sums = computed(() => list.data.value?.totals || null);
const SIGNS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'in', label: { ar: 'وارد', en: 'In' } }, { k: 'out', label: { ar: 'صادر', en: 'Out' } }];
const columns = computed(() => [
  { key: 'sel', header: '', width: '36px', bare: true, hidden: !props.selectable },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '84px', kind: 'date' },
  { key: 'text', header: { ar: 'سطر الكشف', en: 'Statement line' }, width: 'minmax(150px,2fr)' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num' },
]);
const isSel = (r) => !!props.selected[r.id];
</script>

<template>
  <div class="card flex min-w-0 flex-col">
    <div class="card-head flex-wrap !gap-2">
      <div class="card-title">{{ t('سطور الكشف غير المطابقة', 'Unmatched statement lines') }} <span v-if="list.data.value" class="card-count num ms-1">{{ list.data.value.total }}</span></div>
      <span v-if="sums" class="num ltr text-[10px]"><span class="text-ok">+{{ money(sums.in) }}</span> · <span class="text-bad">{{ money(sums.out) }}</span></span>
    </div>
    <div class="row wrap px-3 pb-2 !gap-1.5">
      <Tabs :model-value="sign" :tabs="SIGNS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.patch({ ls: k, lp: null })" />
      <TextInput small type="search" class="w-[170px]" :model-value="search.text.value" :placeholder="{ ar: 'مرجع، وصف، طرف…', en: 'Reference, text, party…' }" @update:model-value="search.type" @enter="search.apply" />
      <TextInput small dir="ltr" class="w-[100px]" :model-value="amount.text.value" :placeholder="{ ar: 'المبلغ', en: 'Amount' }" @update:model-value="amount.type" @enter="amount.apply" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="nav.get('line') || null" dense
               :row-style="(r) => (isSel(r) ? { background: '#efeaf8' } : null)" :empty-text="{ ar: 'لا سطور غير مطابقة — كل ما في الكشوف مطابَق.', en: 'No unmatched line — everything in the statements is matched.' }"
               @page="(p) => nav.patch({ lp: p > 1 ? p : null })" @row-click="(r) => nav.openLine(r.id)">
      <template #cell-sel="{ row }">
        <input type="checkbox" :checked="isSel(row)" :aria-label="t('اختيار السطر', 'Select the line')" @click.stop @change="emit('toggle', row)">
      </template>
      <template #cell-date="{ row }"><span class="cell-date">{{ fmtDay(row.date) }}</span></template>
      <template #cell-text="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ row.kind === 'opening' ? t('الرصيد الافتتاحي', 'Opening balance') : row.reference || row.description || '—' }}</div>
          <div class="truncate text-[9px] text-muted" dir="auto" :title="row.description || ''"><span class="num ltr">{{ row.statementNumber }}#{{ row.lineNo }}</span><template v-if="row.partnerName"> · {{ row.partnerName }}</template><template v-if="row.reference && row.description"> · {{ row.description }}</template></div>
        </div>
      </template>
      <template #cell-amount="{ row }"><span class="num ltr font-extrabold" :class="signClass(row.amount)">{{ money(row.amount) }}</span></template>
    </DataTable>
  </div>
</template>
