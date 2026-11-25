<script setup>
// The engine's parameters, each a dated history: GET /payroll/parameters → { items: [{ code, known, kind number|choice|text,
// nameAr, nameEn, options, current, history: [{ id, value, effectiveFrom, note, current, inUse, source }] }] }.
// A new value is a new dated row (POST /payroll/parameters { code, value, effectiveFrom, note? }); a row approved pay was
// computed with stays (RATE_IN_USE); one nobody used may be taken out (DELETE /payroll/parameters/{id}) — never the only one.
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, FormDrawer, SectionCard } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { CURRENT_CHIP, FROM_ODOO, IN_USE_CHIP, choiceText, fmtDay, pname, todayIso, usePayrollSummary } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const q = useGet('/payroll/parameters');
const summary = usePayrollSummary();
const following = computed(() => !!summary.data.value?.following);
const items = computed(() => q.data.value?.items || []);
const shown = (p, v) => (p.kind === 'choice' ? choiceText(v) : v ?? '—');

const act = useAction({ invalidate: ['payroll'] });
async function remove(p, h) {
  if (!(await confirm({ title: { ar: `حذف قيمة «${pname(p)}» السارية من ${fmtDay(h.effectiveFrom)}؟`, en: `Remove the value of “${pname(p)}” from ${fmtDay(h.effectiveFrom)}?` }, tone: 'danger', okLabel: { ar: 'حذف', en: 'Remove' } }))) return;
  await act.run(() => api.del(`/payroll/parameters/${encodeURIComponent(h.id)}`), { success: t('حُذفت القيمة', 'Value removed') });
}

const adding = ref(null);
const fields = computed(() => {
  const p = adding.value;
  if (!p) return [];
  return [
    p.kind === 'choice'
      ? { k: 'value', label: { ar: 'القيمة', en: 'Value' }, type: 'select', required: true, opts: (p.options || []).map((o) => [o, choiceText(o)]) }
      : { k: 'value', label: { ar: 'القيمة', en: 'Value' }, type: 'num', required: true, min: 0.01, max: 366, validate: (v) => (Number(v) > 0 && Number(v) <= 366 ? null : t('رقم أكبر من صفر حتى 366', 'A number above 0 up to 366')) },
    { k: 'effectiveFrom', label: { ar: 'يسري من', en: 'In force from' }, type: 'date', required: true },
    { k: 'note', label: { ar: 'ملاحظة', en: 'Note' }, type: 'area', full: true },
  ];
});
</script>

<template>
  <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
  <div v-if="q.isLoading.value" class="skel h-[160px]" />
  <div class="grid-eq">
    <SectionCard v-for="p in items" :key="p.code" :title="pname(p)" :sub="p.code">
      <template v-if="canEdit && p.known" #actions><Btn size="sm" tone="softPurple" :label="{ ar: '+ قيمة بتاريخ', en: '+ Dated value' }" @click="adding = p" /></template>
      <div class="mb-2 text-[11px]">{{ t('السارية الآن', 'In force now') }}: <b :class="p.kind === 'choice' ? '' : 'num'">{{ shown(p, p.current) }}</b></div>
      <div v-if="!p.known" class="hint amber !mt-0 mb-2">{{ t('معامل لا يقرؤه المحرّك (من مصدر قديم): يُعرض فقط.', 'A parameter the engine does not read (from an old source): shown only.') }}</div>
      <div v-if="!p.history.length" class="text-[10px] text-faint">{{ t('لا قيم.', 'No value.') }}</div>
      <div v-for="h in p.history" :key="h.id" class="flex flex-wrap items-center gap-2 border-b border-line-2 py-1.5 text-[10.5px] last:border-b-0">
        <span class="cell-date">{{ fmtDay(h.effectiveFrom) }}</span>
        <b :class="p.kind === 'choice' ? '' : 'num'">{{ shown(p, h.value) }}</b>
        <span class="min-w-0 flex-1 truncate text-muted" dir="auto" :title="h.note || ''">{{ h.note || '' }}</span>
        <Chip v-if="h.current" small :label="CURRENT_CHIP" :fg="CURRENT_CHIP.fg" :bg="CURRENT_CHIP.bg" />
        <Chip v-if="h.inUse" small :label="IN_USE_CHIP" :fg="IN_USE_CHIP.fg" :bg="IN_USE_CHIP.bg" />
        <Chip v-if="h.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        <Btn v-if="canEdit && !h.inUse && !(h.source === 'odoo' && following) && p.history.length > 1" size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'حذف', en: 'Remove' }" @click="remove(p, h)" />
      </div>
    </SectionCard>
  </div>
  <div class="hint">{{ t('أيام الشهر: يقسم عليها الأجر الشهري لأجر اليوم. أساس خصم الإجازة غير المدفوعة وأساس أجر الساعة للحضور: الإجمالي أو الأساسي. القيمة الجديدة تسري من شهر لم يُعتمد راتبه بعد.', 'Month days: the monthly pay is divided by it for the daily pay. The bases of the unpaid-leave deduction and of the hourly pay for attendance: gross or basic. A new value applies from a month whose pay is not approved yet.') }}</div>

  <FormDrawer :open="!!adding" :title="adding ? { ar: `قيمة جديدة: ${adding.nameAr}`, en: `New value: ${adding.nameEn}` } : null" :fields="fields" :initial="{ effectiveFrom: todayIso() }"
              :submit="(v) => api.postIdempotent('/payroll/parameters', { code: adding.code, value: adding.kind === 'number' ? Number(v.value) : v.value, effectiveFrom: v.effectiveFrom, note: v.note || null })"
              :action="{ success: t('أُضيفت القيمة بتاريخها', 'Dated value added'), invalidate: ['payroll'] }" @close="adding = null" />
</template>
