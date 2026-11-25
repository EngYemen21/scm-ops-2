<script setup>
// Pay structures: GET /payroll/structures → { items: [{ id, code, nameAr, nameEn, gosiScheme, typeLabel, country, isDefault,
// active, notes, employees, ruleCount, source, locked }] }; one of them (`structure=<id>` in the query string) opens with
// Odoo's salary rules, read-only: GET /payroll/structures/{id} → … + rules [{ sequence, code, name, categoryCode, condition,
// conditionText, formula, quantity, appearsOnPayslip, active, component }]. The engine computes pay itself; the rules are
// shown so a person can compare. «+ هيكل» POST /payroll/structures · «تعديل» PATCH /payroll/structures/{id} — on a
// structure from Odoo the GOSI scheme, the default choice and the notes are OPS's.
import { computed, ref } from 'vue';
import { api, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, FormDrawer, KV } from '@/components';
import { t } from '@/i18n';
import { DEFAULT_CHIP, FROM_ODOO, INACTIVE_CHIP, YES_NO, bool, pname, usePayrollNav, yesNo } from './payroll';

const props = defineProps({
  canEdit: { type: Boolean, default: false },
});

const nav = usePayrollNav();
const q = useGet('/payroll/structures');
const gosi = useGet('/payroll/gosi-rates', undefined, { staleTime: 60_000, enabled: () => props.canEdit });
const schemeOptions = computed(() => (gosi.data.value?.schemes || []).map((s) => [s.scheme, s.scheme]));
const rows = computed(() => q.data.value?.items || []);
const columns = [
  { key: 'name', header: { ar: 'الهيكل', en: 'Structure' }, width: 'minmax(180px,1.6fr)', sortable: true, sortValue: (r) => pname(r) },
  { key: 'gosiScheme', header: { ar: 'نظام التأمينات', en: 'GOSI scheme' }, width: '110px', kind: 'num', value: (r) => r.gosiScheme || t('بلا تأمينات', 'none') },
  { key: 'employees', header: { ar: 'موظفون', en: 'Employees' }, width: '74px', kind: 'num', sortable: true },
  { key: 'ruleCount', header: { ar: 'قواعد أودو', en: 'Odoo rules' }, width: '80px', kind: 'num' },
  { key: 'state', header: { ar: '', en: '' }, width: '150px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '76px', bare: true },
];

// ---- detail (read-only rules)
const openId = computed(() => nav.get('structure') || null);
const detail = useGet(() => (openId.value ? `/payroll/structures/${encodeURIComponent(openId.value)}` : null));
const s = computed(() => (openId.value ? detail.data.value || null : null));
const ruleColumns = [
  { key: 'sequence', header: { ar: '#', en: '#' }, width: '52px', kind: 'num' },
  { key: 'name', header: { ar: 'القاعدة', en: 'Rule' }, width: 'minmax(160px,1.3fr)' },
  { key: 'categoryCode', header: { ar: 'الفئة', en: 'Category' }, width: '86px', kind: 'muted' },
  { key: 'formula', header: { ar: 'الشرط والصيغة', en: 'Condition & formula' }, width: 'minmax(200px,2fr)' },
  { key: 'appearsOnPayslip', header: { ar: 'في القسيمة', en: 'On payslip' }, width: '76px', value: (r) => yesNo(r.appearsOnPayslip) },
];

// ---- form
const editing = ref(null);
const isNew = computed(() => !!editing.value && !editing.value.id);
const locked = computed(() => !!editing.value?.locked);
const fields = computed(() => [
  ...(isNew.value ? [{ k: 'code', label: { ar: 'الرمز', en: 'Code' }, required: true, dir: 'ltr', validate: (v) => (/^[A-Za-z0-9_.-]{2,40}$/.test(v || '') ? null : t('حروف لاتينية وأرقام (2–40)', 'Latin letters and digits (2–40)')) }] : []),
  { k: 'nameAr', label: { ar: 'الاسم بالعربية', en: 'Name (Arabic)' }, required: true, disabled: () => locked.value },
  { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (English)' }, dir: 'ltr', disabled: () => locked.value },
  { k: 'gosiScheme', label: { ar: 'نظام التأمينات الاجتماعية', en: 'Social-insurance scheme' }, type: 'select', opts: schemeOptions.value, ph: { ar: '— بلا تأمينات —', en: '— none —' }, hint: { ar: 'نسبه وتواريخها في «نسب التأمينات»', en: 'Its dated rates are in “GOSI rates”' } },
  { k: 'isDefault', label: { ar: 'الهيكل الافتراضي', en: 'Default structure' }, type: 'select', opts: YES_NO, disabled: () => !!editing.value?.isDefault },
  ...(!isNew.value ? [{ k: 'active', label: { ar: 'مفعَّل', en: 'Active' }, type: 'select', opts: YES_NO, disabled: () => locked.value }] : []),
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area', full: true },
]);
const initial = computed(() => {
  const r = editing.value;
  return r && r.id ? { nameAr: r.nameAr, nameEn: r.nameEn, gosiScheme: r.gosiScheme || '', isDefault: r.isDefault ? '1' : '0', active: r.active ? '1' : '0', notes: r.notes || '' } : { isDefault: '0' };
});
function submit(v) {
  const r = editing.value;
  const own = { gosiScheme: v.gosiScheme || null, notes: v.notes || null, ...(bool(v.isDefault) && !r.isDefault ? { isDefault: true } : {}) };
  if (isNew.value) return api.postIdempotent('/payroll/structures', { code: String(v.code).toUpperCase(), nameAr: v.nameAr, nameEn: v.nameEn || null, ...own });
  const body = locked.value ? own : { ...own, nameAr: v.nameAr, nameEn: v.nameEn || null, active: bool(v.active) };
  return api.patch(`/payroll/structures/${encodeURIComponent(r.id)}`, body);
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <span class="text-[10.5px] text-muted">{{ t('الهيكل يحدد نظام التأمينات لمن يُصرف عليه؛ مبالغ الراتب من العقد.', 'A structure sets the social-insurance scheme of who is paid under it; the amounts come from the contract.') }}</span>
    <span class="grow" />
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ هيكل راتب', en: '+ Pay structure' }" @click="editing = {}" />
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :loading="q.isLoading.value" :page-size="25" :row-key="(r) => r.id" :selected-key="openId" :empty-text="{ ar: 'لا هياكل رواتب.', en: 'No pay structure.' }" @row-click="(r) => nav.open('structure', r.id)">
      <template #cell-name="{ row }"><div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ pname(row) }}</div><div class="cell-sub">{{ row.code }}<template v-if="row.typeLabel"> · {{ row.typeLabel }}</template></div></div></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="row.isDefault" small :label="DEFAULT_CHIP" :fg="DEFAULT_CHIP.fg" :bg="DEFAULT_CHIP.bg" />
          <Chip v-if="!row.active" small :label="INACTIVE_CHIP" :fg="INACTIVE_CHIP.fg" :bg="INACTIVE_CHIP.bg" />
          <Chip v-if="row.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template #cell-act="{ row }"><Btn v-if="canEdit" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="editing = row" /></template>
    </DataTable>
  </div>

  <Drawer :open="!!openId" :width="820" @close="nav.close('structure')">
    <template #title><span dir="auto">{{ s ? pname(s) : t('هيكل راتب', 'Pay structure') }}</span></template>
    <template #sub><span v-if="s" class="num ltr inline-block">{{ s.code }}</span></template>
    <ErrorBanner :error="detail.error.value" :closable="false" />
    <div v-if="detail.isLoading.value" class="skel h-[200px]" />
    <template v-if="s">
      <div class="kv-grid">
        <KV :k="{ ar: 'نظام التأمينات', en: 'GOSI scheme' }" :v="s.gosiScheme || t('بلا تأمينات', 'none')" ltr />
        <KV :k="{ ar: 'موظفون عليه', en: 'Employees on it' }" :v="s.employees" ltr />
        <KV :k="{ ar: 'افتراضي', en: 'Default' }" :v="yesNo(s.isDefault)" />
        <KV :k="{ ar: 'مفعَّل', en: 'Active' }" :v="yesNo(s.active)" />
        <KV v-if="s.country" :k="{ ar: 'الدولة', en: 'Country' }" :v="s.country" />
        <KV v-if="s.notes" :k="{ ar: 'ملاحظات', en: 'Notes' }"><span dir="auto">{{ s.notes }}</span></KV>
      </div>
      <div class="hint">{{ t('قواعد الراتب أدناه كما هي في أودو — للاطلاع والمقارنة فقط. محرّك الرواتب هنا يحسب من العقد والكشوف والإجازات والتسويات بقواعده المكتوبة، ويشرح كل سطر في القسيمة.', 'The salary rules below are as Odoo holds them — to read and compare only. The payroll engine here computes from the contract, sheets, leaves and adjustments with its own written rules, and explains every payslip line.') }}</div>
      <div class="card mt-3">
        <DataTable :columns="ruleColumns" :rows="s.rules" :page-size="50" :row-key="(r) => r.id" :empty-text="{ ar: 'لا قواعد من أودو لهذا الهيكل.', en: 'No Odoo rule for this structure.' }">
          <template #cell-name="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto" :class="{ 'line-through opacity-60': !row.active }">{{ row.name }}</div><div class="cell-sub">{{ row.code }}<template v-if="row.component"> · {{ pname(row.component) }}</template></div></div></template>
          <template #cell-formula="{ row }">
            <div class="min-w-0 text-[10px] leading-[1.6]">
              <div v-if="row.conditionText || row.condition" class="truncate text-muted" :title="row.conditionText || row.condition"><b>{{ t('شرط', 'if') }}:</b> <span class="ltr">{{ row.conditionText || row.condition }}</span></div>
              <div class="truncate" :title="row.formula || ''"><span class="num ltr">{{ row.formula || '—' }}</span><template v-if="row.quantity"> × <span class="num ltr">{{ row.quantity }}</span></template></div>
            </div>
          </template>
        </DataTable>
      </div>
    </template>
  </Drawer>

  <FormDrawer :open="!!editing" :title="isNew ? { ar: 'هيكل راتب جديد', en: 'New pay structure' } : { ar: `الهيكل ${editing?.code || ''}`, en: `Structure ${editing?.code || ''}` }"
              :sub="locked ? { ar: 'من أودو: نظام التأمينات والافتراضي والملاحظات فقط هنا', en: 'From Odoo: only the GOSI scheme, the default choice and notes here' } : null"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: t('حُفظ الهيكل', 'Structure saved'), invalidate: ['payroll'] }" @close="editing = null" />
</template>
