<script setup>
// The end-of-day report of a session (`report=<session id>`), shown on the page itself so it prints (the print rules
// hide the shell, drawers and `.no-print`). A closed session's report is the one frozen at closing (`frozen`).
//   GET /pos/sessions/{id}/report → { frozen, session, sales, refunds, net, byMethod, cash, vat, products, pending, margin? }
// Cost and margin come only to users who may see cost (inventory.view_cost).
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner } from '@/components';
import { fmtDate, fmtMoney, num, t } from '@/i18n';
import { HISTORY_CHIP, METHOD_KIND, SESSION_STATE, labelOf, money, nameOf, pct } from './pos';

const props = defineProps({
  id: { type: String, required: true },
});
const emit = defineEmits(['back']);

const rep = useGet(() => `/pos/sessions/${encodeURIComponent(props.id)}/report`);
const r = computed(() => rep.data.value || null);
const m = (v) => fmtMoney(num(v));
const hasCost = computed(() => (r.value?.products || []).some((p) => p.cogs != null));
const diffClass = (v) => (num(v) === 0 ? 'text-ok' : num(v) > 0 ? 'text-warn' : 'text-bad');
function print() { window.print(); }
</script>

<template>
  <div class="no-print mb-3 flex flex-wrap items-center gap-2">
    <Btn tone="soft" size="sm" :label="{ ar: '→ رجوع', en: '← Back' }" @click="emit('back')" />
    <div class="grow" />
    <Btn tone="dark" size="sm" :disabled="!r" :label="{ ar: 'طباعة التقرير', en: 'Print report' }" @click="print" />
  </div>
  <ErrorBanner :error="rep.error.value" :closable="false" />
  <div v-if="!r && !rep.error.value" class="skel h-[360px]" />

  <div v-else-if="r" class="pos-report card p-5">
    <!-- head -->
    <div class="mb-4 flex flex-wrap items-start gap-3 border-b border-line pb-3">
      <div class="min-w-0 flex-1">
        <div class="text-[17px] font-extrabold text-ink">{{ t('تقرير نهاية اليوم', 'End-of-day report') }} — <span class="num ltr">{{ r.session.number }}</span></div>
        <div class="mt-1 text-[11px] text-sec" dir="auto">{{ r.session.register ? `${r.session.register.code} · ${nameOf(r.session.register)}` : '—' }} · {{ t('الكاشير', 'Cashier') }}: {{ r.session.cashier || '—' }}</div>
        <div class="mt-0.5 text-[11px] text-muted">{{ t('من', 'From') }} <span class="num ltr">{{ fmtDate(r.session.openedAt) }}</span> {{ t('إلى', 'to') }} <span class="num ltr">{{ r.session.closedAt ? fmtDate(r.session.closedAt) : t('الآن', 'now') }}</span></div>
      </div>
      <div class="flex flex-wrap gap-1">
        <Chip :map="SESSION_STATE" :k="r.session.state" />
        <Chip v-if="r.frozen" :label="{ ar: 'مجمّد عند الإغلاق', en: 'Frozen at closing' }" fg="#55506a" bg="#F1EFF6" />
        <Chip v-else :label="{ ar: 'حيّ — يتغير مع كل بيع', en: 'Live — changes with every sale' }" fg="#3C79F5" bg="#e8effe" />
        <Chip v-if="r.session.history" :label="HISTORY_CHIP" :fg="HISTORY_CHIP.fg" :bg="HISTORY_CHIP.bg" />
      </div>
    </div>

    <!-- sales / refunds / net -->
    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3">
      <div class="rounded-xl border border-line p-3">
        <div class="card-title mb-1.5">{{ t('المبيعات', 'Sales') }} <span class="num text-muted">({{ r.sales.count }})</span></div>
        <table class="w-full text-[11.5px]"><tbody>
          <tr><td class="text-sec">{{ t('الإجمالي قبل الخصم', 'Gross') }}</td><td class="num ltr text-end">{{ m(r.sales.gross) }}</td></tr>
          <tr><td class="text-sec">{{ t('الخصم', 'Discount') }}</td><td class="num ltr text-end">{{ m(r.sales.discount) }}</td></tr>
          <tr><td class="text-sec">{{ t('الصافي', 'Net') }}</td><td class="num ltr text-end">{{ m(r.sales.net) }}</td></tr>
          <tr><td class="text-sec">{{ t('الضريبة', 'VAT') }}</td><td class="num ltr text-end">{{ m(r.sales.vat) }}</td></tr>
          <tr class="font-extrabold"><td>{{ t('الإجمالي', 'Total') }}</td><td class="num ltr text-end">{{ m(r.sales.total) }}</td></tr>
          <tr><td class="text-sec">{{ t('على الحساب', 'On account') }}</td><td class="num ltr text-end">{{ m(r.sales.onAccount) }}</td></tr>
          <tr><td class="text-sec">{{ t('الباقي المُعاد', 'Change given') }}</td><td class="num ltr text-end">{{ m(r.sales.change) }}</td></tr>
        </tbody></table>
      </div>
      <div class="rounded-xl border border-line p-3">
        <div class="card-title mb-1.5">{{ t('المرتجعات', 'Refunds') }} <span class="num text-muted">({{ r.refunds.count }})</span></div>
        <table class="w-full text-[11.5px]"><tbody>
          <tr><td class="text-sec">{{ t('الصافي', 'Net') }}</td><td class="num ltr text-end">{{ m(r.refunds.net) }}</td></tr>
          <tr><td class="text-sec">{{ t('الضريبة', 'VAT') }}</td><td class="num ltr text-end">{{ m(r.refunds.vat) }}</td></tr>
          <tr class="font-extrabold"><td>{{ t('الإجمالي', 'Total') }}</td><td class="num ltr text-end">{{ m(r.refunds.total) }}</td></tr>
        </tbody></table>
      </div>
      <div class="rounded-xl border border-line bg-soft p-3">
        <div class="card-title mb-1.5">{{ t('صافي الجلسة', 'Session net') }}</div>
        <table class="w-full text-[11.5px]"><tbody>
          <tr><td class="text-sec">{{ t('الصافي', 'Net') }}</td><td class="num ltr text-end">{{ m(r.net.net) }}</td></tr>
          <tr><td class="text-sec">{{ t('الضريبة', 'VAT') }}</td><td class="num ltr text-end">{{ m(r.net.vat) }}</td></tr>
          <tr class="text-[13px] font-extrabold"><td>{{ t('الإجمالي', 'Total') }}</td><td class="num ltr text-end">{{ money(r.net.total) }}</td></tr>
          <template v-if="r.margin">
            <tr><td class="text-sec">{{ t('تكلفة المبيعات', 'Cost of sales') }}</td><td class="num ltr text-end">{{ m(r.margin.cogs) }}</td></tr>
            <tr class="font-extrabold"><td>{{ t('مجمل الربح', 'Gross margin') }}</td><td class="num ltr text-end" :class="num(r.margin.gross) < 0 ? 'text-bad' : 'text-ok'">{{ m(r.margin.gross) }}</td></tr>
          </template>
        </tbody></table>
      </div>
    </div>

    <!-- by method -->
    <div class="card-title mb-1.5">{{ t('حسب طريقة الدفع', 'By payment method') }}</div>
    <table class="rep-table mb-4">
      <thead><tr><th class="text-start">{{ t('الطريقة', 'Method') }}</th><th>{{ t('النوع', 'Kind') }}</th><th>{{ t('العمليات', 'Count') }}</th><th>{{ t('مبيعات', 'Sales') }}</th><th>{{ t('مرتجعات', 'Refunds') }}</th><th>{{ t('الصافي', 'Net') }}</th></tr></thead>
      <tbody>
        <tr v-for="b in r.byMethod" :key="b.code"><td class="text-start" dir="auto">{{ nameOf(b) }} <span class="num text-muted">{{ b.code }}</span></td><td>{{ labelOf(METHOD_KIND, b.kind) }}</td><td class="num">{{ b.count }}</td><td class="num">{{ m(b.sales) }}</td><td class="num">{{ m(b.refunds) }}</td><td class="num font-extrabold">{{ m(b.net) }}</td></tr>
        <tr v-if="!r.byMethod.length"><td colspan="6" class="text-muted">{{ t('لا مدفوعات.', 'No payments.') }}</td></tr>
      </tbody>
    </table>

    <!-- cash -->
    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
      <div>
        <div class="card-title mb-1.5">{{ t('تسوية النقد', 'Cash reconciliation') }}</div>
        <table class="rep-table">
          <tbody>
            <tr><td class="text-start">{{ t('النقد الافتتاحي', 'Opening cash') }}</td><td class="num">{{ m(r.cash.opening) }}</td></tr>
            <tr><td class="text-start">+ {{ t('نقد المبيعات (بعد الباقي)', 'Cash sales (after change)') }}</td><td class="num">{{ m(r.cash.sales) }}</td></tr>
            <tr><td class="text-start">− {{ t('نقد المرتجعات', 'Cash refunds') }}</td><td class="num">{{ m(r.cash.refunds) }}</td></tr>
            <tr><td class="text-start">+ {{ t('إيداعات', 'Cash in') }}</td><td class="num">{{ m(r.cash.in) }}</td></tr>
            <tr><td class="text-start">− {{ t('سحوبات', 'Cash out') }}</td><td class="num">{{ m(r.cash.out) }}</td></tr>
            <tr class="font-extrabold"><td class="text-start">{{ t('المتوقع', 'Expected') }}</td><td class="num">{{ m(r.cash.expected) }}</td></tr>
            <tr v-if="r.cash.counted != null"><td class="text-start">{{ t('المعدود', 'Counted') }}</td><td class="num">{{ m(r.cash.counted) }}</td></tr>
            <tr v-if="r.cash.difference != null" class="font-extrabold"><td class="text-start">{{ t('الفرق', 'Difference') }}</td><td class="num" :class="diffClass(r.cash.difference)">{{ m(r.cash.difference) }}</td></tr>
            <tr v-if="r.cash.reason"><td class="text-start">{{ t('السبب', 'Reason') }}</td><td class="text-start" dir="auto">{{ r.cash.reason }}</td></tr>
          </tbody>
        </table>
      </div>
      <div>
        <div class="card-title mb-1.5">{{ t('عدّ الإغلاق', 'Closing count') }}</div>
        <table class="rep-table">
          <thead><tr><th>{{ t('الفئة', 'Value') }}</th><th>{{ t('العدد', 'Count') }}</th><th>{{ t('المجموع', 'Total') }}</th></tr></thead>
          <tbody>
            <tr v-for="c in r.cash.counts || []" :key="c.value"><td class="num">{{ m(c.value) }}</td><td class="num">{{ c.count }}</td><td class="num">{{ m(c.total) }}</td></tr>
            <tr v-if="!(r.cash.counts || []).length"><td colspan="3" class="text-muted">{{ r.session.state === 'open' ? t('الجلسة مفتوحة — لم يُعدّ بعد.', 'The session is open — not counted yet.') : t('لم يُعدّ نقد.', 'No cash counted.') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <template v-if="(r.cash.moves || []).length">
      <div class="card-title mb-1.5">{{ t('حركات النقد', 'Cash moves') }}</div>
      <table class="rep-table mb-4">
        <thead><tr><th>{{ t('الاتجاه', 'Direction') }}</th><th>{{ t('المبلغ', 'Amount') }}</th><th class="text-start">{{ t('السبب', 'Reason') }}</th><th>{{ t('بواسطة', 'By') }}</th><th>{{ t('الوقت', 'Time') }}</th></tr></thead>
        <tbody>
          <tr v-for="(mv, i) in r.cash.moves" :key="i"><td>{{ mv.direction === 'in' ? t('إيداع', 'In') : t('سحب', 'Out') }}</td><td class="num">{{ m(mv.amount) }}</td><td class="text-start" dir="auto">{{ mv.reason }}</td><td>{{ mv.by || '—' }}</td><td class="num">{{ fmtDate(mv.at) }}</td></tr>
        </tbody>
      </table>
    </template>

    <!-- VAT -->
    <div class="card-title mb-1.5">{{ t('ضريبة القيمة المضافة', 'VAT') }}</div>
    <table class="rep-table mb-4">
      <thead><tr><th>{{ t('النسبة', 'Rate') }}</th><th>{{ t('الأساس', 'Base') }}</th><th>{{ t('الضريبة', 'VAT') }}</th></tr></thead>
      <tbody>
        <tr v-for="v in r.vat" :key="v.ratePct"><td class="num">{{ pct(v.ratePct) }}</td><td class="num">{{ m(v.base) }}</td><td class="num">{{ m(v.vat) }}</td></tr>
        <tr v-if="!r.vat.length"><td colspan="3" class="text-muted">—</td></tr>
      </tbody>
    </table>

    <!-- products -->
    <div class="card-title mb-1.5">{{ t('الأصناف', 'Products') }}</div>
    <table class="rep-table mb-4">
      <thead><tr><th>SKU</th><th class="text-start">{{ t('الصنف', 'Product') }}</th><th>{{ t('المباع', 'Sold') }}</th><th>{{ t('المرتجع', 'Refunded') }}</th><th>{{ t('الصافي', 'Net') }}</th><th v-if="hasCost">{{ t('التكلفة', 'Cost') }}</th></tr></thead>
      <tbody>
        <tr v-for="p in r.products" :key="p.sku"><td class="num">{{ p.sku }}</td><td class="text-start" dir="auto">{{ nameOf(p) }}</td><td class="num">{{ p.qtySold }}</td><td class="num">{{ p.qtyRefunded }}</td><td class="num">{{ m(p.net) }}</td><td v-if="hasCost" class="num">{{ p.cogs != null ? m(p.cogs) : '—' }}</td></tr>
        <tr v-if="!r.products.length"><td :colspan="hasCost ? 6 : 5" class="text-muted">{{ t('لا مبيعات.', 'No sales.') }}</td></tr>
      </tbody>
    </table>

    <template v-if="(r.pending || []).length">
      <div class="card-title mb-1.5 text-warn">{{ t('كميات مستحقة على المخزون', 'Quantities owed by stock') }}</div>
      <table class="rep-table">
        <thead><tr><th>{{ t('الطلب', 'Order') }}</th><th>SKU</th><th class="text-start">{{ t('الصنف', 'Product') }}</th><th>{{ t('الكمية', 'Qty') }}</th></tr></thead>
        <tbody><tr v-for="(p, i) in r.pending" :key="i"><td class="num">{{ p.order }}</td><td class="num">{{ p.sku }}</td><td class="text-start" dir="auto">{{ p.nameAr }}</td><td class="num">{{ p.qty }}</td></tr></tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
.rep-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.rep-table th { background: var(--color-soft); color: var(--color-sec); font-weight: 800; font-size: 10.5px; padding: 6px 8px; border: 1px solid var(--color-line); text-align: center; }
.rep-table td { padding: 5px 8px; border: 1px solid var(--color-line); text-align: center; }
.rep-table td.num { direction: ltr; font-family: var(--font-num); }
@media print {
  .pos-report { border: none; box-shadow: none; padding: 0; }
}
</style>
