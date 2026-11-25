<script setup>
// One favourite list: its header, its products — read a page at a time, in the list's own order — and what is done
// to it: add a product, change a usual quantity, move a product up, down or to a place, take one off; edit the header,
// archive / restore; and "order again" (a draft quotation in Sales).
//   <FavoriteListDrawer :id="idOrNull" @close="…" @changed="…" />
// GET /portal/favorites/lists/{id} → header + { events, can };  GET …/items?page&pageSize&q → the page of items.
// The page of items and the search inside the list live in the query string (`ipage`, `iq`). The buttons follow `can`.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, NumberInput, TextInput, Timeline } from '@/components';
import { fmtNum, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import FavoriteListForm from './FavoriteListForm.vue';
import OrderAgainDrawer from './OrderAgainDrawer.vue';
import PartyName from './PartyName.vue';
import ProductPick from './ProductPick.vue';
import { ACTIVE_LABELS, OWNED_CHIP, customerLine, eventItem, moment, nameOf, usePortalNav, useSearchParam } from './portal';

const props = defineProps({
  /** Id of the list; null closes the drawer. */
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'changed']);

const auth = useAuth();
const nav = usePortalNav();
const canManage = computed(() => auth.can('portal.manage'));
const base = computed(() => (props.id ? `/portal/favorites/lists/${encodeURIComponent(props.id)}` : null));
const q = useGet(base);
const l = computed(() => (props.id ? q.data.value || null : null));
const act = useAction({ invalidate: ['portal'] });

// ---- the items, a page at a time
const PAGE_SIZE = 50;
const ipage = computed(() => Math.max(1, Number(nav.get('ipage')) || 1));
const { text: iqText, type: iqType, apply: iqApply } = useSearchParam(nav, 'iq', 'ipage');
const items = useList(() => (base.value ? `${base.value}/items` : null), () => ({ page: ipage.value, pageSize: PAGE_SIZE, q: nav.get('iq') }));
const editable = computed(() => canManage.value && !!l.value?.can.items);
const columns = computed(() => [
  { key: 'position', header: '#', width: '44px', kind: 'num', value: (r) => (r.position == null ? '·' : fmtNum(r.position)) },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.6fr)' },
  { key: 'qty', header: { ar: 'الكمية المعتادة', en: 'Usual quantity' }, width: editable.value ? '128px' : '92px' },
  { key: 'actions', header: '', width: '132px', bare: true, hidden: !editable.value },
]);

/** Quantities typed and not saved yet: item id → number. */
const draft = ref({});
watch(() => props.id, () => { draft.value = {}; adding.value = { sku: '', qty: 1 }; });
const changed = (row) => draft.value[row.id] != null && num(draft.value[row.id]) > 0 && num(draft.value[row.id]) !== num(row.qty);
function done() { q.refetch(); items.refetch(); emit('changed'); }

async function saveQty(row) {
  const r = await act.run(() => api.patch(`${base.value}/items/${row.id}`, { qty: num(draft.value[row.id]) }), { success: t('حُفظت الكمية', 'Quantity saved') });
  if (r) { const d = { ...draft.value }; delete d[row.id]; draft.value = d; done(); }
}
async function move(row, position) {
  const r = await act.run(() => api.postIdempotent(`${base.value}/items/${row.id}/move`, { position }), { success: false });
  if (r) done();
}
async function moveTo(row) {
  const answer = await ask({ title: { ar: `نقل «${nameOf(row.product)}» إلى أي موضع؟`, en: `Move “${nameOf(row.product)}” to which place?` }, label: { ar: `الموضع (1 – ${l.value.itemsCount})`, en: `Place (1 – ${l.value.itemsCount})` },
    value: String(row.position || ''), required: true, okLabel: { ar: 'نقل', en: 'Move' } });
  const position = Math.floor(Number(answer));
  if (answer === null || !(position >= 1)) return;
  await move(row, position);
}
async function remove(row) {
  if (!(await confirm({ title: { ar: `إزالة «${nameOf(row.product)}» من القائمة؟`, en: `Take “${nameOf(row.product)}” off the list?` }, sub: { ar: 'يبقى في سجل القائمة من أزاله ومتى.', en: 'The list’s history keeps who took it off and when.' },
    okLabel: { ar: 'إزالة', en: 'Remove' }, tone: 'danger' }))) return;
  const r = await act.run(() => api.del(`${base.value}/items/${row.id}`), { success: t('أُزيل المنتج من القائمة', 'Product taken off the list') });
  if (r !== undefined) done();
}

