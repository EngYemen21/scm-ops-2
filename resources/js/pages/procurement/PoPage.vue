<script setup>
// PO detail — deep link /po/:number: header tiles, status stepper, meta, lines, shipments → GRNs trace, approval chain, actions.
import { computed } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, PageHead, SectionCard, Stepper } from '@/components';
import { fmtDate, fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { PO_LABELS, SHIPMENT_LABELS } from '@/shared';
import ApprovalsTimeline from './ApprovalsTimeline.vue';
import PoActions from './PoActions.vue';
import { pname, sname } from './shared';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const poQ = useGet(() => (number.value ? `/procurement/po/${encodeURIComponent(number.value)}` : null));
/** Po — see shared.js */
const po = computed(() => poQ.data.value);

const STEPS = ['draft', 'pending', 'approved', 'sent', 'confirmed', 'received', 'closed'];
const STEP_LABELS = [
  { label: { ar: 'مسودة', en: 'Draft' } }, { label: { ar: 'بانتظار الاعتماد', en: 'Pending' } }, { label: { ar: 'معتمد', en: 'Approved' } }, { label: { ar: 'أُرسل للمورد', en: 'Sent' } },
  { label: { ar: 'مؤكد', en: 'Confirmed' } }, { label: { ar: 'مستلم — GRN', en: 'Received' } }, { label: { ar: 'مقفل', en: 'Closed' } },
];
const failed = computed(() => po.value?.status === 'cancelled');
const current = computed(() => {
  if (!po.value) return -1;
  // A partially received PO sits between "confirmed" and "received".
  const cur = Math.floor(po.value.status === 'partial' ? STEPS.indexOf('received') - 0.5 : STEPS.indexOf(po.value.status));
  return failed.value ? Math.max(0, cur) : cur;
});
const sub = computed(() => {
  const p = po.value;
  return p ? `${sname(p.supplier)} · ${p.warehouse.code} ${pname(p.warehouse)} · ${t('أُنشئ', 'Created')} ${fmtDate(p.createdAt)}${p.createdBy ? ` · ${p.createdBy}` : ''}` : null;
});
const noSource = computed(() => !po.value?.sources?.rfq && !po.value?.sources?.prs?.length);
const openQty = (l) => Math.max(0, l.qty - l.receivedQty - l.damagedQty - l.rejectedQty);

const columns = [
  { key: 'lineNo', header: '#', width: '36px', kind: 'num' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(180px,1.6fr)' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '80px', kind: 'num', value: (l) => fmtNum(l.qty) },
  { key: 'price', header: { ar: 'سعر الوحدة', en: 'Unit price' }, width: '90px', kind: 'num', value: (l) => fmtMoney(l.price) },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num' },
  { key: 'receivedQty', header: { ar: 'مستلم', en: 'Received' }, width: '80px', kind: 'num', class: 'text-ok', value: (l) => fmtNum(l.receivedQty) },
  { key: 'damagedQty', header: { ar: 'تالف', en: 'Damaged' }, width: '70px', kind: 'num' },
  { key: 'rejectedQty', header: { ar: 'مرفوض QC', en: 'Rejected' }, width: '80px', kind: 'num' },
  { key: 'open', header: { ar: 'متبقٍ', en: 'Open' }, width: '70px', kind: 'num' },
];
</script>

<template>
  <PageHead :sub="sub">
    <Btn tone="outline" :label="{ ar: '← أوامر الشراء', en: '← Purchase orders' }" @click="router.push('/procurement?tab=po')" />
    <PoActions v-if="po" :po="po" size="md" @changed="poQ.refetch()" />
  </PageHead>
  <ErrorBanner :error="poQ.error.value" :closable="false" />
  <div v-if="poQ.isLoading.value" class="skel min-h-[240px]" />

  <template v-if="po">
    <SectionCard class="mb-3.5">
      <div class="row wrap !gap-3 pt-3.5">
        <div><div class="num-mixed text-[15px] font-extrabold">{{ po.number }}</div><div class="mt-1"><Chip :map="PO_LABELS" :k="po.status" /></div></div>
        <span class="grow" />
        <div class="tile"><div class="tile-v">{{ fmtMoney(po.total) }} <span class="font-sans text-[9px] text-faint">{{ t('ر.س', 'SAR') }}</span></div><div class="tile-l">{{ t('الإجمالي', 'Total') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtDateOnly(po.dueDate) }}</div><div class="tile-l">{{ t('تاريخ التوريد', 'Due date') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtNum(po.lines?.length || 0) }}</div><div class="tile-l">{{ t('أصناف', 'Lines') }}</div></div>
        <div class="tile amber"><div class="tile-v">{{ fmtNum(po.openQty ?? 0) }}</div><div class="tile-l">{{ t('كمية مفتوحة', 'Open qty') }}</div></div>
      </div>
      <div class="mt-4"><Stepper :steps="STEP_LABELS" :current="current" :failed="failed" /></div>
      <div class="kv-grid mt-4">
        <div class="kv"><div class="kv-k">{{ t('المورد', 'Supplier') }}</div><div class="kv-v cursor-pointer !text-violet" @click="router.push(`/procurement?tab=sup&supplier=${encodeURIComponent(po.supplier.code)}`)">{{ sname(po.supplier) }} <span class="num muted text-[9px]">Score {{ fmtNum(po.supplier.score) }}</span></div></div>
        <div class="kv"><div class="kv-k">{{ t('شروط الدفع', 'Payment terms') }}</div><div class="kv-v">{{ po.paymentTerms || '—' }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('المرجع', 'Reference') }}</div><div class="kv-v num-mixed">{{ po.reference || '—' }}</div></div>
        <div class="kv">
          <div class="kv-k">{{ t('المصدر', 'Source') }}</div>
          <div class="kv-v">
            <span v-if="po.sources?.rfq" class="num cursor-pointer text-azure" @click="router.push(`/procurement?tab=rfq&rfq=${encodeURIComponent(po.sources.rfq.number)}`)">{{ po.sources.rfq.number }}</span>
            <span v-for="p in po.sources?.prs || []" :key="p.number" class="num ms-1.5 text-violet">{{ p.number }}</span>
            <template v-if="noSource">—</template>
          </div>
        </div>
        <div class="kv"><div class="kv-k">{{ t('أُرسل', 'Sent') }}</div><div class="kv-v ltr num text-start">{{ fmtDate(po.sentAt) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('تأكيد المورد', 'Confirmed') }}</div><div class="kv-v ltr num text-start">{{ fmtDate(po.confirmedAt) }}</div></div>
        <div v-if="po.notes" class="kv col-span-full"><div class="kv-k">{{ t('ملاحظات', 'Notes') }}</div><div class="kv-v whitespace-pre-wrap !font-normal">{{ po.notes }}</div></div>
      </div>
    </SectionCard>

    <SectionCard :title="{ ar: 'البنود', en: 'Lines' }" :count="po.lines?.length" :padded="false" class="mb-3.5">
      <DataTable :columns="columns" :rows="po.lines || []" :row-key="(l) => l.id" :min-width="860">
        <template #cell-product="{ row }">
          <div>
            <RouterLink :to="`/product/${encodeURIComponent(row.product.sku)}`" class="cell-name !text-ink no-underline">{{ pname(row.product) }}</RouterLink>
            <div class="num text-[8.5px] text-faint">{{ row.product.sku }}{{ row.product.tracksExpiry ? ` · ${t('يتتبع الصلاحية', 'tracks expiry')}` : '' }}</div>
          </div>
        </template>
        <template #cell-total="{ row }"><b>{{ fmtMoney(row.qty * Number(row.price)) }}</b></template>
        <template #cell-damagedQty="{ row }"><span :class="row.damagedQty ? 'text-bad' : 'text-faint'">{{ fmtNum(row.damagedQty) }}</span></template>
        <template #cell-rejectedQty="{ row }"><span :class="row.rejectedQty ? 'text-warn' : 'text-faint'">{{ fmtNum(row.rejectedQty) }}</span></template>
        <template #cell-open="{ row }"><span class="font-extrabold" :class="openQty(row) ? 'text-warn' : 'text-ok'">{{ fmtNum(openQty(row)) }}</span></template>
        <template #footer><span class="font-extrabold">{{ t('الإجمالي', 'Total') }}: <span class="num">{{ fmtMoney(po.total) }}</span> {{ t('ر.س', 'SAR') }}</span></template>
      </DataTable>
    </SectionCard>

    <div class="grid-2">
      <SectionCard :title="{ ar: 'التتبع — الشحنات وإشعارات الاستلام', en: 'Trace — shipments & GRNs' }" :sub="{ ar: 'PO → شحنة متوقعة → GRN → Putaway → حركات المخزون', en: 'PO → expected shipment → GRN → putaway → movements' }">
        <div v-if="!po.shipments?.length" class="empty">
          {{ po.status === 'approved' ? t('لم يُرسل بعد — الإرسال للمورد يُنشئ الشحنة المتوقعة', 'Not sent yet — sending creates the expected shipment') : t('لا شحنات مرتبطة بعد', 'No shipments yet') }}
        </div>
        <div class="col">
          <div v-for="s in po.shipments || []" :key="s.number" class="rounded-xl border border-line-2 px-[13px] py-2.5">
            <div class="row wrap">
              <RouterLink :to="`/shipments/${encodeURIComponent(s.number)}`" class="num text-[11px] !text-brand-dark no-underline">{{ s.number }}</RouterLink>
              <Chip :map="SHIPMENT_LABELS" :k="s.status" small />
              <span class="grow" />
              <span class="cell-date ltr">ETA {{ fmtDateOnly(s.eta) }}</span>
            </div>
            <div class="row wrap mt-1.5 !gap-1.5">
              <span v-if="!(s.grns || []).length" class="muted text-[9.5px]">{{ t('لا GRN بعد', 'No GRN yet') }}</span>
              <RouterLink v-for="g in s.grns || []" :key="g.number" :to="`/grn/${encodeURIComponent(g.number)}`" class="chip bg-ok-soft !text-ok no-underline">{{ g.number }} · <span class="ltr">{{ fmtDate(g.postedAt) }}</span></RouterLink>
            </div>
          </div>
        </div>
        <div v-if="po.grns?.length" class="mt-3">
          <div class="field-l mb-1.5">{{ t('ملخص الاستلام', 'Receipt summary') }}</div>
          <div v-for="g in po.grns" :key="g.number" class="border-t border-[#F7F6FA] py-1.5 text-[10px] leading-[1.7] text-sec">
            <RouterLink :to="`/grn/${encodeURIComponent(g.number)}`" class="num !text-ok no-underline">{{ g.number }}</RouterLink> · {{ lang === 'ar' ? g.summaryAr : g.summaryEn }} <span class="muted ltr num text-[9px]">{{ fmtDate(g.postedAt) }}</span>
          </div>
        </div>
      </SectionCard>

      <SectionCard :title="{ ar: 'سلسلة الاعتماد', en: 'Approval chain' }" :sub="po.currentStep ? { ar: `الخطوة الحالية: ${po.currentStep.labelAr}`, en: `Current step: ${po.currentStep.labelEn}` } : null">
        <ApprovalsTimeline :approvals="po.approvals" :created-by="po.createdBy" :created-at="po.createdAt" />
      </SectionCard>
    </div>
  </template>
</template>
