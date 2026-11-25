<script setup>
// «الإعداد» (credit.manage): what the section is made of — the stages of the workflow, the versions of the credit
// terms, the payment methods and the policies (working calendar, required papers, reminders, signature expiry) — and
// «تشغيل المواعيد الآن»: POST /credit/deadlines/run runs the clock once (reminders, overdue, escalations, expired
// envelopes) and answers its counts. The clock also runs on its own every 15 minutes (scm:credit-deadlines).
//   /credit?tab=setup&set=stages|terms|methods|policies
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Tabs } from '@/components';
import { fmtNum, t } from '@/i18n';
import { confirm } from '@/stores/ui';
import MethodsTab from './MethodsTab.vue';
import PoliciesTab from './PoliciesTab.vue';
import StagesTab from './StagesTab.vue';
import TermsTab from './TermsTab.vue';
import { useCreditLookups, useCreditNav } from './credit';

const nav = useCreditNav();
const lk = useCreditLookups();
const SETS = ['stages', 'terms', 'methods', 'policies'];
const set = computed(() => (SETS.includes(nav.get('set')) ? nav.get('set') : 'stages'));
const tabs = computed(() => [
  { k: 'stages', label: { ar: 'المراحل', en: 'Stages' }, badge: lk.stages.value.filter((s) => s.active).length || null },
  { k: 'terms', label: { ar: 'إصدارات الشروط', en: 'Terms versions' } },
  { k: 'methods', label: { ar: 'طرق الدفع', en: 'Payment methods' }, badge: lk.paymentMethods.value.filter((m) => m.active).length || null },
  { k: 'policies', label: { ar: 'السياسات', en: 'Policies' } },
]);

const act = useAction({ invalidate: ['credit'] });
const ran = ref(null);
const RAN = [
  ['checked', { ar: 'طلبات فُحصت', en: 'Requests checked' }], ['reminded', { ar: 'تذكيرات أُرسلت', en: 'Reminders' }], ['overdue', { ar: 'متأخرة', en: 'Overdue' }],
  ['escalated', { ar: 'صُعّدت', en: 'Escalated' }], ['envelopesExpired', { ar: 'اتفاقيات انتهت مهلتها', en: 'Envelopes expired' }], ['failed', { ar: 'تعذّرت', en: 'Failed' }],
];
async function runDeadlines() {
  if (!(await confirm({ title: { ar: 'تشغيل المواعيد الآن؟', en: 'Run the deadlines now?' }, sub: { ar: 'يرسل التذكيرات ويصعّد المتأخر ويُنهي الاتفاقيات المنتهية — كما يفعل التشغيل الدوري', en: 'Sends reminders, escalates what is late and expires lapsed envelopes — as the scheduled run does' }, tone: 'dark', okLabel: { ar: 'تشغيل', en: 'Run' } }))) return;
  const r = await act.run(() => api.postIdempotent('/credit/deadlines/run'), { success: t('اكتمل التشغيل', 'Run complete') });
  if (r) ran.value = r;
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="set" :tabs="tabs" variant="sm" class="!mb-0" @update:model-value="(k) => nav.patch({ set: k === 'stages' ? null : k })" />
    <span class="grow" />
    <Btn size="sm" tone="dark" :loading="act.pending.value" :label="{ ar: 'تشغيل المواعيد الآن', en: 'Run the deadlines now' }" @click="runDeadlines" />
  </div>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="ran" class="banner green">
    <div class="min-w-0 flex-1">
      <div>{{ t('نتيجة تشغيل المواعيد', 'Result of the deadline run') }}<template v-if="ran.installed === false"> — {{ t('جداول القسم غير مثبتة', 'the section’s tables are not installed') }}</template></div>
      <div class="mt-1 flex flex-wrap gap-x-4 text-[10px]">
        <span v-for="[k, l] in RAN" :key="k">{{ t(l.ar, l.en) }}: <b class="num">{{ fmtNum(ran[k] ?? 0) }}</b></span>
      </div>
    </div>
    <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('إغلاق', 'Close')" @click="ran = null">✕</button>
  </div>

  <StagesTab v-if="set === 'stages'" />
  <TermsTab v-else-if="set === 'terms'" />
  <MethodsTab v-else-if="set === 'methods'" />
  <PoliciesTab v-else />
</template>
