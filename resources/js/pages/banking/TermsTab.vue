<script setup>
// «شروط الدفع» — the payment terms the section keeps from Odoo (GET /banking/payment-terms), read-only: the name, its
// instalments (`schedule`: a share in percent or a fixed amount, after N days), the early-payment discount, the days to
// the last instalment and its note. Search, active, origin, sort and paging in the query string.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { OWNED_CHIP, useBankingNav, useSearchParam } from './banking';

const nav = useBankingNav();
const { text, type, apply } = useSearchParam(nav);
const ACTIVE = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: '1', label: { ar: 'الفعّالة', en: 'Active' } }, { k: '0', label: { ar: 'المؤرشفة', en: 'Archived' } }];
const active = computed(() => (['0', '1'].includes(nav.get('active')) ? nav.get('active') : ''));
const ORIGIN = [{ v: 'ops', l: { ar: 'أُنشئت هنا', en: 'Made in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const SORTS = ['name', 'days'];
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'desc' ? 'desc' : 'asc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const list = useList('/banking/payment-terms', () => ({
  active: active.value || undefined, origin: nav.get('origin') || undefined, q: nav.get('q') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['active', 'origin', 'q'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ active: null, origin: null, q: null, page: null });

const columns = [
  { key: 'name', header: { ar: 'الشرط', en: 'Term' }, width: 'minmax(170px,1.4fr)', sortable: true },
  { key: 'schedule', header: { ar: 'الأقساط', en: 'Instalments' }, width: 'minmax(180px,1.6fr)' },
  { key: 'days', header: { ar: 'أيام السداد', en: 'Days to pay' }, width: '90px', kind: 'num', sortable: true },
  { key: 'discount', header: { ar: 'خصم السداد المبكر', en: 'Early-payment discount' }, width: '150px' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '120px', bare: true },
];
/** One instalment: "30% بعد 30 يومًا" / "500 after 0 days"; the rest of the balance when Odoo says `balance`. */
function instalment(s) {
  const days = Number(s.days) || 0;
  const share = s.value === 'percent' ? `${fmtNum(s.amount, Number.isInteger(Number(s.amount)) ? 0 : 2)}%` : s.value === 'balance' ? t('الباقي', 'the balance') : fmtNum(s.amount, 2);
  const when = days === 0 ? t('فورًا', 'immediately') : t(`بعد ${days} يومًا`, `after ${days} days`);
  const end = s.delayType && s.delayType !== 'days_after' ? t(' (من نهاية الشهر)', ' (from month end)') : '';
  return `${share} ${when}${end}`;
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'بحث بالاسم أو الملاحظة…', en: 'Search name or note…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="active" :tabs="ACTIVE" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('active', k)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('origin')" :options="ORIGIN" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('origin', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <div class="hint !mt-0 mb-2.5">{{ t('قائمة للقراءة: شروط الدفع تُعرَّف في أودو وتُقرأ هنا.', 'Read-only: payment terms are defined in Odoo and read here.') }}</div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا شروط تطابق الفلاتر.', en: 'No term fits the filters.' } : { ar: 'لا شروط دفع بعد — تظهر بعد مزامنة أودو.', en: 'No payment term yet — they appear once Odoo is synchronised.' }"
               @sort="setSort" @page="nav.setPage">
      <template #cell-name="{ row }">
        <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ row.name }}</div><div v-if="row.note" class="truncate text-[9px] text-muted" dir="auto" :title="row.note">{{ row.note }}</div></div>
      </template>
      <template #cell-schedule="{ row }">
        <span v-if="row.schedule?.length" class="text-[10px] text-sec">{{ row.schedule.map(instalment).join(' + ') }}</span>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-days="{ row }"><span class="num">{{ row.dueDays ?? '—' }}</span></template>
      <template #cell-discount="{ row }">
        <span v-if="row.earlyDiscount" class="text-[10px] text-ok"><span class="num">{{ row.discountPercent }}%</span> {{ t(`خلال ${row.discountDays ?? 0} يومًا`, `within ${row.discountDays ?? 0} days`) }}</span>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip small :label="row.active ? { ar: 'فعّال', en: 'Active' } : { ar: 'مؤرشف', en: 'Archived' }" :fg="row.active ? '#1d7a3e' : '#55506a'" :bg="row.active ? '#e6f9ec' : '#F1EFF6'" />
          <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        </span>
      </template>
    </DataTable>
  </div>
</template>
