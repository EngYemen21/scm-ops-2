<script setup>
// One rule of a price list — new (POST /pricing/lists/{id}/rules) or changed (PATCH /pricing/rules/{id}).
// What it covers: a product, a category (with its sub-categories) or everything. How it prices: a fixed price, the
// base less a percentage, or a formula (base − % → rounded → + amount → kept between a floor and a ceiling measured
// from the base). The base is the product's list price, its cost, or its price on another list.
// The server refuses a second rule with the same target, minimum quantity and overlapping period (409
// PRICE_RULE_DUPLICATE): the form then offers to show the rule that is already there (`@find` with the product code).
import { computed, ref, watch } from 'vue';
import { api, isApiError } from '@/api/client';
import { Btn, FormDrawer } from '@/components';
import { bi, num, t } from '@/i18n';
import PickRecord from './PickRecord.vue';
import { APPLIES, BASE, COMPUTE, usePricingLookups } from './pricing';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The list the rule belongs to: { id, code, name, currency }. */
  list: { type: Object, default: null },
  /** The rule being changed; null = a new one. */
  rule: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done', 'find']);

const lk = usePricingLookups();
const options = (map) => Object.entries(map).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
/** Lists a rule may start from: active, another list, the same currency. */
const baseLists = computed(() => lk.lists.value.filter((l) => l.active && l.code !== props.list?.code && l.currency === props.list?.currency).map((l) => ({ v: l.code, l: `${l.name} · ${l.code}` })));
const derived = (v) => v.compute && v.compute !== 'fixed';
const amount = (k, label, more = {}) => ({ k, label, type: 'num', visible: (v) => v.compute === 'formula', ...more });

const fields = computed(() => [
  { k: 'appliesTo', label: { ar: 'تنطبق على', en: 'Covers' }, type: 'select', required: true, opts: options(APPLIES) },
  { k: 'product', label: { ar: 'المنتج', en: 'Product' }, required: true, visible: (v) => v.appliesTo === 'product' },
  { k: 'category', label: { ar: 'الفئة', en: 'Category' }, type: 'select', required: true, opts: lk.categoryOptions.value, visible: (v) => v.appliesTo === 'category', hint: { ar: 'تشمل فئاتها الفرعية', en: 'Its sub-categories are covered too' } },
  { k: 'compute', label: { ar: 'طريقة التسعير', en: 'How it prices' }, type: 'select', required: true, opts: options(COMPUTE) },
  { k: 'fixedPrice', label: { ar: `السعر (${props.list?.currency || ''})`, en: `Price (${props.list?.currency || ''})` }, type: 'num', required: true, min: 0, visible: (v) => v.compute === 'fixed',
    validate: (x) => (num(x) > 0 ? null : t('أكبر من صفر', 'Greater than zero')) },
  { k: 'base', label: { ar: 'يبدأ من', en: 'Starts from' }, type: 'select', required: true, opts: options(BASE), visible: derived },
  { k: 'baseList', label: { ar: 'القائمة الأساس', en: 'The base list' }, type: 'select', required: true, opts: baseLists.value, visible: (v) => derived(v) && v.base === 'pricelist',
    hint: baseLists.value.length ? null : { ar: 'لا قائمة أخرى فعّالة بالعملة نفسها', en: 'No other active list in the same currency' } },
  { k: 'percent', label: { ar: 'نسبة الخصم %', en: 'Discount %' }, type: 'num', required: true, max: 100, visible: (v) => v.compute === 'percent',
    hint: { ar: 'قيمة سالبة = زيادة على الأساس', en: 'A negative value is a mark-up' }, validate: (x) => (num(x) !== 0 ? null : t('لا تكون صفرًا', 'Not zero')) },
  amount('discountPct', { ar: 'خصم % من الأساس', en: 'Discount % off the base' }, { max: 100, hint: { ar: 'سالبة = زيادة', en: 'Negative = a mark-up' } }),
  amount('roundTo', { ar: 'تقريب إلى مضاعفات', en: 'Round to a multiple of' }, { min: 0, hint: { ar: 'مثال: 0.5 أو 5 — صفر = بلا تقريب', en: 'e.g. 0.5 or 5 — zero = no rounding' } }),
  amount('surcharge', { ar: 'إضافة مبلغ بعد التقريب', en: 'Add an amount after rounding' }),
  amount('minMargin', { ar: 'حد أدنى: الأساس +', en: 'Floor: base +' }, { hint: { ar: 'صفر = بلا حد أدنى', en: 'Zero = no floor' } }),
  amount('maxMargin', { ar: 'حد أعلى: الأساس +', en: 'Ceiling: base +' }, { hint: { ar: 'صفر = بلا حد أعلى', en: 'Zero = no ceiling' } }),
  { k: 'minQty', label: { ar: 'من كمية', en: 'From quantity' }, type: 'num', min: 0, hint: { ar: 'صفر = أي كمية', en: 'Zero = any quantity' } },
  { k: 'validFrom', label: { ar: 'من تاريخ', en: 'From' }, type: 'date', validate: (v, all) => (v && all.validTo && v > all.validTo ? t('بعد تاريخ النهاية', 'After the end date') : null) },
  { k: 'validTo', label: { ar: 'إلى تاريخ', en: 'Until' }, type: 'date', hint: { ar: 'فارغ = دائمة', en: 'Empty = open-ended' } },
  { k: 'note', label: { ar: 'ملاحظة', en: 'Note' }, type: 'area' },
]);

