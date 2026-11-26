<script setup>
// The product view — opened from any product link, and from a row of the list of products (`card=product:<odooId>`
// in the query string): what the product is, Odoo's own stock of it per warehouse (with its lots and the nearest
// expiry where Odoo tracks them), and its latest sales, purchases and stock moves. A count opens the list of that
// kind filtered by the product; a document opens its own drawer. The stock is Odoo's figure, not the OPS inventory.
// GET /odoo-archive/products/{odooId}
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useGet } from '@/api/client';
import { Chip, DataTable, Drawer, EmptyState, ErrorBanner, KV } from '@/components';
import { fmtNum, t } from '@/i18n';
import { agoAt, fmtAt } from '../integration/odoo';
import ArchiveDocLinks from './ArchiveDocLinks.vue';
import ArchiveValue from './ArchiveValue.vue';
import { API, ARCHIVED_CHIP, DELETED_CHIP, dayText, groupsOf, isKindKey, isObj, labelOf, numText, profileOf, stockOf, textOf, useArchive } from './archive';

const props = defineProps({
  /** The product's record id in Odoo; null closes the drawer. */
  odooId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const { nav, kindOf, kindsOfModel, entityKey } = useArchive();
const isOpen = computed(() => props.odooId != null);
const q = useGet(() => (isOpen.value ? `${API}/products/${props.odooId}` : null), null, { staleTime: 30_000 });
const d = computed(() => (isOpen.value && isObj(q.data.value) ? q.data.value : null));

const name = computed(() => textOf(d.value?.name) || textOf(d.value?.title));
const code = computed(() => textOf(d.value?.code ?? d.value?.number));
const ownKind = computed(() => (isKindKey(d.value?.kind) ? d.value.kind : null));
const profile = computed(() => profileOf(d.value));
const groups = computed(() => groupsOf(d.value));
const stock = computed(() => stockOf(d.value));
const onHand = computed(() => stock.value.reduce((n, r) => n + (r.qty || 0), 0));
/** When the stock figures were last read from Odoo, when the answer says. */
const asOf = computed(() => (isObj(d.value?.stock) && typeof d.value.stock.asOf === 'string' ? d.value.stock.asOf : null));
const qty = (v) => (v == null ? '—' : numText(v, 'qty'));
const stockColumns = computed(() => [
  { key: 'warehouse', header: { ar: 'المستودع في أودو', en: 'Warehouse in Odoo' }, width: 'minmax(150px,1fr)', kind: 'name', value: (r) => r.warehouse || '—' },
  { key: 'qty', header: { ar: 'الكمية', en: 'On hand' }, width: '96px', kind: 'num', value: (r) => qty(r.qty) },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '96px', kind: 'num', value: (r) => qty(r.reserved) },
  { key: 'available', header: { ar: 'المتاح', en: 'Available' }, width: '96px', kind: 'num', value: (r) => qty(r.available) },
  // lots and expiry dates only where Odoo tracks them for this product
  { key: 'lots', header: { ar: 'تشغيلات', en: 'Lots' }, width: '80px', kind: 'num', hidden: !stock.value.some((r) => r.lots), value: (r) => qty(r.lots) },
  { key: 'nearestExpiry', header: { ar: 'أقرب انتهاء', en: 'Nearest expiry' }, width: '104px', kind: 'date', hidden: !stock.value.some((r) => r.nearestExpiry), value: (r) => dayText(r.nearestExpiry) || '—' },
]);
/** The product's own record as a document (history, files, every field): the kind the answer names, else the only kind that holds products. */
const recordTo = computed(() => {
  const only = kindsOfModel('product.product');
  const key = ownKind.value && kindOf(ownKind.value) ? ownKind.value : only.length === 1 ? only[0].key : null;
  return key ? nav.docTo(key, props.odooId) : null;
});
/** The list of a kind, filtered by this product: with the query the answer gives for it, else the kind's product filter. */
const listTo = (g) => (g.kind && kindOf(g.kind) ? nav.kindTo(g.kind, g.query || { [entityKey(g.kind, 'product')]: props.odooId }) : null);
const groupLabel = (g) => labelOf(g.label) || labelOf(kindOf(g.kind)) || t('مستندات', 'Documents');
</script>

<template>
  <Drawer :open="isOpen" :width="720" @close="emit('close')">
    <template #title><span dir="auto">{{ name || (odooId != null ? `#${odooId}` : '') }}</span></template>
    <template #sub><span v-if="code" class="num ltr inline-block">{{ code }}</span><template v-if="code"> · </template>{{ t('الصنف كما هو في أودو', 'The product as Odoo holds it') }} · <span class="num ltr inline-block">#{{ odooId }}</span></template>
    <template v-if="d && (d.active === false || d.deleted === true)" #headExtra>
      <Chip v-if="d.deleted === true" small :label="DELETED_CHIP" :fg="DELETED_CHIP.fg" :bg="DELETED_CHIP.bg" />
      <Chip v-else small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
    </template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="col"><div class="skel h-[90px]" /><div class="skel h-[120px]" /><div class="skel h-[160px]" /></div>
    <template v-if="d">
      <div v-if="profile.length" class="kv-grid">
        <KV v-for="(s, i) in profile" :key="s.key || i" :k="labelOf(s) || '—'">
          <ArchiveValue :type="s.type || 'text'" :value="s.value" :link="isObj(s.link) ? s.link : null" :currency="s.currency ?? d.currency" :kind="ownKind" wrap />
        </KV>
      </div>
      <RouterLink v-if="recordTo" :to="recordTo" class="mt-2.5 inline-block text-[10px] font-bold">{{ t('سجل الصنف كاملًا: السجل، المرفقات وكل الحقول', 'The whole product record: history, attachments and every field') }}</RouterLink>

      <div class="mb-1.5 mt-[18px] flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <div class="text-[13px] font-extrabold">{{ t('مخزون أودو حسب المستودع', 'Odoo stock per warehouse') }}</div>
        <span v-if="stock.length" class="text-[10px] text-muted">{{ t('الإجمالي', 'Total') }} <b class="num ltr text-ink">{{ qty(onHand) }}</b></span>
        <span v-if="asOf" class="text-[10px] text-muted" :title="fmtAt(asOf)">{{ t('آخر قراءة من أودو', 'Last read from Odoo') }} <span class="num-mixed">{{ agoAt(asOf) }}</span></span>
      </div>
      <div class="overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="stockColumns" :rows="stock" :row-key="(r) => r.key" dense
                   :empty-text="{ ar: 'لا رصيد لهذا الصنف في مستودعات أودو. تظهر هنا كميته في كل مستودع: الموجود، المحجوز والمتاح.', en: 'Odoo holds no stock of this product. Its quantity per warehouse appears here: on hand, reserved and available.' }" />
      </div>
      <div class="mt-1 text-[9.5px] text-faint">{{ t('هذه أرقام أودو كما نُسخت، وليست مخزون نظام العمليات.', 'These are Odoo’s figures as copied, not the OPS inventory.') }}</div>

      <div class="mb-1 mt-[18px] text-[13px] font-extrabold">{{ t('آخر حركته في الأرشيف', 'Its latest activity in the archive') }}</div>
      <EmptyState v-if="!groups.length" :text="{ ar: 'لا مبيعات ولا مشتريات ولا حركات مخزون لهذا الصنف في الأرشيف. تظهر هنا أحدثها لكل نوع.', en: 'The archive holds no sale, purchase or stock move of this product. The latest of each kind appear here.' }" />
      <div v-for="g in groups" :key="g.key" class="mt-3">
        <div class="mb-1.5 flex flex-wrap items-center gap-2">
          <div class="text-[11.5px] font-extrabold" dir="auto">{{ groupLabel(g) }}</div>
          <span v-if="g.count != null" class="num ltr text-[9.5px] font-bold text-faint">{{ fmtNum(g.count) }}</span>
          <span class="flex-1" />
          <RouterLink v-if="listTo(g)" :to="listTo(g)" class="text-[10px] font-bold">{{ g.count != null ? t(`عرض الكل (${fmtNum(g.count)})`, `Show all (${fmtNum(g.count)})`) : t('عرض الكل', 'Show all') }}</RouterLink>
        </div>
        <ArchiveDocLinks v-if="g.items.length" :kind="g.kind" :items="g.items" />
      </div>
    </template>
  </Drawer>
</template>
