<script setup>
// The form of a new-product request: raising a new one, editing one's own draft (or a request that was sent back),
// and — for a reviewer — correcting a request that waits for review.
//   <RequestForm :open="open" :number="numberOrNull" @close="…" @done="(number) => …" />
// While the form is typed the server is asked whether the product already exists (GET duplicates, half a second after
// the last key): a taken barcode or code blocks the sending, the same name asks for a confirmation when it is sent.
// «تقدير من الاسم» reads a first weight and carton from the name (GET estimate) — a proposal a person corrects.
// A new request is saved as a draft (POST), its pictures are added to it, and «حفظ وإرسال» then sends it; an edit
// sends only what changed (PATCH; an emptied field is sent as null).
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Drawer, ErrorBanner, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { toast } from '@/stores/ui';
import DuplicatesPanel from './DuplicatesPanel.vue';
import PickInput from './PickInput.vue';
import { API, STORAGE, STORAGE_OPTIONS, dataUrlBytes, readPicture, refName, sendForReview, sizeText, useMaster, useSection } from './preq';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The request being edited (number or id); null for a new one. */
  number: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const section = useSection();
const master = useMaster(() => props.open);
const act = useAction({ invalidate: ['product-requests'] });

const TEXT = ['nameAr', 'nameEn', 'brand', 'supplierName', 'packText', 'barcode', 'proposedSku', 'reason', 'customerName'];
const REFS = ['category', 'supplier', 'uom', 'packUom', 'customer'];
const NUMBERS = ['packQty', 'costPrice', 'salePrice', 'shelfLifeDays', 'weightKg', 'lengthCm', 'widthCm', 'heightCm'];
const PHYSICAL = ['weightKg', 'lengthCm', 'widthCm', 'heightCm'];
const blank = () => ({
  nameAr: '', nameEn: '', brand: '', category: '', supplier: '', supplierName: '', uom: '', packText: '', packQty: null, packUom: '', costPrice: null, salePrice: null, barcode: '', proposedSku: '',
  storageClass: 'ambient', tracksExpiry: false, shelfLifeDays: null, weightKg: null, lengthCm: null, widthCm: null, heightCm: null, reason: '', customer: '', customerName: '',
});
const num = (x) => (x == null || x === '' ? null : Number(x));
/** The values of the form for a request as the API sends it. */
const fromRequest = (r) => ({
  nameAr: r.nameAr || '', nameEn: r.nameEn || '', brand: r.brand || '', category: r.category?.id || '', supplier: r.supplier?.id || '', supplierName: r.supplier ? '' : r.supplierName || '',
  uom: r.uom?.id || '', packText: r.packText || '', packQty: num(r.packQty), packUom: r.packUom?.id || '', costPrice: num(r.costPrice), salePrice: num(r.salePrice), barcode: r.barcode || '', proposedSku: r.proposedSku || '',
  storageClass: r.storageClass || 'ambient', tracksExpiry: !!r.tracksExpiry, shelfLifeDays: num(r.shelfLifeDays), weightKg: num(r.weightKg), lengthCm: num(r.lengthCm), widthCm: num(r.widthCm), heightCm: num(r.heightCm),
  reason: r.reason || '', customer: r.customer?.id || '', customerName: r.customer ? '' : r.customerName || '',
});

// ---- state (declared before the watchers that run at once)
const v = reactive(blank());
/** What the request held when the form opened (an edit sends only what differs). */
let original = blank();
const errs = ref({});
/** The form holds the values it is about (a new one at once, an edit once its request is read). */
const filled = ref(false);
/** Pictures picked for a NEW request: sent once the draft exists. [{ key, fileName, base64, size }] */
const picked = ref([]);
const pictureError = ref(null);
const picker = ref(null);
/** The estimate that filled the weight and the dimensions (null once a person typed one of them). */
const estimated = ref(null);
const estimating = ref(false);
/** '' | 'save' | 'send' — the button that is working. */
const busy = ref('');
/** What the duplicates check found for what is typed (null before the first answer). */
const found = ref(null);
const checking = ref(false);
let checkTimer = null;
let checkSeq = 0;

