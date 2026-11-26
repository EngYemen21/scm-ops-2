<script setup>
// «الحسابات» — every OPS bank account and cash box with where its reconciliation stands today (GET /banking/accounts):
// the balance in the books, the balance of the last statement, the last statement locked, the open statements, what is
// still unmatched on each side, the Odoo journals linked to it, and a warning when it shares its ledger account.
// Each card opens its statements, its reconcile workspace and its reconciliation statement.
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, KpiCard, KpiGrid, TextInput } from '@/components';
import { t } from '@/i18n';
import { BANK_KIND, STATEMENT_STATUS, fmtDay, money, nameOf, signClass, useBankingNav } from './banking';

const nav = useBankingNav();
const q = useGet('/banking/accounts');
const all = computed(() => q.data.value?.items || []);
const search = ref('');
const showInactive = ref(false);
const items = computed(() => {
  const s = search.value.trim().toLowerCase();
  return all.value.filter((b) => (showInactive.value || b.active) && (!s || [b.code, b.nameAr, b.nameEn, b.bankName, b.iban].some((x) => String(x || '').toLowerCase().includes(s))));
});
const inactiveCount = computed(() => all.value.filter((b) => !b.active).length);
const totals = computed(() => ({
  open: all.value.reduce((s, b) => s + (b.openStatements || 0), 0),
  lines: all.value.reduce((s, b) => s + (b.unmatchedLines?.count || 0), 0),
  entries: all.value.reduce((s, b) => s + (b.unmatchedEntries?.count || 0), 0),
}));
const open = (tab, b) => nav.go(tab, { bank: b.code });
</script>