const initial = computed(() => {
  const r = props.rule;
  if (!r) return { appliesTo: 'product', compute: 'fixed', base: 'list', minQty: 0 };
  const n = (v) => (v == null ? null : num(v));
  return {
    appliesTo: r.appliesTo, product: r.product?.sku || '', category: r.categoryId || '', compute: r.compute, fixedPrice: n(r.fixedPrice), percent: n(r.percent),
    base: r.compute === 'fixed' ? 'list' : r.base, baseList: r.baseList?.code || '', discountPct: n(r.discountPct), roundTo: n(r.roundTo), surcharge: n(r.surcharge), minMargin: n(r.minMargin),
    maxMargin: n(r.maxMargin), minQty: n(r.minQty) ?? 0, validFrom: r.validFrom || '', validTo: r.validTo || '', note: r.note || '',
  };
});

/** The product code of a refused twin: the form offers to show the rule that is already there. */
const twinOf = ref(null);
watch(() => props.open, () => { twinOf.value = null; });

async function submit(v) {
  twinOf.value = null;
  const body = { appliesTo: v.appliesTo, compute: v.compute, minQty: v.minQty ?? 0, validFrom: v.validFrom || null, validTo: v.validTo || null, note: v.note || null };
  if (v.appliesTo === 'product') body.product = v.product;
  if (v.appliesTo === 'category') body.category = v.category;
  if (v.compute === 'fixed') body.fixedPrice = v.fixedPrice;
  else {
    body.base = v.base || 'list';
    if (body.base === 'pricelist') body.baseList = v.baseList;
    if (v.compute === 'percent') body.percent = v.percent;
    else Object.assign(body, { discountPct: v.discountPct ?? 0, roundTo: v.roundTo ?? 0, surcharge: v.surcharge ?? 0, minMargin: v.minMargin ?? 0, maxMargin: v.maxMargin ?? 0 });
  }
  try {
    return await (props.rule ? api.patch(`/pricing/rules/${encodeURIComponent(props.rule.id)}`, body) : api.postIdempotent(`/pricing/lists/${encodeURIComponent(props.list.id)}/rules`, body));
  } catch (e) {
    if (isApiError(e) && e.code === 'PRICE_RULE_DUPLICATE') twinOf.value = v.appliesTo === 'product' ? v.product : bi(APPLIES[v.appliesTo]);
    throw e;
  }
}
</script>

<template>
  <FormDrawer :open="open" :title="rule ? { ar: 'تعديل القاعدة', en: 'Edit the rule' } : { ar: 'قاعدة جديدة', en: 'New rule' }" :sub="list ? `${list.name} · ${list.code}` : null" :fields="fields" :initial="initial"
              :submit="submit" :action="{ success: rule ? { ar: 'حُفظت القاعدة ✓', en: 'Rule saved ✓' } : { ar: 'أُضيفت القاعدة ✓', en: 'Rule added ✓' }, invalidate: ['pricing'] }"
              @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-product="{ value, set }">
      <PickRecord kind="product" :model-value="value || ''" :label="{ ar: 'المنتج', en: 'Product' }" required @update:model-value="set" />
    </template>
    <template #after>
      <div v-if="twinOf" class="hint amber">
        {{ t('في القائمة قاعدة فعّالة للهدف نفسه والكمية نفسها — عدّلها بدل إضافة ثانية.', 'The list already has an active rule for the same target and quantity — change it instead of adding a second one.') }}
        <Btn size="sm" tone="soft" class="ms-1" :label="{ ar: 'عرض القاعدة الموجودة', en: 'Show the existing rule' }" @click="emit('find', twinOf)" />
      </div>
      <div class="hint">
        {{ t('أي قاعدة تحسم سعر منتج: قاعدة المنتج قبل قاعدة فئته قبل قاعدة «كل المنتجات»، ثم الأعلى حدًّا أدنى للكمية الذي بلغته الكمية، ثم الأحدث.',
          'Which rule decides a product\'s price: its own rule before its category\'s before the rule of everything; then the highest minimum quantity that the quantity reaches; then the newest.') }}
      </div>
    </template>
  </FormDrawer>
</template>
