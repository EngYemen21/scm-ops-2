<script setup>
// Phone search: a topbar button that opens a full-screen search — results grouped by type with a count, recent
// searches remembered on the device, a start screen that says what can be searched, and a real "no results" state.
// A scanned barcode typed by a hardware scanner lands in the same field, so scanning works without extra UI.
import { nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ScanButton from '../../components/ScanButton.vue';
import { useScrollLock } from '../../composables/scrollLock';
import { t } from '../../i18n';
import Icon from '../Icon.vue';
import { useGlobalSearch } from '../useGlobalSearch';

const LS_RECENT = 'scm.search.recent';
const router = useRouter();
const route = useRoute();
const { q, state, groups, flat, reset } = useGlobalSearch(20);
const open = ref(false);
const input = ref(null);
const recent = ref(read());
useScrollLock(open);

function read() { try { return JSON.parse(localStorage.getItem(LS_RECENT) || '[]').filter((x) => typeof x === 'string').slice(0, 6); } catch { return []; } }
function remember(term) {
  const v = term.trim(); if (v.length < 2) return;
  recent.value = [v, ...recent.value.filter((x) => x !== v)].slice(0, 6);
  try { localStorage.setItem(LS_RECENT, JSON.stringify(recent.value)); } catch { /* private mode */ }
}
function clearRecent() { recent.value = []; try { localStorage.removeItem(LS_RECENT); } catch { /* ignore */ } }

async function show() { open.value = true; await nextTick(); input.value?.focus(); }
function close() { open.value = false; reset(); }
function go(h) { remember(q.value); close(); if (h?.path) router.push(h.path); }
function onEnter() { if (flat.value[0]) go(flat.value[0]); }

// camera: a QR printed by this system holds a link into it and opens directly; anything else is searched for
function onCamera(code) {
  if (code.startsWith(`${window.location.origin}/`)) { close(); router.push(code.slice(window.location.origin.length)); return; }
  q.value = code; input.value?.focus();
}
watch(() => route.fullPath, () => { if (open.value) close(); });

const HINTS = [
  { ar: 'رقم طلب أو أمر شراء', en: 'Order or PO number', ex: 'SO-2026-00123' },
  { ar: 'منتج بالاسم أو SKU أو الباركود', en: 'Product by name, SKU or barcode', ex: '6281000101605' },
  { ar: 'عميل أو مورد', en: 'Customer or supplier', ex: '' },
  { ar: 'رحلة، سائق، مركبة، تحويل، مرتجع', en: 'Trip, driver, vehicle, transfer, return', ex: 'TRP-2026-0031' },
];
</script>

<template>
  <button type="button" class="tb-btn m-tb-btn" :aria-label="t('بحث', 'Search')" @click="show"><Icon name="search" :size="17" color="#c9cde0" /></button>

  <Teleport to="body">
    <div v-if="open" class="m-search" role="dialog" aria-modal="true" @keydown.esc="close">
      <div class="m-search-inner">
        <div class="m-search-bar">
          <div class="m-search-box">
            <Icon name="search" :size="16" color="#a8a4b8" />
            <input ref="input" v-model="q" type="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" spellcheck="false"
                   :placeholder="t('ابحث: طلب · منتج · باركود · عميل · رحلة…', 'Search: order · product · barcode · customer · trip…')" @keydown.enter.prevent="onEnter">
            <button v-if="q" type="button" class="m-search-clear" :aria-label="t('مسح', 'Clear')" @click="q = ''; input?.focus()">✕</button>
            <ScanButton v-else @detected="onCamera" />
          </div>
          <button type="button" class="m-search-cancel" @click="close">{{ t('إلغاء', 'Cancel') }}</button>
        </div>

        <div class="m-search-body">
          <!-- start screen -->
          <template v-if="state === 'idle'">
            <template v-if="recent.length">
              <div class="m-sec-head"><span>{{ t('عمليات بحث سابقة', 'Recent searches') }}</span><button type="button" class="m-link" @click="clearRecent">{{ t('مسح', 'Clear') }}</button></div>
              <div class="flex flex-wrap gap-2">
                <button v-for="r in recent" :key="r" type="button" class="pill" @click="q = r">{{ r }}</button>
              </div>
            </template>
            <div class="m-sec-head" :class="{ 'mt-5': recent.length }"><span>{{ t('ماذا يمكنك أن تبحث عنه؟', 'What can you search for?') }}</span></div>
            <div class="m-list">
              <div v-for="h in HINTS" :key="h.en" class="m-list-row !cursor-default">
                <div class="min-w-0 flex-1">
                  <div class="text-[12.5px] font-bold">{{ t(h.ar, h.en) }}</div>
                  <div v-if="h.ex" class="num ltr mt-0.5 text-start text-[10.5px] text-faint">{{ h.ex }}</div>
                </div>
              </div>
            </div>
          </template>

          <div v-else-if="state === 'loading' && flat.length === 0" class="m-search-note"><span class="pulse">{{ t('جارٍ البحث…', 'Searching…') }}</span></div>
          <div v-else-if="state === 'unavailable'" class="m-search-note">{{ t('البحث الشامل غير متاح الآن.', 'Search is not available right now.') }}</div>
          <div v-else-if="flat.length === 0" class="m-search-note">
            <div class="text-[13px] font-extrabold text-ink">{{ t('لا نتائج', 'No results') }}</div>
            <div class="mt-1">{{ t('جرّب رقم المستند كاملًا، أو جزءًا من الاسم، أو الباركود.', 'Try the full document number, part of the name, or the barcode.') }}</div>
          </div>

          <template v-else>
            <template v-for="g in groups" :key="g.type">
              <div class="m-sec-head"><span>{{ g.label }}</span><span class="badge soft">{{ g.items.length }}</span></div>
              <div class="m-list mb-4">
                <button v-for="h in g.items" :key="`${h.type}-${h.id}`" type="button" class="m-list-row" @click="go(h)">
                  <div class="min-w-0 flex-1 text-start">
                    <div class="num ltr text-start text-[11px] text-violet">{{ h.id }}</div>
                    <div class="ellipsis mt-0.5 text-[12.5px] font-extrabold">{{ h.label }}</div>
                    <div v-if="h.sub" class="ellipsis mt-0.5 text-[10.5px] text-muted">{{ h.sub }}</div>
                  </div>
                  <span class="chip" style="color: #654e92; background: #efeaf8">{{ h.typeLabel }}</span>
                </button>
              </div>
            </template>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>
