<script setup>
// Pick-task card: sequence · product · zone/bin · batch + expiry · qty/picked, then (when pickable) scan bin → scan
// product → scan batch (only when the bin holds several batches of the product) → qty → confirm; or one of the two honest
// ways out, each with a structured reason (not found / damaged / expired / other + note):
//   «نقص»         short pick — the rest is not there: the line ships less, the shortage becomes a backorder or is cancelled;
//   «دفعة بديلة»  substitute — this batch / bin is unusable, the rest is reserved again from another one (FEFO).
// The server rejects a wrong bin / product / batch, over-picking, expired or quarantined batches — its message is shown as is.
//   <PickTaskCard :task="task" :fo-status="fo.status" :held-by="otherPicker" @changed="(result) => …" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, Modal, NumberInput, PillChoice, TextArea, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { PICK_REASONS, TASK_LABELS, prodName, reasonLabel } from './shared';

const props = defineProps({
  task: { type: Object, required: true },
  foStatus: { type: String, required: true },
  /** Who holds the pick list when it is another picker's and this user may not override — scanning is closed then. */
  heldBy: { type: String, default: null },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const act = useAction();
const bin = ref('');
const sku = ref('');
const batch = ref('');
const qty = ref(null);
/** Which report dialog is open: 'short' | 'sub' | null. */
const dialog = ref(null);
const reason = ref('notfound');
const note = ref('');

const remaining = computed(() => props.task.qty - props.task.pickedQty);
const pickable = computed(() => ['open', 'partial'].includes(props.task.status) && ['alloc', 'picking'].includes(props.foStatus));
const canDo = computed(() => auth.can('pick.confirm') && pickable.value && !props.heldBy);
const needBatch = computed(() => !!props.task.batchScan);
const batchNo = computed(() => props.task.batch?.batchNo || props.task.batchNo || '');
const ready = computed(() => !!bin.value.trim() && !!sku.value.trim() && (!needBatch.value || !!batch.value.trim()));
const noteNeeded = computed(() => reason.value === 'other');
const INVALIDATE = ['fulfillment', 'sales', 'inventory', 'exceptions'];

async function confirmPick() {
  const body = { scannedBin: bin.value.trim(), scannedProduct: sku.value.trim(), ...(batch.value.trim() ? { scannedBatch: batch.value.trim() } : {}), ...(qty.value ? { qty: qty.value } : {}) };
  const r = await act.run(() => api.postIdempotent(`/fulfillment/pick-tasks/${props.task.id}/confirm`, body), { success: (x) => x.message || t('تم ✓', 'Done ✓'), invalidate: INVALIDATE });
  if (r) { bin.value = ''; sku.value = ''; batch.value = ''; qty.value = null; emit('changed', r); }
}
function onEnter() { if (ready.value) confirmPick(); }
function openDialog(kind) { dialog.value = kind; reason.value = 'notfound'; note.value = ''; act.clearError(); }
async function report() {
  const path = dialog.value === 'sub' ? 'substitute' : 'short';
  const body = { reason: reason.value, ...(note.value.trim() ? { note: note.value.trim() } : {}) };
  const r = await act.run(() => api.postIdempotent(`/fulfillment/pick-tasks/${props.task.id}/${path}`, body), { success: (x) => x.message || t('سُجّل', 'Recorded'), invalidate: INVALIDATE });
  if (r) { dialog.value = null; emit('changed', { message: r.message, tone: r.short === 0 ? 'teal' : 'amber' }); }
}
</script>

<template>
  <div class="rounded-[13px] border border-line-2 px-[15px] py-[11px]" :class="{ 'bg-[#FAFDFB]': task.status === 'done', 'bg-[#FFFAFA]': task.status === 'short' }">
    <div class="row wrap !gap-3">
      <div class="num flex h-[26px] w-[26px] flex-none items-center justify-center rounded-lg bg-canvas text-[10px] text-sec">{{ task.seq }}</div>
      <div class="min-w-[170px]">
        <div class="text-[11.5px] font-extrabold">{{ prodName(task.product) }}<Chip v-if="task.substituted" class="ms-1.5" :label="{ ar: 'بديل', en: 'Substitute' }" fg="#0d7f93" bg="#d9f4f9" small /></div>
        <div class="num mt-px text-[8.5px] text-faint">{{ task.product.sku }}</div>
      </div>
      <div class="num min-w-[90px] text-[10.5px] text-violet"><span class="ltr">{{ task.bin.zone?.code ? `${task.bin.zone.code} · ` : '' }}{{ task.bin.code }}</span></div>
      <div class="num min-w-[70px] text-[10px] text-brand-dark">{{ batchNo || '—' }}<div v-if="task.batch?.expiryDate" class="text-[8px] text-faint">{{ t('ينتهي', 'exp.') }} {{ fmtDateOnly(task.batch.expiryDate) }}</div></div>
      <div class="min-w-[90px] flex-1 text-[11px] font-extrabold">{{ fmtNum(task.qty) }}<div class="num text-[8.5px] text-warn">{{ t(`مجهز ${task.pickedQty} / ${task.qty}`, `Picked ${task.pickedQty} / ${task.qty}`) }}</div></div>
      <template v-if="canDo">
        <div class="row flex-wrap !gap-1.5">
          <TextInput scan v-model="bin" small mono dir="ltr" class="w-[104px]" placeholder="Scan Bin" @enter="onEnter" />
          <TextInput scan v-model="sku" small mono dir="ltr" class="w-24" placeholder="Scan SKU" @enter="onEnter" />
          <TextInput v-if="needBatch" scan v-model="batch" small mono dir="ltr" class="w-[104px]" placeholder="Scan Batch" @enter="onEnter" />
          <NumberInput v-model="qty" small class="w-14" :min="1" :max="remaining" :placeholder="String(remaining)" @enter="onEnter" />
        </div>
        <Btn tone="primary" class="!h-[38px] !rounded-[11px] !text-[11px]" :loading="act.pending.value" :disabled="!ready" :label="{ ar: 'تأكيد', en: 'Confirm' }" @click="confirmPick">
          <template #icon><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M7 12h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg></template>
        </Btn>
        <Btn tone="softRed" size="sm" :label="{ ar: 'نقص', en: 'Short' }" @click="openDialog('short')" />
        <Btn tone="softAmber" size="sm" :label="{ ar: 'دفعة بديلة', en: 'Substitute' }" @click="openDialog('sub')" />
        <Chip v-if="task.status === 'partial'" :map="TASK_LABELS" k="partial" small />
      </template>
      <template v-else>
        <Chip :map="TASK_LABELS" :k="task.status" />
        <span v-if="pickable && heldBy" class="faint text-[9px]">{{ t(`القائمة بعهدة ${heldBy}`, `List held by ${heldBy}`) }}</span>
      </template>
    </div>
    <div v-if="canDo && needBatch" class="mt-1.5 text-[9px] font-bold text-warn">{{ t(`في ${task.bin.code} أكثر من دفعة لهذا المنتج — امسح الدفعة المطلوبة ${batchNo} (الأقرب انتهاءً)`, `${task.bin.code} holds several batches of this product — scan batch ${batchNo} (first to expire)`) }}</div>
    <div v-if="(task.pickedBy && task.status !== 'open') || task.shortReason" class="faint mt-1.5 text-[8.5px]">
      <template v-if="task.pickedBy && task.status !== 'open'">{{ task.pickedBy }}{{ task.pickedAt ? ` · ${fmtDateOnly(task.pickedAt)}` : '' }}</template>
      <span v-if="task.shortReason" class="text-bad"> · {{ reasonLabel(task.shortReason) }}</span>
      <span v-if="task.note"> — {{ task.note }}</span>
    </div>
    <ErrorBanner v-if="!dialog" class="mt-2" :error="act.error.value" @close="act.clearError()" />

    <Modal :open="!!dialog" :width="480"
           :title="dialog === 'sub' ? { ar: `دفعة بديلة — ${task.product.sku}`, en: `Substitute batch — ${task.product.sku}` } : { ar: `نقص تجهيز — ${task.product.sku}`, en: `Short pick — ${task.product.sku}` }"
           :sub="dialog === 'sub'
             ? { ar: `المتبقي ${remaining} يُعاد حجزه من موقع / دفعة أخرى (الأقرب انتهاءً) بعيدًا عن ${task.bin.code}${batchNo ? ` / ${batchNo}` : ''}. ما لا يتوفر يُسجَّل نقصًا.`, en: `The remaining ${remaining} is reserved again from another bin / batch (FEFO), away from ${task.bin.code}. What is not available becomes a short pick.` }
             : { ar: `المتبقي ${remaining} غير متوفر في ${task.bin.code} — يُشحن السطر ناقصًا، والناقص يصير طلبًا معلّقًا أو يُلغى حسب سياسة الشركة، ويُبلَّغ مدير المستودع والمبيعات.`, en: `${remaining} unavailable at ${task.bin.code} — the line ships short; the shortage becomes a backorder or is cancelled per policy, and the warehouse manager and sales are told.` }"
           @close="dialog = null">
      <PillChoice v-model="reason" full required :label="{ ar: 'السبب', en: 'Reason' }" :options="PICK_REASONS" />
      <div class="hint !mb-2.5 !mt-2" :class="reason === 'notfound' ? 'amber' : ''">
        {{ reason === 'notfound' ? t('«غير موجود»: يُجمَّد رصيد هذا الموقع ولا يُخصَّص لطلب آخر حتى يُجرد.', '"Not at the bin": the stock row is frozen and is not allocated again until it is counted.')
          : reason === 'other' ? t('اكتب السبب في الملاحظة.', 'Write the reason in the note.')
            : t('يُفتح استثناء لمراقب المخزون لنقل الرصيد إلى التالف / الحجر.', 'An exception is raised for the inventory controller to move the stock to damaged / quarantine.') }}
      </div>
      <TextArea v-model="note" :label="{ ar: 'ملاحظة', en: 'Note' }" :required="noteNeeded" :rows="2" :placeholder="{ ar: 'مثال: الرف فارغ / كراتين مبللة', en: 'e.g. empty shelf / wet cartons' }" />
      <ErrorBanner class="mt-2" :error="act.error.value" @close="act.clearError()" />
      <template #footer>
        <div class="flex gap-2">
          <Btn :tone="dialog === 'sub' ? 'dark' : 'danger'" class="!h-10 flex-1" :loading="act.pending.value" :disabled="noteNeeded && !note.trim()"
               :label="dialog === 'sub' ? { ar: 'إعادة الحجز من دفعة أخرى', en: 'Reserve from another batch' } : { ar: 'تسجيل النقص', en: 'Record short pick' }" @click="report" />
          <Btn tone="soft" class="!h-10 w-[100px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="dialog = null" />
        </div>
      </template>
    </Modal>
  </div>
</template>
