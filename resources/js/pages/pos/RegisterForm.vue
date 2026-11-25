<script setup>
// Create / change a register (pos.manage): the warehouse it sells from, its walk-in customer, the largest line
// discount, selling beyond stock, where refunds go back, whether refunds need a manager, the receipt (format, footer,
// seller details for the ZATCA receipt), the account cash differences go to, and the payment methods it takes.
//   POST /pos/registers { code, nameAr, warehouse, … }  ·  PATCH /pos/registers/{id} { …same keys, active }
// The warehouses come from GET /warehouses (the master list other pages use — read only here).
import { computed } from 'vue';
import { api, useGet } from '@/api/client';
import { FormDrawer, Chip } from '@/components';
import { t } from '@/i18n';
import { METHOD_KIND, RECEIPT_FORMAT, RESTOCK, nameOf, usePosLookups } from './pos';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The register being changed; null = a new one. */
  register: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = usePosLookups();
const whs = useGet(() => (props.open ? '/warehouses' : null), { pageSize: 200 });
const warehouses = computed(() => (Array.isArray(whs.data.value) ? whs.data.value : whs.data.value?.items || []));
const whOptions = computed(() => {
  const list = warehouses.value.map((w) => ({ v: w.id, l: { ar: `${w.code} · ${w.nameAr || w.nameEn}`, en: `${w.code} · ${w.nameEn || w.nameAr}` } }));
  const cur = props.register?.warehouse;
  if (cur && props.register.warehouseId && !list.some((o) => o.v === props.register.warehouseId)) list.unshift({ v: props.register.warehouseId, l: { ar: `${cur.code} · ${cur.nameAr}`, en: `${cur.code} · ${cur.nameEn || cur.nameAr}` } });
  return list;
});
const whFailed = computed(() => !!whs.error.value);

const YES_NO = [['yes', { ar: 'نعم', en: 'Yes' }], ['no', { ar: 'لا', en: 'No' }]];
const flag = (b) => (b ? 'yes' : 'no');
const yes = (v) => v === 'yes';
const editing = computed(() => !!props.register);

