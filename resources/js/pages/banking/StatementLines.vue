<script setup>
// The lines of one statement (GET /banking/lines?statement=…): state (matched / unmatched), sign, amount, search and
// paging, with the totals of what the filter holds. An unmatched line of an open statement may be edited or removed
// (bankstatement.manage) or opened for its proposals (`line=<id>`); a matched one names its match.
//   <StatementLines :statement="detail" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import LineForm from './LineForm.vue';
import { MATCH_KIND, OWNED_CHIP, fmtDay, isMoney, money, signClass, useBankingNav } from './banking';

const props = defineProps({
  statement: { type: Object, required: true },
});

const auth = useAuth();
const nav = useBankingNav();
const act = useAction({ invalidate: ['banking'] });
const canManage = computed(() => auth.can('bankstatement.manage'));
const canEdit = computed(() => canManage.value && !!props.statement.can?.lines);

const state = ref('all');
const sign = ref('');
const search = ref('');
const amount = ref('');
const page = ref(1);
watch(() => props.statement.id, () => { state.value = 'all'; sign.value = ''; search.value = ''; amount.value = ''; page.value = 1; });
const list = useList('/banking/lines', () => ({
  statement: props.statement.id, state: state.value, sign: sign.value || undefined, q: search.value.trim() || undefined,
  amount: isMoney(amount.value) ? amount.value.trim() : undefined, page: page.value, pageSize: 50,
}));
const sums = computed(() => list.data.value?.totals || null);

const STATES = [{ k: 'all', label: { ar: 'الكل', en: 'All' } }, { k: 'unmatched', label: { ar: 'غير المطابقة', en: 'Unmatched' } }, { k: 'matched', label: { ar: 'المطابقة', en: 'Matched' } }];
const SIGNS = [{ k: '', label: { ar: 'الاتجاهان', en: 'Both' } }, { k: 'in', label: { ar: 'وارد', en: 'In' } }, { k: 'out', label: { ar: 'صادر', en: 'Out' } }];
const columns = [
  { key: 'lineNo', header: '#', width: '46px', kind: 'num' },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date' },
  { key: 'text', header: { ar: 'المرجع والوصف', en: 'Reference & description' }, width: 'minmax(170px,2fr)' },
  { key: 'partner', header: { ar: 'الطرف', en: 'Counterparty' }, width: 'minmax(100px,1fr)' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num' },
  { key: 'state', header: { ar: 'المطابقة', en: 'Match' }, width: '112px', bare: true },
  { key: 'actions', header: '', width: '150px', bare: true },
];

const editing = ref(null);
async function remove(l) {
  if (!(await confirm({ title: { ar: `حذف السطر ${l.lineNo} من الكشف؟`, en: `Remove line ${l.lineNo}?` }, sub: { ar: `${l.date} · ${l.amount} — يبقى ما كان فيه في سجل التدقيق.`, en: `${l.date} · ${l.amount} — what it held stays on the audit trail.` }, tone: 'danger', okLabel: { ar: 'حذف', en: 'Remove' } }))) return;
  await act.run(() => api.del(`/banking/lines/${l.id}`), { success: (r) => t(r?.messageAr || 'حُذف السطر', r?.messageEn || 'Line removed') });
}
</script>

<template>
  <div class="row wrap mb-2">
    <Tabs v-model="state" :tabs="STATES" variant="pill" class="!mb-0" @update:model-value="page = 1" />
    <Tabs v-model="sign" :tabs="SIGNS" variant="pill" class="!mb-0" @update:model-value="page = 1" />
  </div>
  <div class="row wrap mb-2">
    <TextInput v-model="search" small type="search" class="w-[230px]" :placeholder="{ ar: 'بحث بالمرجع، الوصف، الطرف…', en: 'Search reference, text, party…' }" @update:model-value="page = 1" />
    <TextInput v-model="amount" small dir="ltr" class="w-[120px]" :placeholder="{ ar: 'المبلغ', en: 'Amount' }" @update:model-value="page = 1" />
    <span class="grow" />
    <span v-if="sums" class="num ltr text-[10.5px]"><span class="text-ok">+{{ money(sums.in) }}</span> · <span class="text-bad">{{ money(sums.out) }}</span> · {{ t('الصافي', 'net') }} <b :class="signClass(sums.net)">{{ money(sums.net) }}</b></span>
  </div>
  <ErrorBanner :error="list.error.value || act.error.value" :closable="!list.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" dense
               :empty-text="state === 'unmatched' ? { ar: 'كل سطور الكشف مطابَقة.', en: 'Every line of the statement is matched.' } : { ar: 'لا سطور — أدخلها أو استورد ملف البنك.', en: 'No line — type them or import the bank’s file.' }" @page="page = $event">
      <template #cell-lineNo="{ row }"><span class="num text-faint">{{ row.kind === 'opening' ? '0' : row.lineNo }}</span></template>
      <template #cell-date="{ row }"><span class="cell-date">{{ fmtDay(row.date) }}</span><div v-if="row.valueDate && row.valueDate !== row.date" class="cell-sub">{{ t('قيمة', 'value') }} {{ fmtDay(row.valueDate) }}</div></template>
      <template #cell-text="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ row.kind === 'opening' ? t('الرصيد الافتتاحي', 'Opening balance') : row.reference || row.description || '—' }}</div>
          <div v-if="row.kind !== 'opening' && row.reference && row.description" class="truncate text-[9px] text-muted" dir="auto" :title="row.description">{{ row.description }}</div>
          <div v-if="row.sourceState === 'cancelled'" class="text-[9px] font-bold text-bad">{{ t('ألغاه النظام المصدر — لا يُحتسب', 'Cancelled at the source — not counted') }}</div>
        </div>
      </template>
      <template #cell-partner="{ row }">
        <div class="min-w-0"><div class="truncate text-[10px]" dir="auto">{{ row.partnerName || '—' }}</div><div v-if="row.partnerAccount" class="cell-sub">{{ row.partnerAccount }}</div></div>
      </template>
      <template #cell-amount="{ row }"><span class="num ltr font-extrabold" :class="signClass(row.amount)">{{ money(row.amount) }}</span><div v-if="row.balanceAfter" class="cell-sub">{{ t('رصيد', 'bal.') }} {{ money(row.balanceAfter) }}</div></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <button v-if="row.matched && row.match" type="button" class="cell-id !text-[10px]" @click.stop="nav.openMatch(row.match.id)">{{ row.match.number }}</button>
          <Chip v-if="row.matched && row.match?.kind" small :map="MATCH_KIND" :k="row.match.kind" />
          <span v-if="!row.matched" class="text-[10px] font-bold text-warn">{{ t('غير مطابَق', 'Unmatched') }}</span>
          <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </span>
      </template>
      <template #cell-actions="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Btn v-if="!row.matched && statement.can?.match" size="sm" tone="softPurple" :label="{ ar: 'اقتراحات', en: 'Proposals' }" @click.stop="nav.openLine(row.id)" />
          <template v-if="canEdit && !row.matched && row.kind === 'line' && !row.owned">
            <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="editing = row" />
            <Btn size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'حذف', en: 'Remove' }" @click.stop="remove(row)" />
          </template>
        </span>
      </template>
    </DataTable>
  </div>

  <LineForm :line="editing" :statement="statement" @close="editing = null" />
</template>
