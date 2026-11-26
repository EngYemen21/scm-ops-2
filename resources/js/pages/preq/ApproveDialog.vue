<script setup>
// Approving a request: the moment the product enters the catalogue.
//   <ApproveDialog :request="detailOrNull" @close="…" @done="(request) => …" />
// The reviewer decides the product code (the next free one of the catalogue's series is proposed — GET next-sku),
// completes what the catalogue cannot do without (weight and dimensions: the request's own values, otherwise the
// estimate read from its name, shown as such) and sees once more whether the product already exists (GET duplicates
// for the request with the code he typed): a taken code or barcode blocks, the same name asks him to confirm.
// POST /product-requests/{id}/approve { sku, changes, confirmDuplicate, note } — all of it happens together or not at all.
// A request about a product the catalogue already has (a customer's «صنف موجود») creates nothing: it is approved as it is.
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, PillChoice, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import DuplicatesPanel from './DuplicatesPanel.vue';
import { API, FIELDS, STORAGE, STORAGE_OPTIONS, fmtPrice, productName } from './preq';

const props = defineProps({
  /** The request as GET /product-requests/{id} answers it; null closes the dialog. */
  request: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const PHYSICAL = ['weightKg', 'lengthCm', 'widthCm', 'heightCm'];
const act = useAction({ invalidate: ['product-requests', 'products'] });
const v = reactive({ sku: '', weightKg: null, lengthCm: null, widthCm: null, heightCm: null, storageClass: 'ambient', note: '' });
const errs = ref({});
/** The physical values that were put from the estimate and not typed since. */
const fromEstimate = ref([]);
const confirmed = ref(false);
const found = ref(null);
const checking = ref(false);
let checkTimer = null;
let checkSeq = 0;

const r = computed(() => props.request);
const creates = computed(() => !!r.value && r.value.requestType === 'new');
// asked once per request (the request is part of the key): the next free code changes with every approval
const nextSku = useGet(() => (r.value && creates.value ? `${API}/next-sku` : null), () => ({ for: r.value?.id }), { staleTime: 0 });
/** The code in the field is the proposed one, not one a person typed: a newer proposal may replace it. */
const suggested = ref(false);
function takeSuggestion() {
  const sku = nextSku.data.value?.sku;
  if (!sku || !r.value || !creates.value || (v.sku && !suggested.value) || v.sku === sku) return;
  v.sku = sku;
  suggested.value = true;
  checkSoon(50);
}

async function check() {
  if (!r.value || !creates.value) return;
  const seq = ++checkSeq;
  checking.value = true;
  try {
    const answer = await api.get(`${API}/duplicates`, { request: r.value.id, sku: v.sku.trim() || undefined });
    if (seq === checkSeq) found.value = answer;
  } catch { if (seq === checkSeq) found.value = null; /* the server checks again when the approval is sent */ }
  if (seq === checkSeq) checking.value = false;
}
const checkSoon = (ms) => { clearTimeout(checkTimer); checkTimer = setTimeout(check, ms); };
onBeforeUnmount(() => clearTimeout(checkTimer));

watch(() => props.request?.id, (id) => {
  act.clearError();
  errs.value = {};
  confirmed.value = false;
  found.value = null;
  if (!id) return;
  const q = props.request;
  const estimate = q.estimate || {};
  const used = [];
  PHYSICAL.forEach((k) => {
    if (q[k] != null && Number(q[k]) > 0) v[k] = Number(q[k]);
    else if (estimate[k] != null) { v[k] = Number(estimate[k]); used.push(k); } else v[k] = null;
  });
  fromEstimate.value = used;
  v.storageClass = q.storageClass || (STORAGE[estimate.storageClass] ? estimate.storageClass : 'ambient');
  v.sku = q.proposedSku || '';
  suggested.value = false;
  v.note = '';
  takeSuggestion(); // already known (the dialog was opened on this request before)
  checkSoon(50);
}, { immediate: true });
// the proposed code arrives a moment after the dialog opens: it fills the field unless a person put a code there
watch(() => nextSku.data.value?.sku, takeSuggestion);

function set(k, x) {
  v[k] = x;
  if (errs.value[k]) { const n = { ...errs.value }; delete n[k]; errs.value = n; }
  if (PHYSICAL.includes(k)) fromEstimate.value = fromEstimate.value.filter((f) => f !== k);
  if (k === 'sku') { suggested.value = false; confirmed.value = false; checkSoon(400); }
}

const blocked = computed(() => !!found.value?.blocked);
const asks = computed(() => !!found.value?.needsConfirmation);
const estimateBasis = computed(() => (fromEstimate.value.length ? r.value?.estimate?.basis || 'name' : null));

function validate() {
  const e = {};
  if (creates.value) {
    if (!/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/.test(v.sku.trim())) e.sku = t('3 إلى 32 حرفًا لاتينيًا أو رقمًا', '3 to 32 Latin letters or digits');
    PHYSICAL.forEach((k) => { if (!(Number(v[k]) > 0)) e[k] = t('مطلوب، أكبر من صفر', 'Required, greater than zero'); });
  }
  errs.value = e;
  return !Object.keys(e).length;
}
/** What the reviewer sets here that the request does not already hold. */
function changes() {
  const out = {};
  PHYSICAL.forEach((k) => { if (Number(r.value[k]) !== Number(v[k])) out[k] = Number(v[k]); });
  if ((r.value.storageClass || null) !== v.storageClass) out.storageClass = v.storageClass;
  return out;
}
async function approve() {
  if (!validate() || blocked.value || (asks.value && !confirmed.value)) return;
  const body = creates.value
    ? { sku: v.sku.trim().toUpperCase(), changes: changes(), confirmDuplicate: confirmed.value || undefined, note: v.note.trim() || undefined }
    : { note: v.note.trim() || undefined };
  if (body.changes && !Object.keys(body.changes).length) delete body.changes;
  const done = await act.run(() => api.postIdempotent(`${API}/${r.value.id}/approve`, body), {
    success: (d) => (d.productLink === 'created' ? t(`اعتُمد الطلب — أُنشئ الصنف ${d.productSku} في الكتالوج`, `Approved — product ${d.productSku} is in the catalogue`) : t('اعتُمد الطلب', 'Request approved')),
  });
  if (done) { emit('done', done); emit('close'); return; }
  // the catalogue changed since the dialog opened (a code or a name taken meanwhile): show what is there now
  if (['PREQ_DUPLICATE_NAME', 'PREQ_SKU_EXISTS', 'PREQ_BARCODE_EXISTS', 'PREQ_DUPLICATE_REQUEST'].includes(act.error.value?.code)) checkSoon(0);
  if (act.error.value) { const fe = act.error.value.fieldErrors(); if (Object.keys(fe).length) errs.value = { ...errs.value, ...fe }; }
}
</script>

<template>
  <Modal :open="!!request" :width="640" :title="{ ar: 'اعتماد الطلب', en: 'Approve the request' }" :sub="request ? `${request.number} — ${productName(request)}` : ''" @close="emit('close')">
    <template v-if="r">
      <template v-if="creates">
        <div class="hint green !mt-0 mb-3">{{ t('عند الاعتماد يُنشأ الصنف في الكتالوج بهذه القيم ويُربط بالطلب. لا يُنشأ صنف برمز أو باركود مستخدم.', 'On approval the product is created in the catalogue with these values and linked to the request. No product is created with a code or a barcode that is taken.') }}</div>
        <div class="form-grid">
          <TextInput :model-value="v.sku" :label="{ ar: 'رمز الصنف (SKU)', en: 'Product code (SKU)' }" required dir="ltr" mono :error="errs.sku"
                     :hint="nextSku.data.value && nextSku.data.value.sku === v.sku ? { ar: 'الرمز التالي في سلسلة الكتالوج — يمكن تغييره', en: 'The next code of the catalogue’s series — you may change it' } : r.proposedSku && r.proposedSku === v.sku ? { ar: 'الرمز الذي اقترحه صاحب الطلب', en: 'The code the requester proposed' } : null"
                     @update:model-value="set('sku', $event)" />
          <div class="field">
            <span class="field-l">{{ t('الأسعار المقترحة', 'Proposed prices') }}</span>
            <div class="text-[11px] leading-[1.9] text-sec">{{ t('التكلفة', 'Cost') }} <b class="num ltr text-ink">{{ fmtPrice(r.costPrice, r.currency) }}</b> · {{ t('البيع', 'Selling') }} <b class="num ltr text-ink">{{ fmtPrice(r.salePrice, r.currency) }}</b></div>
            <div class="field-hint">{{ t('تُسجَّل التكلفة سعر شراء للصنف؛ سعر البيع يبقى على الطلب لقوائم الأسعار.', 'The cost becomes the product’s purchase price; the selling price stays on the request for the price lists.') }}</div>
          </div>
          <NumberInput v-for="k in PHYSICAL" :key="k" :model-value="v[k]" :label="FIELDS[k]" required :min="0" :error="errs[k]" :hint="fromEstimate.includes(k) ? { ar: 'تقدير', en: 'estimate' } : null" @update:model-value="set(k, $event)" />
          <PillChoice :model-value="v.storageClass" :label="{ ar: 'شرط التخزين', en: 'Storage' }" full purple :options="STORAGE_OPTIONS" @update:model-value="set('storageClass', $event)" />
        </div>
        <div v-if="estimateBasis" class="hint amber !mt-2.5">
          {{ estimateBasis === 'default'
            ? t('الطلب بلا وزن وأبعاد، ولا قياس مكتوب في اسمه: القيم المعلَّمة «تقدير» مبدئية (كرتون 10 كجم) — اكتب القياس الفعلي.', 'The request states no weight or dimensions and its name writes no measure: the values marked “estimate” are placeholders (a 10 kg carton) — type the real ones.')
            : t('الطلب بلا وزن وأبعاد: القيم المعلَّمة «تقدير» مقروءة من اسمه وعبوته — صحّحها بالقياس الفعلي إن عُرف، وتُستبدل لاحقًا من صفحة الصنف.', 'The request states no weight or dimensions: the values marked “estimate” are read from its name and pack — correct them with the measured ones when known; they can be replaced later on the product page.') }}
        </div>

        <div class="mt-3">
          <DuplicatesPanel :found="found" :loading="checking" show-clean />
          <button v-if="asks && !blocked" type="button" class="pill mt-2" :class="{ active: confirmed }" :aria-pressed="confirmed" @click="confirmed = !confirmed">
            {{ confirmed ? t('✓ أؤكد أنه صنف آخر (عبوة أو نوع مختلف)', '✓ I confirm this is another product (another pack or kind)') : t('أؤكد أنه صنف آخر — اضغط للتأكيد', 'Confirm this is another product — press to confirm') }}
          </button>
        </div>
      </template>
      <div v-else class="hint purple !mt-0">
        {{ t(`هذا طلب عميل لصنف موجود في الكتالوج (${r.productSku || '—'}): يُعتمد الطلب ويبقى مرتبطًا بذلك الصنف — لا يُنشأ صنف جديد.`, `This is a customer’s request for a product the catalogue already has (${r.productSku || '—'}): the request is approved and stays linked to that product — no product is created.`) }}
      </div>

      <TextArea field-class="mt-3" :model-value="v.note" :rows="2" :label="{ ar: 'ملاحظة الاعتماد (اختيارية)', en: 'Approval note (optional)' }" @update:model-value="set('note', $event)" />
    </template>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="success" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="blocked || (asks && !confirmed)" :label="creates ? { ar: 'اعتماد وإنشاء الصنف', en: 'Approve and create the product' } : { ar: 'اعتماد الطلب', en: 'Approve the request' }" @click="approve" />
        <Btn tone="soft" class="!h-11 w-[110px]" :disabled="act.pending.value" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
