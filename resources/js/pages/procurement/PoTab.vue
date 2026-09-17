<script setup>
// Purchase orders tab: search + status pills + warehouse selector → table with the approval chain and the row actions.
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import { PO_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import ChainPills from './ChainPills.vue';
import FilterBar from './FilterBar.vue';
import PoActions from './PoActions.vue';
import { pname, sname } from './shared';

const emit = defineEmits(['new']);
const auth = useAuth();
const wh = useWarehouse();
const router = useRouter();

const q = ref('');
const status = ref('');
const page = ref(1);
watch([q, status, () => wh.whParams.warehouse], () => { page.value = 1; });
const list = useList('/procurement/po', () => ({ q: q.value, status: status.value, page: page.value, pageSize: 25, ...wh.whParams }));

const openPo = (r) => router.push(`/po/${encodeURIComponent(r.number)}`);
const STATUSES = [
  { k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'pending', label: { ar: 'بانتظار الاعتماد', en: 'Pending' } }, { k: 'approved', label: { ar: 'معتمد', en: 'Approved' } },
  { k: 'sent,confirmed', label: { ar: 'مُرسل / مؤكد', en: 'Sent / confirmed' } }, { k: 'partial,received', label: { ar: 'مستلم', en: 'Received' } },
  { k: 'draft', label: { ar: 'مسودة', en: 'Draft' } }, { k: 'cancelled', label: { ar: 'ملغى', en: 'Cancelled' } },
];
const columns = [
  { key: 'number', header: { ar: 'الأمر', en: 'PO' }, width: '130px' },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(150px,1.2fr)', kind: 'name', value: (r) => sname(r.supplier) },
  { key: 'items', header: { ar: 'الأصناف', en: 'Items' }, width: '110px', class: 'text-[10px]', value: (r) => `${fmtNum(r._count?.lines ?? r.lines?.length ?? 0)} ${t('صنف', 'lines')}${r._count?.grns ? ` · ${r._count.grns} GRN` : ''}` },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num', sortable: true, sortValue: (r) => Number(r.total), value: (r) => fmtMoney(r.total) },
  { key: 'dueDate', header: { ar: 'التوريد', en: 'Due' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.dueDate) },
  { key: 'chain', header: { ar: 'سلسلة الاعتماد', en: 'Approval chain' }, width: 'minmax(180px,1.3fr)' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '140px' },
  { key: 'act', header: '', width: 'minmax(170px,1fr)' },
];
</script>

<template>
  <FilterBar v-model="q">
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <span class="grow" />
    <Btn v-if="auth.can('po.create')" size="sm" tone="primary" :label="{ ar: '+ أمر شراء', en: '+ PO' }" @click="emit('new')" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1020" :empty-text="{ ar: 'لا أوامر شراء مطابقة.', en: 'No matching purchase orders.' }" @page="page = $event" @row-click="openPo">
      <template #cell-number="{ row }">
        <div>
          <div class="cell-id cursor-pointer" @click.stop="openPo(row)">{{ row.number }}</div>
          <div class="text-[8px] text-faint">{{ row.warehouse.code }} · {{ pname(row.warehouse) }}</div>
        </div>
      </template>
      <template #cell-chain="{ row }"><ChainPills :approvals="row.approvals" /></template>
      <template #cell-status="{ row }"><Chip :map="PO_LABELS" :k="row.status" /></template>
      <template #cell-act="{ row }"><div @click.stop><PoActions :po="row" /></div></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('سلسلة الاعتماد تُحدَّد بقيمة الأمر: أقل من 5,000 مدير المشتريات · 5–25 ألف + المدير العام · أكثر من 25 ألف + المالية. الإرسال للمورد يُنشئ شحنة متوقعة في الاستلام، والمورد دون تقييم 65 يحتاج استثناءً موثقًا.', 'Approval chain by total: < 5,000 procurement · 5–25k + GM · > 25k + finance. Sending creates an expected inbound shipment; suppliers below score 65 need a documented override.') }}</div>
</template>
