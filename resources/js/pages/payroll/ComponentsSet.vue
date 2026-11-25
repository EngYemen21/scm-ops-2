<script setup>
// Pay components: GET /payroll/components → { items: [{ id, code, nameAr, nameEn, category, calc, contractField, gosiBase,
// sequence, accountKey, accountCode, system, active, notes, input, source, locked }] }.
// «+ بند» POST /payroll/components — what a person adds is always a manual addition or deduction of a month (an adjustment);
// the engine's own components (basic, allowances from the contract, overtime, lateness, GOSI, claims) are fixed and only
// renamed, re-ordered, routed to an account or switched off here. «تعديل» PATCH /payroll/components/{id}.
import { computed, ref } from 'vue';
import { api, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { COMPONENT_CALC, COMPONENT_CATEGORY, FROM_ODOO, INACTIVE_CHIP, TONE, YES_NO, bool, optionsOf, own, pname } from './payroll';

const props = defineProps({
  canEdit: { type: Boolean, default: false },
});

const q = useGet('/payroll/components');
const accounts = useGet('/payroll/accounts', undefined, { staleTime: 60_000, enabled: () => props.canEdit });
const keyOptions = computed(() => (accounts.data.value?.items || []).map((a) => [a.key, pname(a)]));
const keyName = (k) => { const a = (accounts.data.value?.items || []).find((x) => x.key === k); return a ? pname(a) : k; };
const search = ref('');
const category = ref('');
const rows = computed(() => {
  const s = search.value.trim().toLowerCase();
  return (q.data.value?.items || []).filter((r) => (!category.value || r.category === category.value) && (!s || `${r.code} ${r.nameAr} ${r.nameEn}`.toLowerCase().includes(s)));
});
const SYSTEM_CHIP = { ar: 'يحسبه النظام', en: 'Engine', ...TONE.neutral };
const columns = [
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '62px', kind: 'num', sortable: true },
  { key: 'name', header: { ar: 'البند', en: 'Component' }, width: 'minmax(170px,1.5fr)', sortable: true, sortValue: (r) => pname(r) },
  { key: 'category', header: { ar: 'الفئة', en: 'Category' }, width: '116px', bare: true },
  { key: 'calc', header: { ar: 'يُحسب', en: 'Computed' }, width: 'minmax(120px,1fr)', value: (r) => bi(own(COMPONENT_CALC, r.calc) || { ar: r.calc, en: r.calc }) + (r.gosiBase ? t(' · في وعاء التأمينات', ' · in the GOSI base') : '') },
  { key: 'account', header: { ar: 'الحساب', en: 'Account' }, width: '130px', value: (r) => r.accountCode || (r.accountKey ? keyName(r.accountKey) : t('التوجيه الافتراضي', 'Default posting')) },
  { key: 'state', header: { ar: '', en: '' }, width: '140px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '76px', bare: true },
];

const editing = ref(null);
const isNew = computed(() => !!editing.value && !editing.value.id);
const fields = computed(() => {
  const c = editing.value || {};
  return [
    ...(isNew.value ? [
      { k: 'code', label: { ar: 'الرمز', en: 'Code' }, required: true, dir: 'ltr', ph: 'BONUS', validate: (v) => (/^[A-Za-z0-9_.-]{2,40}$/.test(v || '') ? null : t('حروف لاتينية وأرقام (2–40)', 'Latin letters and digits (2–40)')) },
      { k: 'category', label: { ar: 'إضافة أم خصم', en: 'Addition or deduction' }, type: 'select', required: true, opts: [['allowance', { ar: 'إضافة', en: 'Addition' }], ['deduction', { ar: 'خصم', en: 'Deduction' }]] },
    ] : []),
    { k: 'nameAr', label: { ar: 'الاسم بالعربية', en: 'Name (Arabic)' }, required: true },
    { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (English)' }, dir: 'ltr' },
    { k: 'sequence', label: { ar: 'الترتيب في القسيمة', en: 'Order on the payslip' }, type: 'num', min: 1, max: 899 },
    { k: 'accountKey', label: { ar: 'بند التوجيه المحاسبي', en: 'Posting key' }, type: 'select', opts: keyOptions.value, ph: { ar: '— بحسب الفئة —', en: '— by category —' } },
    { k: 'accountCode', label: { ar: 'حساب خاص (رمز الحساب)', en: 'Own account (code)' }, dir: 'ltr', hint: { ar: 'يتقدّم على بند التوجيه؛ فارغ = بحسب البند', en: 'Wins over the posting key; empty = by the key' } },
    ...(!isNew.value && c.calc === 'contract' ? [{ k: 'gosiBase', label: { ar: 'ضمن وعاء التأمينات', en: 'In the GOSI base' }, type: 'select', opts: YES_NO }] : []),
    ...(!isNew.value && c.code !== 'BASIC' ? [{ k: 'active', label: { ar: 'مفعَّل', en: 'Active' }, type: 'select', opts: YES_NO }] : []),
    { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area', full: true },
  ];
});
const initial = computed(() => {
  const c = editing.value;
  if (!c || !c.id) return { category: 'allowance' };
  return { nameAr: c.nameAr, nameEn: c.nameEn, sequence: c.sequence, accountKey: c.accountKey || '', accountCode: c.accountCode || '', gosiBase: c.gosiBase ? '1' : '0', active: c.active ? '1' : '0', notes: c.notes || '' };
});
function submit(v) {
  const c = editing.value;
  const body = { nameAr: v.nameAr, nameEn: v.nameEn || null, accountKey: v.accountKey || null, accountCode: v.accountCode ? String(v.accountCode).trim() : null, notes: v.notes || null, ...(v.sequence != null ? { sequence: Number(v.sequence) } : {}) };
  if (isNew.value) return api.postIdempotent('/payroll/components', { code: String(v.code).toUpperCase(), category: v.category, ...body });
  if (c.calc === 'contract' && v.gosiBase != null) body.gosiBase = bool(v.gosiBase);
  if (c.code !== 'BASIC' && v.active != null) body.active = bool(v.active);
  return api.patch(`/payroll/components/${encodeURIComponent(c.id)}`, body);
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالرمز أو الاسم…', en: 'Search code or name…' }" />
    <SelectInput v-model="category" small class="w-[150px]" :options="optionsOf(COMPONENT_CATEGORY).filter(([k]) => !['gross', 'net'].includes(k))" :placeholder="{ ar: 'كل الفئات', en: 'Every category' }" />
    <span class="grow" />
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ بند إضافة / خصم', en: '+ Addition / deduction' }" @click="editing = {}" />
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :loading="q.isLoading.value" :page-size="50" :row-key="(r) => r.id" :empty-text="{ ar: 'لا بنود.', en: 'No component.' }">
      <template #cell-name="{ row }"><div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ pname(row) }}</div><div class="cell-sub">{{ row.code }}</div></div></template>
      <template #cell-category="{ row }"><Chip small :map="COMPONENT_CATEGORY" :k="row.category" /></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="row.system" small :label="SYSTEM_CHIP" :fg="SYSTEM_CHIP.fg" :bg="SYSTEM_CHIP.bg" />
          <Chip v-if="!row.active" small :label="INACTIVE_CHIP" :fg="INACTIVE_CHIP.fg" :bg="INACTIVE_CHIP.bg" />
          <Chip v-if="row.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template #cell-act="{ row }"><Btn v-if="canEdit && !row.locked" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = row" /></template>
    </DataTable>
  </div>

  <FormDrawer :open="!!editing" :title="isNew ? { ar: 'بند إضافة أو خصم يدوي', en: 'Manual addition or deduction' } : { ar: `البند ${editing?.code || ''}`, en: `Component ${editing?.code || ''}` }"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: t('حُفظ البند', 'Component saved'), invalidate: ['payroll'] }" @close="editing = null" />
</template>
