<script setup>
// One inbound shipment, with EVERY action of its lifecycle in one place (receiving drawer + /shipments/:number):
//   expected   → register the truck's arrival (carrier) · cancel the shipment
//   arrived    → start the inspection
//   inspecting → scan products / fill quantities per line → post the GRN
//   putaway    → go to its putaway tasks
//   done / cancelled / partially received → open the follow-up shipment for what is still due on the purchase order
// The "next step" box always says what happens next and who may do it, so a missing button is never a mystery.
//   <ShipmentPanel :shipment="s" closable @changed="refetch" @grn-posted="refetch" @close="sel = null" @open="(n) => …" />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Btn, Chip, DateInput, ErrorBanner, ScanInput, SectionCard, Stepper, TextInput } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { SHIPMENT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm, showLabel, toast } from '@/stores/ui';
import ReceiveLinesGrid from './ReceiveLinesGrid.vue';
import { SHIPMENT_STEPS, draftLines, draftSum, emptyDraft, pn, shipmentStep, useShipmentActions } from './shared';

const props = defineProps({
  shipment: { type: Object, required: true },
  /** Shows the ✕ button (emits `close`). */
  closable: { type: Boolean, default: false },
});
const emit = defineEmits(['changed', 'close', 'grn-posted', 'open']);

const auth = useAuth();
const router = useRouter();
const { act, arrive, inspect, postGrn, cancel, backorder } = useShipmentActions((s) => emit('changed', s));

const drafts = ref({});
const carrier = ref('');
const notes = ref('');
const eta = ref('');
const postedGrn = ref(null);
watch(() => props.shipment.id, () => { drafts.value = {}; postedGrn.value = null; carrier.value = ''; notes.value = ''; eta.value = ''; act.clearError(); });

const S = computed(() => props.shipment.status);
const editable = computed(() => S.value === 'inspecting' && auth.can('grn.post'));
/** Every line has an accepted quantity and nothing exceeds the open quantity. */
const ready = computed(() => editable.value && props.shipment.lines.every((l) => { const d = drafts.value[l.lineNo]; return d && d.acceptedQty != null && draftSum(d) <= l.openQty; }));
const entered = computed(() => props.shipment.lines.filter((l) => drafts.value[l.lineNo]?.acceptedQty != null).length);
const bo = computed(() => props.shipment.backorder || null);
/** The follow-up shipment matters once this one no longer waits for goods. */
const showBackorder = computed(() => !!bo.value && bo.value.openQty > 0 && ['putaway', 'done', 'cancelled'].includes(S.value));

/** What the next step is, and whether this user may take it. */
const next = computed(() => {
  const s = props.shipment;
  if (S.value === 'expected') {
    return s.locked
      ? { tone: 'neutral', title: t('بانتظار اعتماد أمر الشراء', 'Waiting for the purchase order to be approved'), text: t('تُفتح الشحنة للاستلام تلقائيًا عند اكتمال اعتماد أمر الشراء.', 'The shipment opens for receiving once the purchase order is fully approved.') }
      : { tone: 'teal', title: t('الخطوة التالية: تسجيل وصول الشاحنة', 'Next: register the truck’s arrival'), text: t('سجّل الوصول عند البوابة (الناقل / رقم الشاحنة اختياري) ليبدأ الفحص.', 'Register the arrival at the gate (carrier / truck number optional) so inspection can start.'), perm: 'shipment.receive' };
  }
  if (S.value === 'arrived') return { tone: 'teal', title: t('الخطوة التالية: بدء الفحص', 'Next: start the inspection'), text: t('افتح الفحص لإدخال الكميات السليمة والتالفة والمرفوضة لكل سطر.', 'Open the inspection to enter accepted, damaged and rejected quantities per line.'), perm: 'shipment.receive' };
  if (S.value === 'inspecting') return { tone: 'amber', title: t('الخطوة التالية: إدخال الكميات وإصدار GRN', 'Next: enter quantities and post the GRN'), text: t('امسح باركود المنتج لتعبئة سطره، أو أدخل الكميات يدويًا. التالف والمرفوض لا يدخلان المخزون المتاح، ولا يُقبل ما يزيد على المتبقي.', 'Scan a product to fill its line, or type the quantities. Damaged and rejected goods never enter available stock, and nothing above the open quantity is accepted.'), perm: 'grn.post' };
  if (S.value === 'putaway') return { tone: 'purple', title: t('الخطوة التالية: التخزين Putaway', 'Next: putaway'), text: t('البضاعة السليمة في منطقة الاستلام. أكّد مهام التخزين بمسح الموقع والمنتج لتصبح متاحة للبيع.', 'Accepted goods are in inbound staging. Confirm the putaway tasks (scan bin + product) to make them available.') };
  if (S.value === 'done') return { tone: 'green', title: t('اكتملت الشحنة ✓', 'Shipment completed ✓'), text: t('استُلمت وخُزّنت بالكامل.', 'Received and put away.') };
  return { tone: 'red', title: t('الشحنة ملغاة', 'Shipment cancelled'), text: t('لا يمكن الاستلام على شحنة ملغاة.', 'A cancelled shipment cannot be received.') };
});
const allowed = computed(() => !next.value.perm || auth.can(next.value.perm));