const fields = computed(() => [
  ...(editing.value ? [] : [{ k: 'code', label: { ar: 'رمز الصندوق', en: 'Register code' }, required: true, dir: 'ltr', ph: { ar: 'مثال: POS-RYD-1', en: 'e.g. POS-RYD-1' }, validate: (v) => (/^[A-Za-z0-9_-]{2,20}$/.test(String(v || '')) ? null : t('حروف لاتينية وأرقام و - _ فقط (2–20)', 'Latin letters, digits, - and _ only (2–20)')) }]),
  { k: 'nameAr', label: { ar: 'اسم الصندوق', en: 'Name (AR)' }, required: true },
  { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (EN)' }, dir: 'ltr' },
  whFailed.value
    ? { k: 'warehouse', label: { ar: 'المستودع (الرمز)', en: 'Warehouse (code)' }, required: true, dir: 'ltr', hint: { ar: 'تعذر تحميل قائمة المستودعات — اكتب الرمز', en: 'The warehouse list could not load — type the code' } }
    : { k: 'warehouse', label: { ar: 'المستودع الذي يبيع منه', en: 'Sells from warehouse' }, type: 'select', required: true, opts: whOptions.value },
  { k: 'customer', label: { ar: 'العميل النقدي الافتراضي (الرمز)', en: 'Walk-in customer (code)' }, dir: 'ltr', hint: { ar: 'يُباع له حين لا يُختار عميل', en: 'Used when no customer is chosen' } },
  { k: 'maxDiscountPct', label: { ar: 'أقصى خصم للسطر %', en: 'Max line discount %' }, type: 'num', min: 0, max: 100, def: 0 },
  { k: 'allowNegativeStock', label: { ar: 'البيع بأكثر من الرصيد؟', en: 'Sell beyond stock?' }, type: 'select', opts: YES_NO, def: 'no', hint: { ar: '«نعم»: يُباع ويُسجَّل الباقي دَينًا على المخزون', en: '"Yes": it sells; the rest is owed by stock' } },
  { k: 'refundRestock', label: { ar: 'المرتجع يعود', en: 'Refunds go back' }, type: 'select', opts: Object.entries(RESTOCK), def: 'shelf' },
  { k: 'refundNeedsManager', label: { ar: 'الاسترجاع يحتاج مديرًا؟', en: 'Refunds need a manager?' }, type: 'select', opts: YES_NO, def: 'no' },
  { k: 'receiptFormat', label: { ar: 'شكل الإيصال', en: 'Receipt format' }, type: 'select', opts: Object.entries(RECEIPT_FORMAT), def: '80mm' },
  { k: 'differenceAccount', label: { ar: 'حساب فروق النقد (الرمز)', en: 'Cash difference account (code)' }, dir: 'ltr', hint: { ar: 'فارغ = حساب المصروفات الإدارية', en: 'Empty = administrative expenses' } },
  { k: 'sellerNameAr', label: { ar: 'اسم البائع في الإيصال', en: 'Seller name (AR)' } },
  { k: 'sellerNameEn', label: { ar: 'اسم البائع بالإنجليزية', en: 'Seller name (EN)' }, dir: 'ltr' },
  { k: 'sellerVatNo', label: { ar: 'الرقم الضريبي للبائع', en: 'Seller VAT no.' }, dir: 'ltr', validate: (v) => (!v || /^\d{15}$/.test(String(v)) ? null : t('15 رقمًا', '15 digits')) },
  { k: 'sellerCr', label: { ar: 'السجل التجاري', en: 'Commercial registration' }, dir: 'ltr' },
  { k: 'sellerAddress', label: { ar: 'عنوان البائع', en: 'Seller address' }, full: true },
  { k: 'receiptFooter', label: { ar: 'تذييل الإيصال', en: 'Receipt footer' }, type: 'area' },
  { k: 'methods', label: { ar: 'طرق الدفع', en: 'Payment methods' }, full: true, def: [] },
  ...(editing.value ? [{ k: 'active', label: { ar: 'نشط؟', en: 'Active?' }, type: 'select', opts: YES_NO, hint: { ar: '«لا» يوقفه: لا تُفتح عليه جلسات', en: '"No" switches it off: no session opens on it' } }] : []),
]);

const initial = computed(() => {
  const r = props.register;
  if (!r) return { methods: [] };
  return {
    nameAr: r.nameAr, nameEn: r.nameEn || '', warehouse: whFailed.value ? r.warehouse?.code || '' : r.warehouseId || '', customer: r.customer?.code || '', maxDiscountPct: r.maxDiscountPct != null ? Number(r.maxDiscountPct) : 0,
    allowNegativeStock: flag(r.allowNegativeStock), refundRestock: r.refundRestock || 'shelf', refundNeedsManager: flag(r.refundNeedsManager), receiptFormat: r.receiptFormat || '80mm',
    differenceAccount: r.differenceAccountId || '', sellerNameAr: r.sellerNameAr || '', sellerNameEn: r.sellerNameEn || '', sellerVatNo: r.sellerVatNo || '', sellerCr: r.sellerCr || '',
    sellerAddress: r.sellerAddress || '', receiptFooter: r.receiptFooter || '', methods: (r.methods || []).map((m) => m.id), active: flag(r.active),
  };
});

const OPTIONAL = ['nameEn', 'customer', 'receiptFooter', 'sellerNameAr', 'sellerNameEn', 'sellerVatNo', 'sellerCr', 'sellerAddress', 'differenceAccount'];
function submit(v) {
  const body = {
    nameAr: v.nameAr, warehouse: v.warehouse, maxDiscountPct: v.maxDiscountPct ?? 0, allowNegativeStock: yes(v.allowNegativeStock), refundRestock: v.refundRestock || 'shelf',
    refundNeedsManager: yes(v.refundNeedsManager), receiptFormat: v.receiptFormat || '80mm', methods: Array.isArray(v.methods) ? v.methods : [],
  };
  // an emptied optional field is cleared on a change (null); on a new register it is simply left out
  for (const k of OPTIONAL) { if (v[k] != null && v[k] !== '') body[k] = String(v[k]).trim(); else if (editing.value) body[k] = null; }
  if (editing.value) {
    body.active = v.active !== 'no';
    return api.patch(`/pos/registers/${encodeURIComponent(props.register.id)}`, body);
  }
  body.code = String(v.code).trim().toUpperCase();
  return api.postIdempotent('/pos/registers', body);
}
const title = computed(() => (editing.value ? { ar: `تعديل الصندوق ${props.register.code}`, en: `Change register ${props.register.code}` } : { ar: 'صندوق جديد', en: 'New register' }));
const toggle = (list, id) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
</script>

<template>
  <FormDrawer :open="open" :title="title" :fields="fields" :initial="initial" :submit="submit" :width="600"
              :action="{ success: t('حُفظ الصندوق', 'Register saved'), invalidate: ['pos'] }" @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-methods="{ value, set }">
      <div class="field-l">{{ t('طرق الدفع التي يقبلها', 'Payment methods it takes') }}</div>
      <div v-if="!lk.methods.value.length" class="text-[11px] text-muted">{{ t('لا طرق دفع بعد — أضفها من «طرق الدفع».', 'No payment method yet — add them under "Payment methods".') }}</div>
      <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
        <label v-for="m in lk.methods.value" :key="m.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-line px-2.5 py-1.5 text-[11.5px]" :class="{ 'opacity-60': !m.active }">
          <input type="checkbox" :checked="(value || []).includes(m.id)" @change="set(toggle(value || [], m.id))">
          <span class="min-w-0 flex-1 truncate font-bold" dir="auto">{{ nameOf(m) }} <span class="num text-muted">{{ m.code }}</span></span>
          <Chip small :map="METHOD_KIND" :k="m.kind" />
        </label>
      </div>
    </template>
  </FormDrawer>
</template>
