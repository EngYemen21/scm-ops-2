<script setup>
// Worker — Receive (mobile-first): shipments → scan lines → quantities → GRN → putaway.
// Deep link: /wreceive?sel=SHP-…
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useGet, useList } from '@/api/client';
import { Chip, ErrorBanner, Icon, PageHead, ScanButton, ScanInput } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import PutawayList from './PutawayList.vue';
import WorkerBigBtn from './WorkerBigBtn.vue';
import WorkerQtyInput from './WorkerQtyInput.vue';
import { WORKER_SHIP_LABELS, draftLines, draftSum, emptyBatch, emptyDraft, firstBatchQty, lineReady, liveGrn, pn, queryOf, splitProblem, useShipmentActions } from './shared';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const wh = useWarehouse();

const sel = computed(() => queryOf(route, 'sel'));
function setSel(n) {
  const query = { ...route.query };
  if (n) query.sel = n; else delete query.sel;
  router.replace({ query });
}

const today = new Date().toISOString().slice(0, 10);
const ships = useList('/inbound/shipments', () => ({ status: 'open', pageSize: 30, ...wh.whParams }), { refetchInterval: 30_000 });
const put = useList('/inbound/putaway', () => ({ status: 'open', pageSize: 1, ...wh.whParams }));
const picks = useList('/fulfillment/pick-lists', () => ({ status: 'open', pageSize: 1, ...wh.whParams }));
const doneToday = useList('/inbound/grns', () => ({ from: today, pageSize: 1, ...wh.whParams }));
const detail = useGet(() => (sel.value ? `/inbound/shipments/${encodeURIComponent(sel.value)}` : null));
const shipment = computed(() => (sel.value ? detail.data.value : null));

const { act, arrive, inspect, postGrn, unarrive, refuse } = useShipmentActions();
/** `{ [lineNo]: true }` — a line must be scanned before its quantities can be entered. */
const scanned = ref({});
/** `{ [lineNo]: ReceiveDraft }` */
const drafts = ref({});
const scanErr = ref(null);
const lookupErr = ref(null);
const lineScan = ref(null);
const focusLineScan = () => nextTick(() => lineScan.value?.focus());
watch(sel, () => { scanned.value = {}; drafts.value = {}; scanErr.value = null; act.clearError(); });

const S = computed(() => shipment.value?.status);
const lines = computed(() => shipment.value?.lines || []);
const allScanned = computed(() => lines.value.length > 0 && lines.value.every((l) => scanned.value[l.lineNo]));
/** Every scanned line is within its open quantity and its batch split (if any) adds up. */
const allReady = computed(() => allScanned.value && lines.value.every((l) => lineReady(l, drafts.value[l.lineNo])));
const grnNo = computed(() => liveGrn(shipment.value));
/** The truck is here and nothing was received: the delivery can still be turned away. */
const gateOpen = computed(() => ['arrived', 'inspecting'].includes(S.value) && !grnNo.value && auth.can('shipment.receive'));
watch(S, (s) => { if (s === 'inspecting') focusLineScan(); }, { immediate: true });

const draftOf = (l) => drafts.value[l.lineNo] || emptyDraft(l);
const diffOf = (l) => l.openQty - draftSum(draftOf(l));
const set = (l, patch) => { drafts.value = { ...drafts.value, [l.lineNo]: { ...draftOf(l), ...patch } }; };
function markScanned(l) {
  scanned.value = { ...scanned.value, [l.lineNo]: true };
  set(l, { acceptedQty: drafts.value[l.lineNo]?.acceptedQty ?? l.openQty }); // first scan fills the open quantity
}
const unscan = (l) => { scanned.value = { ...scanned.value, [l.lineNo]: false }; focusLineScan(); };
/** Product barcode / SKU scanned inside the open shipment. */
function scanCode(code) {
  const c = code.trim().toUpperCase();
  const l = lines.value.find((x) => x.product.sku.toUpperCase() === c || x.product.barcodes?.some((b) => b.barcode === code.trim()));
  if (!l) scanErr.value = t(`الباركود ${code} لا يطابق أي سطر في ${shipment.value?.number || ''} — تحقق من المنتج`, `Barcode ${code} does not match any line on ${shipment.value?.number || ''}`);
  else { scanErr.value = null; markScanned(l); }
  focusLineScan();
}
/** PO / shipment number scanned in the page header. */
async function scanShipment(code) {
  lookupErr.value = null;
  try { const s = await api.get(`/inbound/shipments/scan/${encodeURIComponent(code)}`); setSel(s.number); } catch (e) { lookupErr.value = e; }
}
const addPhotoNote = (l) => { const d = draftOf(l); set(l, { qcNote: `${d.qcNote ? d.qcNote + ' · ' : ''}${t('صورة — Integration Pending', 'photo — Integration Pending')}` }); };

