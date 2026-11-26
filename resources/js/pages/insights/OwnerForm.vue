<script setup>
// Create / edit a brand owner (brandowner.manage) — POST /insights/brands, PATCH /insights/brands/{id}:
// { name, userId, brandName?, contactCustomerId?, dateStart?, allowInvoice?, allowPayment?, allowReturn?, allowMargin?,
//   notes?, productIds?, categoryIds? }. The users, categories and brand names come from GET /insights/lookups; a user
// already linked to another owner is offered but disabled (the server refuses it: BRAND_USER_TAKEN).
// The brand's products = the named products + the products of the named categories.
//   <OwnerForm :open :owner="detailOrNull" @close @done="(owner) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, Field, SelectInput, TextArea, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import CustomerPicker from './CustomerPicker.vue';
import ProductsPicker from './ProductsPicker.vue';
import { nameOf, useInsightsLookups, userName } from './insights';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** GET /insights/brands/{id} when editing; null to create. */
  owner: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = useInsightsLookups(() => props.open);
const f = reactive({ name: '', userId: '', brandName: '', contact: null, dateStart: '', allowInvoice: true, allowPayment: false, allowReturn: true, allowMargin: false, notes: '', products: [], categoryIds: [] });
const errors = ref({});
const catQ = ref('');
const act = useAction();

function reset() {
  const o = props.owner;
  Object.assign(f, {
    name: o?.name || '', userId: o?.user?.id ? String(o.user.id) : '', brandName: o?.brandName || '',
    contact: o?.contact?.id ? { id: o.contact.id, code: o.contact.code, nameAr: o.contact.nameAr, nameEn: o.contact.nameEn } : null,
    dateStart: o?.dateStart || '', allowInvoice: o ? !!o.allowInvoice : true, allowPayment: o ? !!o.allowPayment : false, allowReturn: o ? !!o.allowReturn : true, allowMargin: o ? !!o.allowMargin : false,
    notes: o?.notes || '', products: (o?.products || []).map((p) => ({ id: p.id, sku: p.sku, nameAr: p.nameAr, nameEn: p.nameEn })), categoryIds: (o?.categories || []).map((c) => String(c.id)),
  });
  errors.value = {}; catQ.value = ''; act.clearError();
}
watch(() => props.open, (v) => { if (v) reset(); }, { immediate: true });

const userOptions = computed(() => lk.users.value.map((u) => {
  const taken = u.ownerId && (!props.owner || String(u.ownerId) !== String(props.owner.id));
  return { v: String(u.id), l: `${userName(u)} (${u.username})${taken ? ` — ${t('مرتبط بمالك آخر', 'linked to another owner')}` : ''}`, disabled: !!taken };
}));
const brandListId = 'ins-brand-names';
const categories = computed(() => {
  const needle = catQ.value.trim().toLowerCase();
  return lk.categories.value.filter((c) => !needle || `${c.code} ${c.nameAr} ${c.nameEn}`.toLowerCase().includes(needle));
});
const toggleCat = (id, on) => { const s = new Set(f.categoryIds); if (on) s.add(String(id)); else s.delete(String(id)); f.categoryIds = [...s]; };
const FLAGS = [
  { k: 'allowInvoice', ar: 'يرى الفواتير والمتأخرات', en: 'Sees invoices and overdue' },
  { k: 'allowReturn', ar: 'يرى المرتجعات', en: 'Sees returns' },
  { k: 'allowPayment', ar: 'يرى المدفوعات', en: 'Sees payments' },
  { k: 'allowMargin', ar: 'يرى هامش الربح', en: 'Sees margin' },
];

async function submit() {
  const errs = {};
  if (f.name.trim().length < 2) errs.name = t('الاسم حرفان على الأقل', 'At least two characters');
  if (!f.userId && !props.owner) errs.userId = t('اختر المستخدم الذي يدخل به المالك', 'Pick the user the owner signs in with');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = {
    name: f.name.trim(), brandName: f.brandName.trim() || null, contactCustomerId: f.contact?.id || null, dateStart: f.dateStart || null,
    allowInvoice: f.allowInvoice, allowPayment: f.allowPayment, allowReturn: f.allowReturn, allowMargin: f.allowMargin, notes: f.notes.trim() || null,
    productIds: f.products.map((p) => String(p.id)), categoryIds: f.categoryIds,
  };
  if (f.userId) body.userId = f.userId;
  else if (props.owner) body.userId = null;
  const res = await act.run(() => (props.owner ? api.patch(`/insights/brands/${encodeURIComponent(props.owner.id)}`, body) : api.postIdempotent('/insights/brands', body)), {
    success: props.owner ? t('حُفظ مالك العلامة', 'Brand owner saved') : t('أُضيف مالك العلامة (مسودة — فعّله بعد المراجعة)', 'Brand owner added (draft — activate it once checked)'), invalidate: ['insights'],
  });
  if (res) { emit('done', res); emit('close'); }
}
watch(() => act.error.value, (e) => { if (e) { const fe = e.fieldErrors(); if (Object.keys(fe).length) errors.value = { ...errors.value, ...fe }; } });
</script>

