<script setup>
// Sales › customers tab: search + cash / credit filter, table with credit usage, detail drawer (balance, limit,
// available credit, recent orders) with edit / new quotation / new sales order.
import { computed, ref, watch } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, ProgressBar, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, num, t } from '@/i18n';
import { SO_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import CustomerForm from './CustomerForm.vue';
import { CASH_TERMS, custName, priceListLabel } from './shared';

const props = defineProps({
  initialQ: { type: String, default: '' },
});
const emit = defineEmits(['new-quote', 'new-so']);

const auth = useAuth();
const q = ref(props.initialQ);
const terms = ref('');
const page = ref(1);
const sel = ref(null);
const editing = ref(null);
watch(() => props.initialQ, (v) => { q.value = v; page.value = 1; });

const list = useList('/customers', () => ({ q: q.value.trim() || undefined, terms: terms.value || undefined, page: page.value, pageSize: 25 }));
const det = useGet(() => (sel.value ? `/customers/${encodeURIComponent(sel.value)}` : null));
const d = computed(() => det.data.value);

/** Credit usage of a customer: `{ pct, color, lim }`. */
function usage(c) {
  const lim = num(c.creditLimit); const bal = num(c.balance);
  const pct = lim > 0 ? Math.round((bal / lim) * 100) : 0;
  return { pct, color: pct > 85 ? '#b23b3b' : pct > 60 ? '#b26a16' : '#1d7a3e', lim };
}
const du = computed(() => (d.value ? usage(d.value) : null));

const termTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'cash', label: { ar: 'نقدي', en: 'Cash' } }, { k: 'credit', label: { ar: 'آجل', en: 'Credit' } }];
const columns = [
  { key: 'name', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(180px,1.5fr)' },
  { key: 'city', header: { ar: 'المدينة · المنطقة', en: 'City · zone' }, width: '150px' },
  { key: 'terms', header: { ar: 'شروط الدفع', en: 'Terms' }, width: '110px' },
  { key: 'creditLimit', header: { ar: 'الحد الائتماني', en: 'Credit limit' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.creditLimit) },
  { key: 'balance', header: { ar: 'الرصيد المستحق', en: 'Balance' }, width: '100px', kind: 'num' },
  { key: 'use', header: { ar: 'الاستخدام', en: 'Usage' }, width: 'minmax(120px,1fr)' },
  { key: 'sos', header: { ar: 'أوامر', en: 'SOs' }, width: '60px', kind: 'num' },
  { key: 'price', header: { ar: 'قائمة الأسعار', en: 'Price list' }, width: '120px' },
  { key: 'act', header: '', width: '90px' },
];

function newQuote(code) { sel.value = null; emit('new-quote', code); }
function newSo(code) { sel.value = null; emit('new-so', code); }
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput v-model="q" small class="w-[260px]" :placeholder="{ ar: 'بحث بالاسم / الرمز / المنطقة…', en: 'Search name / code / zone…' }" @update:model-value="page = 1" />
      <Tabs v-model="terms" :tabs="termTabs" variant="pill" class="!mb-0" @update:model-value="page = 1" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="1000" :row-key="(r) => r.code" :selected-key="sel" @page="page = $event" @row-click="(r) => (sel = r.code)">
        <template #cell-name="{ row }">
          <div><div class="cell-name">{{ custName(row) }}<span v-if="!row.active" class="text-[8.5px] text-bad"> · {{ t('موقوف', 'inactive') }}</span></div><div class="cell-sub">{{ row.code }}</div></div>
        </template>
        <template #cell-city="{ row }"><span class="sec text-[10px]">{{ [row.city, row.zone].filter(Boolean).join(' · ') || '—' }}</span></template>
        <template #cell-terms="{ row }"><span class="text-[9.5px] font-bold text-violet">{{ row.terms || CASH_TERMS }}</span></template>
        <template #cell-balance="{ row }"><span class="text-warn">{{ fmtMoney(row.balance) }}</span></template>
        <template #cell-use="{ row }">
          <div v-if="usage(row).lim > 0" class="row !gap-1.5"><div class="grow"><ProgressBar :pct="usage(row).pct" :color="usage(row).color" :height="7" /></div><span class="num text-[10px]" :style="{ color: usage(row).color }">{{ usage(row).pct }}%</span></div>
          <span v-else class="faint text-[9px]">{{ t('نقدي', 'Cash') }}</span>
        </template>
        <template #cell-sos="{ row }"><span class="muted">{{ row._count?.orders ?? '—' }}</span></template>
        <template #cell-price="{ row }"><span class="muted text-[9px]">{{ priceListLabel(row.priceList) }}</span></template>
        <template #cell-act="{ row }"><Btn v-if="auth.can('sales.manage')" tone="softPurple" size="sm" :label="{ ar: 'عرض سعر', en: 'Quote' }" @click.stop="emit('new-quote', row.code)" /></template>
      </DataTable>
    </div>

    <Drawer :open="!!sel" :width="520" :title="d ? custName(d) : sel" :sub="d ? `${d.code} · ${[d.city, d.zone].filter(Boolean).join(' · ')}` : null" @close="sel = null">
      <ErrorBanner :error="det.error.value" :closable="false" />
      <div v-if="!d && !det.error.value" class="skel h-[120px]" />
      <template v-if="d && du">
        <div class="grid grid-cols-3 gap-2">
          <div class="tile amber"><div class="tile-v">{{ fmtMoney(d.balance) }}</div><div class="tile-l">{{ t('الرصيد المستحق ر.س', 'Balance SAR') }}</div></div>
          <div class="tile"><div class="tile-v">{{ du.lim > 0 ? fmtMoney(d.creditLimit) : '—' }}</div><div class="tile-l">{{ t('الحد الائتماني', 'Credit limit') }}</div></div>
          <div class="tile green"><div class="tile-v">{{ d.availableCredit != null ? fmtMoney(d.availableCredit) : '—' }}</div><div class="tile-l">{{ t('المتاح', 'Available') }}</div></div>
        </div>
        <div v-if="du.lim > 0" class="mt-2.5"><ProgressBar :pct="du.pct" :color="du.color" :label="{ ar: 'استخدام الحد الائتماني', en: 'Credit usage' }" show-pct /></div>
        <div class="mt-3">
          <KV :k="{ ar: 'شروط الدفع', en: 'Terms' }"><span class="text-violet">{{ d.terms || CASH_TERMS }}</span></KV>
          <KV :k="{ ar: 'جهة الاتصال', en: 'Contact' }" :v="d.contact || '—'" />
          <KV :k="{ ar: 'العنوان', en: 'Address' }" :v="d.address || '—'" />
          <KV :k="{ ar: 'قائمة الأسعار', en: 'Price list' }" :v="priceListLabel(d.priceList)" />
          <KV :k="{ ar: 'أوامر مفتوحة', en: 'Open orders' }"><span class="num">{{ d.openSosCount ?? '—' }}</span></KV>
          <KV :k="{ ar: 'إجمالي الأوامر / العروض / المرتجعات', en: 'Orders / quotes / returns' }"><span class="num">{{ d._count?.orders ?? 0 }} / {{ d._count?.quotations ?? 0 }} / {{ d._count?.returns ?? 0 }}</span></KV>
          <KV :k="{ ar: 'الحالة', en: 'Status' }"><span :class="d.active ? 'text-ok' : 'text-bad'">{{ d.active ? t('نشط', 'Active') : t('موقوف', 'Inactive') }}</span></KV>
        </div>
        <div class="mt-3.5 text-[12px] font-extrabold">{{ t('آخر الأوامر', 'Recent orders') }}</div>
        <div class="col mt-1.5 !gap-1.5">
          <div v-if="(d.recentOrders || []).length === 0" class="empty !p-2.5">{{ t('لا أوامر', 'No orders') }}</div>
          <div v-for="o in d.recentOrders || []" :key="o.id" class="row wrap rounded-[11px] border border-line-2 px-3 py-2">
            <RouterLink :to="`/so/${encodeURIComponent(o.number)}`" class="cell-id min-w-[100px]">{{ o.number }}</RouterLink>
            <span class="cell-date">{{ fmtDateOnly(o.dueDate || o.date) }}</span>
            <span class="sec text-[10px]">{{ o.warehouse?.code || '' }}</span>
            <div class="grow" />
            <Chip :map="SO_LABELS" :k="o.status" small />
          </div>
        </div>
      </template>

      <template v-if="d" #footer>
        <div class="row wrap">
          <Btn v-if="auth.can('customer.manage')" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = d" />
          <Btn v-if="auth.can('sales.manage')" tone="softPurple" :label="{ ar: '+ عرض سعر', en: '+ Quotation' }" @click="newQuote(d.code)" />
          <Btn v-if="auth.can('so.reserve')" tone="soft" :label="{ ar: '+ أمر بيع', en: '+ Sales order' }" @click="newSo(d.code)" />
        </div>
      </template>
    </Drawer>

    <CustomerForm :open="!!editing" :customer="editing" @close="editing = null" @done="det.refetch()" />
  </div>
</template>
