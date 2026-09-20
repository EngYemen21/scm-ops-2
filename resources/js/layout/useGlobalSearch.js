// Global search logic shared by the desktop topbar box (GlobalSearch.vue) and the phone search screen
// (mobile/MobileSearch.vue): GET /search?q= debounced (≥ 2 chars), normalised and grouped by entity type.
import { computed, ref, watch } from 'vue';
import { api, isApiError } from '../api/client';
import { lang } from '../i18n';
import { entityPath } from '../router/routes';

const TYPE_LABELS = {
  product: { ar: 'منتج', en: 'Product' }, supplier: { ar: 'مورد', en: 'Supplier' }, customer: { ar: 'عميل', en: 'Customer' }, po: { ar: 'أمر شراء', en: 'PO' }, so: { ar: 'أمر بيع', en: 'SO' },
  qt: { ar: 'عرض سعر', en: 'Quote' }, quotation: { ar: 'عرض سعر', en: 'Quote' }, trf: { ar: 'تحويل', en: 'Transfer' }, transfer: { ar: 'تحويل', en: 'Transfer' }, rtn: { ar: 'مرتجع', en: 'Return' }, return: { ar: 'مرتجع', en: 'Return' },
  trip: { ar: 'رحلة', en: 'Trip' }, driver: { ar: 'سائق', en: 'Driver' }, vehicle: { ar: 'مركبة', en: 'Vehicle' }, bin: { ar: 'موقع', en: 'Bin' }, grn: { ar: 'GRN', en: 'GRN' }, shipment: { ar: 'شحنة', en: 'Shipment' },
  fo: { ar: 'أمر تجهيز', en: 'FO' }, exc: { ar: 'استثناء', en: 'Exception' }, exception: { ar: 'استثناء', en: 'Exception' }, oc: { ar: 'دفعة تجميع', en: 'Batch' }, invoice: { ar: 'فاتورة مورد', en: 'Invoice' },
};

/** Accepts the shapes the search endpoint may return: flat array, { groups }, { items }, { hits }, { results } or { type: [...] }. */
function normalize(payload, l, limit) {
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
  return out.slice(0, limit);
}

/** state: idle | loading | ok | unavailable */
export function useGlobalSearch(limit = 12) {
  const q = ref('');
  const hits = ref([]);
  const state = ref('idle');
  let timer; let ctrl;

  watch([q, lang], () => {
    clearTimeout(timer);
    const term = q.value.trim();
    if (term.length < 2) { ctrl?.abort(); hits.value = []; state.value = 'idle'; return; }
    timer = setTimeout(async () => {
      ctrl?.abort(); const c = new AbortController(); ctrl = c;
      state.value = 'loading';
      try {
        const r = await api.get('/search', { q: term, limit }, c.signal);
        if (!c.signal.aborted) { hits.value = normalize(r, lang.value, limit); state.value = 'ok'; }
      } catch (e) {
        if (e?.name === 'AbortError') return;
        hits.value = []; state.value = isApiError(e) && e.status === 404 ? 'unavailable' : 'ok';
      }
    }, 250);
  });

  const groups = computed(() => hits.value.reduce((acc, h) => { const g = acc.find((x) => x.type === h.type); if (g) g.items.push(h); else acc.push({ type: h.type, label: h.typeLabel || h.type, items: [h] }); return acc; }, []));
  const flat = computed(() => groups.value.flatMap((g) => g.items));
  const reset = () => { q.value = ''; hits.value = []; state.value = 'idle'; };

  return { q, hits, state, groups, flat, reset };
}
