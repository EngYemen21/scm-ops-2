<script setup>
// Supplier quotations tab: every recorded quotation with its attachment name and linked RFQ (click → RFQ comparison).
// File upload / preview is Integration Pending — clicking the attachment says so, never a fake preview.
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner } from '@/components';
import { fmtDateOnly, fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { toast } from '@/stores/ui';
import FilterBar from './FilterBar.vue';
import { SQ_LABELS, pname, sname } from './shared';

const emit = defineEmits(['new']);
const auth = useAuth();
const route = useRoute();
const router = useRouter();

const q = ref('');
const page = ref(1);
watch(q, () => { page.value = 1; });
const list = useList('/procurement/quotations', () => ({ q: q.value, page: page.value, pageSize: 25 }));

const sayPending = () => toast.say({ ar: 'معاينة المرفق — Integration Pending (تخزين الملفات غير مربوط)', en: 'Attachment preview — Integration Pending (file storage not connected)' });
/** Same call as the reference (the read is audited by the server); the preview itself is not connected. */
const preview = (x) => api.get(`/procurement/quotations/${x.number}`).then(sayPending, sayPending);
const openRfq = (number) => router.push({ query: { ...route.query, tab: 'rfq', rfq: number } });

const columns = [
  { key: 'number', header: { ar: 'العرض', en: 'Quote' }, width: '110px' },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(140px,1.1fr)', kind: 'name', value: (r) => sname(r.supplier) },
  { key: 'prod', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(150px,1.3fr)', class: 'text-[10px]', value: (r) => r.lines.map((l) => pname(l.product)).join('، ') },
  { key: 'price', header: { ar: 'سعر الوحدة', en: 'Unit price' }, width: '90px', kind: 'num', value: (r) => r.lines.map((l) => fmtMoney(l.price)).join(' / ') },
  { key: 'lead', header: { ar: 'مهلة', en: 'Lead' }, width: '70px', kind: 'num', class: 'text-violet', value: (r) => `${r.leadDays} ${t('ي', 'd')}` },
  { key: 'pay', header: { ar: 'الدفع', en: 'Payment' }, width: '100px', class: 'text-[9.5px] font-bold', value: (r) => r.paymentTerms || '—' },
  { key: 'valid', header: { ar: 'صالح حتى', en: 'Valid until' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.validUntil) },
  { key: 'att', header: { ar: 'الملف', en: 'File' }, width: 'minmax(140px,1.1fr)' },
  { key: 'rfq', header: 'RFQ', width: '100px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '110px' },
];
</script>

<template>
  <FilterBar v-model="q">
    <span class="grow" />
    <Btn v-if="auth.can('supquote.create')" size="sm" :label="{ ar: '+ عرض سعر مورد', en: '+ Supplier quote' }" @click="emit('new')" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1000" :empty-text="{ ar: 'لا عروض مسجلة.', en: 'No quotations recorded.' }" @page="page = $event">
      <template #cell-number="{ row }"><div><div class="cell-id">{{ row.number }}</div><div class="num ltr text-start text-[8px] text-faint">{{ row.supplierRef }}</div></div></template>
      <template #cell-att="{ row }">
        <span v-if="row.attachmentName" class="row cursor-pointer !gap-1.5" @click.stop="preview(row)">
          <span class="num rounded-[5px] bg-bad px-1.5 py-0.5 text-[8px] text-white">{{ row.attachmentType }}</span>
          <span class="num ltr ellipsis text-[9.5px] text-brand-dark">{{ row.attachmentName }}</span>
        </span>
        <template v-else>—</template>
      </template>
      <template #cell-rfq="{ row }">
        <span v-if="row.rfq" class="num ltr cursor-pointer text-[9.5px] text-azure" @click.stop="openRfq(row.rfq.number)">{{ row.rfq.number }}</span>
        <template v-else>—</template>
      </template>
      <template #cell-status="{ row }"><Chip :map="SQ_LABELS" :k="row.status" small /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('كل عرض سعر يُسجَّل باسم مرفقه (PDF / JPG / PNG) — رفع الملفات ومعاينتها: Integration Pending. العروض المرتبطة بـ RFQ تدخل المقارنة تلقائيًا.', 'Every quotation is recorded with its attachment name (PDF / JPG / PNG) — file upload and preview: Integration Pending. Quotes linked to an RFQ join the comparison automatically.') }}</div>
</template>
