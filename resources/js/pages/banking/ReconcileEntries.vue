<script setup>
// The books' side of the reconcile workspace: the unmatched entries of the bank's ledger account
// (GET /banking/ledger-items?bank=…&state=unmatched) — receipts, payments, expenses, journals, reversals, write-offs —
// with kind, sign, period, amount, search and paging in the query string (ek · es · ef · et · ea · eq · ep).
// A click (or the check box) adds an entry to the selection. A voided document and its reversal that are both free
// (`pair.free`) can be selected together and cleared as a contra, with no statement line.
//   <ReconcileEntries :bank="code" :selected="{ [id]: item }" :selectable @toggle="(entry) => …" @pair="(entry) => …" />
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { ENTRY_KIND, fmtDay, isMoney, money, nameOf, optionsOf, signClass, useBankingNav, useSearchParam } from './banking';

const props = defineProps({
  bank: { type: String, required: true },
  selected: { type: Object, required: true },
  selectable: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle', 'pair']);

const nav = useBankingNav();
const search = useSearchParam(nav, 'eq', 'ep');
const amount = useSearchParam(nav, 'ea', 'ep');
const page = computed(() => Math.max(1, Number(nav.get('ep')) || 1));
const sign = computed(() => (['in', 'out'].includes(nav.get('es')) ? nav.get('es') : ''));
const kind = computed(() => (ENTRY_KIND[nav.get('ek')] ? nav.get('ek') : ''));
const list = useList('/banking/ledger-items', () => ({
  bank: props.bank, state: 'unmatched', sign: sign.value || undefined, kind: kind.value || undefined, from: nav.get('ef') || undefined, to: nav.get('et') || undefined,
  amount: isMoney(nav.get('ea')) ? nav.get('ea') : undefined, q: nav.get('eq') || undefined, order: 'asc', page: page.value, pageSize: 25,
}));
const sums = computed(() => list.data.value?.totals || null);
const SIGNS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'in', label: { ar: 'وارد', en: 'In' } }, { k: 'out', label: { ar: 'صادر', en: 'Out' } }];
const kinds = optionsOf(ENTRY_KIND);
const columns = computed(() => [
  { key: 'sel', header: '', width: '36px', bare: true, hidden: !props.selectable },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '84px', kind: 'date' },
  { key: 'doc', header: { ar: 'قيد الدفاتر', en: 'Ledger entry' }, width: 'minmax(150px,2fr)' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num' },
]);
const isSel = (r) => !!props.selected[r.id];
const set = (k, v) => nav.patch({ [k]: v, ep: null });
</script>

<template>
  <div class="card flex min-w-0 flex-col">
    <div class="card-head flex-wrap !gap-2">
      <div class="card-title">{{ t('قيود الدفاتر غير المطابقة', 'Unmatched ledger entries') }} <span v-if="list.data.value" class="card-count num ms-1">{{ list.data.value.total }}</span></div>
      <span v-if="sums" class="num ltr text-[10px]"><span class="text-ok">+{{ money(sums.in) }}</span> · <span class="text-bad">-{{ money(sums.out) }}</span></span>
    </div>
    <div class="row wrap px-3 pb-2 !gap-1.5">
      <Tabs :model-value="sign" :tabs="SIGNS" variant="pill" class="!mb-0" @update:model-value="(k) => set('es', k)" />
      <SelectInput small class="w-[130px]" :model-value="kind" :options="kinds" :placeholder="{ ar: 'كل الأنواع', en: 'Every kind' }" @update:model-value="(v) => set('ek', v)" />
      <TextInput small type="search" class="w-[160px]" :model-value="search.text.value" :placeholder="{ ar: 'رقم مستند، مرجع، بيان…', en: 'Document, reference, memo…' }" @update:model-value="search.type" @enter="search.apply" />
      <TextInput small dir="ltr" class="w-[100px]" :model-value="amount.text.value" :placeholder="{ ar: 'المبلغ', en: 'Amount' }" @update:model-value="amount.type" @enter="amount.apply" />
      <DateInput small class="w-[130px]" :model-value="nav.get('ef')" @update:model-value="(v) => set('ef', v)" />
      <DateInput small class="w-[130px]" :model-value="nav.get('et')" @update:model-value="(v) => set('et', v)" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" dense
               :row-style="(r) => (isSel(r) ? { background: '#efeaf8' } : null)" :empty-text="{ ar: 'لا قيود غير مطابقة على حساب هذا البنك.', en: 'No unmatched entry on this bank’s ledger account.' }"
               @page="(p) => nav.patch({ ep: p > 1 ? p : null })" @row-click="(r) => selectable && emit('toggle', r)">
      <template #cell-sel="{ row }">
        <input type="checkbox" :checked="isSel(row)" :aria-label="t('اختيار القيد', 'Select the entry')" @click.stop @change="emit('toggle', row)">
      </template>
      <template #cell-date="{ row }"><span class="cell-date">{{ fmtDay(row.date) }}</span></template>
      <template #cell-doc="{ row }">
        <div class="min-w-0">
          <div class="row !gap-1"><span class="cell-id truncate">{{ row.document?.number || row.journalNumber }}</span><Chip small :map="ENTRY_KIND" :k="row.kind" /></div>
          <div class="truncate text-[9px] text-muted" dir="auto" :title="row.memo || row.description || ''">
            <template v-if="row.party">{{ nameOf(row.party) }} · </template><template v-if="row.reference"><span class="num ltr">{{ row.reference }}</span> · </template>{{ row.description || row.memo || '' }}
            <template v-if="row.journalNumber && row.journalNumber !== row.document?.number"> · <span class="num ltr">{{ row.journalNumber }}</span></template>
          </div>
          <div v-if="row.pair" class="row mt-0.5 !gap-1 text-[9px]">
            <span :class="row.pair.free ? 'text-violet' : 'text-faint'">{{ t('يقابله', 'Mirrored by') }} <span class="num ltr">{{ row.pair.journalNumber }}</span></span>
            <Btn v-if="selectable && row.pair.free" size="sm" tone="softPurple" class="!h-6 !px-2 !text-[9px]" :label="{ ar: 'اختره مع عكسه', en: 'Pick with its reversal' }" @click.stop="emit('pair', row)" />
          </div>
        </div>
      </template>
      <template #cell-amount="{ row }"><span class="num ltr font-extrabold" :class="signClass(row.amount)">{{ money(row.amount) }}</span></template>
    </DataTable>
  </div>
</template>