// one line in several batches: each extra row is carved out of the accepted quantity
const extraOf = (l) => draftOf(l).extra || [];
const addBatch = (l) => set(l, { extra: [...extraOf(l), emptyBatch()] });
const removeBatch = (l, i) => set(l, { extra: extraOf(l).filter((_, k) => k !== i) });
const setBatch = (l, i, patch) => set(l, { extra: extraOf(l).map((b, k) => (k === i ? { ...b, ...patch } : b)) });
const splitErr = (l) => splitProblem(l, draftOf(l));

const doArrive = () => arrive(shipment.value.number).then(() => detail.refetch());
const doInspect = () => inspect(shipment.value.number).then(() => detail.refetch());
async function doUnarrive() {
  const n = shipment.value.number;
  if (!(await confirm({ title: { ar: `التراجع عن تسجيل وصول ${n}؟`, en: `Undo the arrival of ${n}?` }, sub: { ar: 'تعود الشحنة «متوقعة». استخدمه عند تسجيل الوصول على شحنة خاطئة.', en: 'The shipment goes back to expected. Use it when the arrival was registered on the wrong shipment.' }, tone: 'dark', okLabel: { ar: 'تراجع عن الوصول', en: 'Undo arrival' }, cancelLabel: { ar: 'إبقاء', en: 'Keep' } }))) return;
  if (await unarrive(n)) detail.refetch();
}
async function doRefuse() {
  const n = shipment.value.number;
  const reason = await ask({ title: { ar: `رفض الشحنة ${n} عند البوابة`, en: `Refuse shipment ${n} at the gate` }, sub: { ar: 'تُعاد الشحنة كاملة للمورد دون استلام، ويُبلَّغ قسم المشتريات. يبقى أمر الشراء مفتوحًا.', en: 'The whole delivery goes back, nothing is received and procurement is told. The purchase order stays open.' }, label: { ar: 'سبب الرفض', en: 'Reason' }, required: true, tone: 'danger', okLabel: { ar: 'رفض الشحنة', en: 'Refuse delivery' } });
  if (reason === null) return;
  if (reason.length < 3) { scanErr.value = t('اكتب سبب الرفض (3 أحرف على الأقل)', 'Write the reason (at least 3 characters)'); return; }
  if (await refuse(n, reason)) detail.refetch();
}
async function complete() {
  if (!shipment.value) return;
  const g = await postGrn(shipment.value.number, draftLines(lines.value, drafts.value));
  if (g) detail.refetch();
}

const kpis = computed(() => [
  { v: ships.data.value?.total, l: t('شحنات للاستلام', 'Shipments to receive'), c: '#1BC4DB' }, { v: put.data.value?.total, l: t('مهام تخزين Putaway', 'Putaway tasks'), c: '#654e92' },
  { v: picks.data.value?.total, l: t('قوائم تجهيز', 'Pick lists'), c: '#3C79F5' }, { v: doneToday.data.value?.total, l: t('أُنجز اليوم (GRN)', 'Done today (GRN)'), c: '#1d7a3e' },
]);
</script>

