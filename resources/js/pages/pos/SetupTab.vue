<script setup>
// «الإعداد» — what the counter is made of: registers, payment methods, cash denominations. Everyone who sees the page
// reads it; adding and changing needs pos.manage (the buttons are hidden without it; the server enforces it).
// Nothing is deleted — a register, a method or a denomination is switched off. While the section follows Odoo, what came
// from Odoo (`owned`, `sourceSystem`) is read-only here.         /pos?view=setup&set=methods
//   GET /pos/registers?page&q&active&warehouse&source · GET /pos/payment-methods · GET /pos/denominations
//   POST | PATCH /pos/registers · /pos/payment-methods · /pos/denominations
import { computed, ref } from 'vue';
import { api, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, Tabs, TextInput } from '@/components';
import { fmtMoney, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import MethodForm from './MethodForm.vue';
import RegisterForm from './RegisterForm.vue';
import { ACTIVE_CHIP, DENOM_KIND, INACTIVE_CHIP, METHOD_KIND, NOT_READY, OWNED_CHIP, OWNED_HELP, RECEIPT_FORMAT, labelOf, nameOf, pct, usePosLookups, usePosNav, useSearchParam } from './pos';

const auth = useAuth();
const nav = usePosNav();
const lk = usePosLookups();
const canManage = computed(() => auth.can('pos.manage'));
const SETS = ['registers', 'methods', 'denominations'];
const set = computed(() => (SETS.includes(nav.get('set')) ? nav.get('set') : 'registers'));
const { text, type, apply } = useSearchParam(nav);
const ACTIVE = [{ v: '1', l: { ar: 'النشطة', en: 'Active' } }, { v: '0', l: { ar: 'الموقوفة', en: 'Inactive' } }];
const SOURCES = [{ v: 'ops', l: { ar: 'من هنا', en: 'Made here' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];

// ---------------------------------------------------------------- registers
const regs = useList(() => (set.value === 'registers' ? '/pos/registers' : null), () => ({
  q: nav.get('q') || undefined, active: nav.get('active') || undefined, source: nav.get('source') || undefined, page: nav.page.value, pageSize: 25,
}));
const regCols = computed(() => [
  { key: 'code', header: { ar: 'الصندوق', en: 'Register' }, width: 'minmax(170px,1.4fr)' },
  { key: 'warehouse', header: { ar: 'المستودع', en: 'Warehouse' }, width: '120px', value: (r) => (r.warehouse ? r.warehouse.code : '—') },
  { key: 'customer', header: { ar: 'العميل النقدي', en: 'Walk-in' }, width: '110px', value: (r) => (r.customer ? r.customer.code : '—') },
  { key: 'methods', header: { ar: 'طرق الدفع', en: 'Methods' }, width: 'minmax(130px,1fr)' },
  { key: 'maxDiscountPct', header: { ar: 'أقصى خصم', en: 'Max disc.' }, width: '76px', kind: 'num', value: (r) => pct(r.maxDiscountPct) },
  { key: 'receiptFormat', header: { ar: 'الإيصال', en: 'Receipt' }, width: '96px', value: (r) => labelOf(RECEIPT_FORMAT, r.receiptFormat) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '150px', bare: true },
  { key: 'act', header: '', width: '80px', bare: true, hidden: !canManage.value },
]);

// ---------------------------------------------------------------- payment methods
const methods = useGet(() => (set.value === 'methods' ? '/pos/payment-methods' : null));
const methodKind = ref('');
const methodRows = computed(() => {
  const all = Array.isArray(methods.data.value) ? methods.data.value : [];
  const s = String(text.value || '').trim().toLowerCase();
  const act = nav.get('active');
  return all.filter((m) => (!methodKind.value || m.kind === methodKind.value) && (!act || (act === '1') === !!m.active)
    && (!s || [m.code, m.nameAr, m.nameEn].some((x) => String(x || '').toLowerCase().includes(s))));
});
const KIND_OPTS = Object.entries(METHOD_KIND).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
const methodCols = computed(() => [
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '64px', kind: 'num', sortable: true },
  { key: 'name', header: { ar: 'الطريقة', en: 'Method' }, width: 'minmax(170px,1.4fr)', sortable: true, sortValue: (m) => nameOf(m) },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '110px', bare: true, sortable: true },
  { key: 'bank', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(140px,1fr)', value: (m) => (m.bankAccount ? `${m.bankAccount.code} · ${nameOf(m.bankAccount)}` : m.kind === 'account' ? t('ذمة العميل', 'Customer receivable') : '—') },
  { key: 'requiresReference', header: { ar: 'مرجع', en: 'Reference' }, width: '70px', value: (m) => (m.requiresReference ? t('مطلوب', 'Required') : '—') },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '130px', bare: true },
  { key: 'act', header: '', width: '80px', bare: true, hidden: !canManage.value },
]);

// ---------------------------------------------------------------- denominations
const denoms = useGet(() => (set.value === 'denominations' ? '/pos/denominations' : null));
const denomRows = computed(() => {
  const all = Array.isArray(denoms.data.value) ? denoms.data.value : [];
  const act = nav.get('active');
  return all.filter((d) => !act || (act === '1') === !!d.active);
});
const denomCols = computed(() => [
  { key: 'value', header: { ar: 'القيمة', en: 'Value' }, width: '110px', kind: 'num', sortable: true, sortValue: (d) => num(d.value), value: (d) => fmtMoney(num(d.value)) },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '130px', value: (d) => labelOf(DENOM_KIND, d.kind) },
  { key: 'label', header: { ar: 'التسمية', en: 'Label' }, width: 'minmax(140px,1fr)' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '110px', bare: true },
  { key: 'act', header: '', width: '80px', bare: true, hidden: !canManage.value },
]);
const YES_NO = [['yes', { ar: 'نعم', en: 'Yes' }], ['no', { ar: 'لا', en: 'No' }]];
const denomForm = ref(null); // null closed · {} new · a denomination being changed
const denomEditing = computed(() => (denomForm.value && denomForm.value.id ? denomForm.value : null));
const denomFields = computed(() => [
  ...(denomEditing.value ? [] : [{ k: 'value', label: { ar: 'القيمة (ر.س)', en: 'Value (SAR)' }, type: 'num', required: true, min: 0.01, validate: (v) => (num(v) > 0 ? null : t('أكبر من صفر', 'Above zero')) }]),
  { k: 'kind', label: { ar: 'النوع', en: 'Kind' }, type: 'select', required: true, opts: Object.entries(DENOM_KIND), def: 'note' },
  { k: 'label', label: { ar: 'التسمية', en: 'Label' }, ph: { ar: 'مثال: 500 ريال', en: 'e.g. 500 riyals' } },
  ...(denomEditing.value ? [{ k: 'active', label: { ar: 'مستخدمة؟', en: 'In use?' }, type: 'select', opts: YES_NO }] : []),
]);
const denomInitial = computed(() => (denomEditing.value ? { kind: denomEditing.value.kind, label: denomEditing.value.label || '', active: denomEditing.value.active ? 'yes' : 'no' } : null));
function denomSubmit(v) {
  if (denomEditing.value) {
    const body = { kind: v.kind, active: v.active !== 'no' };
    if (v.label) body.label = v.label;
    return api.patch(`/pos/denominations/${encodeURIComponent(denomEditing.value.id)}`, body);
  }
  return api.postIdempotent('/pos/denominations', { value: v.value, kind: v.kind, label: v.label || undefined });
}

