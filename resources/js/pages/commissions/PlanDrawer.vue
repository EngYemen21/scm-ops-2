<script setup>
// One commission plan, from any tab: `plan=<code>` in the query string opens it.
//   GET /commissions/plans/{code} → the plan, its rules and people, how many statements it produced, `locked`, `owned`.
// The manager's steps: edit (PlanForm) · activate (draft / archived → active) · archive (active → archived, optional
// reason) · duplicate (a draft copy OPS owns — the way to change locked terms, or to take over a rule that came from Odoo).
// A plan that came from Odoo is read-only while the section follows Odoo: it can only be duplicated.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner } from '@/components';
import { fmtDate, t } from '@/i18n';
import { confirm } from '@/stores/ui';
import PlanForm from './PlanForm.vue';
import ReasonDrawer from './ReasonDrawer.vue';
import { API, BASE, EARNER, EARN_ON, ODOO_CHIP, PLAN_STATUS, STATEMENT_STATUS, TIER_MODE, dayText, label, personName, rateText, recordName, scopeText, useCommissions } from './commissions';

const { nav, can } = useCommissions();
const code = computed(() => nav.get('plan') || null);
const detailQ = useGet(() => (code.value ? `${API}/plans/${encodeURIComponent(code.value)}` : null));
const p = computed(() => (code.value && detailQ.data.value && detailQ.data.value.code ? detailQ.data.value : null));
const act = useAction({ invalidate: ['commissions'] });
const editing = ref(false);
const asking = ref(null);
watch(code, () => { editing.value = false; asking.value = null; act.clearError(); });

const mine = computed(() => can.value.manage && p.value && !p.value.owned);
const url = (suffix) => `${API}/plans/${encodeURIComponent(p.value.code)}${suffix}`;
async function activate() {
  if (!(await confirm({ title: { ar: `تفعيل الخطة ${p.value.code}؟`, en: `Activate plan ${p.value.code}?` },
    sub: { ar: 'تحكم من تسمّيهم في الشهور التي تغطيها، ويبدأ احتسابها عند «احتساب شهر».', en: 'It decides for the people it names in the months it covers, from the next “Calculate a month”.' }, tone: 'primary' }))) return;
  await act.run(() => api.postIdempotent(url('/activate')), { success: t('فُعّلت الخطة', 'Plan activated') });
}
const askArchive = () => { asking.value = {
  title: { ar: `أرشفة الخطة ${p.value.code}`, en: `Archive plan ${p.value.code}` }, sub: { ar: 'لا تحكم بعدها أي شهر لم يُقدَّم كشفه؛ الكشوف التي صدرت عنها تبقى كما هي', en: 'It decides no month whose statement is not submitted yet; the statements it produced stay as they are' },
  okLabel: { ar: 'أرشفة', en: 'Archive' }, optional: true,
  run: (reason) => act.run(() => api.postIdempotent(url('/archive'), reason ? { reason } : undefined), { success: t('أُرشفت الخطة', 'Plan archived') }) }; };
async function duplicate() {
  const r = await act.run(() => api.postIdempotent(url('/duplicate')), { success: (x) => t(`نُسخت إلى ${x.code} — مسودة`, `Copied to ${x.code} — a draft`) });
  if (r) nav.openPlan(r.code);
}
async function runAsked(reason) { const r = await asking.value.run(reason); if (r) asking.value = null; }
const usage = computed(() => Object.entries(p.value?.statementsByStatus || {}).filter(([, n]) => n > 0));
</script>

