<script setup>
// The answer of the price service for a customer (GET /pricing/price): the price, the sentence that says why, the list
// the customer was priced with, the rule that decided, the steps of the calculation and — on demand — the other rules
// that were looked at with why each lost.
//   <PriceAnswer :answer="answer" @open-list="(code) => …" />
import { computed, ref } from 'vue';
import { Chip, KV } from '@/components';
import { bi, t } from '@/i18n';
import { BASIS, BASIS_HELP, OUTCOME, SOURCE, VIA, day, nameOf, periodText, plain, price, ruleTarget } from './pricing';

const props = defineProps({
  answer: { type: Object, required: true },
});
const emit = defineEmits(['open-list']);

const a = computed(() => props.answer);
const showAll = ref(false);
const others = computed(() => (a.value.considered || []).filter((x) => x.outcome !== 'applied'));
const basis = computed(() => (a.value.priceList?.via === 'assigned' ? a.value.assignment?.basis : null));
</script>

<template>
  <div class="col !gap-3">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-2">
      <div class="tile" :class="a.found ? 'green' : 'red'">
        <div class="tile-v !text-[22px]">{{ a.found ? price(a.price) : '—' }}<span v-if="a.found" class="ms-1 text-[10px] font-bold text-muted">{{ a.currency }}</span></div>
        <div class="tile-l">{{ t('سعر الوحدة', 'Unit price') }} · {{ t('الكمية', 'qty') }} <span class="num">{{ plain(a.qty) }}</span> · <span class="num">{{ day(a.date) }}</span></div>
      </div>
      <div class="tile">
        <div class="tile-v">{{ a.listPrice != null ? price(a.listPrice) : '—' }}</div>
        <div class="tile-l">{{ t('السعر المعلن للمنتج', "The product's list price") }}</div>
      </div>
      <div v-if="a.found && a.discountPct != null" class="tile" :class="a.discountPct > 0 ? 'purple' : a.discountPct < 0 ? 'amber' : ''">
        <div class="tile-v">{{ plain(Math.abs(a.discountPct)) }}%</div>
        <div class="tile-l">{{ a.discountPct >= 0 ? t('أقل من السعر المعلن', 'below the list price') : t('أعلى من السعر المعلن', 'above the list price') }}</div>
      </div>
      <div v-if="a.found" class="tile">
        <div class="tile-v">{{ price(a.price * a.qty) }}</div>
        <div class="tile-l">{{ t('إجمالي السطر قبل الضريبة', 'Line total before VAT') }}</div>
      </div>
    </div>

    <div class="hint !mt-0" :class="a.found ? 'teal' : 'amber'">
      <div class="row wrap !gap-1.5">
        <Chip small :map="SOURCE" :k="a.source" />
        <span class="font-extrabold" dir="auto">{{ bi(a.reason) }}</span>
      </div>
      <div v-for="(n, i) in a.notes || []" :key="i" class="mt-1 font-bold text-warn" dir="auto">⚠ {{ bi(n) }}</div>
    </div>

    <div class="kv-grid">
      <KV :k="{ ar: 'العميل', en: 'Customer' }"><span v-if="a.customer" dir="auto">{{ nameOf(a.customer) }} <span class="num ltr text-muted">{{ a.customer.code }}</span></span><span v-else class="text-faint">{{ t('بلا عميل — فحص قائمة', 'No customer — a list check') }}</span></KV>
      <KV :k="{ ar: 'المنتج', en: 'Product' }"><span dir="auto">{{ nameOf(a.product) }} <span class="num ltr text-muted">{{ a.product.sku }}</span></span></KV>
      <KV :k="{ ar: 'القائمة المسعِّرة', en: 'Priced with' }">
        <template v-if="a.priceList">
          <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[10.5px] font-extrabold text-violet" dir="auto" @click="emit('open-list', a.priceList.code)">{{ a.priceList.name }}</button>
          <span class="num ltr ms-1 text-muted">{{ a.priceList.code }}</span>
          <Chip small class="ms-1" :map="VIA" :k="a.priceList.via" />
        </template>
        <Chip v-else small :map="VIA" k="none" />
      </KV>
      <KV v-if="basis" :k="{ ar: 'كيف عُرفت قائمة العميل', en: 'How the customer’s list is known' }">
        <span :title="bi(BASIS_HELP[basis])"><Chip small :map="BASIS" :k="basis" /><span v-if="a.assignment.basisRef" class="num ltr ms-1 text-muted">{{ a.assignment.basisRef }}</span></span>
      </KV>
      <KV v-if="a.rule" :k="{ ar: 'القاعدة', en: 'The rule' }">
        <span dir="auto">{{ ruleTarget(a.rule) }} — {{ bi(a.rule.text) }}</span>
        <div class="mt-0.5 text-[9px] font-normal text-muted">{{ t('من كمية', 'from qty') }} <span class="num">{{ plain(a.rule.minQty) }}</span> · {{ periodText(a.rule.validFrom, a.rule.validTo) }}</div>
      </KV>
    </div>

    <div v-if="(a.steps || []).length > 1" class="rounded-xl border border-line-2">
      <div class="border-b border-line-2 bg-soft px-3 py-1.5 text-[9.5px] font-extrabold text-faint">{{ t('خطوات الحساب', 'How it was worked out') }}</div>
      <div v-for="(s, i) in a.steps" :key="i" class="flex items-center gap-2 border-b border-line-2 px-3 py-1.5 text-[10.5px] last:border-b-0">
        <span class="num w-4 flex-none text-faint">{{ i + 1 }}</span>
        <span class="min-w-0 flex-1" dir="auto">{{ bi(s) }}</span>
        <span class="num ltr font-bold">{{ s.hidden ? t('غير ظاهر', 'hidden') : price(s.value) }}</span>
      </div>
    </div>

    <div v-if="others.length">
      <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[10px] font-extrabold text-violet" @click="showAll = !showAll">
        {{ showAll ? t('إخفاء القواعد الأخرى', 'Hide the other rules') : t(`قواعد أخرى نُظر فيها (${others.length})`, `Other rules that were looked at (${others.length})`) }}
      </button>
      <div v-if="showAll" class="mt-1.5 rounded-xl border border-line-2">
        <div v-for="x in others" :key="x.rule.id" class="flex flex-wrap items-center gap-2 border-b border-line-2 px-3 py-1.5 text-[10px] last:border-b-0">
          <Chip small :map="OUTCOME" :k="x.outcome" />
          <span class="min-w-0 flex-1" dir="auto">{{ ruleTarget(x.rule) }} — {{ bi(x.rule.text) }} <span class="text-muted">· {{ t('من كمية', 'from qty') }} <span class="num">{{ plain(x.rule.minQty) }}</span></span></span>
          <span class="text-muted" dir="auto">{{ bi(x.why) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