// ---- add a product
const adding = ref({ sku: '', qty: 1 });
async function add() {
  const r = await act.run(() => api.postIdempotent(`${base.value}/items`, { sku: adding.value.sku.trim(), qty: num(adding.value.qty) || 1 }), { success: t('أُضيف المنتج إلى آخر القائمة', 'Product added at the end of the list') });
  if (r) { adding.value = { sku: '', qty: 1 }; done(); }
}

// ---- the list itself
const editing = ref(false);
const ordering = ref(false);
async function archive() {
  const reason = await ask({ title: { ar: `أرشفة القائمة «${l.value.name}»؟`, en: `Archive list “${l.value.name}”?` }, sub: { ar: 'تبقى القائمة بمنتجاتها وسجلها، ولا تُعرض بين القوائم النشطة.', en: 'The list stays with its products and history, and leaves the active lists.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'أرشفة', en: 'Archive' }, tone: 'danger' });
  if (reason === null) return;
  const r = await act.run(() => api.postIdempotent(`${base.value}/archive`, reason ? { reason } : {}), { success: t('أُرشفت القائمة', 'List archived') });
  if (r) done();
}
async function restore() {
  const r = await act.run(() => api.postIdempotent(`${base.value}/restore`), { success: t('استُعيدت القائمة', 'List restored') });
  if (r) done();
}
const events = computed(() => (l.value?.events || []).map(eventItem));
const canQuote = computed(() => auth.can('sales.manage'));
</script>

<template>
  <Drawer :open="!!id" :width="680" @close="emit('close')">
    <template #title><span dir="auto">{{ l?.name || t('قائمة مفضلة', 'Favourite list') }}</span></template>
    <template v-if="l" #sub><span dir="auto">{{ customerLine(l.customer) }}<template v-if="l.branch"> · {{ t('فرع', 'branch') }} {{ l.branch.name }}</template></span></template>
    <template v-if="l" #headExtra>
      <div class="row !gap-1">
        <Chip small :map="ACTIVE_LABELS" :k="l.active ? 'active' : 'archived'" />
        <Chip v-if="l.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value || items.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[160px]" />
    <template v-if="l">
      <div v-if="l.owned" class="hint purple !mt-0 mb-3">{{ t('هذه القائمة تُدار من أودو: اسمها ومنتجاتها وكمياتها تُعدَّل هناك. يُضاف هنا الفرع الذي تخصه والملاحظات، ويُنشأ منها عرض سعر.', 'This list is managed in Odoo: its name, products and quantities are changed there. The branch it is kept for and notes are added here, and a quotation can be made from it.') }}</div>
      <div v-if="l.customer && !l.customer.linked" class="hint amber !mt-0 mb-3">{{ t('صاحب هذه القائمة في أودو ليس عميلًا في نظام العمليات بعد: لا يُسعَّر له قبل ربطه بعميل.', 'The owner of this list is not an OPS customer yet: it cannot be quoted before it is linked to one.') }}</div>

      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'العميل', en: 'Customer' }"><PartyName :party="l.customer" /></KV>
        <KV :k="{ ar: 'الفرع', en: 'Branch' }" :v="l.branch ? l.branch.name : t('كل فروع العميل', 'The whole customer')" />
        <KV :k="{ ar: 'عدد المنتجات', en: 'Products' }" :v="fmtNum(l.itemsCount)" ltr />
        <KV :k="{ ar: 'آخر تعديل', en: 'Last change' }" :v="moment(l.updatedAt || l.createdAt)" ltr />
        <KV v-if="l.notes" class="col-span-full" :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="l.notes" />
      </div>

      <div class="row wrap mb-2">
        <TextInput small type="search" field-class="min-w-[180px] flex-1" :model-value="iqText" :placeholder="{ ar: 'بحث عن منتج داخل القائمة…', en: 'Find a product on the list…' }" @update:model-value="iqType" @enter="iqApply" />
      </div>
      <div class="overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="columns" :paged="items.data.value" :loading="items.isFetching.value" :row-key="(r) => r.id" dense
                   :empty-text="nav.get('iq') ? { ar: 'لا منتج مطابق في هذه القائمة.', en: 'No matching product on this list.' } : { ar: 'القائمة فارغة.', en: 'The list is empty.' }" @page="(p) => nav.patch({ ipage: p > 1 ? p : null })">
          <template #cell-product="{ row }">
            <PartyName :party="row.product" product />
          </template>
          <template #cell-qty="{ row }">
            <div v-if="editable" class="flex items-center gap-1">
              <NumberInput small center :model-value="draft[row.id] ?? num(row.qty)" :min="0" :label="null" @update:model-value="(v) => (draft = { ...draft, [row.id]: v })" @enter="changed(row) && saveQty(row)" />
              <button v-if="changed(row)" type="button" class="x-btn !h-7 !w-7 !text-[11px] !text-ok" :title="t('حفظ الكمية', 'Save the quantity')" @click="saveQty(row)">✓</button>
            </div>
            <span v-else class="num">{{ row.qty }}</span>
            <span v-if="row.product?.uom" class="ms-1 text-[9px] text-faint">{{ row.product.uom }}</span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex items-center justify-center gap-1">
              <template v-if="row.position != null">
                <button type="button" class="x-btn !h-7 !w-7 !text-[10px]" :disabled="row.position <= 1 || act.pending.value" :title="t('أعلى', 'Up')" @click="move(row, row.position - 1)">▲</button>
                <button type="button" class="x-btn !h-7 !w-7 !text-[10px]" :disabled="row.position >= l.itemsCount || act.pending.value" :title="t('أسفل', 'Down')" @click="move(row, row.position + 1)">▼</button>
                <button type="button" class="x-btn !h-7 !w-7 !text-[10px]" :disabled="act.pending.value" :title="t('نقل إلى موضع…', 'Move to a place…')" @click="moveTo(row)">⇅</button>
              </template>
              <button type="button" class="x-btn !h-7 !w-7 !text-[11px] !text-bad" :disabled="act.pending.value" :title="t('إزالة من القائمة', 'Take off the list')" @click="remove(row)">✕</button>
            </div>
          </template>
        </DataTable>
      </div>
      <div v-if="nav.get('iq')" class="mt-1 text-[9.5px] text-faint">{{ t('الترتيب يُعدَّل من القائمة كاملة: أزل البحث لتظهر مواضع المنتجات.', 'The order is changed on the whole list: clear the search to see the places.') }}</div>

      <div v-if="editable" class="mt-3 rounded-xl border border-dashed border-line p-3">
        <div class="field-l mb-1.5">{{ t('إضافة منتج إلى القائمة', 'Add a product to the list') }}</div>
        <div class="row wrap !items-start !gap-2">
          <div class="min-w-[200px] flex-1"><ProductPick small :value="adding.sku" @pick="(p, sku) => (adding = { ...adding, sku })" /></div>
          <NumberInput v-model="adding.qty" small center field-class="w-[96px]" :min="0" :placeholder="{ ar: 'الكمية', en: 'Qty' }" />
          <Btn size="sm" tone="softPurple" :disabled="!adding.sku.trim() || !(num(adding.qty) > 0)" :loading="act.pending.value" :label="{ ar: 'إضافة', en: 'Add' }" @click="add" />
        </div>
      </div>

      <div class="card-title mb-1 mt-4">{{ t('السجل', 'History') }}</div>
      <Timeline :items="events" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }" />
    </template>

    <template v-if="l" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="canManage && canQuote && l.can.orderAgain" tone="primary" :label="{ ar: 'اطلب مجددًا — عرض سعر', en: 'Order again — quotation' }" @click="ordering = true" />
        <Btn v-if="canManage && (l.can.edit || l.can.assign)" tone="dark" :label="{ ar: 'تعديل بيانات القائمة', en: 'Edit the list' }" @click="editing = true" />
        <Btn v-if="canManage && l.can.archive" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
        <Btn v-if="canManage && l.can.restore" tone="success" :loading="act.pending.value" :label="{ ar: 'استعادة', en: 'Restore' }" @click="restore" />
      </div>
      <div v-if="canManage && l.can.orderAgain && !canQuote" class="mt-2 text-[10px] font-bold text-warn">{{ t('«اطلب مجددًا» ينشئ عرض سعر في المبيعات ويحتاج صلاحية إدارة المبيعات.', '“Order again” makes a quotation in Sales and needs the sales permission.') }}</div>
    </template>
  </Drawer>

  <FavoriteListForm :open="editing" :list="l" @close="editing = false" @done="() => { editing = false; done(); }" />
  <OrderAgainDrawer :open="ordering" :list-id="id" @close="ordering = false" @done="done" />
</template>