// ---- the request being edited
const detail = useGet(() => (props.open && props.number ? `${API}/${encodeURIComponent(props.number)}` : null));
const editing = computed(() => (props.number ? detail.data.value || null : null));
/** A reviewer corrects a request that waits for review; its author edits his draft or what was sent back to him. */
const correcting = computed(() => !!editing.value && ['submitted', 'in_review'].includes(editing.value.status));
const canSend = computed(() => (props.number ? !!editing.value?.can?.submit : auth.can('preq.create')));
/** A link asked to edit a request this user may not edit (somebody else's, a closed one, one Odoo manages): it is shown, not saved. */
const locked = computed(() => !!editing.value && !editing.value.can?.edit);

function fill(values) {
  Object.assign(v, blank(), values);
  original = { ...blank(), ...values };
  errs.value = {};
  estimated.value = null;
  filled.value = true;
}
watch(() => [props.open, props.number], ([open, number]) => {
  if (!open) return;
  act.clearError();
  picked.value = [];
  pictureError.value = null;
  found.value = null;
  filled.value = false;
  if (!number) fill(blank());
}, { immediate: true });
watch(editing, (r) => { if (r && props.open && !filled.value) fill(fromRequest(r)); }, { immediate: true });

function set(k, x) {
  v[k] = x;
  if (errs.value[k]) { const n = { ...errs.value }; delete n[k]; errs.value = n; }
  if (k === 'supplier' && x) v.supplierName = '';
  if (k === 'customer' && x) v.customerName = '';
  if (PHYSICAL.includes(k)) estimated.value = null;
}

// ---- duplicates, while the form is typed
const probe = computed(() => ({ nameAr: v.nameAr.trim(), nameEn: v.nameEn.trim(), packText: v.packText.trim(), barcode: v.barcode.trim(), sku: v.proposedSku.trim() }));
async function check() {
  const p = probe.value;
  if (p.nameAr.length < 3 && p.nameEn.length < 3 && !p.barcode && !p.sku) { found.value = null; checking.value = false; return; }
  const seq = ++checkSeq;
  checking.value = true;
  try {
    const r = await api.get(`${API}/duplicates`, { ...p, except: props.number || undefined });
    if (seq === checkSeq) found.value = r;
  } catch {
    // the check is a help, not a gate: the server checks again when the request is sent
    if (seq === checkSeq) found.value = null;
  }
  if (seq === checkSeq) checking.value = false;
}
const checkSoon = (ms) => { clearTimeout(checkTimer); checkTimer = setTimeout(check, ms); };
watch(probe, () => { if (props.open && filled.value) checkSoon(450); });
watch(filled, (ready) => { if (ready && props.open) checkSoon(150); });
onBeforeUnmount(() => clearTimeout(checkTimer));

// ---- a first weight and carton from the name
const categoryName = computed(() => (master.lk.value?.categories || []).find((c) => c.id === v.category)?.nameAr || editing.value?.categoryName || '');
async function estimate() {
  if (v.nameAr.trim().length < 2) { errs.value = { ...errs.value, nameAr: t('اكتب اسم الصنف أولًا', 'Type the name of the product first') }; return; }
  estimating.value = true;
  try {
    const e = await api.get(`${API}/estimate`, { nameAr: v.nameAr.trim(), nameEn: v.nameEn.trim(), packText: v.packText.trim(), category: categoryName.value });
    PHYSICAL.forEach((k) => { v[k] = e[k]; });
    if (v.storageClass === original.storageClass && STORAGE[e.storageClass]) v.storageClass = e.storageClass;
    estimated.value = e;
    errs.value = Object.fromEntries(Object.entries(errs.value).filter(([k]) => !PHYSICAL.includes(k)));
  } catch (err) {
    toast.say(err && typeof err.localized === 'function' ? err.localized() : t('تعذّر التقدير', 'The estimate failed'), 3200);
  }
  estimating.value = false;
}

// ---- pictures of a new request
const limits = computed(() => section.limits.value);
const room = computed(() => Math.max(0, limits.value.pictures - picked.value.length));
function pick() { if (picker.value) picker.value.click(); }
async function onPick(e) {
  const files = [...(e.target.files || [])].slice(0, room.value);
  e.target.value = '';
  pictureError.value = null;
  for (const file of files) {
    try {
      const p = await readPicture(file, limits.value.pictureBytes);
      picked.value = [...picked.value, { key: `${Date.now()}-${picked.value.length}`, fileName: p.fileName, base64: p.base64, size: dataUrlBytes(p.base64) }];
    } catch (err) { pictureError.value = err; break; }
  }
}
const unpick = (key) => { picked.value = picked.value.filter((p) => p.key !== key); };

