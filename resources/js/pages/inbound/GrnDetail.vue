<script setup>
// GRN detail (GRN log tab + /grn/:number): header with links, lines + QC results, exceptions, putaway tasks, inventory movements.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, KV, SectionCard, Timeline } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import { QC_LABELS, pn } from './shared';

const props = defineProps({ grn: { type: Object, required: true } });

// Exceptions raised for this receipt (shortage / damaged / rejected).
const exc = useList('/exceptions', () => ({ entity: props.grn.number, pageSize: 20 }));
const excItems = computed(() => exc.data.value?.items || []);
const excFg = (s) => (s === 'resolved' ? '#1d7a3e' : s === 'ack' ? '#b26a16' : '#b23b3b');
const excBg = (s) => (s === 'resolved' ? '#e6f9ec' : s === 'ack' ? '#fbf0dd' : '#fdecec');

const cols = [
  { key: 'lineNo', header: '#', width: '36px', kind: 'num' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.5fr)' },
  { key: 'receivedQty', header: { ar: 'مستلم', en: 'Received' }, width: '70px', kind: 'num' },
  { key: 'acceptedQty', header: { ar: 'مقبول', en: 'Accepted' }, width: '70px', kind: 'num' },
  { key: 'damagedQty', header: { ar: 'تالف', en: 'Damaged' }, width: '70px', kind: 'num' },
  { key: 'rejectedQty', header: { ar: 'مرفوض QC', en: 'Rejected' }, width: '80px', kind: 'num' },
  { key: 'remainingQty', header: { ar: 'متبقٍ على PO', en: 'Open on PO' }, width: '90px', kind: 'num' },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '100px' },
  { key: 'expiryDate', header: { ar: 'الانتهاء', en: 'Expiry' }, width: '90px', kind: 'date', value: (l) => fmtDateOnly(l.expiryDate) },
  { key: 'qc', header: 'QC', width: 'minmax(120px,1fr)' },
];

// Movement entries: the label is the type chip + number (slot `chip`), the note is "qty × batch → bin".
const movements = computed(() => (props.grn.movements || []).map((m) => ({
  at: m.createdAt, label: '', type: m.type, number: m.number,
  note: `${fmtNum(m.qty)} × ${m.batchNo || '—'} → ${m.dstBin?.code || '—'}${m.srcBin ? ` (${t('من', 'from')} ${m.srcBin.code})` : ''}`,
  color: m.type === 'dmg' ? '#b23b3b' : m.type === 'putaway' ? '#654e92' : '#1d7a3e',
})));
</script>

