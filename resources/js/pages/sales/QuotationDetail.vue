<script setup>
// Quotation detail card under the quotations table: state-dependent buttons (send / approve / reject with confirm /
// convert / duplicate / print / open SO), key facts, lines, totals incl. VAT and the status history.
//   <QuotationDetail :number="sel" @close="sel = null" @select="(n) => sel = n" @open-so="…" />
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, KV, Timeline } from '@/components';
import { fmtDate, fmtDateOnly, fmtMoney, fmtNum, num, t } from '@/i18n';
import { QT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import ConvertModal from './ConvertModal.vue';
import TotalsBox from './TotalsBox.vue';
import { custName, historyItems, lineNetOf, prodName, uomCode } from './shared';

const props = defineProps({
  number: { type: String, required: true },
});
const emit = defineEmits(['close', 'select', 'open-so']);

const auth = useAuth();
const act = useAction();
const det = useGet(() => `/sales/quotations/${encodeURIComponent(props.number)}`);
const d = computed(() => det.data.value);
const history = computed(() => historyItems(d.value?.history, QT_LABELS));
const converting = ref(null);

async function run(path, success, body) {
  const r = await act.run(() => api.postIdempotent(path, body), { success, invalidate: ['sales'] });
  if (r !== undefined) det.refetch();
}
const send = () => run(`/sales/quotations/${d.value.number}/send`, t('أُرسل العرض للعميل', 'Sent to customer'));
const approve = () => run(`/sales/quotations/${d.value.number}/approve`, t('اعتُمد العرض — يمكن تحويله لأمر بيع', 'Approved — can be converted'));
async function reject() {
  const n = d.value.number;
  if (await confirm({ title: { ar: `رفض العرض ${n}؟`, en: `Reject ${n}?` } })) await run(`/sales/quotations/${n}/reject`, t('رُفض العرض', 'Rejected'), { reason: 'رفض العميل' });
}
async function duplicate() {
  const r = await act.run(() => api.postIdempotent(`/sales/quotations/${d.value.number}/duplicate`), { success: (x) => t(`نُسخ العرض → ${x.number}`, `Duplicated → ${x.number}`), invalidate: ['sales'] });
  if (r) emit('select', r.number);
}
const print = () => window.print();
function onConverted(so) { det.refetch(); emit('open-so', so.number); }

const LINE_GRID = 'bgrid grid grid-cols-[minmax(180px,1.5fr)_90px_100px_80px_110px] gap-2';
</script>

<template>
  <div class="card selected mt-3.5 px-5 py-[18px]">
    <ErrorBanner :error="det.error.value" :closable="false" />
    <div v-if="!d && !det.error.value" class="skel h-[120px]" />
    <template v-if="d">
      <div class="row wrap !gap-2.5">
        <div class="num-mixed text-[15px] font-extrabold">{{ d.number }}</div>
        <div class="text-[12px] font-extrabold">{{ custName(d.customer) }}</div>
        <Chip :map="QT_LABELS" :k="d.status" />
        <div class="grow" />
        <Btn v-if="auth.can('sales.manage') && d.status === 'draft'" tone="primary" size="sm" :loading="act.pending.value" :label="{ ar: 'إرسال للعميل', en: 'Send' }" @click="send" />
        <Btn v-if="auth.can('sales.manage') && ['draft', 'sent'].includes(d.status)" tone="success" size="sm" :loading="act.pending.value" :label="{ ar: 'اعتماد (وافق العميل)', en: 'Approve' }" @click="approve" />
        <Btn v-if="auth.can('sales.manage') && ['draft', 'sent', 'approved'].includes(d.status)" tone="softRed" size="sm" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject" />
        <Btn v-if="auth.can('so.reserve') && d.status === 'approved'" tone="purple" size="sm" :label="{ ar: 'تحويل لأمر بيع', en: 'Convert to SO' }" @click="converting = d" />
        <Btn v-if="auth.can('sales.manage')" tone="soft" size="sm" :loading="act.pending.value" :label="{ ar: 'نسخ', en: 'Duplicate' }" @click="duplicate" />
        <Btn tone="outline" size="sm" :label="{ ar: 'طباعة / PDF', en: 'Print / PDF' }" @click="print" />
        <Btn v-if="d.so" tone="softBlue" size="sm" :label="{ ar: `فتح أمر البيع ${d.so.number}`, en: `Open SO ${d.so.number}` }" @click="emit('open-so', d.so.number)" />
        <button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
      </div>
      <ErrorBanner class="mt-2.5" :error="act.error.value" @close="act.clearError()" />

      <div class="kv-grid mt-3.5">
        <KV :k="{ ar: 'التاريخ', en: 'Date' }" ltr><span class="num">{{ fmtDate(d.date) }}</span></KV>
        <KV :k="{ ar: 'صالح حتى', en: 'Valid until' }" ltr><span class="num">{{ fmtDateOnly(d.validUntil) }}</span></KV>
        <KV :k="{ ar: 'شروط الدفع', en: 'Terms' }" :v="d.terms || '—'" />
        <KV :k="{ ar: 'شروط التوصيل', en: 'Delivery' }" :v="d.delivery || '—'" />
        <KV :k="{ ar: 'المندوب', en: 'Rep' }" :v="d.createdBy || '—'" />
        <KV :k="{ ar: 'جهة الاتصال', en: 'Contact' }" :v="d.customer.contact || '—'" />
        <KV :k="{ ar: 'أمر البيع المرتبط', en: 'Linked SO' }">
          <RouterLink v-if="d.so" :to="`/so/${encodeURIComponent(d.so.number)}`" class="cell-id">{{ d.so.number }}</RouterLink>
          <template v-else>—</template>
        </KV>
        <KV :k="{ ar: 'المرفقات', en: 'Attachments' }" :v="d.attachments?.length ? d.attachments.join('، ') : '—'" />
        <KV :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="d.notes || '—'" />
      </div>

      <div class="mt-3.5 overflow-x-auto"><div class="min-w-[700px]">
        <div :class="LINE_GRID" class="rounded-[10px] bg-soft px-3.5 py-2 text-[9.5px] font-extrabold text-faint">
          <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('السعر', 'Price') }}</div><div>{{ t('خصم', 'Disc.') }}</div><div>{{ t('الصافي', 'Net') }}</div>
        </div>
        <div v-for="l in d.lines" :key="l.lineNo" :class="LINE_GRID" class="items-center border-b border-line-2 px-3.5 py-[9px]">
          <div><div class="text-[11px] font-extrabold">{{ prodName(l.product) }}</div><div class="cell-sub">{{ l.product.sku }}</div></div>
          <div class="num text-[11px]">{{ fmtNum(l.qty) }} <span class="font-sans text-[8.5px] text-faint">{{ uomCode(l.product.baseUom) }}</span></div>
          <div class="num text-[11px]">{{ fmtMoney(l.price) }}</div>
          <div class="num text-[10.5px] text-warn">{{ num(l.discPct) > 0 ? `${l.discPct}%` : '—' }}</div>
          <div class="num text-[11.5px]">{{ fmtMoney(lineNetOf(l)) }}</div>
        </div>
      </div></div>
      <div class="mt-3 flex justify-end"><TotalsBox :totals="d.totals" wide /></div>

      <div class="mt-4 text-[12px] font-extrabold">{{ t('سجل الحالة', 'Status history') }}</div>
      <div class="mt-1.5"><Timeline :items="history" :empty-text="{ ar: 'لا سجل', en: 'No history' }" /></div>
    </template>

    <ConvertModal :qt="converting" @close="converting = null" @done="onConverted" />
  </div>
</template>
