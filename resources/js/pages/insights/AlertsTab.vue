<script setup>
// «التنبيهات» — the alerts of every brand (manager only): the list with its filters in the query string
// (q · owner · read · type · severity · customer · from · to · sort · order · page) and "compute now"
// (POST /insights/alerts/run → { owners, raised, byType }; the scheduled command does the same every day).
import { ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import AlertsList from './AlertsList.vue';
import { ALERT_TYPE, own } from './insights';

defineProps({
  me: { type: Object, required: true },
});

const act = useAction();
const lastRun = ref(null);
async function runAll() {
  const r = await act.run(() => api.postIdempotent('/insights/alerts/run', {}), {
    success: (x) => t(`حُسبت التنبيهات لـ ${x.owners} مالك: ${x.raised} جديد`, `Alerts computed for ${x.owners} owner(s): ${x.raised} new`), invalidate: ['insights'],
  });
  if (r) lastRun.value = r;
}
const typeName = (k) => bi(own(ALERT_TYPE, k) || { ar: k, en: k });
</script>

<template>
  <div class="row wrap mb-2">
    <div class="text-[10.5px] text-muted">{{ t('تُحسب التنبيهات يوميًا لكل مالك نشط له منتجات وفروع، ولا يُرفع التنبيه نفسه مرتين.', 'Alerts are computed daily for every active owner with products and franchisees; the same alert is never raised twice.') }}</div>
    <div class="grow" />
    <Btn v-if="me.manager" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'احسب التنبيهات الآن', en: 'Compute alerts now' }" @click="runAll" />
  </div>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="lastRun" class="hint green !mt-0 mb-2.5">
    {{ t('آخر حساب', 'Last run') }}: {{ t('الملاك', 'owners') }} <b class="num">{{ fmtNum(lastRun.owners) }}</b> · {{ t('جديد', 'new') }} <b class="num">{{ fmtNum(lastRun.raised) }}</b>
    <template v-for="(n, k) in lastRun.byType" :key="k"> · {{ typeName(k) }} <b class="num">{{ fmtNum(n) }}</b></template>
  </div>
  <AlertsList :manager="me.manager" query-state />
</template>
