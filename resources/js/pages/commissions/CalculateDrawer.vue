<script setup>
// Calculating a month (commission.manage): POST /commissions/calculate { period, beneficiary? }.
// It rebuilds the DRAFT statement of every earner of the month from the finance invoices and writes only what differs —
// running it twice changes nothing; statements already submitted, approved or paid are passed by. The answer says, per
// statement, what happened (new · updated · unchanged · closed · withdrawn) and how many invoices of the month are nobody's sale.
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, SelectInput } from '@/components';
import { t } from '@/i18n';
import { API, OUTCOME, money, periodLabel, personName, sar, useCommissions } from './commissions';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);

const { nav, lookups, userOptions, periods } = useCommissions();
const act = useAction({ invalidate: ['commissions'] });
const period = ref('');
const beneficiary = ref('');
const result = ref(null);
watch(() => props.open, (open) => { if (open) { period.value = nav.get('from') && nav.get('from') === nav.get('to') ? nav.get('from') : lookups.value.currentPeriod; beneficiary.value = ''; result.value = null; act.clearError(); } });

async function run() {
  const body = { period: period.value, ...(beneficiary.value ? { beneficiary: beneficiary.value } : {}) };
  const r = await act.run(() => api.postIdempotent(`${API}/calculate`, body), { success: (x) => t(`احتُسب ${periodLabel(x.period)}`, `${periodLabel(x.period)} calculated`) });
  if (r) result.value = r;
}
const changed = computed(() => (result.value ? result.value.counts.created + result.value.counts.updated + (result.value.counts.removed || 0) : 0));
function show(statement) { emit('close'); nav.openStatement(statement.number); }
function toAssignments() { emit('close'); nav.setTab('assignments', { period: result.value.period }); }
</script>

<template>
  <Drawer :open="open" :width="560" :title="{ ar: 'احتساب عمولات شهر', en: 'Calculate a month’s commissions' }" :sub="{ ar: 'من فواتير المالية وتحصيلاتها، بحسب الخطط الفعّالة', en: 'From the finance invoices and collections, by the active plans' }" @close="emit('close')">
    <div class="form-grid">
      <SelectInput v-model="period" required :label="{ ar: 'الشهر', en: 'Month' }" :options="periods" />
      <SelectInput v-model="beneficiary" :label="{ ar: 'المستحق له', en: 'Earner' }" :options="userOptions" :placeholder="{ ar: 'كل المستحقين', en: 'Every earner' }" />
    </div>
    <div class="hint">{{ t('تُعاد كتابة المسودات فقط، وبما اختلف فقط — احتساب الشهر مرتين لا يغيّر شيئًا. الكشوف المقدَّمة والمعتمدة والمصروفة لا تُمسّ: ما يستجد عليها يدخل كشف الشهر المفتوح.', 'Only drafts are rewritten, and only where something differs — calculating a month twice changes nothing. Submitted, approved and paid statements are not touched: what changes about them enters the open month’s statement.') }}</div>

    <template v-if="result">
      <div class="mt-3 mb-1.5 flex flex-wrap items-center gap-2">
        <span class="text-[12px] font-extrabold">{{ periodLabel(result.period) }}</span>
        <Chip v-for="(n, k) in result.counts" v-show="n > 0" :key="k" :map="OUTCOME" :k="k" small>{{ t(OUTCOME[k].ar, OUTCOME[k].en) }} <span class="num ms-1">{{ n }}</span></Chip>
        <span v-if="!result.statements.length" class="text-[10.5px] text-muted">{{ t('لا مستحقات في هذا الشهر: لا فواتير تخص من تشملهم الخطط الفعّالة.', 'Nothing earned this month: no invoice belongs to the people the active plans cover.') }}</span>
      </div>
      <div v-if="result.statements.length" class="overflow-hidden rounded-xl border border-line-2">
        <button v-for="x in result.statements" :key="x.number" type="button" :disabled="x.outcome === 'removed'" :title="x.outcome === 'removed' ? t('مسودة لم يعد لها ما يبررها فسُحبت', 'A draft nothing justified any more: it was withdrawn') : null"
                class="flex w-full flex-wrap items-center gap-x-2 gap-y-1 border-0 border-t border-line-2 bg-white px-3 py-2 text-start first:border-t-0" :class="x.outcome === 'removed' ? 'cursor-default opacity-70' : 'cursor-pointer hover:bg-soft'" @click="show(x)">
          <span class="min-w-0 flex-1 basis-[150px] truncate text-[11px] font-bold" dir="auto">{{ personName(x.beneficiary) }}</span>
          <span class="num ltr text-[9.5px] text-muted">{{ x.number }}</span>
          <Chip :map="OUTCOME" :k="x.outcome" small />
          <span class="num ltr w-[92px] text-end text-[11px] font-bold">{{ money(x.total) }}</span>
        </button>
      </div>
      <div v-if="result.unassigned.count > 0" class="hint amber">
        <b class="num">{{ result.unassigned.count }}</b> {{ t('فاتورة في هذا الشهر بلا مندوب معروف', 'invoices of this month have no known salesperson') }}
        (<span class="num ltr">{{ money(result.unassigned.net) }}</span> {{ sar() }} {{ t('قبل الضريبة', 'before tax') }}) —
        {{ t('لا تدخل كشف أي مندوب حتى يُسنَد عملاؤها.', 'they enter no salesperson’s statement until their customers are assigned.') }}
        <button type="button" class="cursor-pointer border-0 bg-transparent p-0 font-extrabold text-violet underline" @click="toAssignments">{{ t('إسناد المبيعات', 'Assign them') }}</button>
      </div>
      <div v-else-if="changed === 0 && result.statements.length" class="hint green">{{ t('لا تغيير: الكشوف مطابقة للفواتير كما هي الآن.', 'Nothing changed: the statements match the invoices as they stand now.') }}</div>
    </template>

    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!period" :label="result ? { ar: 'احتساب مرة أخرى', en: 'Calculate again' } : { ar: 'احتساب', en: 'Calculate' }" @click="run" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إغلاق', en: 'Close' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
