<script setup>
// One brand owner: its header (state, linked user, what it may see), the manager's actions (edit, state moves, compute
// alerts now) and four sections — sec=dashboard|franchisees|alerts|profile in the query string.
// GET /insights/brands/{id} → owner + contact, notes, access, products[], categories[], productCount, franchisees[],
// unreadAlerts, next[] (the states it may move to; empty when Odoo owns it or the user is not a manager).
//   <BrandDetail :owner-id="id" :me="me" inline />   inline = the outsider's page (no drawer around it)
import { computed } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, KV, SectionCard, Tabs } from '@/components';
import { fmtNum, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import AlertsList from './AlertsList.vue';
import BrandDashboard from './BrandDashboard.vue';
import FranchiseesPanel from './FranchiseesPanel.vue';
import { ODOO_CHIP, OWNED_HELP, OWNER_MOVE, OWNER_STATE, fmtDay, nameOf, useInsightsNav, userName } from './insights';

const props = defineProps({
  ownerId: { type: String, required: true },
  me: { type: Object, required: true },
  inline: { type: Boolean, default: false },
});
const emit = defineEmits(['edit']);

const nav = useInsightsNav();
const detail = useGet(() => `/insights/brands/${encodeURIComponent(props.ownerId)}`);
const d = computed(() => detail.data.value || null);
const manager = computed(() => !!props.me.manager);
const owned = computed(() => !!d.value?.ownedBySource);

const SECS = ['dashboard', 'franchisees', 'alerts', 'profile'];
const sec = computed(() => (SECS.includes(nav.get('sec')) ? nav.get('sec') : 'dashboard'));
const secs = computed(() => [
  { k: 'dashboard', label: { ar: 'اللوحة', en: 'Dashboard' } },
  { k: 'franchisees', label: { ar: 'الفروع', en: 'Franchisees' }, badge: d.value?.franchisees?.length || null },
  { k: 'alerts', label: { ar: 'التنبيهات', en: 'Alerts' }, badge: d.value?.unreadAlerts || null },
  { k: 'profile', label: { ar: 'الملف والنطاق', en: 'Profile & scope' } },
]);
const setSec = (k) => nav.patch({ sec: k === 'dashboard' ? null : k, fig: null, figCustomer: null, figMonth: null, figProduct: null, figKind: null, figSource: null });

// ---- actions (manager)
const act = useAction();
async function move(to) {
  const o = d.value;
  const label = OWNER_MOVE[to] || { ar: to, en: to };
  let reason = null;
  if (to === 'suspended' || to === 'archived') {
    reason = await ask({
      title: { ar: `${label.ar}: ${o.name}`, en: `${label.en}: ${o.name}` },
      sub: to === 'archived' ? { ar: 'المالك المؤرشف لا يُعدَّل ولا يرى لوحته بعد ذلك.', en: 'An archived owner can no longer be changed and loses his dashboard.' } : { ar: 'يفقد حساب المالك الوصول إلى لوحته حتى يُعاد تفعيله.', en: 'The owner\'s account loses its dashboard until it is activated again.' },
      label: { ar: 'السبب', en: 'Reason' }, required: true, tone: 'danger', okLabel: label,
    });
    if (reason === null) return;
  } else if (!(await confirm({ title: { ar: `${label.ar}: ${o.name}؟`, en: `${label.en}: ${o.name}?` }, tone: 'dark' }))) return;
  await act.run(() => api.postIdempotent(`/insights/brands/${encodeURIComponent(o.id)}/state`, reason ? { to, reason } : { to }), { success: t('تغيّرت حالة المالك', 'Owner state changed'), invalidate: ['insights'] });
}
async function runAlerts() {
  await act.run(() => api.postIdempotent('/insights/alerts/run', { owner: d.value.id }), {
    success: (x) => (x.owners ? t(`حُسبت التنبيهات: ${x.raised} تنبيه جديد`, `Alerts computed: ${x.raised} new`) : t('لم يُحسب شيء — التنبيهات للملاك النشطين فقط', 'Nothing computed — alerts are for active owners only')),
    invalidate: ['insights'],
  });
}

const access = computed(() => {
  const a = d.value?.access || {};
  return [
    { k: 'invoices', ar: 'الفواتير والمتأخرات', en: 'Invoices & overdue', on: a.invoices },
    { k: 'returns', ar: 'المرتجعات', en: 'Returns', on: a.returns },
    { k: 'payments', ar: 'المدفوعات', en: 'Payments', on: a.payments },
    { k: 'margin', ar: 'هامش الربح', en: 'Margin', on: a.margin },
  ];
});
const flags = computed(() => {
  const o = d.value || {};
  return [
    { k: 'allowInvoice', ar: 'يرى الفواتير', en: 'Sees invoices', on: o.allowInvoice },
    { k: 'allowReturn', ar: 'يرى المرتجعات', en: 'Sees returns', on: o.allowReturn },
    { k: 'allowPayment', ar: 'يرى المدفوعات', en: 'Sees payments', on: o.allowPayment },
    { k: 'allowMargin', ar: 'يرى الهامش', en: 'Sees margin', on: o.allowMargin },
  ];
});
const contactText = computed(() => {
  const c = d.value?.contact;
  if (!c) return '—';
  return c.code ? `${nameOf(c)} · ${c.code}` : c.name || '—';
});
</script>

<template>
  <ErrorBanner :error="detail.error.value" :closable="false" />
  <div v-if="!d && detail.isLoading.value" class="skel h-[160px]" />
  <template v-else-if="d">
    <div class="row wrap mb-3 !gap-2" :class="{ 'no-print': !inline }">
      <template v-if="inline">
        <div class="min-w-0">
          <div class="text-[15px] font-extrabold text-ink" dir="auto">{{ d.name }}</div>
          <div v-if="d.brandName" class="text-[10.5px] font-bold text-violet" dir="auto">{{ t('العلامة', 'Brand') }}: {{ d.brandName }}</div>
        </div>
      </template>
      <Chip :map="OWNER_STATE" :k="d.state" />
      <Chip v-if="owned" :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" />
      <span class="text-[10px] text-muted">{{ t('منتجات العلامة', 'Brand products') }} <b class="num">{{ fmtNum(d.productCount) }}</b></span>
      <span class="text-[10px] text-muted">· {{ t('الفروع', 'Franchisees') }} <b class="num">{{ fmtNum(d.franchisees?.length || 0) }}</b></span>
      <div class="grow" />
      <template v-if="manager">
        <Btn v-if="!owned && d.state !== 'archived'" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', d)" />
        <Btn v-for="to in d.next || []" :key="to" size="sm" :tone="OWNER_MOVE[to]?.tone || 'soft'" :loading="act.pending.value" :label="OWNER_MOVE[to] || to" @click="move(to)" />
        <Btn v-if="d.state === 'active'" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'احسب التنبيهات الآن', en: 'Compute alerts now' }" @click="runAlerts" />
      </template>
    </div>
    <div v-if="owned && manager" class="hint purple !mt-0 mb-3">{{ t(OWNED_HELP.ar, OWNED_HELP.en) }}</div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />

    <Tabs :model-value="sec" :tabs="secs" variant="sm" class="no-print" @update:model-value="setSec" />

    <BrandDashboard v-if="sec === 'dashboard'" :owner-id="d.id" />
    <FranchiseesPanel v-else-if="sec === 'franchisees'" :owner="d" :manager="manager" />
    <AlertsList v-else-if="sec === 'alerts'" :owner-id="d.id" :manager="manager" />
    <template v-else-if="sec === 'profile'">
      <SectionCard :title="{ ar: 'بيانات المالك', en: 'Owner' }">
        <div class="kv-grid">
          <KV :k="{ ar: 'الاسم', en: 'Name' }" :v="d.name" />
          <KV :k="{ ar: 'العلامة', en: 'Brand' }" :v="d.brandName || '—'" />
          <KV :k="{ ar: 'المستخدم المرتبط', en: 'Linked user' }" :v="d.user ? `${userName(d.user)} (${d.user.username})` : d.userName || t('بلا مستخدم', 'No user')" />
          <KV :k="{ ar: 'جهة الاتصال (عميل)', en: 'Contact (customer)' }" :v="contactText" />
          <KV :k="{ ar: 'تاريخ البداية', en: 'Start date' }" :v="fmtDay(d.dateStart)" ltr />
          <KV :k="{ ar: 'المصدر', en: 'Source' }" :v="owned ? t('أودو', 'Odoo') : t('العمليات', 'OPS')" />
        </div>
        <div v-if="d.notes" class="mt-3 whitespace-pre-line text-[11px] leading-[1.8] text-sec" dir="auto">{{ d.notes }}</div>
      </SectionCard>
      <div class="grid-2 mt-3">
        <SectionCard :title="{ ar: 'ما يراه حساب المالك', en: 'What the owner\'s account sees' }" :sub="{ ar: 'المشتريات تظهر دائمًا؛ الباقي حسب الصلاحيات.', en: 'Purchases are always shown; the rest follows these flags.' }">
          <div class="row wrap !gap-1.5">
            <span v-for="f in flags" :key="f.k" class="chip sm" :class="f.on ? 'text-ok' : 'text-faint'" :style="{ background: f.on ? '#e6f9ec' : '#F1EFF6' }">{{ f.on ? '✓' : '✕' }} {{ t(f.ar, f.en) }}</span>
          </div>
          <div class="mt-2 text-[9.5px] text-muted">{{ t('ما تراه أنت الآن في هذه اللوحة:', 'What you see on this dashboard now:') }}</div>
          <div class="row wrap mt-1 !gap-1.5">
            <span v-for="f in access" :key="f.k" class="text-[9.5px] font-bold" :class="f.on ? 'text-ok' : 'text-faint'">{{ f.on ? '✓' : '✕' }} {{ t(f.ar, f.en) }}</span>
          </div>
        </SectionCard>
        <SectionCard :title="{ ar: 'نطاق العلامة', en: 'Brand scope' }" :sub="{ ar: 'منتجات العلامة = المنتجات المسماة + منتجات الفئات المسماة.', en: 'Brand products = the named products + the products of the named categories.' }">
          <div class="text-[10px] font-extrabold text-sec">{{ t('الفئات', 'Categories') }} <span class="num">({{ fmtNum(d.categories?.length || 0) }})</span></div>
          <div class="row wrap mt-1 !gap-1">
            <span v-for="c in d.categories" :key="c.id" class="chip sm" dir="auto">{{ nameOf(c) }} <span class="num ltr ms-1">{{ c.code }}</span></span>
            <span v-if="!d.categories?.length" class="text-[10px] text-faint">—</span>
          </div>
          <div class="mt-3 text-[10px] font-extrabold text-sec">{{ t('منتجات مسماة', 'Named products') }} <span class="num">({{ fmtNum(d.products?.length || 0) }})</span></div>
          <div class="mt-1 max-h-[220px] overflow-auto">
            <div v-for="p in d.products" :key="p.id" class="row !gap-2 border-b border-line-2 py-1 text-[10px] last:border-b-0">
              <span class="cell-id min-w-[90px]">{{ p.sku }}</span><span class="min-w-0 flex-1 truncate" dir="auto">{{ nameOf(p) }}</span>
            </div>
            <div v-if="!d.products?.length" class="text-[10px] text-faint">—</div>
          </div>
          <div class="mt-2 text-[10px] text-muted">{{ t('إجمالي منتجات العلامة', 'Brand products in total') }}: <b class="num">{{ fmtNum(d.productCount) }}</b></div>
        </SectionCard>
      </div>
    </template>
  </template>
</template>
