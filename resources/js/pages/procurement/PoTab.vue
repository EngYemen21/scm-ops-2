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
/** '' | '1' — only orders past their delivery date and still awaiting goods. */
const late = ref('');
const page = ref(1);
watch([q, status, late, () => wh.whParams.warehouse], () => { page.value = 1; });
const list = useList('/procurement/po', () => ({ q: q.value, status: status.value, late: late.value, page: page.value, pageSize: 25, ...wh.whParams }));
const LATE_TABS = [{ k: '', label: { ar: 'كل المواعيد', en: 'Any date' } }, { k: '1', label: { ar: 'متأخرة التوريد', en: 'Late delivery' } }];

const openPo = (r) => router.push(`/po/${encodeURIComponent(r.number)}`);
/** { ordered, received, damaged, rejected, open, waived } → tooltip of the received column. */
const receiptTitle = (r) => t(`مطلوب ${r.ordered} · مستلم سليم ${r.received} · تالف ${r.damaged} · مرفوض ${r.rejected} · متبقٍ على المورد ${r.open}${r.waived ? ` · أُسقط ${r.waived}` : ''}`,
  `Ordered ${r.ordered} · received ${r.received} · damaged ${r.damaged} · rejected ${r.rejected} · still owed ${r.open}${r.waived ? ` · waived ${r.waived}` : ''}`);
const STATUSES = [
  { k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'pending', label: { ar: 'بانتظار الاعتماد', en: 'Pending' } }, { k: 'approved', label: { ar: 'معتمد', en: 'Approved' } },
  { k: 'sent,confirmed', label: { ar: 'مُرسل / مؤكد', en: 'Sent / confirmed' } }, { k: 'partial', label: { ar: 'مستلم جزئيًا', en: 'Partly received' } }, { k: 'received', label: { ar: 'مستلم', en: 'Received' } },
  { k: 'closed', label: { ar: 'مقفل', en: 'Closed' } }, { k: 'draft', label: { ar: 'مسودة', en: 'Draft' } }, { k: 'cancelled', label: { ar: 'ملغى', en: 'Cancelled' } },
];
const columns = [
  { key: 'number', header: { ar: 'الأمر', en: 'PO' }, width: '130px' },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(150px,1.2fr)', kind: 'name', value: (r) => sname(r.supplier) },
  { key: 'items', header: { ar: 'الأصناف', en: 'Items' }, width: '110px', class: 'text-[10px]', value: (r) => `${fmtNum(r._count?.lines ?? r.lines?.length ?? 0)} ${t('صنف', 'lines')}${r._count?.grns ? ` · ${r._count.grns} GRN` : ''}` },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num', sortable: true, sortValue: (r) => Number(r.total), value: (r) => fmtMoney(r.total) },
  { key: 'dueDate', header: { ar: 'التوريد', en: 'Due' }, width: '110px' },
  { key: 'received', header: { ar: 'مستلم / مطلوب', en: 'Received / ordered' }, width: '100px' },
  { key: 'chain', header: { ar: 'سلسلة الاعتماد', en: 'Approval chain' }, width: 'minmax(170px,1.3fr)' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '140px' },
  { key: 'act', header: '', width: 'minmax(170px,1fr)' },
];
</script>

<template>
  <FilterBar v-model="q">
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <Tabs v-model="late" :tabs="LATE_TABS" variant="pill" class="!mb-0" />
    <span class="grow" />
    <Btn v-if="auth.can('po.create')" size="sm" tone="primary" :label="{ ar: '+ أمر شراء', en: '+ PO' }" @click="emit('new')" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1140" :empty-text="{ ar: 'لا أوامر شراء مطابقة.', en: 'No matching purchase orders.' }" @page="page = $event" @row-click="openPo">
      <template #cell-number="{ row }">
        <div>
          <div class="cell-id cursor-pointer" @click.stop="openPo(row)">{{ row.number }}</div>
          <div class="text-[8px] text-faint">{{ row.warehouse.code }} · {{ pname(row.warehouse) }}</div>
        </div>
      </template>
      <template #cell-dueDate="{ row }">
        <span class="cell-date ltr">{{ fmtDateOnly(row.dueDate) }}</span>
        <Chip v-if="row.late" small fg="#b23b3b" bg="#fdecec" :label="{ ar: 'متأخر', en: 'Late' }" class="ms-1" />
      </template>
      <template #cell-received="{ row }">
        <!-- figures, not one percentage: received / ordered, then what was damaged, rejected and is still owed -->
        <div v-if="['sent', 'confirmed', 'partial', 'received', 'closed'].includes(row.status) && row.receipt" :title="receiptTitle(row.receipt)">
          <div class="num text-[10.5px]"><span :class="row.receipt.received ? 'text-ok' : 'text-faint'">{{ fmtNum(row.receipt.received) }}</span><span class="text-faint"> / {{ fmtNum(row.receipt.ordered) }}</span></div>
          <div class="num text-[8.5px] leading-[1.5]">
            <span v-if="row.receipt.damaged" class="text-bad">{{ fmtNum(row.receipt.damaged) }} {{ t('تالف', 'dmg') }} </span>
            <span v-if="row.receipt.rejected" class="text-warn">{{ fmtNum(row.receipt.rejected) }} {{ t('مرفوض', 'rej') }} </span>
            <span v-if="row.receipt.open" class="text-warn">{{ fmtNum(row.receipt.open) }} {{ t('متبقٍ', 'open') }}</span>
            <span v-else-if="row.receipt.waived" class="text-muted">{{ fmtNum(row.receipt.waived) }} {{ t('أُسقط', 'waived') }}</span>
          </div>
        </div>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-chain="{ row }"><ChainPills :approvals="row.approvals" /></template>
      <template #cell-status="{ row }">
        <Chip :map="PO_LABELS" :k="row.status" />
        <Chip v-if="row.priceChanged" small fg="#b26a16" bg="#fbf0dd" class="mt-0.5" :label="{ ar: 'سعر أعلى من تقدير الطلب', en: 'Above PR estimate' }" :title="row.priceNote || null" />
      </template>
      <template #cell-act="{ row }"><div @click.stop><PoActions :po="row" /></div></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('سلسلة الاعتماد تُحدَّد من قيمة الأصناف حسب شرائح الإعدادات، ومنشئ الأمر لا يعتمده (فصل المسؤوليات). الإرسال للمورد يُنشئ شحنة متوقعة في الاستلام؛ الأمر الذي تجاوز موعده يظهر «متأخر» ويُنبَّه عليه آليًا مرة واحدة وتُسجَّل متابعته. المورد دون الحد الأدنى للتقييم أو بمستند إلزامي منتهٍ يحتاج استثناءً موثقًا. المرفوض عند الفحص لا يُحتسب مستلمًا ويبقى على المورد؛ الأمر المستلم جزئيًا يُكمَل بشحنة متابعة أو يُقفل على ما استُلم بسبب مكتوب.', 'Approval chain follows the goods value per the configured tiers and the creator never approves (segregation of duties). Sending creates an expected inbound shipment; an order past its date shows as late, is alerted once automatically and its follow-up is recorded. Suppliers below the minimum score or with an expired mandatory document need a documented override. Goods rejected at inspection never count as received and stay owed; a partly received order is completed by a follow-up shipment or closed short with a written reason.') }}</div>
</template>
