<script setup>
// Floating + button and its "quick action" sheet. Only actions whose page is open to the user are offered (nav.js);
// with none, the button is not rendered at all.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { bi, t } from '../../i18n';
import { useAuth } from '../../stores/auth';
import Icon from '../Icon.vue';
import BottomSheet from './BottomSheet.vue';
import { quickActions } from './nav';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const open = ref(false);
const actions = computed(() => quickActions(auth.user));
watch(() => route.fullPath, () => { open.value = false; });

function run(a) { open.value = false; router.push(a.to); }
</script>

<template>
  <template v-if="actions.length">
    <button type="button" class="m-fab" :aria-label="t('إجراء سريع', 'Quick action')" @click="open = true"><Icon name="plus" :size="22" color="#fff" /></button>
    <BottomSheet :open="open" :title="{ ar: 'إجراء سريع', en: 'Quick action' }" @close="open = false">
      <div class="m-actions">
        <button v-for="a in actions" :key="a.key" type="button" class="m-action" @click="run(a)">
          <span class="m-action-ico" :style="{ background: a.tint[0], color: a.tint[1] }"><Icon :name="a.icon" :size="20" :color="a.tint[1]" /></span>
          <span class="m-action-l">{{ bi(a.label) }}</span>
        </button>
      </div>
    </BottomSheet>
  </template>
</template>
