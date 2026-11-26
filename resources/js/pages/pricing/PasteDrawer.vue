<script setup>
// Prices for many products at once, pasted from a sheet: one line per product — "code, price" (cells separated by a
// tab, a comma or a semicolon; a header line is skipped). The lines are read here first, so what will be sent is seen
// before it is sent; the server then takes all of them or none (400 PRICE_LINES_INVALID names every faulty line).
//   <PasteDrawer :open :list="list" @close @done />     fixed prices of a list  → POST /pricing/lists/{id}/prices   (a third cell = the minimum quantity)
//   <PasteDrawer :open @close @done />                  list prices of products → POST /pricing/list-prices/bulk
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Drawer, ErrorBanner, TextArea } from '@/components';
import { fmtNum, t } from '@/i18n';
import { parseSheet, plain, price } from './pricing';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The list whose fixed prices are pasted; null = the list prices of the products. */
  list: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const LIMIT = 500;
const text = ref('');
const result = ref(null);
const act = useAction({ invalidate: ['pricing'] });
watch(() => props.open, (o) => { if (o) { text.value = ''; result.value = null; act.clearError(); } });

const parsed = computed(() => parseSheet(text.value, !!props.list));
const items = computed(() => parsed.value.items);
/** What the server said about a line (`details: [{ path: 'items.3.sku', message }]`), by the line of the sheet. */
const serverErrors = computed(() => {
  const out = [];
  for (const [path, message] of Object.entries(act.error.value?.fieldErrors?.() || {})) {
    const m = /^items\.(\d+)\./.exec(path);
    const item = m ? items.value[Number(m[1])] : null;
    out.push({ line: item?.line ?? '—', text: item ? item.sku : path, message });
  }
  return out;
});
const problems = computed(() => [...parsed.value.errors, ...serverErrors.value]);
const tooMany = computed(() => items.value.length > LIMIT);
const canSend = computed(() => items.value.length > 0 && !parsed.value.errors.length && !tooMany.value);

async function send() {
  const body = { items: items.value.map((i) => (props.list ? { sku: i.sku, price: i.price, ...(i.minQty != null ? { minQty: i.minQty } : {}) } : { sku: i.sku, price: i.price })) };
  const r = await act.run(() => api.postIdempotent(props.list ? `/pricing/lists/${encodeURIComponent(props.list.id)}/prices` : '/pricing/list-prices/bulk', body), {
    success: (d) => t(`أُضيف ${fmtNum(d.created)} · عُدّل ${fmtNum(d.updated)} · بلا تغيير ${fmtNum(d.unchanged)}`, `${fmtNum(d.created)} added · ${fmtNum(d.updated)} changed · ${fmtNum(d.unchanged)} unchanged`),
  });
  if (r) { result.value = r; emit('done', r); }
}
</script>

<template>
  <Drawer :open="open" :width="560" :title="list ? { ar: 'لصق أسعار القائمة', en: 'Paste the prices of the list' } : { ar: 'لصق الأسعار المعلنة', en: 'Paste list prices' }" :sub="list ? `${list.name} · ${list.code}` : null" :z-index="70" @close="emit('close')">
    <div class="hint !mt-0 mb-3">
      {{ list
        ? t('سطر لكل منتج: الرمز ثم السعر، ويمكن إضافة خانة ثالثة للحد الأدنى للكمية. سعر المنتج الموجود في القائمة يُعدَّل، وغير الموجود يُضاف كسعر ثابت.', 'One line per product: the code, then the price; a third cell may give the minimum quantity. A product the list already prices is changed, another is added as a fixed price.')
        : t('سطر لكل منتج: الرمز ثم السعر المعلن. السعر الموجود يُعدَّل وغير الموجود يُضاف.', 'One line per product: the code, then the list price. An existing price is changed, a missing one is added.') }}
      <span class="num ltr mt-1 block text-[9.5px] text-muted">P00325 &nbsp; 47.50{{ list ? '   12' : '' }}</span>
    </div>
    <TextArea v-model="text" :rows="10" dir="ltr" class="num" :label="{ ar: 'الأسطر الملصوقة', en: 'Pasted lines' }" :placeholder="{ ar: 'الصق من الجدول هنا…', en: 'Paste from the sheet here…' }" />

    <div v-if="text.trim()" class="mt-3">
      <div class="row wrap text-[10.5px] font-extrabold">
        <span class="text-ok">{{ t(`${fmtNum(items.length)} سطرًا مقروءًا`, `${fmtNum(items.length)} lines read`) }}</span>
        <span v-if="problems.length" class="text-bad">{{ t(`${fmtNum(problems.length)} سطرًا فيه خطأ`, `${fmtNum(problems.length)} faulty lines`) }}</span>
        <span v-if="tooMany" class="text-bad">{{ t(`الحد الأقصى ${LIMIT} سطرًا في المرة`, `At most ${LIMIT} lines at a time`) }}</span>
      </div>
      <div v-if="problems.length" class="mt-2 max-h-[180px] overflow-y-auto rounded-xl border border-[#F3C4C4] bg-[#FDECEC]">
        <div v-for="(p, i) in problems" :key="i" class="flex items-center gap-2 border-b border-[#F3C4C4] px-3 py-1.5 text-[10px] last:border-b-0">
          <span class="num w-8 flex-none text-bad">{{ p.line }}</span>
          <span class="num ltr min-w-0 flex-1 truncate text-sec">{{ p.text }}</span>
          <span class="text-bad" dir="auto">{{ p.message }}</span>
        </div>
      </div>
      <div v-if="items.length" class="mt-2 max-h-[220px] overflow-y-auto rounded-xl border border-line-2">
        <div v-for="i in items.slice(0, 60)" :key="i.line" class="flex items-center gap-2 border-b border-line-2 px-3 py-1 text-[10px] last:border-b-0">
          <span class="num w-8 flex-none text-faint">{{ i.line }}</span>
          <span class="cell-id min-w-0 flex-1 truncate">{{ i.sku }}</span>
          <span v-if="i.minQty != null" class="num text-muted">≥ {{ plain(i.minQty) }}</span>
          <span class="num ltr font-bold">{{ price(i.price) }}</span>
        </div>
        <div v-if="items.length > 60" class="px-3 py-1.5 text-[9.5px] text-muted">{{ t(`… و${fmtNum(items.length - 60)} سطرًا آخر`, `… and ${fmtNum(items.length - 60)} more lines`) }}</div>
      </div>
    </div>
    <div v-if="result" class="hint green">{{ t(`تم: أُضيف ${fmtNum(result.created)} · عُدّل ${fmtNum(result.updated)} · بلا تغيير ${fmtNum(result.unchanged)}`, `Done: ${fmtNum(result.created)} added · ${fmtNum(result.updated)} changed · ${fmtNum(result.unchanged)} unchanged`) }}</div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :disabled="!canSend" :loading="act.pending.value" :label="{ ar: `حفظ ${fmtNum(items.length)} سعرًا`, en: `Save ${fmtNum(items.length)} prices` }" @click="send" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إغلاق', en: 'Close' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