<template>
  <PageHead :title="{ ar: 'الاستلام', en: 'Receive' }" :sub="t('مهامك اليوم — Scan ← تأكيد الكمية ← إبلاغ فرق/تالف ← إتمام', 'Your tasks today — scan → confirm qty → report variance/damage → complete')">
    <div class="w-[220px]"><ScanInput small :placeholder="{ ar: 'امسح رقم PO / الشحنة', en: 'Scan PO / shipment' }" @submit="scanShipment" /></div>
  </PageHead>

  <div class="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-2.5">
    <div v-for="k in kpis" :key="k.l" class="kpi">
      <div class="kpi-v !text-[22px]" :style="{ color: k.c }">{{ k.v == null ? '—' : fmtNum(k.v) }}</div>
      <div class="kpi-l !text-[10px]">{{ k.l }}</div>
    </div>
  </div>
  <ErrorBanner :error="ships.error.value" :closable="false" class="mt-3" />
  <ErrorBanner :error="lookupErr" class="mt-3" @close="lookupErr = null" />

  <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-2.5">
    <div v-if="ships.isLoading.value && !ships.data.value" class="skel min-h-[90px]" />
    <div v-for="s in ships.data.value?.items || []" :key="s.number" class="cursor-pointer rounded-[14px] border-[1.5px] border-solid px-[15px] py-[13px]" :class="s.number === sel ? 'border-brand bg-[#F3FCFE]' : 'border-line bg-white'" @click="setSel(s.number === sel ? null : s.number)">
      <div class="row"><span class="num text-[12px]">{{ s.number }}</span><span class="grow" /><Chip :map="WORKER_SHIP_LABELS" :k="s.status" /></div>
      <div class="mt-[5px] text-[10.5px] font-bold">{{ pn(s.supplier) }}</div>
      <div class="num mt-0.5 text-[9px] text-faint">{{ s.po.number }} · {{ s.lines.length }} {{ t('أسطر', 'lines') }} · ETA {{ fmtDateOnly(s.eta) }}</div>
    </div>
    <div v-if="ships.data.value && ships.data.value.items.length === 0" class="empty success">{{ t('لا شحنات مسندة لك الآن ✓', 'No shipments assigned to you right now ✓') }}</div>
  </div>

  <ErrorBanner :error="sel ? detail.error.value : null" :closable="false" class="mt-3" />
  <div v-if="shipment" class="mt-3.5 rounded-[18px] border-[1.5px] border-solid border-[#A8E4EF] bg-white px-5 py-[18px]">
    <div class="row wrap !gap-2.5">
      <span class="num-mixed text-[15px] font-extrabold">{{ shipment.number }}</span>
      <span class="text-[11px] font-extrabold">{{ pn(shipment.supplier) }}</span>
      <span class="num text-[9.5px] text-faint">{{ shipment.po.number }}</span>
      <Chip :map="WORKER_SHIP_LABELS" :k="shipment.status" />
      <span class="grow" />
      <WorkerBigBtn v-if="S === 'expected' && auth.can('shipment.receive')" bg="#3C79F5" fg="#fff" :label="t('تسجيل وصول الشاحنة', 'Register truck arrival')" :pending="act.pending.value" @click="doArrive" />
      <WorkerBigBtn v-if="S === 'arrived' && auth.can('shipment.receive')" bg="#1BC4DB" fg="#0b2a30" :label="t('بدء الاستلام', 'Start receiving')" :pending="act.pending.value" @click="doInspect" />
      <template v-if="S === 'inspecting' && auth.can('grn.post')">
        <WorkerBigBtn v-if="allReady" bg="#1d7a3e" fg="#fff" :label="t('إتمام الاستلام وإصدار GRN', 'Complete & post GRN')" :pending="act.pending.value" @click="complete" />
        <div v-else class="flex h-12 items-center rounded-[13px] bg-line-2 px-[22px] text-[12px] font-extrabold text-faint">{{ allScanned ? t('صحّح الكميات أو الدفعات أولًا', 'Fix the quantities or batches first') : t('امسح كل الأسطر أولًا', 'Scan every line first') }}</div>
      </template>
      <div v-if="S === 'putaway'" class="flex h-12 items-center rounded-[13px] bg-violet-soft px-[22px] text-[12px] font-extrabold text-violet">{{ t('مهام التخزين في الأسفل', 'Putaway tasks below') }}</div>
      <Chip v-if="S === 'done'" fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'مكتملة ✓', en: 'Done ✓' }" />
    </div>
    <!-- the truck is at the gate and nothing was received: wrong click → undo · bad delivery → refuse -->
    <div v-if="gateOpen" class="row wrap mt-2.5 !gap-2">
      <button v-if="S === 'arrived'" type="button" class="btn outline !h-11 !rounded-xl" :disabled="act.pending.value" @click="doUnarrive">{{ t('تراجع عن تسجيل الوصول', 'Undo arrival') }}</button>
      <button type="button" class="btn dangerOutline !h-11 !rounded-xl" :disabled="act.pending.value" @click="doRefuse">{{ t('رفض الشحنة عند البوابة', 'Refuse at the gate') }}</button>
      <span class="text-[9.5px] text-faint">{{ t('الرفض يعيد الشحنة كاملة للمورد ويُبقي أمر الشراء مفتوحًا', 'Refusing sends the whole delivery back and keeps the purchase order open') }}</span>
    </div>
    <div v-if="shipment.refusedAt" class="banner red mt-2.5">{{ t(`رُفضت عند البوابة — ${shipment.refusedReason || ''}`, `Refused at the gate — ${shipment.refusedReason || ''}`) }}</div>

    <div v-if="S === 'inspecting'" class="mt-3">
      <ScanInput ref="lineScan" :label="{ ar: 'Scan باركود المنتج — يُعلّم السطر ويُعبّئ الكمية المتبقية', en: 'Scan product barcode — marks the line and fills the open qty' }" :placeholder="{ ar: 'امسح الباركود / SKU', en: 'Scan barcode / SKU' }" @submit="scanCode" />
      <div v-if="scanErr" class="banner red mt-2">{{ scanErr }}</div>
    </div>
    <ErrorBanner :error="act.error.value" class="mt-3" @close="act.clearError()" />

    <div class="col mt-3.5">
      <div v-for="l in lines" :key="l.id" class="flex flex-wrap items-center gap-3 rounded-[13px] border border-line-2 px-[15px] py-3">
        <div class="min-w-[190px] flex-1">
          <div class="text-[12px] font-extrabold">{{ pn(l.product) }}</div>
          <div class="num mt-0.5 text-[9px] text-faint">{{ l.product.sku }} · {{ l.batchNo || draftOf(l).batchNo || '—' }} · {{ fmtDateOnly(l.expiryDate || draftOf(l).expiryDate) }} → {{ l.suggestedBin?.code || (lang === 'ar' ? l.suggestionAr : l.suggestionEn) }}</div>
        </div>
        <div class="text-center">
          <div class="num text-[15px] text-muted">{{ fmtNum(l.openQty) }}</div>
          <div class="text-[8px] font-extrabold text-faint">{{ t('مطلوب', 'Ordered') }}{{ l.previouslyReceived ? ` (${t('سبق', 'prev')} ${l.previouslyReceived})` : '' }}</div>
        </div>
        <div v-if="S !== 'inspecting'" class="text-center">
          <div class="num text-[15px] text-ok">{{ fmtNum(l.acceptedQty) }}</div>
          <div class="text-[8px] font-extrabold text-ok">{{ t('مستلم سليم', 'Accepted') }}</div>
        </div>

        <template v-if="S === 'inspecting' && scanned[l.lineNo]">
          <WorkerQtyInput :model-value="draftOf(l).acceptedQty" :width="72" color="#1d7a3e" border="#BFE8CC" :label="t('مستلم سليم', 'Accepted')" @update:model-value="set(l, { acceptedQty: $event })" />
          <WorkerQtyInput :model-value="draftOf(l).damagedQty" color="#b23b3b" border="#F3C4C4" :label="t('تالف', 'Damaged')" @update:model-value="set(l, { damagedQty: $event })" />
          <WorkerQtyInput :model-value="draftOf(l).rejectedQty" color="#b26a16" border="#F0DEB8" :label="t('مرفوض', 'Rejected')" @update:model-value="set(l, { rejectedQty: $event })" />
          <div v-if="l.product.tracksExpiry || extraOf(l).length" class="row !gap-1.5">
            <ScanButton :title="{ ar: 'امسح رقم الدفعة', en: 'Scan the batch number' }" @detected="(code) => set(l, { batchNo: code })" />
            <input :value="draftOf(l).batchNo" :placeholder="t('الدفعة *', 'Batch *')" dir="ltr" class="inp num !h-10 !w-[100px]" :class="{ '!border-[#F0DEB8]': !draftOf(l).batchNo }" @input="set(l, { batchNo: $event.target.value })">
            <input type="date" :value="draftOf(l).expiryDate" dir="ltr" class="inp num !h-10 !w-[130px] !text-[11px]" :class="{ '!border-[#F0DEB8]': !draftOf(l).expiryDate }" :title="t('تاريخ الانتهاء *', 'Expiry *')" @input="set(l, { expiryDate: $event.target.value })">
          </div>
          <div v-if="diffOf(l) !== 0" dir="ltr" class="flex h-11 items-center rounded-xl px-3 text-[11px] font-extrabold" :class="diffOf(l) < 0 ? 'bg-bad-soft text-bad' : 'bg-warn-soft text-warn'">
            {{ diffOf(l) < 0 ? t(`يتجاوز المتبقي بـ ${-diffOf(l)}`, `Over by ${-diffOf(l)}`) : `${t('فرق', 'Diff')} −${diffOf(l)}` }}
          </div>
          <button v-if="Number(draftOf(l).damagedQty) > 0 || diffOf(l) > 0" type="button" class="btn outline !h-11 !rounded-xl" @click="addPhotoNote(l)">{{ t('صورة', 'Photo') }}</button>
          <button type="button" class="flex h-11 cursor-pointer items-center gap-1.5 rounded-xl border-[1.5px] border-solid border-[#BFE8CC] bg-ok-soft px-[18px] text-[12px] font-extrabold text-ok" @click="unscan(l)"><Icon name="check" :size="14" color="#1d7a3e" />{{ t('تم المسح — إعادة', 'Scanned — redo') }}</button>
          <button type="button" class="btn soft !h-11 !rounded-xl" :disabled="!(Number(draftOf(l).acceptedQty) > 1)" :title="Number(draftOf(l).acceptedQty) > 1 ? null : t('أدخل الكمية المقبولة أولًا', 'Enter the accepted quantity first')" @click="addBatch(l)">+ {{ t('دفعة أخرى', 'Another batch') }}</button>
          <!-- the line arrived in several batches: each extra row has its own quantity, number and expiry -->
          <div v-if="extraOf(l).length" class="basis-full rounded-xl bg-soft px-3 py-2.5">
            <div class="text-[10px] font-extrabold text-muted">{{ t('الدفعة الأولى', 'First batch') }} <span class="num text-violet">{{ draftOf(l).batchNo || '—' }}</span>: <span class="num" :class="firstBatchQty(draftOf(l)) > 0 ? 'text-ok' : 'text-bad'">{{ fmtNum(firstBatchQty(draftOf(l))) }}</span></div>
            <div v-for="(b, i) in extraOf(l)" :key="i" class="row wrap mt-2 !gap-1.5">
              <WorkerQtyInput :model-value="b.qty" :width="72" color="#1d7a3e" border="#BFE8CC" :label="t(`دفعة ${i + 2}`, `Batch ${i + 2}`)" @update:model-value="setBatch(l, i, { qty: $event })" />
              <ScanButton :title="{ ar: 'امسح رقم الدفعة', en: 'Scan the batch number' }" @detected="(code) => setBatch(l, i, { batchNo: code })" />
              <input :value="b.batchNo" :placeholder="t('الدفعة *', 'Batch *')" dir="ltr" class="inp num !h-10 !w-[110px]" :class="{ '!border-[#F0DEB8]': !b.batchNo }" @input="setBatch(l, i, { batchNo: $event.target.value })">
              <input type="date" :value="b.expiryDate" dir="ltr" class="inp num !h-10 !w-[130px] !text-[11px]" :class="{ '!border-[#F0DEB8]': l.product.tracksExpiry && !b.expiryDate }" :title="t('تاريخ الانتهاء', 'Expiry')" @input="setBatch(l, i, { expiryDate: $event.target.value })">
              <button type="button" class="btn dangerOutline !h-10 !rounded-xl" @click="removeBatch(l, i)">{{ t('حذف', 'Remove') }}</button>
            </div>
            <div v-if="splitErr(l)" class="mt-1.5 text-[10.5px] font-extrabold text-bad">{{ lang === 'ar' ? splitErr(l).ar : splitErr(l).en }}</div>
          </div>
        </template>
        <button v-if="S === 'inspecting' && !scanned[l.lineNo]" type="button" class="flex h-11 cursor-pointer items-center gap-[7px] rounded-xl border-0 bg-brand px-[18px] text-[12px] font-extrabold text-[#0b2a30]" @click="markScanned(l)"><Icon name="scan" :size="15" color="#0b2a30" />{{ t('Scan الباركود', 'Scan barcode') }}</button>
      </div>
    </div>
  </div>

  <PutawayList v-if="shipment && grnNo && ['putaway', 'done'].includes(shipment.status)" big :params="{ grn: grnNo, status: shipment.status === 'done' ? 'done' : 'open' }" :title="{ ar: 'مهام التخزين — Scan الموقع ثم أكّد', en: 'Putaway — scan the bin, then confirm' }" />
  <PutawayList v-if="!shipment && auth.can('putaway.confirm') && (put.data.value?.total || 0) > 0" big :params="{ status: 'open', ...wh.whParams }" :title="{ ar: 'مهام التخزين المفتوحة', en: 'Open putaway tasks' }" />
  <div class="hint">{{ t('كل سطر يُمسح قبل إدخال الكمية؛ التالف والمرفوض لا يدخلان المخزون المتاح. أي كمية تتجاوز المتبقي على أمر الشراء تُرفض من النظام (لا Over-Receipt). بعد GRN تظهر مهام التخزين: امسح الموقع المقترح والمنتج ثم أكّد.', 'Scan each line before entering quantities; damaged and rejected never enter available stock. Any quantity above the open PO quantity is rejected (no over-receipt). After the GRN, putaway tasks appear: scan the suggested bin and product, then confirm.') }}</div>
</template>
