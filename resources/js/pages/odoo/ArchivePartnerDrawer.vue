<script setup>
// The partner view — opened from any partner link, and from a row of a list of partners (`card=partner:<odooId>` in
// the query string): who the partner is, what is open on Odoo's posted invoices (owed by it) and bills (owed to it),
// and its documents per kind — how many the archive holds and the latest ones. A count opens the list of that kind
// filtered by the partner; a document opens its own drawer. Odoo writes a company's documents on any of its
// contacts, so "its documents" are the partner's and those of the contacts under it. Nothing is computed here.
// GET /odoo-archive/partners/{odooId}
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useGet } from '@/api/client';
import { Chip, Drawer, EmptyState, ErrorBanner, KV } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { TONE } from '../integration/odoo';
import ArchiveDocLinks from './ArchiveDocLinks.vue';
import ArchiveValue from './ArchiveValue.vue';
import { API, ARCHIVED_CHIP, DELETED_CHIP, fmtAmount, groupsOf, isKindKey, isObj, labelOf, openBalance, profileOf, textOf, useArchive } from './archive';

const props = defineProps({
  /** The partner's record id in Odoo; null closes the drawer. */
  odooId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const { nav, kindOf, kindsOfModel, entityKey } = useArchive();
const isOpen = computed(() => props.odooId != null);
const q = useGet(() => (isOpen.value ? `${API}/partners/${props.odooId}` : null), null, { staleTime: 30_000 });
const d = computed(() => (isOpen.value && isObj(q.data.value) ? q.data.value : null));

const name = computed(() => textOf(d.value?.name) || textOf(d.value?.title));
const ROLE = { customer: { ar: 'عميل', en: 'Customer', fg: TONE.blue.fg, bg: TONE.blue.bg }, supplier: { ar: 'مورد', en: 'Vendor', fg: TONE.violet.fg, bg: TONE.violet.bg } };
const roles = computed(() => [d.value?.isCustomer === true ? ROLE.customer : null, d.value?.isSupplier === true ? ROLE.supplier : null].filter(Boolean));
const contacts = computed(() => { const n = Number(d.value?.contacts); return Number.isFinite(n) && n > 0 ? n : 0; });
const ownKind = computed(() => (isKindKey(d.value?.kind) ? d.value.kind : null));
const profile = computed(() => profileOf(d.value));
const groups = computed(() => groupsOf(d.value));
const balances = computed(() => [
  { k: 'receivable', label: { ar: 'مستحق عليه — فواتير عملاء مفتوحة', en: 'Owed by it — open customer invoices' }, tone: 'amber', v: openBalance(d.value, 'receivable') },
  { k: 'payable', label: { ar: 'مستحق له — فواتير موردين مفتوحة', en: 'Owed to it — open supplier bills' }, tone: 'purple', v: openBalance(d.value, 'payable') },
].filter((x) => x.v));
/** The partner's own record as a document (history, files, every field): the kind the answer names, else the only kind that holds partners. */
const recordTo = computed(() => {
  const only = kindsOfModel('res.partner');
  const key = ownKind.value && kindOf(ownKind.value) ? ownKind.value : only.length === 1 ? only[0].key : null;
  return key ? nav.docTo(key, props.odooId) : null;
});
/** The list of a kind, filtered by this partner: with the query the answer gives for it, else the kind's partner filter. */
const listTo = (g) => (g.kind && kindOf(g.kind) ? nav.kindTo(g.kind, g.query || { [entityKey(g.kind, 'partner')]: props.odooId }) : null);
const groupLabel = (g) => labelOf(g.label) || labelOf(kindOf(g.kind)) || t('مستندات', 'Documents');
</script>

<template>
  <Drawer :open="isOpen" :width="720" @close="emit('close')">
    <template #title><span dir="auto">{{ name || (odooId != null ? `#${odooId}` : '') }}</span></template>
    <template #sub>{{ t('ملف الشريك كما هو في أودو', 'The partner as Odoo holds it') }} · <span class="num ltr inline-block">#{{ odooId }}</span></template>
    <template v-if="d && (roles.length || d.active === false || d.deleted === true)" #headExtra>
      <div class="row !gap-1">
        <Chip v-for="r in roles" :key="r.en" small :label="r" :fg="r.fg" :bg="r.bg" />
        <Chip v-if="d.deleted === true" small :label="DELETED_CHIP" :fg="DELETED_CHIP.fg" :bg="DELETED_CHIP.bg" />
        <Chip v-else-if="d.active === false" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="col"><div class="skel h-[64px]" /><div class="skel h-[110px]" /><div class="skel h-[160px]" /></div>
    <template v-if="d">
      <div v-if="balances.length" class="mb-4 grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-2">
        <div v-for="b in balances" :key="b.k" class="tile" :class="b.v.n > 0 ? b.tone : null">
          <div class="tile-v">{{ fmtAmount(b.v.n) }}<span v-if="b.v.currency" class="ms-1.5 text-[10px] font-normal text-muted">{{ b.v.currency }}</span></div>
          <div class="tile-l">{{ bi(b.label) }}<template v-if="b.v.count != null"> · {{ t('المستندات', 'Documents') }} <span class="num ltr">{{ fmtNum(b.v.count) }}</span></template></div>
        </div>
      </div>

      <div v-if="profile.length" class="kv-grid">
        <KV v-for="(s, i) in profile" :key="s.key || i" :k="labelOf(s) || '—'">
          <ArchiveValue :type="s.type || 'text'" :value="s.value" :link="isObj(s.link) ? s.link : null" :currency="s.currency ?? d.currency" :kind="ownKind" wrap />
        </KV>
      </div>
      <RouterLink v-if="recordTo" :to="recordTo" class="mt-2.5 inline-block text-[10px] font-bold">{{ t('سجل الشريك كاملًا: السجل، المرفقات وكل الحقول', 'The whole partner record: history, attachments and every field') }}</RouterLink>

      <div class="mb-1 mt-[18px] text-[13px] font-extrabold">{{ t('مستنداته في الأرشيف', 'Its documents in the archive') }}</div>
      <div v-if="contacts" class="mb-1 text-[10px] text-muted">{{ t('تشمل ما كُتب في أودو على جهات الاتصال التابعة له، وعددها', 'Including what Odoo holds on the contacts under it — contacts:') }} <span class="num ltr">{{ fmtNum(contacts) }}</span></div>
      <EmptyState v-if="!groups.length" :text="{ ar: 'لا مستندات لهذا الشريك في الأرشيف. تظهر هنا أوامره وفواتيره ومدفوعاته وتحويلاته، لكل نوع عددها وأحدثها.', en: 'The archive holds no document of this partner. Its orders, invoices, payments and transfers appear here, each kind with its count and its latest ones.' }" />
      <div v-for="g in groups" :key="g.key" class="mt-3">
        <div class="mb-1.5 flex flex-wrap items-center gap-2">
          <div class="text-[11.5px] font-extrabold" dir="auto">{{ groupLabel(g) }}</div>
          <span v-if="g.count != null" class="num ltr text-[9.5px] font-bold text-faint">{{ fmtNum(g.count) }}</span>
          <span class="flex-1" />
          <RouterLink v-if="listTo(g)" :to="listTo(g)" class="text-[10px] font-bold">{{ g.count != null ? t(`عرض الكل (${fmtNum(g.count)})`, `Show all (${fmtNum(g.count)})`) : t('عرض الكل', 'Show all') }}</RouterLink>
        </div>
        <ArchiveDocLinks v-if="g.items.length" :kind="g.kind" :items="g.items" :omit="name" />
      </div>
      <div class="hint">{{ t('الأرقام هنا من نسخة أودو كما هي: المستحق هو المتبقي على فواتير أودو المرحّلة غير المسدّدة، بعملتها. ليست ذمم نظام العمليات ولا تغيّر فيها شيئًا.', 'These figures come from the Odoo copy as it is: what is open is what remains on Odoo’s posted, unpaid invoices and bills, in their own currency. They are not the OPS receivables and change nothing there.') }}</div>
    </template>
  </Drawer>
</template>