<template>
  <SectionCard class="mb-3.5">
    <div class="pt-4">
      <div class="row wrap !gap-3">
        <div>
          <div class="num text-[15px] text-ok">{{ grn.number }}</div>
          <div class="mt-[3px] text-[10px] text-muted">
            {{ t('أُصدر', 'Posted') }} <span class="ltr num">{{ fmtDate(grn.postedAt) }}</span>{{ grn.postedBy ? ` · ${grn.postedBy}` : '' }}<template v-if="grn.transactionId"> · <span class="num ltr text-[8.5px]">{{ grn.transactionId }}</span></template>
          </div>
        </div>
        <span class="grow" />
        <RouterLink :to="`/po/${encodeURIComponent(grn.po.number)}`" class="chip bg-violet-soft !text-violet">PO {{ grn.po.number }}</RouterLink>
        <RouterLink v-if="grn.shipment" :to="`/shipments/${encodeURIComponent(grn.shipment.number)}`" class="chip bg-[#d9f4f9] !text-brand-dark">{{ grn.shipment.number }}</RouterLink>
        <RouterLink :to="`/ledger?referenceNumber=${encodeURIComponent(grn.number)}`" class="chip bg-line-2 !text-sec">{{ t('سجل الحركات', 'Ledger') }}</RouterLink>
      </div>
      <div class="kv-grid mt-3.5">
        <KV :k="{ ar: 'المورد', en: 'Supplier' }" :v="pn(grn.supplier)" />
        <KV :k="{ ar: 'المستودع', en: 'Warehouse' }" :v="`${grn.warehouse.code} ${grn.warehouse.nameAr ? `· ${grn.warehouse.nameAr}` : ''}`" />
        <KV :k="{ ar: 'الملخص', en: 'Summary' }"><span class="font-normal">{{ lang === 'ar' ? grn.summaryAr : grn.summaryEn }}</span></KV>
        <KV v-if="grn.notes" :k="{ ar: 'ملاحظات', en: 'Notes' }"><span class="font-normal">{{ grn.notes }}</span></KV>
      </div>
    </div>
  </SectionCard>

  <SectionCard :title="{ ar: 'البنود ونتائج الفحص QC', en: 'Lines & QC results' }" :count="grn.lines.length" :padded="false" class="mb-3.5">
    <DataTable :columns="cols" :rows="grn.lines" :row-key="(l) => l.id" :min-width="900">
      <template #cell-product="{ row }">
        <div>
          <RouterLink :to="`/product/${encodeURIComponent(row.product.sku)}`" class="cell-name !text-ink">{{ pn(row.product) }}</RouterLink>
          <div class="num text-[8.5px] text-faint">{{ row.product.sku }}</div>
        </div>
      </template>
      <template #cell-acceptedQty="{ row }"><span class="text-ok">{{ fmtNum(row.acceptedQty) }}</span></template>
      <template #cell-damagedQty="{ row }"><span :class="row.damagedQty ? 'text-bad' : 'text-faint'">{{ fmtNum(row.damagedQty) }}</span></template>
      <template #cell-rejectedQty="{ row }"><span :class="row.rejectedQty ? 'text-warn' : 'text-faint'">{{ fmtNum(row.rejectedQty) }}</span></template>
      <template #cell-remainingQty="{ row }"><span :class="row.remainingQty ? 'text-warn' : 'text-ok'">{{ fmtNum(row.remainingQty) }}</span></template>
      <template #cell-batchNo="{ row }"><span class="num text-violet">{{ row.batchNo || '—' }}</span></template>
      <template #cell-qc="{ row }">
        <div><Chip :map="QC_LABELS" :k="row.qcResult" small /><div v-if="row.qcNote" class="mt-[3px] text-[9px] text-muted">{{ row.qcNote }}</div></div>
      </template>
    </DataTable>
  </SectionCard>

  <div class="grid-2">
    <div>
      <SectionCard :title="{ ar: 'الاستثناءات', en: 'Exceptions' }" :sub="{ ar: 'نقص · تالف · مرفوض — تُفتح للمشتريات تلقائيًا', en: 'Shortage · damaged · rejected — raised for procurement' }" class="mb-3.5">
        <div v-if="!excItems.length" class="empty">{{ t('لا استثناءات على هذا الاستلام ✓', 'No exceptions on this receipt ✓') }}</div>
        <div v-else class="col !gap-1.5">
          <RouterLink v-for="e in excItems" :key="e.id" :to="`/exc/${encodeURIComponent(e.number)}`" class="flex items-center gap-2 rounded-[11px] border border-line-2 px-3 py-2 text-[10.5px] !text-inherit">
            <span class="num text-bad">{{ e.number }}</span><span class="grow text-sec">{{ lang === 'ar' ? e.textAr : e.textEn }}</span><Chip small :label="e.status" :fg="excFg(e.status)" :bg="excBg(e.status)" />
          </RouterLink>
        </div>
      </SectionCard>
      <SectionCard :title="{ ar: 'مهام التخزين Putaway', en: 'Putaway tasks' }" :count="grn.putaways?.length">
        <div v-if="!grn.putaways?.length" class="empty">{{ t('لا مهام تخزين (لا كميات مقبولة)', 'No putaway tasks (nothing accepted)') }}</div>
        <div v-else class="col !gap-1.5">
          <div v-for="p in grn.putaways" :key="p.id" class="row wrap rounded-[11px] border border-line-2 px-3 py-2 text-[10.5px]">
            <span class="num text-violet">{{ p.number }}</span><span class="font-extrabold">{{ pn(p.product) }}</span><span class="num muted">× {{ fmtNum(p.qty) }}</span><span class="grow" />
            <span class="num text-brand-dark">{{ p.suggestedBin?.code || '—' }}</span>
            <Chip v-if="p.status === 'done'" small fg="#1d7a3e" bg="#e6f9ec" :label="`${t('مخزّن ✓', 'Stored ✓')} ${p.actualBin?.code || ''}`" />
            <Chip v-else small fg="#b26a16" bg="#fbf0dd" :label="{ ar: 'مفتوحة', en: 'Open' }" />
          </div>
        </div>
      </SectionCard>
    </div>
    <SectionCard :title="{ ar: 'حركات المخزون', en: 'Inventory movements' }" :count="grn.movements?.length">
      <div v-if="!grn.movements?.length" class="empty">{{ t('لا حركات', 'No movements') }}</div>
      <Timeline v-else :items="movements">
        <template #chip="{ item }"><span class="-ms-2 inline-flex items-center"><Chip :map="MOVEMENT_LABELS" :k="item.type" small /> <span class="num ms-1.5 text-[10.5px]">{{ item.number }}</span></span></template>
      </Timeline>
    </SectionCard>
  </div>
</template>
