<script setup>
// Social-insurance (GOSI) rates — configuration with dates: GET /payroll/gosi-rates → { schemes: [{ scheme, structures,
// rates: [{ id, scheme, effectiveFrom, employeePct, employerPct, baseMin, baseMax, note, createdBy, current, inUse }] }] }.
// A change is a NEW dated row (POST /payroll/gosi-rates); a row approved pay was computed under (`inUse`) keeps its figures
// (only its note may be completed: PATCH …/{id}) and is never removed; a row nobody was paid under may be corrected or taken
// out (DELETE …/{id}). No row may start behind an approved month (RATE_RETROACTIVE).
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SectionCard } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { CURRENT_CHIP, IN_USE_CHIP, fmtDay, money, todayIso } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const q = useGet('/payroll/gosi-rates');
const schemes = computed(() => q.data.value?.schemes || []);
const columns = [
  { key: 'effectiveFrom', header: { ar: 'يسري من', en: 'From' }, width: '100px', kind: 'date', value: (r) => fmtDay(r.effectiveFrom) },
  { key: 'employeePct', header: { ar: 'حصة الموظف %', en: 'Employee %' }, width: '96px', kind: 'num' },
  { key: 'employerPct', header: { ar: 'حصة المنشأة %', en: 'Employer %' }, width: '96px', kind: 'num' },
  { key: 'base', header: { ar: 'حدود الوعاء', en: 'Base floor – ceiling' }, width: '150px', kind: 'num', value: (r) => `${money(r.baseMin)} – ${r.baseMax == null ? '∞' : money(r.baseMax)}` },
  { key: 'note', header: { ar: 'ملاحظة', en: 'Note' }, width: 'minmax(120px,1fr)' },
  { key: 'state', header: { ar: '', en: '' }, width: '170px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '120px', bare: true },
];

const act = useAction({ invalidate: ['payroll'] });
async function remove(r) {
  if (!(await confirm({ title: { ar: `حذف نسبة ${r.scheme} السارية من ${fmtDay(r.effectiveFrom)}؟`, en: `Remove the ${r.scheme} rate from ${fmtDay(r.effectiveFrom)}?` }, sub: { ar: 'لم يُحسب بها راتب معتمد — حذفها تصحيح لخطأ إدخال', en: 'No approved pay used it — removing it corrects a typing slip' }, tone: 'danger', okLabel: { ar: 'حذف', en: 'Remove' } }))) return;
  await act.run(() => api.del(`/payroll/gosi-rates/${encodeURIComponent(r.id)}`), { success: t('حُذفت النسبة', 'Rate removed') });
}

/** null closed · { scheme } new · a row edit */
const editing = ref(null);
const isNew = computed(() => !!editing.value && !editing.value.id);
const inUse = computed(() => !!editing.value?.inUse);
const fields = computed(() => [
  ...(isNew.value ? [
    { k: 'scheme', label: { ar: 'النظام', en: 'Scheme' }, required: true, dir: 'ltr', ph: 'saudi', hint: { ar: 'حروف لاتينية صغيرة وأرقام، مثل saudi أو non_saudi', en: 'Lower-case letters and digits, e.g. saudi or non_saudi' }, validate: (v) => (/^[a-z0-9_-]{2,20}$/.test(String(v || '').trim().toLowerCase()) ? null : t('حروف لاتينية وأرقام (2–20)', 'Latin letters and digits (2–20)')) },
    { k: 'effectiveFrom', label: { ar: 'يسري من', en: 'In force from' }, type: 'date', required: true },
  ] : []),
  { k: 'employeePct', label: { ar: 'حصة الموظف %', en: 'Employee share %' }, type: 'num', required: true, min: 0, max: 100, step: 0.25, disabled: () => inUse.value },
  { k: 'employerPct', label: { ar: 'حصة المنشأة %', en: 'Employer share %' }, type: 'num', required: true, min: 0, max: 100, step: 0.25, disabled: () => inUse.value },
  { k: 'baseMin', label: { ar: 'الحد الأدنى للوعاء', en: 'Base floor' }, type: 'num', min: 0, disabled: () => inUse.value },
  { k: 'baseMax', label: { ar: 'الحد الأعلى للوعاء', en: 'Base ceiling' }, type: 'num', min: 0, hint: { ar: 'فارغ = بلا حد', en: 'Empty = no ceiling' }, disabled: () => inUse.value,
    validate: (v, all) => (v != null && v !== '' && all.baseMin != null && Number(v) < Number(all.baseMin) ? t('الأعلى لا يقل عن الأدنى', 'The ceiling cannot be below the floor') : null) },
  { k: 'note', label: { ar: 'ملاحظة', en: 'Note' }, type: 'area', full: true },
]);
const initial = computed(() => {
  const r = editing.value;
  if (!r) return {};
  return r.id ? { employeePct: r.employeePct, employerPct: r.employerPct, baseMin: r.baseMin == null ? null : Number(r.baseMin), baseMax: r.baseMax == null ? null : Number(r.baseMax), note: r.note || '' }
    : { scheme: r.scheme || '', effectiveFrom: todayIso(), baseMin: 0 };
});
function submit(v) {
  const r = editing.value;
  const figures = { employeePct: Number(v.employeePct), employerPct: Number(v.employerPct), baseMin: v.baseMin == null ? 0 : Number(v.baseMin), baseMax: v.baseMax == null ? null : Number(v.baseMax) };
  if (isNew.value) return api.postIdempotent('/payroll/gosi-rates', { scheme: String(v.scheme).trim().toLowerCase(), effectiveFrom: v.effectiveFrom, ...figures, note: v.note || null });
  return api.patch(`/payroll/gosi-rates/${encodeURIComponent(r.id)}`, r.inUse ? { note: v.note || null } : { ...figures, note: v.note || null });
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <span class="text-[10.5px] text-muted">{{ t('النسبة السارية في شهر الراتب هي آخر صف تاريخه لا يتجاوز نهاية الشهر.', 'The rate of a pay month is the latest row dated on or before the end of the month.') }}</span>
    <span class="grow" />
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ نظام / نسبة جديدة', en: '+ Scheme / new rate' }" @click="editing = { scheme: '' }" />
  </div>
  <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
  <div v-if="q.isLoading.value" class="skel h-[160px]" />
  <div v-else-if="!schemes.length && !q.error.value" class="card"><div class="empty">{{ t('لا نسب تأمينات بعد — بلا نسبة سارية يُستبعد من هيكله نظام تأمينات.', 'No GOSI rate yet — without one in force, people on a structure with a scheme are left out of the run.') }}</div></div>
  <SectionCard v-for="sc in schemes" :key="sc.scheme" class="mb-3" :padded="false" :title="sc.scheme" :sub="{ ar: `هياكل مفعَّلة تعمل به: ${sc.structures}`, en: `Active structures using it: ${sc.structures}` }">
    <template v-if="canEdit" #actions><Btn size="sm" tone="softPurple" :label="{ ar: '+ نسبة بتاريخ', en: '+ Dated rate' }" @click="editing = { scheme: sc.scheme }" /></template>
    <DataTable :columns="columns" :rows="sc.rates" :row-key="(r) => r.id" :empty-text="{ ar: 'لا صفوف.', en: 'No row.' }">
      <template #cell-note="{ row }"><span class="truncate text-[10px] text-muted" dir="auto" :title="row.note || ''">{{ row.note || '—' }}</span></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="row.current" small :label="CURRENT_CHIP" :fg="CURRENT_CHIP.fg" :bg="CURRENT_CHIP.bg" />
          <Chip v-if="row.inUse" small :label="IN_USE_CHIP" :fg="IN_USE_CHIP.fg" :bg="IN_USE_CHIP.bg" />
        </span>
      </template>
      <template #cell-act="{ row }">
        <span v-if="canEdit" class="inline-flex gap-1">
          <Btn size="sm" tone="ghost" :label="row.inUse ? { ar: 'ملاحظة', en: 'Note' } : { ar: 'تعديل', en: 'Edit' }" @click="editing = row" />
          <Btn v-if="!row.inUse" size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'حذف', en: 'Remove' }" @click="remove(row)" />
        </span>
      </template>
    </DataTable>
  </SectionCard>

  <FormDrawer :open="!!editing" :title="isNew ? { ar: 'نسبة تأمينات بتاريخ سريان', en: 'Dated GOSI rate' } : { ar: `نسبة ${editing?.scheme} من ${fmtDay(editing?.effectiveFrom)}`, en: `${editing?.scheme} rate from ${fmtDay(editing?.effectiveFrom)}` }"
              :sub="inUse ? { ar: 'حُسبت بها رواتب معتمدة: أرقامها تاريخ لا يتغيّر — الملاحظة فقط', en: 'Approved pay used it: its figures are history — the note only' } : null"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: t('حُفظت النسبة', 'Rate saved'), invalidate: ['payroll'] }" @close="editing = null" />
</template>
