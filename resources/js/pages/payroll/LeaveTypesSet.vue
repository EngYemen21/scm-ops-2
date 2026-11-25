<script setup>
// Kinds of time off: GET /payroll/leave-types → { items: [{ id, code, nameAr, nameEn, paid, requiresAllocation, yearlyDays,
// approval none|manager|hr|both, requiresDocument, allowHalfDay, allowNegative, unit, color 0..11, sequence, active, notes, source, locked }] }.
// «+ نوع» POST /payroll/leave-types · «تعديل» PATCH /payroll/leave-types/{id}. On a type from Odoo (`locked`) the name,
// paid, allowance rule, approval, document, order, colour and active flag are Odoo's; the yearly allowance, half days,
// negative balance and the notes are OPS's own and stay editable.
import { computed, ref } from 'vue';
import { api, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { APPROVAL, FROM_ODOO, INACTIVE_CHIP, PALETTE_SIZE, YES_NO, bool, days, optionsOf, own, pname, typeColor, yesNo } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const q = useGet('/payroll/leave-types');
const search = ref('');
const rows = computed(() => {
  const s = search.value.trim().toLowerCase();
  return (q.data.value?.items || []).filter((r) => !s || `${r.code} ${r.nameAr} ${r.nameEn}`.toLowerCase().includes(s));
});
const columns = [
  { key: 'name', header: { ar: 'النوع', en: 'Type' }, width: 'minmax(170px,1.5fr)', sortable: true, sortValue: (r) => pname(r) },
  { key: 'paid', header: { ar: 'مدفوعة', en: 'Paid' }, width: '66px', value: (r) => yesNo(r.paid) },
  { key: 'yearlyDays', header: { ar: 'رصيد سنوي', en: 'Yearly days' }, width: '84px', kind: 'num', sortable: true, value: (r) => (r.requiresAllocation ? days(r.yearlyDays ?? 0) : '—') },
  { key: 'approval', header: { ar: 'الاعتماد', en: 'Approval' }, width: '140px', value: (r) => bi(own(APPROVAL, r.approval) || { ar: r.approval, en: r.approval }) },
  { key: 'rules', header: { ar: 'قواعد', en: 'Rules' }, width: 'minmax(150px,1.2fr)' },
  { key: 'state', header: { ar: '', en: '' }, width: '120px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '76px', bare: true },
];

const editing = ref(null);
const locked = computed(() => !!editing.value?.locked);
const isNew = computed(() => !!editing.value && !editing.value.id);
const fields = computed(() => {
  const own_ = () => locked.value;
  return [
    ...(isNew.value ? [{ k: 'code', label: { ar: 'الرمز', en: 'Code' }, required: true, dir: 'ltr', ph: 'ANNUAL', validate: (v) => (/^[A-Za-z0-9_.-]{2,30}$/.test(v || '') ? null : t('حروف لاتينية وأرقام (2–30)', 'Latin letters and digits (2–30)')) }] : []),
    { k: 'nameAr', label: { ar: 'الاسم بالعربية', en: 'Name (Arabic)' }, required: true, disabled: own_ },
    { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (English)' }, dir: 'ltr', disabled: own_ },
    { k: 'paid', label: { ar: 'مدفوعة الأجر', en: 'Paid' }, type: 'select', opts: YES_NO, disabled: own_ },
    { k: 'approval', label: { ar: 'من يعتمد', en: 'Approved by' }, type: 'select', opts: optionsOf(APPROVAL), disabled: own_ },
    { k: 'requiresAllocation', label: { ar: 'تُحسب من رصيد ممنوح', en: 'Counted against granted days' }, type: 'select', opts: YES_NO, disabled: own_ },
    { k: 'yearlyDays', label: { ar: 'الرصيد السنوي (يوم)', en: 'Yearly allowance (days)' }, type: 'num', min: 0, max: 366, step: 0.5, hint: { ar: 'يُمنح بزر «الرصيد السنوي»', en: 'Granted with “Yearly allowance”' } },
    { k: 'allowHalfDay', label: { ar: 'يقبل نصف يوم', en: 'Half days allowed' }, type: 'select', opts: YES_NO },
    { k: 'allowNegative', label: { ar: 'يسمح برصيد سالب', en: 'May go negative' }, type: 'select', opts: YES_NO },
    { k: 'requiresDocument', label: { ar: 'يحتاج مستندًا', en: 'Needs a paper' }, type: 'select', opts: YES_NO, disabled: own_ },
    { k: 'color', label: { ar: `اللون (0–${PALETTE_SIZE - 1})`, en: `Colour (0–${PALETTE_SIZE - 1})` }, type: 'num', min: 0, max: PALETTE_SIZE - 1, disabled: own_ },
    { k: 'sequence', label: { ar: 'الترتيب', en: 'Order' }, type: 'num', min: 0, max: 100000, disabled: own_ },
    { k: 'active', label: { ar: 'مفعَّل', en: 'Active' }, type: 'select', opts: YES_NO, disabled: own_ },
    { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area', full: true },
  ];
});
const yn = (b) => (b ? '1' : '0');
const initial = computed(() => {
  const r = editing.value;
  if (!r || !r.id) return { paid: '1', approval: 'manager', requiresAllocation: '0', allowHalfDay: '0', allowNegative: '0', requiresDocument: '0', active: '1', color: 0 };
  return { nameAr: r.nameAr, nameEn: r.nameEn, paid: yn(r.paid), approval: r.approval, requiresAllocation: yn(r.requiresAllocation), yearlyDays: r.yearlyDays, allowHalfDay: yn(r.allowHalfDay),
    allowNegative: yn(r.allowNegative), requiresDocument: yn(r.requiresDocument), color: r.color, sequence: r.sequence, active: yn(r.active), notes: r.notes || '' };
});
function submit(v) {
  const opsOwn = { yearlyDays: v.yearlyDays == null ? null : Number(v.yearlyDays), allowHalfDay: bool(v.allowHalfDay), allowNegative: bool(v.allowNegative), notes: v.notes || null };
  const body = locked.value ? opsOwn : {
    ...opsOwn, nameAr: v.nameAr, nameEn: v.nameEn || null, paid: bool(v.paid), approval: v.approval || 'manager', requiresAllocation: bool(v.requiresAllocation), requiresDocument: bool(v.requiresDocument),
    active: bool(v.active), ...(v.color != null ? { color: Number(v.color) } : {}), ...(v.sequence != null ? { sequence: Number(v.sequence) } : {}),
  };
  return isNew.value ? api.postIdempotent('/payroll/leave-types', { code: String(v.code).toUpperCase(), ...body }) : api.patch(`/payroll/leave-types/${encodeURIComponent(editing.value.id)}`, body);
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالرمز أو الاسم…', en: 'Search code or name…' }" />
    <span class="grow" />
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ نوع إجازة', en: '+ Leave type' }" @click="editing = {}" />
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :loading="q.isLoading.value" :page-size="25" :row-key="(r) => r.id" :empty-text="{ ar: 'لا أنواع إجازات.', en: 'No leave type.' }">
      <template #cell-name="{ row }"><span class="inline-flex min-w-0 items-center gap-2"><span class="h-3 w-3 shrink-0 rounded-full" :style="{ background: typeColor(row.color).fg }" /><span class="min-w-0"><span class="cell-name block truncate" dir="auto">{{ pname(row) }}</span><span class="cell-sub block">{{ row.code }}</span></span></span></template>
      <template #cell-rules="{ row }">
        <span class="truncate text-[10px] text-muted">{{ [row.requiresAllocation ? t('من رصيد', 'from a balance') : t('بلا رصيد', 'no balance'), row.allowHalfDay ? t('نصف يوم', 'half days') : '', row.allowNegative ? t('سالب مسموح', 'negative ok') : '', row.requiresDocument ? t('مستند', 'paper') : ''].filter(Boolean).join(' · ') }}</span>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="!row.active" small :label="INACTIVE_CHIP" :fg="INACTIVE_CHIP.fg" :bg="INACTIVE_CHIP.bg" />
          <Chip v-if="row.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template #cell-act="{ row }"><Btn v-if="canEdit" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = row" /></template>
    </DataTable>
  </div>

  <FormDrawer :open="!!editing" :title="isNew ? { ar: 'نوع إجازة جديد', en: 'New leave type' } : { ar: `نوع الإجازة ${editing?.code || ''}`, en: `Leave type ${editing?.code || ''}` }"
              :sub="locked ? { ar: 'من أودو: الرصيد السنوي ونصف اليوم والسالب والملاحظات فقط هنا', en: 'From Odoo: only the yearly allowance, half days, negative and notes here' } : null"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: t('حُفظ نوع الإجازة', 'Leave type saved'), invalidate: ['payroll'] }" @close="editing = null" />
</template>
