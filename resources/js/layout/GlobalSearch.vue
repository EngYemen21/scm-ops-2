<script setup>
// Topbar global search (desktop): grouped dropdown (type chip · id · label) with keyboard navigation.
// The search itself lives in useGlobalSearch.js, shared with the phone search screen.
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { t } from '../i18n';
import Icon from './Icon.vue';
import { useGlobalSearch } from './useGlobalSearch';

const router = useRouter();
const { q, state, groups, flat } = useGlobalSearch(12);
const open = ref(false);
const hi = ref(0);
watch(state, (s) => { if (s === 'loading') open.value = true; else if (s === 'idle') open.value = false; else hi.value = 0; });

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
