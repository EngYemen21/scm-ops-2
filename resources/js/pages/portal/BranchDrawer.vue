<script setup>
// One customer branch: who it belongs to, where it is, whom to call, when it takes deliveries, the customer's people
// who order for it, what hangs on it (lists, order requests, statements) and its timeline.
//   <BranchDrawer :id="idOrNull" @close="…" @edit="(branch) => …" @changed="…" />
// GET /portal/branches/{id} → the branch + { sites, counts, events, can }. Actions (portal.manage):
// POST …/archive { reason? } · POST …/restore. The buttons follow `can` — what the server would accept now.
import { computed } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Timeline } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import PartyName from './PartyName.vue';
import { ACTIVE_LABELS, HUB_ROLES, OWNED_CHIP, customerKey, customerLine, daysLabel, eventItem, labelOf, moment, usePortalNav } from './portal';

const props = defineProps({
  /** Id of the branch; null closes the drawer. */
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'edit', 'changed']);

const auth = useAuth();
const nav = usePortalNav();
const canManage = computed(() => auth.can('portal.manage'));
const q = useGet(() => (props.id ? `/portal/branches/${encodeURIComponent(props.id)}` : null));
const b = computed(() => (props.id ? q.data.value || null : null));
const act = useAction({ invalidate: ['portal'] });
const events = computed(() => (b.value?.events || []).map(eventItem));

