<script setup>
// Shipment panel (desktop receiving tab + /shipments/:number): header with lifecycle actions (arrive → inspect → post GRN),
// stepper, per-line receiving form, totals and the GRN policy note.
//   <ShipmentPanel :shipment="s" closable @changed="refetch" @grn-posted="refetch" @close="sel = null" />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Btn, Chip, ErrorBanner, SectionCard, Stepper, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { SHIPMENT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import ReceiveLinesGrid from './ReceiveLinesGrid.vue';
import { SHIPMENT_STEPS, draftLines, draftSum, emptyDraft, pn, shipmentStep, useShipmentActions } from './shared';

const props = defineProps({
  shipment: { type: Object, required: true },
  /** Shows the ✕ button (emits `close`). */
  closable: { type: Boolean, default: false },
});
const emit = defineEmits(['changed', 'close', 'grn-posted']);

const auth = useAuth();
const router = useRouter();
const { act, arrive, inspect, postGrn } = useShipmentActions((s) => emit('changed', s));

const drafts = ref({});
const carrier = ref('');
const notes = ref('');
const postedGrn = ref(null);
watch(() => props.shipment.id, () => { drafts.value = {}; postedGrn.value = null; act.clearError(); });

const S = computed(() => props.shipment.status);
const editable = computed(() => S.value === 'inspecting' && auth.can('grn.post'));
/** Every line has an accepted quantity and nothing exceeds the open quantity. */
const ready = computed(() => editable.value && props.shipment.lines.every((l) => { const d = drafts.value[l.lineNo]; return d && d.acceptedQty != null && draftSum(d) <= l.openQty; }));

function fillAll() {
  const d = {};
  props.shipment.lines.forEach((l) => { d[l.lineNo] = { ...(drafts.value[l.lineNo] || emptyDraft(l)), acceptedQty: l.openQty }; });
  drafts.value = d;
}
async function doPost() {
  const g = await postGrn(props.shipment.number, draftLines(props.shipment.lines, drafts.value), notes.value);
  if (g) { postedGrn.value = g; emit('grn-posted', g); }
}
const goPutaway = () => router.push(`/receiving?tab=putaway&grn=${encodeURIComponent(props.shipment.grns[0]?.number || '')}`);
</script>