<template>
  <Drawer :open="!!code" :width="620" :title="p ? recordName(p) : t('خطة عمولات', 'Commission plan')" :sub="p ? p.code : null" @close="nav.closePlan()">
    <template #headExtra>
      <div v-if="p" class="row !gap-1"><Chip :map="PLAN_STATUS" :k="p.status" small /><Chip v-if="p.sourceSystem === 'odoo'" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" /></div>
    </template>
    <ErrorBanner :error="detailQ.error.value" :closable="false" />
    <ErrorBanner v-if="!asking" :error="act.error.value" @close="act.clearError()" />
    <div v-if="!p && !detailQ.error.value" class="col"><div class="skel h-[60px]" /><div class="skel h-[140px]" /></div>

    <template v-if="p">
      <div v-if="p.owned" class="hint purple !mt-0 mb-3">{{ t('قاعدة احتساب جاءت من أودو: تُعرض للاطلاع ما دام القسم يتبع أودو. لتعمل بها في نظام العمليات انسخها — النسخة ملك العمليات تُعدَّل وتُفعَّل.', 'A calculation rule that came from Odoo: read-only while the section follows Odoo. To put it to work in OPS, duplicate it — the copy is OPS’s own to edit and activate.') }}</div>
      <div v-else-if="p.locked" class="hint amber !mt-0 mb-3">{{ t('كشوف صادرة عن هذه الخطة تجاوزت المسودة: شروطها مقفلة. لشروط جديدة أنهِها بتاريخ نهاية وانسخها.', 'Statements of this plan have left the draft state: its terms are locked. For new terms, end it and duplicate it.') }}</div>
      <div v-else-if="p.status === 'draft'" class="hint !mt-0 mb-3">{{ t('مسودة: لا تدخل الاحتساب حتى تُفعَّل.', 'A draft: it takes no part in a calculation until it is activated.') }}</div>

      <div class="kv-grid mb-3">
        <div class="kv"><div class="kv-k">{{ t('من يستحق', 'Who earns') }}</div><div class="kv-v">{{ label(EARNER, p.earnerType) }}</div></div>
        <div v-if="p.earnerType === 'manager'" class="kv"><div class="kv-k">{{ t('المدير المستحق', 'The manager') }}</div><div class="kv-v" dir="auto">{{ personName(p.beneficiary) }}<span v-if="p.beneficiary && p.beneficiary.external" class="ms-1 text-[9px] font-normal text-warn">{{ t('بالاسم — ليس مستخدمًا في النظام', 'by name — not an OPS user') }}</span></div></div>
        <div class="kv"><div class="kv-k">{{ t('متى تُستحق', 'When it is earned') }}</div><div class="kv-v">{{ label(EARN_ON, p.earnOn) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('أساس النسبة', 'Base of the rate') }}</div><div class="kv-v">{{ label(BASE, p.base) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('المرتجعات', 'Returns') }}</div><div class="kv-v">{{ p.netOfReturns ? t('الإشعار الدائن يخصم حصته', 'A credit note takes its share back') : t('لا تُخصم', 'Not deducted') }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('السريان', 'Validity') }}</div><div class="kv-v num ltr">{{ dayText(p.validFrom) }} – {{ p.validTo ? dayText(p.validTo) : t('مفتوحة', 'open-ended') }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('أُنشئت', 'Created') }}</div><div class="kv-v"><span class="num ltr">{{ fmtDate(p.createdAt) }}</span><span v-if="p.createdBy" class="text-muted"> · {{ p.createdBy }}</span></div></div>
        <div v-if="p.activatedAt" class="kv"><div class="kv-k">{{ t('فُعّلت', 'Activated') }}</div><div class="kv-v"><span class="num ltr">{{ fmtDate(p.activatedAt) }}</span><span v-if="p.activatedBy" class="text-muted"> · {{ p.activatedBy }}</span></div></div>
        <div v-if="p.archivedAt" class="kv"><div class="kv-k">{{ t('أُرشفت', 'Archived') }}</div><div class="kv-v"><span class="num ltr">{{ fmtDate(p.archivedAt) }}</span><span v-if="p.archivedBy" class="text-muted"> · {{ p.archivedBy }}</span></div></div>
        <div v-if="p.notes" class="kv col-span-full"><div class="kv-k">{{ t('ملاحظات', 'Notes') }}</div><div class="kv-v !font-normal leading-[1.8]" dir="auto">{{ p.notes }}</div></div>
      </div>

      <div class="card-title mb-1.5">{{ p.earnerType === 'manager' ? t('على مبيعات من', 'On whose sales') : t('المندوبون المشمولون', 'Salespeople on the plan') }}</div>
      <div v-if="p.everyone" class="mb-3 text-[11px] font-bold">{{ p.earnerType === 'manager' ? t('كل الفواتير — أيًّا كان مندوبها', 'Every invoice — whoever its salesperson') : t('كل مندوب لا تسمّيه خطة أخرى', 'Every salesperson no other plan names') }}</div>
      <div v-else class="mb-3 flex flex-wrap gap-1.5">
        <span v-for="m in p.members" :key="m.key" class="pill max-w-full" :class="{ 'opacity-70': m.external }"><span class="min-w-0 truncate" dir="auto">{{ personName(m) }}</span><span v-if="m.external" class="text-[8.5px] font-normal">{{ t('بالاسم', 'by name') }}</span></span>
        <span v-if="!p.members.length" class="text-[10.5px] text-muted">{{ t('لا أحد بعد', 'Nobody yet') }}</span>
      </div>

      <div class="card-title mb-1.5">{{ t('القواعد — أول قاعدة تشمل السطر تحكمه', 'Rules — the first one that covers a line decides') }}</div>
      <div class="mb-3 overflow-hidden rounded-xl border border-line-2">
        <div v-for="(r, i) in p.rules" :key="r.id" class="border-t border-line-2 px-3 py-2.5 first:border-t-0">
          <div class="flex flex-wrap items-baseline gap-x-2 text-[11px]"><span class="num text-muted">{{ i + 1 }}</span><span class="min-w-0 flex-1 font-bold" dir="auto">{{ scopeText(r) }}</span><b class="num ltr text-[12px]">{{ rateText(r) }}</b></div>
          <div v-if="r.rateType === 'tiered'" class="mt-0.5 text-[9.5px] text-muted">{{ t('شرائح على أساس الشهر', 'Tiers on the month’s base') }} — {{ label(TIER_MODE, r.tierMode || 'whole') }}</div>
          <div v-if="r.label" class="mt-0.5 text-[9.5px] text-muted" dir="auto">{{ r.label }}</div>
        </div>
      </div>

      <div class="card-title mb-1.5">{{ t('الكشوف الصادرة عنها', 'Statements it produced') }} <span class="card-count num ms-1">{{ p.statements }}</span></div>
      <div v-if="usage.length" class="flex flex-wrap items-center gap-1.5">
        <Chip v-for="[status, n] in usage" :key="status" :map="STATEMENT_STATUS" :k="status" small>{{ label(STATEMENT_STATUS, status) }} <span class="num ms-1">{{ n }}</span></Chip>
        <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[10px] font-extrabold text-violet" @click="nav.setTab('statements', { ofplan: p.code })">{{ t('عرض الكشوف', 'Show them') }}</button>
      </div>
      <div v-else class="text-[10.5px] text-muted">{{ t('لا كشوف بعد.', 'None yet.') }}</div>
    </template>

    <template v-if="p && can.manage" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="mine && p.status !== 'active'" tone="primary" :loading="act.pending.value" :label="p.status === 'archived' ? { ar: 'إعادة تفعيل', en: 'Reactivate' } : { ar: 'تفعيل الخطة', en: 'Activate' }" @click="activate" />
        <Btn v-if="mine && p.status !== 'archived'" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = true" />
        <Btn tone="softPurple" :loading="act.pending.value" :label="{ ar: 'نسخ إلى خطة جديدة', en: 'Duplicate' }" @click="duplicate" />
        <span class="grow" />
        <Btn v-if="mine && p.status === 'active'" tone="dangerOutline" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="askArchive" />
      </div>
    </template>
  </Drawer>

  <PlanForm :open="editing" :plan="p" @close="editing = false" />
  <ReasonDrawer :ask="asking" :pending="act.pending.value" :error="act.error.value" @submit="runAsked" @close="asking = null; act.clearError()" @clear-error="act.clearError()" />
</template>