async function archive() {
  const reason = await ask({ title: { ar: `أرشفة الفرع «${b.value.name}»؟`, en: `Archive branch “${b.value.name}”?` }, sub: { ar: 'يبقى الفرع بسجله وقوائمه وكشوفه، ولا يُعرض بين الفروع النشطة. يمكن استعادته.', en: 'The branch stays with its history, lists and statements, and leaves the active branches. It can be restored.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'أرشفة', en: 'Archive' }, tone: 'danger' });
  if (reason === null) return;
  const r = await act.run(() => api.postIdempotent(`/portal/branches/${b.value.id}/archive`, reason ? { reason } : {}), { success: t('أُرشف الفرع', 'Branch archived') });
  if (r) { q.refetch(); emit('changed'); }
}
async function restore() {
  if (!(await confirm({ title: { ar: `استعادة الفرع «${b.value.name}»؟`, en: `Restore branch “${b.value.name}”?` }, okLabel: { ar: 'استعادة', en: 'Restore' }, tone: 'dark' }))) return;
  const r = await act.run(() => api.postIdempotent(`/portal/branches/${b.value.id}/restore`), { success: t('استُعيد الفرع', 'Branch restored') });
  if (r) { q.refetch(); emit('changed'); }
}
/** The lists / requests / statements of this branch: the other tab, filtered. */
const goTo = (tab) => nav.go(tab, { customer: customerKey(b.value.customer), branchId: b.value.id });
</script>

<template>
  <Drawer :open="!!id" :width="560" @close="emit('close')">
    <template #title><span dir="auto">{{ b?.name || t('فرع', 'Branch') }}</span></template>
    <template v-if="b" #sub><span dir="auto">{{ customerLine(b.customer) }}</span></template>
    <template v-if="b" #headExtra>
      <div class="row !gap-1">
        <Chip small :map="ACTIVE_LABELS" :k="b.active ? 'active' : 'archived'" />
        <Chip v-if="b.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[180px]" />
    <template v-if="b">
      <div v-if="b.owned" class="hint purple !mt-0 mb-3">{{ t('هذا الفرع يُدار من أودو: اسمه وعميله وترتيبه تُعدَّل هناك. العنوان وجهة الاتصال ونافذة التسليم وموقع التسليم تُضاف هنا.', 'This branch is managed in Odoo: its name, customer and order are changed there. Its address, contact, delivery window and delivery site are added here.') }}</div>
      <div v-if="b.customer && !b.customer.linked" class="hint amber !mt-0 mb-3">{{ t('عميل هذا الفرع في أودو ليس عميلًا في نظام العمليات بعد. يُربط الفرع به تلقائيًا عند ربط العميل.', 'The customer of this branch is not an OPS customer yet. The branch is linked by itself once the customer is.') }}</div>
      <div v-if="!b.active && b.archiveReason" class="hint amber !mt-0 mb-3">{{ t('سبب الأرشفة', 'Archived because') }}: {{ b.archiveReason }} <span class="text-faint">· {{ b.archivedBy }} · <span class="num ltr">{{ moment(b.archivedAt) }}</span></span></div>

      <div class="kv-grid">
        <KV :k="{ ar: 'العميل', en: 'Customer' }"><PartyName :party="b.customer" /></KV>
        <KV :k="{ ar: 'رمز الفرع', en: 'Branch code' }" :v="b.code" ltr />
        <KV :k="{ ar: 'جهة الاتصال', en: 'Contact' }" :v="b.contactName" />
        <KV :k="{ ar: 'الجوال', en: 'Mobile' }" :v="b.phone" ltr />
        <KV :k="{ ar: 'البريد الإلكتروني', en: 'E-mail' }" :v="b.email" ltr />
        <KV :k="{ ar: 'المدينة · الحي', en: 'City · district' }" :v="[b.city, b.district].filter(Boolean).join(' · ') || null" />
        <KV :k="{ ar: 'نافذة التسليم', en: 'Delivery window' }"><span v-if="b.window" class="num ltr">{{ b.window }}</span><span v-else class="text-faint">{{ t('غير محددة', 'Not set') }}</span></KV>
        <KV :k="{ ar: 'أيام التسليم', en: 'Delivery days' }" :v="daysLabel(b.deliveryDays)" />
        <KV :k="{ ar: 'موقع التسليم في النظام', en: 'Delivery site in OPS' }">
          <span v-if="b.site" dir="auto">{{ b.site.name }}<span v-if="b.site.city" class="text-faint"> · {{ b.site.city }}</span></span>
          <span v-else class="text-warn">{{ t('غير مربوط — لا يصدر له كشف فرع', 'Not tied — no branch statement can be issued') }}</span>
        </KV>
        <KV :k="{ ar: 'الموقع على الخريطة', en: 'Map location' }"><span v-if="b.located" class="num ltr">{{ Number(b.lat).toFixed(5) }}, {{ Number(b.lng).toFixed(5) }}</span><span v-else class="text-faint">{{ t('غير محدَّد', 'Not set') }}</span></KV>
        <KV v-if="b.address" class="col-span-full" :k="{ ar: 'العنوان', en: 'Address' }" :v="b.address" />
        <KV v-if="b.deliveryNotes" class="col-span-full" :k="{ ar: 'تعليمات التسليم', en: 'Delivery instructions' }" :v="b.deliveryNotes" />
        <KV v-if="b.notes" class="col-span-full" :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="b.notes" />
      </div>

      <div class="kpi-grid compact !mb-0 mt-4">
        <button type="button" class="tile cursor-pointer border-0 text-start" @click="goTo('favorites')"><div class="tile-v">{{ b.counts.lists }}</div><div class="tile-l">{{ t('قوائم مفضلة للفرع', 'Favourite lists') }}</div></button>
        <button type="button" class="tile cursor-pointer border-0 text-start" @click="goTo('requests')"><div class="tile-v">{{ b.counts.requests }}</div><div class="tile-l">{{ t('طلبات شراء', 'Order requests') }}</div></button>
        <button type="button" class="tile cursor-pointer border-0 text-start" @click="goTo('statements')"><div class="tile-v">{{ b.counts.statements }}</div><div class="tile-l">{{ t('كشوف حساب للفرع', 'Branch statements') }}</div></button>
      </div>

      <template v-if="b.portalUsers.length">
        <div class="card-title mb-1.5 mt-4">{{ t('مستخدمو البوابة في هذا الفرع', 'Portal users of this branch') }}</div>
        <div class="overflow-hidden rounded-xl border border-line-2">
          <div v-for="u in b.portalUsers" :key="u.ref || u.name" class="flex items-center gap-2 border-t border-line-2 px-3 py-2 text-[10.5px] first:border-t-0">
            <span class="min-w-0 flex-1 truncate font-bold" dir="auto">{{ u.name }}</span>
            <span v-if="u.role" class="text-muted">{{ labelOf(HUB_ROLES, u.role) }}</span>
          </div>
        </div>
      </template>

      <div class="card-title mb-1 mt-4">{{ t('السجل', 'History') }}</div>
      <Timeline :items="events" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }" />
    </template>

    <template v-if="b && canManage" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="b.can.edit" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', b)" />
        <Btn v-if="b.can.archive" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
        <Btn v-if="b.can.restore" tone="success" :loading="act.pending.value" :label="{ ar: 'استعادة', en: 'Restore' }" @click="restore" />
      </div>
    </template>
  </Drawer>
</template>
