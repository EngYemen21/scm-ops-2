<script setup>
// «قائمة كل عميل» — every customer with the list it is priced with, and how OPS knows it:
//   /pricing?tab=customers&assigned=no            customers on the default list
//   /pricing?tab=customers&of=PRL-2026-00012      the customers of one list        &basis=order   read from Odoo orders
// GET /pricing/customers → { items, total, …, defaultList, follows }. Giving a customer a list, moving it or taking it
// off needs pricelist.manage (POST /pricing/customers/{code}/assign | unassign). A list that Odoo's own field names is
// Odoo's while the section follows Odoo; one that was only read from the customer's orders may be corrected here.
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, Tabs, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { BASIS, BASIS_HELP, VIA, nameOf, usePricingLookups, usePricingNav, useSearchParam } from './pricing';

const auth = useAuth();
const nav = usePricingNav();
const { page } = nav;
const lk = usePricingLookups();
const { follows, listOptions, allListOptions } = lk;
const { text, type, apply } = useSearchParam(nav);
const canManage = computed(() => auth.can('pricelist.manage'));

const ASSIGNED = [{ k: '', label: { ar: 'كل العملاء', en: 'Every customer' } }, { k: 'yes', label: { ar: 'لهم قائمة خاصة', en: 'With a list of their own' } }, { k: 'no', label: { ar: 'على الافتراضية', en: 'On the default list' } }];
const BASES = Object.entries(BASIS).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
const SORTS = ['code', 'nameAr', 'city'];
const assigned = computed(() => (['yes', 'no'].includes(nav.get('assigned')) ? nav.get('assigned') : ''));
const sort = computed(() => nav.sortOf(SORTS));
const list = useList('/pricing/customers', () => ({
  q: nav.get('q'), list: nav.get('of') || undefined, assigned: assigned.value || undefined, basis: nav.get('basis') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const defaultList = computed(() => list.data.value?.defaultList || null);
const filtered = computed(() => !!nav.get('q') || !!nav.get('of') || !!assigned.value || !!nav.get('basis'));

const columns = computed(() => [
  { key: 'nameAr', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(200px,1.7fr)', sortable: true },
  { key: 'city', header: { ar: 'المدينة', en: 'City' }, width: 'minmax(90px,0.6fr)', sortable: true, value: (c) => c.city || '—' },
  { key: 'list', header: { ar: 'يُسعَّر بقائمة', en: 'Priced with' }, width: 'minmax(190px,1.4fr)' },
  { key: 'basis', header: { ar: 'كيف عُرفت', en: 'How it is known' }, width: '178px', bare: true },
  { key: 'actions', header: '', width: '150px', bare: true, hidden: !canManage.value },
]);

// ── give / move / take off
const act = useAction({ invalidate: ['pricing'] });
/** The customer whose list is being chosen. */
const giving = ref(null);
const giveFields = computed(() => [
  { k: 'list', label: { ar: 'قائمة الأسعار', en: 'Price list' }, type: 'select', required: true, opts: listOptions.value, full: true },
  { k: 'note', label: { ar: 'ملاحظة (اختياري)', en: 'Note (optional)' }, type: 'area', ph: { ar: 'مثال: عقد سنوي من 2027/01/01', en: 'e.g. yearly contract from 2027-01-01' } },
]);
const give = (v) => api.postIdempotent(`/pricing/customers/${encodeURIComponent(giving.value.code)}/assign`, { list: v.list, note: v.note || null });
async function takeOff(c) {
  if (!(await confirm({ title: { ar: `إخراج ${nameOf(c)} من قائمته؟`, en: `Take ${nameOf(c)} off its list?` },
    sub: defaultList.value ? { ar: `يعود إلى القائمة الافتراضية «${defaultList.value.name}».`, en: `It goes back to the default list “${defaultList.value.name}”.` } : { ar: 'لا قائمة افتراضية: سيُسعَّر بالسعر المعلن للمنتجات.', en: 'There is no default list: it will be priced at the products\' list prices.' },
    tone: 'danger' }))) return;
  await act.run(() => api.postIdempotent(`/pricing/customers/${encodeURIComponent(c.code)}/unassign`), { success: { ar: 'عاد العميل إلى القائمة الافتراضية', en: 'The customer is back on the default list' } });
}
/** Is the customer's list Odoo's right now (`locked` of the row)? Then it is changed in Odoo, and no button is offered here. */
const locked = (c) => !!c.locked;
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'بحث باسم العميل أو رمزه أو مدينته…', en: 'Search customer name, code or city…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="assigned" :tabs="ASSIGNED" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('assigned', k)" />
    <SelectInput small class="w-[220px]" :model-value="nav.get('of')" :options="allListOptions" :placeholder="{ ar: 'كل القوائم', en: 'Every list' }" @update:model-value="(v) => nav.setFilter('of', v)" />
    <SelectInput v-if="follows" small class="w-[190px]" :model-value="nav.get('basis')" :options="BASES" :placeholder="{ ar: 'أي طريقة معرفة', en: 'Known any way' }" @update:model-value="(v) => nav.setFilter('basis', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="nav.go('customers')" />
  </div>
  <ErrorBanner :error="list.error.value || act.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(c) => c.id" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا عملاء يطابقون البحث والفلاتر.', en: 'No customer matches the search and filters.' } : { ar: 'لا عملاء بعد.', en: 'No customers yet.' }"
               @sort="nav.setSort" @page="nav.setPage">
      <template #cell-nameAr="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
          <div class="cell-sub">{{ row.code }}<template v-if="row.sourceSystem === 'odoo'"> · Odoo</template><template v-if="!row.active"> · {{ t('موقوف', 'stopped') }}</template></div>
        </div>
      </template>
      <template #cell-list="{ row }">
        <div class="min-w-0">
          <button v-if="row.effective.list" type="button" class="max-w-full cursor-pointer truncate border-0 bg-transparent p-0 text-[10.5px] font-extrabold text-violet" dir="auto" @click.stop="nav.openList(row.effective.list.code)">{{ row.effective.list.name }}</button>
          <span v-else class="text-[10.5px] font-bold text-muted">{{ t('السعر المعلن للمنتج', "The product's list price") }}</span>
          <div class="mt-0.5"><Chip small :map="VIA" :k="row.effective.via" />
            <span v-if="row.assignment && row.effective.via !== 'assigned'" class="ms-1 text-[9px] font-bold text-warn" dir="auto">{{ t(`قائمته «${row.assignment.list?.name}» لا تُسعِّر الآن`, `its list “${row.assignment.list?.name}” does not price now`) }}</span>
          </div>
        </div>
      </template>
      <template #cell-basis="{ row }">
        <span v-if="row.assignment" :title="bi(BASIS_HELP[row.assignment.basis])"><Chip small :map="BASIS" :k="row.assignment.basis" /><span v-if="row.assignment.basisRef" class="num ltr ms-1 text-[9px] text-muted">{{ row.assignment.basisRef }}</span></span>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-actions="{ row }">
        <span v-if="locked(row)" class="text-[9px] text-muted" :title="bi(BASIS_HELP.partner)">{{ t('تُدار من أودو', 'Set in Odoo') }}</span>
        <span v-else class="inline-flex gap-1">
          <Btn size="sm" tone="soft" :label="row.assignment ? { ar: 'تغيير', en: 'Change' } : { ar: 'إسناد قائمة', en: 'Give a list' }" @click.stop="giving = row" />
          <Btn v-if="row.assignment" size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'إخراج', en: 'Take off' }" @click.stop="takeOff(row)" />
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">
    {{ follows
      ? t('القسم يتبع أودو: قائمة العميل تؤخذ من حقل «قائمة الأسعار» في بطاقته في أودو متى حملته النسخة، وإلى ذلك الحين تُقرأ من قائمة أسعار آخر طلب له هناك — وهذه يمكن تصحيحها هنا. عملاء النظام تُسند قوائمهم هنا دائمًا.',
        'The section follows Odoo: a customer\'s list is taken from the price list on its partner in Odoo once the copy carries it; until then it is read from the price list of its latest order there — which can be corrected here. Customers of OPS are always given their list here.')
      : t('لكل عميل قائمة واحدة على الأكثر، وكل تغيير يُسجَّل في سجل التدقيق على العميل.', 'A customer has one list at most, and every change is kept in the audit trail of the customer.') }}
  </div>

  <FormDrawer :open="!!giving" :title="giving ? { ar: `قائمة أسعار ${nameOf(giving)}`, en: `Price list of ${nameOf(giving)}` } : null" :sub="giving?.code" :fields="giveFields"
              :initial="giving ? { list: giving.assignment?.list?.code || '' } : null" :submit="give"
              :action="{ success: { ar: 'أُسندت القائمة ✓', en: 'List assigned ✓' }, invalidate: ['pricing'] }" @close="giving = null" />
</template>
