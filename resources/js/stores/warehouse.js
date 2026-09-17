// Warehouse selector shown under the topbar: "all" or one of the user's warehouses. Persisted per browser.
import { computed, ref, watch } from 'vue';
import { defineStore } from 'pinia';
import { useAuth } from './auth';

const LS = 'scm.wh';

export const useWarehouse = defineStore('warehouse', () => {
  const auth = useAuth();
  const wh = ref((() => { try { return localStorage.getItem(LS) || 'all'; } catch { return 'all'; } })());

  /** Warehouses the user may see. */
  const warehouses = computed(() => auth.user?.warehouses || []);
  /** Selected warehouse record (undefined when "all"). */
  const current = computed(() => warehouses.value.find((w) => w.code === wh.value));
  const isAll = computed(() => !current.value);
  /** Spread into list params: `{ ...whParams.value }` → `{ warehouse: 'RYD' }` or `{}`. */
  const whParams = computed(() => (current.value ? { warehouse: current.value.code } : {}));

  function setWh(code) {
    wh.value = code;
    try { localStorage.setItem(LS, code); } catch { /* ignore */ }
  }
  // A stored warehouse the user can no longer see falls back to "all".
  watch(warehouses, (list) => { if (wh.value !== 'all' && list.length && !list.some((w) => w.code === wh.value)) setWh('all'); }, { immediate: true });

  return { wh, warehouses, current, isAll, whParams, setWh };
});
