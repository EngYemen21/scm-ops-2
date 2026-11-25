<script setup>
// The payment methods a request may choose (GET /credit/lookups `paymentMethods`), in their order. credit.manage:
//   POST /credit/payment-methods { code, nameAr, nameEn?, sequence? } · PATCH /credit/payment-methods/{id}
//   { nameAr?, nameEn?, sequence?, active? }. Nothing is deleted: a method no longer offered is archived and stays on
//   the requests that chose it.
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, TextInput } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { nameOf, useCreditLookups } from './credit';
import { ORIGIN } from './labels';

const lk = useCreditLookups();
const act = useAction({ invalidate: ['credit'] });
const search = ref('');
const rows = computed(() => {
  const s = search.value.trim().toLowerCase();
  const all = [...lk.paymentMethods.value].sort((a, b) => (a.sequence - b.sequence) || String(a.id).localeCompare(String(b.id)));
  return s ? all.filter((x) => [x.code, x.nameAr, x.nameEn].some((n) => String(n || '').toLowerCase().includes(s))) : all;
});

const form = ref(null);
const editing = computed(() => (form.value && form.value.id ? form.value : null));
const fields = computed(() => [
  ...(editing.value ? [] : [{ k: 'code', label: { ar: 'الرمز', en: 'Code' }, required: true, dir: 'ltr' }]),
  { k: 'nameAr', label: { ar: 'الاسم بالعربية', en: 'Name (AR)' }, required: true },
  { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (EN)' }, dir: 'ltr' },
  { k: 'sequence', label: { ar: 'الترتيب', en: 'Order' }, type: 'num', min: 0, max: 9999, step: 1 },
]);
const initial = computed(() => (editing.value ? { nameAr: editing.value.nameAr, nameEn: editing.value.nameEn || '', sequence: editing.value.sequence } : null));
function submit(x) {
  if (editing.value) return api.patch(`/credit/payment-methods/${editing.value.id}`, { nameAr: x.nameAr, nameEn: x.nameEn ?? null, ...(x.sequence != null ? { sequence: Number(x.sequence) } : {}) });
  return api.postIdempotent('/credit/payment-methods', { code: x.code, nameAr: x.nameAr, ...(x.nameEn ? { nameEn: x.nameEn } : {}), ...(x.sequence != null ? { sequence: Number(x.sequence) } : {}) });
}
const action = { success: { ar: 'حُفظت طريقة الدفع', en: 'Payment method saved' }, invalidate: ['credit'] };

async function setActive(m, active) {
  if (!active && !(await confirm({ title: { ar: `أرشفة «${m.nameAr}»؟`, en: `Archive "${m.nameEn || m.nameAr}"?` }, sub: { ar: 'لا تُعرض للطلبات الجديدة وتبقى على الطلبات التي اختارتها', en: 'It is no longer offered to new requests and stays on those that chose it' }, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } }))) return;
  await act.run(() => api.patch(`/credit/payment-methods/${m.id}`, { active }), { success: active ? t('أُعيد تفعيلها', 'Reactivated') : t('أُرشفت', 'Archived') });
}

const columns = [
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '70px', kind: 'num' },
  { key: 'name', header: { ar: 'طريقة الدفع', en: 'Payment method' }, width: 'minmax(180px,1.5fr)' },
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '120px', kind: 'num' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '140px', bare: true },
  { key: 'act', header: '', width: '160px', bare: true },
];
const IN_USE = { ar: 'مستخدمة', en: 'In use', fg: '#1d7a3e', bg: '#e6f9ec' };
const ARCHIVED = { ar: 'مؤرشفة', en: 'Archived', fg: '#55506a', bg: '#F1EFF6' };
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالاسم أو الرمز…', en: 'Search name or code…' }" />
    <span class="grow" />
    <Btn size="sm" tone="primary" :label="{ ar: '+ طريقة دفع', en: '+ Payment method' }" @click="form = {}" />
  </div>
  <ErrorBanner :error="lk.query.error.value || act.error.value" :closable="!lk.query.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :page-size="25" :loading="lk.query.isLoading.value" :row-key="(x) => x.id"
               :empty-text="search.trim() ? { ar: 'لا نتائج مطابقة للبحث.', en: 'Nothing matches the search.' } : { ar: 'لا طرق دفع بعد.', en: 'No payment method yet.' }">
      <template #cell-name="{ row }"><span class="cell-name truncate" :class="{ 'opacity-50': !row.active }" dir="auto">{{ nameOf(row) }}</span></template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :label="row.active ? IN_USE : ARCHIVED" :fg="(row.active ? IN_USE : ARCHIVED).fg" :bg="(row.active ? IN_USE : ARCHIVED).bg" />
          <Chip v-if="row.origin === 'odoo'" small :label="ORIGIN.odoo" :fg="ORIGIN.odoo.fg" :bg="ORIGIN.odoo.bg" />
        </span>
      </template>
      <template #cell-act="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="form = row" />
          <Btn v-if="row.active" size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click.stop="setActive(row, false)" />
          <Btn v-else size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'تفعيل', en: 'Activate' }" @click.stop="setActive(row, true)" />
        </span>
      </template>
    </DataTable>
  </div>

  <FormDrawer :open="!!form" :title="editing ? { ar: `تعديل ${editing.nameAr}`, en: `Edit ${editing.nameEn || editing.nameAr}` } : { ar: 'طريقة دفع جديدة', en: 'New payment method' }"
              :fields="fields" :initial="initial" :submit="submit" :action="action" @close="form = null" />
</template>