function fillAll() {
  const d = {};
  props.shipment.lines.forEach((l) => { d[l.lineNo] = { ...(drafts.value[l.lineNo] || emptyDraft(l)), acceptedQty: l.openQty }; });
  drafts.value = d;
}
/** A scanned (or typed) product barcode / SKU fills that line with its open quantity. */
function onScanProduct(code) {
  const c = code.trim();
  const l = props.shipment.lines.find((x) => x.product.sku.toUpperCase() === c.toUpperCase() || x.product.barcodes?.some((b) => b.barcode === c));
  if (!l) { toast.say(t(`${c} ليس ضمن أصناف هذه الشحنة`, `${c} is not on this shipment`), 3500); return; }
  drafts.value = { ...drafts.value, [l.lineNo]: { ...(drafts.value[l.lineNo] || emptyDraft(l)), acceptedQty: l.openQty } };
  toast.say(t(`السطر ${l.lineNo}: ${pn(l.product)} — ${fmtNum(l.openQty)}`, `Line ${l.lineNo}: ${pn(l.product)} — ${fmtNum(l.openQty)}`));
}
async function doPost() {
  const g = await postGrn(props.shipment.number, draftLines(props.shipment.lines, drafts.value), notes.value);
  if (g) { postedGrn.value = g; emit('grn-posted', g); }
}
async function doCancel() {
  const n = props.shipment.number;
  if (!(await confirm({ title: { ar: `إلغاء الشحنة ${n}؟`, en: `Cancel shipment ${n}?` }, sub: { ar: 'لن يمكن الاستلام عليها بعد الإلغاء. يبقى أمر الشراء مفتوحًا ويمكن فتح شحنة جديدة للكمية نفسها.', en: 'It can no longer be received. The purchase order stays open and a new shipment can be opened for the same quantity.' }, tone: 'danger', okLabel: { ar: 'إلغاء الشحنة', en: 'Cancel shipment' }, cancelLabel: { ar: 'تراجع', en: 'Back' } }))) return;
  await cancel(n);
}
async function doBackorder() {
  const s = await backorder(props.shipment.po.number, eta.value);
  if (s?.number) emit('open', s.number);
}
const goPutaway = () => router.push(`/receiving?tab=putaway&grn=${encodeURIComponent(props.shipment.grns[0]?.number || '')}`);
</script>

