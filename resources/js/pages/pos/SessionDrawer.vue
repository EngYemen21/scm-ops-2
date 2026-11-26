<script setup>
// One session (`session=<id>` in the query string): its register and cashier, the cash it opened with, what the drawer
// should hold, the count at closing and its difference, the cash moved in and out, the counts by denomination, and
// the way to its end-of-day report, its orders and — for its cashier or a manager, while it is open — its close.
//   GET /pos/sessions/{id}   (history = true: a session of Odoo's history, read only)
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, EmptyState, ErrorBanner, KV } from '@/components';
import { fmtDate, fmtMoney, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CloseSessionForm from './CloseSessionForm.vue';
import { HISTORY_CHIP, SESSION_STATE, forgetCart, money, nameOf, usePosNav } from './pos';

const props = defineProps({
  id: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = usePosNav();
const det = useGet(() => (props.id ? `/pos/sessions/${encodeURIComponent(props.id)}` : null));
const s = computed(() => det.data.value || null);
const isOwner = computed(() => !!s.value && !!auth.user && (s.value.cashierId === auth.user.id));
const canClose = computed(() => !!s.value && s.value.state === 'open' && !s.value.history && auth.can('pos.sell') && (isOwner.value || auth.can('pos.manage')));
const closing = ref(false);
function onClosed(x) { closing.value = false; forgetCart(x.id); nav.openReport(x.id); }
const diffClass = (v) => (num(v) === 0 ? 'text-ok' : num(v) > 0 ? 'text-warn' : 'text-bad');
const countCols = [
  { key: 'value', header: { ar: 'الفئة', en: 'Denomination' }, width: '1fr', kind: 'num', value: (r) => fmtMoney(num(r.value)) },
  { key: 'count', header: { ar: 'العدد', en: 'Count' }, width: '80px', kind: 'num' },
  { key: 'total', header: { ar: 'المجموع', en: 'Total' }, width: '110px', kind: 'num', value: (r) => fmtMoney(num(r.total ?? num(r.value) * num(r.count))) },
];
const moveCols = [
  { key: 'direction', header: { ar: 'الاتجاه', en: 'Direction' }, width: '86px', bare: true },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '96px', kind: 'num', value: (r) => money(r.amount) },
  { key: 'reason', header: { ar: 'السبب', en: 'Reason' }, width: 'minmax(140px,1fr)' },
  { key: 'bank', header: { ar: 'الحساب', en: 'Account' }, width: '120px', value: (r) => (r.bankAccount ? `${r.bankAccount.code} · ${r.bankAccount.nameAr}` : '—') },
  { key: 'createdAt', header: { ar: 'الوقت', en: 'Time' }, width: '112px', kind: 'date', value: (r) => fmtDate(r.createdAt) },
];
</script>

<template>
  <Drawer :open="!!id" :title="s ? s.number : t('جلسة', 'Session')" :sub="s ? `${s.register?.code || ''} · ${nameOf(s.register)}` : null" :width="620" @close="emit('close')">
    <ErrorBanner :error="det.error.value" :closable="false" />
    <div v-if="!s && !det.error.value" class="skel h-[200px]" />
    <template v-else-if="s">
      <div class="row wrap mb-3">
        <Chip :map="SESSION_STATE" :k="s.state" />
        <Chip v-if="s.history" :label="HISTORY_CHIP" :fg="HISTORY_CHIP.fg" :bg="HISTORY_CHIP.bg" />
        <div class="grow" />
        <Btn tone="softPurple" size="sm" :label="{ ar: 'تقرير نهاية اليوم', en: 'End-of-day report' }" @click="nav.openReport(s.id)" />
        <Btn tone="soft" size="sm" :label="{ ar: 'طلبات الجلسة', en: 'Orders' }" @click="nav.go('orders', { session: s.number })" />
        <Btn v-if="canClose" tone="danger" size="sm" :label="{ ar: 'إغلاق الجلسة', en: 'Close session' }" @click="closing = true" />
      </div>
      <div v-if="s.history" class="hint purple !mt-0 mb-3">{{ t('جلسة من سجل أودو: تُعرض للقراءة فقط ولا يُباع فيها ولا تُغلق من هنا.', 'A session of the Odoo history: read only — no sale and no closing here.') }}</div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'الكاشير', en: 'Cashier' }" :v="s.cashierName" />
        <KV :k="{ ar: 'الصندوق', en: 'Register' }" :v="`${s.register?.code || ''} · ${nameOf(s.register)}`" />
        <KV :k="{ ar: 'فُتحت', en: 'Opened' }" :v="fmtDate(s.openedAt)" ltr />
        <KV :k="{ ar: 'أُغلقت', en: 'Closed' }" :v="s.closedAt ? fmtDate(s.closedAt) : '—'" ltr />
        <KV :k="{ ar: 'النقد الافتتاحي', en: 'Opening cash' }" :v="money(s.openingCash)" ltr />
        <KV :k="s.state === 'open' ? { ar: 'المتوقع في الدرج الآن', en: 'Expected in the drawer now' } : { ar: 'النقد المتوقع', en: 'Expected cash' }" :v="money(s.state === 'open' ? s.expectedCashNow : s.expectedCash)" ltr />
        <KV v-if="s.state === 'closed'" :k="{ ar: 'النقد المعدود', en: 'Counted cash' }" :v="money(s.countedCash)" ltr />
        <KV v-if="s.state === 'closed'" :k="{ ar: 'الفرق', en: 'Difference' }"><span class="num ltr font-extrabold" :class="diffClass(s.difference)">{{ money(s.difference) }}</span></KV>
        <KV v-if="s.differenceReason" :k="{ ar: 'سبب الفرق', en: 'Reason' }" :v="s.differenceReason" />
        <KV v-if="s.state === 'open'" :k="{ ar: 'مبيعات معلّقة', en: 'Held sales' }" :v="String(s.held ?? 0)" ltr />
      </div>

      <template v-if="s.summary">
        <div class="card-title mb-1.5">{{ t('الملخص', 'Summary') }}</div>
        <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
          <div class="rounded-xl bg-soft p-2.5"><div class="text-[10px] text-muted">{{ t('مبيعات', 'Sales') }} · <span class="num">{{ s.summary.sales }}</span></div><div class="num ltr font-extrabold">{{ money(s.summary.salesTotal) }}</div></div>
          <div class="rounded-xl bg-soft p-2.5"><div class="text-[10px] text-muted">{{ t('مرتجعات', 'Refunds') }} · <span class="num">{{ s.summary.refunds }}</span></div><div class="num ltr font-extrabold text-warn">{{ money(s.summary.refundsTotal) }}</div></div>
          <div class="rounded-xl bg-soft p-2.5"><div class="text-[10px] text-muted">{{ t('الصافي', 'Net') }}</div><div class="num ltr font-extrabold">{{ money(s.summary.netTotal) }}</div></div>
          <div class="rounded-xl bg-soft p-2.5"><div class="text-[10px] text-muted">{{ t('الضريبة', 'VAT') }}</div><div class="num ltr font-extrabold">{{ money(s.summary.netVat) }}</div></div>
        </div>
      </template>

      <div class="card-title mb-1.5">{{ t('حركات النقد', 'Cash in / out') }}</div>
      <div class="card mb-4">
        <DataTable :columns="moveCols" :rows="s.cashMoves || []" dense :empty-text="{ ar: 'لا حركات نقدية.', en: 'No cash moves.' }">
          <template #cell-direction="{ row }"><Chip small :label="row.direction === 'in' ? { ar: 'إيداع', en: 'In' } : { ar: 'سحب', en: 'Out' }" :fg="row.direction === 'in' ? '#1d7a3e' : '#b26a16'" :bg="row.direction === 'in' ? '#e6f9ec' : '#fbf0dd'" /></template>
        </DataTable>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <div class="card-title mb-1.5">{{ t('عدّ الافتتاح', 'Opening count') }}</div>
          <div v-if="s.openingCounts?.length" class="card"><DataTable :columns="countCols" :rows="s.openingCounts" dense /></div>
          <EmptyState v-else :text="{ ar: 'أُدخل مبلغًا واحدًا', en: 'Entered as one amount' }" />
        </div>
        <div v-if="s.state === 'closed'">
          <div class="card-title mb-1.5">{{ t('عدّ الإغلاق', 'Closing count') }}</div>
          <div v-if="s.closingCounts?.length" class="card"><DataTable :columns="countCols" :rows="s.closingCounts" dense /></div>
          <EmptyState v-else :text="{ ar: 'لم يُعدّ نقد (درج فارغ)', en: 'No cash counted (empty drawer)' }" />
        </div>
      </div>
    </template>
  </Drawer>
  <CloseSessionForm :open="closing" :session-id="s ? s.id : null" @close="closing = false" @done="onClosed" />
</template>