<template>
  <KpiGrid compact>
    <KpiCard :value="all.length" :label="{ ar: 'حسابات وصناديق', en: 'Banks and cash boxes' }" :loading="q.isLoading.value" />
    <KpiCard :value="totals.open" :label="{ ar: 'كشوف مفتوحة', en: 'Open statements' }" color="#3C79F5" :loading="q.isLoading.value" />
    <KpiCard :value="totals.lines" :label="{ ar: 'سطور كشف غير مطابقة', en: 'Unmatched statement lines' }" :color="totals.lines ? '#b26a16' : '#1d7a3e'" :loading="q.isLoading.value" />
    <KpiCard :value="totals.entries" :label="{ ar: 'قيود دفاتر غير مطابقة', en: 'Unmatched ledger entries' }" :color="totals.entries ? '#b26a16' : '#1d7a3e'" :loading="q.isLoading.value" />
  </KpiGrid>

  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[260px]" :placeholder="{ ar: 'بحث بالرمز، الاسم، البنك، الآيبان…', en: 'Search code, name, bank, IBAN…' }" />
    <label v-if="inactiveCount" class="row !gap-1.5 text-[10.5px] text-sec"><input v-model="showInactive" type="checkbox"> {{ t(`إظهار الموقوفة (${inactiveCount})`, `Show inactive (${inactiveCount})`) }}</label>
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3"><div v-for="i in 3" :key="i" class="skel h-[220px]" /></div>
  <EmptyState v-else-if="!items.length && !q.error.value" tone="dashed" :text="all.length ? { ar: 'لا حساب يطابق البحث.', en: 'No account matches the search.' } : { ar: 'لا حسابات بنكية بعد — تُعرَّف الحسابات البنكية في المالية.', en: 'No bank account yet — bank accounts are defined in finance.' }" />

  <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
    <div v-for="b in items" :key="b.id" class="card flex flex-col p-3.5" :class="{ 'opacity-70': !b.active }">
      <div class="flex items-start gap-2">
        <div class="min-w-0 flex-1">
          <div class="truncate text-[12.5px] font-extrabold text-ink" dir="auto">{{ nameOf(b) }}</div>
          <div class="cell-sub !text-start"><span class="num ltr">{{ b.code }}</span><template v-if="b.bankName"> · <span dir="auto">{{ b.bankName }}</span></template><template v-if="b.iban"> · <span class="num ltr">{{ b.iban }}</span></template></div>
        </div>
        <Chip small :map="BANK_KIND" :k="b.kind" />
        <Chip v-if="!b.active" small :label="{ ar: 'موقوف', en: 'Inactive' }" fg="#55506a" bg="#F1EFF6" />
      </div>

      <div class="mt-3 grid grid-cols-2 gap-2">
        <div class="tile"><div class="tile-v" :class="signClass(b.bookBalance)">{{ money(b.bookBalance) }}</div><div class="tile-l">{{ t('الرصيد في الدفاتر', 'Book balance') }} <span class="num">{{ b.currency }}</span></div></div>
        <div class="tile"><div class="tile-v">{{ money(b.statementBalance) }}</div><div class="tile-l">{{ b.lastStatement ? t(`رصيد آخر كشف (${fmtDay(b.lastStatement.dateTo)})`, `Last statement balance (${fmtDay(b.lastStatement.dateTo)})`) : t('لا كشوف بعد', 'No statement yet') }}</div></div>
      </div>

      <div class="mt-3 col !gap-1.5 text-[10.5px]">
        <div class="row !gap-1.5">
          <span class="text-muted">{{ t('آخر كشف مقفل', 'Last reconciled') }}:</span>
          <template v-if="b.lastReconciled"><button type="button" class="cell-id" @click="nav.openStatement(b.lastReconciled.id)">{{ b.lastReconciled.number }}</button><span class="cell-date">{{ t('حتى', 'to') }} {{ fmtDay(b.lastReconciled.dateTo) }}</span></template>
          <span v-else class="text-faint">{{ t('لم يُقفل كشف بعد', 'none yet') }}</span>
        </div>
        <div class="row !gap-1.5">
          <span class="text-muted">{{ t('آخر كشف', 'Last statement') }}:</span>
          <template v-if="b.lastStatement"><button type="button" class="cell-id" @click="nav.openStatement(b.lastStatement.id)">{{ b.lastStatement.number }}</button><Chip small :map="STATEMENT_STATUS" :k="b.lastStatement.status" /></template>
          <span v-else class="text-faint">—</span>
          <span class="grow" />
          <span class="text-muted">{{ t('مفتوحة', 'Open') }}: <b class="num">{{ b.openStatements }}</b> / <span class="num">{{ b.statements }}</span></span>
        </div>
        <div class="row !gap-1.5" :class="b.unmatchedLines?.count ? 'text-warn' : 'text-ok'">
          <span class="font-bold">{{ t('سطور كشف غير مطابقة', 'Unmatched statement lines') }}: <span class="num">{{ b.unmatchedLines?.count || 0 }}</span></span>
          <span class="grow" />
          <span v-if="b.unmatchedLines?.count" class="num ltr text-[10px]"><span class="text-ok">+{{ money(b.unmatchedLines.in) }}</span> · <span class="text-bad">{{ money(b.unmatchedLines.out) }}</span></span>
        </div>
        <div class="row !gap-1.5" :class="b.unmatchedEntries?.count ? 'text-warn' : 'text-ok'">
          <span class="font-bold">{{ t('قيود دفاتر غير مطابقة', 'Unmatched ledger entries') }}: <span class="num">{{ b.unmatchedEntries?.count || 0 }}</span></span>
          <span class="grow" />
          <span v-if="b.unmatchedEntries?.count" class="num ltr text-[10px]"><span class="text-ok">+{{ money(b.unmatchedEntries.in) }}</span> · <span class="text-bad">{{ money(b.unmatchedEntries.out) }}</span></span>
        </div>
        <div v-if="b.ledgerAccount" class="text-muted">{{ t('حساب الأستاذ', 'Ledger account') }}: <span class="num ltr">{{ b.ledgerAccount.code }}</span> <span dir="auto">{{ nameOf(b.ledgerAccount) }}</span></div>
        <div v-if="b.sourceJournals?.length" class="text-muted">
          {{ t('دفاتر أودو المربوطة', 'Linked Odoo journals') }}:
          <span v-for="j in b.sourceJournals" :key="j.id" class="me-1.5 inline-block"><span class="num ltr">{{ j.code }}</span> <span dir="auto">{{ j.name }}</span></span>
        </div>
      </div>
      <div v-if="b.sharedWith?.length" class="hint amber !mb-0 mt-2.5">
        {{ t(`حساب الأستاذ مشترك مع: ${b.sharedWith.join('، ')} — القيد الذي لا يحدد مستنده بنكًا يظهر تحت كل حساب يشاركه حتى يُطابَق.`,
          `The ledger account is shared with: ${b.sharedWith.join(', ')} — an entry whose document names no bank shows under every account that shares it until it is matched.`) }}
      </div>

      <div class="grow" />
      <div class="row wrap mt-3 !gap-1.5">
        <Btn size="sm" tone="soft" :label="{ ar: 'الكشوف', en: 'Statements' }" @click="open('statements', b)" />
        <Btn size="sm" tone="softPurple" :label="{ ar: 'المطابقة', en: 'Reconcile' }" @click="open('reconcile', b)" />
        <Btn size="sm" tone="softBlue" :label="{ ar: 'كشف التسوية', en: 'Reconciliation statement' }" @click="open('recon', b)" />
      </div>
    </div>
  </div>
</template>