// ---- saving
function validate() {
  const e = {};
  if (v.nameAr.trim().length < 2) e.nameAr = t('اسم الصنف بالعربية مطلوب', 'The Arabic name is required');
  for (const k of ['costPrice', 'salePrice']) if (v[k] != null && !(Number(v[k]) >= 0)) e[k] = t('رقم غير صالح', 'Not a valid number');
  for (const k of [...PHYSICAL, 'packQty', 'shelfLifeDays']) if (v[k] != null && !(Number(v[k]) > 0)) e[k] = t('يجب أن يكون أكبر من صفر', 'Must be greater than zero');
  if (v.proposedSku.trim() && !/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/.test(v.proposedSku.trim())) e.proposedSku = t('3 إلى 32 حرفًا لاتينيًا أو رقمًا', '3 to 32 Latin letters or digits');
  errs.value = e;
  return !Object.keys(e).length;
}
const clean = (k) => (TEXT.includes(k) ? String(v[k] ?? '').trim() : v[k]);
/** A new request: what was filled. An edit: what differs from what the request held (an emptied field as null). */
function payload() {
  const out = {};
  for (const k of [...TEXT, ...REFS, ...NUMBERS, 'storageClass', 'tracksExpiry']) {
    const now = clean(k);
    const empty = now === '' || now == null;
    if (!props.number) {
      if (!empty && !(k === 'tracksExpiry' && now === false)) out[k] = now;
      continue;
    }
    const was = TEXT.includes(k) ? String(original[k] ?? '').trim() : original[k];
    if (now !== was && !(empty && (was === '' || was == null))) out[k] = empty ? null : now;
  }
  return out;
}
/** Server-side validation details land on their fields. */
watch(() => act.error.value, (e) => { if (e) { const fe = e.fieldErrors(); if (Object.keys(fe).length) errs.value = { ...errs.value, ...fe }; } });
const why = () => (act.error.value ? act.error.value.localized() : '');

async function save(send) {
  if (busy.value || locked.value || !validate()) return;
  busy.value = send ? 'send' : 'save';
  try {
    let number = props.number;
    let complete = true;
    if (!number) {
      const created = await act.run(() => api.postIdempotent(API, payload()), { success: false });
      if (!created) return;
      number = created.number;
      for (const p of picked.value) {
        const added = await act.run(() => api.postIdempotent(`${API}/${created.id}/pictures`, { fileName: p.fileName, base64: p.base64 }), { success: false, errorToast: false });
        if (added === undefined) {
          // the draft exists: it opens with what was saved, and says what did not go through
          toast.say(t(`حُفظت المسودة ${number} — تعذّر رفع صورة: ${why()}`, `Draft ${number} saved — a picture was not uploaded: ${why()}`), 5200);
          complete = false;
          break;
        }
      }
    } else {
      const body = payload();
      if (Object.keys(body).length && !(await act.run(() => api.patch(`${API}/${encodeURIComponent(number)}`, body), { success: false }))) return;
    }
    if (send && complete) {
      const sent = await sendForReview(act, number);
      if (sent === undefined && props.number) return; // an edit stays on screen with the reason
      if (sent === undefined) toast.say(t(`حُفظت المسودة ${number} ولم تُرسل: ${why() || 'لم يُؤكَّد الإرسال'}`, `Draft ${number} was saved and not sent: ${why() || 'the sending was not confirmed'}`), 5200);
    } else if (complete) {
      toast.say(correcting.value ? t('حُفظ التصحيح', 'Correction saved') : t(`حُفظ الطلب ${number}`, `Request ${number} saved`));
    }
    emit('done', number);
  } finally { busy.value = ''; }
}

const title = computed(() => (!props.number ? { ar: 'طلب صنف جديد', en: 'New product request' } : correcting.value ? { ar: `تصحيح الطلب ${props.number}`, en: `Correct request ${props.number}` } : { ar: `تعديل الطلب ${props.number}`, en: `Edit request ${props.number}` }));
const about = computed(() => (correcting.value
  ? { ar: 'تصحيحات المراجع تُسجَّل في سجل الطلب باسمه', en: 'A reviewer’s corrections are recorded in the history under his name' }
  : { ar: 'يُراجَع الطلب ثم يُنشأ الصنف في الكتالوج عند الاعتماد', en: 'The request is reviewed; on approval the product is created in the catalogue' }));