// ---------------------------------------------------------------- forms
const regForm = ref(null); // null closed · {} new · a register
const methodForm = ref(null);
const tabs = computed(() => [
  { k: 'registers', label: { ar: 'الصناديق', en: 'Registers' }, badge: regs.data.value?.total ?? null },
  { k: 'methods', label: { ar: 'طرق الدفع', en: 'Payment methods' }, badge: lk.methods.value.length || null },
  { k: 'denominations', label: { ar: 'فئات النقد', en: 'Denominations' }, badge: lk.denominations.value.length || null },
]);
const newLabel = computed(() => ({ registers: { ar: '+ صندوق', en: '+ Register' }, methods: { ar: '+ طريقة دفع', en: '+ Method' }, denominations: { ar: '+ فئة', en: '+ Denomination' } }[set.value]));
function create() { if (set.value === 'registers') regForm.value = {}; else if (set.value === 'methods') methodForm.value = {}; else denomForm.value = {}; }
const ownedBySource = (x) => !!x.owned || (lk.follows.value && x.sourceSystem === 'odoo');
</script>

<template>
  <div class="row wrap mb-2">
    <Tabs :model-value="set" :tabs="tabs" variant="sm" class="!mb-0" @update:model-value="(k) => nav.go('setup', k === 'registers' ? {} : { set: k })" />
    <div class="grow" />
    <Btn v-if="canManage" tone="primary" size="sm" :label="newLabel" @click="create" />
  </div>
  <div v-if="!canManage" class="hint !mt-0 mb-2">{{ t('عرض للقراءة فقط — الإضافة والتعديل لمدير نقطة البيع (pos.manage).', 'Read-only view — adding and changing is for a point-of-sale manager (pos.manage).') }}</div>
  <div v-if="lk.follows.value" class="hint purple !mt-0 mb-2">{{ t(OWNED_HELP.ar, OWNED_HELP.en) }}</div>

  <div class="row wrap mb-2.5">
    <TextInput v-if="set !== 'denominations'" small type="search" class="w-[220px]" :model-value="text" :placeholder="{ ar: 'بحث بالرمز أو الاسم…', en: 'Search code or name…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('active')" :options="ACTIVE" :placeholder="{ ar: 'الكل', en: 'All' }" @update:model-value="(v) => nav.setFilter('active', v)" />
    <SelectInput v-if="set === 'methods'" v-model="methodKind" small class="w-[140px]" :options="KIND_OPTS" :placeholder="{ ar: 'كل الأنواع', en: 'Every kind' }" />
    <SelectInput v-if="set === 'registers' && lk.follows.value" small class="w-[130px]" :model-value="nav.get('source')" :options="SOURCES" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
  </div>

  <!-- registers -->
  <template v-if="set === 'registers'">
    <ErrorBanner :error="regs.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="regCols" :paged="regs.data.value" :loading="regs.isFetching.value" :row-key="(r) => r.id" :empty-text="{ ar: 'لا صناديق بعد.', en: 'No register yet.' }" @page="nav.setPage">
        <template #cell-code="{ row }">
          <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div><div class="cell-sub">{{ row.code }}</div></div>
        </template>
        <template #cell-methods="{ row }">
          <span class="truncate text-[10px] text-sec" :title="(row.methods || []).map((m) => m.code).join(', ')">{{ (row.methods || []).filter((m) => m.active !== false).map((m) => m.code).join(' · ') || '—' }}</span>
        </template>
        <template #cell-state="{ row }">
          <span class="inline-flex flex-wrap items-center justify-center gap-1">
            <Chip small :label="row.active ? ACTIVE_CHIP : INACTIVE_CHIP" :fg="(row.active ? ACTIVE_CHIP : INACTIVE_CHIP).fg" :bg="(row.active ? ACTIVE_CHIP : INACTIVE_CHIP).bg" />
            <Chip v-if="row.openSession" small :label="{ ar: `جلسة ${row.openSession.number}`, en: `Session ${row.openSession.number}` }" fg="#1d7a3e" bg="#e6f9ec" :title="row.openSession.cashierName" />
            <Chip v-for="k in row.notReady || []" :key="k" small :label="NOT_READY[k] || { ar: k, en: k }" fg="#b23b3b" bg="#fdecec" />
            <Chip v-if="ownedBySource(row)" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
          </span>
        </template>
        <template #cell-act="{ row }">
          <Btn v-if="!ownedBySource(row)" tone="soft" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="regForm = row" />
          <span v-else class="text-[9.5px] text-faint">{{ t('يُعدَّل في أودو', 'Changed in Odoo') }}</span>
        </template>
      </DataTable>
    </div>
  </template>

  <!-- payment methods -->
  <template v-else-if="set === 'methods'">
    <ErrorBanner :error="methods.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="methodCols" :rows="methodRows" :loading="methods.isLoading.value" :page-size="25" :row-key="(m) => m.id" :empty-text="{ ar: 'لا طرق دفع.', en: 'No payment method.' }">
        <template #cell-name="{ row }">
          <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div><div class="cell-sub">{{ row.code }}</div></div>
        </template>
        <template #cell-kind="{ row }"><Chip small :map="METHOD_KIND" :k="row.kind" /></template>
        <template #cell-state="{ row }">
          <span class="inline-flex flex-wrap items-center justify-center gap-1">
            <Chip small :label="row.active ? ACTIVE_CHIP : INACTIVE_CHIP" :fg="(row.active ? ACTIVE_CHIP : INACTIVE_CHIP).fg" :bg="(row.active ? ACTIVE_CHIP : INACTIVE_CHIP).bg" />
            <Chip v-if="row.kind !== 'account' && !row.bankAccount" small :label="{ ar: 'بلا حساب', en: 'No account' }" fg="#b23b3b" bg="#fdecec" />
            <Chip v-if="ownedBySource(row)" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
          </span>
        </template>
        <template #cell-act="{ row }">
          <Btn v-if="!ownedBySource(row)" tone="soft" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="methodForm = row" />
          <span v-else class="text-[9.5px] text-faint">{{ t('يُعدَّل في أودو', 'Changed in Odoo') }}</span>
        </template>
      </DataTable>
    </div>
  </template>

  <!-- denominations -->
  <template v-else>
    <ErrorBanner :error="denoms.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="denomCols" :rows="denomRows" :loading="denoms.isLoading.value" :page-size="25" :row-key="(d) => d.id" :empty-text="{ ar: 'لا فئات نقدية — تُعدّ النقود حينها بمبلغ واحد.', en: 'No denominations — cash is then entered as one amount.' }">
        <template #cell-state="{ row }"><Chip small :label="row.active ? ACTIVE_CHIP : INACTIVE_CHIP" :fg="(row.active ? ACTIVE_CHIP : INACTIVE_CHIP).fg" :bg="(row.active ? ACTIVE_CHIP : INACTIVE_CHIP).bg" /></template>
        <template #cell-act="{ row }"><Btn tone="soft" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="denomForm = row" /></template>
      </DataTable>
    </div>
  </template>

  <RegisterForm :open="!!regForm" :register="regForm && regForm.id ? regForm : null" @close="regForm = null" />
  <MethodForm :open="!!methodForm" :method="methodForm && methodForm.id ? methodForm : null" @close="methodForm = null" />
  <FormDrawer :open="!!denomForm" :title="denomEditing ? { ar: `تعديل الفئة ${fmtMoney(num(denomEditing.value))}`, en: `Change ${fmtMoney(num(denomEditing.value))}` } : { ar: 'فئة نقدية جديدة', en: 'New denomination' }"
              :fields="denomFields" :initial="denomInitial" :submit="denomSubmit" :action="{ success: t('حُفظت الفئة', 'Denomination saved'), invalidate: ['pos'] }" @close="denomForm = null" />
</template>