<template>
  <Drawer :open="open" :title="owner ? { ar: `تعديل ${owner.name}`, en: `Edit ${owner.name}` } : { ar: 'مالك علامة جديد', en: 'New brand owner' }" :width="620" :z-index="90" @close="emit('close')">
    <ErrorBanner :error="lk.query.error.value" :closable="false" />
    <form class="form-grid" @submit.prevent="submit">
      <TextInput v-model="f.name" full required :label="{ ar: 'اسم المالك', en: 'Owner name' }" :error="errors.name" />
      <SelectInput v-model="f.userId" full :required="!owner" :options="userOptions" :label="{ ar: 'المستخدم الذي يدخل به', en: 'User he signs in with' }" :error="errors.userId"
                   :placeholder="{ ar: owner ? '— بلا مستخدم —' : '— اختر —', en: owner ? '— no user —' : '— select —' }" :hint="{ ar: 'حساب المالك يرى علامته فقط، ولا يُفعَّل مالك بلا مستخدم.', en: 'The owner\'s account sees his brand only; an owner without a user cannot be activated.' }" />
      <TextInput v-model="f.brandName" :label="{ ar: 'اسم العلامة', en: 'Brand name' }" :list="brandListId" :error="errors.brandName" :hint="{ ar: 'كما يُكتب في حقل العلامة بالمنتجات', en: 'As written in the brand field of the products' }" />
      <DateInput v-model="f.dateStart" :label="{ ar: 'تاريخ البداية', en: 'Start date' }" :error="errors.dateStart" />
      <datalist :id="brandListId"><option v-for="b in lk.brands.value" :key="b.brand" :value="b.brand">{{ b.brand }} ({{ b.products }})</option></datalist>
      <CustomerPicker v-model="f.contact" :label="{ ar: 'جهة الاتصال (عميل، اختياري)', en: 'Contact (customer, optional)' }" :error="errors.contactCustomerId" />

      <Field full :label="{ ar: 'ما يراه حساب المالك (المشتريات دائمًا)', en: 'What the owner\'s account sees (purchases always)' }">
        <div class="row wrap !gap-x-4 !gap-y-1.5">
          <label v-for="x in FLAGS" :key="x.k" class="inline-flex cursor-pointer items-center gap-1.5 text-[11px] font-bold text-sec">
            <input v-model="f[x.k]" type="checkbox" class="h-4 w-4">{{ t(x.ar, x.en) }}
          </label>
        </div>
      </Field>

      <Field full :label="{ ar: `فئات العلامة (${fmtNum(f.categoryIds.length)})`, en: `Brand categories (${fmtNum(f.categoryIds.length)})` }" :error="errors.categoryIds">
        <TextInput v-model="catQ" small type="search" :placeholder="{ ar: 'تصفية الفئات…', en: 'Filter categories…' }" />
        <div class="mt-1.5 max-h-[180px] overflow-auto rounded-[12px] border border-line-2 px-3 py-1.5">
          <div v-if="lk.query.isLoading.value" class="skel h-10" />
          <div v-else-if="!categories.length" class="empty !p-2">{{ t('لا فئات.', 'No category.') }}</div>
          <label v-for="c in categories" :key="c.id" class="flex cursor-pointer items-center gap-2 py-1 text-[10.5px]">
            <input type="checkbox" class="h-4 w-4" :checked="f.categoryIds.includes(String(c.id))" @change="toggleCat(c.id, $event.target.checked)">
            <span class="cell-id min-w-[70px]">{{ c.code }}</span><span class="min-w-0 flex-1 truncate" dir="auto">{{ nameOf(c) }}</span>
          </label>
        </div>
      </Field>
      <ProductsPicker v-model="f.products" :label="{ ar: 'منتجات مسماة', en: 'Named products' }" :hint="{ ar: 'تُضاف إلى منتجات الفئات أعلاه.', en: 'Added to the products of the categories above.' }" />
      <div v-if="errors.productIds" class="field-err full">{{ errors.productIds }}</div>
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :error="errors.notes" />
    </form>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t('يُسجَّل كل تغيير في سجل التدقيق. المالك الجديد يبدأ مسودة.', 'Every change is recorded in the audit trail. A new owner starts as a draft.') }}</div>
    </template>
  </Drawer>
</template>
