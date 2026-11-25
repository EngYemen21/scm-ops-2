<script setup>
// The budget scenarios of a run: money per month, the weights of the priority, the allocation and what it funded.
//   GET  /planning/runs/{id}/budgets → { items: [budget] }       POST /planning/runs/{id}/budgets { name, monthlyAmount? }
// A scenario opens in BudgetDrawer (`budget=<id>`).
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, NumberInput, ProgressBar, TextInput } from '@/components';
import { fmtDate, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { BUDGET_STATUS, OWNED_CHIP, money, pct, usePlanningNav } from './planning';

const props = defineProps({ run: { type: Object, required: true } });

const auth = useAuth();
const nav = usePlanningNav();
const q = useGet(() => `/planning/runs/${props.run.id}/budgets`);
const items = computed(() => q.data.value?.items || []);
const canAdd = computed(() => auth.can('forecast.manage') && !props.run.owned);

const adding = ref(false);
const name = ref('');
const monthly = ref(null);
const act = useAction({ invalidate: ['planning'] });
async function add() {
  if (!name.value.trim()) return;
  const body = { name: name.value.trim(), ...(monthly.value != null && monthly.value !== '' ? { monthlyAmount: Number(monthly.value) } : {}) };
  const r = await act.run(() => api.postIdempotent(`/planning/runs/${props.run.id}/budgets`, body), { success: t('أُضيف السيناريو', 'Scenario added') });
  if (r) { adding.value = false; name.value = ''; monthly.value = null; nav.patch({ budget: r.id }); }
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <div class="hint !m-0 flex-1">{{ t('كل سيناريو يوزّع ميزانية شهرية على احتياجات الشراء حسب أوزان الأولوية؛ منه تُصنع خطة الشراء.', 'Each scenario spreads a monthly budget over the purchase requirements by priority weights; a purchase plan is made from it.') }}</div>
    <Btn v-if="canAdd && !adding" size="sm" tone="primary" :label="{ ar: '+ سيناريو ميزانية', en: '+ Budget scenario' }" @click="adding = true" />
  </div>
  <div v-if="adding" class="card mb-3 !p-3">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div class="form-grid">
      <TextInput v-model="name" :label="{ ar: 'اسم السيناريو', en: 'Scenario name' }" required autofocus @enter="add" />
      <NumberInput v-model="monthly" :min="0" :label="{ ar: 'المبلغ الشهري (ر.س)', en: 'Monthly amount (SAR)' }" :hint="{ ar: 'فارغ = ميزانية التشغيل الشهرية', en: 'Empty = the run’s monthly budget' }" />
    </div>
    <div class="row mt-2 !justify-end">
      <Btn size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="adding = false" />
      <Btn size="sm" tone="primary" :loading="act.pending.value" :disabled="!name.trim()" :label="{ ar: 'إضافة', en: 'Add' }" @click="add" />
    </div>
  </div>

  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value" class="skel h-[120px]" />
  <div v-else-if="!items.length" class="empty">{{ t('لا سيناريوهات ميزانية بعد — أضف واحدًا، أو حدّد ميزانية شهرية في معطيات التشغيل.', 'No budget scenario yet — add one, or set a monthly budget in the run parameters.') }}</div>
  <div v-else class="grid gap-2 md:grid-cols-2">
    <button v-for="b in items" :key="b.id" type="button" class="card !p-3 text-start" :class="{ '!border-violet': nav.get('budget') === b.id }" @click="nav.patch({ budget: b.id })">
      <div class="row mb-1.5 !gap-1.5">
        <span class="truncate text-[12px] font-extrabold" dir="auto">{{ b.name }}</span>
        <span v-if="b.isPrimary" class="text-[9.5px] font-bold text-violet">· {{ t('ميزانية التشغيل', 'Run budget') }}</span>
        <div class="grow" />
        <Chip small :map="BUDGET_STATUS" :k="b.status" />
        <Chip v-if="b.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </div>
      <div class="grid grid-cols-3 gap-1.5 text-[10px]">
        <div><div class="text-muted">{{ t('الميزانية', 'Budget') }}</div><div class="num font-extrabold">{{ money(b.budgetTotal) }}</div></div>
        <div><div class="text-muted">{{ t('المطلوب', 'Required') }}</div><div class="num font-extrabold">{{ money(b.required) }}</div></div>
        <div><div class="text-muted">{{ t('المموّل', 'Funded') }}</div><div class="num font-extrabold text-ok">{{ money(b.funded) }}</div></div>
      </div>
      <ProgressBar class="mt-2" :pct="num(b.fundedPct)" :height="6" />
      <div class="mt-1 text-[9.5px] text-muted">
        {{ t('مُموّل', 'Funded') }} <span class="num">{{ pct(b.fundedPct) }}</span> · {{ t('مؤجّل', 'Deferred') }} <span class="num">{{ money(b.deferred) }}</span>
        <template v-if="b.allocatedAt"> · {{ t('وُزّع', 'Allocated') }} <span class="num">{{ fmtDate(b.allocatedAt) }}</span></template>
      </div>
    </button>
  </div>
</template>
