<script setup>
// The eight alert rules of the brand dashboards (manager) — GET /insights/alert-rules → { items: [{ type, enabled,
// threshold, window, unit (days|months), severity, label, rule, updatedBy, updatedAt }] }; PUT /insights/alert-rules/{type}
// { enabled?, threshold?, window? }. `rule` is the sentence people read, with the configured numbers in it; it is
// rewritten by the server after a save. Months are 1–12; days at most 365.
import { computed, reactive } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, NumberInput, SectionCard } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { SEVERITY, fmtDay } from './insights';

const q = useGet('/insights/alert-rules');
const rules = computed(() => q.data.value?.items || []);
/** type → { enabled, threshold, window } being edited */
const edits = reactive({});
const draft = (r) => edits[r.type] || { enabled: r.enabled, threshold: r.threshold, window: r.window };
function set(r, k, v) { edits[r.type] = { ...draft(r), [k]: v }; }
function undo(r) { delete edits[r.type]; }
const dirty = (r) => { const e = edits[r.type]; return !!e && (e.enabled !== r.enabled || Number(e.threshold) !== Number(r.threshold) || Number(e.window) !== Number(r.window)); };
const windowError = (r) => {
  const w = Number(draft(r).window);
  if (!Number.isFinite(w)) return t('مطلوب', 'Required');
  if (r.unit === 'months' && (w < 1 || w > 12)) return t('عدد الأشهر بين 1 و12', 'Months are 1 to 12');
  if (r.unit === 'days' && (w < 0 || w > 365)) return t('الأيام بين 0 و365', 'Days are 0 to 365');
  return null;
};

const act = useAction();
async function save(r) {
  if (windowError(r)) return;
  const e = draft(r);
  const res = await act.run(() => api.put(`/insights/alert-rules/${encodeURIComponent(r.type)}`, { enabled: !!e.enabled, threshold: Number(e.threshold) || 0, window: Number(e.window) }), {
    success: t('حُفظت القاعدة', 'Rule saved'), invalidate: ['insights'],
  });
  if (res) delete edits[r.type];
}
const unitLabel = (u) => (u === 'months' ? t('شهر', 'months') : t('يوم', 'days'));
</script>

<template>
  <div class="mb-2 text-[10.5px] text-muted">{{ t('تُطبَّق القواعد عند الحساب اليومي على كل مالك نشط. تعطيل قاعدة يوقف رفع تنبيهات جديدة منها ولا يمس ما رُفع.', 'The rules apply at the daily run on every active owner. Disabling a rule stops new alerts of it; what was raised stays.') }}</div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="q.isLoading.value" class="skel h-[200px]" />
  <div v-else-if="!rules.length && !q.error.value" class="empty dashed">{{ t('لا قواعد.', 'No rule.') }}</div>
  <div v-else class="grid-2">
    <SectionCard v-for="r in rules" :key="r.type" :title="r.label" small>
      <template #actions><Chip small :map="SEVERITY" :k="r.severity" /></template>
      <div class="mb-2 text-[10.5px] leading-[1.8] text-sec" dir="auto">{{ bi(r.rule) }}</div>
      <div class="form-grid">
        <label class="field flex !flex-row items-center gap-2">
          <input type="checkbox" class="h-4 w-4" :checked="!!draft(r).enabled" @change="set(r, 'enabled', $event.target.checked)">
          <span class="field-l !mb-0">{{ draft(r).enabled ? t('مفعّلة', 'Enabled') : t('معطّلة', 'Disabled') }}</span>
        </label>
        <div />
        <NumberInput :model-value="draft(r).threshold" :label="{ ar: 'الحد', en: 'Threshold' }" :min="0" :max="100000" @update:model-value="(v) => set(r, 'threshold', v)" />
        <NumberInput :model-value="draft(r).window" :label="{ ar: `النافذة (${r.unit === 'months' ? 'أشهر' : 'أيام'})`, en: `Window (${r.unit})` }" :error="windowError(r)" :min="r.unit === 'months' ? 1 : 0" :max="r.unit === 'months' ? 12 : 365" :step="1" @update:model-value="(v) => set(r, 'window', v)" />
      </div>
      <div class="row wrap mt-2">
        <span class="text-[9px] text-faint">
          {{ t('الحالي', 'Current') }}: {{ r.enabled ? t('مفعّلة', 'enabled') : t('معطّلة', 'disabled') }} · {{ t('الحد', 'threshold') }} <span class="num">{{ fmtNum(r.threshold, Number.isInteger(r.threshold) ? 0 : 2) }}</span> · <span class="num">{{ r.window }}</span> {{ unitLabel(r.unit) }}
          <template v-if="r.updatedBy"> · {{ t('عدّلها', 'by') }} {{ r.updatedBy }} <span class="num ltr inline-block">{{ fmtDay(r.updatedAt) }}</span></template>
        </span>
        <div class="grow" />
        <Btn v-if="dirty(r)" size="sm" tone="ghost" :label="{ ar: 'تراجع', en: 'Undo' }" @click="undo(r)" />
        <Btn size="sm" tone="dark" :disabled="!dirty(r) || !!windowError(r)" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="save(r)" />
      </div>
    </SectionCard>
  </div>
</template>
