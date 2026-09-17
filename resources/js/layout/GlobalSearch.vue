<script setup>
// Topbar global search: GET /search?q= (debounced, ≥ 2 chars), grouped dropdown (type chip · id · label), keyboard navigation.
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, isApiError } from '../api/client';
import { lang, t } from '../i18n';
import { entityPath } from '../router/routes';
import Icon from './Icon.vue';

const router = useRouter();
const q = ref('');
const open = ref(false);
const hits = ref([]);
const state = ref('idle'); // idle | loading | ok | unavailable
const hi = ref(0);
let timer; let ctrl;

const TYPE_LABELS = {
  product: { ar: 'منتج', en: 'Product' }, supplier: { ar: 'مورد', en: 'Supplier' }, customer: { ar: 'عميل', en: 'Customer' }, po: { ar: 'أمر شراء', en: 'PO' }, so: { ar: 'أمر بيع', en: 'SO' },
  qt: { ar: 'عرض سعر', en: 'Quote' }, quotation: { ar: 'عرض سعر', en: 'Quote' }, trf: { ar: 'تحويل', en: 'Transfer' }, transfer: { ar: 'تحويل', en: 'Transfer' }, rtn: { ar: 'مرتجع', en: 'Return' }, return: { ar: 'مرتجع', en: 'Return' },
  trip: { ar: 'رحلة', en: 'Trip' }, driver: { ar: 'سائق', en: 'Driver' }, vehicle: { ar: 'مركبة', en: 'Vehicle' }, bin: { ar: 'موقع', en: 'Bin' }, grn: { ar: 'GRN', en: 'GRN' }, shipment: { ar: 'شحنة', en: 'Shipment' },
  fo: { ar: 'أمر تجهيز', en: 'FO' }, exc: { ar: 'استثناء', en: 'Exception' }, exception: { ar: 'استثناء', en: 'Exception' }, oc: { ar: 'دفعة تجميع', en: 'Batch' }, invoice: { ar: 'فاتورة مورد', en: 'Invoice' },
};

/** Accepts the shapes the search endpoint may return: flat array, { groups }, { items }, { hits }, { results } or { type: [...] }. */
function normalize(payload, l) {
  const out = [];
  const pick = (h, gType, gLabel) => {
    const type = (h.type || gType || 'item').toLowerCase();
    const id = h.number || h.id || h.code || h.sku || '';
    const label = (l === 'ar' ? h.labelAr || h.nameAr : h.labelEn || h.nameEn) || h.label || h.name || h.nameAr || h.nameEn || id;
    const path = h.path || h.url || entityPath(type, id) || '';
    const typeLabel = h.typeLabel || (l === 'ar' ? h.typeAr : h.typeEn) || gLabel || (TYPE_LABELS[type] ? TYPE_LABELS[type][l] : type);
    out.push({ type, typeLabel, id, label, sub: h.sub, path });
  };
  if (Array.isArray(payload)) payload.forEach((h) => pick(h));
  else if (payload && typeof payload === 'object') {
    if (Array.isArray(payload.groups)) payload.groups.forEach((g) => (g.items || []).forEach((h) => pick(h, g.type, l === 'ar' ? g.labelAr || g.label : g.labelEn || g.label)));
    else if (Array.isArray(payload.items)) payload.items.forEach((h) => pick(h));
    else if (Array.isArray(payload.hits)) payload.hits.forEach((h) => pick(h));
    else if (Array.isArray(payload.results)) payload.results.forEach((h) => (Array.isArray(h.items) ? h.items.forEach((x) => pick(x, h.type)) : pick(h)));
    else Object.entries(payload).forEach(([type, arr]) => { if (Array.isArray(arr)) arr.forEach((h) => pick(h, type)); });
  }
  return out.slice(0, 12);
}

watch([q, lang], () => {
  clearTimeout(timer);
  const term = q.value.trim();
  if (term.length < 2) { hits.value = []; open.value = false; state.value = 'idle'; return; }
  timer = setTimeout(async () => {
    ctrl?.abort(); const c = new AbortController(); ctrl = c;
    state.value = 'loading'; open.value = true;
    try {
      const r = await api.get('/search', { q: term, limit: 12 }, c.signal);
      if (!c.signal.aborted) { hits.value = normalize(r, lang.value); state.value = 'ok'; hi.value = 0; }
    } catch (e) {
      if (e?.name === 'AbortError') return;
      hits.value = []; state.value = isApiError(e) && e.status === 404 ? 'unavailable' : 'ok';
    }
  }, 250);
});

const groups = computed(() => hits.value.reduce((acc, h) => { const g = acc.find((x) => x.type === h.type); if (g) g.items.push(h); else acc.push({ type: h.type, label: h.typeLabel || h.type, items: [h] }); return acc; }, []));
const flat = computed(() => groups.value.flatMap((g) => g.items));

function go(h) { open.value = false; q.value = ''; if (h?.path) router.push(h.path); }
function onKey(e) {
  if (e.key === 'Escape') { open.value = false; e.target.blur(); }
  else if (e.key === 'ArrowDown') { e.preventDefault(); hi.value = Math.min(flat.value.length - 1, hi.value + 1); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); hi.value = Math.max(0, hi.value - 1); }
  else if (e.key === 'Enter' && flat.value[hi.value]) go(flat.value[hi.value]);
}
</script>

<template>
  <div class="gs-wrap relative w-[min(300px,28vw)] flex-none">
    <div class="gs-box">
      <Icon name="search" />
      <input v-model="q" :placeholder="t('بحث شامل: SKU · باركود · طلب · مورد · عميل · رحلة…', 'Search: SKU · barcode · order · supplier · customer · trip…')" @focus="q.trim().length >= 2 && (open = true)" @keydown="onKey">
    </div>
    <template v-if="open">
      <div class="fixed inset-0 z-[80]" @click="open = false" />
      <div class="gs-panel">
        <div v-if="state === 'loading' && flat.length === 0" class="p-4 text-center text-[10.5px] text-faint"><span class="pulse">{{ t('جارٍ البحث…', 'Searching…') }}</span></div>
        <div v-if="state === 'unavailable'" class="p-4 text-center text-[10.5px] text-faint">{{ t('البحث الشامل غير متاح بعد — قريبًا', 'Global search is not available yet — soon') }}</div>
        <div v-if="state === 'ok' && flat.length === 0" class="p-4 text-center text-[10.5px] text-faint">{{ t('لا نتائج مطابقة.', 'No matching results.') }}</div>
        <template v-for="g in groups" :key="g.type">
          <div class="gs-group">{{ g.label }}</div>
          <div v-for="h in g.items" :key="`${h.type}-${h.id}`" class="gs-row" :class="{ hi: flat.indexOf(h) === hi }" @click="go(h)" @mouseenter="hi = flat.indexOf(h)">
            <span class="chip" style="color: #654e92; background: #efeaf8">{{ h.typeLabel }}</span>
            <span class="num ltr min-w-[110px] text-start text-[10.5px] text-violet">{{ h.id }}</span>
            <span class="ellipsis flex-1 text-[10.5px] font-bold">{{ h.label }}<span v-if="h.sub" class="faint font-normal"> · {{ h.sub }}</span></span>
          </div>
        </template>
        <div class="px-[13px] py-1.5 text-[9px] text-faint">{{ t('حتى 12 نتيجة · Enter للفتح', 'Up to 12 results · Enter to open') }}</div>
      </div>
    </template>
  </div>
</template>