<template>
  <SectionCard class="mt-3.5">
    <div class="pt-4">
      <div class="row wrap !gap-2.5">
        <div class="num-mixed text-[13.5px] font-extrabold">{{ shipment.number }} <span class="num text-[10px] text-faint">· <RouterLink :to="`/po/${encodeURIComponent(shipment.po.number)}`" class="!text-violet">{{ shipment.po.number }}</RouterLink></span></div>
        <Chip :map="SHIPMENT_LABELS" :k="S" />
        <Chip v-if="shipment.locked" fg="#7d7990" bg="#F1EFF6" :label="{ ar: 'بانتظار اعتماد PO', en: 'Awaiting PO approval' }" />
        <span class="text-[10.5px] font-extrabold">{{ pn(shipment.supplier) }}</span>
        <span class="muted text-[10px]">{{ shipment.warehouse.code }} · {{ pn(shipment.warehouse) }}</span>
        <span class="cell-date ltr">ETA {{ fmtDateOnly(shipment.eta) }}</span>
        <span class="grow" />
        <div v-if="S === 'expected' && auth.can('shipment.receive') && !shipment.locked" class="row !gap-1.5">
          <TextInput v-model="carrier" small class="!w-[200px]" :placeholder="{ ar: 'الناقل / رقم الشاحنة (اختياري)', en: 'Carrier / truck (optional)' }" />
          <Btn tone="primary" class="!h-10 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'تسجيل وصول الشاحنة', en: 'Register truck arrival' }" @click="arrive(shipment.number, carrier)" />
        </div>
        <Btn v-if="S === 'arrived' && auth.can('shipment.receive')" tone="primary" class="!h-10 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'بدء الفحص', en: 'Start inspection' }" @click="inspect(shipment.number)" />
        <template v-if="S === 'inspecting' && auth.can('grn.post')">
          <Btn tone="soft" size="sm" :label="{ ar: 'تعبئة المتبقي كمستلم', en: 'Fill open qty as accepted' }" @click="fillAll" />
          <Btn tone="primary" class="!h-10 !text-[11.5px]" :loading="act.pending.value" :disabled="!ready" :label="{ ar: 'إصدار GRN وإنشاء مهام Putaway', en: 'Post GRN & create putaway tasks' }" @click="doPost" />
        </template>
        <Btn v-if="S === 'putaway'" tone="softPurple" size="sm" :label="{ ar: 'مهام التخزين ←', en: 'Putaway tasks →' }" @click="goPutaway" />
        <button v-if="closable" type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
      </div>

      <div class="mt-4"><Stepper :steps="SHIPMENT_STEPS" :current="S === 'cancelled' ? 0 : shipmentStep(S)" :failed="S === 'cancelled'" :max-width="560" /></div>
      <ErrorBanner :error="act.error.value" class="mt-3" @close="act.clearError()" />
      <div v-if="postedGrn" class="banner green mt-3">
        <div>
          {{ t('أُصدر', 'Posted') }} <RouterLink :to="`/grn/${encodeURIComponent(postedGrn.number)}`" class="num !text-ok">{{ postedGrn.number }}</RouterLink> — {{ lang === 'ar' ? postedGrn.summaryAr : postedGrn.summaryEn }}
          · <span class="num">{{ fmtNum(postedGrn.putaways?.length || 0) }}</span> {{ t('مهمة تخزين', 'putaway tasks') }} · <span class="num">{{ fmtNum(postedGrn.movements?.length || 0) }}</span> {{ t('حركة مخزون', 'movements') }}
        </div>
      </div>

      <div class="mt-3.5"><ReceiveLinesGrid v-model:drafts="drafts" :lines="shipment.lines" :editable="editable" /></div>
      <div v-if="editable" class="mt-2.5"><TextInput v-model="notes" small :placeholder="{ ar: 'ملاحظات GRN (اختياري)', en: 'GRN notes (optional)' }" /></div>

      <div class="row wrap mt-2.5 !gap-3.5 text-[10px] text-sec">
        <span>{{ t('مطلوب', 'Ordered') }} <b class="num">{{ fmtNum(shipment.totals.ordered) }}</b></span>
        <span>{{ t('مقبول', 'Accepted') }} <b class="num text-ok">{{ fmtNum(shipment.totals.accepted) }}</b></span>
        <span>{{ t('تالف', 'Damaged') }} <b class="num text-bad">{{ fmtNum(shipment.totals.damaged) }}</b></span>
        <span>{{ t('مرفوض', 'Rejected') }} <b class="num text-warn">{{ fmtNum(shipment.totals.rejected) }}</b></span>
        <span>{{ t('متبقٍ على PO', 'Open on PO') }} <b class="num">{{ fmtNum(shipment.totals.open) }}</b></span>
        <span v-if="shipment.grns.length > 0">GRN: <RouterLink v-for="g in shipment.grns" :key="g.number" :to="`/grn/${encodeURIComponent(g.number)}`" class="num ms-1 !text-ok">{{ g.number }}</RouterLink></span>
      </div>
      <div class="hint amber mt-[11px]">{{ t('مرفقات إلزامية مع GRN: فاتورة المورد · بوليصة الشحن · صور التلف (رفع الملفات Integration Pending). لا سياسة Over-Receipt — أي كمية تتجاوز المتبقي تُرفض بالكامل، والتالف يذهب إلى منطقة DMG ولا يدخل المتاح.', 'Required GRN attachments: supplier invoice · bill of lading · damage photos (file upload Integration Pending). No over-receipt policy — any excess over the open quantity is rejected; damaged goods go to DMG and never become available.') }}</div>
    </div>
  </SectionCard>
</template>
