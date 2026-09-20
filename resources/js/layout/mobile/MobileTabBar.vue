<script setup>
// Phone bottom tab bar: home · up to three pages of the user's role · More (see nav.js).
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { bi } from '../../i18n';
import { useAuth } from '../../stores/auth';
import Icon from '../Icon.vue';
import { tabsFor } from './nav';

const auth = useAuth();
const route = useRoute();
const tabs = computed(() => tabsFor(auth.user));
/** A document page lights the tab of the list it belongs to (SO-… → Orders). */
const PARENT = { so: 'sales', consol: 'sales', fo: 'picking', po: 'procurement', shipment: 'receiving', grn: 'receiving', wreceive: 'receiving', trip: 'trips', rtn: 'returns', trf: 'returns', product: 'products', exc: 'tower', batches: 'inv', ledger: 'inv' };
/** Anything else reached from the More screen keeps "More" lit, so the bar always shows where you are. */
const activeKey = computed(() => {
  const own = route.meta?.key || '';
  const key = tabs.value.some((tb) => tb.key === own) ? own : PARENT[own] || own;
  return tabs.value.some((tb) => tb.key === key) ? key : 'more';
});
</script>

<template>
  <nav class="m-tabbar" :aria-label="bi({ ar: 'التنقل الرئيسي', en: 'Main navigation' })">
    <RouterLink v-for="tb in tabs" :key="tb.key" :to="tb.to" class="m-tab" :class="{ active: tb.key === activeKey }" :aria-current="tb.key === activeKey ? 'page' : null">
      <span class="m-tab-ico"><Icon v-if="tb.icon" :name="tb.icon" :size="19" /><Icon v-else :nav="tb.nav" :size="19" /></span>
      <span class="m-tab-l">{{ bi(tb.label) }}</span>
    </RouterLink>
  </nav>
</template>
