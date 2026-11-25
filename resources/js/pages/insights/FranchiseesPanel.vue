<script setup>
// The franchisees of one brand (the `franchisees[]` of GET /insights/brands/{id}): search, status filter, sort, paging.
// Manager: add (POST brands/{id}/franchisees), edit (PATCH franchisees/{id}), move status (POST franchisees/{id}/status
// { to, reason? } — a reason is required to stop one; a stopped franchisee is kept, never removed). A franchisee Odoo
// owns (`ownedBySource`) is shown with its badge and without edit buttons (the server answers INSIGHTS_OWNED_BY_SOURCE).
// A row opens its purchases on the dashboard (figure=sales&customer=…).
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import FranchiseeForm from './FranchiseeForm.vue';
import { FR_MOVE, FR_STATUS, ODOO_CHIP, amount, fmtDay, nameOf, useInsightsNav } from './insights';

const props = defineProps({
  /** GET /insights/brands/{id} */
  owner: { type: Object, required: true },
  manager: { type: Boolean, default: false },
});

const nav = useInsightsNav();
const q = ref('');
const status = ref('');
const rows = computed(() => {
  const needle = q.value.trim().toLowerCase();
  return (props.owner.franchisees || []).filter((f) => (!status.value || f.status === status.value)
    && (!needle || `${f.customer?.code || ''} ${f.customer?.nameAr || ''} ${f.customer?.nameEn || ''} ${f.region || ''} ${f.city || ''}`.toLowerCase().includes(needle)));
});
const counts = computed(() => (props.owner.franchisees || []).reduce((m, f) => ({ ...m, [f.status]: (m[f.status] || 0) + 1 }), {}));
const statusTabs = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' }, badge: (props.owner.franchisees || []).length || null },
  ...Object.keys(FR_STATUS).map((k) => ({ k, label: { ar: FR_STATUS[k].ar, en: FR_STATUS[k].en }, badge: counts.value[k] || null }))]);
const canAdd = computed(() => props.manager && props.owner.state !== 'archived');

const columns = computed(() => [
  { key: 'customer', header: { ar: 'الفرع (العميل)', en: 'Franchisee (customer)' }, width: 'minmax(200px,1.6fr)', sortable: true, sortValue: (r) => nameOf(r.customer) },
  { key: 'place', header: { ar: 'المنطقة / المدينة', en: 'Region / city' }, width: 'minmax(120px,0.9fr)', sortable: true, sortValue: (r) => `${r.region || ''} ${r.city || ''}` },
  { key: 'dateStart', header: { ar: 'البداية', en: 'Start' }, width: '88px', kind: 'date', sortable: true, value: (r) => fmtDay(r.dateStart) },
  { key: 'monthlyTarget', header: { ar: 'الهدف الشهري', en: 'Monthly target' }, width: '100px', kind: 'num', sortable: true, sortValue: (r) => Number(r.monthlyTarget) || 0, value: (r) => amount(r.monthlyTarget) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '120px', bare: true, sortable: true },
  { key: 'actions', header: '', width: '220px', bare: true, hidden: !props.manager },
]);

const act = useAction();
async function move(f, to) {
  const label = FR_MOVE[to] || { ar: to, en: to };
  const who = `${nameOf(f.customer)} (${f.customer?.code || ''})`;
  let reason = null;
  if (to === 'stopped') {
    reason = await ask({ title: { ar: `إيقاف الفرع ${who}`, en: `Stop the franchisee ${who}` }, sub: { ar: 'الفرع المتوقف لا يُعدَّل ولا يُحسب في الهدف بعد ذلك.', en: 'A stopped franchisee can no longer be changed and leaves the target.' }, label: { ar: 'سبب الإيقاف', en: 'Reason' }, required: true, tone: 'danger', okLabel: label });
    if (reason === null) return;
  } else if (!(await confirm({ title: { ar: `${label.ar}: ${who}؟`, en: `${label.en}: ${who}?` }, tone: 'dark' }))) return;
  await act.run(() => api.postIdempotent(`/insights/franchisees/${encodeURIComponent(f.id)}/status`, reason ? { to, reason } : { to }), { success: t('تغيّرت حالة الفرع', 'Franchisee status changed'), invalidate: ['insights'] });
}
const creating = ref(false);
const editing = ref(null);
const openLines = (f) => f.customer && nav.patch({ sec: null, fig: 'sales', figCustomer: f.customer.id, figMonth: null, figProduct: null, figKind: null, figSource: null });
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput v-model="q" small type="search" class="w-[230px]" :placeholder="{ ar: 'بحث بالعميل، الرمز، المدينة…', en: 'Search customer, code, city…' }" />
    <Tabs v-model="status" :tabs="statusTabs" variant="pill" class="!mb-0" />
    <div class="grow" />
    <Btn v-if="canAdd" tone="primary" size="sm" :label="{ ar: '+ فرع', en: '+ Franchisee' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :page-size="25" :row-key="(r) => r.id"
               :empty-text="(owner.franchisees || []).length ? { ar: 'لا فرع يطابق البحث.', en: 'No franchisee matches the search.' } : { ar: 'لا فروع لهذه العلامة بعد.', en: 'This brand has no franchisee yet.' }"
               @row-click="openLines">
      <template #cell-customer="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ nameOf(row.customer) }}</div>
          <div class="cell-sub"><span class="num ltr inline-block">{{ row.customer?.code }}</span><template v-if="row.notes"> · <span dir="auto">{{ row.notes }}</span></template></div>
        </div>
      </template>
      <template #cell-place="{ row }"><span class="truncate text-[10px] text-sec" dir="auto">{{ [row.region, row.city].filter(Boolean).join(' / ') || '—' }}</span></template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="FR_STATUS" :k="row.status" />
          <Chip v-if="row.ownedBySource" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" />
        </span>
      </template>
      <template #cell-actions="{ row }">
        <span v-if="row.ownedBySource" class="text-[9.5px] text-violet">{{ t('يُعدَّل في أودو', 'Changed in Odoo') }}</span>
        <span v-else class="inline-flex flex-wrap justify-center gap-1" @click.stop>
          <Btn v-if="row.status !== 'stopped'" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = row" />
          <Btn v-for="to in row.next || []" :key="to" size="sm" :tone="FR_MOVE[to]?.tone || 'soft'" :loading="act.pending.value" :label="FR_MOVE[to] || to" @click="move(row, to)" />
        </span>
      </template>
    </DataTable>
  </div>
  <div class="mt-1.5 text-[9px] text-faint">{{ t('النشط والضعيف يُتوقع منهما الشراء ويدخلان في الهدف والتنبيهات.', 'Active and weak franchisees are expected to buy and count in the target and the alerts.') }} · {{ t('الإجمالي', 'Total') }} <span class="num">{{ fmtNum((owner.franchisees || []).length) }}</span></div>

  <FranchiseeForm :open="creating || !!editing" :owner-id="owner.id" :franchisee="editing" @close="creating = false; editing = null" />
</template>
