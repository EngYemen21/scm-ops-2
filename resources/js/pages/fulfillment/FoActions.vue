<script setup>
// The corrections and ways back of a fulfillment order, as buttons with their dialogs (FO page, pick panel, packing rows):
//   «ملصقات الكراتين»          one label per carton of a packed order ("1 / N", code as CODE128, customer, stop)
//   «تعديل الطرد»              PATCH /fulfillment/orders/:n/package — cartons / weight / volume before loading (pack.confirm)
//   «فكّ التعبئة»              POST  /fulfillment/orders/:n/unpack  — STG-OUT → PACK, package and cartons void (pack.confirm)
//   «إرجاع التجهيز للمواقع»    POST  /fulfillment/orders/:n/putback — PACK → the bins, order waits again or is cancelled (pick.override)
// A button that cannot work right now stays visible, disabled, and says why (title + the line under the buttons).
//   <FoActions :fo="fo" @changed="refetch" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, PillChoice, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, showLabels, toast } from '@/stores/ui';
import { cartonLabels, custName, labelRanges } from './shared';

const props = defineProps({
  /** Fo (number, status, loaded, cartons, weightKg, cbm, customer; `cartonList` / `stop` when it is the full document). */
  fo: { type: Object, required: true },
  /** Small buttons (inside a table-like row). */
  compact: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const act = useAction();
const INVALIDATE = ['fulfillment', 'sales', 'inventory', 'transport'];
const enc = encodeURIComponent;
const size = computed(() => (props.compact ? 'sm' : 'md'));

const st = computed(() => props.fo.status);
const packedHere = computed(() => st.value === 'packed' && !props.fo.loaded);
const showPackage = computed(() => auth.can('pack.confirm') && ['packed', 'loaded'].includes(st.value));
const showPutback = computed(() => auth.can('pick.override') && ['picking', 'picked', 'packed'].includes(st.value));
const showLabelsBtn = computed(() => ['packed', 'loaded', 'onroute'].includes(st.value));
const packageWhy = computed(() => (packedHere.value ? null : t('الطلب على المركبة — أنزِله أولًا من صفحة التحميل', 'The order is on the truck — unload it first from the loading page')));
const putbackWhy = computed(() => (st.value === 'packed' ? t('الطلب معبّأ — فكّ التعبئة أولًا ثم أرجِعه للمواقع', 'The order is packed — unpack it first, then put it back') : null));
const whys = computed(() => [showPackage.value ? packageWhy.value : null, showPutback.value ? putbackWhy.value : null].filter(Boolean));

// ── carton labels ──
async function printLabels(given) {
  let doc = props.fo;
  let cartons = given || doc.cartonList;
  if (!cartons) { // a list row has no carton list: fetch the document
    doc = await act.run(() => api.get(`/fulfillment/orders/${enc(props.fo.number)}`), { success: false, invalidate: false });
    if (!doc) return;
    cartons = doc.cartonList;
  }
  if (!cartons?.length) { toast.say({ ar: 'لا كراتين مسجّلة لهذا الطلب (عُبّئ قبل ترقيم الكراتين) — عدّل الطرد لإصدارها', en: 'No carton rows for this order (packed before cartons were numbered) — edit the package to issue them' }, 4200); return; }
  const all = cartonLabels({ fo: doc.number, cartons, customer: custName(doc.customer), stop: doc.stop });
  const ranges = labelRanges(all.length);
  if (ranges.length === 1) { showLabels(all, doc.number); return; }
  // more labels than one print job takes: ask which range
  const pick = await ask({ title: { ar: `${all.length} ملصقًا — اختر الدفعة`, en: `${all.length} labels — pick a range` }, label: { ar: `رقم الدفعة (1 – ${ranges.length})، كل دفعة ${ranges[0][1]} ملصقًا`, en: `Range number (1 – ${ranges.length}), ${ranges[0][1]} labels each` }, value: '1', required: true });
  if (pick === null) return;
  const r = ranges[Math.min(ranges.length, Math.max(1, parseInt(pick, 10) || 1)) - 1];
  showLabels(all.slice(r[0] - 1, r[1]), `${doc.number} · ${r[0]}–${r[1]}`);
}

// ── edit package ──
const editOpen = ref(false);
const form = ref({ cartons: null, kg: null, cbm: null, weights: '' });
function openEdit() { form.value = { cartons: props.fo.cartons || 1, kg: props.fo.weightKg || null, cbm: props.fo.cbm || null, weights: '' }; act.clearError(); editOpen.value = true; }
const weightList = computed(() => form.value.weights.split(/[,،\s]+/).map((x) => x.trim()).filter(Boolean).map(Number));
const weightsBad = computed(() => weightList.value.length > 0 && (weightList.value.some((x) => !(x > 0)) || weightList.value.length !== (form.value.cartons || 0)));
async function saveEdit() {
  const f = form.value;
  const body = { cartons: f.cartons || 1, ...(weightList.value.length ? { cartonWeights: weightList.value } : f.kg ? { weightKg: f.kg } : {}), ...(f.cbm ? { volumeM3: f.cbm } : {}) };
  const r = await act.run(() => api.patch(`/fulfillment/orders/${enc(props.fo.number)}/package`, body), { success: (x) => x.message, invalidate: INVALIDATE });
  if (!r) return;
  editOpen.value = false;
  emit('changed');
  if (r.cartonsReissued) printLabels(r.cartonList);
}

// ── unpack ──
async function unpack() {
  const n = props.fo.number;
  const reason = await ask({ title: { ar: `فكّ تعبئة ${n}؟`, en: `Unpack ${n}?` }, sub: { ar: 'تعود الكميات من Staging الصادر إلى محطة التعبئة، ويُبطل الطرد وملصقات كراتينه. بعدها: عبّئ من جديد أو أرجِع التجهيز للمواقع.', en: 'The stock returns from outbound staging to the packing station; the package and its carton labels become void. Then pack again or put the picking back.' }, label: { ar: 'السبب', en: 'Reason' }, required: true, tone: 'danger', okLabel: { ar: 'فكّ التعبئة', en: 'Unpack' } });
  if (reason === null) return;
  const r = await act.run(() => api.postIdempotent(`/fulfillment/orders/${enc(n)}/unpack`, { reason }), { success: (x) => x.message, invalidate: INVALIDATE });
  if (r) emit('changed');
}

// ── put-back ──
const backOpen = ref(false);
const back = ref({ reason: '', then: 'wait', bins: {}, alt: '' });
/** The task the server could not return to its own bin (BIN_UNUSABLE details): the dialog then asks for another bin. */
const stuck = computed(() => (act.error.value?.code === 'BIN_UNUSABLE' ? act.error.value.details : null));
function openBack() { back.value = { reason: '', then: 'wait', bins: {}, alt: '' }; act.clearError(); backOpen.value = true; }
async function putback() {
  const b = back.value;
  if (stuck.value && b.alt.trim()) b.bins = { ...b.bins, [stuck.value.taskId]: b.alt.trim() };
  const body = { reason: b.reason.trim(), cancelOrder: b.then === 'cancel', ...(Object.keys(b.bins).length ? { bins: b.bins } : {}) };
  const r = await act.run(() => api.postIdempotent(`/fulfillment/orders/${enc(props.fo.number)}/putback`, body), { success: (x) => x.message, invalidate: INVALIDATE });
  b.alt = '';
  if (r) { backOpen.value = false; emit('changed'); }
}
</script>

<template>
  <div v-if="showLabelsBtn || showPackage || showPutback" class="row wrap !gap-1.5">
    <Btn v-if="showLabelsBtn" tone="soft" :size="size" :loading="act.pending.value && !editOpen && !backOpen" :label="{ ar: `ملصقات الكراتين${fo.cartons ? ` (${fo.cartons})` : ''}`, en: `Carton labels${fo.cartons ? ` (${fo.cartons})` : ''}` }" @click="printLabels()" />
    <Btn v-if="showPackage" tone="softPurple" :size="size" :disabled="!packedHere" :title="packageWhy" :label="{ ar: 'تعديل الطرد', en: 'Edit package' }" @click="openEdit" />
    <Btn v-if="showPackage" tone="softAmber" :size="size" :disabled="!packedHere" :title="packageWhy" :loading="act.pending.value && !editOpen && !backOpen" :label="{ ar: 'فكّ التعبئة', en: 'Unpack' }" @click="unpack" />
    <Btn v-if="showPutback" tone="softRed" :size="size" :disabled="!!putbackWhy" :title="putbackWhy" :label="{ ar: 'إرجاع التجهيز للمواقع', en: 'Put picking back' }" @click="openBack" />
    <div v-for="w in whys" :key="w" class="faint w-full text-[9px]">{{ w }}</div>
    <div v-if="act.error.value && !editOpen && !backOpen" class="w-full"><ErrorBanner :error="act.error.value" @close="act.clearError()" /></div>

    <Modal :open="editOpen" :width="460" :title="{ ar: `تعديل طرد ${fo.number}`, en: `Edit package of ${fo.number}` }"
           :sub="{ ar: 'قبل التحميل فقط. تغيير عدد الكراتين يُبطل ملصقاتها ويصدر ملصقات جديدة؛ وإن كان الطلب على رحلة تُعاد حساب حمولتها.', en: 'Before loading only. Changing the number of cartons voids their labels and issues new ones; the load of the trip the order is on is recomputed.' }" @close="editOpen = false">
      <div class="form-grid">
        <NumberInput v-model="form.cartons" :min="1" :label="{ ar: 'الكراتين', en: 'Cartons' }" required />
        <NumberInput v-model="form.kg" :min="0" :step="0.1" :disabled="weightList.length > 0" :label="{ ar: 'الوزن الإجمالي كجم', en: 'Total weight kg' }" />
        <NumberInput v-model="form.cbm" :min="0" :step="0.1" :label="{ ar: 'الحجم م³', en: 'Volume m³' }" />
        <TextInput v-model="form.weights" full dir="ltr" :label="{ ar: 'وزن كل كرتون (اختياري)', en: 'Weight per carton (optional)' }" placeholder="12.5, 11, 13"
                   :error="weightsBad ? t('وزن واحد أكبر من صفر لكل كرتون', 'One weight above zero per carton') : null"
                   :hint="{ ar: 'افصل بفاصلة — إن تُرك فارغًا يُقسَّم الوزن الإجمالي بالتساوي', en: 'Comma separated — empty = the total is divided evenly' }" />
      </div>
      <ErrorBanner class="mt-2" :error="act.error.value" @close="act.clearError()" />
      <template #footer>
        <div class="flex gap-2">
          <Btn tone="dark" class="!h-10 flex-1" :loading="act.pending.value" :disabled="weightsBad || !form.cartons" :label="{ ar: 'حفظ التعديل', en: 'Save' }" @click="saveEdit" />
          <Btn tone="soft" class="!h-10 w-[100px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="editOpen = false" />
        </div>
      </template>
    </Modal>

    <Modal :open="backOpen" :width="480" :title="{ ar: `إرجاع تجهيز ${fo.number} للمواقع`, en: `Put the picking of ${fo.number} back` }"
           :sub="{ ar: 'كل ما جُهّز يعود من محطة التعبئة إلى الموقع الذي أُخذ منه، وتُلغى قائمة التجهيز وأمر التنفيذ، ويُفرج عن الحجز.', en: 'Everything picked returns from the packing station to the bin it came from; the pick list and the fulfillment order are cancelled and the reservation is released.' }" @close="backOpen = false">
      <PillChoice v-model="back.then" full required :label="{ ar: 'وأمر البيع؟', en: 'And the sales order?' }"
                  :options="[{ v: 'wait', l: { ar: 'يعود بانتظار التخصيص', en: 'Waits for allocation again' } }, { v: 'cancel', l: { ar: 'يُلغى', en: 'Is cancelled' } }]" />
      <TextArea v-model="back.reason" field-class="mt-2.5" :label="{ ar: 'السبب', en: 'Reason' }" required :rows="2" />
      <ErrorBanner class="mt-2" :error="act.error.value" @close="act.clearError()" />
      <TextInput v-if="stuck" v-model="back.alt" scan full mono dir="ltr" field-class="mt-2" required
                 :label="{ ar: `موقع بديل لإرجاع ${stuck.sku} (${stuck.qty}) بدل ${stuck.bin}`, en: `Another bin for ${stuck.sku} (${stuck.qty}) instead of ${stuck.bin}` }" placeholder="A-01-1-B1" />
      <template #footer>
        <div class="flex gap-2">
          <Btn tone="danger" class="!h-10 flex-1" :loading="act.pending.value" :disabled="!back.reason.trim() || (!!stuck && !back.alt.trim())" :label="{ ar: 'إرجاع للمواقع', en: 'Put back' }" @click="putback" />
          <Btn tone="soft" class="!h-10 w-[100px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="backOpen = false" />
        </div>
      </template>
    </Modal>
  </div>
</template>