const blocked = computed(() => !!found.value?.blocked);
const notReady = computed(() => !!props.number && !filled.value);
</script>

<template>
  <Drawer :open="open" :title="title" :sub="about" :width="700" :z-index="70" @close="emit('close')">
    <ErrorBanner :error="detail.error.value" :closable="false" />
    <div v-if="notReady" class="col"><div class="skel h-[40px]" /><div class="skel h-[40px]" /><div class="skel h-[160px]" /></div>

    <form v-else class="form-grid" @submit.prevent="save(false)">
      <div v-if="locked" class="banner amber col-span-full !mb-0">
        <div>{{ editing.owned ? t('هذا الطلب يُدار في أودو: يُعدَّل هناك ويُعرض هنا للمتابعة.', 'This request is managed in Odoo: it is edited there and shown here to be followed.') : t('لا يمكنك تعديل هذا الطلب في حالته الحالية — القيم معروضة للقراءة فقط.', 'You cannot edit this request as it stands — the values are shown to be read only.') }}</div>
      </div>
      <div v-if="editing && editing.status === 'returned' && editing.returnReason" class="banner amber col-span-full !mb-0">
        <div><div>{{ t('أُعيد الطلب للتصحيح:', 'The request was sent back for correction:') }}</div><div class="font-bold" dir="auto">{{ editing.returnReason }}</div></div>
      </div>

      <TextInput :model-value="v.nameAr" :label="{ ar: 'اسم الصنف (عربي)', en: 'Product name (Arabic)' }" required full :placeholder="{ ar: 'مثال: عصير برتقال طازج (250ml*24pcs)', en: 'e.g. Fresh orange juice (250ml*24pcs)' }" :error="errs.nameAr" autofocus @update:model-value="set('nameAr', $event)" />
      <TextInput :model-value="v.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" full :error="errs.nameEn" @update:model-value="set('nameEn', $event)" />
      <TextInput :model-value="v.brand" :label="{ ar: 'العلامة التجارية', en: 'Brand' }" :error="errs.brand" @update:model-value="set('brand', $event)" />
      <SelectInput :model-value="v.category" :label="{ ar: 'التصنيف', en: 'Category' }" :options="master.categoryOptions.value" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errs.category"
                   :hint="editing && !v.category && editing.categoryName ? { ar: `كما كُتب في الطلب: ${editing.categoryName}`, en: `As written on the request: ${editing.categoryName}` } : null" @update:model-value="set('category', $event)" />
      <TextInput :model-value="v.barcode" :label="{ ar: 'الباركود', en: 'Barcode' }" dir="ltr" mono scan :error="errs.barcode" @update:model-value="set('barcode', $event)" />
      <TextInput :model-value="v.proposedSku" :label="{ ar: 'رمز الصنف المقترح', en: 'Proposed product code' }" dir="ltr" mono :hint="{ ar: 'اختياري — المراجع يحدد الرمز عند الاعتماد', en: 'Optional — the reviewer decides the code at approval' }" :error="errs.proposedSku" @update:model-value="set('proposedSku', $event)" />
      <PickInput :model-value="v.supplier" :label="{ ar: 'المورد', en: 'Supplier' }" :options="master.supplierOptions.value" :missing-label="refName(editing && editing.supplier)" :error="errs.supplier" @update:model-value="set('supplier', $event)" />
      <TextInput v-if="!v.supplier" :model-value="v.supplierName" :label="{ ar: 'أو مورد غير مسجَّل', en: 'Or a supplier not registered yet' }" :placeholder="{ ar: 'اسم المورد', en: 'Supplier name' }" :error="errs.supplierName" @update:model-value="set('supplierName', $event)" />

      <div class="col-span-full">
        <DuplicatesPanel :found="found" :loading="checking" show-clean />
      </div>

      <div class="col-span-full mt-1 text-[11px] font-extrabold text-ink">{{ t('العبوة والأسعار', 'Pack and prices') }}</div>
      <SelectInput :model-value="v.uom" :label="{ ar: 'وحدة المخزون', en: 'Stock unit' }" :options="master.uomOptions.value" :placeholder="{ ar: '—', en: '—' }" :error="errs.uom" @update:model-value="set('uom', $event)" />
      <TextInput :model-value="v.packText" :label="{ ar: 'وصف العبوة', en: 'Pack' }" dir="ltr" placeholder="250ml*24pcs" :error="errs.packText" @update:model-value="set('packText', $event)" />
      <NumberInput :model-value="v.packQty" :label="{ ar: 'وحدات في عبوة الشراء', en: 'Units per purchase pack' }" :min="1" :error="errs.packQty" @update:model-value="set('packQty', $event)" />
      <SelectInput :model-value="v.packUom" :label="{ ar: 'وحدة عبوة الشراء', en: 'Purchase pack unit' }" :options="master.uomOptions.value" :placeholder="{ ar: '—', en: '—' }" :error="errs.packUom" @update:model-value="set('packUom', $event)" />
      <NumberInput :model-value="v.costPrice" :label="{ ar: 'سعر التكلفة المقترح ر.س', en: 'Proposed cost SAR' }" :min="0" :error="errs.costPrice" @update:model-value="set('costPrice', $event)" />
      <NumberInput :model-value="v.salePrice" :label="{ ar: 'سعر البيع المقترح ر.س', en: 'Proposed selling price SAR' }" :min="0" :error="errs.salePrice" @update:model-value="set('salePrice', $event)" />

      <PillChoice :model-value="v.storageClass" :label="{ ar: 'شرط التخزين', en: 'Storage' }" full purple :options="STORAGE_OPTIONS" :error="errs.storageClass" @update:model-value="set('storageClass', $event)" />
      <div class="field">
        <span class="field-l">{{ t('الصلاحية', 'Expiry') }}</span>
        <button type="button" class="pill w-fit" :class="{ active: v.tracksExpiry }" :aria-pressed="v.tracksExpiry" @click="set('tracksExpiry', !v.tracksExpiry)">{{ v.tracksExpiry ? t('يُتتبَّع تاريخ الانتهاء ✓', 'Expiry dates are tracked ✓') : t('لا يُتتبَّع تاريخ الانتهاء', 'Expiry dates are not tracked') }}</button>
      </div>
      <NumberInput :model-value="v.shelfLifeDays" :label="{ ar: 'مدة الصلاحية (يوم)', en: 'Shelf life (days)' }" :min="1" :error="errs.shelfLifeDays" @update:model-value="set('shelfLifeDays', $event)" />

      <div class="col-span-full mt-1">
        <div class="row">
          <div class="grow text-[11px] font-extrabold text-ink">{{ t('وزن وحدة المخزون وأبعادها', 'Weight and dimensions of the stock unit') }}</div>
          <Btn tone="softPurple" size="sm" :loading="estimating" :label="{ ar: 'تقدير من الاسم', en: 'Estimate from the name' }" @click="estimate" />
        </div>
        <div class="mt-0.5 text-[9.5px] text-faint">{{ t('لا يُنشأ الصنف بلا وزن وأبعاد — يمكن تركها للمراجع.', 'The product cannot be created without them — they may be left to the reviewer.') }}</div>
      </div>
      <NumberInput :model-value="v.weightKg" :label="{ ar: 'الوزن كجم', en: 'Weight kg' }" :min="0" :error="errs.weightKg" @update:model-value="set('weightKg', $event)" />
      <NumberInput :model-value="v.lengthCm" :label="{ ar: 'الطول سم', en: 'Length cm' }" :min="0" :error="errs.lengthCm" @update:model-value="set('lengthCm', $event)" />
      <NumberInput :model-value="v.widthCm" :label="{ ar: 'العرض سم', en: 'Width cm' }" :min="0" :error="errs.widthCm" @update:model-value="set('widthCm', $event)" />
      <NumberInput :model-value="v.heightCm" :label="{ ar: 'الارتفاع سم', en: 'Height cm' }" :min="0" :error="errs.heightCm" @update:model-value="set('heightCm', $event)" />
      <div v-if="estimated" class="hint amber col-span-full !mt-0">
        {{ estimated.basis === 'default'
          ? t('لم يُكتب قياس في الاسم أو العبوة: وُضعت قيم مبدئية (كرتون 10 كجم) — استبدلها بالقياس الفعلي.', 'No measure is written in the name or the pack: placeholder values were put (a 10 kg carton) — replace them with the real ones.')
          : t('تقدير مقروء من الاسم والعبوة (الوزن الصافي المكتوب وكرتون بحجمه) — صحّحه بالقياس الفعلي إن عُرف.', 'An estimate read from the name and the pack (the stated net weight and a carton sized for it) — correct it with the measured values when they are known.') }}
      </div>

      <TextArea :model-value="v.reason" :label="{ ar: 'لماذا نحتاج هذا الصنف؟', en: 'Why is this product needed?' }" :placeholder="{ ar: 'من طلبه، الكمية المتوقعة، البديل الحالي…', en: 'Who asked, the expected quantity, the present alternative…' }" :error="errs.reason" @update:model-value="set('reason', $event)" />
      <PickInput :model-value="v.customer" :label="{ ar: 'العميل الذي طلبه', en: 'The customer who asked' }" :options="master.customerOptions.value" :missing-label="refName(editing && editing.customer)" :error="errs.customer" @update:model-value="set('customer', $event)" />
      <TextInput v-if="!v.customer" :model-value="v.customerName" :label="{ ar: 'أو اسم عميل غير مسجَّل', en: 'Or a customer not registered yet' }" :error="errs.customerName" @update:model-value="set('customerName', $event)" />

      <!-- pictures: a new request takes them with the save; an existing one keeps them on its own page -->
      <div class="col-span-full">
        <div class="field-l mb-1">{{ t('صور الصنف', 'Pictures of the product') }}</div>
        <template v-if="!number">
          <div class="flex flex-wrap gap-2">
            <div v-for="p in picked" :key="p.key" class="relative">
              <img :src="p.base64" alt="" class="h-[72px] w-[72px] rounded-xl border border-line object-cover">
              <button type="button" class="x-btn absolute end-1 top-1 !h-5 !w-5 !rounded-md !bg-white/90 !text-[9px] !text-bad" :aria-label="t('إزالة', 'Remove')" @click="unpick(p.key)">✕</button>
              <div class="num ltr mt-0.5 text-center text-[8.5px] text-faint">{{ sizeText(p.size) }}</div>
            </div>
            <template v-if="room > 0">
              <input ref="picker" type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="hidden" @change="onPick">
              <button type="button" class="flex h-[72px] w-[72px] cursor-pointer flex-col items-center justify-center gap-0.5 rounded-xl border border-dashed border-[#DCD2EE] bg-[#FAF8FD] text-violet" @click="pick">
                <span class="text-[17px] leading-none">+</span><span class="text-[8.5px] font-extrabold">{{ t('صورة', 'Picture') }}</span>
              </button>
            </template>
          </div>
          <div class="mt-1 text-[9px] text-faint">{{ t(`حتى ${fmtNum(limits.pictures)} صور — تُصغَّر صور الهاتف قبل الرفع`, `Up to ${fmtNum(limits.pictures)} pictures — phone photos are shrunk before they are sent`) }}</div>
          <ErrorBanner :error="pictureError" class="!mb-0 mt-1.5" @close="pictureError = null" />
        </template>
        <div v-else class="text-[10px] text-muted">{{ t('الصور تُضاف وتُزال من صفحة الطلب نفسها.', 'Pictures are added and removed on the page of the request itself.') }}</div>
      </div>
    </form>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div v-if="blocked" class="mb-2 text-[10px] font-bold text-bad">{{ t('الباركود أو الرمز مستخدم: يُحفظ الطلب مسودة، ولا يُرسل قبل تصحيحه.', 'The barcode or the code is taken: the request can be saved as a draft, and is not sent before that is corrected.') }}</div>
      <div class="flex flex-wrap gap-2">
        <Btn v-if="canSend && !correcting && !locked" tone="primary" class="!h-11 min-w-[150px] flex-1 !text-[11.5px]" :loading="busy === 'send'" :disabled="blocked || notReady || busy === 'save'" :label="{ ar: 'حفظ وإرسال للمراجعة', en: 'Save and send for review' }" @click="save(true)" />
        <Btn tone="dark" class="!h-11 min-w-[120px] flex-1 !text-[11.5px]" :loading="busy === 'save'" :disabled="notReady || locked || busy === 'send'" :label="correcting ? { ar: 'حفظ التصحيح', en: 'Save the correction' } : number ? { ar: 'حفظ', en: 'Save' } : { ar: 'حفظ مسودة', en: 'Save as a draft' }" @click="save(false)" />
        <Btn tone="soft" class="!h-11 w-[100px]" :disabled="!!busy" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t('* الاسم إلزامي · كل تعديل يُسجَّل في سجل الطلب وفي Audit Trail', '* the name is required · every change is recorded in the history of the request and in the audit trail') }}</div>
    </template>
  </Drawer>
</template>
