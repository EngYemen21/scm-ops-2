<script setup>
// Dashboard: KPI grid → exception centre (1.5fr) + inventory by warehouse / live feed (1fr).
// Every figure comes from GET /api/dashboard (refreshed every minute) — nothing is computed or simulated here.
//
// Response: { generatedAt, costVisible, expiringSoonDays,
//   kpis: [{ key, labelAr, labelEn, value, hint?, link? }],
//   actions: [{ kind, number, textAr, textEn, owner, path }],
//   inventoryByWarehouse: [{ warehouseId, code, nameAr, nameEn, onHand, reserved, available, skus, value?, sharePct }],
//   recentActivity: [{ id, textAr, textEn, username, at }],
//   pendingApprovals: { pos: Approval[], prs: Approval[] } }   Approval = { type: 'PO'|'PR', number, step, labelAr, labelEn, total, supplierAr, supplierEn, priority, warehouse, createdAt, path }
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Chip, EmptyState, ErrorBanner, KpiCard, KpiGrid, PageHead, ProgressBar, SectionCard } from '@/components';
import { fmtMoney, fmtNum, fmtTime, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import Ago from './Ago.vue';
import CardTitle from './CardTitle.vue';
import ExceptionActionModal from './ExceptionActionModal.vue';
import ExceptionActions from './ExceptionActions.vue';
import ListRow from './ListRow.vue';
import { useExceptionActions } from './shared';

/** KPI value colour by key. */
const KPI_COLOR = {
  availableValue: '#654e92', availableUnits: '#0d7f93', lowStock: '#b26a16', expiringSoon: '#b23b3b', expired: '#b23b3b', slaBreached: '#b23b3b', exceptionsCritical: '#b23b3b',
  exceptionsOpen: '#b26a16', deliveriesFailedToday: '#b23b3b', deliveriesCompletedToday: '#1d7a3e', vehiclesMaintenance: '#b23b3b', vehiclesOnRoute: '#3C79F5', vehiclesAvailable: '#1d7a3e',
  tripsToday: '#3C79F5', readyForDispatch: '#654e92', picking: '#3C79F5', packing: '#654e92', ordersWaitingAllocation: '#b26a16', waitingQc: '#b26a16', waitingPutaway: '#654e92',
  expectedInbounds: '#0d7f93', receivingToday: '#1d7a3e', returnsOpen: '#0d7f93',
};
/** Severity pill per action kind. */
const ACTION_SEV = {
  sla_breach: { ar: 'حرج', en: 'Critical', fg: '#fff', bg: '#b23b3b' }, exception: { ar: 'حرج', en: 'Critical', fg: '#fff', bg: '#b23b3b' },
  expired: { ar: 'عالٍ', en: 'High', fg: '#b26a16', bg: '#fbf0dd' }, driver_blocked: { ar: 'عالٍ', en: 'High', fg: '#b26a16', bg: '#fbf0dd' }, vehicle_doc: { ar: 'عالٍ', en: 'High', fg: '#b26a16', bg: '#fbf0dd' },
  po_approval: { ar: 'متوسط', en: 'Medium', fg: '#55506a', bg: '#F1EFF6' },
};

const router = useRouter();
const auth = useAuth();
const wh = useWarehouse();
const q = useGet('/dashboard', () => ({ ...wh.whParams }), { refetchInterval: 60_000 });
const d = computed(() => q.data.value);
const loading = computed(() => q.isLoading.value && !d.value);
const actions = computed(() => d.value?.actions || []);
const approvals = computed(() => [...(d.value?.pendingApprovals?.pos || []), ...(d.value?.pendingApprovals?.prs || [])]);
const exc = useExceptionActions();

const isExc = (a) => a.kind === 'exception' || a.kind === 'sla_breach';
const go = (path) => { if (path) router.push(path); };
</script>

<template>
  <PageHead :sub="t('نظرة لحظية على المخزون والعمليات عبر المستودعات', 'Real-time view across all warehouses')" />
  <ErrorBanner :error="q.error.value" :closable="false" />

  <KpiGrid>
    <template v-if="loading"><KpiCard v-for="i in 8" :key="i" loading label="" /></template>
    <template v-else>
      <KpiCard v-for="k in d?.kpis || []" :key="k.key" :value="k.key === 'availableValue' ? fmtMoney(k.value) : k.value" :unit="k.key === 'availableValue' ? { ar: 'ر.س', en: 'SAR' } : null"
               :label="{ ar: k.labelAr, en: k.labelEn }" :color="KPI_COLOR[k.key] || '#20242E'" :sub="k.hint" :clickable="!!k.link" @click="go(k.link)" />
    </template>
  </KpiGrid>

  <div class="grid-2 items-start">
    <div class="col !gap-3.5">
      <!-- Exception centre -->
      <div class="card">
        <CardTitle>{{ t('مركز الاستثناءات — يتطلب إجراء', 'Exception center — action required') }} <span class="num text-bad">({{ fmtNum(actions.length) }})</span></CardTitle>
        <div v-if="loading" class="p-[18px]"><div class="skel mb-2.5 h-3.5" /><div class="skel h-3.5 w-4/5" /></div>
        <EmptyState v-else-if="actions.length === 0" tone="success" class="m-3.5 !p-[22px]" :text="{ ar: 'لا استثناءات تتطلب إجراء الآن ✓', en: 'Nothing needs action right now ✓' }" />
        <template v-else>
          <ListRow v-for="(a, i) in actions" :key="`${a.kind}-${a.number}-${i}`" clickable @click="go(a.path)">
            <Chip :map="ACTION_SEV" :k="a.kind" class="flex-none" />
            <div class="flex-1 text-[11px] font-bold leading-[1.8] text-sec">{{ lang === 'ar' ? a.textAr : a.textEn }}</div>
            <ExceptionActions v-if="isExc(a)" :row="{ number: a.number, status: 'open' }" @act="(mode) => exc.open(mode, a.number)" />
            <Chip v-if="a.owner" small :label="a.owner" fg="#654e92" bg="#efeaf8" />
          </ListRow>
        </template>
      </div>

      <!-- Pending approvals for my roles -->
      <div v-if="approvals.length > 0" class="card">
        <CardTitle>
          {{ t('اعتمادات بانتظارك', 'Approvals waiting for you') }} <span class="num text-warn">({{ fmtNum(approvals.length) }})</span>
          <span v-if="auth.user" class="text-[9.5px] font-normal text-faint"> · {{ auth.user.roles.join(' · ') }}</span>
        </CardTitle>
        <ListRow v-for="a in approvals" :key="`${a.type}-${a.number}`" clickable @click="go(a.path)">
          <Chip small :label="a.type" :fg="a.type === 'PO' ? '#3C79F5' : '#654e92'" :bg="a.type === 'PO' ? '#e8effe' : '#efeaf8'" />
          <span class="cell-id flex-none">{{ a.number }}</span>
          <div class="flex-1 text-[10.5px] leading-[1.7] text-sec">
            <template v-if="a.type === 'PO'">{{ lang === 'ar' ? a.supplierAr : a.supplierEn }} · <span class="num">{{ fmtMoney(a.total) }}</span> {{ t('ر.س', 'SAR') }} · {{ t('خطوة', 'step') }} {{ a.step }} — {{ lang === 'ar' ? a.labelAr : a.labelEn }}</template>
            <template v-else>{{ t('طلب شراء', 'Purchase request') }} · {{ a.warehouse }} · {{ a.priority }} · {{ t('خطوة', 'step') }} {{ a.step }}</template>
          </div>
          <Ago :at="a.createdAt" />
        </ListRow>
      </div>
    </div>

    <div class="col !gap-3.5">
      <!-- Inventory by warehouse -->
      <SectionCard :title="d?.costVisible ? { ar: 'قيمة المخزون حسب المستودع', en: 'Inventory value by warehouse' } : { ar: 'المخزون حسب المستودع', en: 'Inventory by warehouse' }">
        <div class="col !gap-[11px]">
          <div v-for="w in d?.inventoryByWarehouse || []" :key="w.warehouseId">
            <div class="flex text-[10.5px] font-extrabold text-sec">
              <div>{{ lang === 'ar' ? w.nameAr : w.nameEn }} <span class="num text-[9px] text-faint">{{ w.code }}</span></div>
              <div class="flex-1" />
              <div class="num text-brand-dark">
                <template v-if="d?.costVisible && w.value != null">{{ fmtMoney(w.value) }} <span class="font-sans text-[8px] text-faint">{{ t('ر.س', 'SAR') }}</span></template>
                <template v-else>{{ fmtNum(w.onHand) }} <span class="font-sans text-[8px] text-faint">{{ t('وحدة', 'units') }}</span></template>
              </div>
            </div>
            <ProgressBar :pct="w.sharePct" class="mt-1" />
            <div class="num-mixed mt-[3px] text-[9px] text-faint">
              {{ fmtNum(w.skus) }} SKU · {{ t('متاح', 'available') }} {{ fmtNum(w.available) }} · {{ t('محجوز', 'reserved') }} {{ fmtNum(w.reserved) }} · {{ fmtNum(w.sharePct, 1) }}%
            </div>
          </div>
          <EmptyState v-if="d && d.inventoryByWarehouse.length === 0" :text="{ ar: 'لا مستودعات نشطة', en: 'No active warehouses' }" />
        </div>

        <!-- Live feed -->
        <div class="mt-3.5 rounded-xl border border-[#DCD2EE] bg-[#EFEAF8] px-3.5 py-2.5">
          <div class="text-[10px] font-extrabold text-violet">{{ t('آخر النشاط — مباشر', 'Recent activity — live') }}</div>
          <div v-if="(d?.recentActivity || []).length === 0" class="mt-1.5 text-[9.5px] text-[#a89cc4]">{{ t('لا نشاط بعد', 'No activity yet') }}</div>
          <div v-for="f in d?.recentActivity || []" :key="f.id" class="mt-1.5 flex items-baseline gap-[7px] text-[10px] leading-[1.7] text-[#55417e]">
            <div class="pulse h-1.5 w-1.5 flex-none rounded-full bg-violet" />
            <div class="flex-1">{{ lang === 'ar' ? f.textAr : f.textEn || f.textAr }}</div>
            <div class="num ltr flex-none text-[8.5px] text-[#a89cc4]">{{ f.username ? `${f.username} · ` : '' }}{{ fmtTime(f.at) }}</div>
          </div>
        </div>
        <div v-if="d" class="num-mixed mt-2.5 text-[8.5px] text-faint">{{ t('آخر تحديث', 'Updated') }} {{ fmtTime(d.generatedAt) }} · {{ t('يتحدث تلقائيًا كل دقيقة', 'auto-refresh every minute') }}</div>
      </SectionCard>
    </div>
  </div>

  <ExceptionActionModal :mode="exc.state.value?.mode" :number="exc.state.value?.number" @close="exc.close()" />
</template>
