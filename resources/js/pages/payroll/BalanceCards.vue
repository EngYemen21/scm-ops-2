<script setup>
// The leave balances of one person for one year: GET /payroll/leave-balances?employee=&year= → { employee, year,
// balances: [{ type, allocated, taken, adjusted, balance, pending, available }] }. Without `employee` it is the
// caller's own. A type that is not counted against days granted shows what was taken only.
//   <BalanceCards :employee="idOrNull" :year="2026" />
import { computed, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner, SelectInput } from '@/components';
import { t } from '@/i18n';
import { days, pname, typeColor, yearOptions } from './payroll';

const props = defineProps({
  employee: { type: String, default: null },
  year: { type: Number, default: null },
  /** Smaller tiles (the card of My corner). */
  compact: { type: Boolean, default: false },
  /** Hide the year picker (the parent has its own). */
  fixedYear: { type: Boolean, default: false },
});

const picked = ref(String(props.year || new Date().getFullYear()));
watch(() => props.year, (y) => { if (y) picked.value = String(y); });
const q = useGet('/payroll/leave-balances', () => ({ employee: props.employee || undefined, year: picked.value }));
const rows = computed(() => q.data.value?.balances || []);
</script>

<template>
  <div>
    <div v-if="!fixedYear" class="row mb-2">
      <span class="text-[10px] font-bold text-muted">{{ t('سنة الرصيد', 'Allowance year') }}</span>
      <SelectInput small class="w-[96px]" :model-value="picked" :options="yearOptions()" @update:model-value="(v) => (picked = v)" />
    </div>
    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-[90px]" />
    <div v-else-if="!rows.length && !q.error.value" class="empty !p-3">{{ t('لا أنواع إجازات مفعَّلة.', 'No active leave type.') }}</div>
    <div v-else class="grid gap-2" :class="compact ? 'grid-cols-2' : 'grid-cols-[repeat(auto-fill,minmax(170px,1fr))]'">
      <div v-for="b in rows" :key="b.type.id" class="rounded-[12px] border border-line-2 px-3 py-2" :style="{ borderInlineStartWidth: '4px', borderInlineStartColor: typeColor(b.type.color).fg }">
        <div class="truncate text-[10.5px] font-extrabold" dir="auto" :title="pname(b.type)">{{ pname(b.type) }}</div>
        <template v-if="b.type.requiresAllocation">
          <div class="mt-1 flex items-baseline gap-1">
            <span class="num text-[18px] font-bold" :class="b.available < 0 ? 'text-bad' : 'text-ink'">{{ days(b.available) }}</span>
            <span class="text-[9.5px] text-muted">{{ t('يوم متاح', 'day(s) available') }}</span>
          </div>
          <div class="mt-0.5 text-[9px] leading-[1.7] text-muted">
            {{ t('ممنوح', 'Granted') }} <span class="num">{{ days(b.allocated) }}</span> · {{ t('مأخوذ', 'Taken') }} <span class="num">{{ days(b.taken) }}</span>
            <template v-if="b.adjusted"> · {{ t('تسوية', 'Adjusted') }} <span class="num ltr inline-block">{{ days(b.adjusted) }}</span></template>
            <template v-if="b.pending"> · <span class="text-warn">{{ t('بانتظار القرار', 'Waiting') }} <span class="num">{{ days(b.pending) }}</span></span></template>
          </div>
        </template>
        <template v-else>
          <div class="mt-1 flex items-baseline gap-1"><span class="num text-[18px] font-bold">{{ days(b.taken) }}</span><span class="text-[9.5px] text-muted">{{ t('يوم مأخوذ', 'day(s) taken') }}</span></div>
          <div class="mt-0.5 text-[9px] text-muted">{{ t('لا يُحسب من رصيد ممنوح', 'Not counted against granted days') }}<template v-if="b.pending"> · <span class="text-warn">{{ t('بانتظار', 'waiting') }} <span class="num">{{ days(b.pending) }}</span></span></template></div>
        </template>
      </div>
    </div>
  </div>
</template>