<template>
  <SectionCard class="mt-3.5">
    <div class="pt-4">
      <!-- identity -->
      <div class="row wrap !gap-2.5">
        <div class="num-mixed text-[13.5px] font-extrabold">{{ shipment.number }} <span class="num text-[10px] text-faint">· <RouterLink :to="`/po/${encodeURIComponent(shipment.po.number)}`" class="!text-violet">{{ shipment.po.number }}</RouterLink></span></div>
        <Chip :map="SHIPMENT_LABELS" :k="S" />
        <Chip v-if="shipment.locked" fg="#7d7990" bg="#F1EFF6" :label="{ ar: 'بانتظار اعتماد PO', en: 'Awaiting PO approval' }" />
        <span class="text-[10.5px] font-extrabold">{{ pn(shipment.supplier) }}</span>
        <span class="muted text-[10px]">{{ shipment.warehouse.code }} · {{ pn(shipment.warehouse) }}</span>
        <span class="cell-date ltr">ETA {{ fmtDateOnly(shipment.eta) }}</span>
        <span v-if="shipment.arrivedAt" class="cell-date ltr">{{ t('وصلت', 'Arrived') }} {{ fmtDate(shipment.arrivedAt) }}{{ shipment.carrier ? ` · ${shipment.carrier}` : '' }}</span>
        <span class="grow" />
        <Btn tone="soft" size="sm" :label="{ ar: 'ملصق الشحنة', en: 'Label' }" @click="showLabel({ type: 'code128', text: shipment.number, title: shipment.number, sub: shipment.po?.number })" />
        <button v-if="closable" type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
      </div>

      <div class="mt-4"><Stepper :steps="SHIPMENT_STEPS" :current="S === 'cancelled' ? 0 : shipmentStep(S)" :failed="S === 'cancelled'" :max-width="560" /></div>

      <!-- next step -->
      <div class="next-step mt-3.5" :class="next.tone">
        <div class="min-w-0 flex-1">
          <div class="text-[12.5px] font-extrabold">{{ next.title }}</div>
          <div class="mt-1 text-[10.5px] leading-[1.8] opacity-90">{{ next.text }}</div>
          <div v-if="!allowed" class="mt-1.5 text-[10px] font-extrabold text-bad">{{ t(`دورك لا يملك صلاحية هذا الإجراء (${next.perm}).`, `Your role lacks the permission for this step (${next.perm}).`) }}</div>
        </div>
        <div v-if="allowed" class="next-actions">
          <template v-if="S === 'expected' && !shipment.locked">
            <TextInput v-model="carrier" small field-class="next-input" :placeholder="{ ar: 'الناقل / رقم الشاحنة (اختياري)', en: 'Carrier / truck (optional)' }" @enter="arrive(shipment.number, carrier)" />
            <Btn tone="primary" class="!h-10 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'تسجيل وصول الشاحنة', en: 'Register truck arrival' }" @click="arrive(shipment.number, carrier)" />
          </template>
          <Btn v-if="S === 'arrived'" tone="primary" class="!h-10 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'بدء الفحص', en: 'Start inspection' }" @click="inspect(shipment.number)" />
          <template v-if="S === 'inspecting'">
            <ScanInput small field-class="next-input" :placeholder="{ ar: 'امسح باركود المنتج / SKU', en: 'Scan product barcode / SKU' }" @submit="onScanProduct" />
            <Btn tone="soft" :label="{ ar: 'تعبئة المتبقي كمستلم', en: 'Fill open qty as accepted' }" @click="fillAll" />
            <Btn tone="primary" class="!h-10 !text-[11.5px]" :loading="act.pending.value" :disabled="!ready" :label="{ ar: 'إصدار GRN وإنشاء مهام Putaway', en: 'Post GRN & create putaway tasks' }" @click="doPost" />
          </template>
          <Btn v-if="S === 'putaway'" tone="purple" class="!h-10" :label="{ ar: 'مهام التخزين ←', en: 'Putaway tasks →' }" @click="goPutaway" />
        </div>
      </div>
      <div v-if="S === 'inspecting' && allowed && !ready" class="mt-1.5 text-[10px] font-bold text-warn">
        {{ t(`أُدخلت كميات ${fmtNum(entered)} من ${fmtNum(shipment.lines.length)} سطر — أكمل كل الأسطر (يمكن إدخال 0) لتفعيل إصدار GRN.`, `${fmtNum(entered)} of ${fmtNum(shipment.lines.length)} lines entered — complete every line (0 is allowed) to enable the GRN.`) }}
      </div>

      <ErrorBanner :error="act.error.value" class="mt-3" @close="act.clearError()" />
      <div v-if="postedGrn" class="banner green mt-3">
        <div>
          {{ t('أُصدر', 'Posted') }} <RouterLink :to="`/grn/${encodeURIComponent(postedGrn.number)}`" class="num !text-ok">{{ postedGrn.number }}</RouterLink> — {{ lang === 'ar' ? postedGrn.summaryAr : postedGrn.summaryEn }}
          · <span class="num">{{ fmtNum(postedGrn.putaways?.length || 0) }}</span> {{ t('مهمة تخزين', 'putaway tasks') }} · <span class="num">{{ fmtNum(postedGrn.movements?.length || 0) }}</span> {{ t('حركة مخزون', 'movements') }}
        </div>
      </div>

      <!-- what is still due on the purchase order -->
      <div v-if="showBackorder" class="next-step amber mt-3">
        <div class="min-w-0 flex-1">
          <div class="text-[12.5px] font-extrabold">{{ t(`متبقٍ على أمر الشراء: ${fmtNum(bo.openQty)} وحدة في ${fmtNum(bo.openLines)} سطر`, `Still due on the purchase order: ${fmtNum(bo.openQty)} units on ${fmtNum(bo.openLines)} line(s)`) }}</div>
          <div class="mt-1 text-[10.5px] leading-[1.8] opacity-90">
            <template v-if="bo.allowed">{{ t('افتح شحنة جديدة للكمية المتبقية عندما يحدد المورد موعد توريدها.', 'Open a new shipment for the remainder once the supplier gives a delivery date.') }}</template>
            <template v-else>{{ lang === 'ar' ? bo.reasonAr : bo.reasonEn }}</template>
          </div>
        </div>
        <div class="next-actions">
          <template v-if="bo.allowed && auth.can('shipment.receive')">
            <DateInput v-model="eta" small field-class="next-input" :title="t('موعد الوصول المتوقع (اختياري)', 'Expected arrival (optional)')" />
            <Btn tone="dark" class="!h-10" :loading="act.pending.value" :label="{ ar: 'فتح شحنة للمتبقي', en: 'Open shipment for the remainder' }" @click="doBackorder" />
          </template>
          <Btn v-else-if="bo.shipment" tone="softPurple" class="!h-10" :label="{ ar: `فتح ${bo.shipment}`, en: `Open ${bo.shipment}` }" @click="emit('open', bo.shipment)" />
        </div>
      </div>

      <div class="mt-3.5"><ReceiveLinesGrid v-model:drafts="drafts" :lines="shipment.lines" :editable="editable" /></div>
      <div v-if="editable" class="mt-2.5"><TextInput v-model="notes" small :placeholder="{ ar: 'ملاحظات GRN (اختياري)', en: 'GRN notes (optional)' }" /></div>

      <div class="row wrap mt-2.5 !gap-3.5 text-[10px] text-sec">
        <span>{{ t('مطلوب', 'Ordered') }} <b class="num">{{ fmtNum(shipment.totals.ordered) }}</b></span>
        <span>{{ t('مقبول', 'Accepted') }} <b class="num text-ok">{{ fmtNum(shipment.totals.accepted) }}</b></span>
        <span>{{ t('تالف', 'Damaged') }} <b class="num text-bad">{{ fmtNum(shipment.totals.damaged) }}</b></span>
        <span>{{ t('مرفوض', 'Rejected') }} <b class="num text-warn">{{ fmtNum(shipment.totals.rejected) }}</b></span>
        <span>{{ t('متبقٍ على هذه الشحنة', 'Open on this shipment') }} <b class="num">{{ fmtNum(shipment.totals.open) }}</b></span>
        <span v-if="shipment.grns.length > 0">GRN: <RouterLink v-for="g in shipment.grns" :key="g.number" :to="`/grn/${encodeURIComponent(g.number)}`" class="num ms-1 !text-ok">{{ g.number }}</RouterLink></span>
        <span class="grow" />
        <Btn v-if="S === 'expected' && !shipment.locked && auth.can('po.cancel')" tone="dangerOutline" size="sm" :loading="act.pending.value" :label="{ ar: 'إلغاء الشحنة', en: 'Cancel shipment' }" @click="doCancel" />
      </div>
      <div class="hint amber mt-[11px]">{{ t('مرفقات إلزامية مع GRN: فاتورة المورد · بوليصة الشحن · صور التلف (رفع الملفات Integration Pending). لا سياسة Over-Receipt — أي كمية تتجاوز المتبقي على أمر الشراء تُرفض من النظام.', 'Required GRN attachments: supplier invoice · bill of lading · damage photos (file upload: Integration Pending). No over-receipt policy — any quantity above the open PO quantity is rejected by the server.') }}</div>
    </div>
  </SectionCard>
</template>
