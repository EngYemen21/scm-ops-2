<script setup>
// Trip room · "Stops & map": the map + the stop list.
//  · every stop shows the customer's WINDOW (the promise, from the sales order) and the PLANNED arrival (the plan of this
//    trip: maps or estimate) as two separate things; a planned arrival outside the window is flagged;
//  · while the trip is being planned (trip.manage): ▲ ▼ change the visiting order (POST …/stops/reorder — the arrivals
//    are re-planned, the windows never change) and "add order" puts another packed order on the trip;
//  · while planning or loading: an order that is not loaded can be taken off the trip (POST …/orders/:fo/remove);
//  · a closed stop shows its POD with the stored signature / photo.
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { api, useAction } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, Modal, TextInput } from '@/components';
import { bi, fmtDate, fmtNum, fmtTime, lang, t } from '@/i18n';
import { FAIL_REASON_LABELS, STOP_LABELS } from '@/shared';
import { isMobile } from '@/composables/viewport';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import FoPicker from './FoPicker.vue';
import PodFiles from './PodFiles.vue';
import TripMap from './TripMap.vue';
import { CLOSED_STOP, ETA_SOURCE_LABELS, NO_SCAN_REASON_LABELS, STOP_DOT, labelOf } from './tms';

const props = defineProps({ trip: { type: Object, required: true } });

const auth = useAuth();
const act = useAction();
const stops = computed(() => props.trip.stops || []);
const canManage = computed(() => auth.can('trip.manage'));
const canReorder = computed(() => canManage.value && !!props.trip.canEdit && stops.value.length > 1);
const canAdd = computed(() => canManage.value && !!props.trip.canEdit);
const lateCount = computed(() => stops.value.filter((s) => !CLOSED_STOP.includes(s.status) && s.windowOk === false).length);
const hasPlan = computed(() => stops.value.some((s) => s.eta));
const base = computed(() => `/transport/trips/${encodeURIComponent(props.trip.number)}`);

const actualTime = (s) => s.actualTime || (s.arrivedAt ? fmtTime(s.arrivedAt) : null);
/** Why an order cannot be taken off the trip — null when it can. */
function removeBlock(s) {
  if (!s.fo?.number) return t('المحطة بلا طلب', 'The stop has no order');
  if (s.fo.loaded) return t('الطلب محمَّل — أنزله أولًا من لوحة التحميل', 'The order is loaded — unload it first from the loading board');
  if (!props.trip.canRemoveOrders) return stops.value.length <= 1 ? t('آخر طلب في الرحلة — ألغِ الرحلة بدلًا من إزالته', 'Last order of the trip — cancel the trip instead') : t('لا تُزال الطلبات في هذه المرحلة', 'Orders cannot be removed at this stage');
  return null;
}
const showRemove = computed(() => canManage.value && (!!props.trip.canEdit || !!props.trip.canAssignDriver));

function move(index, by) {
  const ids = stops.value.map((s) => s.id);
  const to = index + by;
  if (to < 0 || to >= ids.length) return;
  [ids[index], ids[to]] = [ids[to], ids[index]];
  return act.run(() => api.postIdempotent(`${base.value}/stops/reorder`, { stopIds: ids }), { success: () => t('أُعيد ترتيب المحطات — حُدّثت أوقات الوصول المخططة', 'Stops reordered — planned arrivals updated'), invalidate: ['transport'] });
}
async function removeOrder(s) {
  const reason = await ask({ title: { ar: `إزالة ${s.fo.number} من الرحلة؟`, en: `Remove ${s.fo.number} from the trip?` }, sub: { ar: 'تُحذف محطته ويُعاد ترقيم المحطات وتُحدَّث الحمولة وأوقات الوصول. الطلب يبقى مجهّزًا ومتاحًا لرحلة أخرى.', en: 'Its stop is deleted, the stops are renumbered and the load and arrivals are updated. The order stays packed, free for another trip.' }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'إزالة من الرحلة', en: 'Remove from trip' }, cancelLabel: { ar: 'تراجع', en: 'Back' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`${base.value}/orders/${encodeURIComponent(s.fo.number)}/remove`, { reason: reason || undefined }), { success: () => t(`أُزيل ${s.fo.number} من الرحلة`, `${s.fo.number} removed from the trip`), invalidate: ['transport', 'fulfillment'] });
}

// ── add orders (planning stage)
const addOpen = ref(false);
const addCode = ref('');
const picked = ref([]);
const addAct = useAction();
function openAdd() { addCode.value = ''; picked.value = []; addAct.clearError(); addOpen.value = true; }
const toAdd = computed(() => [...new Set([...picked.value, ...(String(addCode.value || '').trim() ? [String(addCode.value).trim()] : [])])]);
async function addOrders() {
  // one request per order: each is checked on its own (state, warehouse, other trip, vehicle capacity) and the first refusal stops the rest
  for (const number of toAdd.value) {
    const r = await addAct.run(() => api.postIdempotent(`${base.value}/orders/add`, { foNumber: number }), { success: () => t(`أُضيف ${number} إلى الرحلة`, `${number} added to the trip`), invalidate: ['transport', 'fulfillment'] });
    if (r === undefined) return;
    picked.value = picked.value.filter((n) => n !== number);
    if (String(addCode.value).trim() === number) addCode.value = '';
  }
  addOpen.value = false;
}
</script>

<template>
  <div>
    <TripMap :number="trip.number" :height="isMobile ? 240 : 300" />

    <div class="row wrap mt-3 !gap-2 text-[10px]">
      <span v-if="hasPlan" class="text-muted">{{ t('الوصول المخطط: من وقت الانطلاق وزمن القيادة بين المحطات (خرائط أو تقدير) ووقت الخدمة في كل محطة — خطة لا تتبّع حي. نافذة العميل وعد لا يغيّره الترتيب.', 'Planned arrival: from the planned start, the drive time between stops (maps or estimate) and the service time at each stop — a plan, not live tracking. The customer window is a promise the order of stops never changes.') }}</span>
      <span v-else-if="stops.length > 0" class="text-warn">{{ t('لا وصول مخطط: حدّد موقع المستودع والعملاء على الخريطة ليُحسب.', 'No planned arrival: set the warehouse and customer locations on the map to compute it.') }}</span>
      <span class="flex-1" />
      <Btn v-if="canAdd" tone="softPurple" size="sm" :label="{ ar: '+ إضافة طلب', en: '+ Add order' }" @click="openAdd" />
    </div>
    <div v-if="lateCount > 0" class="mt-2 rounded-[10px] bg-[#fdecec] px-3 py-2 text-[10.5px] font-bold text-bad">
      ⚠ {{ t(`${lateCount} محطة وصولها المخطط خارج نافذة العميل`, `${lateCount} stop(s) are planned outside the customer window`) }}<template v-if="canReorder"> — {{ t('غيّر الترتيب بالأسهم أو عدّل وقت الانطلاق.', 'change the order with the arrows or move the planned start.') }}</template>
    </div>
    <ErrorBanner :error="act.error.value" class="mt-2" @close="act.clearError()" />

    <div class="col mt-3">
      <EmptyState v-if="stops.length === 0" tone="dashed" :text="{ ar: 'لا محطات على هذه الرحلة', en: 'No stops on this trip' }" />
      <div v-for="(s, i) in stops" :key="s.id" class="card sm px-[15px] py-3">
        <div class="row wrap !gap-2.5">
          <div v-if="canReorder" class="col flex-none !gap-0.5">
            <button type="button" class="btn ghost sm !h-[18px] !px-1.5 !py-0 !text-[9px]" :disabled="i === 0 || act.pending.value" :title="i === 0 ? t('أول محطة', 'First stop') : t('تقديم المحطة', 'Move earlier')" :aria-label="t('تقديم المحطة', 'Move earlier')" @click="move(i, -1)">▲</button>
            <button type="button" class="btn ghost sm !h-[18px] !px-1.5 !py-0 !text-[9px]" :disabled="i === stops.length - 1 || act.pending.value" :title="i === stops.length - 1 ? t('آخر محطة', 'Last stop') : t('تأخير المحطة', 'Move later')" :aria-label="t('تأخير المحطة', 'Move later')" @click="move(i, 1)">▼</button>
          </div>
          <div class="num flex h-[26px] w-[26px] flex-none items-center justify-center rounded-full text-[10px] font-bold text-white" :style="{ background: STOP_DOT[s.status] || '#a8a4b8' }">{{ s.seq }}</div>
          <div class="min-w-[150px] flex-1">
            <div class="text-[11.5px] font-extrabold">{{ (lang === 'en' && s.customerEn) || s.customerAr || s.customer?.nameAr }}</div>
            <div class="num mt-px text-[8.5px] text-faint">
              <template v-if="s.transfer?.number">{{ t('تحويل مخزني', 'Warehouse transfer') }} <RouterLink :to="`/trf/${encodeURIComponent(s.transfer.number)}`" class="cell-id">{{ s.transfer.number }}</RouterLink> · {{ t('يُقفل باستلام مستودع الوجهة', 'closed by the destination receipt') }}</template>
              <RouterLink v-if="s.fo?.number" :to="`/fo/${encodeURIComponent(s.fo.number)}`" class="cell-id">{{ s.fo.number }}</RouterLink>{{ s.invoice ? ` · ${s.invoice}` : '' }}{{ s.fo?.status ? ` · ${s.fo.status}` : '' }}{{ s.fo?.loaded ? ` · ${t('محمَّل', 'loaded')}` : '' }}
            </div>
          </div>
          <div v-if="actualTime(s)" class="text-[9.5px]"><span class="font-extrabold text-faint">{{ t('الفعلي', 'Actual') }}</span> <span dir="ltr" class="num font-bold text-brand-dark">{{ actualTime(s) }}</span></div>
          <Chip :map="STOP_LABELS" :k="s.status" />
          <Btn v-if="showRemove && !CLOSED_STOP.includes(s.status)" tone="softRed" size="sm" :disabled="!!removeBlock(s) || act.pending.value" :title="removeBlock(s) || t('إزالة الطلب من الرحلة', 'Remove the order from the trip')" :label="{ ar: 'إزالة', en: 'Remove' }" @click="removeOrder(s)" />
        </div>
        <div class="row wrap mt-2 !gap-3.5 text-[9.5px] text-sec">
          <div><span class="font-extrabold text-faint">{{ t('نافذة العميل', 'Customer window') }}</span> <span dir="ltr" class="num font-bold">{{ s.window || '—' }}</span></div>
          <div>
            <span class="font-extrabold text-faint">{{ t('الوصول المخطط', 'Planned arrival') }}</span>
            <span dir="ltr" class="num font-extrabold" :class="s.windowOk === false ? 'text-bad' : 'text-brand-dark'"> {{ s.eta || '—' }}</span>
            <span v-if="s.eta && s.etaSource" class="text-faint"> ({{ bi(labelOf(ETA_SOURCE_LABELS, s.etaSource)) }})</span>
            <span v-if="s.windowOk === false" class="font-extrabold text-bad"> · {{ t('خارج النافذة', 'outside the window') }}</span>
            <span v-else-if="s.windowOk === true" class="font-bold text-ok"> ✓</span>
          </div>
          <div><span class="font-extrabold text-faint">{{ t('الأصناف', 'Items') }}</span> <span class="num">{{ fmtNum(s.items) }}</span>{{ s.fo?.cartons != null ? ` · ${fmtNum(s.fo.cartons)} ${t('كرتون', 'ctn')}` : '' }}</div>
          <div><span class="font-extrabold text-faint">{{ t('كجم', 'kg') }}</span> <span class="num">{{ fmtNum(s.kg || s.fo?.weightKg, 1) }}</span></div>
          <div><span class="font-extrabold text-faint">{{ t('جهة الاتصال', 'Contact') }}</span> {{ s.contact || s.customer?.contact || '—' }}</div>
          <div v-if="s.address || s.customer?.address"><span class="font-extrabold text-faint">{{ t('العنوان', 'Address') }}</span> {{ s.address || s.customer?.address }}</div>
        </div>
        <div v-if="showRemove && !CLOSED_STOP.includes(s.status) && removeBlock(s)" class="mt-1 text-[9px] text-faint">{{ t('الإزالة غير متاحة', 'Remove is not available') }}: {{ removeBlock(s) }}</div>
        <div v-if="s.note" class="hint amber !mt-[7px] !px-2.5 !py-[5px] !text-[9.5px]">{{ s.note }}</div>
        <div v-if="s.pod" class="hint green !mt-[7px] !px-2.5 !py-[5px] !text-[9.5px] font-bold">
          POD <span class="num">{{ s.pod.number }}</span> · {{ bi(labelOf(STOP_LABELS, s.pod.result)) }} · <span class="num">{{ fmtDate(s.pod.at) }}</span><template v-if="s.pod.receiverName"> · {{ t('المستلم', 'Receiver') }}: {{ s.pod.receiverName }}</template>
          <template v-if="s.pod.deliveredQty != null"> · {{ t('مسلّم', 'delivered') }} <span class="num">{{ fmtNum(s.pod.deliveredQty) }}</span><template v-if="s.pod.returnedQty"> / {{ t('مرتجع', 'returned') }} <span class="num">{{ fmtNum(s.pod.returnedQty) }}</span></template></template>
          <span v-if="s.pod.scanOk === true" class="text-ok"> · {{ t('مسح ✓', 'scanned ✓') }}</span>
          <span v-else-if="s.pod.noScanReason" class="text-warn"> · {{ t('بلا مسح', 'no scan') }}: {{ bi(labelOf(NO_SCAN_REASON_LABELS, s.pod.noScanReason)) }}</span>
          <div v-if="(s.pod.attachments || []).length > 0" class="mt-1"><PodFiles :attachments="s.pod.attachments" /></div>
        </div>
        <div v-if="s.failReason" class="hint !mt-[7px] !border-0 !bg-bad-soft !px-2.5 !py-[5px] !text-[9.5px] font-bold !text-bad">{{ t('سبب الفشل', 'Fail reason') }}: {{ bi(labelOf(FAIL_REASON_LABELS, s.failReason)) }}</div>
      </div>
    </div>

    <!-- teleported: the dialog must not be confined to the trip drawer it is opened from -->
    <Teleport to="body">
      <Modal :open="addOpen" :width="560" :z-index="90" :title="{ ar: `إضافة طلبات إلى ${trip.number}`, en: `Add orders to ${trip.number}` }" :sub="{ ar: 'تُضاف كل محطة في آخر المسار، وتُفحص كما عند إنشاء الرحلة: طلب مجهّز أو مُلتقط، من مستودع الرحلة، وغير مرتبط برحلة أخرى، والمركبة المسندة تتحمله.', en: 'Each order becomes the last stop and is checked as at creation: packed or picked, from the trip warehouse, on no other trip, and the assigned vehicle must fit it.' }" @close="addOpen = false">
        <ErrorBanner :error="addAct.error.value" @close="addAct.clearError()" />
        <TextInput v-model="addCode" scan dir="ltr" mono :label="{ ar: 'رقم الطلب (امسح أو اكتب)', en: 'Order number (scan or type)' }" :placeholder="{ ar: 'FO-…', en: 'FO-…' }" @enter="addOrders" />
        <div v-if="addOpen" class="mt-3"><FoPicker v-model="picked" :warehouse="trip.warehouse?.code" /></div>
        <template #footer>
          <div class="row justify-end">
            <Btn tone="ghost" :label="{ ar: 'إغلاق', en: 'Close' }" @click="addOpen = false" />
            <Btn tone="dark" :loading="addAct.pending.value" :disabled="toAdd.length === 0" :label="{ ar: `إضافة (${toAdd.length})`, en: `Add (${toAdd.length})` }" @click="addOrders" />
          </div>
        </template>
      </Modal>
    </Teleport>
  </div>
</template>
